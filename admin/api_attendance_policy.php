<?php
/**
 * ============================================================
 * Attendance policy API (Attendance alerting — Phase 1)
 * ============================================================
 *   GET  ?action=get                         → both department policies
 *   POST action=save {department, consecutive_threshold, prolonged_threshold,
 *                     critical_threshold, auto_deactivate, low_rate_threshold,
 *                     min_sessions}          → school_admin ONLY
 *
 * Reads: School Admin, Super Admin (read-only), HR, Information, Education,
 * Mezmur (so dashboards can show the thresholds in force).
 * Validation: 1 ≤ standard < prolonged < critical ≤ 60, 0 < low-rate ≤ 100,
 * 1 ≤ min sessions ≤ 30. Every change is audited with old/new values.
 * Until FEATURE_ATTENDANCE_V2 is on and sql/071 is applied, `get` answers
 * the defaults with available:false and `save` is refused.
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/AttendancePolicyService.php';

use App\Services\AttendancePolicyService;

$fail = static function (int $code, string $message, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
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

const AP_READ_ROLES = ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'];
const AP_WRITE_ROLES = ['school_admin'];
if (!in_array($role, AP_READ_ROLES, true)) {
    $fail(403, 'You do not have permission to use this page.');
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$input = $_POST;
if ($method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($json)) {
        $input = $json + $_POST;
        $action = (string)($json['action'] ?? $action);
    }
}

if (!in_array($action, ['get', 'save'], true)) {
    $fail(400, 'Unknown action');
}
if ($action === 'save') {
    if ($method !== 'POST') {
        $fail(405, 'Use POST for changes.');
    }
    if (!in_array($role, AP_WRITE_ROLES, true)) {
        $fail(403, 'Only the School Admin may change attendance policy.');
    }
    $token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!validateCsrf($token)) {
        $fail(403, 'Security token expired. Please refresh the page.');
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    $rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
    $check = $rl->consume($method === 'POST' ? 'att_policy_write' : 'att_policy_read', 'user:' . $adminId, $method === 'POST' ? 30 : 240, 60);
    if (!$check['allowed']) {
        $fail(429, 'Too many requests. Please wait a moment and try again.');
    }
}

$available = AttendancePolicyService::available($conn);
$bounds = [
    'max_consecutive' => AttendancePolicyService::MAX_CONSECUTIVE,
    'max_min_sessions' => AttendancePolicyService::MAX_MIN_SESSIONS,
];

if ($action === 'get') {
    $ok([
        'available' => $available,
        'can_edit' => $available && in_array($role, AP_WRITE_ROLES, true),
        'bounds' => $bounds,
        'policies' => AttendancePolicyService::all($conn),
    ]);
}

// save
if (!$available) {
    $fail(409, 'Attendance policy is not available yet (feature disabled or sql/071 not applied).', ['available' => false]);
}
$dept = is_string($input['department'] ?? null) ? trim($input['department']) : '';
if (!in_array($dept, AttendancePolicyService::DEPARTMENTS, true)) {
    $fail(400, 'department must be edu or mezmur.');
}
$clean = [];
$errors = AttendancePolicyService::validate($input, $clean);
if ($errors !== []) {
    $fail(400, implode(' ', $errors), ['errors' => $errors]);
}
try {
    $result = AttendancePolicyService::save($conn, $dept, $clean, $adminId);
} catch (\Throwable $e) {
    error_log('Attendance policy save failed: ' . $e->getMessage());
    $fail(500, 'Unable to save the attendance policy right now.');
}
$ok([
    'available' => true,
    'changed' => $result['changed'],
    'message' => $result['changed'] ? 'Attendance policy saved.' : 'No changes.',
    'policy' => $result['policy'],
    'policies' => AttendancePolicyService::all($conn),
]);
