<?php
// /api/ has no browsable content; block directory listing and probing.
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo json_encode(['status' => 'error', 'message' => 'Not found.']);
exit;
