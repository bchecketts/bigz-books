#!/usr/bin/php
<?php

/**
 * The Farmers Bank Business Savings (x8531) after Sep 16 2025, from its statements (uploads/farmers-bankBig Z
 * Statements.pdf): every deposit was in QuickBooks, but not the payments to the Farmers line of credit (loan 7310),
 * the service charges or the interest credits, so QuickBooks showed $20,838.19 for an account closed at $0 on
 * 2026-06-18. Enters them on their statement dates:
 *   - loan payments, taken as interest (user's OK; the 2025 ones are in a filed year, accountant had them): interest
 *     journal entry (Interest Paid split 59/22/19 over 148/146/152 Johnson Dr like #3415; the line-of-credit side has
 *     no class), then a transfer from savings to the line of credit. Net 0 on the line of credit, which stays $0.
 *   - service charges (Bank Charges & Fees) and their refund at closing; interest credits (Interest Income, like #3420)
 * Afterwards the savings account is $0.
 *
 * OUTCOME: run 2026-11-16; 26 entries #4587-#4612. Savings and the line of credit both $0.
 *
 *   php maint/oneoff/2026-11-16-farmers-savings.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const PAYMENTS = [['2025-09-29', 25.00], ['2025-11-12', 5908.93], ['2025-12-15', 2186.19], ['2025-12-18', 300.00], ['2026-02-02', 3400.00],
    ['2026-02-17', 4508.10], ['2026-03-09', 50.00], ['2026-04-21', 4454.13]];
const FEES = [['2026-02-28', -6.00, 'Service charge'], ['2026-04-30', -6.00, 'Service charge'], ['2026-05-31', -6.00, 'Service charge'],
    ['2026-06-18', 12.00, 'Service charges reversed at closing']];
const INTEREST = [['2025-09-30', 0.02], ['2025-10-31', 0.03], ['2025-11-30', 0.04], ['2025-12-31', 0.01], ['2026-01-31', 0.03], ['2026-03-31', 0.03]];
const SPLIT = ['148 Johnson Dr' => 0.59, '146 Johnson Dr' => 0.22, '152 Johnson Dr' => 0.19];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$savings = $one('Account', 'Name', 'The Farmers Bank Business Savings');
$loc = $one('Account', 'Name', 'The Farmers Bank (Line of Credit)');
$interestPaid = $one('Account', 'Name', 'Interest Paid');
$interestIncome = $one('Account', 'Name', 'Interest Income');
$fees = $one('Account', 'Name', 'Bank Charges & Fees');
$farmers = $qbo->get('deposit/3420')['Deposit']['Line'][0]['DepositLineDetail']['Entity'];
$classes = [];
foreach (array_keys(SPLIT) as $class) {
    $classes[$class] = $one('Class', 'Name', $class)['Id'];
}
foreach ($qbo->query("SELECT * FROM Transfer WHERE TxnDate >= '2025-09-17' MAXRESULTS 1000")['Transfer'] ?? [] as $transfer) {
    if ($transfer['FromAccountRef']['value'] == $savings['Id'] && $transfer['ToAccountRef']['value'] == $loc['Id']) {
        fwrite(STDERR, "Transfer #{$transfer['Id']} {$transfer['TxnDate']} savings -> line of credit is already in QuickBooks: nothing changed\n");
        exit(1);
    }
}
$split = function (float $amount): array {
    $parts = [];
    $soFar = 0;
    foreach (SPLIT as $class => $share) {
        $part = $class == array_key_last(SPLIT) ? round($amount - $soFar, 2) : round($amount * $share, 2);
        $parts[$class] = $part;
        $soFar += $part;
    }
    return $parts;
};
$balance = (float) $savings['CurrentBalance'];
foreach (PAYMENTS as [$date, $amount]) {
    $balance -= $amount;
    printf("%s payment %8.2f  interest %s\n", $date, $amount, implode(' / ', $split($amount)));
}
foreach (FEES as [$date, $amount, $memo]) {
    $balance += $amount;
    printf("%s fee     %8.2f  %s\n", $date, $amount, $memo);
}
foreach (INTEREST as [$date, $amount]) {
    $balance += $amount;
}
printf("Interest credits %.2f (%d)\nSavings %.2f -> %.2f\n", array_sum(array_column(INTEREST, 1)), count(INTEREST), (float) $savings['CurrentBalance'], $balance);
if (abs($balance) > 0.001) {
    fwrite(STDERR, "Doesn't end at 0: nothing changed\n");
    exit(1);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$ids = [];
foreach (PAYMENTS as [$date, $amount]) {
    $lines = [['Amount' => $amount, 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Farmers line of credit interest',
        'JournalEntryLineDetail' => ['PostingType' => 'Credit', 'AccountRef' => ['value' => $loc['Id']]]]];
    foreach ($split($amount) as $class => $part) {
        $lines[] = ['Amount' => $part, 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Farmers line of credit interest',
            'JournalEntryLineDetail' => ['PostingType' => 'Debit', 'AccountRef' => ['value' => $interestPaid['Id']], 'ClassRef' => ['value' => $classes[$class]]]];
    }
    $ids[] = $qbo->create('JournalEntry', ['TxnDate' => $date, 'PrivateNote' => 'Farmers line of credit interest', 'Line' => $lines])['Id'];
    $ids[] = $qbo->create('Transfer', ['TxnDate' => $date, 'Amount' => $amount, 'FromAccountRef' => ['value' => $savings['Id']], 'ToAccountRef' => ['value' => $loc['Id']],
        'PrivateNote' => 'Online loan payment to Farmers loan 7310'])['Id'];
}
foreach (FEES as [$date, $amount, $memo]) {
    $line = ['Amount' => abs($amount), 'Description' => $memo];
    if ($amount < 0) {
        $ids[] = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $savings['Id']], 'PrivateNote' => $memo,
            'Line' => [$line + ['DetailType' => 'AccountBasedExpenseLineDetail', 'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $fees['Id']]]]]])['Id'];
    } else {
        $ids[] = $qbo->create('Deposit', ['TxnDate' => $date, 'DepositToAccountRef' => ['value' => $savings['Id']], 'PrivateNote' => $memo,
            'Line' => [$line + ['DetailType' => 'DepositLineDetail', 'DepositLineDetail' => ['AccountRef' => ['value' => $fees['Id']]]]]])['Id'];
    }
}
foreach (INTEREST as [$date, $amount]) {
    $ids[] = $qbo->create('Deposit', ['TxnDate' => $date, 'DepositToAccountRef' => ['value' => $savings['Id']],
        'Line' => [['Amount' => $amount, 'DetailType' => 'DepositLineDetail', 'DepositLineDetail' => ['Entity' => $farmers, 'AccountRef' => ['value' => $interestIncome['Id']]]]]])['Id'];
}
echo count($ids).' entries: #'.min($ids).'-#'.max($ids)."\n";
foreach ([$savings, $loc] as $account) {
    echo $account['Name'].': '.$qbo->get('account/'.$account['Id'])['Account']['CurrentBalance']."\n";
}
