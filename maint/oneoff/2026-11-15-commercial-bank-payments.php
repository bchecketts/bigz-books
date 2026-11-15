#!/usr/bin/php
<?php

/**
 * The Commercial Bank loan payments from First American, May-Nov 2026, that QuickBooks doesn't have: transfers from
 * First American to each loan account at the bank's date (bank CSV uploads/ResearchTransactionsList.csv), assigned to
 * loans by the loans' own histories (uploads/*_xx65*_Transactions_*.csv; 2060's payments were $1,555.36 Jun-Sep).
 * Also corrects the two October Tuxedo payments already in QuickBooks: $1,244.28, not $1,224.28, and the first one
 * cleared Oct 13, not Oct 9 (#4188, #4189). Deni had no September payment; October has two (Oct 13 and 15).
 * The full payment goes to the loan; the interest in it is moved to Interest Paid by a journal entry on the payment
 * date (like #4180), from the balances in the bank's web view the user pasted (interest = payment - drop in balance),
 * through Nov 13. The Nov 13 Deni and Tuxedo payments were still pending at First American (posting Nov 16).
 * Afterwards each loan should show the bank's balance: Deni 361,597.78, Tuxedo 178,404.16, 2050 222,946.21,
 * 2060 222,946.17, 2070 221,835.32.
 * The Tuxedo interest entries already in QuickBooks (#4180, #4181, #4184, #4185) were a month off: corrected.
 *
 * OUTCOME: run 2026-11-15. 29 transfers and 31 interest journal entries, #4310-#4369; transfers #4188, #4189 and
 * journal entries #4180, #4181, #4184, #4185 corrected. All five loans match the bank's Nov 13 balances.
 *
 *   php maint/oneoff/2026-11-15-commercial-bank-payments.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK = 'First American Bank & Trust xx0207';
const LOANS = [
    'deni' => 'The Commercial Bank *6527 - 1020 Deni Ct',
    'tuxedo' => 'The Commercial Bank *6524 - 1020 Tuxedo',
    '2050' => 'The Commercial Bank *6528 - 2050 Lakeside Dr',
    '2060' => 'The Commercial Bank *6530 - 2060 Lakeside Dr',
    '2070' => 'The Commercial Bank *6494 - 2070 Lakeside Dr',
];
const PAYMENTS = [
    ['2026-05-15', 'deni', 2523.11], ['2026-06-16', 'deni', 2523.11], ['2026-07-15', 'deni', 2523.11], ['2026-08-17', 'deni', 2523.11],
    ['2026-10-13', 'deni', 2523.11], ['2026-10-15', 'deni', 2523.11], ['2026-11-13', 'deni', 2523.11], ['2026-11-13', 'tuxedo', 1244.28],
    ['2026-05-18', '2050', 1555.35], ['2026-05-18', '2060', 1555.35], ['2026-05-18', '2070', 1711.59],
    ['2026-06-11', '2050', 1555.35], ['2026-06-11', '2060', 1555.36], ['2026-06-11', '2070', 1711.59],
    ['2026-07-13', '2050', 1555.35], ['2026-07-13', '2060', 1555.36], ['2026-07-13', '2070', 1711.59],
    ['2026-08-11', '2050', 1555.35], ['2026-08-11', '2060', 1555.36], ['2026-08-11', '2070', 1711.59],
    ['2026-09-10', '2050', 1555.35], ['2026-09-10', '2060', 1555.36], ['2026-09-10', '2070', 1711.59],
    ['2026-10-28', '2050', 1555.35], ['2026-10-28', '2060', 1555.35], ['2026-10-28', '2070', 1711.59],
    ['2026-11-09', '2050', 1555.35], ['2026-11-09', '2060', 1555.35], ['2026-11-09', '2070', 1711.59],
];
// [date, loan, interest, class]
const INTEREST = [
    ['2026-05-15', 'deni', 2523.11, '1020 Deni Ct.'], ['2026-06-16', 'deni', 2025.00, '1020 Deni Ct.'],
    ['2026-07-15', 'deni', 2157.05, '1020 Deni Ct.'], ['2026-08-17', 'deni', 1952.86, '1020 Deni Ct.'],
    ['2026-10-13', 'deni', 2218.75, '1020 Deni Ct.'], ['2026-10-15', 'deni', 1440.41, '1020 Deni Ct.'], ['2026-11-13', 'deni', 1942.37, '1020 Deni Ct.'],
    ['2026-10-13', 'tuxedo', 1052.33, '1020 Tuxedo Ln.'], ['2026-10-15', 'tuxedo', 711.21, '1020 Tuxedo Ln.'], ['2026-11-13', 'tuxedo', 958.32, '1020 Tuxedo Ln.'],
    ['2026-05-18', '2050', 1555.35, '2050 Lakeside Dr'], ['2026-06-11', '2050', 1555.35, '2050 Lakeside Dr'], ['2026-07-13', '2050', 1024.44, '2050 Lakeside Dr'],
    ['2026-08-11', '2050', 1328.36, '2050 Lakeside Dr'], ['2026-09-10', '2050', 1202.62, '2050 Lakeside Dr'],
    ['2026-10-28', '2050', 1242.12, '2050 Lakeside Dr'], ['2026-11-09', '2050', 925.42, '2050 Lakeside Dr'],
    ['2026-05-18', '2060', 1555.35, '2060 Lakeside Dr'], ['2026-06-11', '2060', 1555.36, '2060 Lakeside Dr'], ['2026-07-13', '2060', 1024.44, '2060 Lakeside Dr'],
    ['2026-08-11', '2060', 1328.36, '2060 Lakeside Dr'], ['2026-09-10', '2060', 1202.62, '2060 Lakeside Dr'],
    ['2026-10-28', '2060', 1242.11, '2060 Lakeside Dr'], ['2026-11-09', '2060', 925.42, '2060 Lakeside Dr'],
    ['2026-05-18', '2070', 1711.59, '2070 Lakeside Dr'], ['2026-06-11', '2070', 1581.16, '2070 Lakeside Dr'], ['2026-07-13', '2070', 998.05, '2070 Lakeside Dr'],
    ['2026-08-11', '2070', 1326.51, '2070 Lakeside Dr'], ['2026-09-10', '2070', 1200.09, '2070 Lakeside Dr'],
    ['2026-10-28', '2070', 1238.63, '2070 Lakeside Dr'], ['2026-11-09', '2070', 760.42, '2070 Lakeside Dr'],
];
// Tuxedo interest entries already in QuickBooks (made 2026-11-10) are a month off: each payment's interest from the
// bank balances. #4187 ($62.21, Sep 25) stays: the 5% late charge on the September payment, part of the Oct 9 drop.
const TUXEDO_CORRECTIONS = [
    '4180' => ['date' => '2026-05-15', 'from' => 998.63, 'to' => 1224.28],
    '4181' => ['date' => '2026-06-16', 'from' => 1063.87, 'to' => 998.63],
    '4184' => ['date' => '2026-07-15', 'from' => 963.27, 'to' => 1063.87],
    '4185' => ['date' => '2026-08-17', 'from' => 1094.54, 'to' => 963.27],
];
const TUXEDO_FIXES = [['id' => '4188', 'date' => '2026-10-13', 'amount' => 1244.28], ['id' => '4189', 'date' => '2026-10-15', 'amount' => 1244.28]];

$qbo = app::qbo();
$bank = $qbo->query("SELECT * FROM Account WHERE Name = '".BANK."'")['Account'][0];
$loanIds = [];
foreach (LOANS as $key => $name) {
    $loanIds[$key] = $qbo->query("SELECT * FROM Account WHERE Name = '".addslashes($name)."'")['Account'][0]['Id'];
}
$existing = $qbo->query("SELECT * FROM Transfer WHERE TxnDate >= '2026-05-01' MAXRESULTS 1000")['Transfer'] ?? [];
foreach (PAYMENTS as [$date, $loan, $amount]) {
    foreach ($existing as $transfer) {
        if ($transfer['TxnDate'] == $date && (float) $transfer['Amount'] == $amount && $transfer['ToAccountRef']['value'] == $loanIds[$loan]) {
            fwrite(STDERR, "Transfer #{$transfer['Id']} {$date} {$amount} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
    printf("Transfer %s %8.2f  First American -> %s\n", $date, $amount, LOANS[$loan]);
}
$fixes = [];
foreach (TUXEDO_FIXES as $fix) {
    $transfer = $qbo->get('transfer/'.$fix['id'])['Transfer'];
    printf("Correct transfer #%s %s %.2f -> %s %.2f (%s)\n", $fix['id'], $transfer['TxnDate'], $transfer['Amount'], $fix['date'], $fix['amount'], $transfer['ToAccountRef']['name']);
    if ((float) $transfer['Amount'] != 1224.28 && (float) $transfer['Amount'] != $fix['amount']) {
        fwrite(STDERR, "Transfer #{$fix['id']} isn't as expected: nothing changed\n");
        exit(1);
    }
    $fixes[] = [$fix, $transfer];
}
$classes = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classes[$class['FullyQualifiedName']] = $class['Id'];
}
$interestAccount = $qbo->query("SELECT * FROM Account WHERE Name = 'Interest Paid'")['Account'][0];
$existingInterest = $qbo->query("SELECT * FROM JournalEntry WHERE TxnDate >= '2026-05-01' MAXRESULTS 1000")['JournalEntry'] ?? [];
foreach (INTEREST as [$date, $loan, $amount, $class]) {
    foreach ($existingInterest as $entry) {
        foreach ($entry['Line'] as $line) {
            $sameLoan = $line['JournalEntryLineDetail']['AccountRef']['value'] == $loanIds[$loan];
            if ($entry['TxnDate'] == $date && $sameLoan && (float) $line['Amount'] == $amount) {
                fwrite(STDERR, "Interest {$date} {$loan} {$amount} is already in QuickBooks (#{$entry['Id']}): nothing changed\n");
                exit(1);
            }
        }
    }
    if (!isset($classes[$class])) {
        fwrite(STDERR, "No class {$class}: nothing changed\n");
        exit(1);
    }
    printf("Interest %s %8.2f  %s -> Interest Paid, %s\n", $date, $amount, LOANS[$loan], $class);
}
$corrections = [];
foreach (TUXEDO_CORRECTIONS as $id => $fix) {
    $entry = $qbo->get('journalentry/'.$id)['JournalEntry'];
    $amounts = array_unique(array_map(fn ($line) => (float) $line['Amount'], $entry['Line']));
    if ($entry['TxnDate'] != $fix['date'] || $amounts != [$fix['from']]) {
        fwrite(STDERR, "Journal entry #{$id} isn't as expected: nothing changed\n");
        exit(1);
    }
    printf("Correct journal entry #%s %s Tuxedo interest %.2f -> %.2f\n", $id, $fix['date'], $fix['from'], $fix['to']);
    $corrections[] = [$entry, $fix];
}
$tuxedoChange = array_sum(array_map(fn ($fix) => $fix['to'] - $fix['from'], TUXEDO_CORRECTIONS));
$expected = ['deni' => 361597.78, 'tuxedo' => 178404.16, '2050' => 222946.21, '2060' => 222946.17, '2070' => 221835.32];
foreach ($expected as $loan => $bankBalance) {
    $current = abs((float) $qbo->get('account/'.$loanIds[$loan])['Account']['CurrentBalance']);
    $paid = array_sum(array_map(fn ($p) => $p[1] == $loan ? $p[2] : 0, PAYMENTS)) + ($loan == 'tuxedo' ? 2 * (1244.28 - 1224.28) : 0);
    $interest = array_sum(array_map(fn ($i) => $i[1] == $loan ? $i[2] : 0, INTEREST)) + ($loan == 'tuxedo' ? $tuxedoChange : 0);
    $after = round($current - $paid + $interest, 2);
    printf("%-6s QuickBooks now %11.2f -> after %11.2f (bank %11.2f)%s\n", $loan, $current, $after, $bankBalance, abs($after - $bankBalance) < 0.005 ? '' : '  MISMATCH');
}
printf("Total new payments: %.2f; interest: %.2f\n", array_sum(array_column(PAYMENTS, 2)), array_sum(array_column(INTEREST, 2)) + $tuxedoChange);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach (PAYMENTS as [$date, $loan, $amount]) {
    $saved = $qbo->create('Transfer', ['TxnDate' => $date, 'Amount' => $amount, 'FromAccountRef' => ['value' => $bank['Id']], 'ToAccountRef' => ['value' => $loanIds[$loan]],
        'PrivateNote' => 'Loan payment (interest split out separately)']);
    echo "Transfer #{$saved['Id']} {$date} {$amount} {$loan}\n";
}
foreach ($fixes as [$fix, $transfer]) {
    $saved = $qbo->update('Transfer', ['Id' => $transfer['Id'], 'SyncToken' => $transfer['SyncToken'], 'TxnDate' => $fix['date'], 'Amount' => $fix['amount'],
        'FromAccountRef' => $transfer['FromAccountRef'], 'ToAccountRef' => $transfer['ToAccountRef']]);
    echo "Transfer #{$saved['Id']} now {$saved['TxnDate']} {$saved['Amount']}\n";
}
foreach (INTEREST as [$date, $loan, $amount, $class]) {
    $detail = fn ($posting, $accountId) => ['PostingType' => $posting, 'AccountRef' => ['value' => $accountId], 'ClassRef' => ['value' => $classes[$class]]];
    $saved = $qbo->create('JournalEntry', ['TxnDate' => $date, 'PrivateNote' => 'Interest in the '.LOANS[$loan].' payment', 'Line' => [
        ['Amount' => $amount, 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Loan interest', 'JournalEntryLineDetail' => $detail('Debit', $interestAccount['Id'])],
        ['Amount' => $amount, 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Loan interest', 'JournalEntryLineDetail' => $detail('Credit', $loanIds[$loan])],
    ]]);
    echo "Journal entry #{$saved['Id']} {$date} {$loan} interest {$amount}\n";
}
foreach ($corrections as [$entry, $fix]) {
    foreach ($entry['Line'] as &$line) {
        $line['Amount'] = $fix['to'];
    }
    unset($line);
    $saved = $qbo->update('JournalEntry', ['Id' => $entry['Id'], 'SyncToken' => $entry['SyncToken'], 'Line' => $entry['Line']]);
    echo "Journal entry #{$saved['Id']} now {$saved['Line'][0]['Amount']}\n";
}
