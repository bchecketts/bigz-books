#!/usr/bin/php
<?php

/**
 * First American transfers to Wells Fargo, Mar-Apr 2026, as the user identified them (bank CSV
 * uploads/ResearchTransactionsList.csv). Entered like the earlier ones: owner draws as Brandon Checketts to Owner's
 * Investment (#3806, #3818); insurance paid from Wells Fargo as a reimbursement to Insurance by class (#4022, #4023).
 *
 * OUTCOME: run 2026-11-15. Vendor #200000071 Auto-Owners Insurance; Purchases #4291-#4296.
 *
 *   php maint/oneoff/2026-11-15-wells-fargo-transfers-mar-apr.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK = 'First American Bank & Trust xx0207';
const ITEMS = [
    ['date' => '2026-03-23', 'amount' => 7000.00, 'kind' => 'draw', 'memo' => 'Owner draw, transfer to WF'],
    ['date' => '2026-04-15', 'amount' => 5000.00, 'kind' => 'draw', 'memo' => 'Owner draw, transfer to WF'],
    ['date' => '2026-04-15', 'amount' => 5000.01, 'kind' => 'draw', 'memo' => 'Owner draw, transfer to WF'],
    ['date' => '2026-04-17', 'amount' => 3511.99, 'kind' => 'insurance', 'class' => '1020 Deni Ct.', 'memo' => 'Reimburse WF for Auto-Owners Insurance, 1020 Deni Ct (WF ref F20XPDTYKV)'],
    ['date' => '2026-04-17', 'amount' => 885.26, 'kind' => 'insurance', 'class' => '1020 Tuxedo Ln.', 'memo' => 'Reimburse WF for Auto-Owners Insurance, 1020 Tuxedo Ln (WF ref F20XPDTQ3G)'],
    ['date' => '2026-04-21', 'amount' => 25000.00, 'kind' => 'draw', 'memo' => 'Owner draw, transfer to WF'],
];
const INSURER = 'Auto-Owners Insurance';

$qbo = app::qbo();
$bank = $qbo->query("SELECT * FROM Account WHERE Name = '".BANK."'")['Account'][0];
$sample = $qbo->get('purchase/3818')['Purchase'];
$owner = $sample['EntityRef'];
$equityAccount = $sample['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef'];
$insuranceAccount = $qbo->get('purchase/4023')['Purchase']['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef'];
$insurer = $qbo->query("SELECT * FROM Vendor WHERE DisplayName = '".INSURER."'")['Vendor'][0] ?? null;
$classes = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classes[$class['FullyQualifiedName']] = $class['Id'];
}
$existing = $qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-03-20' AND TxnDate <= '2026-04-25' MAXRESULTS 200")['Purchase'] ?? [];
foreach (ITEMS as $item) {
    foreach ($existing as $purchase) {
        if ($purchase['TxnDate'] == $item['date'] && (float) $purchase['TotalAmt'] == $item['amount']) {
            fwrite(STDERR, "#{$purchase['Id']} {$purchase['TxnDate']} {$purchase['TotalAmt']} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
    $to = $item['kind'] == 'draw' ? "{$owner['name']}, {$equityAccount['name']}" : INSURER.($insurer ? '' : ' (new vendor)').", {$insuranceAccount['name']}, class {$item['class']}";
    printf("%s %10.2f  %s: %s\n", $item['date'], $item['amount'], $to, $item['memo']);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
if (!$insurer) {
    $insurer = $qbo->create('Vendor', ['DisplayName' => INSURER]);
    echo "Created vendor #{$insurer['Id']} ".INSURER."\n";
}
foreach (ITEMS as $item) {
    $draw = $item['kind'] == 'draw';
    $detail = ['AccountRef' => $draw ? $equityAccount : $insuranceAccount];
    if (!$draw) {
        $detail['ClassRef'] = ['value' => $classes[$item['class']]];
    }
    $saved = $qbo->create('Purchase', [
        'TxnDate' => $item['date'],
        'PaymentType' => 'Cash',
        'AccountRef' => ['value' => $bank['Id']],
        'EntityRef' => $draw ? $owner : ['value' => $insurer['Id'], 'type' => 'Vendor'],
        'PrivateNote' => $item['memo'],
        'Line' => [['Amount' => $item['amount'], 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => $item['memo'], 'AccountBasedExpenseLineDetail' => $detail]],
    ]);
    echo "#{$saved['Id']} {$item['date']} {$saved['TotalAmt']}\n";
}
