#!/usr/bin/php
<?php

/**
 * Enters a Rent Oconee Clarke owner statement in QuickBooks, in "Rent Oconee Clarke (Holding Account)", the way the
 * earlier statements were entered:
 *   - a deposit per property (customer Renter, the property's class): rent, late, pet, eviction and first-month fees,
 *     plus anything the tenant paid back toward an expense; dated the property's last payment in the period
 *   - an expense per repair, utility bill and management fee, with a generic payee (Plumbing, Maintenance, HVAC...) and
 *     the statement's comment (contractor, invoice) as the memo
 *   - a transfer of everything paid to the owner to First American Bank & Trust, dated the day the statement was made
 * Money one property's rent covered for another ("Received from owner") needs no entry: it all stays in the one
 * holding account. Prepaid rent Rent Oconee Clarke holds isn't entered.
 *
 * The statement is transcribed from the PDF into statements/rent-oconee-clarke/YYYY-MM.json (see the files there):
 * each property's income and expense lines as [date, statement account code, amount, comment], and its net and paid to
 * owner, which are checked. Entries already in QuickBooks (the same type, class and amount, dated in or near the period)
 * are left out, so it's safe to run again.
 *
 * USAGE
 *   php maint/rent_oconee_clarke.php statements/rent-oconee-clarke/2026-10.json          preview
 *   php maint/rent_oconee_clarke.php statements/rent-oconee-clarke/2026-10.json --post   write to QuickBooks
 */

require_once __DIR__.'/../app/common.php';

// The statement's properties: QuickBooks class
const PROPERTY_CLASSES = [
    '146 Johnson Dr.'            => '146 Johnson Dr',
    '152 Johnson Dr.'            => '152 Johnson Dr',
    '460 Barnett Shoals Rd. E-1' => '460 Barnett Shoals Rd',
    '1020 Deni Court'            => '1020-1026 Deni Ct:1020 Deni Ct.',
    '1020 Tuxedo Lane'           => '1020 Tuxedo Ln.',
    '1022 Deni Court'            => '1020-1026 Deni Ct:1022 Deni Ct',
    '1024 Deni Court'            => '1020-1026 Deni Ct:1024 Deni Ct',
    '1026 Deni Court'            => '1020-1026 Deni Ct:1026 Deni Ct',
    '2050 Lakeside Dr.'          => '2050 Lakeside Dr',
    '2060 Lakeside Drive'        => '2060 Lakeside Dr',
    '2070 Lakeside Drive'        => '2070 Lakeside Dr',
    '2505 W. Broad St. #412'     => '412 Rivers Edge',
];

// The statement's account codes: [QuickBooks account, payee]. Payees are generic on purpose (a new contractor needs no
// new vendor); the comment names the contractor. A null payee is worked out from the comment (utilities).
const ACCOUNT_CODES = [
    '4101' => ['Rental Income', null],
    '4102' => ['Rental Income:First Month\'s Rent', null],
    '4103' => ['Rental Income:Pet Fee', null],
    '4110' => ['Rental Income:Late Fees', null],
    '410'  => ['Rental Income:Late Fees', null],
    '4123' => ['Rental Income:Eviction Fee', null],
    '5000' => ['Property Management Fees', 'Rent Oconee Clarke'],
    // Not a statement code: the leasing fee for a new lease (half a month's rent), which the statement lumps into the
    // month's 5000 management fee. Split it out into a line of its own when transcribing.
    '5000-leasing' => ['Property Management Fees:Lease Renewal - Leasing Fee', 'Rent Oconee Clarke'],
    '5033' => ['Repairs & Maintenance', 'Landscaping'],
    '5105' => ['Cleaning Fees', 'Cleaning'],
    '5108' => ['Repairs & Maintenance', 'Plumbing'],
    '5109' => ['Repairs & Maintenance', 'Maintenance'],
    '5110' => ['Repairs & Maintenance', 'Electrician'],
    '5111' => ['Repairs & Maintenance', 'HVAC'],
    '5120' => ['Repairs & Maintenance', 'Appliances'],
    '5125' => ['Repairs & Maintenance', 'Air Filter Maintenince'],
    '5201' => ['Repairs & Maintenance', 'Appliances'],
    '5402' => ['Utilities', null],
    '5404' => ['Utilities', null],
];

// Utility companies, by their name in the comment; any other goes to a generic payee by statement code (created in
// QuickBooks the first time it's needed), with the company named in the memo
const UTILITY_PAYEES = [
    '/Georgia Power/i'                    => 'Georgia Power Company',
    '/Walton EMC/i'                       => 'Walton EMC',
    '/Athens.Clarke County Water|ACC Water/i' => 'ACC Water',
    '/Oconee County Water/i'              => 'Oconee County Water Resources',
];
const GENERIC_UTILITY_PAYEES = ['5402' => 'Water Company', '5404' => 'Electric Company'];

const HOLDING_ACCOUNT = 'Rent Oconee Clarke (Holding Account)';
const OWNER_ACCOUNT = 'First American Bank & Trust xx0207';

$file = $argv[1] ?? '';
$statement = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!$statement) {
    fwrite(STDERR, "usage: php maint/rent_oconee_clarke.php statements/rent-oconee-clarke/YYYY-MM.json [--post]\n");
    exit(1);
}
$post = (bool) cliOption('post');
$cents = fn ($dollars) => (int) round((float) $dollars * 100);
$dollars = fn ($cents) => number_format($cents / 100, 2, '.', '');
[$periodStart, $periodEnd] = $statement['period'];
$periodText = date('n/j/y', strtotime($periodStart)).'-'.date('n/j/y', strtotime($periodEnd));
$tag = "[Rent Oconee Clarke statement {$periodText}]";

// 1. Check the transcription: each property's income less expenses is its net; what's paid to the owner in all is the
// total net (one property's rent may cover another's bills)
$problems = [];
$totalNet = 0;
$totalPaid = 0;
foreach ($statement['properties'] as $property => $lines) {
    if (!isset(PROPERTY_CLASSES[$property])) {
        $problems[] = "{$property}: no class for it in PROPERTY_CLASSES";
    }
    foreach (array_merge($lines['income'], $lines['expenses']) as $line) {
        if (!isset(ACCOUNT_CODES[$line[1]])) {
            $problems[] = "{$property}: no account for statement code {$line[1]} in ACCOUNT_CODES";
        }
    }
    $net = array_sum(array_map(fn ($line) => $cents($line[2]), $lines['income'])) - array_sum(array_map(fn ($line) => $cents($line[2]), $lines['expenses']));
    if ($net != $cents($lines['net'])) {
        $problems[] = "{$property}: income less expenses is {$dollars($net)}, the statement says {$lines['net']}";
    }
    $totalNet += $net;
    $totalPaid += $cents($lines['paid_to_owner']);
}
if ($totalNet != $totalPaid) {
    $problems[] = "the properties' net adds up to {$dollars($totalNet)}, but {$dollars($totalPaid)} was paid to the owner";
}
if ($problems) {
    fwrite(STDERR, "The statement doesn't add up or has something new:\n  ".implode("\n  ", $problems)."\n");
    exit(1);
}

// 2. QuickBooks' names: accounts, classes, vendors, the Renter customer
$qbo = app::qbo();
$byName = function ($type, $nameField) use ($qbo) {
    $records = [];
    foreach ($qbo->query("SELECT * FROM {$type} MAXRESULTS 1000")[$type] ?? [] as $record) {
        $records[$record[$nameField]] = $record['Id'];
    }
    return $records;
};
$accounts = $byName('Account', 'FullyQualifiedName');
$classes = $byName('Class', 'FullyQualifiedName');
$classNames = array_flip($classes);
$vendors = $byName('Vendor', 'DisplayName');
$customers = $byName('Customer', 'DisplayName');
$need = fn ($list, $name, $what) => $list[$name] ?? throw new RuntimeException("no {$what} \"{$name}\" in QuickBooks");
$holdingId = $need($accounts, HOLDING_ACCOUNT, 'account');

// 3. The entries
$entries = [];
$newVendors = [];
foreach ($statement['properties'] as $property => $lines) {
    $class = PROPERTY_CLASSES[$property];
    $classRef = ['value' => $need($classes, $class, 'class'), 'name' => $class];

    // Deposit: income by account, plus expenses paid back (a negative expense)
    $depositLines = [];
    foreach ($lines['income'] as [$date, $code, $amount]) {
        $depositLines[ACCOUNT_CODES[$code][0]] = ($depositLines[ACCOUNT_CODES[$code][0]] ?? 0) + $cents($amount);
    }
    foreach ($lines['expenses'] as [$date, $code, $amount]) {
        if ($amount < 0) {
            $depositLines[ACCOUNT_CODES[$code][0]] = ($depositLines[ACCOUNT_CODES[$code][0]] ?? 0) - $cents($amount);
        }
    }
    $depositLines = array_filter($depositLines);
    if ($depositLines) {
        $dates = array_column($lines['income'], 0) ?: [$periodEnd];
        $record = ['TxnDate' => max($dates), 'DepositToAccountRef' => ['value' => $holdingId], 'PrivateNote' => $tag, 'Line' => []];
        foreach ($depositLines as $account => $amount) {
            $record['Line'][] = ['Amount' => $amount / 100, 'DetailType' => 'DepositLineDetail', 'DepositLineDetail' => [
                'Entity' => ['value' => $need($customers, 'Renter', 'customer'), 'type' => 'Customer'], 'ClassRef' => $classRef,
                'AccountRef' => ['value' => $need($accounts, $account, 'account')]]];
        }
        $entries[] = ['type' => 'Deposit', 'class' => $class, 'date' => $record['TxnDate'], 'amount' => array_sum($depositLines),
            'what' => implode(', ', array_map(fn ($account, $amount) => "{$account} {$dollars($amount)}", array_keys($depositLines), $depositLines)), 'record' => $record];
    }

    // An expense for each bill and fee
    foreach ($lines['expenses'] as [$date, $code, $amount, $comment]) {
        if ($amount < 0) {
            continue;
        }
        [$account, $payee] = ACCOUNT_CODES[$code];
        foreach ($payee ? [] : UTILITY_PAYEES as $pattern => $utility) {
            if (preg_match($pattern, $comment)) {
                $payee = $utility;
                break;
            }
        }
        $payee = $payee ?? GENERIC_UTILITY_PAYEES[$code] ?? null;
        $record = ['TxnDate' => $date, 'PaymentType' => 'Cash', 'AccountRef' => ['value' => $holdingId], 'PrivateNote' => "{$comment} {$tag}",
            'Line' => [['Amount' => $cents($amount) / 100, 'DetailType' => 'AccountBasedExpenseLineDetail', 'AccountBasedExpenseLineDetail' => [
                'ClassRef' => $classRef, 'AccountRef' => ['value' => $need($accounts, $account, 'account')]]]]];
        if ($payee) {
            $record['EntityRef'] = ['value' => $vendors[$payee] ?? "new vendor {$payee}", 'type' => 'Vendor'];
            if (!isset($vendors[$payee])) {
                $newVendors[$payee] = true;
            }
        }
        $entries[] = ['type' => 'Purchase', 'class' => $class, 'date' => $date, 'amount' => $cents($amount),
            'what' => "{$account} / ".($payee ?? '(no payee)').": {$comment}", 'record' => $record];
    }
}
// The payout
$entries[] = ['type' => 'Transfer', 'class' => '', 'date' => $statement['generated'], 'amount' => $totalPaid, 'what' => 'Paid to owner, to '.OWNER_ACCOUNT,
    'record' => ['TxnDate' => $statement['generated'], 'Amount' => $totalPaid / 100, 'FromAccountRef' => ['value' => $holdingId],
        'ToAccountRef' => ['value' => $need($accounts, OWNER_ACCOUNT, 'account')], 'PrivateNote' => $tag]];

// 4. What's in QuickBooks already: the holding account's deposits, expenses and transfers out dated from the period's
// start to a week after the statement was made (rents repeat each month, so an earlier one is last month's), or
// carrying this statement's tag. An entry matches one of the same type, class (all its lines) and amount; each existing
// one matches once.
$from = date('Y-m-d', strtotime("{$periodStart} -45 days"));
$to = date('Y-m-d', strtotime("{$statement['generated']} +7 days"));
$existing = [];
foreach (['Deposit', 'Purchase', 'Transfer'] as $type) {
    foreach ($qbo->query("SELECT * FROM {$type} WHERE TxnDate >= '{$from}' AND TxnDate <= '{$to}' MAXRESULTS 1000")[$type] ?? [] as $record) {
        $account = $record['DepositToAccountRef']['value'] ?? $record['AccountRef']['value'] ?? $record['FromAccountRef']['value'] ?? '';
        if ($account != $holdingId) {
            continue;
        }
        if ($record['TxnDate'] < $periodStart && !str_contains($record['PrivateNote'] ?? '', $tag)) {
            continue;
        }
        // By class Id: a line's ClassRef name is the short name, not the full "Parent:Child" one the entries use
        $lineClassIds = array_unique(array_filter(array_map(fn ($line) => ($line['DepositLineDetail'] ?? $line['AccountBasedExpenseLineDetail'] ?? [])['ClassRef']['value'] ?? null, $record['Line'] ?? [])));
        $lineClasses = array_map(fn ($classId) => $classNames[$classId] ?? $classId, $lineClassIds);
        $existing[] = ['type' => $type, 'id' => $record['Id'], 'date' => $record['TxnDate'], 'amount' => $cents($record['TotalAmt'] ?? $record['Amount']),
            'class' => count($lineClasses) == 1 ? reset($lineClasses) : ''];
    }
}
$matched = [];
foreach ($entries as $index => $entry) {
    foreach ($existing as $existingIndex => $record) {
        if (!isset($matched[$existingIndex]) && $record['type'] == $entry['type'] && $record['class'] == $entry['class'] && $record['amount'] == $entry['amount']) {
            $matched[$existingIndex] = true;
            $entries[$index]['existing'] = $record;
            break;
        }
    }
}

// 5. Show, and post what's new
printf("Rent Oconee Clarke statement %s (made %s): %d properties, paid to owner %s\n\n", $periodText, $statement['generated'], count($statement['properties']), $dollars($totalPaid));
foreach (array_keys($newVendors) as $name) {
    echo "Vendor \"{$name}\" isn't in QuickBooks: ".($post ? 'creating it' : 'it will be created')."\n";
    if ($post) {
        $vendors[$name] = $qbo->create('Vendor', ['DisplayName' => $name])['Id'];
        bclog("rent_oconee_clarke {$periodText}: created Vendor #{$vendors[$name]} {$name}");
    }
}
$new = 0;
foreach ($entries as $entry) {
    if (str_starts_with($entry['record']['EntityRef']['value'] ?? '', 'new vendor ')) {
        $entry['record']['EntityRef']['value'] = $vendors[substr($entry['record']['EntityRef']['value'], 11)] ?? '';
    }
    $status = isset($entry['existing']) ? "in QuickBooks: {$entry['existing']['type']} #{$entry['existing']['id']} {$entry['existing']['date']}" : ($post ? 'posting' : 'new');
    printf("%-8s %s %-22s %10s  %s  [%s]\n", $entry['type'], $entry['date'], $entry['class'], $dollars($entry['amount']), $entry['what'], $status);
    if (!isset($entry['existing'])) {
        $new++;
        if ($post) {
            $created = $qbo->create($entry['type'], $entry['record']);
            echo "         created {$entry['type']} #{$created['Id']}\n";
            bclog("rent_oconee_clarke {$periodText}: created {$entry['type']} #{$created['Id']} {$entry['class']} {$dollars($entry['amount'])}");
        }
    }
}
$balanceChange = array_sum(array_map(fn ($entry) => isset($entry['existing']) ? 0 : ($entry['type'] == 'Deposit' ? 1 : -1) * $entry['amount'], $entries));
printf("\n%d new entr%s%s; they change the holding account's balance by %s.\n", $new, $new == 1 ? 'y' : 'ies', $post ? ' posted' : ' (preview: run with --post to write them)', $dollars($balanceChange));
