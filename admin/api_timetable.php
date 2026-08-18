<?php
/**
 * Timetable API — periods + weekly grid.
 * Teachers may only read their own schedule. Writes are Education / admin.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/TimetableService.php';

use App\Services\TimetableService;

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['admin_role'] ?? '';
$isTeacher = $role === 'teacher';
$manageRoles = ['super_admin', 'school_admin', 'edu_dept'];
$canManage = in_array($role, $manageRoles, true);

if (!$isTeacher && !$canManage) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$writeActions = ['save_period', 'delete_period', 'save_entry', 'clear_entry'];

if (in_array($action, $writeActions, true)) {
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only Education can edit the timetable.']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'POST required']);
        exit;
    }
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrf)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

TimetableService::ensureSchema($conn);

try {
    switch ($action) {
        case 'periods':
            echo json_encode(TimetableService::periods($conn), JSON_UNESCAPED_UNICODE);
            break;

        case 'class_grid':
            if (!$canManage) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                break;
            }
            echo json_encode(TimetableService::classGrid($conn, (int)($_GET['class_id'] ?? 0)), JSON_UNESCAPED_UNICODE);
            break;

        case 'teacher_schedule':
            $tid = (int)($_GET['teacher_id'] ?? 0);
            if ($isTeacher) {
                $tid = (int)$_SESSION['admin_id'];
            }
            if ($tid <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Teacher is required.']);
                break;
            }
            echo json_encode(TimetableService::teacherSchedule($conn, $tid), JSON_UNESCAPED_UNICODE);
            break;

        case 'my_schedule':
            if (!$isTeacher) {
                echo json_encode(['status' => 'error', 'message' => 'Not a teacher account']);
                break;
            }
            echo json_encode(TimetableService::teacherSchedule($conn, (int)$_SESSION['admin_id']), JSON_UNESCAPED_UNICODE);
            break;

        case 'save_period':
            echo json_encode(TimetableService::savePeriod($conn, $_POST), JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_period':
            echo json_encode(TimetableService::deletePeriod($conn, (int)($_POST['period_id'] ?? 0)), JSON_UNESCAPED_UNICODE);
            break;

        case 'save_entry':
            echo json_encode(TimetableService::saveEntry($conn, $_POST), JSON_UNESCAPED_UNICODE);
            break;

        case 'clear_entry':
            echo json_encode(
                TimetableService::clearEntry(
                    $conn,
                    (int)($_POST['class_id'] ?? 0),
                    (int)($_POST['period_id'] ?? 0),
                    (int)($_POST['day_of_week'] ?? 0)
                ),
                JSON_UNESCAPED_UNICODE
            );
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('api_timetable [' . $action . ']: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}
