<?php

date_default_timezone_set('UTC');

mb_internal_encoding('UTF-8');

ini_set('log_errors', true);
ini_set('error_reporting', E_ALL);

require_once __DIR__.'/../vendor/autoload.php';

\RoundSphere\Properties::setConfigFile(__DIR__.'/project.properties');

function bclog($msg)
{
    // Write to the configured logfile (applogs/app.log), if set and writable.
    $logfile = \RoundSphere\Properties::getProperty('logfile');
    if ($logfile) {
        $fh = @fopen($logfile, 'a');
        if ($fh) {
            fwrite($fh, date('Y-m-d H:i:s')." {$msg}\n");
            fclose($fh);
        }
    }
}

/**
 * Value of a --name=value (or bare --name) command-line option, or $default.
 */
function cliOption($name, $default = null)
{
    foreach ($GLOBALS['argv'] ?? [] as $arg) {
        if ($arg === "--{$name}") {
            return true;
        }
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen("--{$name}="));
        }
    }
    return $default;
}

function dollars($cents)
{
    return number_format($cents / 100, 2, '.', '');
}
