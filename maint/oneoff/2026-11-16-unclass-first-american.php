#!/usr/bin/php
<?php

/**
 * Balance sheet by class showed First American with amounts under four properties. They came only from four closing
 * journal entries whose First American line had the property's class (everything else on the account has none):
 * #2070 (2023-01-18, 4716 Kings Castle sale, +346,345.45), #2130 (2023-02-28, 2734 Fizer Rd, -43,100.71),
 * #4017 (2026-04-15, 1020 Tuxedo, -121,045.58), #4020 (2026-04-15, 1020 Deni, -260,318.54). Removes the class from
 * those First American lines only; amounts, accounts and the other lines' classes are unchanged, so the P&L doesn't
 * change, and the bank account shows no classes (user's choice).
 *
 * OUTCOME: run 2026-11-16 (the first try, a sparse update, kept the classes: QuickBooks keeps a ClassRef a line leaves
 * out). The full update removed the class from the four First American lines only; nothing else changed.
 *
 *   php maint/oneoff/2026-11-16-unclass-first-american.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK_ID = '34';
const ENTRIES = ['2070' => 346345.45, '2130' => 43100.71, '4017' => 121045.58, '4020' => 260318.54];

$qbo = app::qbo();
$plan = [];
foreach (ENTRIES as $id => $amount) {
    $entry = $qbo->get('journalentry/'.$id)['JournalEntry'];
    $found = 0;
    foreach ($entry['Line'] as $index => $line) {
        $detail = $line['JournalEntryLineDetail'] ?? null;
        if ($detail && $detail['AccountRef']['value'] == BANK_ID && isset($detail['ClassRef']) && (float) $line['Amount'] == $amount) {
            printf("JE #%s %s line %s: %s %.2f First American, class %s -> no class\n", $id, $entry['TxnDate'], $line['Id'], $detail['PostingType'], $amount, $detail['ClassRef']['name']);
            unset($entry['Line'][$index]['JournalEntryLineDetail']['ClassRef']);
            $found++;
        }
    }
    if ($found != 1) {
        fwrite(STDERR, "JE #{$id} isn't as expected ({$found} classed First American lines): nothing changed\n");
        exit(1);
    }
    $plan[] = $entry;
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as $entry) {
    // A full update (sparse false): a sparse one keeps the ClassRef the line leaves out
    // of only the writable fields (QuickBooks rejects domain, MetaData, TotalAmt and the lines' CustomExtensions)
    $lines = array_map(function ($line) {
        unset($line['CustomExtensions']);
        return $line;
    }, array_values($entry['Line']));
    $writable = array_intersect_key($entry, array_flip(['Id', 'SyncToken', 'TxnDate', 'DocNumber', 'PrivateNote', 'CurrencyRef', 'Adjustment']));
    $saved = $qbo->update('JournalEntry', ['sparse' => false, 'Line' => $lines] + $writable);
    echo "JE #{$saved['Id']} saved (SyncToken {$saved['SyncToken']})\n";
}
