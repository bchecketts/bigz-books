<?php

use RoundSphere\Bookkeeping\Qbo;

/**
 * Shared services for Big Z's scripts
 */
class app
{
    protected static $qbo = [];

    /**
     * QuickBooks client for Big Z. $sandbox = true uses an Intuit sandbox company.
     */
    public static function qbo($sandbox = false)
    {
        if (!isset(self::$qbo[$sandbox])) {
            self::$qbo[$sandbox] = new Qbo($sandbox);
        }
        return self::$qbo[$sandbox];
    }
}
