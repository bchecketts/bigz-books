#!/usr/bin/php
<?php

/**
 * "NFCU Mortgage - 460 Barnett Shoals E1" (account 51) was a Credit Card account (made by a 2020 bank feed); it's a
 * mortgage. QuickBooks refused the type change: "used on a Check ... where an account of type 'Cash' is required".
 * The cause is the feed's opening entry #251 (2020-01-01, $58,392.87, Opening Balance Equity), stored as a check-type
 * transaction (TxnType 1). The 83 monthly interest expenses (TxnType 54) don't block it: tested in the Intuit sandbox
 * (MouseDining's dev connection, allowed by the user for that one test), where an account with the same kind of
 * expenses, transfers and journal entries changed to Long Term Liability.
 * Replaces #251 with an equivalent journal entry (same date, amount and accounts), then changes the type to
 * Long Term Liability / Notes Payable like the Durby mortgage. Balances and P&L don't change.
 *
 * OUTCOME: run 2026-11-16 (a first try, type change only, was refused as above). Journal entry #4586 replaced Purchase
 * #251; the account is now Long Term Liability / Notes Payable, balance still $22,805.01.
 *
 *   php maint/oneoff/2026-11-16-barnett-mortgage-type.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const ACCOUNT = '51';
const OPENING = ['id' => '251', 'date' => '2020-01-01', 'amount' => 58392.87];

$qbo = app::qbo();
$account = $qbo->get('account/'.ACCOUNT)['Account'];
if ($account['Name'] != 'NFCU Mortgage - 460 Barnett Shoals E1' || $account['AccountType'] != 'Credit Card') {
    fwrite(STDERR, "Account 51 is {$account['Name']} ({$account['AccountType']}): nothing changed\n");
    exit(1);
}
$opening = $qbo->get('purchase/'.OPENING['id'])['Purchase'];
$line = $opening['Line'][0]['AccountBasedExpenseLineDetail'];
$asExpected = $opening['TxnDate'] == OPENING['date'] && (float) $opening['TotalAmt'] == OPENING['amount'] && $opening['AccountRef']['value'] == ACCOUNT
    && count($opening['Line']) == 1;
if (!$asExpected) {
    fwrite(STDERR, "Purchase #".OPENING['id']." isn't as expected: nothing changed\n");
    exit(1);
}
printf("Replace Purchase #%s (%s, %.2f, %s -> %s, \"%s\") with a journal entry: debit %s, credit %s\n", $opening['Id'], $opening['TxnDate'], $opening['TotalAmt'],
    $account['Name'], $line['AccountRef']['name'], $opening['PrivateNote'] ?? '', $line['AccountRef']['name'], $account['Name']);
printf("Then %s: %s/%s -> Long Term Liability/NotesPayable (balance %s)\n", $account['Name'], $account['AccountType'], $account['AccountSubType'], $account['CurrentBalance']);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$entry = $qbo->create('JournalEntry', ['TxnDate' => $opening['TxnDate'], 'PrivateNote' => 'Opening Balance from Bank (was Purchase #'.$opening['Id'].')', 'Line' => [
    ['Amount' => OPENING['amount'], 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Opening Balance from Bank',
        'JournalEntryLineDetail' => ['PostingType' => 'Debit', 'AccountRef' => ['value' => $line['AccountRef']['value']]]],
    ['Amount' => OPENING['amount'], 'DetailType' => 'JournalEntryLineDetail', 'Description' => 'Opening Balance from Bank',
        'JournalEntryLineDetail' => ['PostingType' => 'Credit', 'AccountRef' => ['value' => ACCOUNT]]],
]]);
echo "Journal entry #{$entry['Id']}\n";
$qbo->delete('Purchase', $opening['Id'], $opening['SyncToken']);
echo "Deleted Purchase #{$opening['Id']}\n";
$account = $qbo->get('account/'.ACCOUNT)['Account'];
echo "Balance after the swap: {$account['CurrentBalance']}\n";
$saved = $qbo->update('Account', ['Id' => $account['Id'], 'SyncToken' => $account['SyncToken'], 'Name' => $account['Name'],
    'AccountType' => 'Long Term Liability', 'AccountSubType' => 'NotesPayable']);
echo "{$saved['Name']}: {$saved['AccountType']}/{$saved['AccountSubType']}, balance {$saved['CurrentBalance']}\n";
