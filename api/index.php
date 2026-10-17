<?php
// /api/ has no browsable content; block directory listing and probing.
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'none'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
echo json_encode(['status' => 'error', 'message' => 'Not found.']);
exit;
