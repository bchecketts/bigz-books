#!/usr/bin/php
<?php

/**
 * MidSouth Best Rentals' August 2026 statement has two identical $30 landfill charges for 2734 Fizer Rd; QuickBooks had
 * one (#4150), leaving the MidSouth holding account $30 above their balances from August on. The user said to treat
 * both as valid. Adds the second, a copy of #4150 (2026-08-31, MidSouth Renovations, Repairs & Maintenance, 2734 Fizer
 * Rd). Afterwards the holding account matches MidSouth's month-end balances (Oct 31: -5,549.96).
 *
 * OUTCOME: run 2026-11-16; Purchase #4615. Holding account -3,429.96 (after the Nov rents QuickBooks already had).
 *
 *   php maint/oneoff/2026-11-16-midsouth-second-landfill.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

$qbo = app::qbo();
$first = $qbo->get('purchase/4150')['Purchase'];
$sameDay = array_filter($qbo->query("SELECT * FROM Purchase WHERE TxnDate = '2026-08-31'")['Purchase'] ?? [],
    fn ($purchase) => $purchase['AccountRef']['value'] == $first['AccountRef']['value'] && (float) $purchase['TotalAmt'] == 30.0);
if (count($sameDay) != 1) {
    fwrite(STDERR, count($sameDay)." \$30 MidSouth charges on 2026-08-31 already: nothing changed\n");
    exit(1);
}
$detail = $first['Line'][0]['AccountBasedExpenseLineDetail'];
printf("Copy #%s: %s %.2f %s, %s, %s: %s (second charge)\n", $first['Id'], $first['TxnDate'], $first['TotalAmt'], $first['EntityRef']['name'], $detail['AccountRef']['name'],
    $detail['ClassRef']['name'], $first['PrivateNote'] ?? '');
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$saved = $qbo->create('Purchase', ['TxnDate' => $first['TxnDate'], 'PaymentType' => $first['PaymentType'], 'AccountRef' => ['value' => $first['AccountRef']['value']],
    'EntityRef' => ['value' => $first['EntityRef']['value'], 'type' => 'Vendor'], 'PrivateNote' => 'landfill services (second charge on the August statement)',
    'Line' => [['Amount' => 30.00, 'DetailType' => 'AccountBasedExpenseLineDetail',
        'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $detail['AccountRef']['value']], 'ClassRef' => ['value' => $detail['ClassRef']['value']]]]]]);
echo "#{$saved['Id']}; holding account now ".$qbo->get('account/'.$first['AccountRef']['value'])['Account']['CurrentBalance']."\n";
