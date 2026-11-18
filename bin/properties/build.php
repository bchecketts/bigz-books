#!/usr/bin/php
<?php

/**
 * Rent status for the home page: for each rental in app/properties.json, the rent collected in QuickBooks (Rental
 * Income and its sub-accounts other than fees and deposits, by class) for its manager's latest month, and over the last
 * three months. A manager's latest month is that of the newest deposit in its holding account (its statement data is
 * entered in batches), so a property isn't shown behind just because the statement isn't in yet; rent paid straight to
 * First American uses this month (last month until the 6th). Also the portfolio: each property's estimated value against
 * its book value and loans. Read-only. Writes ../var/rent-status.json.
 *
 *   php bin/properties/build.php
 */

require_once __DIR__.'/../../app/common.php';

$root = dirname(__DIR__, 2);
$config = json_decode((string) file_get_contents("{$root}/app/properties.json"), true, 512, JSON_THROW_ON_ERROR);
$outputFile = dirname($root).'/var/rent-status.json';
$today = date('Y-m-d');
$qbo = app::qbo();

// Rent accounts: Rental Income and sub-accounts, without fees, deposits and other charges
$rentAccounts = [];
foreach ($qbo->query("SELECT * FROM Account WHERE AccountType = 'Income' MAXRESULTS 500")['Account'] as $account) {
    $isRent = str_starts_with($account['FullyQualifiedName'], 'Rental Income');
    $isCharge = (bool) preg_match('/Late|Security|Pet|Eviction|NSF|Fee/i', $account['FullyQualifiedName']);
    if ($isRent && !$isCharge) {
        $rentAccounts[$account['Id']] = true;
    }
}
$accountIds = [];
foreach ($qbo->query("SELECT * FROM Account WHERE AccountType = 'Bank' MAXRESULTS 500")['Account'] as $account) {
    $accountIds[$account['Name']] = $account['Id'];
}

// Collected rent by class and month, and the newest deposit in each account, from four months back
$since = date('Y-m-01', strtotime("{$today} -4 months"));
$collected = [];
$newestDeposit = [];
foreach (['Deposit' => 'DepositLineDetail', 'JournalEntry' => 'JournalEntryLineDetail'] as $type => $detailKey) {
    foreach ($qbo->query("SELECT * FROM {$type} WHERE TxnDate >= '{$since}' MAXRESULTS 1000")[$type] ?? [] as $txn) {
        if ($type == 'Deposit') {
            $accountId = $txn['DepositToAccountRef']['value'];
            $newestDeposit[$accountId] = max($newestDeposit[$accountId] ?? '', $txn['TxnDate']);
        }
        foreach ($txn['Line'] as $line) {
            $detail = $line[$detailKey] ?? null;
            if (!$detail || !isset($rentAccounts[$detail['AccountRef']['value'] ?? '']) || !isset($detail['ClassRef'])) {
                continue;
            }
            $sign = ($detail['PostingType'] ?? 'Credit') == 'Debit' ? -1 : 1;
            $month = substr($txn['TxnDate'], 0, 7);
            $classId = $detail['ClassRef']['value'];
            $collected[$classId][$month] = ($collected[$classId][$month] ?? 0) + $sign * (int) round($line['Amount'] * 100);
        }
    }
}
$classIds = [];
foreach ($qbo->query("SELECT * FROM Class MAXRESULTS 500")['Class'] as $class) {
    $classIds[$class['FullyQualifiedName']] = $class['Id'];
}

// Each manager's latest month
$managers = [];
foreach ($config['managers'] as $manager => $accountName) {
    if ($manager == 'Direct') {
        $dataDate = $today;
        $month = (int) date('j') <= 5 ? date('Y-m', strtotime('first day of last month')) : date('Y-m');
    } else {
        $dataDate = $newestDeposit[$accountIds[$accountName] ?? ''] ?? null;
        $month = $dataDate ? substr($dataDate, 0, 7) : date('Y-m');
    }
    $managers[$manager] = ['account' => $accountName, 'data_through' => $dataDate, 'month' => $month];
}

$properties = [];
foreach ($config['properties'] as $property) {
    $classId = $classIds[$property['class']] ?? null;
    if (!$classId) {
        fwrite(STDERR, "No class {$property['class']} for {$property['name']}\n");
        continue;
    }
    $month = $managers[$property['manager']]['month'];
    $rent = (int) round($property['rent'] * 100);
    $paid = $collected[$classId][$month] ?? 0;
    $threeMonths = [];
    for ($back = 0; $back < 3; $back++) {
        $threeMonths[] = date('Y-m', strtotime("{$month}-01 -{$back} months"));
    }
    $paidThree = array_sum(array_map(fn ($m) => $collected[$classId][$m] ?? 0, $threeMonths));
    if ($rent == 0) {
        $status = 'vacant';
    } elseif ($paid >= $rent) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'partial';
    } else {
        $status = 'unpaid';
    }
    $properties[] = [
        'name' => $property['name'], 'manager' => $property['manager'], 'note' => $property['note'] ?? '',
        'rent' => $rent, 'asking' => (int) round(($property['asking'] ?? 0) * 100), 'month' => $month, 'paid' => $paid, 'status' => $status,
        'three_months' => ['from' => end($threeMonths), 'paid' => $paidThree, 'due' => 3 * $rent],
    ];
}

// Portfolio: estimated value (app/properties.json) against book value (fixed asset accounts: cost plus improvements, no
// depreciation, which is only on the tax return) and loans (current balances), each account shared where it covers
// several properties
$balances = [];
foreach ($qbo->query("SELECT * FROM Account MAXRESULTS 1000")['Account'] as $account) {
    $balances[$account['FullyQualifiedName']] = (int) round(abs((float) $account['CurrentBalance']) * 100);
}
$rentByUnit = array_column($config['properties'], 'rent', 'name');
$askingByUnit = array_column($config['properties'], 'asking', 'name');
$classByUnit = array_column($config['properties'], 'class', 'name');

// Operating results for the last 12 full months, by class (P&L by class): income, and expenses other than interest
$periodEnd = date('Y-m-t', strtotime('last day of last month'));
$periodStart = date('Y-m-01', strtotime(date('Y-m-01', strtotime($periodEnd)).' -11 months'));
$report = $qbo->get('reports/ProfitAndLoss', ['start_date' => $periodStart, 'end_date' => $periodEnd, 'summarize_column_by' => 'Classes',
    'accounting_method' => 'Accrual']);
$columns = array_map(fn ($column) => $column['ColTitle'], $report['Columns']['Column']);
$results = [];
$collect = function (array $node) use (&$collect, &$results, $columns) {
    foreach ($node['Row'] ?? [] as $row) {
        foreach ([$row['ColData'] ?? null, $row['Summary']['ColData'] ?? null] as $cells) {
            $label = $cells[0]['value'] ?? '';
            if (in_array($label, ['Total Income', 'Total Expenses', 'Interest Paid'], true)) {
                foreach ($cells as $index => $cell) {
                    if ($index > 0) {
                        $results[$columns[$index]][$label] = (int) round((float) $cell['value'] * 100);
                    }
                }
            }
        }
        if (isset($row['Rows'])) {
            $collect($row['Rows']);
        }
    }
};
$collect($report['Rows'] ?? []);
// Financing costs in the period aren't operating costs (like interest): the loan and title fees and recording taxes of
// the April 2026 closings (Deni #4020, Tuxedo #4017) and the Lakeside refinance (#4264). Taken out by class.
$financingFees = [];
$feeAccounts = [];
foreach ($qbo->query("SELECT * FROM Account WHERE AccountType = 'Expense' MAXRESULTS 500")['Account'] as $account) {
    if (in_array($account['FullyQualifiedName'], ['Bank Charges & Fees', 'Taxes & Licenses'], true)) {
        $feeAccounts[$account['Id']] = true;
    }
}
$classNames = array_flip($classIds);
foreach ([['journalentry', '4020'], ['journalentry', '4017'], ['deposit', '4264']] as [$type, $id]) {
    $txn = $qbo->get("{$type}/{$id}")[$type == 'journalentry' ? 'JournalEntry' : 'Deposit'];
    if ($txn['TxnDate'] < $periodStart || $txn['TxnDate'] > $periodEnd) {
        continue;
    }
    foreach ($txn['Line'] as $line) {
        $detail = $line['JournalEntryLineDetail'] ?? $line['DepositLineDetail'] ?? null;
        if (!$detail || !isset($feeAccounts[$detail['AccountRef']['value']]) || !isset($detail['ClassRef'])) {
            continue;
        }
        // A journal entry's debit is a cost; a deposit carries its fees as negative lines
        $cost = $type == 'journalentry' ? (($detail['PostingType'] == 'Debit' ? 1 : -1) * $line['Amount']) : -$line['Amount'];
        $parts = explode(':', $classNames[$detail['ClassRef']['value']] ?? '');
        $short = end($parts);
        $financingFees[$short] = ($financingFees[$short] ?? 0) + (int) round($cost * 100);
    }
}
$portfolio = [];
foreach ($config['portfolio'] ?? [] as $holding) {
    $sum = function (array $accounts) use ($balances, $holding) {
        $total = 0;
        foreach ($accounts as $entry) {
            if (!isset($balances[$entry['account']])) {
                fwrite(STDERR, "No account {$entry['account']} for {$holding['name']}\n");
            }
            $total += (int) round(($balances[$entry['account']] ?? 0) * $entry['share']);
        }
        return $total;
    };
    $rent = array_sum(array_map(fn ($unit) => (int) round(($rentByUnit[$unit] ?? 0) * 100), $holding['units']));
    // At current rent, a vacant unit counts at its asking rent
    $rentOrAsking = array_sum(array_map(fn ($unit) => (int) round((($rentByUnit[$unit] ?? 0) ?: ($askingByUnit[$unit] ?? 0)) * 100), $holding['units']));
    // The units' own columns (a class's short name: the part after the last colon)
    $income = 0;
    $operating = 0;
    foreach ($holding['units'] as $unit) {
        $parts = explode(':', $classByUnit[$unit] ?? '');
        $column = $results[end($parts)] ?? [];
        $income += $column['Total Income'] ?? 0;
        $operating += ($column['Total Expenses'] ?? 0) - ($column['Interest Paid'] ?? 0) - ($financingFees[end($parts)] ?? 0);
    }
    // Owned for part of the period: scale to a year from the months owned
    $months = 12;
    if (!empty($holding['acquired']) && $holding['acquired'] > $periodStart) {
        $months = max(1, (strtotime($periodEnd) - strtotime($holding['acquired'])) / (30.44 * 86400));
    }
    $scale = 12 / $months;
    $portfolio[] = ['name' => $holding['name'], 'value' => (int) round($holding['value'] * 100), 'book' => $sum($holding['assets']),
        'loans' => $sum($holding['loans']), 'annual_rent' => 12 * $rent,
        'noi_actual' => (int) round(($income - $operating) * $scale), 'operating_costs' => (int) round($operating * $scale),
        'noi_current_rent' => 12 * $rentOrAsking - (int) round($operating * $scale), 'months_owned' => round($months, 1)];
}

$result = ['built_at' => date('c'), 'managers' => $managers, 'properties' => $properties,
    'portfolio' => $portfolio, 'operating_period' => [$periodStart, $periodEnd], 'values_as_of' => $config['values_as_of'] ?? null, 'values_source' => $config['values_source'] ?? null];
file_put_contents("{$outputFile}.tmp", json_encode($result, JSON_PRETTY_PRINT));
chmod("{$outputFile}.tmp", 0640);
rename("{$outputFile}.tmp", $outputFile);
printf("Rent status for %d properties written to %s\n", count($properties), $outputFile);
foreach ($properties as $property) {
    printf("  %-38s %-22s %s %8.2f of %8.2f  %s\n", $property['name'], $property['manager'], $property['month'], $property['paid'] / 100, $property['rent'] / 100, $property['status']);
}
