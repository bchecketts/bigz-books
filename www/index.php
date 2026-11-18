<?php

// Home: Big Z's rentals, their rent and whether it's been paid (../var/rent-status.json, built by
// bin/properties/build.php from QuickBooks with the cash flow forecast).

require_once __DIR__.'/../app/common.php';

$statusFile = dirname(__DIR__, 2).'/var/rent-status.json';
$status = is_file($statusFile) ? json_decode((string) file_get_contents($statusFile), true) : null;
$money = fn (int $cents) => '$'.number_format($cents / 100, 0);
$h = fn ($text) => htmlspecialchars((string) $text);

$intro = '';
$rows = '';
$totalRent = 0;
if ($status) {
    $through = [];
    foreach ($status['managers'] as $manager => $info) {
        $label = $manager === 'Direct' ? 'paid directly' : $manager;
        $date = $info['data_through'] && $manager !== 'Direct' ? ' (through '.date('M j', strtotime($info['data_through'])).')' : '';
        $through[] = $h($label).' '.date('M Y', strtotime($info['month'].'-01')).$date;
    }
    $intro = 'Rent collected as recorded in QuickBooks, for each manager\'s latest month: '.implode(' · ', $through)
        .'. Updated '.date('M j, g:ia', strtotime($status['built_at'])).'.';
    foreach ($status['properties'] as $property) {
        $totalRent += $property['rent'];
        $three = $property['three_months'];
        $short = $three['due'] - $three['paid'];
        $month = ' <span class="muted">'.date('M', strtotime($property['month'].'-01')).'</span>';
        if ($property['status'] === 'vacant') {
            $cell = '<span class="warn">Vacant</span>'.($property['asking'] ? ' <span class="muted">asking '.$money($property['asking']).'</span>' : '');
        } elseif ($property['status'] === 'paid') {
            $cell = '<span class="ok">Paid</span>'.$month;
        } elseif ($property['status'] === 'partial') {
            $cell = '<span class="bad">Behind</span>'.$month.' <span class="muted">'.$money($property['paid']).' of '.$money($property['rent']).'</span>';
        } else {
            $cell = '<span class="bad">Not paid</span>'.$month;
        }
        $threeCell = '';
        if ($property['rent']) {
            $threeCell = $money($three['paid']).' of '.$money($three['due']).($short > 0 ? ' <span class="bad">('.$money($short).' short)</span>' : '');
        }
        $rows .= '<tr><td>'.$h($property['name']).($property['note'] ? ' <span class="muted">'.$h($property['note']).'</span>' : '').'</td>'
            .'<td>'.$h($property['manager'] === 'Direct' ? 'Paid directly' : $property['manager']).'</td>'
            .'<td class="n">'.($property['rent'] ? $money($property['rent']) : '').'</td>'
            .'<td class="status">'.$cell.'</td><td class="n">'.$threeCell.'</td></tr>';
    }
}

?><!doctype html>
<html lang="en">
<head><?= site::head('Big Z Properties') ?>
<style>.status { white-space: nowrap; }</style>
</head>
<body>
<?= site::header('/') ?>
<main>
<h1>Properties</h1>
<?php if (!$status) : ?>
<p>Rent status hasn't been built yet: run <code>php bin/properties/build.php</code>.</p>
<?php else : ?>
<p class="muted"><?= $intro ?></p>
<div class="table-wrap"><table class="list">
<tr><th>Property</th><th>Managed by</th><th class="n">Rent</th><th>This month</th><th class="n">Last 3 months</th></tr>
    <?= $rows ?>
<tfoot><tr><td colspan="2">Total monthly rent (let units)</td><td class="n"><?= $money($totalRent) ?></td><td colspan="2"></td></tr></tfoot>
</table></div>
<p class="muted">"Last 3 months" compares what came in with three months' rent, so a late payment that caught up shows as
paid there. Rents are set in <code>app/properties.json</code>.</p>
<?php endif; ?>
</main>
</body>
</html>
