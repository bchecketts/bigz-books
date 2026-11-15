#!/usr/bin/php
<?php

/**
 * First American transfers to Wells Fargo, May-Jul 2026, as the user identified them (bank CSV
 * uploads/ResearchTransactionsList.csv): reimbursements for Big Z bills paid from Wells Fargo, and an owner draw.
 * Insurance to Insurance by class (like #4294); the Tuxedo kitchen added to the 1020 Tuxedo Lane fixed asset like the
 * other improvements (#4266, #4267); smaller repairs to Repairs & Maintenance; the owner draw like #4291.
 * The kitchen was cabinets and countertop. $707.40 on 2026-06-17 was a Lowe's purchase; Tuxedo assumed (the work going on then).
 *
 * OUTCOME: run 2026-11-15; Purchases #4297-#4306 (Build.com created as a vendor). The 148 Johnson premium appears twice
 * (#4299, #4306): paid, refunded after a policy change, paid again; the refund is still to be found.
 *
 *   php maint/oneoff/2026-11-15-wells-fargo-transfers-may-jul.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK = 'First American Bank & Trust xx0207';
// [date, amount, payee (vendor name, or 'owner'), account, class, memo]
const ITEMS = [
    ['2026-05-05', 186.08, 'Auto-Owners Insurance', 'insurance', '460 Barnett Shoals Rd', 'Reimburse WF for Auto-Owners Insurance, 460 Barnett Shoals'],
    ['2026-05-15', 11527.89, 'Home Depot', 'asset:1020 Tuxedo Lane', '1020 Tuxedo Ln.', 'Reimburse WF for Home Depot: kitchen, 1020 Tuxedo Ln'],
    ['2026-05-20', 1157.39, 'Auto-Owners Insurance', 'insurance', '148 Johnson Dr', 'Reimburse WF for Auto-Owners Insurance, 148 Johnson Dr'],
    ['2026-05-20', 440.16, 'Build.com', 'Repairs & Maintenance', '1020 Tuxedo Ln.', 'Reimburse WF for Build.com: door knobs, 1020 Tuxedo Ln'],
    ['2026-05-20', 705.20, 'Auto-Owners Insurance', 'insurance', '152 Johnson Dr', 'Reimburse WF for Auto-Owners Insurance, 152 Johnson Dr'],
    ['2026-06-02', 335.69, 'Plumbing', 'Repairs & Maintenance', '1020 Tuxedo Ln.', 'Reimburse WF for Dawson Plumbing, 1020 Tuxedo Ln'],
    ['2026-06-17', 707.40, 'Lowes', 'Repairs & Maintenance', '1020 Tuxedo Ln.', "Reimburse WF for Lowe's purchase, 1020 Tuxedo Ln"],
    ['2026-06-17', 10000.00, 'owner', 'equity', '', 'Owner draw, transfer to WF'],
    ['2026-06-29', 322.92, 'Home Depot', 'Repairs & Maintenance', '1020 Tuxedo Ln.', 'Reimburse WF for Home Depot: microwave, 1020 Tuxedo Ln'],
    ['2026-07-21', 1157.39, 'Auto-Owners Insurance', 'insurance', '148 Johnson Dr', 'Reimburse WF for Auto-Owners Insurance, 148 Johnson Dr'],
];

$qbo = app::qbo();
$byName = function (string $type, string $field, string $value) use ($qbo) {
    return $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
};
$bank = $byName('Account', 'Name', BANK);
$draw = $qbo->get('purchase/4291')['Purchase'];
$insurance = $qbo->get('purchase/4294')['Purchase']['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef'];
$classes = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classes[$class['FullyQualifiedName']] = $class['Id'];
}
$existing = $qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-05-01' AND TxnDate <= '2026-07-31' MAXRESULTS 500")['Purchase'] ?? [];
$plan = [];
foreach (ITEMS as [$date, $amount, $payee, $accountKey, $class, $memo]) {
    foreach ($existing as $purchase) {
        if ($purchase['TxnDate'] == $date && (float) $purchase['TotalAmt'] == $amount && ($purchase['AccountRef']['value'] ?? '') == $bank['Id']) {
            fwrite(STDERR, "#{$purchase['Id']} {$date} {$amount} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
    if ($accountKey == 'insurance') {
        $accountRef = $insurance;
    } elseif ($accountKey == 'equity') {
        $accountRef = $draw['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef'];
    } else {
        $found = $byName('Account', 'Name', str_starts_with($accountKey, 'asset:') ? substr($accountKey, 6) : $accountKey);
        $accountRef = ['value' => $found['Id'], 'name' => $found['Name']];
    }
    $vendor = $payee == 'owner' ? null : $byName('Vendor', 'DisplayName', $payee);
    if ($class && !isset($classes[$class])) {
        fwrite(STDERR, "No class {$class}: nothing changed\n");
        exit(1);
    }
    $payeeText = $payee == 'owner' ? $draw['EntityRef']['name'] : $payee.($vendor ? '' : ' (new vendor)');
    printf("%s %10.2f  %-30s %-28s %-16s %s\n", $date, $amount, $payeeText, $accountRef['name'] ?? $accountKey, $class, $memo);
    $plan[] = compact('date', 'amount', 'payee', 'accountRef', 'class', 'memo');
}
printf("Total %.2f\n", array_sum(array_column($plan, 'amount')));
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as $item) {
    if ($item['payee'] == 'owner') {
        $entity = $draw['EntityRef'];
    } else {
        $vendor = $byName('Vendor', 'DisplayName', $item['payee']) ?? $qbo->create('Vendor', ['DisplayName' => $item['payee']]);
        $entity = ['value' => $vendor['Id'], 'type' => 'Vendor'];
    }
    $detail = ['AccountRef' => ['value' => $item['accountRef']['value']]];
    if ($item['class']) {
        $detail['ClassRef'] = ['value' => $classes[$item['class']]];
    }
    $saved = $qbo->create('Purchase', [
        'TxnDate' => $item['date'],
        'PaymentType' => 'Cash',
        'AccountRef' => ['value' => $bank['Id']],
        'EntityRef' => $entity,
        'PrivateNote' => $item['memo'],
        'Line' => [['Amount' => $item['amount'], 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => $item['memo'], 'AccountBasedExpenseLineDetail' => $detail]],
    ]);
    echo "#{$saved['Id']} {$item['date']} {$saved['TotalAmt']}\n";
}
