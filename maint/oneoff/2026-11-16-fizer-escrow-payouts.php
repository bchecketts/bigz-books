#!/usr/bin/php
<?php

/**
 * Rocket Mortgage paid two 2026 bills for 2734 Fizer Rd from escrow (Rocket's "Taxes and insurance payment history",
 * pasted by the user 2026-11-16) that QuickBooks didn't have. Entered like 2025's (#3643-style expenses from
 * _Escrow 2734 Fizer): Jan 27 USAA homeowners insurance $555.33 (Insurance), Aug 13 City of Memphis city tax $892.31
 * (Property Tax), class 2734 Fizer Rd. Escrow goes from $2,634.45 to $1,186.81.
 *
 * OUTCOME: run 2026-11-16; Purchases #4613, #4614. Escrow $1,186.81.
 *
 *   php maint/oneoff/2026-11-16-fizer-escrow-payouts.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const ITEMS = [
    ['2026-01-27', 555.33, 'USAA Insurance', 'Insurance', 'Homeowners insurance, paid from Rocket escrow'],
    ['2026-08-13', 892.31, 'City of Memphis', 'Property Tax', 'City Tax, paid from Rocket escrow'],
];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$escrow = $one('Account', 'Name', '_Escrow 2734 Fizer');
$class = $one('Class', 'Name', '2734 Fizer Rd');
$existing = $qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-01-01' MAXRESULTS 1000")['Purchase'] ?? [];
foreach ($existing as $purchase) {
    if ($purchase['AccountRef']['value'] == $escrow['Id']) {
        fwrite(STDERR, "#{$purchase['Id']} {$purchase['TxnDate']} from the Fizer escrow is already in QuickBooks: nothing changed\n");
        exit(1);
    }
}
$plan = [];
$balance = (float) $escrow['CurrentBalance'];
foreach (ITEMS as [$date, $amount, $vendorName, $accountName, $memo]) {
    $vendor = $one('Vendor', 'DisplayName', $vendorName);
    $account = $one('Account', 'Name', $accountName);
    $balance -= $amount;
    printf("%s %7.2f  %s, %s, 2734 Fizer Rd: %s\n", $date, $amount, $vendor['DisplayName'], $account['Name'], $memo);
    $plan[] = [$date, $amount, $vendor, $account, $memo];
}
printf("Escrow %.2f -> %.2f\n", (float) $escrow['CurrentBalance'], $balance);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as [$date, $amount, $vendor, $account, $memo]) {
    $saved = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $escrow['Id']], 'EntityRef' => ['value' => $vendor['Id'], 'type' => 'Vendor'],
        'PrivateNote' => $memo, 'Line' => [['Amount' => $amount, 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => $memo,
            'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $account['Id']], 'ClassRef' => ['value' => $class['Id']]]]]]);
    echo "#{$saved['Id']} {$date} {$amount}\n";
}
echo 'Escrow now '.$qbo->get('account/'.$escrow['Id'])['Account']['CurrentBalance']."\n";
