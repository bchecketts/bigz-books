#!/usr/bin/php
<?php

/**
 * NFCU mortgage payments Feb-Nov 2026 and escrow activity Jan 30 - Nov 2026, from NFCU's payment histories
 * (uploads/2699-durby-Payment_History_1_1_2026_-_11_14_2026.pdf, uploads/460-barnett-shoals-Payment_History_...pdf).
 * Entered like Jan 2 2026:
 *   - Durby: transfer First American -> mortgage, journal entry for the interest (Interest Paid, credit mortgage,
 *     NFCU, class 2699 Durby; like #3649), transfer mortgage -> escrow for the escrow part
 *   - Barnett (a Credit Card-type account): transfer First American -> mortgage, interest as an expense from the
 *     mortgage account (like #3626), transfer mortgage -> escrow
 *   - escrow interest credits as deposits to Interest Income (like #3608), taxes paid from escrow (Shelby County Tax
 *     for Durby like #3643; Clarke County Tax Commissioner for Barnett like #3616), insurance paid from escrow (USAA
 *     Insurance like #3607: assumed, the history doesn't name the insurer)
 *   - not entered: Barnett's $196.68 "Escrow" line on 2026-09-28, NFCU covering the shortage after the tax bill and
 *     taking it back from later payments (the escrow balance shows it); entering it would leave escrow $196.68 high.
 * Afterwards the mortgages and escrows show NFCU's balances: Durby 53,422.32 / 333.17, Barnett 22,805.01 / 401.84.
 *
 * OUTCOME: run 2026-11-15; 74 entries #4435-#4508. Mortgages and escrows match NFCU.
 *
 *   php maint/oneoff/2026-11-15-nfcu-mortgages.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const BANK = 'First American Bank & Trust xx0207';
// [date, total, principal, interest, escrow]
const DURBY = [
    ['2026-02-02', 655.72, 213.30, 330.20, 112.22], ['2026-03-02', 655.72, 214.56, 328.94, 112.22], ['2026-04-01', 655.72, 215.84, 327.66, 112.22],
    ['2026-05-01', 674.64, 217.12, 326.38, 131.14], ['2026-06-01', 674.64, 218.41, 325.09, 131.14], ['2026-07-01', 674.64, 219.70, 323.80, 131.14],
    ['2026-08-03', 674.64, 221.01, 322.49, 131.14], ['2026-09-01', 674.64, 222.32, 321.18, 131.14], ['2026-10-01', 674.64, 223.64, 319.86, 131.14],
    ['2026-11-02', 674.64, 224.97, 318.53, 131.14],
];
const BARNETT = [
    ['2026-02-02', 885.89, 470.99, 83.31, 331.59], ['2026-03-02', 877.60, 472.41, 81.89, 323.30], ['2026-04-01', 877.60, 473.84, 80.46, 323.30],
    ['2026-05-01', 877.60, 475.27, 79.03, 323.30], ['2026-06-01', 877.60, 476.70, 77.60, 323.30], ['2026-07-01', 877.60, 478.14, 76.16, 323.30],
    ['2026-08-03', 877.60, 479.59, 74.71, 323.30], ['2026-09-01', 877.60, 481.04, 73.26, 323.30], ['2026-10-01', 853.55, 482.49, 71.81, 299.25],
    ['2026-11-02', 853.55, 483.95, 70.35, 299.25],
];
// [escrow, date, amount (+ in, - out), what]
const ESCROW = [
    ['durby', '2026-07-31', -502.61, 'tax'], ['durby', '2026-07-31', 0.01, 'interest'], ['durby', '2026-10-13', -392.18, 'insurance'],
    ['barnett', '2026-01-30', 0.01, 'interest'], ['barnett', '2026-02-27', 0.01, 'interest'], ['barnett', '2026-03-31', 0.01, 'interest'],
    ['barnett', '2026-04-30', 0.02, 'interest'], ['barnett', '2026-05-29', 0.02, 'interest'], ['barnett', '2026-06-30', 0.02, 'interest'],
    ['barnett', '2026-07-28', -411.67, 'insurance'], ['barnett', '2026-07-31', 0.02, 'interest'], ['barnett', '2026-08-31', 0.02, 'interest'],
    ['barnett', '2026-09-25', -3465.03, 'tax'], ['barnett', '2026-09-30', 0.02, 'interest'],
];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$bank = $one('Account', 'Name', BANK);
$accounts = [
    'durby' => ['mortgage' => $one('Account', 'Name', 'NFCU Mortgage - 2699 Durby Circle'), 'escrow' => $one('Account', 'Name', 'NFCU Escrow - 2699 Durby'),
        'class' => $one('Class', 'Name', '2699 Durby'), 'tax' => '72'],
    'barnett' => ['mortgage' => $one('Account', 'Name', 'NFCU Mortgage - 460 Barnett Shoals E1'), 'escrow' => $one('Account', 'Name', 'NFCU Escrow - 460 Barnett Shoals Rd'),
        'class' => $one('Class', 'Name', '460 Barnett Shoals Rd'), 'tax' => '20'],
];
$interestPaid = $one('Account', 'Name', 'Interest Paid');
$interestIncome = $one('Account', 'Name', 'Interest Income');
$propertyTax = $one('Account', 'Name', 'Property Tax');
$insurance = $qbo->get('purchase/3607')['Purchase']['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef'];
$owner = $qbo->get('purchase/3818')['Purchase'];
$ownerEquity = $owner['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef'];
const NFCU = '26';
const USAA = '29';

foreach ($qbo->query("SELECT * FROM Transfer WHERE TxnDate >= '2026-02-01' MAXRESULTS 1000")['Transfer'] ?? [] as $transfer) {
    foreach ($accounts as $set) {
        if ($transfer['ToAccountRef']['value'] == $set['mortgage']['Id'] || $transfer['ToAccountRef']['value'] == $set['escrow']['Id']) {
            fwrite(STDERR, "Transfer #{$transfer['Id']} {$transfer['TxnDate']} to {$transfer['ToAccountRef']['name']} is already in QuickBooks: nothing changed\n");
            exit(1);
        }
    }
}

$balances = [];
foreach ($accounts as $key => $set) {
    $balances[$key] = ['mortgage' => abs((float) $set['mortgage']['CurrentBalance']), 'escrow' => (float) $set['escrow']['CurrentBalance']];
}
foreach (['durby' => DURBY, 'barnett' => BARNETT] as $key => $payments) {
    foreach ($payments as [$date, $total, $principal, $interest, $escrow]) {
        if (abs($principal + $interest + $escrow - $total) > 0.001) {
            fwrite(STDERR, "{$key} {$date} doesn't add up: nothing changed\n");
            exit(1);
        }
        $balances[$key]['mortgage'] -= $principal;
        $balances[$key]['escrow'] += $escrow;
        printf("%-7s %s payment %7.2f = principal %6.2f + interest %6.2f + escrow %6.2f\n", $key, $date, $total, $principal, $interest, $escrow);
    }
}
foreach (ESCROW as [$key, $date, $amount, $what]) {
    $balances[$key]['escrow'] += $amount;
    printf("%-7s %s escrow %9.2f  %s\n", $key, $date, $amount, $what == 'owner' ? 'deposit paid personally (Owner\'s Investment)' : $what);
}
foreach ($balances as $key => $balance) {
    printf("%-7s after: mortgage %.2f, escrow %.2f\n", $key, $balance['mortgage'], $balance['escrow']);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}

$transfer = fn ($date, $amount, $from, $to, $memo = '') => $qbo->create('Transfer', ['TxnDate' => $date, 'Amount' => $amount, 'FromAccountRef' => ['value' => $from],
    'ToAccountRef' => ['value' => $to], 'PrivateNote' => $memo])['Id'];
$ids = [];
foreach (['durby' => DURBY, 'barnett' => BARNETT] as $key => $payments) {
    $set = $accounts[$key];
    $class = ['value' => $set['class']['Id']];
    foreach ($payments as [$date, $total, $principal, $interest, $escrow]) {
        $ids[] = $transfer($date, $total, $bank['Id'], $set['mortgage']['Id'], 'NFCU mortgage payment');
        if ($key == 'durby') {
            $ids[] = $qbo->create('JournalEntry', ['TxnDate' => $date, 'Line' => [
                ['Amount' => $interest, 'DetailType' => 'JournalEntryLineDetail', 'JournalEntryLineDetail' => ['PostingType' => 'Credit', 'AccountRef' => ['value' => $set['mortgage']['Id']],
                    'ClassRef' => $class, 'Entity' => ['Type' => 'Vendor', 'EntityRef' => ['value' => NFCU]]]],
                ['Amount' => $interest, 'DetailType' => 'JournalEntryLineDetail', 'JournalEntryLineDetail' => ['PostingType' => 'Debit', 'AccountRef' => ['value' => $interestPaid['Id']], 'ClassRef' => $class]],
            ]])['Id'];
        } else {
            $ids[] = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'CreditCard', 'AccountRef' => ['value' => $set['mortgage']['Id']], 'EntityRef' => ['value' => NFCU, 'type' => 'Vendor'],
                'Line' => [['Amount' => $interest, 'DetailType' => 'AccountBasedExpenseLineDetail', 'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $interestPaid['Id']], 'ClassRef' => $class]]]])['Id'];
        }
        $ids[] = $transfer($date, $escrow, $set['mortgage']['Id'], $set['escrow']['Id']);
    }
}
foreach (ESCROW as [$key, $date, $amount, $what]) {
    $set = $accounts[$key];
    $class = ['value' => $set['class']['Id']];
    if ($amount > 0) {
        $entity = $what == 'owner' ? ['value' => $owner['EntityRef']['value'], 'type' => 'Customer'] : ['value' => NFCU, 'type' => 'Vendor'];
        $account = $what == 'owner' ? $ownerEquity['value'] : $interestIncome['Id'];
        $line = ['Amount' => $amount, 'DetailType' => 'DepositLineDetail', 'DepositLineDetail' => ['Entity' => $entity, 'AccountRef' => ['value' => $account]]];
        if ($what != 'owner') {
            $line['DepositLineDetail']['ClassRef'] = $class;
        }
        $ids[] = $qbo->create('Deposit', ['TxnDate' => $date, 'DepositToAccountRef' => ['value' => $set['escrow']['Id']], 'Line' => [$line],
            'PrivateNote' => $what == 'owner' ? 'Escrow shortage after the 2026 tax bill, paid personally' : ''])['Id'];
    } else {
        $vendor = $what == 'tax' ? $set['tax'] : USAA;
        $account = $what == 'tax' ? $propertyTax['Id'] : $insurance['value'];
        $ids[] = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $set['escrow']['Id']], 'EntityRef' => ['value' => $vendor, 'type' => 'Vendor'],
            'PrivateNote' => 'Paid from NFCU escrow',
            'Line' => [['Amount' => -$amount, 'DetailType' => 'AccountBasedExpenseLineDetail', 'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $account], 'ClassRef' => $class]]]])['Id'];
    }
}
echo count($ids).' entries: #'.min($ids).'-#'.max($ids)."\n";
foreach ($accounts as $key => $set) {
    $mortgage = $qbo->get('account/'.$set['mortgage']['Id'])['Account']['CurrentBalance'];
    $escrow = $qbo->get('account/'.$set['escrow']['Id'])['Account']['CurrentBalance'];
    echo "{$key}: mortgage {$mortgage}, escrow {$escrow}\n";
}
