#!/usr/bin/php
<?php

/**
 * $1,701.78 of 2026 Interest Paid had no class: five older-style interest entries put the property's class on the loan
 * line instead of the Interest Paid line (#3649 Durby, #3738 Fizer, #4128/#4129/#4132 412 Rivers Edge). Moves the class
 * to the Interest Paid line and takes it off the loan line (CLAUDE.md: classes on income/expense lines only). Amounts
 * unchanged. Full update (a sparse one keeps a ClassRef a line leaves out).
 *
 * OUTCOME: run 2026-11-16; all five fixed; no 2026 Interest Paid without a class.
 *
 *   php maint/oneoff/2026-11-16-interest-class-fix.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const ENTRIES = ['3649', '3738', '4128', '4129', '4132'];

$qbo = app::qbo();
$interestPaid = $qbo->query("SELECT * FROM Account WHERE Name = 'Interest Paid'")['Account'][0]['Id'];
$plan = [];
foreach (ENTRIES as $id) {
    $entry = $qbo->get('journalentry/'.$id)['JournalEntry'];
    $class = null;
    foreach ($entry['Line'] as $line) {
        $detail = $line['JournalEntryLineDetail'];
        if ($detail['AccountRef']['value'] != $interestPaid && isset($detail['ClassRef'])) {
            $class = $detail['ClassRef'];
        }
    }
    $interestLines = array_filter($entry['Line'], fn ($line) => $line['JournalEntryLineDetail']['AccountRef']['value'] == $interestPaid);
    $interestLine = reset($interestLines);
    if (count($entry['Line']) != 2 || !$class || !$interestLine || isset($interestLine['JournalEntryLineDetail']['ClassRef'])) {
        fwrite(STDERR, "JE #{$id} isn't as expected (already fixed?): nothing changed\n");
        exit(1);
    }
    $lines = [];
    foreach ($entry['Line'] as $line) {
        unset($line['CustomExtensions'], $line['JournalEntryLineDetail']['ClassRef']);
        if ($line['JournalEntryLineDetail']['AccountRef']['value'] == $interestPaid) {
            $line['JournalEntryLineDetail']['ClassRef'] = ['value' => $class['value']];
        }
        $lines[] = $line;
    }
    printf("JE #%s %s %8.2f: class %s moves from the loan line to Interest Paid\n", $id, $entry['TxnDate'], $interestLine['Amount'], $class['name']);
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
