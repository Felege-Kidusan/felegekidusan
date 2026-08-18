<?php
/**
 * Teacher / subject / class assignment API.
 * Thin JSON wrapper around AssignmentService.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/member_sync.php';
require_once __DIR__ . '/backend/services/AssignmentService.php';

use App\Services\AssignmentService;

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['admin_role'] ?? '';
$manageRoles = ['super_admin', 'school_admin', 'edu_dept'];
if (!in_array($role, $manageRoles, true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only the Education department can manage assignments.']);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$writeActions = ['assign', 'assign_bulk', 'set_primary', 'set_homeroom', 'unassign', 'set_class_subjects'];

if (in_array($action, $writeActions, true)) {
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
    if (function_exists('ay_require_writable')) {
        if ($action === 'set_class_subjects') {
            ay_block_if_readonly($conn);
        } else {
            ay_require_writable($conn);
        }
    }
}

AssignmentService::ensureSchema($conn);

try {
    switch ($action) {
        case 'matrix':
            echo json_encode(AssignmentService::matrix($conn), JSON_UNESCAPED_UNICODE);
            break;

        case 'workload':
            echo json_encode(AssignmentService::workload($conn), JSON_UNESCAPED_UNICODE);
            break;

        case 'gaps':
            echo json_encode(AssignmentService::gaps($conn), JSON_UNESCAPED_UNICODE);
            break;

        case 'teachers':
            $q = trim((string)($_GET['q'] ?? ''));
            $page = (int)($_GET['page'] ?? 1);
            echo json_encode(AssignmentService::searchTeachers($conn, $q, $page), JSON_UNESCAPED_UNICODE);
            break;

        case 'class_subjects':
            $subjectId = (int)($_GET['subject_id'] ?? 0);
            $classId = (int)($_GET['class_id'] ?? 0);
            $out = ['status' => 'success', 'classes' => [], 'subjects' => []];
            if ($subjectId) {
                $stmt = $conn->prepare(
                    "SELECT c.id, c.class_name, c.class_name_en
                     FROM classes c
                     JOIN class_subjects cs ON cs.class_id = c.id
                     WHERE cs.subject_id = ? AND c.is_active = 1
                     ORDER BY c.level_order"
                );
                if ($stmt) {
                    $stmt->bind_param('i', $subjectId);
                    $stmt->execute();
                    $r = $stmt->get_result();
                    while ($row = $r->fetch_assoc()) {
                        $out['classes'][] = $row;
                    }
                    $stmt->close();
                }
            }
            if ($classId) {
                $stmt = $conn->prepare(
                    "SELECT s.id, s.subject_name, s.subject_name_en
                     FROM subjects s
                     JOIN class_subjects cs ON cs.subject_id = s.id
                     WHERE cs.class_id = ? AND s.is_active = 1
                     ORDER BY s.subject_name"
                );
                if ($stmt) {
                    $stmt->bind_param('i', $classId);
                    $stmt->execute();
                    $r = $stmt->get_result();
                    while ($row = $r->fetch_assoc()) {
                        $out['subjects'][] = $row;
                    }
                    $stmt->close();
                }
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;

        case 'assign':
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            $classId = (int)($_POST['class_id'] ?? 0);
            $subjectId = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
            $asgRole = (string)($_POST['role'] ?? 'primary');
            $res = AssignmentService::assign($conn, $teacherId, $classId, $subjectId, $asgRole, null, (int)$_SESSION['admin_id']);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            break;

        case 'assign_bulk':
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            $subjectId = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
            $asgRole = (string)($_POST['role'] ?? 'primary');
            $classIds = $_POST['class_ids'] ?? [];
            if (!is_array($classIds)) {
                $decoded = json_decode((string)$classIds, true);
                $classIds = is_array($decoded) ? $decoded : [];
            }
            $res = AssignmentService::assignBulk($conn, $teacherId, $classIds, $subjectId, $asgRole, null, (int)$_SESSION['admin_id']);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            break;

        case 'set_primary':
            echo json_encode(AssignmentService::setPrimary($conn, (int)($_POST['assignment_id'] ?? 0)), JSON_UNESCAPED_UNICODE);
            break;

        case 'set_homeroom':
            echo json_encode(
                AssignmentService::setHomeroom(
                    $conn,
                    (int)($_POST['teacher_id'] ?? 0),
                    (int)($_POST['class_id'] ?? 0),
                    null,
                    (int)$_SESSION['admin_id']
                ),
                JSON_UNESCAPED_UNICODE
            );
            break;

        case 'unassign':
            echo json_encode(AssignmentService::unassign($conn, (int)($_POST['assignment_id'] ?? 0)), JSON_UNESCAPED_UNICODE);
            break;

        case 'set_class_subjects':
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            $classIds = $_POST['class_ids'] ?? [];
            if (!is_array($classIds)) {
                $decoded = json_decode((string)$classIds, true);
                $classIds = is_array($decoded) ? $decoded : [];
            }
            echo json_encode(AssignmentService::setClassSubjects($conn, $subjectId, $classIds), JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('api_assignments [' . $action . ']: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}
