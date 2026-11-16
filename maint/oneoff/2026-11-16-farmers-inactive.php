#!/usr/bin/php
<?php

/**
 * The Farmers Bank accounts are closed: the savings account (closed 2026-06-18) and the line of credit (paid off by the
 * Schwab line 2026-04-21). Both are $0 in QuickBooks; makes them inactive (user's OK).
 *
 * OUTCOME: run 2026-11-16; both inactive.
 *
 *   php maint/oneoff/2026-11-16-farmers-inactive.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const ACCOUNTS = ['The Farmers Bank Business Savings', 'The Farmers Bank (Line of Credit)'];

$qbo = app::qbo();
$plan = [];
foreach (ACCOUNTS as $name) {
    $account = $qbo->query("SELECT * FROM Account WHERE Name = '".addslashes($name)."'")['Account'][0] ?? null;
    if (!$account || (float) $account['CurrentBalance'] != 0) {
        fwrite(STDERR, "{$name} is inactive already or not at 0: nothing changed\n");
        exit(1);
    }
    echo "Make inactive: {$account['Name']} (#{$account['Id']}, balance {$account['CurrentBalance']})\n";
    $plan[] = $account;
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as $account) {
    $saved = $qbo->update('Account', ['Id' => $account['Id'], 'SyncToken' => $account['SyncToken'], 'Name' => $account['Name'], 'Active' => false]);
    echo "{$saved['Name']}: active=".($saved['Active'] ? 'yes' : 'no')."\n";
}
