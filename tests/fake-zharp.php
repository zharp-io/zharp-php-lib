<?php

// Router for `php -S`: stands in for Zharp's ingest endpoint. Appends each request's headers and
// body to the file in ZHARP_FAKE_OUT as one JSON line; answers with the status in ZHARP_FAKE_STATUS
// (a file holding a number), 201 by default.

$out = getenv('ZHARP_FAKE_OUT');
$statusFile = getenv('ZHARP_FAKE_STATUS');
$status = $statusFile && is_file($statusFile) ? (int) trim((string) file_get_contents($statusFile)) : 201;

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}
file_put_contents($out, json_encode([
    'path' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => json_decode((string) file_get_contents('php://input'), true),
]) . "\n", FILE_APPEND | LOCK_EX);

http_response_code($status);
