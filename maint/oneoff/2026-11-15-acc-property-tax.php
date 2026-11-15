#!/usr/bin/php
<?php

/**
 * Athens-Clarke County property tax 2026: First American check 8223, $24,786.89, cleared 2026-10-20. Amounts per
 * property from the user. Johnson Dr (146/148/152) is one parcel with one bill ($8,429.61), divided like the 2025 bill
 * (Purchase #3778: 1,294.11 / 3,100.31 / 773.33). 460 Barnett Shoals is paid from its NFCU escrow, not on this check.
 * Entered like #3778: payee Athens Clarke County, Property Tax by property class.
 *
 * OUTCOME: run 2026-11-15; check 8223 = Purchase #4268, $24,786.89.
 *
 *   php maint/oneoff/2026-11-15-acc-property-tax.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK = 'First American Bank & Trust xx0207';
const CHECK = '8223';
const DATE = '2026-10-20';
const TOTAL = 24786.89;
const JOHNSON = 8429.61;
const JOHNSON_2025 = ['146 Johnson Dr' => 1294.11, '148 Johnson Dr' => 3100.31, '152 Johnson Dr' => 773.33];
const AMOUNTS = ['2050 Lakeside Dr' => 4726.98, '2060 Lakeside Dr' => 4726.98, '2070 Lakeside Dr' => 4732.33, '412 Rivers Edge' => 2170.99];

$qbo = app::qbo();
$amounts = AMOUNTS;
$johnsonTotal = array_sum(JOHNSON_2025);
$johnsonSoFar = 0;
foreach (JOHNSON_2025 as $property => $lastYear) {
    $share = $property == array_key_last(JOHNSON_2025) ? round(JOHNSON - $johnsonSoFar, 2) : round(JOHNSON * $lastYear / $johnsonTotal, 2);
    $amounts[$property] = $share;
    $johnsonSoFar += $share;
}
if (abs(array_sum($amounts) - TOTAL) > 0.001) {
    fwrite(STDERR, 'Amounts add up to '.array_sum($amounts).', not '.TOTAL.": nothing changed\n");
    exit(1);
}
$duplicate = $qbo->query("SELECT * FROM Purchase WHERE DocNumber = '".CHECK."'")['Purchase'] ?? [];
if ($duplicate) {
    fwrite(STDERR, "Check ".CHECK." is already in QuickBooks (#{$duplicate[0]['Id']}): nothing changed\n");
    exit(1);
}
$lastYear = $qbo->get('purchase/3778')['Purchase'];
$bank = $qbo->query("SELECT * FROM Account WHERE Name = '".BANK."'")['Account'][0];
$taxAccount = $qbo->query("SELECT * FROM Account WHERE Name = 'Property Tax'")['Account'][0];
$classes = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classes[$class['FullyQualifiedName']] = $class['Id'];
}
$lines = [];
foreach ($amounts as $property => $amount) {
    printf("  %10.2f  Property Tax | %s\n", $amount, $property);
    $lines[] = [
        'Amount' => $amount,
        'DetailType' => 'AccountBasedExpenseLineDetail',
        'Description' => '2026 Athens-Clarke County property tax',
        'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $taxAccount['Id']], 'ClassRef' => ['value' => $classes[$property]]],
    ];
}
printf("Check %s %s %.2f to %s from %s\n", CHECK, DATE, array_sum($amounts), $lastYear['EntityRef']['name'], BANK);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$saved = $qbo->create('Purchase', [
    'PaymentType' => 'Check',
    'DocNumber' => CHECK,
    'TxnDate' => DATE,
    'AccountRef' => ['value' => $bank['Id']],
    'EntityRef' => $lastYear['EntityRef'],
    'PrivateNote' => '2026 property tax: Lakeside (3), 412 Rivers Edge, Johnson Dr (one bill, divided like 2025)',
    'Line' => $lines,
]);
echo "Check ".CHECK.": Purchase #{$saved['Id']} {$saved['TotalAmt']}\n";
