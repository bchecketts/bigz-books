#!/usr/bin/php
<?php

/**
 * 1449 Eisenhower Ave was sold 2025-03-12 (1031 exchange; proceeds to the qualified intermediary). The closing entry
 * #3348 credited the property's fixed asset account with the full $445,000 sale price, but its cost on the books was
 * $201,000 (opening balance 2016), leaving the account at -$244,000: that's the gain. Moves it out the way the 2024
 * 132 Rivers Edge sale did (#2742, gain to Realized Capital Gains): journal entry dated 2025-03-12, debit
 * 1449 Eisenhower Ave $244,000, credit Realized Capital Gains $244,000 (no class: balance-sheet lines). The P&L doesn't
 * change; the account ends at $0 and is made inactive.
 *
 * OUTCOME: run 2026-11-16; journal entry #4585; 1449 Eisenhower Ave at $0 and inactive.
 *
 *   php maint/oneoff/2026-11-16-eisenhower-sale-gain.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const DATE = '2025-03-12';
const GAIN = 244000.00;

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$asset = $one('Account', 'Name', '1449 Eisenhower Ave');
$gains = $one('Account', 'Name', 'Realized Capital Gains');
if (!$asset || (float) $asset['CurrentBalance'] != -GAIN) {
    fwrite(STDERR, '1449 Eisenhower Ave is inactive or not at -'.GAIN.": nothing changed\n");
    exit(1);
}
printf("Journal entry %s: debit %s %.2f, credit %s %.2f (gain on the 1031 sale: $445,000 price - $201,000 cost)\n", DATE, $asset['Name'], GAIN, $gains['Name'], GAIN);
printf("Then make %s inactive (balance 0)\n", $asset['Name']);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$entry = $qbo->create('JournalEntry', ['TxnDate' => DATE, 'PrivateNote' => '1449 Eisenhower Ave sale (1031): gain left in the asset account by #3348',
    'Line' => [
        ['Amount' => GAIN, 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Remove sale price above cost', 'JournalEntryLineDetail' => ['PostingType' => 'Debit', 'AccountRef' => ['value' => $asset['Id']]]],
        ['Amount' => GAIN, 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Gain on sale of 1449 Eisenhower Ave ($445,000 - $201,000 cost), 1031 exchange',
            'JournalEntryLineDetail' => ['PostingType' => 'Credit', 'AccountRef' => ['value' => $gains['Id']]]],
    ]]);
echo "Journal entry #{$entry['Id']}\n";
$asset = $qbo->get('account/'.$asset['Id'])['Account'];
$saved = $qbo->update('Account', ['Id' => $asset['Id'], 'SyncToken' => $asset['SyncToken'], 'Name' => $asset['Name'], 'Active' => false]);
echo "{$saved['Name']}: balance {$asset['CurrentBalance']}, active=".($saved['Active'] ? 'yes' : 'no')."\n";
