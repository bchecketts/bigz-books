#!/usr/bin/php
<?php

/**
 * Athens-Clarke County stormwater bills paid from First American in 2026 (bank CSV), matched to properties from the
 * user's stormwater accounts: 460 Barnett Shoals $44.36 (Sep 1), 412 Rivers Edge (2505 W Broad) $34.00, 2050/2060/2070
 * Lakeside $60.41 each (Aug 4), and the Johnson Dr account (one account for 146/148/152; split evenly): $37.67 Mar 27,
 * $13.20 Apr 21, $13.83 Aug 4, $13.98 Nov 3. Account Stormwater, vendor ACC Stormwater, one entry per bank payment.
 *
 * OUTCOME: run 2026-11-16; Purchases #4546-#4554.
 *
 *   php maint/oneoff/2026-11-15-stormwater.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const JOHNSON = ['146 Johnson Dr', '148 Johnson Dr', '152 Johnson Dr'];
// [date, amount, property (or 'johnson')]
const BILLS = [['2026-03-27', 37.67, 'johnson'], ['2026-04-21', 13.20, 'johnson'], ['2026-08-04', 60.41, '2050 Lakeside Dr'],
    ['2026-08-04', 60.41, '2060 Lakeside Dr'], ['2026-08-04', 60.41, '2070 Lakeside Dr'], ['2026-08-04', 34.00, '412 Rivers Edge'],
    ['2026-08-04', 13.83, 'johnson'], ['2026-09-01', 44.36, '460 Barnett Shoals Rd'], ['2026-11-03', 13.98, 'johnson']];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$bank = $one('Account', 'Name', 'First American Bank & Trust xx0207');
$account = $one('Account', 'Name', 'Stormwater');
$vendor = $one('Vendor', 'DisplayName', 'ACC Stormwater');
$classes = [];
foreach (array_merge(JOHNSON, ['2050 Lakeside Dr', '2060 Lakeside Dr', '2070 Lakeside Dr', '412 Rivers Edge', '460 Barnett Shoals Rd']) as $class) {
    $classes[$class] = $one('Class', 'Name', $class)['Id'];
}
$existing = $qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-03-01' MAXRESULTS 1000")['Purchase'] ?? [];
foreach (BILLS as [$date, $amount, $property]) {
    foreach ($existing as $purchase) {
        if ($purchase['TxnDate'] == $date && (float) $purchase['TotalAmt'] == $amount && $purchase['AccountRef']['value'] == $bank['Id']) {
            fwrite(STDERR, "#{$purchase['Id']} {$date} {$amount} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
}
$split = function (float $amount, string $property): array {
    if ($property != 'johnson') {
        return [$property => $amount];
    }
    $third = round($amount / 3, 2);
    return [JOHNSON[0] => $third, JOHNSON[1] => $third, JOHNSON[2] => round($amount - 2 * $third, 2)];
};
foreach (BILLS as [$date, $amount, $property]) {
    $parts = $split($amount, $property);
    printf("%s %6.2f  %s\n", $date, $amount, implode(', ', array_map(fn ($class, $part) => "{$class} {$part}", array_keys($parts), $parts)));
}
printf("%d bills, %.2f, %s / %s\n", count(BILLS), array_sum(array_column(BILLS, 1)), $vendor['DisplayName'], $account['Name']);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$ids = [];
foreach (BILLS as [$date, $amount, $property]) {
    $lines = [];
    foreach ($split($amount, $property) as $class => $part) {
        if ($part != 0) {
            $lines[] = ['Amount' => $part, 'DetailType' => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $account['Id']], 'ClassRef' => ['value' => $classes[$class]]]];
        }
    }
    $ids[] = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank['Id']], 'EntityRef' => ['value' => $vendor['Id'], 'type' => 'Vendor'],
        'PrivateNote' => $property == 'johnson' ? 'Stormwater, Johnson Dr account (146/148/152)' : 'Stormwater', 'Line' => $lines])['Id'];
}
echo count($ids).' entries: #'.min($ids).'-#'.max($ids)."\n";
