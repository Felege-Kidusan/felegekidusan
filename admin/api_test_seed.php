<?php
/**
 * Load or remove the practice member roster (TEST-FKSS).
 * Disabled by default; when deployment-enabled, Super Admin only.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

use App\Services\SecurityAuditService;
use App\Services\TestMemberSeed;

if (!defined('ENABLE_TEST_DATA_TOOLS') || ENABLE_TEST_DATA_TOOLS !== true) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Test data tools are not enabled.']);
    exit;
}
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/TestMemberSeed.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Please log in.']);
    exit;
}

$role = $_SESSION['admin_role'] ?? '';
if ($role !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Super Admin can use test data tools.']);
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $requestMethod === 'POST'
    ? (string)($_POST['action'] ?? '')
    : (string)($_GET['action'] ?? 'status');
$adminId = (int)$_SESSION['admin_id'];

if ($requestMethod === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!function_exists('validateCsrf') || !validateCsrf($csrf)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

// Loading the complete practice roster can take time. Do not serialize every
// other Super Admin request behind PHP's per-session file lock.
if ($requestMethod === 'POST' && in_array($action, ['load', 'clear'], true)
    && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    if ($action === 'status') {
        echo json_encode(['status' => 'success', 'data' => TestMemberSeed::status($conn)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'load') {
        if ($requestMethod !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            exit;
        }
        $result = TestMemberSeed::load($conn, $adminId);
        SecurityAuditService::record($conn, 'Test Data Loaded', [
            'inserted' => (int)($result['inserted'] ?? 0),
            'updated' => (int)($result['updated'] ?? 0),
            'enrolled' => (int)($result['enrolled'] ?? 0),
            'errors' => (int)($result['errors'] ?? 0),
        ], 'test_data');
        echo json_encode([
            'status' => !empty($result['ok']) ? 'success' : 'error',
            'message' => $result['message'] ?? '',
            'data' => $result,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'clear') {
        if ($requestMethod !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            exit;
        }
        $result = TestMemberSeed::clear($conn);
        SecurityAuditService::record($conn, 'Test Data Cleared', [
            'removed' => (int)($result['removed'] ?? 0),
        ], 'test_data');
        echo json_encode([
            'status' => 'success',
            'message' => $result['message'] ?? '',
            'data' => $result,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('api_test_seed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not finish. Please try again.']);
}
