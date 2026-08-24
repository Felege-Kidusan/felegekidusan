<?php
/**
 * Bounded compatibility endpoint for archived members.
 * New dashboard code uses api_list_members.php?view=archive directly.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberDirectoryService.php';

use App\Services\MemberDirectoryService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Service unavailable.']);
    exit;
}

try {
    $result = (new MemberDirectoryService($pdo))->search(
        $_GET,
        (string)($_SESSION['admin_role'] ?? ''),
        'archive'
    );
    echo json_encode([
        'status' => 'success',
        'count' => $result['total'],
        'members' => $result['members'],
        'page' => $result['page'],
        'limit' => $result['limit'],
        'pages' => $result['pages'],
        'next_cursor' => $result['next_cursor'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $error) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Archived members are not available for this role.']);
} catch (Throwable $error) {
    error_log('Archived member directory failed.');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to load archived members.']);
}
