<?php
/**
 * ════════════════════════════════════════════════════════════
 * Department-owned attendance takers API
 * ════════════════════════════════════════════════════════════
 * Each department creates and manages ONLY its own taker type:
 *   mezmur_dept → mezmur_attendance_taker
 *   hr_dept     → hr_attendance_taker
 * Admins manage both. Edu's existing attendance_taker setup is
 * untouched (managed by super admin via user-save.php).
 *
 * Defense in depth:
 *   1. access_control.php ROLE_MAP limits this file to the four
 *      roles above before it executes.
 *   2. This file re-checks login + role itself.
 *   3. CSRF token required on every POST.
 *   4. Per-user rate limiting (writes tighter than reads).
 *   5. DeptTakerService re-validates the creator→role attribution
 *      server-side — the UI is never trusted.
 *   6. Every create/toggle is audit-logged; errors never leak
 *      internals.
 *
 * Actions:
 *   GET  list    → the caller's own takers
 *   POST create  → create one of the caller's taker type
 *   POST toggle  → activate/deactivate one of the caller's takers
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/DeptTakerService.php';

use App\Services\DeptTakerService;

if (!defined('DEPT_TAKER_API_VERSION')) define('DEPT_TAKER_API_VERSION', 'phase6-takers26');

/** Uniform JSON exit — never leak internals. */
function dept_taker_respond(array $payload): void
{
    $payload['v'] = DEPT_TAKER_API_VERSION;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    $token = bin2hex(random_bytes(3));
    error_log('[dept-takers-unhandled #' . $token . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    dept_taker_respond([
        'status' => 'error',
        'message' => 'Unexpected server fault (log reference ' . $token . '). Please retry.',
    ]);
});

// ── 1. Auth (re-checked here even though ROLE_MAP already ran) ─
if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    dept_taker_respond(['status' => 'error', 'message' => 'Please sign in again.']);
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ['super_admin', 'school_admin', 'mezmur_dept', 'hr_dept'], true)) {
    http_response_code(403);
    dept_taker_respond(['status' => 'error', 'message' => 'You do not have permission to manage attendance takers.']);
}

$action = (string)($_REQUEST['action'] ?? '');
if (!in_array($action, ['list', 'create', 'toggle'], true)) {
    dept_taker_respond(['status' => 'error', 'message' => 'Unknown action.']);
}

// ── 2. CSRF on state-changing requests ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        dept_taker_respond(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
    }
} elseif ($action !== 'list') {
    dept_taker_respond(['status' => 'error', 'message' => 'Use POST for this action.']);
}

// ── 3. Rate limiting (per user) ──────────────────────────────
$rl = new \App\Services\SecurityRateLimiter($pdo ?? null, sys_get_temp_dir() . '/ssms_ratelimit');
$rlKey = $action === 'list' ? 'dept_takers_read' : 'dept_takers_write';
$rlLimit = $action === 'list' ? 240 : 20; // per minute
$rlCheck = $rl->consume($rlKey, 'user:' . $adminId, $rlLimit, 60);
if (!$rlCheck['allowed']) {
    dept_taker_respond(['status' => 'error', 'message' => 'Too many requests. Please wait a moment and try again.']);
}

$auth = ['role' => $role, 'user_id' => $adminId];

switch ($action) {
    case 'list': {
        dept_taker_respond([
            'status' => 'success',
            'items' => DeptTakerService::listTakers($conn, $auth),
        ]);
    }

    case 'create': {
        $requestedRole = trim((string)($_POST['role'] ?? ''));
        $fullName = (string)($_POST['full_name'] ?? '');
        $username = (string)($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $result = DeptTakerService::create($conn, $auth, $requestedRole, $fullName, $username, $password);
        dept_taker_respond([
            'status' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'id' => $result['id'] ?? null,
        ]);
    }

    case 'toggle': {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            dept_taker_respond(['status' => 'error', 'message' => 'Invalid account.']);
        }
        $result = DeptTakerService::toggle($conn, $auth, $userId);
        dept_taker_respond([
            'status' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'is_active' => $result['is_active'] ?? null,
        ]);
    }
}
