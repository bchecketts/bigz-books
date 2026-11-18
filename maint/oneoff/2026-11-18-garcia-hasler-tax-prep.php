#!/usr/bin/php
<?php

/**
 * Garcia Hasler's tax preparation invoice, $3,360 in all: $1,000 deposit paid by First American check #228 (2026-03-31,
 * Purchase #4428), the $2,360 balance paid on the owner's personal credit card 2026-11-18 and reimbursed by a transfer
 * from First American to the owner's Wells Fargo account. Entered like the other reimbursements (#4618): an expense from
 * First American, payee Garcia Hasler, Legal & Professional Services, no class (like #4428: the whole company's
 * return). Match the transfer to it in the bank feed when it clears.
 *
 * OUTCOME: run 2026-11-18; Expense #4619.
 *
 *   php maint/oneoff/2026-11-18-garcia-hasler-tax-prep.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const FIRST_AMERICAN = 'First American Bank & Trust xx0207';
const VENDOR_ID = '75';
const AMOUNT = 2360.00;
const DATE = '2026-11-18';
const MEMO = 'Reimburse WF for Garcia Hasler tax preparation, balance of $3,360 invoice ($1,000 deposit check #228 3/31/26), paid on personal credit card';

$qbo = app::qbo();
$bank = $qbo->query("SELECT * FROM Account WHERE Name = '".FIRST_AMERICAN."'")['Account'][0]['Id'];
$legal = $qbo->query("SELECT * FROM Account WHERE FullyQualifiedName = 'Legal & Professional Services'")['Account'][0]['Id'];
$vendor = $qbo->get('vendor/'.VENDOR_ID)['Vendor'];

$existing = array_filter($qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-04-01' MAXRESULTS 1000")['Purchase'] ?? [],
    fn ($purchase) => ($purchase['EntityRef']['value'] ?? '') == VENDOR_ID || abs((float) $purchase['TotalAmt'] - AMOUNT) < 0.01);
if ($existing) {
    fwrite(STDERR, 'Already in QuickBooks: #'.implode(', #', array_column($existing, 'Id'))."; nothing changed\n");
    exit(1);
}

echo "Expense ".DATE.' -'.number_format(AMOUNT, 2).' from '.FIRST_AMERICAN.": {$vendor['DisplayName']}, Legal & Professional Services, no class\n";
echo '  memo: '.MEMO."\n";
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$purchase = $qbo->create('Purchase', ['TxnDate' => DATE, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank],
    'EntityRef' => ['value' => VENDOR_ID, 'type' => 'Vendor'], 'PrivateNote' => MEMO,
    'Line' => [['Amount' => AMOUNT, 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => 'Tax preparation, balance of invoice',
        'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $legal]]]]]);
echo "Expense #{$purchase['Id']}\n";
