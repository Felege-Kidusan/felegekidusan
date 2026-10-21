<?php
/**
 * Timetable API — per‑class working days, bell schedule, weekly grid and
 * makeup classes (Education builder) + the teacher's own schedule.
 *
 * Reads : Education / School Admin / Super Admin (any class), teachers (own).
 * Writes: Education / School Admin / Super Admin only, POST + CSRF, refused
 *         while time‑travelling to a past academic year (ay_require_writable).
 * Every write is audited by TimetableService into activity_logs.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/TimetableService.php';
require_once __DIR__ . '/backend/danger_confirm.php';

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
$writeActions = ['save_days', 'save_period', 'delete_period', 'copy_bell', 'save_entry', 'clear_entry', 'save_makeup', 'delete_makeup'];
$readActions = ['class_setup', 'teacher_schedule', 'my_schedule', 'classes'];

$actor = [
    'id'       => (int)$_SESSION['admin_id'],
    'username' => (string)($_SESSION['admin_username'] ?? ''),
    'ip'       => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
];

$yearCtx = function_exists('ay_resolve') ? ay_resolve($conn) : ['id' => 0, 'active_id' => 0, 'is_readonly' => false];
$yearId = (int)($yearCtx['id'] ?? 0);

if (in_array($action, $writeActions, true)) {
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only Education can edit the timetable.']);
        exit;
    }
    requirePostActions($action, $writeActions);
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrf)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
    // Refuses with 403/409 JSON while viewing a past year or with no active year.
    $yearId = (int)ay_require_writable($conn);
} elseif (!in_array($action, $readActions, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

if ($yearId <= 0) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'No academic year is set. A School Admin must set the current year first.', 'code' => 'no_active_year']);
    exit;
}

try {
    switch ($action) {
        case 'classes':
            if (!$canManage) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                break;
            }
            $rows = [];
            $r = $conn->query("SELECT id, class_name, class_name_en, class_code, level_order FROM classes WHERE is_active = 1 ORDER BY level_order, class_name");
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $rows[] = [
                        'id'            => (int)$row['id'],
                        'class_name'    => $row['class_name'],
                        'class_name_en' => $row['class_name_en'],
                        'class_code'    => $row['class_code'],
                    ];
                }
            }
            echo json_encode([
                'status'           => 'success',
                'classes'          => $rows,
                'academic_year_id' => $yearId,
                'year_name'        => (string)($yearCtx['year']['year_name'] ?? ''),
                'ec_year'          => $yearCtx['ec_year'] ?? null,
                'readonly'         => !empty($yearCtx['is_readonly']),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'class_setup':
            if (!$canManage) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                break;
            }
            echo json_encode(TimetableService::classSetup($conn, (int)($_GET['class_id'] ?? 0), $yearId), JSON_UNESCAPED_UNICODE);
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
            echo json_encode(TimetableService::teacherSchedule($conn, $tid, $yearId), JSON_UNESCAPED_UNICODE);
            break;

        case 'my_schedule':
            if (!$isTeacher) {
                echo json_encode(['status' => 'error', 'message' => 'Not a teacher account']);
                break;
            }
            echo json_encode(TimetableService::teacherSchedule($conn, (int)$_SESSION['admin_id'], $yearId), JSON_UNESCAPED_UNICODE);
            break;

        case 'save_days':
            $days = $_POST['days'] ?? [];
            if (is_string($days)) {
                $days = $days === '' ? [] : explode(',', $days);
            }
            // HIGH risk only when a current working day is being removed
            // (lessons on it are deleted and attendance stops that weekday).
            $classIdForDays = (int)($_POST['class_id'] ?? 0);
            $keepDays = array_map('intval', (array)$days);
            $removingDays = array_diff(TimetableService::workingDays($conn, $classIdForDays, $yearId), $keepDays);
            if ($removingDays) {
                dc_require_phrase('timetable.remove_day');
            }
            echo json_encode(TimetableService::saveWorkingDays($conn, (int)($_POST['class_id'] ?? 0), $yearId, (array)$days, $actor), JSON_UNESCAPED_UNICODE);
            break;

        case 'save_period':
            echo json_encode(TimetableService::savePeriod($conn, $_POST, $yearId, $actor), JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_period':
            dc_require_phrase('timetable.delete_period');
            echo json_encode(TimetableService::deletePeriod($conn, (int)($_POST['period_id'] ?? 0), (int)($_POST['class_id'] ?? 0), $yearId, $actor), JSON_UNESCAPED_UNICODE);
            break;

        case 'copy_bell':
            dc_require_phrase('timetable.copy_bell');
            echo json_encode(TimetableService::copyBell($conn, (int)($_POST['from_class_id'] ?? 0), (int)($_POST['class_id'] ?? 0), $yearId, $actor), JSON_UNESCAPED_UNICODE);
            break;

        case 'save_entry':
            echo json_encode(TimetableService::saveEntry($conn, $_POST, $yearId, $actor), JSON_UNESCAPED_UNICODE);
            break;

        case 'clear_entry':
            echo json_encode(
                TimetableService::clearEntry(
                    $conn,
                    (int)($_POST['class_id'] ?? 0),
                    (int)($_POST['period_id'] ?? 0),
                    (int)($_POST['day_of_week'] ?? 0),
                    $yearId,
                    $actor
                ),
                JSON_UNESCAPED_UNICODE
            );
            break;

        case 'save_makeup':
            echo json_encode(TimetableService::saveMakeup($conn, $_POST, $yearId, $actor), JSON_UNESCAPED_UNICODE);
            break;

        case 'delete_makeup':
            echo json_encode(TimetableService::deleteMakeup($conn, (int)($_POST['makeup_id'] ?? 0), $yearId, $actor), JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('api_timetable [' . $action . ']: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}
