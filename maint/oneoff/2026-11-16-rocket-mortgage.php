#!/usr/bin/php
<?php

/**
 * Mr. Cooper / Rocket Mortgage (2734 Fizer Rd, loan 0000452701) payments Feb-Nov 2026 from First American, split by
 * Rocket's transaction history (pasted by the user 2026-11-16). Entered like Jan 7 2026 (#3738): transfer First
 * American -> loan at the bank's date, journal entry for the interest (Interest Paid, credit the loan, Rocket Mortgage),
 * transfer loan -> _Escrow 2734 Fizer for the escrow part. Class only on the Interest Paid line (CLAUDE.md).
 * Rocket's list shows no escrow disbursements (tax, insurance) for 2026; those aren't entered.
 *
 * OUTCOME: run 2026-11-16; 30 entries #4555-#4584. Loan $99,908.35 (matches Rocket); First American now equals the bank.
 *
 *   php maint/oneoff/2026-11-16-rocket-mortgage.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

// [bank date, total, principal, interest, escrow]
const PAYMENTS = [
    ['2026-02-09', 799.81, 134.93, 464.23, 200.65],
    ['2026-03-09', 815.62, 135.55, 463.61, 216.46],
    ['2026-04-09', 815.62, 136.17, 462.99, 216.46],
    ['2026-05-11', 815.62, 136.80, 462.36, 216.46],
    ['2026-06-09', 815.62, 137.42, 461.74, 216.46],
    ['2026-07-09', 811.65, 138.05, 461.11, 212.49],
    ['2026-08-10', 811.65, 138.69, 460.47, 212.49],
    ['2026-09-09', 811.65, 139.32, 459.84, 212.49],
    ['2026-10-09', 811.65, 139.96, 459.20, 212.49],
    ['2026-11-09', 811.65, 140.60, 458.56, 212.49],
];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$bank = $one('Account', 'Name', 'First American Bank & Trust xx0207');
$loan = $one('Account', 'Name', 'Mr. Cooper Loan - 2734 Fizer Rd.');
$escrow = $one('Account', 'Name', '_Escrow 2734 Fizer');
$interestPaid = $one('Account', 'Name', 'Interest Paid');
$class = $one('Class', 'Name', '2734 Fizer Rd');
$rocket = $qbo->get('journalentry/3738')['JournalEntry']['Line'][0]['JournalEntryLineDetail']['Entity'];
foreach ($qbo->query("SELECT * FROM Transfer WHERE TxnDate >= '2026-02-01' MAXRESULTS 1000")['Transfer'] ?? [] as $transfer) {
    if ($transfer['ToAccountRef']['value'] == $loan['Id']) {
        fwrite(STDERR, "Transfer #{$transfer['Id']} {$transfer['TxnDate']} to the loan is already in QuickBooks: nothing changed\n");
        exit(1);
    }
}
$loanBalance = abs((float) $loan['CurrentBalance']);
$escrowBalance = (float) $escrow['CurrentBalance'];
foreach (PAYMENTS as [$date, $total, $principal, $interest, $escrowPart]) {
    if (abs($principal + $interest + $escrowPart - $total) > 0.001) {
        fwrite(STDERR, "{$date} doesn't add up: nothing changed\n");
        exit(1);
    }
    $loanBalance -= $principal;
    $escrowBalance += $escrowPart;
    printf("%s %7.2f = principal %6.2f + interest %6.2f + escrow %6.2f\n", $date, $total, $principal, $interest, $escrowPart);
}
printf("Loan %.2f -> %.2f; escrow %.2f -> %.2f\n", abs((float) $loan['CurrentBalance']), $loanBalance, (float) $escrow['CurrentBalance'], $escrowBalance);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$ids = [];
foreach (PAYMENTS as [$date, $total, $principal, $interest, $escrowPart]) {
    $ids[] = $qbo->create('Transfer', ['TxnDate' => $date, 'Amount' => $total, 'FromAccountRef' => ['value' => $bank['Id']], 'ToAccountRef' => ['value' => $loan['Id']],
        'PrivateNote' => 'Rocket Mortgage payment'])['Id'];
    $ids[] = $qbo->create('JournalEntry', ['TxnDate' => $date, 'Line' => [
        ['Amount' => $interest, 'DetailType' => 'JournalEntryLineDetail', 'JournalEntryLineDetail' => ['PostingType' => 'Credit', 'AccountRef' => ['value' => $loan['Id']], 'Entity' => $rocket]],
        ['Amount' => $interest, 'DetailType' => 'JournalEntryLineDetail', 'JournalEntryLineDetail' => ['PostingType' => 'Debit', 'AccountRef' => ['value' => $interestPaid['Id']], 'ClassRef' => ['value' => $class['Id']]]],
    ]])['Id'];
    $ids[] = $qbo->create('Transfer', ['TxnDate' => $date, 'Amount' => $escrowPart, 'FromAccountRef' => ['value' => $loan['Id']], 'ToAccountRef' => ['value' => $escrow['Id']]])['Id'];
}
echo count($ids).' entries: #'.min($ids).'-#'.max($ids)."\n";
printf("Loan now %s, escrow %s\n", $qbo->get('account/'.$loan['Id'])['Account']['CurrentBalance'], $qbo->get('account/'.$escrow['Id'])['Account']['CurrentBalance']);
