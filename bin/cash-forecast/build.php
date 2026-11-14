#!/usr/bin/php
<?php

/**
 * Daily cash flow forecast for Big Z, from today through the end of the month months_ahead after this one.
 * Read-only: nothing is written to QuickBooks.
 *
 *   - opening balance: the cash accounts in app/cash-forecast.json, from QuickBooks this morning, or from the bank's
 *     balance in balance_overrides for an account whose QuickBooks entries are behind
 *   - then day by day: the recurring and one-time items in app/cash-forecast.json, and the bills and invoices open in
 *     QuickBooks (paid or collected on their due date)
 *   - a planned item already recorded in QuickBooks (in the opening balance) isn't counted again
 *
 * Writes www/finance/cash-flow-forecast/ (page.html and data.json, served by the committed index.php behind the site's
 * login; a copy of each day's in archive/YYYY-MM-DD/). Run each morning from cron, and by hand after changing the
 * assumptions:
 *   php bin/cash-forecast/build.php [--cached]   (--cached: reuse the last QuickBooks snapshot instead of a new one)
 */

require_once __DIR__.'/../../app/common.php';

$root = dirname(__DIR__, 2);
$config = json_decode((string) file_get_contents("{$root}/app/cash-forecast.json"), true);
if (!$config) {
    fwrite(STDERR, "app/cash-forecast.json isn't valid JSON\n");
    exit(1);
}
$outputDir = "{$root}/www/finance/cash-flow-forecast";
$today = gmdate('Y-m-d');
$through = date('Y-m-t', strtotime(substr($today, 0, 7).'-01 +'.(int) ($config['months_ahead'] ?? 6).' months'));
$config['through'] = $through;
$minimum = cashforecast::cents($config['minimum_balance'] ?? 0);
$holidays = array_flip($config['bank_holidays'] ?? []);
$plusDays = fn ($day, $count) => date('Y-m-d', strtotime("{$day} {$count} days"));
$isBusinessDay = fn ($day) => date('N', strtotime($day)) < 6 && !isset($holidays[$day]);
$nextBusinessDay = function ($day) use ($isBusinessDay, $plusDays) {
    while (!$isBusinessDay($day)) {
        $day = $plusDays($day, '+1');
    }
    return $day;
};
$todayOrNext = $nextBusinessDay($today);

// 1. This morning's QuickBooks numbers (kept in ../var for --cached; it's Big Z data, so readable by ubuntu only)
$snapshotFile = \RoundSphere\Properties::getProperty('var_dir').'/cash-forecast-snapshot.json';
if (cliOption('cached') && is_file($snapshotFile)) {
    $snapshot = json_decode((string) file_get_contents($snapshotFile), true);
} else {
    $snapshot = cashforecast::snapshot($config['cash_accounts']);
    file_put_contents($snapshotFile, json_encode($snapshot));
    chmod($snapshotFile, 0600);
}

// Problems shown at the top of the page
$problems = [];
foreach ($snapshot['missing'] as $name) {
    $problems[] = "There's no bank account \"{$name}\" in QuickBooks (cash_accounts in app/cash-forecast.json).";
}

// 2. Opening balance per account: QuickBooks' today, or the bank's balance at the end of as_of plus what QuickBooks has
// recorded since (QuickBooks' change since that day)
$opening = [];
$overrideSince = [];
foreach ($snapshot['balances'] as $name => $balance) {
    $override = $config['balance_overrides'][$name] ?? null;
    $history = $snapshot['history'][$name] ?? [];
    if (isset($override['as_of'], $override['amount'])) {
        $asOf = $override['as_of'];
        $recordedSince = isset($history[$asOf]) ? $balance - $history[$asOf] : 0;
        if (!isset($history[$asOf])) {
            $problems[] = "{$name}: the bank balance in balance_overrides is from {$asOf}, older than the 6 months of QuickBooks history, so what's been recorded since isn't added.";
        }
        $opening[$name] = cashforecast::cents($override['amount']) + $recordedSince;
        $overrideSince[$name] = $asOf;
        if ($asOf < $plusDays($today, '-7')) {
            $problems[] = "{$name}: the bank balance used is from ".date('M j', strtotime($asOf)).'. Anything the bank paid since that QuickBooks doesn\'t have yet is missing: a newer balance fixes that.';
        }
    } else {
        $opening[$name] = $balance;
        $lastChange = $snapshot['last_change'][$name] ?? null;
        if ($lastChange === null || $lastChange < $plusDays($today, '-30')) {
            $problems[] = "{$name}: nothing recorded in QuickBooks since ".($lastChange ? date('M j, Y', strtotime($lastChange)) : 'at least 6 months ago')
                .', so its balance there ('.dollars($balance).') may be out of date. Give its bank balance in balance_overrides until QuickBooks catches up.';
        }
    }
    if (!empty($override['note']) && !isset($override['as_of'], $override['amount'])) {
        $problems[] = "{$name}: {$override['note']}";
    }
}
$openingTotal = array_sum($opening);
// Planned items before today that aren't in QuickBooks are counted today (they may still be on their way) when they're
// after this day: the last 14 days, or since the bank balance used (whichever is later)
$overdueFrom = max([$plusDays($today, '-14'), ...array_values($overrideSince)]);

// 3. The items' days. One-time: its date. Recurring: its day in each month (only the months listed, if any) within
// from-until. A day on a weekend or bank holiday moves to the next business day. From 45 days back, for matching.
$dayIn = fn ($item, $month) => $item['day'] === 'last' ? date('Y-m-t', strtotime("{$month}-01"))
    : sprintf('%s-%02d', $month, min((int) $item['day'], (int) date('t', strtotime("{$month}-01"))));
$planned = [];
foreach ($config['items'] as $index => $item) {
    $amount = cashforecast::cents($item['amount']);
    if (isset($item['date'])) {
        $planned[] = ['item' => $index, 'day' => $nextBusinessDay($item['date']), 'amount' => $amount, 'one_time' => true];
        continue;
    }
    for ($month = date('Y-m', strtotime("{$today} -45 days")); $month <= substr($through, 0, 7); $month = date('Y-m', strtotime("{$month}-01 +1 month"))) {
        $day = $dayIn($item, $month);
        if ((isset($item['months']) && !in_array((int) substr($month, 5), $item['months'])) || (isset($item['from']) && $day < $item['from']) || (isset($item['until']) && $day > $item['until'])) {
            continue;
        }
        $planned[] = ['item' => $index, 'day' => $nextBusinessDay($day), 'amount' => $amount, 'one_time' => false];
    }
}

// 4. Match the planned items to what's recorded in QuickBooks. A planned item from 24 days back to 7 ahead matches a
// recorded move of the same sign from 5 days before its day (paid early) to 10 days after (paid late): the same amount
// within $1, or within the item's match_within (default 1%) when the payee, memo or other account shares a word with
// the item's name (or matches its match_name regex). Each move goes to one item and each item gets one move, best fits
// first (late counts less than early, as payments are more often late).
$ignoredWords = ['the', 'and', 'for', 'from', 'with', 'pay', 'paid', 'payment', 'bill', 'transfer', 'month', 'monthly', 'loan', 'mortgage', 'account', 'holding', 'bank'];
$words = fn ($text) => array_diff(array_unique(preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY)), $ignoredWords);
$candidates = [];
foreach ($planned as $plannedIndex => $occurrence) {
    $item = $config['items'][$occurrence['item']];
    if ($occurrence['day'] < $plusDays($today, '-24') || $occurrence['day'] > $plusDays($today, '+7') || $occurrence['amount'] == 0) {
        continue;
    }
    $nameWords = array_filter($words($item['name']), fn ($word) => strlen($word) >= 3 && !preg_match('/^\d+$/', $word));
    foreach ($snapshot['recent_moves'] as $moveIndex => $move) {
        if (!in_array($move['account'], $config['cash_accounts'], true)) {
            continue;
        }
        $daysEarly = (strtotime($occurrence['day']) - strtotime($move['date'])) / 86400;
        $difference = abs($move['amount'] - $occurrence['amount']);
        $text = "{$move['name']} {$move['memo']} {$move['other']}";
        $named = isset($item['match_name']) ? (bool) preg_match($item['match_name'], $text) : (bool) array_intersect($nameWords, $words($text));
        $inWindow = $daysEarly <= 5 && $daysEarly >= -10 && (($occurrence['amount'] < 0) == ($move['amount'] < 0));
        $closeEnough = $difference <= 100 || ($named && $difference <= abs($occurrence['amount']) * (float) ($item['match_within'] ?? 0.01));
        if (!$inWindow || !$closeEnough) {
            continue;
        }
        $cost = max($daysEarly, 0) * 3 + max(-$daysEarly, 0) + $difference / max(1, abs($occurrence['amount'])) * 100 - ($named ? 0.5 : 0);
        $candidates[] = ['cost' => $cost, 'planned' => $plannedIndex, 'move' => $moveIndex];
    }
}
usort($candidates, fn ($a, $b) => $a['cost'] <=> $b['cost']);
$matched = [];
$usedMoves = [];
foreach ($candidates as $candidate) {
    if (!isset($matched[$candidate['planned']]) && !isset($usedMoves[$candidate['move']])) {
        $matched[$candidate['planned']] = $snapshot['recent_moves'][$candidate['move']];
        $usedMoves[$candidate['move']] = true;
    }
}

// 5. Events: [day][] = [name, amount (cents), category, property, estimate?]. Overdue ones (expected before today, not in
// QuickBooks) count on the first business day from today.
$events = [];
$overdue = [];
$alreadyRecorded = [];
foreach ($planned as $plannedIndex => $occurrence) {
    $item = $config['items'][$occurrence['item']];
    $event = ['name' => $item['name'], 'amount' => $occurrence['amount'], 'category' => $item['category'] ?? 'Other',
        'property' => $item['property'] ?? '', 'estimate' => !empty($item['estimate'])];
    if (isset($matched[$plannedIndex])) {
        $alreadyRecorded[] = $event + ['planned' => $occurrence['day'], 'recorded' => $matched[$plannedIndex]];
        continue;
    }
    if ($occurrence['day'] >= $today) {
        if ($occurrence['day'] <= $through) {
            $events[$occurrence['day']][] = $event;
        }
    } elseif (($occurrence['one_time'] && empty($item['done'])) || $occurrence['day'] > $overdueFrom) {
        $events[$todayOrNext][] = ['name' => "{$item['name']} (expected ".date('M j', strtotime($occurrence['day'])).')'] + $event;
        $overdue[] = $event + ['expected' => $occurrence['day']];
    }
}
// Open bills are paid, and open invoices collected, on their due date (today if that's passed)
foreach ([['open_bills', -1, 'Bills'], ['open_invoices', 1, 'Invoices']] as [$key, $sign, $category]) {
    foreach ($snapshot[$key] ?? [] as $document) {
        $day = max($nextBusinessDay($document['due']), $todayOrNext);
        $event = ['name' => trim("{$document['party']} ".($category == 'Bills' ? 'bill' : 'invoice')." {$document['number']}"), 'amount' => $sign * $document['balance'],
            'category' => $category, 'property' => '', 'estimate' => false];
        if ($day <= $through) {
            $events[$day][] = $event;
        }
        if ($document['due'] < $today) {
            $overdue[] = $event + ['expected' => $document['due']];
        }
    }
}

// 6. Day by day
$days = [];
for ($day = $today; $day <= $through; $day = $plusDays($day, '+1')) {
    $days[] = $day;
}
$balances = [];
$balance = $openingTotal;
$low = ['balance' => PHP_INT_MAX, 'day' => $today];
$firstBelow = null;
foreach ($days as $day) {
    $balance += array_sum(array_column($events[$day] ?? [], 'amount'));
    $balances[$day] = $balance;
    if ($balance < $low['balance']) {
        $low = ['balance' => $balance, 'day' => $day];
    }
    if ($firstBelow === null && $balance < $minimum) {
        $firstBelow = $day;
    }
}

// Actuals: the cash accounts' end-of-day balances in QuickBooks for the last 6 months. An account with a bank balance in
// balance_overrides counts only from that day (QuickBooks' balance before it isn't right), moved to the bank's figure.
$actuals = [];
foreach (array_keys($snapshot['history'][array_key_first($snapshot['balances']) ?? ''] ?? []) as $day) {
    if ($day >= $today) {
        continue;
    }
    $total = 0;
    foreach (array_keys($snapshot['balances']) as $name) {
        if (isset($overrideSince[$name])) {
            if ($day < $overrideSince[$name]) {
                continue 2;
            }
            $total += $opening[$name] - $snapshot['balances'][$name] + ($snapshot['history'][$name][$day] ?? 0);
        } else {
            $total += $snapshot['history'][$name][$day] ?? 0;
        }
    }
    $actuals[$day] = $total;
}

// 7. The page
$h = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES);
$money = fn ($cents) => ($cents < 0 ? '−' : '').'$'.number_format(abs($cents) / 100, 0);
$moneyExact = fn ($cents) => ($cents < 0 ? '−' : '').'$'.number_format(abs($cents) / 100, 2);
$signed = fn ($cents) => '<span class="'.($cents < 0 ? 'out' : 'in').'">'.($cents < 0 ? '−' : '+').'$'.number_format(abs($cents) / 100, 2).'</span>';
$dateText = fn ($day) => date('D M j', strtotime($day));
$estimateMark = '<span class="est" title="Estimate: to be confirmed">est.</span>';

// Summary
$endBalance = end($balances);
$summary = '<div class="tiles">'
    .'<div class="tile"><div class="label">Cash today</div><div class="value">'.$money($openingTotal).'</div><div class="sub">'
    .implode('<br>', array_map(fn ($name) => $h($name).': '.$money($opening[$name]).(isset($overrideSince[$name]) ? ' (bank, '.date('M j', strtotime($overrideSince[$name])).')' : ''), array_keys($opening))).'</div></div>'
    .'<div class="tile'.($low['balance'] < $minimum ? ' warn' : '').'"><div class="label">Lowest</div><div class="value">'.$money($low['balance']).'</div><div class="sub">on '.$dateText($low['day']).'</div></div>'
    .'<div class="tile"><div class="label">'.date('M j, Y', strtotime($through)).'</div><div class="value">'.$money($endBalance).'</div><div class="sub">'
    .($endBalance >= $openingTotal ? '+' : '−').$money(abs($endBalance - $openingTotal)).' from today</div></div>'
    .'<div class="tile'.($firstBelow ? ' warn' : '').'"><div class="label">Below '.$money($minimum).'</div><div class="value">'.($firstBelow ? $dateText($firstBelow) : 'Never').'</div><div class="sub">minimum_balance</div></div>'
    .'</div>';

// Chart: actual then forecast, one line, with the minimum as a dashed line. Inline SVG (the site allows no scripts).
$chartDays = array_merge(array_keys($actuals), $days);
$values = $actuals + $balances;
$width = 980;
$height = 300;
$padLeft = 70;
$padBottom = 28;
$top = max(max($values), $minimum, 0);
$bottom = min(min($values), 0);
$range = max($top - $bottom, 1);
$x = fn ($index) => round($padLeft + ($width - $padLeft - 10) * $index / max(count($chartDays) - 1, 1), 1);
$y = fn ($value) => round(10 + ($height - $padBottom - 10) * ($top - $value) / $range, 1);
$svg = "<svg class=\"chart\" viewBox=\"0 0 {$width} {$height}\" role=\"img\" aria-label=\"Cash balance by day, actual then forecast\">";
foreach ([0, 0.25, 0.5, 0.75, 1] as $fraction) {
    $value = $bottom + $range * $fraction;
    $svg .= '<line x1="'.$padLeft.'" x2="'.($width - 10).'" y1="'.$y($value).'" y2="'.$y($value).'" class="grid"/><text x="'.($padLeft - 6).'" y="'.($y($value) + 4).'" class="axis" text-anchor="end">'.$money((int) $value).'</text>';
}
$svg .= '<line x1="'.$padLeft.'" x2="'.($width - 10).'" y1="'.$y(0).'" y2="'.$y(0).'" class="zero"/>';
if ($minimum > 0) {
    $svg .= '<line x1="'.$padLeft.'" x2="'.($width - 10).'" y1="'.$y($minimum).'" y2="'.$y($minimum).'" class="minimum"/>';
}
$todayIndex = count($actuals);
if ($todayIndex) {
    $svg .= '<rect x="'.$padLeft.'" y="10" width="'.round($x($todayIndex) - $padLeft, 1).'" height="'.($height - $padBottom - 10).'" class="past"/>'
        .'<text x="'.($padLeft + 6).'" y="24" class="axis">Actual</text>';
}
$svg .= '<text x="'.($x($todayIndex) + 6).'" y="24" class="axis">Forecast</text>';
foreach ($chartDays as $index => $day) {
    if ($index == 0 || substr($chartDays[$index - 1], 0, 7) != substr($day, 0, 7)) {
        $svg .= '<text x="'.$x($index).'" y="'.($height - 8).'" class="axis" text-anchor="middle">'.date('M', strtotime($day)).'</text>';
    }
}
$points = [];
foreach ($chartDays as $index => $day) {
    $points[] = $x($index).','.$y($values[$day]);
}
$svg .= '<polyline class="line" points="'.implode(' ', $points).'"/>';
$svg .= '<circle cx="'.$x(array_search($low['day'], $chartDays)).'" cy="'.$y($low['balance']).'" r="4" class="low"><title>Lowest: '.$money($low['balance']).' on '.$dateText($low['day']).'</title></circle>';
$svg .= '</svg>';

// Problems, overdue and already recorded
$problemList = $problems ? '<div class="problems"><b>Check these</b><ul>'.implode('', array_map(fn ($problem) => '<li>'.$h($problem).'</li>', $problems)).'</ul></div>' : '';
$overdueList = '';
if ($overdue) {
    $overdueList = '<h2>Expected but not in QuickBooks</h2><p class="sub">Expected before today and not found in QuickBooks, so counted '.($todayOrNext == $today ? 'today' : $dateText($todayOrNext))
        .'. If one was paid, it\'s missing from QuickBooks; mark a one-time item <code>"done": true</code> if it was paid some other way.</p><table><tr><th>Expected</th><th>What</th><th class="n">Amount</th></tr>';
    foreach ($overdue as $item) {
        $overdueList .= '<tr><td>'.$dateText($item['expected']).'</td><td>'.$h($item['name']).($item['estimate'] ? " {$estimateMark}" : '').'</td><td class="n">'.$signed($item['amount']).'</td></tr>';
    }
    $overdueList .= '</table>';
}
$recordedList = '';
if ($alreadyRecorded) {
    $recordedList = '<h2>Already in QuickBooks</h2><p class="sub">Planned items found among what\'s recorded in the cash accounts, so in the balance already and not counted again.</p><table><tr><th>Planned</th><th>What</th><th class="n">Planned</th><th>Recorded</th><th class="n">Amount</th></tr>';
    foreach ($alreadyRecorded as $item) {
        $recordedList .= '<tr><td>'.$dateText($item['planned']).'</td><td>'.$h($item['name']).'</td><td class="n">'.$signed($item['amount']).'</td><td>'.$dateText($item['recorded']['date']).' '
            .$h(trim("{$item['recorded']['name']} {$item['recorded']['memo']}") ?: $item['recorded']['other']).'</td><td class="n">'.$signed($item['recorded']['amount']).'</td></tr>';
    }
    $recordedList .= '</table>';
}

// By month: money in and out by category, and the month-end balance
$categories = [];
$byMonth = [];
foreach ($events as $day => $dayEvents) {
    foreach ($dayEvents as $event) {
        $byMonth[substr($day, 0, 7)][$event['category']] = ($byMonth[substr($day, 0, 7)][$event['category']] ?? 0) + $event['amount'];
        $categories[$event['category']] = ($categories[$event['category']] ?? 0) + $event['amount'];
    }
}
arsort($categories);
$monthly = '<div class="scroll"><table><tr><th>Month</th>'.implode('', array_map(fn ($category) => '<th class="n">'.$h($category).'</th>', array_keys($categories))).'<th class="n">Net</th><th class="n">Month end</th></tr>';
for ($month = substr($today, 0, 7); $month <= substr($through, 0, 7); $month = date('Y-m', strtotime("{$month}-01 +1 month"))) {
    $net = array_sum($byMonth[$month] ?? []);
    $end = $balances[min(date('Y-m-t', strtotime("{$month}-01")), $through)];
    $monthly .= '<tr><td>'.date('M Y', strtotime("{$month}-01")).($month == substr($today, 0, 7) ? ' (from today)' : '').'</td>'
        .implode('', array_map(fn ($category) => '<td class="n">'.(isset($byMonth[$month][$category]) ? $signed($byMonth[$month][$category]) : '').'</td>', array_keys($categories)))
        .'<td class="n">'.$signed($net).'</td><td class="n'.($end < $minimum ? ' low' : '').'">'.$money($end).'</td></tr>';
}
$monthly .= '</table></div>';

// By property: what each item adds up to through the end, grouped by property
$byProperty = [];
foreach ($events as $dayEvents) {
    foreach ($dayEvents as $event) {
        $property = $event['property'] ?: 'Company-wide';
        $byProperty[$property][$event['amount'] < 0 ? 'out' : 'in'] = ($byProperty[$property][$event['amount'] < 0 ? 'out' : 'in'] ?? 0) + $event['amount'];
    }
}
ksort($byProperty);
$propertyTable = '<table><tr><th>Property</th><th class="n">In</th><th class="n">Out</th><th class="n">Net</th></tr>';
foreach ($byProperty as $property => $totals) {
    $propertyTable .= '<tr><td>'.$h($property).'</td><td class="n">'.(isset($totals['in']) ? $signed($totals['in']) : '').'</td><td class="n">'.(isset($totals['out']) ? $signed($totals['out']) : '').'</td><td class="n">'
        .$signed(($totals['in'] ?? 0) + ($totals['out'] ?? 0)).'</td></tr>';
}
$propertyTable .= '</table>';

// Every day with something on it
$daily = '<table><tr><th>Day</th><th>What</th><th class="n">Amount</th><th class="n">Balance</th></tr>';
$daily .= '<tr class="opening"><td>'.$dateText($today).'</td><td>Opening balance</td><td></td><td class="n">'.$money($openingTotal).'</td></tr>';
foreach ($days as $day) {
    $dayEvents = $events[$day] ?? [];
    usort($dayEvents, fn ($a, $b) => $b['amount'] <=> $a['amount']);
    foreach ($dayEvents as $index => $event) {
        $last = $index == count($dayEvents) - 1;
        $daily .= '<tr'.($index == 0 ? ' class="first"' : '').'><td>'.($index == 0 ? $dateText($day) : '').'</td><td>'.$h($event['name']).($event['estimate'] ? " {$estimateMark}" : '').'</td><td class="n">'.$signed($event['amount']).'</td>'
            .'<td class="n'.($last && $balances[$day] < $minimum ? ' low' : '').'">'.($last ? $money($balances[$day]) : '').'</td></tr>';
    }
}
$daily .= '</table>';

// The assumptions, as listed in app/cash-forecast.json
$assumptions = '<div class="scroll"><table><tr><th>Item</th><th>Property</th><th>When</th><th class="n">Amount</th><th>Notes</th></tr>';
foreach ($config['items'] as $item) {
    $when = isset($item['date']) ? date('M j, Y', strtotime($item['date'])).(!empty($item['done']) ? ' (done)' : '')
        : (isset($item['months']) ? 'Each '.implode(', ', array_map(fn ($month) => date('M', mktime(0, 0, 0, $month, 1)), $item['months'])).' ' : 'Monthly, ')
            .($item['day'] === 'last' ? 'last day' : 'day '.$item['day'])
            .(isset($item['from']) ? ', from '.$item['from'] : '').(isset($item['until']) ? ', until '.$item['until'] : '');
    $assumptions .= '<tr><td>'.$h($item['name']).'</td><td>'.$h($item['property'] ?? '').'</td><td>'.$h($when).'</td><td class="n">'.$signed(cashforecast::cents($item['amount'])).'</td>'
        .'<td class="note">'.(!empty($item['estimate']) ? "{$estimateMark} " : '').$h($item['note'] ?? '').'</td></tr>';
}
$assumptions .= '</table></div>';

$style = ':root{--text:#1f2328;--muted:#656d76;--line:#d8dee4;--bg:#fff;--tile:#f6f8fa;--in:#1a7f37;--out:#cf222e;--accent:#0969da;--past:rgba(101,109,118,.08);--warn:#fff8c5;--warn-line:#d4a72c}'
    .'@media (prefers-color-scheme:dark){:root{--text:#e6edf3;--muted:#8d96a0;--line:#30363d;--bg:#0d1117;--tile:#161b22;--in:#3fb950;--out:#f85149;--accent:#4493f8;--past:rgba(139,148,158,.1);--warn:#2e2a1a;--warn-line:#9e6a03}}'
    .'body{font-family:system-ui,sans-serif;max-width:68rem;margin:1.5rem auto;padding:0 16px;color:var(--text);background:var(--bg);font-size:15px}'
    .'h1{font-size:1.5rem;margin-bottom:.2rem}h2{font-size:1.1rem;margin-top:2rem}.sub{color:var(--muted);font-size:.9rem}code{font-size:.85em}'
    .'a{color:var(--accent)}.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:.8rem;margin:1rem 0}'
    .'.tile{background:var(--tile);border:1px solid var(--line);border-radius:8px;padding:.7rem .9rem}.tile.warn{background:var(--warn);border-color:var(--warn-line)}'
    .'.tile .label{color:var(--muted);font-size:.85rem}.tile .value{font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums}.tile .sub{font-size:.8rem}'
    .'.problems{background:var(--warn);border:1px solid var(--warn-line);border-radius:8px;padding:.6rem 1rem;margin:1rem 0}.problems ul{margin:.3rem 0;padding-left:1.2rem}'
    .'.chart{width:100%;height:auto;display:block}.chart .grid{stroke:var(--line);stroke-width:1}.chart .zero{stroke:var(--muted);stroke-width:1}'
    .'.chart .minimum{stroke:var(--out);stroke-width:1;stroke-dasharray:5 4;opacity:.7}.chart .past{fill:var(--past)}.chart .axis{fill:var(--muted);font-size:12px}'
    .'.chart .line{fill:none;stroke:var(--accent);stroke-width:2}.chart .low{fill:var(--out)}'
    .'table{border-collapse:collapse;width:100%;font-size:.9rem}th,td{text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}'
    .'th{color:var(--muted);font-weight:500}.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}tr.first td{border-top:2px solid var(--line)}'
    .'tr.opening td{font-weight:600}.in{color:var(--in)}.out{color:var(--out)}td.low{color:var(--out);font-weight:600}.note{color:var(--muted);font-size:.85rem;min-width:16rem}'
    .'.est{font-size:.75rem;border:1px solid var(--warn-line);border-radius:4px;padding:0 .25rem;color:var(--muted)}.scroll{overflow-x:auto}'
    .'details{margin-top:1rem}summary{cursor:pointer;font-weight:600}';

$estimates = count(array_filter($config['items'], fn ($item) => !empty($item['estimate'])));
$takenAt = (new DateTime($snapshot['taken_at']))->setTimezone(new DateTimeZone('America/New_York'))->format('D M j, g:i A');
$page = function ($heading, $archiveLink) use ($h, $style, $takenAt, $through, $estimates, $problemList, $summary, $svg, $overdueList, $monthly, $propertyTable, $daily, $recordedList, $assumptions) {
    return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Cash flow forecast</title><style>{$style}</style></head><body>"
        .'<h1>'.$heading.'</h1><!--AGE--><p class="sub">Big Z Properties. From QuickBooks at '.$h($takenAt).' Eastern, through '.date('M j, Y', strtotime($through)).'. '
        .'Assumptions in <code>app/cash-forecast.json</code>'.($estimates ? "; {$estimates} of them are estimates to confirm (marked est.)" : '').'. <a href="/">Home</a></p>'
        .$problemList.$summary.$svg.$overdueList
        .'<h2>By month</h2>'.$monthly
        .'<h2>By property</h2><p class="sub">Through '.date('M j, Y', strtotime($through)).'. Rent Oconee Clarke sends one payment for the properties it manages.</p>'.$propertyTable
        .'<h2>Every day</h2>'.$daily
        .$recordedList
        .'<h2>Assumptions</h2>'.$assumptions
        .$archiveLink.'</body></html>';
};

$data = json_encode(['built_at' => gmdate('c'), 'config' => $config, 'opening' => $opening, 'low' => $low, 'first_below_minimum' => $firstBelow,
    'balances' => $balances, 'events' => $events, 'overdue' => $overdue, 'already_recorded' => $alreadyRecorded, 'actuals' => $actuals], JSON_PRETTY_PRINT);
$archiveDir = "{$outputDir}/archive/{$today}";
if (!is_dir($archiveDir)) {
    mkdir($archiveDir, 0755, true);
}
file_put_contents("{$archiveDir}/index.html", $page('Cash flow forecast as of '.date('D M j, Y', strtotime($today)).' (kept copy)', '<p class="sub"><a href="../../">Today\'s forecast</a></p>'));
file_put_contents("{$archiveDir}/data.json", $data);

// archive/index.html: every kept day, newest first, with its lowest and end balances
$rows = '';
foreach (array_reverse(array_map('basename', glob("{$outputDir}/archive/????-??-??", GLOB_ONLYDIR) ?: [])) as $day) {
    $kept = json_decode((string) @file_get_contents("{$outputDir}/archive/{$day}/data.json"), true) ?: [];
    $keptBalances = $kept['balances'] ?? [];
    $rows .= '<tr><td><a href="'.$day.'/">'.date('D M j, Y', strtotime($day)).'</a></td><td class="n">'.(isset($kept['low']) ? $money($kept['low']['balance']).' on '.date('M j', strtotime($kept['low']['day'])) : '')
        .'</td><td class="n">'.($keptBalances ? $money(end($keptBalances)).' on '.date('M j, Y', strtotime((string) array_key_last($keptBalances))) : '').'</td></tr>';
}
file_put_contents("{$outputDir}/archive/index.html", "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Earlier forecasts</title><style>{$style}</style></head><body>"
    .'<h1>Earlier forecasts</h1><p class="sub">The forecast as it stood each day (the day\'s last build); each day\'s data.json has its assumptions. <a href="../">Today\'s forecast</a></p>'
    .'<table><tr><th>Day</th><th class="n">Lowest</th><th class="n">At the end</th></tr>'.$rows.'</table></body></html>');
file_put_contents("{$outputDir}/page.html", $page('Cash flow forecast', '<p class="sub"><a href="archive/">Earlier forecasts</a></p>'));
file_put_contents("{$outputDir}/data.json", $data);

$belowText = $firstBelow ? " (below the minimum from {$firstBelow})" : '';
printf("Cash today %s, lowest %s on %s%s, %s on %s\n", $moneyExact($openingTotal), $moneyExact($low['balance']), $low['day'], $belowText, $moneyExact($endBalance), $through);
foreach ($problems as $problem) {
    echo "CHECK: {$problem}\n";
}
foreach ($overdue as $item) {
    printf("NOT IN QUICKBOOKS: %s %s (expected %s)\n", $item['name'], $moneyExact($item['amount']), $item['expected']);
}
foreach ($alreadyRecorded as $item) {
    printf("Already in QuickBooks: %s %s (planned %s, recorded %s %s)\n", $item['name'], $moneyExact($item['amount']), $item['planned'], $item['recorded']['date'], $moneyExact($item['recorded']['amount']));
}
