#!/usr/bin/php
<?php

/**
 * Prints a link that connects this company's QuickBooks Online company (or reconnects it after
 * it expires or is disconnected). Open it, sign in, pick the company, and you land on
 * bk.roundsphere.com, which saves the token to ../var/qbo-token. The link works once, for 15
 * minutes.
 *
 * USAGE
 *   php maint/qbo_connect.php              the real company (production keys)
 *   php maint/qbo_connect.php --sandbox    an Intuit sandbox company, for testing
 */

require_once __DIR__.'/../app/common.php';

$sandbox = (bool) cliOption('sandbox');
$company = \RoundSphere\Properties::getProperty('company');

echo "Open this link, sign in, and pick the ".($sandbox ? 'sandbox' : 'Big Z Properties')." company:\n\n";
echo app::qbo($sandbox)->authorizationUrl()."\n";
