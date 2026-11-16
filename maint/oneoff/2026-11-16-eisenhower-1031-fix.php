#!/usr/bin/php
<?php

/**
 * Corrects #4585 (2026-11-16-eisenhower-sale-gain.php): the 1449 Eisenhower sale was a 1031 exchange, so the $244,000
 * gain is deferred, not realized. The accountant's year-end entry #4024 (2025-12-31, "65 - to tie to TR") already took
 * the deferred gain off the replacement properties (146/148/152 Johnson Dr, $270,960.00) with the other side in Opening
 * Balance Equity. Moves #4585's $244,000 credit from Realized Capital Gains to Opening Balance Equity, so it offsets
 * that; about $26,960 stays in Opening Balance Equity for the accountant (tax-only basis items). Full update (a sparse
 * one keeps fields a line leaves out); nothing else in #4585 changes. P&L unchanged.
 *
 * OUTCOME: run 2026-11-16; #4585 now credits Opening Balance Equity. Realized Capital Gains back to -164,250.
 *
 *   php maint/oneoff/2026-11-16-eisenhower-1031-fix.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$gains = $one('Account', 'Name', 'Realized Capital Gains');
$openingEquity = $one('Account', 'Name', 'Opening Balance Equity');
$entry = $qbo->get('journalentry/4585')['JournalEntry'];
$changed = 0;
foreach ($entry['Line'] as &$line) {
    $detail = &$line['JournalEntryLineDetail'];
    if ($detail['AccountRef']['value'] == $gains['Id'] && $detail['PostingType'] == 'Credit' && (float) $line['Amount'] == 244000.0) {
        printf("JE #4585 %s line %s: Credit %.2f %s -> %s\n", $entry['TxnDate'], $line['Id'], $line['Amount'], $gains['Name'], $openingEquity['Name']);
        $detail['AccountRef'] = ['value' => $openingEquity['Id']];
        $line['Description'] = 'Gain deferred by the 1031 exchange: offsets the accountant\'s 1031 adjustment to the Johnson Dr basis (#4024)';
        $changed++;
    }
    unset($detail, $line['CustomExtensions']);
}
unset($line);
if ($changed != 1) {
    fwrite(STDERR, "JE #4585 isn't as expected ({$changed} matching lines): nothing changed\n");
    exit(1);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$writable = array_intersect_key($entry, array_flip(['Id', 'SyncToken', 'TxnDate', 'DocNumber', 'CurrencyRef', 'Adjustment']));
$saved = $qbo->update('JournalEntry', ['sparse' => false, 'Line' => array_values($entry['Line']),
    'PrivateNote' => '1449 Eisenhower Ave sale (1031): gain left in the asset account by #3348, deferred into the Johnson Dr basis'] + $writable);
echo "JE #{$saved['Id']} saved (SyncToken {$saved['SyncToken']})\n";
foreach ([$gains, $openingEquity] as $account) {
    echo $account['Name'].': '.$qbo->get('account/'.$account['Id'])['Account']['CurrentBalance']."\n";
}
