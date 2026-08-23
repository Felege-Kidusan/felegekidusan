<?php
/**
 * Load or remove the practice member roster (TEST-FKSS).
 * Super Admin / School Admin / Education only.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/TestMemberSeed.php';

use App\Services\TestMemberSeed;

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Please log in.']);
    exit;
}

$role = $_SESSION['admin_role'] ?? '';
if (!in_array($role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Super Admin, School Admin, or Education can do this.']);
    exit;
}

$action = $_REQUEST['action'] ?? 'status';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!function_exists('validateCsrf') || !validateCsrf($csrf)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

try {
    if ($action === 'status') {
        echo json_encode(['status' => 'success', 'data' => TestMemberSeed::status($conn)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'load') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            exit;
        }
        $result = TestMemberSeed::load($conn, (int)$_SESSION['admin_id']);
        echo json_encode([
            'status' => !empty($result['ok']) ? 'success' : 'error',
            'message' => $result['message'] ?? '',
            'data' => $result,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'clear') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            exit;
        }
        $result = TestMemberSeed::clear($conn);
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
