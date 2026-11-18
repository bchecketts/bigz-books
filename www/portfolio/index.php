<?php

// Portfolio: each property's estimated market value against its book value and loans (../var/rent-status.json, built by
// bin/properties/build.php from QuickBooks and the values in app/properties.json).

require_once __DIR__.'/../../app/common.php';

$statusFile = dirname(__DIR__, 3).'/var/rent-status.json';
$status = is_file($statusFile) ? json_decode((string) file_get_contents($statusFile), true) : null;
$money = fn (int $cents) => '$'.number_format($cents / 100, 0);
$h = fn ($text) => htmlspecialchars((string) $text);

$portfolioRows = '';
$totals = ['value' => 0, 'book' => 0, 'loans' => 0, 'annual_rent' => 0, 'noi_actual' => 0, 'noi_current_rent' => 0];
$percent = fn (int $part, int $whole) => $whole ? number_format(100 * $part / $whole, 1).'%' : '';
$capRate = fn (int $noi, int $value) => $value ? '<span class="'.($noi < 0 ? 'bad' : '').'">'.number_format(100 * $noi / $value, 1).'%</span>' : '';
$period = isset($status['operating_period']) ? date('M Y', strtotime($status['operating_period'][0])).' – '.date('M Y', strtotime($status['operating_period'][1])) : '';
foreach ($status['portfolio'] ?? [] as $holding) {
    foreach ($totals as $key => $total) {
        $totals[$key] = $total + $holding[$key];
    }
    $portfolioRows .= '<tr><td>'.$h($holding['name']).'</td><td class="n">'.$money($holding['value']).'</td>'
        .'<td class="n muted">'.$money($holding['book']).'</td><td class="n">'.($holding['loans'] ? $money($holding['loans']) : '').'</td>'
        .'<td class="n">'.$money($holding['value'] - $holding['loans']).'</td><td class="n">'.$percent($holding['loans'], $holding['value']).'</td>'
        .'<td class="n">'.($holding['annual_rent'] ? $money($holding['annual_rent']) : '').'</td><td class="n">'.$percent($holding['annual_rent'], $holding['value']).'</td>'
        .'<td class="n">'.$capRate($holding['noi_actual'], $holding['value']).($holding['months_owned'] < 12 ? '<sup>*</sup>' : '').'</td>'
        .'<td class="n">'.$capRate($holding['noi_current_rent'], $holding['value']).'</td></tr>';
}
$portfolioTotal = '<tr><td>Total</td><td class="n">'.$money($totals['value']).'</td><td class="n">'.$money($totals['book']).'</td>'
    .'<td class="n">'.$money($totals['loans']).'</td><td class="n">'.$money($totals['value'] - $totals['loans']).'</td>'
    .'<td class="n">'.$percent($totals['loans'], $totals['value']).'</td><td class="n">'.$money($totals['annual_rent']).'</td>'
    .'<td class="n">'.$percent($totals['annual_rent'], $totals['value']).'</td><td class="n">'.$capRate($totals['noi_actual'], $totals['value']).'</td>'
    .'<td class="n">'.$capRate($totals['noi_current_rent'], $totals['value']).'</td></tr>';
$valuesNote = isset($status['values_as_of']) ? 'Values: '.$h($status['values_source'] ?? '').', '.date('M j, Y', strtotime($status['values_as_of'])).'.' : '';


?><!doctype html>
<html lang="en">
<head><?= site::head('Portfolio · Big Z Properties') ?></head>
<body>
<?= site::header('/portfolio/') ?>
<main>
<h1>Portfolio</h1>
<?php if (!$status) : ?>
<p>Not built yet: run <code>php bin/properties/build.php</code>.</p>
<?php else : ?>
<p class="muted">Estimated market value against what's owed. <?= $valuesNote ?> Book value is the cost in QuickBooks
(purchase price plus improvements, before depreciation); loans are today's balances, with the Schwab line shared over the
Johnson Dr houses it financed (59/22/19).</p>
<div class="table-wrap"><table class="list">
<tr><th>Property</th><th class="n">Value</th><th class="n">Book<br>value</th><th class="n">Loans</th><th class="n">Equity</th>
<th class="n">Loan to<br>value</th><th class="n">Rent /<br>year</th><th class="n">Rent /<br>value</th>
<th class="n">Cap rate<br>last 12 mo</th><th class="n">Cap rate<br>current rent</th></tr>
    <?= $portfolioRows ?>
<tfoot><?= $portfolioTotal ?></tfoot>
</table></div>
<p class="muted">Cap rate is net operating income over value: rent less operating costs (management, repairs, taxes,
insurance, HOA, utilities), before loan interest and loan or closing fees. <strong>Last 12 months</strong> (<?= $h($period) ?>)
uses actual income and costs; <sup>*</sup>bought in April 2026, scaled to a year from the months owned.
<strong>Current rent</strong> uses today's rents (asking rent for a vacancy) less the same costs. One-time costs in the
period pull some down: 152 Johnson's mold and crawlspace work, Fizer's vandalism repairs, the turnover at 1026 Deni and
2060 Lakeside.</p>
<p class="muted">Update the values in <code>app/properties.json</code> once or twice a year.</p>
<?php endif; ?>
</main>
</body>
</html>
