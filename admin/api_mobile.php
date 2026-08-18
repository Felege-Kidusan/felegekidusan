<?php
/**
 * Retired. The phone website (PWA) and this token API are closed.
 * The FKSS Flutter app uses /api/v1. Staff use the main website for everything else.
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'status' => 'error',
    'message' => 'This phone website API is closed. Use the FKSS app or the main website.',
    'use' => '/api/v1',
], JSON_UNESCAPED_UNICODE);
