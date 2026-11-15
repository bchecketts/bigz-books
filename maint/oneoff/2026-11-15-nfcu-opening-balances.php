#!/usr/bin/php
<?php

/**
 * Connecting the NFCU bank feed (2026-11-08) created two loan accounts, Mortgage (4948) (= NFCU Mortgage - 2699 Durby
 * Circle) and Mortgage (2916) (= NFCU Mortgage - 460 Barnett Shoals E1), each with an "Opening Balance from Bank"
 * journal entry against Opening Balance Equity (#4177 $53,422.32, #4178 $22,805.01). The loans were already on the
 * books, so those entries counted them twice. Deletes the two entries and makes the two accounts inactive (the user
 * left the loan feeds disconnected; payments are entered from the First American side).
 *
 * OUTCOME: not run yet.
 *
 *   php maint/oneoff/2026-11-15-nfcu-opening-balances.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const EXPECTED = [
    '4177' => ['account' => '100', 'name' => 'Mortgage (4948)', 'amount' => 53422.32],
    '4178' => ['account' => '101', 'name' => 'Mortgage (2916)', 'amount' => 22805.01],
];

$qbo = app::qbo();
$entries = [];
$accounts = [];
foreach (EXPECTED as $entryId => $expected) {
    $entry = $qbo->get('journalentry/'.$entryId)['JournalEntry'];
    $account = $qbo->get('account/'.$expected['account'])['Account'];
    $lines = [];
    foreach ($entry['Line'] as $line) {
        $detail = $line['JournalEntryLineDetail'];
        $lines[] = "{$detail['PostingType']} {$detail['AccountRef']['name']} {$line['Amount']}";
    }
    $credit = "Credit {$expected['name']} {$expected['amount']}";
    $debit = "Debit Opening Balance Equity {$expected['amount']}";
    $asExpected = $entry['TxnDate'] == '2026-11-08' && count($lines) == 2 && in_array($credit, $lines) && in_array($debit, $lines);
    if (!$asExpected || $account['Name'] != $expected['name']) {
        fwrite(STDERR, "Journal entry #{$entryId} or account {$expected['account']} isn't as expected: nothing changed\n  ".implode("\n  ", $lines)."\n");
        exit(1);
    }
    echo "Delete journal entry #{$entryId} {$entry['TxnDate']}: ".implode(', ', $lines)."\n";
    echo "Make inactive: account {$account['Id']} {$account['Name']} (balance after delete: 0)\n";
    $entries[] = $entry;
    $accounts[] = $account;
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($entries as $entry) {
    $qbo->delete('JournalEntry', $entry['Id'], $entry['SyncToken']);
    echo "Deleted journal entry #{$entry['Id']}\n";
}
foreach ($accounts as $account) {
    $saved = $qbo->update('Account', ['Id' => $account['Id'], 'SyncToken' => $account['SyncToken'], 'Name' => $account['Name'], 'Active' => false]);
    echo "Account {$saved['Id']} {$saved['Name']}: active=".($saved['Active'] ? 'yes' : 'no')."\n";
}
