#!/usr/bin/php
<?php

/**
 * Renames the five The Commercial Bank loan accounts to "TCB LOAN {property} *{last 4 digits}" (user's request).
 * Names only; balances and history unchanged.
 *
 * OUTCOME: run 2026-11-16; all five renamed.
 *
 *   php maint/oneoff/2026-11-16-rename-tcb-loans.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const RENAMES = [
    'The Commercial Bank *6527 - 1020 Deni Ct' => 'TCB LOAN 1020 Deni Ct *6527',
    'The Commercial Bank *6524 - 1020 Tuxedo' => 'TCB LOAN 1020 Tuxedo Ln *6524',
    'The Commercial Bank *6528 - 2050 Lakeside Dr' => 'TCB LOAN 2050 Lakeside Dr *6528',
    'The Commercial Bank *6530 - 2060 Lakeside Dr' => 'TCB LOAN 2060 Lakeside Dr *6530',
    'The Commercial Bank *6494 - 2070 Lakeside Dr' => 'TCB LOAN 2070 Lakeside Dr *6494',
];

$qbo = app::qbo();
$plan = [];
foreach (RENAMES as $from => $to) {
    $account = $qbo->query("SELECT * FROM Account WHERE Name = '".addslashes($from)."'")['Account'][0] ?? null;
    if (!$account) {
        fwrite(STDERR, "No account named {$from}: nothing changed\n");
        exit(1);
    }
    printf("#%s %-46s -> %s (balance %s)\n", $account['Id'], $from, $to, $account['CurrentBalance']);
    $plan[] = [$account, $to];
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as [$account, $to]) {
    $saved = $qbo->update('Account', ['Id' => $account['Id'], 'SyncToken' => $account['SyncToken'], 'Name' => $to]);
    echo "#{$saved['Id']} {$saved['Name']}\n";
}
