#!/usr/bin/php
<?php

/**
 * Roof repair at 148 Johnson Dr by Accent Roofing Services, $1,114, paid on the owner's personal card ($278.50 deposit
 * 2026-08-31, $835.50 on 2026-09-14) and reimbursed by a transfer from First American to the owner's Wells Fargo
 * account started 2026-11-18. Entered like the other reimbursements (#4022, "Reimburse WF Acct for ..."): an expense
 * from First American, payee Accent Roofing Services (created if missing), Repairs & Maintenance, class 148 Johnson Dr.
 * Match the transfer to it in the bank feed when it clears.
 *
 * OUTCOME: run 2026-11-18; Vendor #200000111 (Accent Roofing Services), Expense #4618.
 *
 *   php maint/oneoff/2026-11-18-accent-roofing-148-johnson.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const FIRST_AMERICAN = 'First American Bank & Trust xx0207';
const VENDOR = 'Accent Roofing Services';
const AMOUNT = 1114.00;
const DATE = '2026-11-18';
const MEMO = 'Reimburse WF for Accent Roofing Services roof repair, 148 Johnson Dr, paid on personal card ($278.50 deposit 8/31/26, $835.50 9/14/26)';

$qbo = app::qbo();
$bank = $qbo->query("SELECT * FROM Account WHERE Name = '".FIRST_AMERICAN."'")['Account'][0]['Id'];
$repairs = $qbo->query("SELECT * FROM Account WHERE FullyQualifiedName = 'Repairs & Maintenance'")['Account'][0]['Id'];
$class = $qbo->query("SELECT * FROM Class WHERE Name = '148 Johnson Dr'")['Class'][0]['Id'];
$vendor = $qbo->query("SELECT * FROM Vendor WHERE DisplayName = '".VENDOR."'")['Vendor'][0] ?? null;

$existing = array_filter($qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-08-01' MAXRESULTS 1000")['Purchase'] ?? [],
    fn ($purchase) => abs((float) $purchase['TotalAmt'] - AMOUNT) < 0.01 || str_contains($purchase['PrivateNote'] ?? '', 'Accent Roofing'));
if ($existing) {
    fwrite(STDERR, 'Already in QuickBooks: #'.implode(', #', array_column($existing, 'Id'))."; nothing changed\n");
    exit(1);
}

echo 'Vendor: '.VENDOR.($vendor ? " (#{$vendor['Id']})" : ' (new)')."\n";
echo 'Expense '.DATE.' -'.number_format(AMOUNT, 2).' from '.FIRST_AMERICAN.": Repairs & Maintenance, 148 Johnson Dr\n";
echo '  memo: '.MEMO."\n";
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$vendor ??= $qbo->create('Vendor', ['DisplayName' => VENDOR]);
$purchase = $qbo->create('Purchase', ['TxnDate' => DATE, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank],
    'EntityRef' => ['value' => $vendor['Id'], 'type' => 'Vendor'], 'PrivateNote' => MEMO,
    'Line' => [['Amount' => AMOUNT, 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => 'Roof repair (deposit and balance)',
        'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $repairs], 'ClassRef' => ['value' => $class]]]]]);
echo "Vendor #{$vendor['Id']}, Expense #{$purchase['Id']}\n";
