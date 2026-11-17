#!/usr/bin/php
<?php

/**
 * Two Rent Oconee Clarke ledger entries for 1026 Deni Ct that were on no statement (their general ledger, 2026-11-17,
 * ../documents/rent-oconee-clarke/2026-11-17-ledger-1026-deni-2060-lakeside-387.50.pdf):
 *   - 2026-08-26 "SD forfeit-cleaning and trash" +$550: a departing tenant's deposit applied to the cleaning and
 *     trash-out. Entered like the 2060 deposit credits (#4101): deposit to the holding account, payee Cleaning, Cleaning
 *     Fees, class 1026 Deni Ct.
 *   - 2026-11-17 the same forfeit reversed, -$550: an expense from the holding account, Cleaning Fees, 1026 Deni Ct.
 * Net $0; the holding account stays at -$387.49 (what Big Z owes Rent Oconee Clarke until the December payout). If the
 * December statement lists the reversal, rent_oconee_clarke.php finds this expense and skips it.
 *
 * OUTCOME: run 2026-11-17; Deposit #4616, Expense #4617; holding account still -387.49.
 *
 *   php maint/oneoff/2026-11-17-deni-deposit-forfeit.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const HOLDING = '35';
const MEMO = 'Rent Oconee Clarke SD forfeit-cleaning and trash (1026 Deni), not on a statement; their ledger 11/17/26';

$qbo = app::qbo();
$sample = $qbo->get('deposit/4101')['Deposit']['Line'][0]['DepositLineDetail'];
$cleaning = $sample['AccountRef']['value'];
$vendor = $sample['Entity'];
$class = $qbo->query("SELECT * FROM Class WHERE Name = '1026 Deni Ct'")['Class'][0]['Id'];
$deposits = array_filter($qbo->query("SELECT * FROM Deposit WHERE TxnDate = '2026-08-26'")['Deposit'] ?? [],
    fn ($deposit) => $deposit['DepositToAccountRef']['value'] == HOLDING && (float) $deposit['TotalAmt'] == 550.0);
$purchases = array_filter($qbo->query("SELECT * FROM Purchase WHERE TxnDate = '2026-11-17'")['Purchase'] ?? [],
    fn ($purchase) => $purchase['AccountRef']['value'] == HOLDING && (float) $purchase['TotalAmt'] == 550.0);
if ($deposits || $purchases) {
    fwrite(STDERR, "Already in QuickBooks: nothing changed\n");
    exit(1);
}
echo "Deposit 2026-08-26 +550.00 to the Rent Oconee Clarke holding account: {$vendor['name']}, Cleaning Fees, 1026 Deni Ct\n";
echo "Expense 2026-11-17 -550.00 from the holding account: {$vendor['name']}, Cleaning Fees, 1026 Deni Ct (the forfeit reversed)\n";
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$deposit = $qbo->create('Deposit', ['TxnDate' => '2026-08-26', 'DepositToAccountRef' => ['value' => HOLDING], 'PrivateNote' => MEMO,
    'Line' => [['Amount' => 550.00, 'DetailType' => 'DepositLineDetail', 'Description' => 'Renter deposit paid towards cleaning and trash out fees',
        'DepositLineDetail' => ['Entity' => $vendor, 'AccountRef' => ['value' => $cleaning], 'ClassRef' => ['value' => $class]]]]]);
$purchase = $qbo->create('Purchase', ['TxnDate' => '2026-11-17', 'PaymentType' => 'Cash', 'AccountRef' => ['value' => HOLDING],
    'EntityRef' => ['value' => $vendor['value'], 'type' => 'Vendor'], 'PrivateNote' => MEMO.' (reversed)',
    'Line' => [['Amount' => 550.00, 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => 'Deposit forfeit reversed',
        'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $cleaning], 'ClassRef' => ['value' => $class]]]]]);
echo "Deposit #{$deposit['Id']}, Expense #{$purchase['Id']}; holding account ".$qbo->get('account/'.HOLDING)['Account']['CurrentBalance']."\n";
