#!/usr/bin/php
<?php

/**
 * Two First American checks missing from QuickBooks, both improvements to the properties bought 2026-04-15, added to
 * each property's fixed asset account like earlier improvements (e.g. check 224, Mayweather Concrete, 148 Johnson Dr):
 *   - check 229, $30,026.00, cleared 2026-04-29: McGee Heating and Air, air conditioning units at 1020 Deni Ct
 *   - check 230, $6,700.00, cleared 2026-05-15: Mayweather Concrete (Finishing), new driveway at 1020 Tuxedo Ln
 * Dated when they cleared the bank (the bank CSV has no write dates). Creates the vendor McGee Heating and Air.
 *
 * OUTCOME: run 2026-11-15. Vendor #200000061 McGee Heating and Air; check 229 = Purchase #4266, check 230 = Purchase #4267.
 *
 *   php maint/oneoff/2026-11-15-deni-tuxedo-improvements.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK = 'First American Bank & Trust xx0207';
const CHECKS = [
    ['number' => '229', 'date' => '2026-04-29', 'amount' => 30026.00, 'vendor' => 'McGee Heating and Air', 'asset' => '1020 Deni Court', 'class' => '1020 Deni Ct.',
        'memo' => 'Air conditioning units, 1020 Deni Ct'],
    ['number' => '230', 'date' => '2026-05-15', 'amount' => 6700.00, 'vendor' => 'Mayweather Concrete', 'asset' => '1020 Tuxedo Lane', 'class' => '1020 Tuxedo Ln.',
        'memo' => 'Maywether Concrete Finishing: new driveway, 1020 Tuxedo Ln'],
];

$qbo = app::qbo();
$one = function (string $type, string $field, string $value) use ($qbo) {
    return $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
};
$bank = $one('Account', 'Name', BANK);
$plan = [];
foreach (CHECKS as $check) {
    $duplicate = $qbo->query("SELECT * FROM Purchase WHERE DocNumber = '{$check['number']}' AND TxnDate >= '2026-03-01'")['Purchase'] ?? [];
    if ($duplicate) {
        fwrite(STDERR, "Check {$check['number']} is already in QuickBooks (#{$duplicate[0]['Id']}): nothing changed\n");
        exit(1);
    }
    $vendor = $one('Vendor', 'DisplayName', $check['vendor']);
    $asset = $one('Account', 'Name', $check['asset']);
    $class = $one('Class', 'Name', $check['class']);
    if (!$bank || !$asset || !$class) {
        fwrite(STDERR, "Missing account or class for check {$check['number']}: nothing changed\n");
        exit(1);
    }
    printf("Check %s %s %10.2f %s%s -> %s (fixed asset), class %s: %s\n", $check['number'], $check['date'], $check['amount'], $check['vendor'], $vendor ? '' : ' (new vendor)', $check['asset'], $check['class'], $check['memo']);
    $plan[] = [$check, $vendor, $asset, $class];
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as [$check, $vendor, $asset, $class]) {
    if (!$vendor) {
        $vendor = $qbo->create('Vendor', ['DisplayName' => $check['vendor']]);
        echo "Created vendor #{$vendor['Id']} {$vendor['DisplayName']}\n";
    }
    $saved = $qbo->create('Purchase', [
        'PaymentType' => 'Check',
        'DocNumber' => $check['number'],
        'TxnDate' => $check['date'],
        'AccountRef' => ['value' => $bank['Id']],
        'EntityRef' => ['value' => $vendor['Id'], 'type' => 'Vendor'],
        'PrivateNote' => $check['memo'],
        'Line' => [[
            'Amount' => $check['amount'],
            'DetailType' => 'AccountBasedExpenseLineDetail',
            'Description' => $check['memo'],
            'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $asset['Id']], 'ClassRef' => ['value' => $class['Id']]],
        ]],
    ]);
    echo "Check {$check['number']}: Purchase #{$saved['Id']} {$saved['TotalAmt']}\n";
}
