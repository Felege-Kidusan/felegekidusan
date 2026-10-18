<?php
/**
 * ════════════════════════════════════════════════════════════
 * HR Attendance — web console endpoint. FULLY RETIRED (410).
 * ════════════════════════════════════════════════════════════
 *
 *   2026-10-07  HR stopped taking attendance (submission_review → 410,
 *               history stayed readable here).
 *   2026-10-18  The read-only history surface was removed as well:
 *               every action (sections, submissions_list,
 *               submission_detail, days_list, takers_list,
 *               submission_review, …) now answers 410
 *               HR_ATTENDANCE_RETIRED. HR analyses the Education +
 *               Mezmur attendance through the shared Attendance
 *               Analytics Hub (api_info_analytics.php) and the
 *               per-member view (api_hr_reports.php) instead.
 *
 * The hr_* tables remain in the database as an untouched archive;
 * nothing reads them. No migration is required.
 *
 * Guard layers kept on purpose: session + role re-check (an anonymous
 * caller gets 401/403, never a hint about the retired domain), CSRF on
 * POST, per-user rate limiting and a safe exception handler.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';

if (!defined('HR_ATTENDANCE_API_VERSION')) define('HR_ATTENDANCE_API_VERSION', 'hr-retired-2');

function hr_att_respond(array $payload): void
{
    $payload['v'] = HR_ATTENDANCE_API_VERSION;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    $token = bin2hex(random_bytes(3));
    error_log('[hr-attendance-unhandled #' . $token . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    hr_att_respond([
        'status' => 'error',
        'message' => 'Unexpected server fault (log reference ' . $token . '). Please retry.',
    ]);
});

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    hr_att_respond(['status' => 'error', 'message' => 'Please sign in again.']);
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ['super_admin', 'school_admin', 'hr_dept'], true)) {
    http_response_code(403);
    hr_att_respond(['status' => 'error', 'message' => 'You do not have permission to view HR attendance.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        hr_att_respond(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
    }
}

$rl = new \App\Services\SecurityRateLimiter($pdo ?? null, sys_get_temp_dir() . '/ssms_ratelimit');
$rlCheck = $rl->consume('hr_attendance_retired', 'user:' . $adminId, 120, 60);
if (!$rlCheck['allowed']) {
    http_response_code(429);
    hr_att_respond(['status' => 'error', 'message' => 'Too many requests. Please wait a moment and try again.']);
}

// Every action — reads included — is retired.
http_response_code(410);
hr_att_respond([
    'status' => 'error',
    'code' => 'HR_ATTENDANCE_RETIRED',
    'message' => 'HR attendance was retired. HR analyses the Education and Mezmur attendance in the Attendance Analytics Hub instead.',
]);
