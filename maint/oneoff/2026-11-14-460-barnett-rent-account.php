#!/usr/bin/php
<?php

/**
 * Deposit #4072 (2026-09-01, Rent Oconee Clarke holding account, 460 Barnett Shoals Rd): its $1,850 of rent was in
 * Rent & Lease (an expense account), so the P&L showed -$1,850 rent expense. Moves that line to Rental Income.
 *
 * OUTCOME: run 2026-11-14; Deposit #4072 line 1 now Rental Income (SyncToken 3). Total and lines otherwise unchanged.
 *
 *   php maint/oneoff/2026-11-14-460-barnett-rent-account.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

$qbo = app::qbo();
$deposit = $qbo->get('deposit/4072')['Deposit'];
$rentalIncome = $qbo->query("SELECT * FROM Account WHERE FullyQualifiedName = 'Rental Income'")['Account'][0];

$changed = 0;
foreach ($deposit['Line'] as &$line) {
    $detail = &$line['DepositLineDetail'];
    $class = $detail['ClassRef']['name'] ?? '';
    if (($detail['AccountRef']['name'] ?? '') == 'Rent & Lease' && $line['Amount'] == 1850 && $class == '460 Barnett Shoals Rd') {
        echo "Line {$line['Id']}: {$line['Amount']} {$class}: Rent & Lease -> Rental Income\n";
        $detail['AccountRef'] = ['value' => $rentalIncome['Id'], 'name' => 'Rental Income'];
        $changed++;
    }
    unset($detail);
}
unset($line);
if ($deposit['TxnDate'] != '2026-09-01' || $changed != 1) {
    fwrite(STDERR, "Deposit #4072 isn't as expected ({$deposit['TxnDate']}, {$changed} matching lines): nothing changed\n");
    exit(1);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$saved = $qbo->update('Deposit', ['Id' => $deposit['Id'], 'SyncToken' => $deposit['SyncToken'], 'DepositToAccountRef' => $deposit['DepositToAccountRef'], 'Line' => $deposit['Line']]);
echo "Saved Deposit #{$saved['Id']} (SyncToken {$saved['SyncToken']})\n";
