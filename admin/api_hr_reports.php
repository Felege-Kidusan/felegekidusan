<?php
/**
 * ════════════════════════════════════════════════════════════
 * HR Attendance Reports API (web dashboard) — READ-ONLY
 * ════════════════════════════════════════════════════════════
 * HR no longer takes attendance (retired 2026-10-07); the
 * department READS combined attendance reports from the Education
 * and Mezmur departments instead. This endpoint is the governed
 * read path behind the HR dashboard's Attendance Reports section.
 *
 * Sources (never HR's own retired hr_* tables):
 *   • Education — `attendance` (class day sheets, mobile + web)
 *   • Mezmur    — `mezmur_attendance` (section day sheets, mobile)
 *
 * Defense in depth:
 *   1. access_control.php ROLE_MAP limits this file to
 *      super_admin / school_admin / hr_dept.
 *   2. This file re-checks login + role itself.
 *   3. Per-user rate limiting (reads only — no writes exist).
 *   4. Exceptions never leak internals; a missing source table
 *      degrades to zeros/empty rows (mezmur resilience pattern).
 *   5. Prepared statements only; PII discipline: names, codes and
 *      sections only — no contact or household columns.
 *
 * Actions:
 *   GET monthly_summary ?month=YYYY-MM
 *   GET member_search   ?q=…            (name or member code, ≥2 chars)
 *   GET member_detail   ?member_id=N&month=YYYY-MM
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';

if (!defined('HR_REPORTS_API_VERSION')) define('HR_REPORTS_API_VERSION', 'hr-reports-1');

function hr_reports_respond(array $payload): void
{
    $payload['v'] = HR_REPORTS_API_VERSION;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    $token = bin2hex(random_bytes(3));
    error_log('[hr-reports-unhandled #' . $token . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    hr_reports_respond([
        'status' => 'error',
        'message' => 'Unexpected server fault (log reference ' . $token . '). Please retry.',
    ]);
});

// ── 1. Auth (re-checked here even though ROLE_MAP already ran) ─
if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    hr_reports_respond(['status' => 'error', 'message' => 'Please sign in again.']);
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ['super_admin', 'school_admin', 'hr_dept'], true)) {
    http_response_code(403);
    hr_reports_respond(['status' => 'error', 'message' => 'You do not have permission to view attendance reports.']);
}

$action = (string)($_REQUEST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    hr_reports_respond(['status' => 'error', 'message' => 'This endpoint is read-only.']);
}
if (!in_array($action, ['monthly_summary', 'member_search', 'member_detail'], true)) {
    hr_reports_respond(['status' => 'error', 'message' => 'Unknown action.']);
}

// ── 2. Rate limiting (per user, reads) ───────────────────────
$rl = new \App\Services\SecurityRateLimiter($pdo ?? null, sys_get_temp_dir() . '/ssms_ratelimit');
$rlCheck = $rl->consume('hr_reports_read', 'user:' . $adminId, 240, 60);
if (!$rlCheck['allowed']) {
    hr_reports_respond(['status' => 'error', 'message' => 'Too many requests. Please wait a moment and try again.']);
}

/** Validate a YYYY-MM month, defaulting to the current month. */
function hr_reports_month(string $month): string
{
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) return $month;
    return date('Y-m');
}

/** Empty per-source summary (missing-table / no-data shape). */
function hr_reports_empty_summary(): array
{
    return ['days' => 0, 'marked' => 0, 'present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
}

/** Aggregate one source table for one month; degrades to zeros. */
function hr_reports_summary(\mysqli $conn, string $table, string $month): array
{
    $like = $month . '-%';
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(DISTINCT attendance_date) AS days,
                    COUNT(*) AS marked,
                    SUM(status = 'present') AS present,
                    SUM(status = 'absent')  AS absent,
                    SUM(status = 'late')    AS late,
                    SUM(status = 'excused') AS excused
             FROM `$table`
             WHERE attendance_date LIKE ?"
        );
        if ($stmt === false) {
            return hr_reports_empty_summary();
        }
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return hr_reports_empty_summary();
        }
        return [
            'days'    => (int)$row['days'],
            'marked'  => (int)$row['marked'],
            'present' => (int)$row['present'],
            'absent'  => (int)$row['absent'],
            'late'    => (int)$row['late'],
            'excused' => (int)$row['excused'],
        ];
    } catch (\Throwable $e) {
        // Missing migration / legacy DB: report zeros, never a 500.
        return hr_reports_empty_summary();
    }
}

switch ($action) {
    case 'monthly_summary': {
        $month = hr_reports_month((string)($_GET['month'] ?? ''));
        hr_reports_respond([
            'status' => 'success',
            'month' => $month,
            'education' => hr_reports_summary($conn, 'attendance', $month),
            'mezmur' => hr_reports_summary($conn, 'mezmur_attendance', $month),
        ]);
    }

    case 'member_search': {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            hr_reports_respond(['status' => 'error', 'message' => 'Type at least 2 characters to search.']);
        }
        $like = '%' . $q . '%';
        try {
            $stmt = $conn->prepare(
                'SELECT id, student_name, father_name, member_code, current_section
                 FROM members
                 WHERE (student_name LIKE ? OR member_code LIKE ?)
                 ORDER BY student_name
                 LIMIT 20'
            );
            $stmt->bind_param('ss', $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            $items = [];
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $items[] = $row;
            }
            $stmt->close();
            hr_reports_respond(['status' => 'success', 'items' => $items]);
        } catch (\Throwable $e) {
            hr_reports_respond(['status' => 'error', 'message' => 'Could not search members. Please try again.']);
        }
    }

    case 'member_detail': {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId <= 0) {
            hr_reports_respond(['status' => 'error', 'message' => 'A member is required.']);
        }
        $month = hr_reports_month((string)($_GET['month'] ?? ''));
        $like = $month . '-%';

        try {
            $stmt = $conn->prepare(
                'SELECT id, student_name, father_name, member_code, current_section
                 FROM members WHERE id = ? LIMIT 1'
            );
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            $member = null;
        }
        if (!$member) {
            hr_reports_respond(['status' => 'error', 'message' => 'Member not found.']);
        }
        $member['id'] = (int)$member['id'];

        // Education rows: date + status + class label.
        $education = [];
        try {
            $stmt = $conn->prepare(
                'SELECT a.attendance_date, a.status, c.class_name
                 FROM attendance a
                 LEFT JOIN classes c ON c.id = a.class_id
                 WHERE a.member_id = ? AND a.attendance_date LIKE ?
                 ORDER BY a.attendance_date'
            );
            $stmt->bind_param('is', $memberId, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $education[] = [
                    'date' => (string)$row['attendance_date'],
                    'status' => (string)$row['status'],
                    'class_name' => (string)($row['class_name'] ?? '—'),
                ];
            }
            $stmt->close();
        } catch (\Throwable $e) {
            $education = [];
        }

        // Mezmur rows: date + status (section is the member's current
        // section — historical per-day section is not stored per row).
        $mezmur = [];
        try {
            $stmt = $conn->prepare(
                'SELECT attendance_date, status
                 FROM mezmur_attendance
                 WHERE member_id = ? AND attendance_date LIKE ?
                 ORDER BY attendance_date'
            );
            $stmt->bind_param('is', $memberId, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $mezmur[] = [
                    'date' => (string)$row['attendance_date'],
                    'status' => (string)$row['status'],
                ];
            }
            $stmt->close();
        } catch (\Throwable $e) {
            $mezmur = [];
        }

        hr_reports_respond([
            'status' => 'success',
            'month' => $month,
            'member' => $member,
            'education' => $education,
            'mezmur' => $mezmur,
        ]);
    }
}
