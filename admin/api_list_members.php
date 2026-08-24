<?php
/**
 * Member directory API controller.
 *
 * Query policy and role-aware data projection live in MemberDirectoryService;
 * browser rendering and interaction live in admin/js/manage-members.js.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberDirectoryService.php';

use App\Services\MemberDirectoryService;

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Member directory database is unavailable.');
    }

    $service = new MemberDirectoryService($pdo);
    $result = $service->search(
        $_GET,
        (string)($_SESSION['admin_role'] ?? ''),
        trim((string)($_GET['view'] ?? ''))
    );

    $sections = ($_GET['include_options'] ?? '') === '1'
        ? $service->sections()
        : [];
    echo json_encode([
        'status' => 'success',
        'members' => $result['members'],
        'options' => ['sections' => $sections],
        'total' => $result['total'],
        'page' => $result['page'],
        'limit' => $result['limit'],
        'pages' => $result['pages'],
        'next_cursor' => $result['next_cursor'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $error) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'The requested directory view is not available.']);
} catch (Throwable $error) {
    reportInternalError('Member directory request failed', $error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not load members.']);
}
