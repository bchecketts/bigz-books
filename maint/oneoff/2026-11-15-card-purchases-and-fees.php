#!/usr/bin/php
<?php

/**
 * Small First American items from 2026 that QuickBooks didn't have (bank CSV uploads/ResearchTransactionsList.csv):
 *   - Lowe's / Home Depot debit card purchases and returns: Repairs & Maintenance, 1020 Tuxedo Ln (user: all Tuxedo;
 *     a little was for Deni but can't be told apart). Parts Town and Appliance Parts too (appliance parts, same time).
 *   - Menards (Grand Island): Repairs & Maintenance, 1605 Grand Ave (user)
 *   - City of Grand Island: Utilities, 1605 Grand Ave
 *   - Tennessee Secretary of State: Taxes & Licenses (annual report)
 *   - Intuit QuickBooks Online: Office Supplies & Software
 *   - bank fees: Apr 15 outgoing wire fee (Deni/Tuxedo closing), May 29 account analysis charge
 * Left for later: ACC stormwater bills, two Taqueria charges, "PY *ATHENS 2 - NORTHST", Commercial Bank account checks.
 *
 * OUTCOME: run 2026-11-15; 34 entries #4509-#4542 (vendor Intuit created).
 *
 *   php maint/oneoff/2026-11-15-card-purchases-and-fees.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK = 'First American Bank & Trust xx0207';
const TUXEDO = '1020 Tuxedo Ln.';
const GRAND = '1605 Grand Ave';
// [date, amount (- out, + in), vendor, account, class, memo]
const ITEMS = [
    ['2026-03-06', -307.05, 'State of Tennessee', 'Taxes & Licenses', null, 'Tennessee Secretary of State, annual report'],
    ['2026-04-15', -25.00, 'Bank Fees', 'Bank Charges & Fees', null, 'Outgoing wire fee (Deni/Tuxedo closing)'],
    ['2026-04-15', -80.59, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-04-17', -66.99, 'Appliances', 'Repairs & Maintenance', TUXEDO, 'Parts Town'],
    ['2026-04-20', -3.22, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-04-22', -129.00, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-05-12', -21.74, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-05-18', -200.86, 'Grand Island Utilities', 'Utilities', GRAND, 'City of Grand Island'],
    ['2026-05-29', -8.05, 'Bank Fees', 'Bank Charges & Fees', null, 'Account analysis charge'],
    ['2026-06-01', -175.50, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-01', -12.39, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-01', -252.97, 'Menards', 'Repairs & Maintenance', GRAND, ''],
    ['2026-06-04', -76.43, 'Appliances', 'Repairs & Maintenance', TUXEDO, 'Appliance Parts'],
    ['2026-06-05', -41.93, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-05', -483.13, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-08', -84.00, 'Menards', 'Repairs & Maintenance', GRAND, ''],
    ['2026-06-08', -17.25, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-12', -107.37, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-22', -16.18, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-22', -34.54, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-23', -24.82, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-06-29', 68.40, 'Home Depot', 'Repairs & Maintenance', TUXEDO, 'Return'],
    ['2026-07-21', -122.31, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-07-22', -6.47, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-07-28', -5.18, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-07-29', -30.18, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-07-29', -52.68, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-07-29', -208.06, 'Grand Island Utilities', 'Utilities', GRAND, 'City of Grand Island'],
    ['2026-08-10', 13.05, 'Lowes', 'Repairs & Maintenance', TUXEDO, 'Return'],
    ['2026-08-10', -15.72, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-08-12', -200.30, 'Home Depot', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-08-14', -43.18, 'Lowes', 'Repairs & Maintenance', TUXEDO, ''],
    ['2026-09-14', -285.01, 'Grand Island Utilities', 'Utilities', GRAND, 'City of Grand Island'],
    ['2026-10-15', -140.00, 'Intuit', 'Office Supplies & Software', null, 'QuickBooks Online'],
];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$bank = $one('Account', 'Name', BANK);
$existing = array_merge($qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-03-01' MAXRESULTS 1000")['Purchase'] ?? [],
    $qbo->query("SELECT * FROM Deposit WHERE TxnDate >= '2026-03-01' MAXRESULTS 1000")['Deposit'] ?? []);
$refs = [];
$totals = [];
foreach (ITEMS as [$date, $amount, $vendor, $account, $class, $memo]) {
    foreach ($existing as $entry) {
        $sameBank = ($entry['AccountRef']['value'] ?? $entry['DepositToAccountRef']['value'] ?? '') == $bank['Id'];
        if ($sameBank && $entry['TxnDate'] == $date && (float) $entry['TotalAmt'] == abs($amount)) {
            fwrite(STDERR, "#{$entry['Id']} {$date} ".abs($amount)." is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
    $refs['vendor'][$vendor] ??= $one('Vendor', 'DisplayName', $vendor);
    $refs['account'][$account] ??= $one('Account', 'Name', $account);
    if ($class) {
        $refs['class'][$class] ??= $one('Class', 'Name', $class);
    }
    $key = sprintf('%-24s %-28s %s', $vendor.($refs['vendor'][$vendor] ? '' : ' (new)'), $account, $class ?? '');
    $totals[$key] = ($totals[$key] ?? 0) + $amount;
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}
foreach ($totals as $key => $total) {
    printf("%2d  %s  %9.2f\n", $counts[$key], $key, $total);
}
printf("Total %.2f\n", array_sum(array_column(ITEMS, 1)));
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$ids = [];
foreach (ITEMS as [$date, $amount, $vendor, $account, $class, $memo]) {
    $refs['vendor'][$vendor] ??= $qbo->create('Vendor', ['DisplayName' => $vendor]);
    $vendorId = $refs['vendor'][$vendor]['Id'];
    $detail = ['AccountRef' => ['value' => $refs['account'][$account]['Id']]];
    if ($class) {
        $detail['ClassRef'] = ['value' => $refs['class'][$class]['Id']];
    }
    if ($amount < 0) {
        $ids[] = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank['Id']], 'EntityRef' => ['value' => $vendorId, 'type' => 'Vendor'],
            'PrivateNote' => $memo, 'Line' => [['Amount' => -$amount, 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => $memo, 'AccountBasedExpenseLineDetail' => $detail]]])['Id'];
    } else {
        $detail['Entity'] = ['value' => $vendorId, 'type' => 'Vendor'];
        $ids[] = $qbo->create('Deposit', ['TxnDate' => $date, 'DepositToAccountRef' => ['value' => $bank['Id']], 'PrivateNote' => $memo,
            'Line' => [['Amount' => $amount, 'DetailType' => 'DepositLineDetail', 'Description' => $memo, 'DepositLineDetail' => $detail]]])['Id'];
    }
}
echo count($ids).' entries: #'.min($ids).'-#'.max($ids)."\n";
