<?php

require_once __DIR__.'/../../app/common.php';

// The cash flow forecast (page.html, written by bin/cash-forecast/build.php) with how long ago it was built, worked out
// on each request (the site allows no scripts), and a reload every 5 minutes so an open page stays current.
$pageFile = __DIR__.'/page.html';
if (!is_file($pageFile)) {
    echo '<!doctype html><title>Cash flow forecast</title><p>Not built yet: run <code>php bin/cash-forecast/build.php</code>.</p>';
    return;
}
$page = (string) file_get_contents($pageFile);
$minutes = (int) floor((time() - (int) filemtime($pageFile)) / 60);
$ago = $minutes < 1 ? 'just now' : ($minutes < 60 ? "{$minutes} minute".($minutes == 1 ? '' : 's').' ago'
    : ($minutes < 2880 ? sprintf('%d hour%s ago', intdiv($minutes, 60), intdiv($minutes, 60) == 1 ? '' : 's') : sprintf('%d days ago', intdiv($minutes, 1440))));
// Built each morning, so more than a day old means the job didn't run
$stale = $minutes > 26 * 60;
$age = '<p class="'.($stale ? 'bad' : 'muted').'" style="margin:0 0 .5rem;font-size:13px'.($stale ? ';font-weight:600' : '').'">Updated '.$ago
    .($stale ? ': the scheduled rebuild seems to have stopped' : '').'</p>';
// The shared stylesheet before the page's own styles (which win where they overlap), and the shared header
$page = str_replace('<head>', '<head><meta http-equiv="refresh" content="300"><link rel="stylesheet" href="/site.css">', $page);
$page = (string) preg_replace('/<body([^>]*)>/', '<body$1>'.site::header('/cash-flow/'), $page, 1);
echo str_replace('<!--AGE-->', $age, $page);
