<?php

/**
 * Receives files from the uploader on index.php (one or more per request, field "files[]") and saves them
 * to uploads_dir. Apache allows POST only here, and only after the Basic auth login. Answers in JSON.
 */

require_once __DIR__.'/../app/common.php';

header('Content-Type: application/json');

// The browser sends the Basic auth login with any POST to this site, even one started from another site's
// page, so only accept uploads that come from our own page
if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'https://bigz.roundsphere.com') {
    http_response_code(403);
    echo json_encode(['error' => 'Uploads are only accepted from bigz.roundsphere.com']);
    exit;
}

$files = $_FILES['files'] ?? null;
if (!$files || !is_array($files['name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No files received (the upload may be larger than '.ini_get('post_max_size').')']);
    exit;
}

$user    = $_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? '';
$results = [];
foreach ($files['name'] as $index => $originalName) {
    $result = ['name' => $originalName];
    try {
        if ($files['error'][$index] === UPLOAD_ERR_INI_SIZE || $files['error'][$index] === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('Larger than '.ini_get('upload_max_filesize'));
        }
        if ($files['error'][$index] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Upload failed (error {$files['error'][$index]})");
        }
        $result['saved_as'] = uploads::save($files['tmp_name'][$index], $originalName);
        $result['size']     = $files['size'][$index];
        bclog("upload by {$user}: {$result['saved_as']} ({$result['size']} bytes)");
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
        bclog("upload by {$user} failed: ".uploads::safeName($originalName).": {$e->getMessage()}");
    }
    $results[] = $result;
}
echo json_encode(['files' => $results]);
