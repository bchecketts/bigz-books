<?php

require_once __DIR__.'/../app/common.php';

$maxSize = ini_get('upload_max_filesize');

function sizeText($bytes)
{
    foreach (['bytes', 'KB', 'MB', 'GB'] as $unit) {
        if ($bytes < 1024 || $unit === 'GB') {
            return ($unit === 'bytes' ? $bytes : number_format($bytes, 1))." {$unit}";
        }
        $bytes /= 1024;
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Big Z Properties</title>
<style>
  :root { --text: #222; --muted: #666; --line: #ddd; --accent: #2a6db0; --bg: #fff; --drop: #f4f8fc; --ok: #2b7a3d; --bad: #b3261e; }
  @media (prefers-color-scheme: dark) {
    :root { --text: #e6e6e6; --muted: #9a9a9a; --line: #444; --accent: #6ea8e0; --bg: #1b1b1b; --drop: #222b35; --ok: #6cc47f; --bad: #f08a80; }
  }
  body { font-family: system-ui, sans-serif; max-width: 44rem; margin: 2rem auto; padding: 0 1rem; color: var(--text); background: var(--bg); }
  h1 { font-size: 1.5rem; }
  h2 { font-size: 1.1rem; margin-top: 2rem; }
  #drop { border: 2px dashed var(--line); border-radius: 8px; padding: 2.5rem 1rem; text-align: center; background: var(--drop); cursor: pointer; }
  #drop.over { border-color: var(--accent); }
  #drop p { margin: .3rem 0; }
  .muted { color: var(--muted); font-size: .9rem; }
  ul { list-style: none; padding: 0; }
  li { display: flex; justify-content: space-between; gap: 1rem; padding: .4rem 0; border-bottom: 1px solid var(--line); overflow-wrap: anywhere; }
  .ok { color: var(--ok); }
  .bad { color: var(--bad); }
  progress { width: 6rem; }
</style>
</head>
<body>
<h1>Big Z Properties</h1>

<h2>Upload files</h2>
<label id="drop">
  <p><strong>Drop files here</strong> or click to choose</p>
  <p class="muted">Up to <?= htmlspecialchars($maxSize) ?> each</p>
  <input type="file" id="picker" multiple hidden>
</label>
<ul id="progress"></ul>

<h2>Recent uploads</h2>
<ul>
<?php foreach (uploads::recent() as $file) { ?>
  <li><span><?= htmlspecialchars($file['name']) ?></span>
      <span class="muted"><?= sizeText($file['size']) ?> · <?= (new DateTime('@'.$file['time']))->setTimezone(new DateTimeZone('America/New_York'))->format('M j, g:ia') ?></span></li>
<?php } ?>
</ul>

<script src="upload.js"></script>
</body>
</html>
