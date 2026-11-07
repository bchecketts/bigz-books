#!/usr/bin/php
<?php

/**
 * Checks the QuickBooks connection by printing the company name and a few accounts.
 *
 * USAGE
 *   php maint/qbo_test.php [--sandbox]
 */

require_once __DIR__.'/../app/common.php';

$qbo  = app::qbo((bool) cliOption('sandbox'));
$info = $qbo->get('companyinfo/'.$qbo->realmId())['CompanyInfo'];
echo "Company: {$info['CompanyName']} (realm {$qbo->realmId()})\n";

foreach ($qbo->query("SELECT * FROM Account MAXRESULTS 10")['Account'] ?? [] as $account) {
    printf("  %-45s %-22s %12.2f\n", $account['FullyQualifiedName'], $account['AccountType'], $account['CurrentBalance']);
}
