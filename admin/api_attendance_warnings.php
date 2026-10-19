<?php
/**
 * ============================================================
 * Attendance warnings API (Attendance alerting — Phase 2)
 * ============================================================
 *   GET  ?action=list[&department=edu|mezmur&status=open|closed|all&kind=&stage=&member_id=&source=&q=&limit=&offset=]
 *   GET  ?action=detail&id=N              → warning + events + risk + EC absence dates
 *   GET  ?action=counts                   → open counts per department / stage (dashboard card)
 *   GET  ?action=member_summary&member_id=N → both departments: risk + warnings (profile tab)
 *   GET  ?action=risk&member_id=N&department=edu|mezmur → the shared risk object
 *   POST action=close {id, outcome(contacted|excused|escalated|deactivated|no_action), note?}
 *
 * Role scoping (requirements §8, §9):
 *   edu_dept    → Education warnings only (read + close)
 *   mezmur_dept → Mezmur warnings only (read + close)
 *   hr_dept / info_dept → both (read + close)
 *   school_admin / super_admin → both, READ-ONLY (close answers 403)
 * Every user-facing date is Ethiopian calendar (the service presents them).
 * Until FEATURE_ATTENDANCE_V2 is on and sql/072 is applied every action
 * answers {status:'success', available:false}.
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/AttendanceWarningService.php';

use App\Services\AttendanceRiskService;
use App\Services\AttendanceWarningService;

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
if (!in_array($role, AttendanceWarningService::READ_ROLES, true)) {
    $fail(403, 'You do not have permission to use this page.');
}
$myDepartments = AttendanceWarningService::departmentsForRole($role);

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

$readActions = ['list', 'detail', 'counts', 'member_summary', 'risk'];
$writeActions = ['close'];
if (!in_array($action, array_merge($readActions, $writeActions), true)) {
    $fail(400, 'Unknown action');
}
if (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        $fail(405, 'Use POST for changes.');
    }
    $token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!validateCsrf($token)) {
        $fail(403, 'Security token expired. Please refresh the page.');
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    $rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
    $check = $rl->consume($method === 'POST' ? 'att_warn_write' : 'att_warn_read', 'user:' . $adminId, $method === 'POST' ? 60 : 240, 60);
    if (!$check['allowed']) {
        $fail(429, 'Too many requests. Please wait a moment and try again.');
    }
}

$available = AttendanceWarningService::available($conn);
$canClose = [];
foreach (AttendanceRiskService::DEPARTMENTS as $d) {
    $canClose[$d] = $available && AttendanceWarningService::roleMayClose($role, $d);
}
$meta = [
    'available' => $available,
    'departments' => $myDepartments,
    'can_close' => $canClose,
    'outcomes' => AttendanceWarningService::OUTCOME_LABELS,
];

/** Department filter: requested ∩ allowed. */
$scopeDepartments = static function (?string $requested) use ($myDepartments, $fail): array {
    $requested = trim((string)$requested);
    if ($requested === '' || $requested === 'all') {
        return $myDepartments;
    }
    if (!in_array($requested, AttendanceRiskService::DEPARTMENTS, true)) {
        $fail(400, 'department must be edu or mezmur.');
    }
    if (!in_array($requested, $myDepartments, true)) {
        $fail(403, 'You cannot view ' . AttendanceRiskService::LABELS[$requested] . ' warnings.');
    }
    return [$requested];
};

switch ($action) {
    case 'list': {
        if (!$available) {
            $ok($meta + ['items' => [], 'total' => 0]);
        }
        $depts = $scopeDepartments($_GET['department'] ?? null);
        $res = AttendanceWarningService::list($conn, [
            'departments' => $depts,
            'status' => (string)($_GET['status'] ?? 'open'),
            'kind' => (string)($_GET['kind'] ?? ''),
            'stage' => (string)($_GET['stage'] ?? ''),
            'member_id' => (int)($_GET['member_id'] ?? 0),
            'source' => (string)($_GET['source'] ?? ''),
            'q' => (string)($_GET['q'] ?? ''),
            'limit' => (int)($_GET['limit'] ?? 100),
            'offset' => (int)($_GET['offset'] ?? 0),
        ]);
        $ok($meta + $res);
    }
    case 'detail': {
        if (!$available) {
            $ok($meta + ['warning' => null]);
        }
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            $fail(400, 'id is required.');
        }
        $row = AttendanceWarningService::detail($conn, $id);
        if (!$row) {
            $fail(404, 'Warning not found.');
        }
        if (!in_array($row['department'], $myDepartments, true)) {
            $fail(403, 'You cannot view ' . $row['department_label'] . ' warnings.');
        }
        $payload = ['warning' => $row];
        // Cross-department summary for HR / Information / admins only.
        if (count($myDepartments) === 2) {
            $payload['member_summary'] = AttendanceWarningService::memberSummary($conn, (int)$row['member_id']);
        }
        $ok($meta + $payload);
    }
    case 'counts': {
        $ok($meta + ['counts' => AttendanceWarningService::openCounts($conn, $myDepartments)]);
    }
    case 'member_summary': {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId <= 0) {
            $fail(400, 'member_id is required.');
        }
        $ok($meta + ['summary' => AttendanceWarningService::memberSummary($conn, $memberId, $myDepartments)]);
    }
    case 'risk': {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId <= 0) {
            $fail(400, 'member_id is required.');
        }
        $depts = $scopeDepartments($_GET['department'] ?? null);
        $out = [];
        foreach ($depts as $d) {
            try {
                $out[$d] = AttendanceWarningService::decorate($conn, AttendanceRiskService::riskFor($conn, $d, $memberId));
            } catch (\Throwable $e) {
                error_log('Attendance risk failed: ' . $e->getMessage());
                $out[$d] = null;
            }
        }
        $ok($meta + ['member_id' => $memberId, 'risk' => $out]);
    }
    case 'close': {
        if (!$available) {
            $ok($meta + ['closed' => false, 'message' => 'Attendance warnings are not available yet (feature disabled or sql/072 not applied).']);
        }
        $id = (int)($input['id'] ?? 0);
        $outcome = is_string($input['outcome'] ?? null) ? trim($input['outcome']) : '';
        $note = is_string($input['note'] ?? null) ? trim($input['note']) : '';
        if ($id <= 0) {
            $fail(400, 'id is required.');
        }
        $existing = AttendanceWarningService::find($conn, $id);
        if (!$existing) {
            $fail(404, 'Warning not found.');
        }
        if (!AttendanceWarningService::roleMayClose($role, (string)$existing['department'])) {
            // §8: School Admin / Super Admin are read-only; other departments never cross.
            $fail(403, 'Only the owning department, HR or Information may close this warning.');
        }
        try {
            $actor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
                $adminId,
                (string)($_SESSION['admin_username'] ?? ''),
                'admin',
                $_SERVER
            );
            $closed = AttendanceWarningService::close($conn, $id, $outcome, $note, $adminId, $role, $actor);
        } catch (\DomainException $e) {
            $fail(422, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Attendance warning close failed: ' . $e->getMessage());
            $fail(500, 'Unable to close the warning right now.');
        }
        $ok($meta + ['closed' => true, 'warning' => $closed, 'message' => 'Warning closed (' . $closed['outcome_label'] . ').']);
    }
}
$fail(400, 'Unknown action');
