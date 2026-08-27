<?php
/**
 * School API v1 — Response Helpers
 * Standard JSON response format for all endpoints
 */

/**
 * Send success response
 * @param mixed $data Response data
 * @param int $code HTTP status code
 */
function ok($data = null, $code = 200) {
    $response = ['status' => 'success'];
    if ($data !== null) {
        if (is_array($data) && isset($data['message'])) {
            $response['message'] = $data['message'];
            unset($data['message']);
            if (!empty($data)) $response['data'] = $data;
        } else {
            $response['data'] = $data;
        }
    }
    apiSendJson($response, $code);
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $code HTTP status code
 * @param array $extra Additional error data
 */
function err($message, $code = 400, $extra = []) {
    $response = ['status' => 'error', 'message' => $message];
    if (!empty($extra)) $response = array_merge($response, $extra);
    apiSendJson($response, $code);
}

function apiSendJson(array $response, int $code = 200) {
    // Module version handshake (see routes/mezmur.php): lets clients
    // detect a stale server and show an actionable update message.
    if (defined('MEZMUR_API_VERSION') && !isset($response['server_meta'])) {
        $response['server_meta'] = ['mezmur' => MEZMUR_API_VERSION];
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    $json = json_encode($response, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"status":"error","message":"Could not encode response."}';
        $code = 500;
        if (!headers_sent()) http_response_code($code);
    }
    if (function_exists('apiIdempotencyStore')) {
        apiIdempotencyStore($json, $code);
    }
    echo $json;
    exit;
}

/**
 * Send paginated list response
 */
function paginated($items, $total, $page, $limit) {
    ok([
        'items' => $items,
        'pagination' => [
            'total' => (int)$total,
            'page' => (int)$page,
            'limit' => (int)$limit,
            'pages' => (int)ceil($total / max($limit, 1)),
            'has_more' => ($page * $limit) < $total
        ]
    ]);
}

function getPagination($maxLimit = 100) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min($maxLimit, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    return [$page, $limit, $offset];
}

function getBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    return $data;
}
