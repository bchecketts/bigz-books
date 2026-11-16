#!/usr/bin/php
<?php

/**
 * JE #4187 (2026-09-25, $62.21): the late charge on the Tuxedo loan's September payment (5% of $1,244.28), debit Bank
 * Charges & Fees with no class, credit the TCB Tuxedo loan with class 1020 Deni Ct. Puts the class on the Bank Charges
 * line as 1020 Tuxedo Ln. (the loan it's on) and takes it off the loan line (CLAUDE.md). Amounts unchanged; full update.
 *
 * OUTCOME: run 2026-11-16; JE #4187 fixed.
 *
 *   php maint/oneoff/2026-11-16-tuxedo-late-fee-class.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

$qbo = app::qbo();
$fees = $qbo->query("SELECT * FROM Account WHERE Name = 'Bank Charges & Fees'")['Account'][0]['Id'];
$tuxedo = $qbo->query("SELECT * FROM Class WHERE Name = '1020 Tuxedo Ln.'")['Class'][0]['Id'];
$entry = $qbo->get('journalentry/4187')['JournalEntry'];
$feeLines = array_filter($entry['Line'], fn ($line) => $line['JournalEntryLineDetail']['AccountRef']['value'] == $fees);
$feeLine = reset($feeLines);
if (count($entry['Line']) != 2 || !$feeLine || (float) $feeLine['Amount'] != 62.21 || isset($feeLine['JournalEntryLineDetail']['ClassRef'])) {
    fwrite(STDERR, "JE #4187 isn't as expected (already fixed?): nothing changed\n");
    exit(1);
}
$lines = [];
foreach ($entry['Line'] as $line) {
    unset($line['CustomExtensions'], $line['JournalEntryLineDetail']['ClassRef']);
    if ($line['JournalEntryLineDetail']['AccountRef']['value'] == $fees) {
        $line['JournalEntryLineDetail']['ClassRef'] = ['value' => $tuxedo];
    }
    $lines[] = $line;
}
echo "JE #4187 {$entry['TxnDate']} 62.21: Bank Charges & Fees -> class 1020 Tuxedo Ln.; loan line without class\n";
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$writable = array_intersect_key($entry, array_flip(['Id', 'SyncToken', 'TxnDate', 'DocNumber', 'PrivateNote', 'CurrencyRef', 'Adjustment']));
$saved = $qbo->update('JournalEntry', ['sparse' => false, 'Line' => $lines] + $writable);
echo "JE #{$saved['Id']} saved\n";
