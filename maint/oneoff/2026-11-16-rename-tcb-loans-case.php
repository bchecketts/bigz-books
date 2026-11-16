#!/usr/bin/php
<?php

/**
 * "TCB LOAN ..." -> "TCB Loan ..." for the five The Commercial Bank loan accounts (user's request, after
 * 2026-11-16-rename-tcb-loans.php). Names only.
 *
 * OUTCOME: run 2026-11-16; all five renamed.
 *
 *   php maint/oneoff/2026-11-16-rename-tcb-loans-case.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

$qbo = app::qbo();
$plan = [];
foreach ($qbo->query("SELECT * FROM Account WHERE Name LIKE 'TCB LOAN%'")['Account'] ?? [] as $account) {
    if (!str_starts_with($account['Name'], 'TCB LOAN ')) {
        continue;
    }
    $to = 'TCB Loan '.substr($account['Name'], strlen('TCB LOAN '));
    printf("#%s %s -> %s\n", $account['Id'], $account['Name'], $to);
    $plan[] = [$account, $to];
}
if (count($plan) != 5) {
    fwrite(STDERR, count($plan)." accounts named TCB LOAN, not 5: nothing changed\n");
    exit(1);
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
foreach ($plan as [$account, $to]) {
    $saved = $qbo->update('Account', ['Id' => $account['Id'], 'SyncToken' => $account['SyncToken'], 'Name' => $to]);
    echo "#{$saved['Id']} {$saved['Name']}\n";
}
