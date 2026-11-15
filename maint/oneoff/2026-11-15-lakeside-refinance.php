#!/usr/bin/php
<?php

/**
 * Lakeside refinance, 2026-04-10: The Commercial Bank lent $225,000 on each of 2050, 2060 and 2070 Lakeside Dr
 * (loans 56528, 56530, 56494; HUD-1s in uploads/Commercial Bank Loan - 20x0 Lakeside Dr - 2026-04-10.pdf, settlement
 * agent Christopher C. Nichols, P.C.). The 2070 closing paid off the First American line of credit ($109,951.81:
 * $109,373.17 balance + $578.64 interest). Net to Big Z, deposited at First American the same day: $220,802.50 +
 * $220,802.50 + $110,776.53 = $552,381.53. First American also refunded a $66.08 payoff overpayment that day.
 *
 * Creates the three loan accounts and records the two deposits. Closing costs are expensed by property class the way
 * the Deni/Tuxedo closings were (Bank Charges & Fees; recording to Taxes & Licenses); line of credit interest is split
 * across the three Lakeside classes like the monthly interest entries.
 *
 * OUTCOME: run 2026-11-15. Created accounts #102 (*6528 - 2050), #103 (*6530 - 2060), #104 (*6494 - 2070), each
 * $225,000; Deposit #4264 $552,381.53 and Deposit #4265 $66.08 (both 2026-04-10, First American). FABT Line of Credit
 * now $0.
 *
 *   php maint/oneoff/2026-11-15-lakeside-refinance.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const DATE = '2026-04-10';
const TAG = '[Lakeside refinance 4/10/26]';
const FIRST_AMERICAN = '34';
const LINE_OF_CREDIT = '1150040000';
const BANK_FEES = '7';
const TAXES = '19';
const INTEREST = '10';

// Settlement charges common to the three HUD-1s (lines 801-1204).
const FEES = [
    ['Loan origination fee - The Commercial Bank', BANK_FEES, 2250.00],
    ['Evaluation - The Commercial Bank', BANK_FEES, 125.00],
    ['Flood insurance monitoring - Lereta LLC', BANK_FEES, 2.00],
    ['Flood certification - Lereta LLC', BANK_FEES, 12.00],
    ['Future release - The Commercial Bank', BANK_FEES, 50.00],
    ['Title examination - Christopher C. Nichols, P.C.', BANK_FEES, 275.00],
    ["Attorney's fees - Christopher C. Nichols, P.C.", BANK_FEES, 585.00],
    ["Lender's title insurance - Fidelity National Title", BANK_FEES, 812.50],
    ['Post closing fee - Christopher C. Nichols, P.C.', BANK_FEES, 35.00],
    ['Recording fee (mortgage)', TAXES, 25.50],
    ['ARL', TAXES, 25.50],
];
const LOANS = [
    '2050 Lakeside Dr' => ['loan' => '56528', 'net' => 220802.50, 'extra' => []],
    '2060 Lakeside Dr' => ['loan' => '56530', 'net' => 220802.50, 'extra' => []],
    '2070 Lakeside Dr' => ['loan' => '56494', 'net' => 110776.53, 'extra' => [
        ['Credit report - The Commercial Bank', BANK_FEES, 14.16],
        ['Payoff wire fee - Christopher C. Nichols, P.C.', BANK_FEES, 25.00],
        ['Mortgage release tracking fee - reQuire, LLC', BANK_FEES, 35.00],
    ]],
];
const PRINCIPAL = 225000.00;
const LOC_BALANCE = 109373.17;
const LOC_INTEREST = ['2050 Lakeside Dr' => 192.88, '2060 Lakeside Dr' => 192.88, '2070 Lakeside Dr' => 192.88];
const REFUND = ['2050 Lakeside Dr' => 22.03, '2060 Lakeside Dr' => 22.03, '2070 Lakeside Dr' => 22.02];

$qbo = app::qbo();
$classes = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classes[$class['FullyQualifiedName']] = $class['Id'];
}
$existing = $qbo->query("SELECT * FROM Deposit WHERE TxnDate = '".DATE."' MAXRESULTS 100")['Deposit'] ?? [];
foreach ($existing as $deposit) {
    if (in_array((float) $deposit['TotalAmt'], [552381.53, 66.08]) || str_contains($deposit['PrivateNote'] ?? '', TAG)) {
        fwrite(STDERR, "A deposit for this is already in QuickBooks (#{$deposit['Id']} {$deposit['TotalAmt']}): nothing changed\n");
        exit(1);
    }
}

$line = function (float $amount, string $accountId, string $class, string $description) use ($classes) {
    return [
        'Amount' => round($amount, 2),
        'DetailType' => 'DepositLineDetail',
        'Description' => $description,
        'DepositLineDetail' => ['AccountRef' => ['value' => $accountId], 'ClassRef' => ['value' => $classes[$class]]],
    ];
};

// 1. Loan accounts
$loanAccounts = [];
foreach (LOANS as $property => $loan) {
    $name = 'The Commercial Bank *'.substr($loan['loan'], -4).' - '.$property;
    $found = $qbo->query("SELECT * FROM Account WHERE Name = '".addslashes($name)."'")['Account'][0] ?? null;
    $loanAccounts[$property] = ['name' => $name, 'id' => $found['Id'] ?? null];
    echo $found ? "Account exists: {$name} (#{$found['Id']})\n" : "Create account: {$name} (Long Term Liability, Notes Payable)\n";
}

// 2. Deposit of the net proceeds, one block of lines per property
$plan = [];
$total = 0;
foreach (LOANS as $property => $loan) {
    $lines = [['loan', PRINCIPAL, null, "Loan {$loan['loan']} principal"]];
    $fees = 0;
    foreach (array_merge(FEES, $loan['extra']) as [$description, $accountId, $amount]) {
        $lines[] = ['fee', -$amount, $accountId, $description];
        $fees += $amount;
    }
    if ($property == '2070 Lakeside Dr') {
        $lines[] = ['loc', -LOC_BALANCE, LINE_OF_CREDIT, 'Payoff of the First American line of credit (balance)'];
    }
    $lines[] = ['interest', -LOC_INTEREST[$property], INTEREST, 'First American line of credit interest in the payoff'];
    $net = array_sum(array_column($lines, 1));
    printf("\n%s: principal %.2f, fees %.2f, net %.2f (HUD-1 cash to borrower %.2f)\n", $property, PRINCIPAL, $fees, $net, $loan['net']);
    foreach ($lines as [$kind, $amount, $accountId, $description]) {
        printf("   %12.2f  %s\n", $amount, $description);
    }
    $plan[$property] = $lines;
    $total += $net;
}
printf("\nDeposit to First American %s: %.2f (bank: 552381.53)\n", DATE, $total);
if (abs($total - 552381.53) > 0.001) {
    fwrite(STDERR, "Lines don't add up to the deposit: nothing changed\n");
    exit(1);
}
printf("Deposit to First American %s: 66.08 refund of payoff overpayment (Interest Paid, split across the three classes)\n", DATE);

if (!cliOption('post')) {
    echo "\nPreview: run with --post to save\n";
    exit;
}

foreach ($loanAccounts as $property => &$account) {
    if (!$account['id']) {
        $created = $qbo->create('Account', ['Name' => $account['name'], 'AccountType' => 'Long Term Liability', 'AccountSubType' => 'NotesPayable', 'Description' => 'Loan '.LOANS[$property]['loan'].', closed '.DATE]);
        $account['id'] = $created['Id'];
        echo "Created account #{$created['Id']} {$created['Name']}\n";
    }
}
unset($account);

$depositLines = [];
foreach ($plan as $property => $lines) {
    foreach ($lines as [$kind, $amount, $accountId, $description]) {
        $depositLines[] = $line($amount, $kind == 'loan' ? $loanAccounts[$property]['id'] : $accountId, $property, $description);
    }
}
$deposit = $qbo->create('Deposit', [
    'TxnDate' => DATE,
    'DepositToAccountRef' => ['value' => FIRST_AMERICAN],
    'PrivateNote' => TAG.' Commercial Bank loans 56528, 56530, 56494 net of closing costs and the line of credit payoff',
    'Line' => $depositLines,
]);
echo "Deposit #{$deposit['Id']} {$deposit['TotalAmt']}\n";

$refundLines = [];
foreach (REFUND as $property => $amount) {
    $refundLines[] = $line($amount, INTEREST, $property, 'Refund of line of credit payoff overpayment');
}
$refund = $qbo->create('Deposit', [
    'TxnDate' => DATE,
    'DepositToAccountRef' => ['value' => FIRST_AMERICAN],
    'PrivateNote' => TAG.' First American line of credit payoff overpayment refunded',
    'Line' => $refundLines,
]);
echo "Deposit #{$refund['Id']} {$refund['TotalAmt']}\n";
