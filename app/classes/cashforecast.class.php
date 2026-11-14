<?php

/**
 * QuickBooks numbers for the cash flow forecast (bin/cash-forecast/build.php). Read-only. Money in integer cents.
 */
class cashforecast
{
    /**
     * This morning's numbers for the cash accounts named in the assumptions:
     *   balances[account]           QuickBooks' balance at the end of today (entries dated later are left out: the
     *                               forecast counts those on their own day)
     *   history[account][day]       end-of-day balance for the last 6 months (balance sheet by day)
     *   last_change[account]        the last day its balance changed in those 6 months (null: not at all)
     *   recent_moves[]              what's recorded in them in the last 45 days: [account, date, amount, name, memo, other]
     *   open_bills / open_invoices  [id, number, party, date, due, balance]
     */
    public static function snapshot(array $cashAccounts)
    {
        $qbo = app::qbo();
        $today = gmdate('Y-m-d');
        $snapshot = ['taken_at' => gmdate('c'), 'balances' => [], 'history' => [], 'last_change' => [], 'recent_moves' => [], 'missing' => []];

        $accountIds = [];
        foreach ($qbo->query("SELECT * FROM Account WHERE AccountType = 'Bank' MAXRESULTS 1000")['Account'] ?? [] as $account) {
            if (in_array($account['Name'], $cashAccounts, true)) {
                $accountIds[$account['Id']] = $account['Name'];
                $snapshot['balances'][$account['Name']] = self::cents($account['CurrentBalance']);
            }
        }
        $snapshot['missing'] = array_values(array_diff($cashAccounts, $accountIds));
        if (!$accountIds) {
            return $snapshot;
        }

        // Balance sheet by day: the cash accounts' end-of-day balances
        $report = $qbo->get('reports/BalanceSheet', ['start_date' => gmdate('Y-m-d', strtotime('-6 months')), 'end_date' => $today,
            'summarize_column_by' => 'Days', 'accounting_method' => 'Accrual']);
        $columnDays = [];
        foreach ($report['Columns']['Column'] ?? [] as $index => $column) {
            if ($index > 0 && strtotime($column['ColTitle'])) {
                $columnDays[$index] = date('Y-m-d', strtotime($column['ColTitle']));
            }
        }
        $findAccounts = function (array $rows) use (&$findAccounts, &$snapshot, $accountIds, $columnDays) {
            foreach ($rows as $row) {
                $name = $accountIds[$row['ColData'][0]['id'] ?? ''] ?? null;
                if ($name) {
                    foreach ($columnDays as $index => $day) {
                        $snapshot['history'][$name][$day] = self::cents($row['ColData'][$index]['value'] ?? 0);
                    }
                }
                $findAccounts($row['Rows']['Row'] ?? []);
            }
        };
        $findAccounts($report['Rows']['Row'] ?? []);
        foreach ($accountIds as $name) {
            if (isset($snapshot['history'][$name][$today])) {
                $snapshot['balances'][$name] = $snapshot['history'][$name][$today];
            }
            $snapshot['last_change'][$name] = null;
            $previous = null;
            foreach ($snapshot['history'][$name] ?? [] as $day => $balance) {
                if ($previous !== null && $balance != $previous) {
                    $snapshot['last_change'][$name] = $day;
                }
                $previous = $balance;
            }
        }

        // What's recorded in the cash accounts in the last 45 days (through today), to match the planned payments to
        $ledger = $qbo->get('reports/GeneralLedger', ['start_date' => gmdate('Y-m-d', strtotime('-45 days')), 'end_date' => $today,
            'account' => implode(',', array_keys($accountIds)), 'accounting_method' => 'Accrual', 'columns' => 'tx_date,subt_nat_amount,name,memo,split_acc']);
        // The report puts the columns in its own order: map them by their keys
        $columnKeys = [];
        foreach ($ledger['Columns']['Column'] ?? [] as $index => $column) {
            $columnKeys[$index] = array_column($column['MetaData'] ?? [], 'Value', 'Name')['ColKey'] ?? '';
        }
        $walk = function (array $rows, $account) use (&$walk, &$snapshot, $columnKeys) {
            foreach ($rows as $row) {
                $section = $row['Header']['ColData'][0]['value'] ?? $account;
                if (isset($row['Rows']['Row'])) {
                    $walk($row['Rows']['Row'], $section);
                }
                if (($row['type'] ?? '') == 'Data' && preg_match('/^\d{4}-/', $row['ColData'][0]['value'] ?? '')) {
                    $values = [];
                    foreach ($columnKeys as $index => $key) {
                        $values[$key] = $row['ColData'][$index]['value'] ?? '';
                    }
                    $snapshot['recent_moves'][] = ['account' => $account, 'date' => $values['tx_date'], 'amount' => self::cents($values['subt_nat_amount']),
                        'name' => $values['name'], 'memo' => $values['memo'], 'other' => $values['split_acc'] ?? ''];
                }
            }
        };
        $walk($ledger['Rows']['Row'] ?? [], '');

        $snapshot['open_bills'] = [];
        foreach ($qbo->query("SELECT * FROM Bill WHERE Balance > '0' MAXRESULTS 1000")['Bill'] ?? [] as $bill) {
            $snapshot['open_bills'][] = ['id' => $bill['Id'], 'number' => $bill['DocNumber'] ?? '', 'party' => $bill['VendorRef']['name'] ?? '',
                'date' => $bill['TxnDate'], 'due' => $bill['DueDate'] ?? $bill['TxnDate'], 'balance' => self::cents($bill['Balance'])];
        }
        $snapshot['open_invoices'] = [];
        foreach ($qbo->query("SELECT * FROM Invoice WHERE Balance > '0' MAXRESULTS 1000")['Invoice'] ?? [] as $invoice) {
            $snapshot['open_invoices'][] = ['id' => $invoice['Id'], 'number' => $invoice['DocNumber'] ?? '', 'party' => $invoice['CustomerRef']['name'] ?? '',
                'date' => $invoice['TxnDate'], 'due' => $invoice['DueDate'] ?? $invoice['TxnDate'], 'balance' => self::cents($invoice['Balance'])];
        }
        return $snapshot;
    }

    /**
     * Dollars (as QuickBooks or the assumptions give them) to integer cents
     */
    public static function cents($dollars)
    {
        return (int) round((float) $dollars * 100);
    }
}
