#!/usr/bin/php
<?php

/**
 * Debit card meals while working on 1020 Tuxedo Ln (user): Taqueria La P... Jun 22 and Jun 24 ($47.42 each) and
 * "PY *ATHENS 2 - NORTHST" Aug 11 ($38.07). Meals & Entertainment, class 1020 Tuxedo Ln., vendor Meals (generic).
 *
 * OUTCOME: run 2026-11-15; Purchases #4543-#4545 (vendor Meals created).
 *
 *   php maint/oneoff/2026-11-15-tuxedo-meals.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const ITEMS = [['2026-06-22', 47.42, 'Taqueria'], ['2026-06-24', 47.42, 'Taqueria'], ['2026-08-11', 38.07, 'PY *ATHENS 2 - NORTHST']];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$bank = $one('Account', 'Name', 'First American Bank & Trust xx0207');
$meals = $one('Account', 'Name', 'Meals & Entertainment');
$class = $one('Class', 'Name', '1020 Tuxedo Ln.');
$vendor = $one('Vendor', 'DisplayName', 'Meals');
$existing = $qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-06-01' AND TxnDate <= '2026-08-31' MAXRESULTS 1000")['Purchase'] ?? [];
foreach (ITEMS as [$date, $amount, $memo]) {
    foreach ($existing as $purchase) {
        if ($purchase['TxnDate'] == $date && (float) $purchase['TotalAmt'] == $amount && $purchase['AccountRef']['value'] == $bank['Id']) {
            fwrite(STDERR, "#{$purchase['Id']} {$date} {$amount} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
    printf("%s %6.2f  Meals%s, Meals & Entertainment, 1020 Tuxedo Ln.: %s\n", $date, $amount, $vendor ? '' : ' (new vendor)', $memo);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$vendor ??= $qbo->create('Vendor', ['DisplayName' => 'Meals']);
foreach (ITEMS as [$date, $amount, $memo]) {
    $saved = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank['Id']], 'EntityRef' => ['value' => $vendor['Id'], 'type' => 'Vendor'],
        'PrivateNote' => "{$memo}: meal while working on 1020 Tuxedo Ln",
        'Line' => [['Amount' => $amount, 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => $memo,
            'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $meals['Id']], 'ClassRef' => ['value' => $class['Id']]]]]]);
    echo "#{$saved['Id']} {$date} {$amount}\n";
}
