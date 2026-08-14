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
    http_response_code($code);
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
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $code HTTP status code
 * @param array $extra Additional error data
 */
function err($message, $code = 400, $extra = []) {
    http_response_code($code);
    $response = ['status' => 'error', 'message' => $message];
    if (!empty($extra)) $response = array_merge($response, $extra);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send paginated list response
 * @param array $items The data items
 * @param int $total Total count (before pagination)
 * @param int $page Current page
 * @param int $limit Items per page
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

/**
 * Parse pagination params from request
 * Returns [page, limit, offset]
 */
function getPagination($maxLimit = 100) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min($maxLimit, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    return [$page, $limit, $offset];
}

/**
 * Get JSON body from POST/PUT request
 */
function getBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    return $data;
}
