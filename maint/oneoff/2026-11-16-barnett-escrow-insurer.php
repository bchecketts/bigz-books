#!/usr/bin/php
<?php

/**
 * The $411.67 insurance NFCU paid from the 460 Barnett Shoals escrow on 2026-07-28 was entered with payee USAA (a guess,
 * 2026-11-15-nfcu-mortgages.php). The user confirmed NFCU's escrow pays Barnett's Auto-Owners policy (56-483665-03),
 * so the payee becomes Auto-Owners Insurance. Amount, account and class unchanged.
 *
 * OUTCOME: run 2026-11-16; Purchase #4504 payee now Auto-Owners Insurance.
 *
 *   php maint/oneoff/2026-11-16-barnett-escrow-insurer.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

$qbo = app::qbo();
$escrow = $qbo->query("SELECT * FROM Account WHERE Name = 'NFCU Escrow - 460 Barnett Shoals Rd'")['Account'][0]['Id'];
$autoOwners = $qbo->query("SELECT * FROM Vendor WHERE DisplayName = 'Auto-Owners Insurance'")['Vendor'][0];
$matches = array_values(array_filter($qbo->query("SELECT * FROM Purchase WHERE TxnDate = '2026-07-28'")['Purchase'] ?? [],
    fn ($purchase) => $purchase['AccountRef']['value'] == $escrow && (float) $purchase['TotalAmt'] == 411.67));
if (count($matches) != 1 || ($matches[0]['EntityRef']['value'] ?? '') == $autoOwners['Id']) {
    fwrite(STDERR, "No single \$411.67 USAA payment from the Barnett escrow on 2026-07-28 (already changed?): nothing changed\n");
    exit(1);
}
$purchase = $matches[0];
echo "Purchase #{$purchase['Id']} {$purchase['TxnDate']} {$purchase['TotalAmt']}: payee {$purchase['EntityRef']['name']} -> {$autoOwners['DisplayName']}\n";
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$saved = $qbo->update('Purchase', ['Id' => $purchase['Id'], 'SyncToken' => $purchase['SyncToken'], 'PaymentType' => $purchase['PaymentType'],
    'AccountRef' => $purchase['AccountRef'], 'EntityRef' => ['value' => $autoOwners['Id'], 'type' => 'Vendor']]);
echo "Purchase #{$saved['Id']}: payee {$saved['EntityRef']['name']}, total {$saved['TotalAmt']}\n";
