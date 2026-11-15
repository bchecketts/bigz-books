#!/usr/bin/php
<?php

/**
 * Two First American checks the user identified:
 *   - check 228, $1,000, cleared 2026-03-31: Garcia Hasler, tax preparation deposit (Legal & Professional Services, no
 *     class, like #2948)
 *   - check 8221, $775, cleared 2026-06-16: landscaping at 2060 Lakeside Dr (Repairs & Maintenance, generic payee
 *     Landscaping)
 *
 * OUTCOME: run 2026-11-15; check 228 = Purchase #4428, check 8221 = Purchase #4429.
 *
 *   php maint/oneoff/2026-11-15-checks-228-8221.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const CHECKS = [
    ['number' => '228', 'date' => '2026-03-31', 'amount' => 1000.00, 'vendor' => 'Garcia Hasler', 'account' => 'Legal & Professional Services', 'class' => null,
        'memo' => 'Tax preparation deposit'],
    ['number' => '8221', 'date' => '2026-06-16', 'amount' => 775.00, 'vendor' => 'Landscaping', 'account' => 'Repairs & Maintenance', 'class' => '2060 Lakeside Dr',
        'memo' => 'Landscaping, 2060 Lakeside Dr'],
];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$bank = $one('Account', 'Name', 'First American Bank & Trust xx0207');
$plan = [];
foreach (CHECKS as $check) {
    if ($qbo->query("SELECT * FROM Purchase WHERE DocNumber = '{$check['number']}' AND TxnDate >= '2026-01-01'")['Purchase'] ?? []) {
        fwrite(STDERR, "Check {$check['number']} is already in QuickBooks: nothing changed\n");
        exit(1);
    }
    $vendor = $one('Vendor', 'DisplayName', $check['vendor']);
    $account = $one('Account', 'Name', $check['account']);
    $class = $check['class'] ? $one('Class', 'Name', $check['class']) : null;
    printf("Check %s %s %8.2f  %s, %s, %s: %s\n", $check['number'], $check['date'], $check['amount'], $vendor['DisplayName'], $account['Name'], $class['Name'] ?? 'no class', $check['memo']);
    $plan[] = [$check, $vendor, $account, $class];
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as [$check, $vendor, $account, $class]) {
    $detail = ['AccountRef' => ['value' => $account['Id']]];
    if ($class) {
        $detail['ClassRef'] = ['value' => $class['Id']];
    }
    $saved = $qbo->create('Purchase', ['PaymentType' => 'Check', 'DocNumber' => $check['number'], 'TxnDate' => $check['date'], 'AccountRef' => ['value' => $bank['Id']],
        'EntityRef' => ['value' => $vendor['Id'], 'type' => 'Vendor'], 'PrivateNote' => $check['memo'],
        'Line' => [['Amount' => $check['amount'], 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => $check['memo'], 'AccountBasedExpenseLineDetail' => $detail]]]);
    echo "Check {$check['number']}: Purchase #{$saved['Id']}\n";
}
