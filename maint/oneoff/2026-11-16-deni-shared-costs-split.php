#!/usr/bin/php
<?php

/**
 * Shared 1020-1026 Deni Ct costs were all on the 1020 Deni Ct. class. The user wants Bank Charges & Fees, Insurance,
 * Property Tax and Taxes & Licenses spread over the four units too, by rent like the interest (1020 $1,400, 1022 $600,
 * 1024 $1,400, 1026 $1,200). In 2026 those are: the April 15 closing (JE #4020: loan and title fees, recording and
 * transfer tax, the seller's prorated county tax credit) and the Auto-Owners insurance (Purchase #4294). Each such line
 * becomes four lines (same account, description and side); the entries' other lines and totals don't change.
 * Full updates (a sparse one keeps fields a line leaves out).
 *
 * OUTCOME: run 2026-11-16; JE #4020 (13 lines) and Purchase #4294 split over the four units; totals unchanged.
 *
 *   php maint/oneoff/2026-11-16-deni-shared-costs-split.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const ACCOUNTS = ['Bank Charges & Fees', 'Insurance', 'Property Tax', 'Taxes & Licenses'];
const RENTS = ['1020 Deni Ct.' => 1400, '1022 Deni Ct' => 600, '1024 Deni Ct' => 1400, '1026 Deni Ct' => 1200];
const TRANSACTIONS = [['JournalEntry', '4020', 'JournalEntryLineDetail'], ['Purchase', '4294', 'AccountBasedExpenseLineDetail']];

$qbo = app::qbo();
$classes = [];
foreach (array_keys(RENTS) as $class) {
    $classes[$class] = $qbo->query("SELECT * FROM Class WHERE Name = '".addslashes($class)."'")['Class'][0]['Id'];
}
$accountIds = [];
foreach ($qbo->query("SELECT * FROM Account MAXRESULTS 1000")['Account'] as $account) {
    if (in_array($account['FullyQualifiedName'], ACCOUNTS)) {
        $accountIds[$account['Id']] = $account['FullyQualifiedName'];
    }
}
$split = function (float $amount): array {
    $parts = [];
    $soFar = 0;
    $total = array_sum(RENTS);
    foreach (RENTS as $class => $rent) {
        $part = $class == array_key_last(RENTS) ? round($amount - $soFar, 2) : round($amount * $rent / $total, 2);
        $parts[$class] = $part;
        $soFar += $part;
    }
    return $parts;
};
$plan = [];
foreach (TRANSACTIONS as [$type, $id, $detailKey]) {
    $txn = $qbo->get(strtolower($type).'/'.$id)[$type];
    $lines = [];
    $split_count = 0;
    foreach ($txn['Line'] as $line) {
        $detail = $line[$detailKey] ?? null;
        unset($line['CustomExtensions']);
        $isShared = $detail && ($detail['ClassRef']['value'] ?? '') == $classes['1020 Deni Ct.'] && isset($accountIds[$detail['AccountRef']['value']])
            && !str_contains($line['Description'] ?? '', '(split by rent)');
        if (!$isShared) {
            if ($detail) {
                $lines[] = $line;
            }
            continue;
        }
        $split_count++;
        $parts = $split((float) $line['Amount']);
        printf("%s #%s %s %s %9.2f %s -> %s\n", $type, $id, $txn['TxnDate'], $detail['PostingType'] ?? '', $line['Amount'], $accountIds[$detail['AccountRef']['value']],
            implode(' / ', $parts));
        foreach ($parts as $class => $part) {
            $newDetail = $detail;
            $newDetail['ClassRef'] = ['value' => $classes[$class]];
            $lines[] = ['Amount' => $part, 'DetailType' => $line['DetailType'], 'Description' => trim(($line['Description'] ?? '').' (split by rent)'), $detailKey => $newDetail];
        }
    }
    if ($split_count == 0) {
        fwrite(STDERR, "{$type} #{$id} has nothing left to split: nothing changed\n");
        exit(1);
    }
    $plan[] = [$type, $txn, $lines];
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as [$type, $txn, $lines]) {
    $keep = $type == 'JournalEntry' ? ['Id', 'SyncToken', 'TxnDate', 'DocNumber', 'PrivateNote', 'CurrencyRef', 'Adjustment']
        : ['Id', 'SyncToken', 'TxnDate', 'DocNumber', 'PrivateNote', 'CurrencyRef', 'PaymentType', 'AccountRef', 'EntityRef', 'Credit'];
    $saved = $qbo->update($type, ['sparse' => false, 'Line' => $lines] + array_intersect_key($txn, array_flip($keep)));
    echo "{$type} #{$saved['Id']} saved, total ".($saved['TotalAmt'] ?? '')."\n";
}
