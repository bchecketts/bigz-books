#!/usr/bin/php
<?php

/**
 * Athens-Clarke County stormwater bills paid from First American in 2026 (bank CSV). The bank doesn't say which
 * property each is for, so each bill is split by the user's usual shares (by lot size, from what they recorded last
 * year): 460 Barnett Shoals 31, 2050 Lakeside 56, 2060 Lakeside 70, 2070 Lakeside 70, 412 Rivers Edge 95, Johnson Dr
 * 90 divided evenly over 146/148/152. Account Stormwater, vendor ACC Stormwater.
 *
 * OUTCOME: not run yet.
 *
 *   php maint/oneoff/2026-11-15-stormwater.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const SHARES = ['460 Barnett Shoals Rd' => 31, '2050 Lakeside Dr' => 56, '2060 Lakeside Dr' => 70, '2070 Lakeside Dr' => 70, '412 Rivers Edge' => 95,
    '146 Johnson Dr' => 30, '148 Johnson Dr' => 30, '152 Johnson Dr' => 30];
const BILLS = [['2026-03-27', 37.67], ['2026-04-21', 13.20], ['2026-08-04', 60.41], ['2026-08-04', 60.41], ['2026-08-04', 60.41], ['2026-08-04', 34.00],
    ['2026-08-04', 13.83], ['2026-09-01', 44.36], ['2026-11-03', 13.98]];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$bank = $one('Account', 'Name', 'First American Bank & Trust xx0207');
$account = $one('Account', 'Name', 'Stormwater');
$vendor = $one('Vendor', 'DisplayName', 'ACC Stormwater');
$classes = [];
foreach (array_keys(SHARES) as $class) {
    $classes[$class] = $one('Class', 'Name', $class)['Id'];
}
$existing = $qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-03-01' MAXRESULTS 1000")['Purchase'] ?? [];
foreach (BILLS as [$date, $amount]) {
    foreach ($existing as $purchase) {
        if ($purchase['TxnDate'] == $date && (float) $purchase['TotalAmt'] == $amount && $purchase['AccountRef']['value'] == $bank['Id']) {
            fwrite(STDERR, "#{$purchase['Id']} {$date} {$amount} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
}
$split = function (float $amount): array {
    $parts = [];
    $soFar = 0;
    $total = array_sum(SHARES);
    foreach (SHARES as $class => $share) {
        $part = $class == array_key_last(SHARES) ? round($amount - $soFar, 2) : round($amount * $share / $total, 2);
        $parts[$class] = $part;
        $soFar += $part;
    }
    return $parts;
};
$byClass = [];
foreach (BILLS as [$date, $amount]) {
    foreach ($split($amount) as $class => $part) {
        $byClass[$class] = ($byClass[$class] ?? 0) + $part;
    }
}
printf("%d bills, %.2f, to %s / %s, split per bill:\n", count(BILLS), array_sum(array_column(BILLS, 1)), $vendor['DisplayName'], $account['Name']);
foreach ($byClass as $class => $total) {
    printf("  %-22s %7.2f\n", $class, $total);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$ids = [];
foreach (BILLS as [$date, $amount]) {
    $lines = [];
    foreach ($split($amount) as $class => $part) {
        if ($part != 0) {
            $lines[] = ['Amount' => $part, 'DetailType' => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $account['Id']], 'ClassRef' => ['value' => $classes[$class]]]];
        }
    }
    $ids[] = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank['Id']], 'EntityRef' => ['value' => $vendor['Id'], 'type' => 'Vendor'],
        'PrivateNote' => 'Stormwater, split by lot-size shares', 'Line' => $lines])['Id'];
}
echo count($ids).' entries: #'.min($ids).'-#'.max($ids)."\n";
