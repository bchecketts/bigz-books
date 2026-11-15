#!/usr/bin/php
<?php

/**
 * Two First American items the user identified:
 *   - 2026-04-16 deposit $2,000: prorated Deni Ct rent at the purchase. Split over the four units by today's rents
 *     (Rent Oconee Clarke, Nov 2026: 1020 $1,400, 1022 $600, 1024 $1,400, 1026 $1,200), customer Renter.
 *   - 2026-07-23 ACH $1,375 to Five Star Painting for 1020 Tuxedo Ln: Repairs & Maintenance, generic payee Painting.
 *
 * OUTCOME: run 2026-11-15; Deposit #4308, Expense #4309.
 *
 *   php maint/oneoff/2026-11-15-deni-rent-and-painting.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const RENTS = ['1020 Deni Ct.' => 1400, '1022 Deni Ct' => 600, '1024 Deni Ct' => 1400, '1026 Deni Ct' => 1200];
const PRORATED = 2000.00;

$qbo = app::qbo();
$one = function (string $type, string $field, string $value) use ($qbo) {
    return $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
};
$bank = $one('Account', 'Name', 'First American Bank & Trust xx0207');
$rental = $one('Account', 'FullyQualifiedName', 'Rental Income');
$repairs = $one('Account', 'Name', 'Repairs & Maintenance');
$renter = $one('Customer', 'DisplayName', 'Renter');
$painting = $one('Vendor', 'DisplayName', 'Painting');
$classes = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classes[$class['FullyQualifiedName']] = $class['Id'];
}
$depositDup = array_filter($qbo->query("SELECT * FROM Deposit WHERE TxnDate = '2026-04-16'")['Deposit'] ?? [], fn ($d) => (float) $d['TotalAmt'] == PRORATED);
$purchaseDup = array_filter($qbo->query("SELECT * FROM Purchase WHERE TxnDate = '2026-07-23'")['Purchase'] ?? [], fn ($p) => (float) $p['TotalAmt'] == 1375.00);
if ($depositDup || $purchaseDup) {
    fwrite(STDERR, "Already in QuickBooks: nothing changed\n");
    exit(1);
}

$lines = [];
$soFar = 0;
$total = array_sum(RENTS);
foreach (RENTS as $class => $rent) {
    $amount = $class == array_key_last(RENTS) ? round(PRORATED - $soFar, 2) : round(PRORATED * $rent / $total, 2);
    $soFar += $amount;
    printf("Deposit 2026-04-16 %8.2f  Rental Income, Renter, %s\n", $amount, $class);
    $lines[] = ['Amount' => $amount, 'DetailType' => 'DepositLineDetail', 'Description' => 'Prorated rent at purchase',
        'DepositLineDetail' => ['Entity' => ['value' => $renter['Id'], 'type' => 'Customer'], 'AccountRef' => ['value' => $rental['Id']], 'ClassRef' => ['value' => $classes[$class]]]];
}
echo "Expense 2026-07-23 1375.00  Painting, Repairs & Maintenance, 1020 Tuxedo Ln. (Five Star Painting)\n";
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$deposit = $qbo->create('Deposit', ['TxnDate' => '2026-04-16', 'DepositToAccountRef' => ['value' => $bank['Id']], 'PrivateNote' => 'Prorated Deni Ct rent at purchase, split by current rents', 'Line' => $lines]);
echo "Deposit #{$deposit['Id']} {$deposit['TotalAmt']}\n";
$purchase = $qbo->create('Purchase', ['TxnDate' => '2026-07-23', 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank['Id']], 'EntityRef' => ['value' => $painting['Id'], 'type' => 'Vendor'],
    'PrivateNote' => 'Five Star Painting, 1020 Tuxedo Ln',
    'Line' => [['Amount' => 1375.00, 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => 'Five Star Painting, 1020 Tuxedo Ln',
        'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $repairs['Id']], 'ClassRef' => ['value' => $classes['1020 Tuxedo Ln.']]]]]]);
echo "Expense #{$purchase['Id']} {$purchase['TotalAmt']}\n";
