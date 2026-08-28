<?php
/**
 * ════════════════════════════════════════════════════════════
 * HR Department Attendance API (web console)
 * ════════════════════════════════════════════════════════════
 * Review-only console surface for HR's OWN section-based
 * attendance domain. Recording happens on the mobile app
 * (api/v1); this endpoint lists, inspects and reviews packets.
 *
 * Isolation rule (2026-08-28): HR data is never combined with
 * Education or Mezmur. This file only touches hr_* tables via
 * HrAttendanceService / HrSubmissionService.
 *
 * Defense in depth:
 *   1. access_control.php ROLE_MAP limits this file to
 *      super_admin / school_admin / hr_dept.
 *   2. This file re-checks login + role itself.
 *   3. CSRF token required on every POST.
 *   4. Per-user rate limiting (review writes tight).
 *   5. Exceptions never leak internals.
 *
 * Actions:
 *   GET  sections          → sections with member counts
 *   GET  submissions_list  → packets (+stats insight strip)
 *   GET  submission_detail → packet + member rows
 *   GET  days_list         → recorded-day history
 *   GET  takers_list       → HR's own taker accounts
 *   POST submission_review → approve / reject / return-with-note
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/HrAttendanceService.php';
require_once __DIR__ . '/backend/services/HrSubmissionService.php';

use App\Services\HrAttendanceService;
use App\Services\HrSubmissionService;

if (!defined('HR_ATTENDANCE_API_VERSION')) define('HR_ATTENDANCE_API_VERSION', 'phase6-hr26');

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

// ── 1. Auth ───────────────────────────────────────────────────
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

$action = (string)($_REQUEST['action'] ?? '');

// ── 2. Schema probe (clear message instead of a raw 1054) ────
try {
    $probe = $conn->query("SELECT 1 FROM hr_submissions LIMIT 0");
    if ($probe === false) {
        throw new \RuntimeException('hr_submissions missing');
    }
    $probe->close();
} catch (\Throwable $e) {
    hr_att_respond(['status' => 'error', 'message' => 'HR attendance tables not found. Ask the administrator to run sql/026_hr_attendance.sql.']);
}

// ── 3. CSRF on POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        hr_att_respond(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
    }
} elseif ($action === 'submission_review') {
    hr_att_respond(['status' => 'error', 'message' => 'Use POST for this action.']);
}

// ── 4. Rate limiting (per user) ──────────────────────────────
$rl = new \App\Services\SecurityRateLimiter($pdo ?? null, sys_get_temp_dir() . '/ssms_ratelimit');
$rlKey = $action === 'submission_review' ? 'hr_attendance_write' : 'hr_attendance_read';
$rlLimit = $action === 'submission_review' ? 30 : 240; // per minute
$rlCheck = $rl->consume($rlKey, 'user:' . $adminId, $rlLimit, 60);
if (!$rlCheck['allowed']) {
    hr_att_respond(['status' => 'error', 'message' => 'Too many requests. Please wait a moment and try again.']);
}

try {
    switch ($action) {
        case 'sections': {
            hr_att_respond(['status' => 'success', 'items' => HrAttendanceService::sectionListWithCounts($conn)]);
        }

        case 'submissions_list': {
            $out = HrSubmissionService::listPackets($conn, [
                'status' => (string)($_GET['status'] ?? ''),
                'from' => (string)($_GET['from'] ?? ''),
                'to' => (string)($_GET['to'] ?? ''),
                'section' => (string)($_GET['section'] ?? ''),
                'page' => $_GET['page'] ?? 1,
                'per_page' => $_GET['per_page'] ?? 50,
            ]);
            $out['stats'] = HrSubmissionService::packetStats($conn);
            hr_att_respond(['status' => 'success'] + $out);
        }

        case 'submission_detail': {
            $item = HrSubmissionService::detail($conn, (int)($_GET['id'] ?? 0));
            if ($item === null) {
                hr_att_respond(['status' => 'error', 'message' => 'Submission not found.']);
            }
            hr_att_respond(['status' => 'success', 'item' => $item]);
        }

        case 'days_list': {
            $out = HrAttendanceService::listDays(
                $conn,
                (string)($_GET['from'] ?? ''),
                (string)($_GET['to'] ?? ''),
                (int)($_GET['page'] ?? 1),
                (int)($_GET['per_page'] ?? 25)
            );
            hr_att_respond(['status' => 'success'] + $out);
        }

        case 'takers_list': {
            hr_att_respond(['status' => 'success', 'items' => HrAttendanceService::takersList($conn)]);
        }

        case 'submission_review': {
            if (!HrSubmissionService::canReview(['role' => $role])) {
                hr_att_respond(['status' => 'error', 'message' => 'You do not have permission to review HR submissions.']);
            }
            $result = HrSubmissionService::reviewPacket(
                $conn,
                (int)($_POST['submission_id'] ?? 0),
                (string)($_POST['new_status'] ?? ''),
                (string)($_POST['notes'] ?? ''),
                $adminId
            );
            if (!$result['ok']) {
                hr_att_respond(['status' => 'error', 'message' => $result['message']]);
            }
            hr_att_respond(['status' => 'success', 'message' => $result['message']]);
        }

        default:
            hr_att_respond(['status' => 'error', 'message' => 'Unknown action.']);
    }
} catch (\DomainException $e) {
    hr_att_respond(['status' => 'error', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[hr-attendance] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    hr_att_respond(['status' => 'error', 'message' => 'Unable to complete the request. Please try again.']);
}
