#!/usr/bin/php
<?php

/**
 * 1020-1026 Deni Ct is one 4-plex with one loan (TCB Loan 1020 Deni Ct *6527); its interest was all on the 1020 Deni Ct.
 * class. The user wants shared costs split over the four units, interest by rent: 1020 $1,400, 1022 $600, 1024 $1,400,
 * 1026 $1,200 (Rent Oconee Clarke, Nov 2026). Rewrites the seven 2026 interest journal entries (#4339-#4345): the
 * Interest Paid line becomes four lines by unit, and the loan line loses its class (CLAUDE.md: no classes on balance-
 * sheet lines). Amounts, dates and the loan balance don't change. Full update (a sparse one keeps a ClassRef left out).
 *
 * OUTCOME: run 2026-11-16; #4339-#4345 split; loan balance unchanged ($361,597.78).
 *
 *   php maint/oneoff/2026-11-16-deni-interest-split.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const ENTRIES = ['4339', '4340', '4341', '4342', '4343', '4344', '4345'];
const RENTS = ['1020 Deni Ct.' => 1400, '1022 Deni Ct' => 600, '1024 Deni Ct' => 1400, '1026 Deni Ct' => 1200];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$interestPaid = $one('Account', 'Name', 'Interest Paid');
$loan = $one('Account', 'Name', 'TCB Loan 1020 Deni Ct *6527');
$classes = [];
foreach (array_keys(RENTS) as $class) {
    $classes[$class] = $one('Class', 'Name', $class)['Id'];
}
$split = function (float $amount): array {
    $parts = [];
    $soFar = 0;
    $total = array_sum(RENTS);
    foreach (RENTS as $class => $rent) {
        $part = $class == array_key_last(RENTS) ? round($amount - $soFar, 2) : round($amount * $rent / $total, 2);
        $parts[$class] = $part;
        $soFar += $part;
    }
    return $parts;
};
$plan = [];
foreach (ENTRIES as $id) {
    $entry = $qbo->get('journalentry/'.$id)['JournalEntry'];
    $interestLines = array_filter($entry['Line'], fn ($line) => $line['JournalEntryLineDetail']['AccountRef']['value'] == $interestPaid['Id']);
    $loanLines = array_filter($entry['Line'], fn ($line) => $line['JournalEntryLineDetail']['AccountRef']['value'] == $loan['Id']);
    $interest = reset($interestLines);
    $asExpected = count($entry['Line']) == 2 && count($interestLines) == 1 && count($loanLines) == 1
        && ($interest['JournalEntryLineDetail']['ClassRef']['value'] ?? '') == $classes['1020 Deni Ct.'];
    if (!$asExpected) {
        fwrite(STDERR, "JE #{$id} isn't as expected (already split?): nothing changed\n");
        exit(1);
    }
    $amount = (float) $interest['Amount'];
    $parts = $split($amount);
    printf("JE #%s %s interest %8.2f -> %s\n", $id, $entry['TxnDate'], $amount, implode(' / ', array_map(fn ($class, $part) => "{$class} {$part}", array_keys($parts), $parts)));
    $loanLine = reset($loanLines);
    $lines = [['Amount' => $amount, 'DetailType' => 'JournalEntryLineDetail', 'Description' => $loanLine['Description'] ?? 'Loan interest',
        'JournalEntryLineDetail' => ['PostingType' => 'Credit', 'AccountRef' => ['value' => $loan['Id']]]]];
    foreach ($parts as $class => $part) {
        $lines[] = ['Amount' => $part, 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Loan interest, split by rent',
            'JournalEntryLineDetail' => ['PostingType' => 'Debit', 'AccountRef' => ['value' => $interestPaid['Id']], 'ClassRef' => ['value' => $classes[$class]]]];
    }
    $plan[] = [$entry, $lines];
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as [$entry, $lines]) {
    $writable = array_intersect_key($entry, array_flip(['Id', 'SyncToken', 'TxnDate', 'DocNumber', 'PrivateNote', 'CurrencyRef', 'Adjustment']));
    $saved = $qbo->update('JournalEntry', ['sparse' => false, 'Line' => $lines] + $writable);
    echo "JE #{$saved['Id']} saved\n";
}
echo 'Loan balance: '.$qbo->get('account/'.$loan['Id'])['Account']['CurrentBalance']."\n";
