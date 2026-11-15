#!/usr/bin/php
<?php

/**
 * 2026-03-10: $4,000 from First American to Farmers savings. The two banks can't transfer to each other, so it went
 * through Wells Fargo (First American "DDA TO DDA WELLS FARGO" debit and Farmers "DDA TO DDA" deposit, both Mar 10).
 * Recorded as a direct transfer with Wells Fargo in the memo, like the 2026-02-03 one already in QuickBooks.
 *
 * OUTCOME: run 2026-11-15; Transfer #4290.
 *
 *   php maint/oneoff/2026-11-15-farmers-transfer-via-wf.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const DATE = '2026-03-10';
const AMOUNT = 4000.00;
const MEMO = "transfer to Farmer's through Wells Fargo (First American and Farmers can't transfer to each other)";

$qbo = app::qbo();
$from = $qbo->query("SELECT * FROM Account WHERE Name = 'First American Bank & Trust xx0207'")['Account'][0];
$to = $qbo->query("SELECT * FROM Account WHERE Name = 'The Farmers Bank Business Savings'")['Account'][0];
foreach ($qbo->query("SELECT * FROM Transfer WHERE TxnDate >= '2026-03-05' AND TxnDate <= '2026-03-15'")['Transfer'] ?? [] as $transfer) {
    if ((float) $transfer['Amount'] == AMOUNT) {
        fwrite(STDERR, "Transfer #{$transfer['Id']} {$transfer['TxnDate']} {$transfer['Amount']} is already in QuickBooks: nothing changed\n");
        exit(1);
    }
}
printf("Transfer %s %.2f from %s to %s: %s\n", DATE, AMOUNT, $from['Name'], $to['Name'], MEMO);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$saved = $qbo->create('Transfer', ['TxnDate' => DATE, 'Amount' => AMOUNT, 'FromAccountRef' => ['value' => $from['Id']], 'ToAccountRef' => ['value' => $to['Id']], 'PrivateNote' => MEMO]);
echo "Transfer #{$saved['Id']}\n";
