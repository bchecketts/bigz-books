#!/usr/bin/php
<?php

/**
 * Landscaping for the 1020-1026 Deni Ct 4-plex (Rent Oconee Clarke lists it under 1020 Deni Court) is for the whole lot:
 * split over the four units by rent like the other building-wide costs (user, 2026-11-16). Rewrites the three 2026
 * charges already in QuickBooks (#4083, #4195, #4240) into four lines each; rent_oconee_clarke.php now splits new ones
 * (SHARED_COSTS). Totals unchanged. Full updates (a sparse one keeps a ClassRef a line leaves out).
 *
 * OUTCOME: run 2026-11-16; #4083, #4195, #4240 split; Oct and Nov statements still preview 0 new entries.
 *
 *   php maint/oneoff/2026-11-16-deni-landscaping-split.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const PURCHASES = ['4083', '4195', '4240'];
const RENTS = ['1020 Deni Ct.' => 1400, '1022 Deni Ct' => 600, '1024 Deni Ct' => 1400, '1026 Deni Ct' => 1200];

$qbo = app::qbo();
$classes = [];
foreach (array_keys(RENTS) as $class) {
    $classes[$class] = $qbo->query("SELECT * FROM Class WHERE Name = '".addslashes($class)."'")['Class'][0]['Id'];
}
$plan = [];
foreach (PURCHASES as $id) {
    $purchase = $qbo->get('purchase/'.$id)['Purchase'];
    $detail = $purchase['Line'][0]['AccountBasedExpenseLineDetail'] ?? [];
    $asExpected = count($purchase['Line']) == 1 && ($detail['ClassRef']['value'] ?? '') == $classes['1020 Deni Ct.'] && ($purchase['EntityRef']['name'] ?? '') == 'Landscaping';
    if (!$asExpected) {
        fwrite(STDERR, "Purchase #{$id} isn't as expected (already split?): nothing changed\n");
        exit(1);
    }
    $amount = (float) $purchase['TotalAmt'];
    $lines = [];
    $soFar = 0;
    foreach (RENTS as $class => $rent) {
        $part = $class == array_key_last(RENTS) ? round($amount - $soFar, 2) : round($amount * $rent / array_sum(RENTS), 2);
        $soFar += $part;
        $lines[] = ['Amount' => $part, 'DetailType' => 'AccountBasedExpenseLineDetail', 'Description' => 'Landscaping, split by rent',
            'AccountBasedExpenseLineDetail' => ['AccountRef' => $detail['AccountRef'], 'ClassRef' => ['value' => $classes[$class]]]];
    }
    printf("Purchase #%s %s %.2f -> %s\n", $id, $purchase['TxnDate'], $amount, implode(' / ', array_column($lines, 'Amount')));
    $plan[] = [$purchase, $lines];
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as [$purchase, $lines]) {
    $keep = ['Id', 'SyncToken', 'TxnDate', 'DocNumber', 'PrivateNote', 'CurrencyRef', 'PaymentType', 'AccountRef', 'EntityRef', 'Credit'];
    $saved = $qbo->update('Purchase', ['sparse' => false, 'Line' => $lines] + array_intersect_key($purchase, array_flip($keep)));
    echo "Purchase #{$saved['Id']} saved, total {$saved['TotalAmt']}\n";
}
