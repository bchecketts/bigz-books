#!/usr/bin/php
<?php

/**
 * Schwab Pledged Asset Line (in Big Z's name; SOFR + 2.9%, 6.78% in Nov 2026), treated as a mortgage. Activity from
 * Schwab's transaction list (pasted by the user 2026-11-15; limit $934,041) and First American's bank CSV:
 *   - 2026-04-21: advance $501,525.05 paid off The Farmers Bank line of credit (loan 7310; QuickBooks balance
 *     $497,721.39). The rest, $3,803.66, is taken as interest: the payments from Farmers savings that QuickBooks
 *     doesn't have yet are assumed to be interest only.
 *   - Interest isn't listed by Schwab; it's the change in balance the transactions don't explain, charged on the
 *     monthly payment date. Split 59/22/19 over 148/146/152 Johnson Dr like the Farmers line it replaced.
 *   - Payments from First American by MoneyLink (bank dates) and the two advances wired to First American.
 *   - Bank fees around it: $15 incoming wire fees Sep 2 and Oct 23; the May 12 $5 account test debit, its May 13
 *     return, and the $7 credit back fee.
 * Afterwards the account should show Schwab's balance of $486,686.10 (2026-10-23).
 *
 * OUTCOME: run 2026-11-15. Account #105 Schwab Pledged Asset Line, balance $486,686.10 (matches Schwab). Journal entries
 * #4269 (payoff), #4272, #4274, #4276, #4278, #4281, #4283 (interest); transfers #4270, #4271, #4273, #4275, #4277,
 * #4280, #4282 (payments), #4279, #4284 (advances); fees #4285-#4289. Farmers line of credit now $0.
 *
 *   php maint/oneoff/2026-11-15-schwab-pledged-asset-line.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const TAG = '[Schwab pledged asset line]';
const PAL_NAME = 'Schwab Pledged Asset Line';
const BANK = 'First American Bank & Trust xx0207';
const FARMERS = 'The Farmers Bank (Line of Credit)';
const FARMERS_BALANCE = 497721.39;
const PAYOFF = 501525.05;
const SCHWAB_BALANCE = 486686.10;
const SPLIT = ['148 Johnson Dr' => 0.59, '146 Johnson Dr' => 0.22, '152 Johnson Dr' => 0.19];
const INTEREST = [
    '2026-05-22' => 2779.97,
    '2026-06-23' => 2373.88,
    '2026-07-23' => 2458.73,
    '2026-08-24' => 2455.01,
    '2026-09-23' => 2486.44,
    '2026-10-23' => 2707.02,
];
const PAYMENTS = ['2026-05-11' => 100.00, '2026-05-19' => 60000.00, '2026-06-22' => 3000.00, '2026-07-20' => 3000.00, '2026-08-20' => 3000.00, '2026-09-21' => 3000.00, '2026-10-20' => 3000.00];
const ADVANCES = ['2026-09-02' => 30000.00, '2026-10-23' => 15000.00];
const FEES = [
    ['2026-09-02', -15.00, 'Incoming wire fee (Schwab line advance)'],
    ['2026-10-23', -15.00, 'Incoming wire fee (Schwab line advance)'],
    ['2026-05-12', -5.00, 'Schwab PAL account verification debit'],
    ['2026-05-13', 5.00, 'Schwab PAL account verification debit returned'],
    ['2026-05-13', -7.00, 'Credit back fee'],
];

$qbo = app::qbo();
$account = function (string $name) use ($qbo) {
    return $qbo->query("SELECT * FROM Account WHERE Name = '".addslashes($name)."'")['Account'][0] ?? null;
};
if ($account(PAL_NAME)) {
    fwrite(STDERR, PAL_NAME." already exists in QuickBooks: nothing changed\n");
    exit(1);
}
$bank = $account(BANK);
$farmers = $account(FARMERS);
$interestAccount = $account('Interest Paid');
$feesAccount = $account('Bank Charges & Fees');
if ((float) $farmers['CurrentBalance'] != -FARMERS_BALANCE && (float) $farmers['CurrentBalance'] != FARMERS_BALANCE) {
    fwrite(STDERR, "Farmers line of credit is at {$farmers['CurrentBalance']}, not ".FARMERS_BALANCE.": nothing changed\n");
    exit(1);
}
$classes = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classes[$class['FullyQualifiedName']] = $class['Id'];
}

$split = function (float $amount): array {
    $parts = [];
    $soFar = 0;
    foreach (SPLIT as $property => $share) {
        $part = $property == array_key_last(SPLIT) ? round($amount - $soFar, 2) : round($amount * $share, 2);
        $parts[$property] = $part;
        $soFar += $part;
    }
    return $parts;
};
$jeLine = function (string $posting, float $amount, string $accountId, ?string $class, string $description) use ($classes) {
    $detail = ['PostingType' => $posting, 'AccountRef' => ['value' => $accountId]];
    if ($class) {
        $detail['ClassRef'] = ['value' => $classes[$class]];
    }
    return ['Amount' => round($amount, 2), 'DetailType' => 'JournalEntryLineDetail', 'Description' => $description, 'JournalEntryLineDetail' => $detail];
};

// Plan, with the line's balance as Schwab shows it
$balance = 0.0;
$plan = [];
$payoffInterest = round(PAYOFF - FARMERS_BALANCE, 2);
$plan[] = ['date' => '2026-04-21', 'kind' => 'payoff', 'amount' => PAYOFF, 'interest' => $payoffInterest];
$balance += PAYOFF;
$events = [];
foreach (INTEREST as $date => $amount) {
    $events[] = ['date' => $date, 'kind' => 'interest', 'amount' => $amount];
}
foreach (PAYMENTS as $date => $amount) {
    $events[] = ['date' => $date, 'kind' => 'payment', 'amount' => $amount];
}
foreach (ADVANCES as $date => $amount) {
    $events[] = ['date' => $date, 'kind' => 'advance', 'amount' => $amount];
}
usort($events, fn ($a, $b) => strcmp($a['date'], $b['date']));
$plan = array_merge($plan, $events);
foreach ($plan as $event) {
    $change = $event['kind'] == 'payment' ? -$event['amount'] : $event['amount'];
    $balance += $event['kind'] == 'payoff' ? 0 : $change;
    $what = [
        'payoff' => sprintf('Advance pays off Farmers line of credit %.2f + interest %.2f', FARMERS_BALANCE, $payoffInterest),
        'interest' => 'Interest: '.implode(', ', array_map(fn ($p, $a) => "{$p} {$a}", array_keys($split($event['amount'])), $split($event['amount']))),
        'payment' => 'Payment from First American (MoneyLink)',
        'advance' => 'Advance wired to First American',
    ][$event['kind']];
    printf("%s %-8s %10.2f  balance %11.2f  %s\n", $event['date'], $event['kind'], $event['amount'], $balance, $what);
}
foreach (FEES as [$date, $amount, $description]) {
    printf("%s fee      %10.2f  First American, Bank Charges & Fees: %s\n", $date, $amount, $description);
}
printf("\n%s after: %.2f (Schwab: %.2f)\n", PAL_NAME, $balance, SCHWAB_BALANCE);
if (abs($balance - SCHWAB_BALANCE) > 0.001) {
    fwrite(STDERR, "Doesn't reach Schwab's balance: nothing changed\n");
    exit(1);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}

$pal = $qbo->create('Account', ['Name' => PAL_NAME, 'AccountType' => 'Long Term Liability', 'AccountSubType' => 'NotesPayable',
    'Description' => "Charles Schwab pledged asset line in Big Z's name, SOFR + 2.9%; paid like a mortgage"]);
echo "Created account #{$pal['Id']} {$pal['Name']}\n";
foreach ($plan as $event) {
    if ($event['kind'] == 'payoff') {
        $lines = [$jeLine('Debit', FARMERS_BALANCE, $farmers['Id'], null, 'Farmers line of credit paid off by the Schwab line')];
        foreach ($split($event['interest']) as $property => $amount) {
            $lines[] = $jeLine('Debit', $amount, $interestAccount['Id'], $property, 'Farmers line of credit interest in the payoff');
        }
        $lines[] = $jeLine('Credit', PAYOFF, $pal['Id'], null, 'Schwab line advance to pay off Farmers line of credit (loan 7310)');
        $saved = $qbo->create('JournalEntry', ['TxnDate' => $event['date'], 'PrivateNote' => TAG.' Farmers line of credit payoff', 'Line' => $lines]);
        echo "Journal entry #{$saved['Id']} {$event['date']} payoff\n";
    } elseif ($event['kind'] == 'interest') {
        $lines = [];
        foreach ($split($event['amount']) as $property => $amount) {
            $lines[] = $jeLine('Debit', $amount, $interestAccount['Id'], $property, 'Schwab pledged asset line interest');
            $lines[] = $jeLine('Credit', $amount, $pal['Id'], $property, 'Schwab pledged asset line interest');
        }
        $saved = $qbo->create('JournalEntry', ['TxnDate' => $event['date'], 'PrivateNote' => TAG.' interest', 'Line' => $lines]);
        echo "Journal entry #{$saved['Id']} {$event['date']} interest {$event['amount']}\n";
    } else {
        $from = $event['kind'] == 'payment' ? $bank['Id'] : $pal['Id'];
        $to = $event['kind'] == 'payment' ? $pal['Id'] : $bank['Id'];
        $saved = $qbo->create('Transfer', ['TxnDate' => $event['date'], 'Amount' => $event['amount'], 'FromAccountRef' => ['value' => $from], 'ToAccountRef' => ['value' => $to],
            'PrivateNote' => TAG.' '.($event['kind'] == 'payment' ? 'payment (MoneyLink)' : 'advance (wire)')]);
        echo "Transfer #{$saved['Id']} {$event['date']} {$event['kind']} {$event['amount']}\n";
    }
}
foreach (FEES as [$date, $amount, $description]) {
    $line = ['Amount' => abs($amount), 'DetailType' => $amount < 0 ? 'AccountBasedExpenseLineDetail' : 'DepositLineDetail', 'Description' => $description];
    if ($amount < 0) {
        $line['AccountBasedExpenseLineDetail'] = ['AccountRef' => ['value' => $feesAccount['Id']]];
        $saved = $qbo->create('Purchase', ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $bank['Id']], 'PrivateNote' => TAG.' '.$description, 'Line' => [$line]]);
    } else {
        $line['DepositLineDetail'] = ['AccountRef' => ['value' => $feesAccount['Id']]];
        $saved = $qbo->create('Deposit', ['TxnDate' => $date, 'DepositToAccountRef' => ['value' => $bank['Id']], 'PrivateNote' => TAG.' '.$description, 'Line' => [$line]]);
    }
    echo "{$description}: #{$saved['Id']} {$date} {$amount}\n";
}
$pal = $qbo->get('account/'.$pal['Id'])['Account'];
echo PAL_NAME." balance now {$pal['CurrentBalance']}\n";
