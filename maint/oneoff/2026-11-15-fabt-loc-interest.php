#!/usr/bin/php
<?php

/**
 * First American line of credit (loan x8224), from its 2026 history (pasted by the user 2026-11-15):
 *   - Feb 20 $696.92 and Mar 20 $616.74: interest-only payments swept from checking. Entered like Jan 20 (#3671 and
 *     the transfer): interest journal entry split over the three Lakeside classes, then a transfer from First American.
 *   - The Apr 10 payoff ($109,885.73) was $109,373.17 principal + $462.56 interest + a $50 recording fee. The Lakeside
 *     refinance entries (#4264 less the #4265 refund) put all $512.56 in interest: $50 moves to Taxes & Licenses.
 *
 * OUTCOME: run 2026-11-15; journal entries #4430, #4432 and transfers #4431, #4433 (Feb, Mar); fee reclass #4434.
 *
 *   php maint/oneoff/2026-11-15-fabt-loc-interest.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const PAYMENTS = ['2026-02-20' => 696.92, '2026-03-20' => 616.74];
const CLASSES = ['2050 Lakeside Dr', '2060 Lakeside Dr', '2070 Lakeside Dr'];
const RECORDING_FEE = 50.00;

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$bank = $one('Account', 'Name', 'First American Bank & Trust xx0207');
$loc = $one('Account', 'Name', 'FABT Line of Credit');
$interest = $one('Account', 'Name', 'Interest Paid');
$taxes = $one('Account', 'Name', 'Taxes & Licenses');
$classIds = [];
foreach (CLASSES as $class) {
    $classIds[$class] = $one('Class', 'Name', $class)['Id'];
}
foreach ($qbo->query("SELECT * FROM Transfer WHERE TxnDate >= '2026-02-01' AND TxnDate <= '2026-03-31'")['Transfer'] ?? [] as $transfer) {
    if ($transfer['ToAccountRef']['value'] == $loc['Id']) {
        fwrite(STDERR, "Transfer #{$transfer['Id']} {$transfer['TxnDate']} to the line of credit is already in QuickBooks: nothing changed\n");
        exit(1);
    }
}

$thirds = function (float $amount): array {
    $first = round($amount / 3, 2);
    return [CLASSES[0] => $first, CLASSES[1] => $first, CLASSES[2] => round($amount - 2 * $first, 2)];
};
$line = fn ($posting, $amount, $accountId, $classId, $description) => ['Amount' => $amount, 'DetailType' => 'JournalEntryLineDetail', 'Description' => $description,
    'JournalEntryLineDetail' => array_filter(['PostingType' => $posting, 'AccountRef' => ['value' => $accountId], 'ClassRef' => $classId ? ['value' => $classId] : null])];
foreach (PAYMENTS as $date => $amount) {
    printf("%s interest %.2f (%s), then transfer %.2f First American -> FABT Line of Credit\n", $date, $amount, implode(' / ', $thirds($amount)), $amount);
}
printf("2026-04-10 reclassify %.2f of the payoff from Interest Paid to Taxes & Licenses (recording fee), split over Lakeside\n", RECORDING_FEE);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach (PAYMENTS as $date => $amount) {
    $lines = [];
    foreach ($thirds($amount) as $class => $part) {
        $lines[] = $line('Credit', $part, $loc['Id'], $classIds[$class], 'Line of credit interest');
        $lines[] = $line('Debit', $part, $interest['Id'], $classIds[$class], 'Line of credit interest');
    }
    $entry = $qbo->create('JournalEntry', ['TxnDate' => $date, 'PrivateNote' => 'FABT line of credit interest', 'Line' => $lines]);
    $transfer = $qbo->create('Transfer', ['TxnDate' => $date, 'Amount' => $amount, 'FromAccountRef' => ['value' => $bank['Id']], 'ToAccountRef' => ['value' => $loc['Id']],
        'PrivateNote' => 'Interest Payment']);
    echo "{$date}: journal entry #{$entry['Id']}, transfer #{$transfer['Id']}\n";
}
$lines = [];
foreach ($thirds(RECORDING_FEE) as $class => $part) {
    $lines[] = $line('Debit', $part, $taxes['Id'], $classIds[$class], 'Recording fee in the line of credit payoff');
    $lines[] = $line('Credit', $part, $interest['Id'], $classIds[$class], 'Recording fee in the line of credit payoff');
}
$entry = $qbo->create('JournalEntry', ['TxnDate' => '2026-04-10', 'PrivateNote' => '[Lakeside refinance 4/10/26] $50 of the line of credit payoff was a recording fee, not interest', 'Line' => $lines]);
echo "2026-04-10: journal entry #{$entry['Id']}\n";
