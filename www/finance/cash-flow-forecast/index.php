<?php

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
$age = '<div style="position:fixed;top:8px;right:12px;font-size:13px;padding:2px 8px;border-radius:4px;z-index:10;'
    .($stale ? 'background:#fee2e2;color:#b91c1c;font-weight:600' : 'background:#f3f4f6;color:#4b5563').'">Updated '.$ago.'</div>';
$page = str_replace('<head>', '<head><meta http-equiv="refresh" content="300">', $page);
echo str_replace('<!--AGE-->', $age, $page);
