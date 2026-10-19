<?php
/**
 * ============================================================
 * Department status API (Attendance alerting — Phase 1)
 * ============================================================
 *   GET  ?action=status&member_id=N            → both department statuses
 *   GET  ?action=history&member_id=N[&department=edu|mezmur]
 *   GET  ?action=summary                       → inactive counts per department
 *   POST action=set {member_id, department, status(active|inactive), reason}
 *        hr_dept / info_dept ONLY (requirements §2, §7). Reason required.
 *
 * Reads: HR, Information, Education, Mezmur, School Admin, Super Admin.
 * Writes: HR and Information only — enforced here regardless of the page.
 * Until FEATURE_ATTENDANCE_V2 is on and sql/071 is applied every action
 * answers {status:'success', available:false}.
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/DepartmentStatusService.php';
require_once __DIR__ . '/backend/services/AttendanceWarningService.php';

use App\Services\DepartmentStatusService;

$fail = static function (int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};
$ok = static function (array $data): never {
    echo json_encode(['status' => 'success'] + $data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    $fail(401, 'Unauthorized');
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');

const DS_READ_ROLES = ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'];
const DS_WRITE_ROLES = ['hr_dept', 'info_dept'];
if (!in_array($role, DS_READ_ROLES, true)) {
    $fail(403, 'You do not have permission to use this page.');
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$input = $_POST;
if ($method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    if (is_array($json)) {
        $input = $json + $_POST;
        $action = (string)($json['action'] ?? $action);
    }
}

$readActions = ['status', 'history', 'summary'];
$writeActions = ['set'];
if (!in_array($action, array_merge($readActions, $writeActions), true)) {
    $fail(400, 'Unknown action');
}
if (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        $fail(405, 'Use POST for changes.');
    }
    if (!in_array($role, DS_WRITE_ROLES, true)) {
        $fail(403, 'Only HR and Information may change a department status.');
    }
    $token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!validateCsrf($token)) {
        $fail(403, 'Security token expired. Please refresh the page.');
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    $rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
    $check = $rl->consume($method === 'POST' ? 'dept_status_write' : 'dept_status_read', 'user:' . $adminId, $method === 'POST' ? 60 : 240, 60);
    if (!$check['allowed']) {
        $fail(429, 'Too many requests. Please wait a moment and try again.');
    }
}

$available = DepartmentStatusService::available($conn);
$departments = [];
foreach (DepartmentStatusService::DEPARTMENTS as $d) {
    $departments[] = ['code' => $d, 'label' => DepartmentStatusService::LABELS[$d]];
}

switch ($action) {
    case 'status': {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId <= 0) {
            $fail(400, 'member_id is required.');
        }
        $ok([
            'available' => $available,
            'can_change' => $available && in_array($role, DS_WRITE_ROLES, true),
            'member_id' => $memberId,
            'departments' => $departments,
            'statuses' => DepartmentStatusService::statuses($conn, $memberId),
        ]);
    }
    case 'history': {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId <= 0) {
            $fail(400, 'member_id is required.');
        }
        $dept = (string)($_GET['department'] ?? '');
        $ok([
            'available' => $available,
            'member_id' => $memberId,
            'history' => DepartmentStatusService::history($conn, $memberId, $dept !== '' ? $dept : null, 200),
        ]);
    }
    case 'summary': {
        $counts = [];
        foreach (DepartmentStatusService::DEPARTMENTS as $d) {
            $counts[$d] = DepartmentStatusService::inactiveCount($conn, $d);
        }
        $ok(['available' => $available, 'departments' => $departments, 'inactive' => $counts]);
    }
    case 'set': {
        if (!$available) {
            $ok(['available' => false, 'changed' => false,
                 'message' => 'Department status is not available yet (feature disabled or sql/071 not applied).']);
        }
        $memberId = (int)($input['member_id'] ?? 0);
        $dept = is_string($input['department'] ?? null) ? trim($input['department']) : '';
        $status = is_string($input['status'] ?? null) ? strtolower(trim($input['status'])) : '';
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if ($memberId <= 0) {
            $fail(400, 'member_id is required.');
        }
        if (!DepartmentStatusService::isDepartment($dept)) {
            $fail(400, 'department must be edu or mezmur.');
        }
        if (!in_array($status, DepartmentStatusService::STATUSES, true)) {
            $fail(400, 'status must be active or inactive.');
        }
        if (mb_strlen($reason) < 3) {
            $fail(400, 'A reason (at least 3 characters) is required.');
        }
        try {
            $actor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
                $adminId,
                (string)($_SESSION['admin_username'] ?? ''),
                'admin',
                $_SERVER
            );
            $result = DepartmentStatusService::setStatus($conn, $memberId, $dept, $status, $adminId, $reason, 'manual', null, $actor);
        } catch (\DomainException $e) {
            $fail(422, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Department status change failed: ' . $e->getMessage());
            $fail(500, 'Unable to change the department status right now.');
        }
        if (!empty($result['changed']) && $status === 'active') {
            // Phase 2: reactivation starts a fresh period — re-evaluate so stale
            // streak data is cleared (flag-gated, never fails the change).
            \App\Services\AttendanceWarningService::safeEvaluate($conn, $dept, $memberId, ['actor' => $adminId]);
        }
        $ok([
            'available' => true,
            'changed' => $result['changed'],
            'message' => $result['changed']
                ? DepartmentStatusService::LABELS[$dept] . ' status set to ' . $status . '.'
                : ($result['message'] ?? 'No change.'),
            'statuses' => DepartmentStatusService::statuses($conn, $memberId),
        ]);
    }
}
$fail(400, 'Unknown action');
