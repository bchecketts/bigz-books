<?php

// Upload: drop files here (upload.js sends each to save.php, which saves it to ../uploads), and the recent uploads.

require_once __DIR__.'/../../app/common.php';

$maxSize = ini_get('upload_max_filesize');
$sizeText = function (int $bytes): string {
    foreach (['bytes', 'KB', 'MB', 'GB'] as $unit) {
        if ($bytes < 1024 || $unit === 'GB') {
            return ($unit === 'bytes' ? $bytes : number_format($bytes, 1))." {$unit}";
        }
        $bytes /= 1024;
    }
    return '';
};

?><!doctype html>
<html lang="en">
<head><?= site::head('Upload · Big Z Properties') ?>
<style>
  main { max-width: 44rem; }
  #drop { display: block; border: 2px dashed var(--line); border-radius: 8px; padding: 2.5rem 1rem; text-align: center; background: var(--tile); cursor: pointer; }
  #drop.over { border-color: var(--accent); }
  #drop p { margin: .3rem 0; }
  ul.files { list-style: none; padding: 0; }
  ul.files li { display: flex; justify-content: space-between; gap: 1rem; padding: .4rem 0; border-bottom: 1px solid var(--line); overflow-wrap: anywhere; }
  progress { width: 6rem; }
</style>
</head>
<body>
<?= site::header('/upload/') ?>
<main>
<h1>Upload files</h1>
<p class="muted">Statements, exports and closing documents for Claude to enter. Once entered they're filed in <code>~/bigz/documents</code>.</p>
<label id="drop">
  <p><strong>Drop files here</strong> or click to choose</p>
  <p class="muted">Up to <?= htmlspecialchars((string) $maxSize) ?> each</p>
  <input type="file" id="picker" multiple hidden>
</label>
<ul id="progress" class="files"></ul>

<h2>Waiting to be entered</h2>
<ul class="files">
<?php $recent = uploads::recent(); ?>
<?php if (!$recent) { ?>
  <li class="muted">Nothing: everything uploaded has been entered and filed.</li>
<?php } ?>
<?php foreach ($recent as $file) { ?>
  <li><span><?= htmlspecialchars($file['name']) ?></span>
      <span class="muted"><?= $sizeText($file['size']) ?> · <?= (new DateTime('@'.$file['time']))->setTimezone(new DateTimeZone('America/New_York'))->format('M j, g:ia') ?></span></li>
<?php } ?>
</ul>
</main>
<script src="upload.js"></script>
</body>
</html>
