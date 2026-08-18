<?php
/**
 * Retired. A phone token can no longer become a full website session.
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'status' => 'error',
    'message' => 'The phone-to-website login bridge is closed. Sign in on the website.',
], JSON_UNESCAPED_UNICODE);
