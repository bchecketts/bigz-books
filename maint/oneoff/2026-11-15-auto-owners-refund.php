#!/usr/bin/php
<?php

/**
 * 2026-07-20: $973.37 check from Auto-Owners Insurance deposited at First American: refund of the 148 Johnson Dr
 * premium after a policy change (paid #4299 May 20, paid again corrected #4306 Jul 21). Credited to Insurance, class
 * 148 Johnson Dr.
 *
 * OUTCOME: run 2026-11-15; Deposit #4307.
 *
 *   php maint/oneoff/2026-11-15-auto-owners-refund.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const DATE = '2026-07-20';
const AMOUNT = 973.37;
const MEMO = 'Auto-Owners Insurance refund, 148 Johnson Dr (policy change; premium paid again Jul 21)';

$qbo = app::qbo();
$bank = $qbo->query("SELECT * FROM Account WHERE Name = 'First American Bank & Trust xx0207'")['Account'][0];
$insurance = $qbo->get('purchase/4299')['Purchase']['Line'][0]['AccountBasedExpenseLineDetail'];
$vendor = $qbo->query("SELECT * FROM Vendor WHERE DisplayName = 'Auto-Owners Insurance'")['Vendor'][0];
foreach ($qbo->query("SELECT * FROM Deposit WHERE TxnDate = '".DATE."'")['Deposit'] ?? [] as $deposit) {
    if ((float) $deposit['TotalAmt'] == AMOUNT) {
        fwrite(STDERR, "Deposit #{$deposit['Id']} is already in QuickBooks: nothing changed\n");
        exit(1);
    }
}
printf("Deposit %s %.2f to %s: %s, %s, class %s\n", DATE, AMOUNT, $bank['Name'], $vendor['DisplayName'], $insurance['AccountRef']['name'], $insurance['ClassRef']['name']);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$saved = $qbo->create('Deposit', [
    'TxnDate' => DATE,
    'DepositToAccountRef' => ['value' => $bank['Id']],
    'PrivateNote' => MEMO,
    'Line' => [[
        'Amount' => AMOUNT,
        'DetailType' => 'DepositLineDetail',
        'Description' => MEMO,
        'DepositLineDetail' => ['Entity' => ['value' => $vendor['Id'], 'type' => 'Vendor'], 'AccountRef' => ['value' => $insurance['AccountRef']['value']], 'ClassRef' => ['value' => $insurance['ClassRef']['value']]],
    ]],
]);
echo "Deposit #{$saved['Id']} {$saved['TotalAmt']}\n";
