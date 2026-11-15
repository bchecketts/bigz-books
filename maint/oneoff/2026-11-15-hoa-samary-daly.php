#!/usr/bin/php
<?php

/**
 * Routine First American items from 2026 that QuickBooks didn't have (bank CSV uploads/ResearchTransactionsList.csv),
 * entered like the Dec 2025 - Jan 2026 ones:
 *   - HOA dues, HOA Fees by class: Retreat South HOA ($156.50 x3, 2050/2060/2070 Lakeside, like #3814-#3816),
 *     Rivers Edge HOA ($215.50, 412 Rivers Edge, like #3813), Stones Creek HOA ($259, 460 Barnett Shoals, like #3817)
 *   - Samary & Co rent for 148 Johnson Dr: Rental Income (like #3820)
 *   - Daly payout #4035 ($1,794) reached the bank Nov 5, not Oct 15: date corrected
 *
 * OUTCOME: run 2026-11-15; 58 entries #4370-#4427 (49 HOA, 9 Samary); transfer #4035 now 2026-11-05.
 *
 *   php maint/oneoff/2026-11-15-hoa-samary-daly.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK = 'First American Bank & Trust xx0207';
const RETREAT = ['2026-02-06', '2026-03-06', '2026-04-06', '2026-05-06', '2026-06-08', '2026-07-06', '2026-08-06', '2026-09-08', '2026-10-06', '2026-11-06'];
const RIVERS = ['2026-03-02', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-03', '2026-09-01', '2026-10-01', '2026-11-02'];
const STONES = ['2026-02-06', '2026-03-06', '2026-04-07', '2026-05-14', '2026-06-11', '2026-07-13', '2026-08-06', '2026-09-09', '2026-10-06', '2026-11-06'];
const SAMARY = [['2026-03-02', 1175.00], ['2026-04-01', 2350.00], ['2026-04-28', 2548.00], ['2026-06-01', 2350.00], ['2026-06-26', 2350.00],
    ['2026-08-04', 2350.00], ['2026-09-01', 2201.50], ['2026-09-30', 2350.00], ['2026-11-02', 2350.00]];
const DALY = ['id' => '4035', 'from' => '2026-10-15', 'to' => '2026-11-05', 'amount' => 1794.00];

$qbo = app::qbo();
$bank = $qbo->query("SELECT * FROM Account WHERE Name = '".BANK."'")['Account'][0];
$template = fn ($id) => $qbo->get('purchase/'.$id)['Purchase'];
$retreat = $template('3814');
$rivers = $template('3813');
$stones = $template('3817');
$samary = $qbo->get('deposit/3820')['Deposit']['Line'][0]['DepositLineDetail'];
$classes = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classes[$class['FullyQualifiedName']] = $class['Id'];
}

$purchases = [];
$addHoa = function (array $sample, string $date, float $amount, string $class) use (&$purchases, $classes) {
    $detail = $sample['Line'][0]['AccountBasedExpenseLineDetail'];
    $purchases[] = ['date' => $date, 'amount' => $amount, 'entity' => $sample['EntityRef'], 'account' => $detail['AccountRef'], 'class' => ['value' => $classes[$class], 'name' => $class]];
};
foreach (RETREAT as $date) {
    foreach (['2050 Lakeside Dr', '2060 Lakeside Dr', '2070 Lakeside Dr'] as $class) {
        $addHoa($retreat, $date, 156.50, $class);
    }
}
foreach (RIVERS as $date) {
    $addHoa($rivers, $date, 215.50, '412 Rivers Edge');
}
foreach (STONES as $date) {
    $addHoa($stones, $date, 259.00, '460 Barnett Shoals Rd');
}

$existingPurchases = $qbo->query("SELECT * FROM Purchase WHERE TxnDate >= '2026-02-01' MAXRESULTS 1000")['Purchase'] ?? [];
$existingDeposits = $qbo->query("SELECT * FROM Deposit WHERE TxnDate >= '2026-02-01' MAXRESULTS 1000")['Deposit'] ?? [];
foreach ($purchases as $item) {
    foreach ($existingPurchases as $purchase) {
        if ($purchase['TxnDate'] == $item['date'] && ($purchase['EntityRef']['value'] ?? '') == $item['entity']['value']) {
            fwrite(STDERR, "#{$purchase['Id']} {$item['date']} {$item['entity']['name']} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
}
foreach (SAMARY as [$date, $amount]) {
    foreach ($existingDeposits as $deposit) {
        if ($deposit['TxnDate'] == $date && (float) $deposit['TotalAmt'] == $amount) {
            fwrite(STDERR, "Deposit #{$deposit['Id']} {$date} {$amount} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
}
$daly = $qbo->get('transfer/'.DALY['id'])['Transfer'];
if ($daly['TxnDate'] != DALY['from'] || (float) $daly['Amount'] != DALY['amount']) {
    fwrite(STDERR, "Transfer #".DALY['id']." isn't as expected ({$daly['TxnDate']} {$daly['Amount']}): nothing changed\n");
    exit(1);
}

$totals = [];
foreach ($purchases as $item) {
    $key = $item['entity']['name'].' ('.$item['class']['name'].')';
    $totals[$key] = ($totals[$key] ?? 0) + $item['amount'];
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}
foreach ($totals as $key => $total) {
    printf("%2d x HOA Fees  %-45s %9.2f\n", $counts[$key], $key, $total);
}
printf("%2d x Rental Income Samary & Co LLC (148 Johnson Dr)            %9.2f  (%s)\n", count(SAMARY), array_sum(array_column(SAMARY, 1)), implode(', ', array_map(fn ($s) => "{$s[0]} {$s[1]}", SAMARY)));
printf("Transfer #%s Daly %.2f: %s -> %s\n", DALY['id'], DALY['amount'], DALY['from'], DALY['to']);
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$ids = [];
foreach ($purchases as $item) {
    $saved = $qbo->create('Purchase', ['TxnDate' => $item['date'], 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank['Id']], 'EntityRef' => $item['entity'],
        'Line' => [['Amount' => $item['amount'], 'DetailType' => 'AccountBasedExpenseLineDetail',
            'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $item['account']['value']], 'ClassRef' => ['value' => $item['class']['value']]]]]]);
    $ids[] = $saved['Id'];
}
foreach (SAMARY as [$date, $amount]) {
    $saved = $qbo->create('Deposit', ['TxnDate' => $date, 'DepositToAccountRef' => ['value' => $bank['Id']],
        'Line' => [['Amount' => $amount, 'DetailType' => 'DepositLineDetail', 'DepositLineDetail' => [
            'Entity' => $samary['Entity'], 'AccountRef' => ['value' => $samary['AccountRef']['value']], 'ClassRef' => ['value' => $samary['ClassRef']['value']]]]]]);
    $ids[] = $saved['Id'];
}
echo count($ids).' entries: #'.min($ids).'-#'.max($ids)."\n";
$saved = $qbo->update('Transfer', ['Id' => $daly['Id'], 'SyncToken' => $daly['SyncToken'], 'TxnDate' => DALY['to'], 'Amount' => $daly['Amount'],
    'FromAccountRef' => $daly['FromAccountRef'], 'ToAccountRef' => $daly['ToAccountRef']]);
echo "Transfer #{$saved['Id']} now {$saved['TxnDate']}\n";
