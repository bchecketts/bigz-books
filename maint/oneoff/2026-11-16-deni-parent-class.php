#!/usr/bin/php
<?php

/**
 * The four Deni Ct unit classes sorted around "1020 Tuxedo Ln." (1020 Deni, 1020 Tuxedo, 1022 Deni...). Groups them
 * under a new parent class "1020-1026 Deni Ct" (the 4-plex) so they sit together and reports can subtotal the
 * building (user's OK). Unit names unchanged; their full names become "1020-1026 Deni Ct:<unit>". Existing entries keep
 * their classes.
 *
 * OUTCOME: run 2026-11-16; parent class created, four units moved under it. rent_oconee_clarke.php now uses the full
 * names and matches existing entries by class Id.
 *
 *   php maint/oneoff/2026-11-16-deni-parent-class.php [--post]
 */

require_once __DIR__.'/../../app/common.php';

const PARENT = '1020-1026 Deni Ct';
const UNITS = ['1020 Deni Ct.', '1022 Deni Ct', '1024 Deni Ct', '1026 Deni Ct'];

$qbo = app::qbo();
$one = fn ($type, $field, $value) => $qbo->query("SELECT * FROM {$type} WHERE {$field} = '".addslashes($value)."'")[$type][0] ?? null;
$parent = $one('Class', 'Name', PARENT);
$units = [];
foreach (UNITS as $name) {
    $unit = $one('Class', 'Name', $name);
    if (!$unit || !empty($unit['SubClass'])) {
        fwrite(STDERR, "Class {$name} missing or already a sub-class: nothing changed\n");
        exit(1);
    }
    $units[] = $unit;
}
echo $parent ? 'Parent class exists: '.PARENT."\n" : 'Create class '.PARENT."\n";
foreach ($units as $unit) {
    echo "Move {$unit['Name']} under ".PARENT."\n";
}
if (!cliOption('post')) {
    echo "Preview: run with --post to save\n";
    exit;
}
$parent ??= $qbo->create('Class', ['Name' => PARENT]);
foreach ($units as $unit) {
    $saved = $qbo->update('Class', ['Id' => $unit['Id'], 'SyncToken' => $unit['SyncToken'], 'Name' => $unit['Name'], 'SubClass' => true,
        'ParentRef' => ['value' => $parent['Id']]]);
    echo "{$saved['FullyQualifiedName']}\n";
}
