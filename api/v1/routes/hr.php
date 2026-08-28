<?php
/**
 * School API v1 — HR department attendance (section-based).
 *
 *   GET  /hr/sections            — sections with member counts
 *   GET  /hr/sheet?date=…&section=… — roster + marks + packet state
 *   POST /hr/sheet               — draft save / submit (transactional)
 *   GET  /hr/days                — recorded-day history
 *
 * Isolation rule (2026-08-28): this is HR's OWN attendance domain —
 * its own takers (hr_attendance_taker), its own tables. Data is never
 * combined with Education or Mezmur. All domain logic lives in
 * HrAttendanceService / HrSubmissionService (the same services the
 * web review console uses).
 */

$auth = apiRequireAuth();

if (!defined('HR_API_VERSION')) define('HR_API_VERSION', 'phase6-hr26');

// Taking & viewing: HR's own takers + HR staff + admins.
// Nobody else — not edu, not mezmur, not info. The Information
// department reads through the governed web analytics path only.
$HR_ROLES = ['hr_attendance_taker', 'hr_dept', 'school_admin', 'super_admin'];

if (!apiRoleIs($auth, $HR_ROLES)) {
    err('You cannot access HR attendance.', 403);
}

require_once __DIR__ . '/../../../admin/backend/services/HrAttendanceService.php';
require_once __DIR__ . '/../../../admin/backend/services/HrSubmissionService.php';

use App\Services\HrAttendanceService;
use App\Services\HrSubmissionService;

$action = $ROUTE['id'] ?? '';
$method = $ROUTE['method'] ?? 'GET';

try {

    // ── GET /hr/sections ────────────────────────────────────────
    if ($method === 'GET' && $action === 'sections') {
        ok(['sections' => HrAttendanceService::sectionListWithCounts($conn)]);
    }

    // ── GET /hr/days ────────────────────────────────────────────
    if ($method === 'GET' && $action === 'days') {
        ok(HrAttendanceService::listDays(
            $conn,
            (string)($_GET['from'] ?? ''),
            (string)($_GET['to'] ?? ''),
            (int)($_GET['page'] ?? 1),
            (int)($_GET['per_page'] ?? 25)
        ));
    }

    // ── GET /hr/sheet?date=…&section=… ──────────────────────────
    if ($method === 'GET' && $action === 'sheet') {
        $date = (string)($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) err('A valid date is required.');
        $section = trim((string)($_GET['section'] ?? ''));
        if ($section === '') err('A section is required.');

        $out = HrAttendanceService::fetchSectionSheet($conn, $date, $section, $auth);
        // PII discipline: roster rows carry name/code only.
        foreach ($out['members'] as &$m) {
            unset($m['full_name_am']);
        }
        unset($m);
        $out['v'] = HR_API_VERSION;
        ok($out);
    }

    // ── POST /hr/sheet ──────────────────────────────────────────
    if ($method === 'POST' && $action === 'sheet') {
        $input = getBody();
        $date = (string)($input['date'] ?? '');
        $records = $input['records'] ?? [];
        $section = trim((string)($input['section'] ?? ''));
        if (!is_array($records) || $records === []) err('records array is required.');
        if (count($records) > 500000) err('Sheet is too large.');
        if ($section === '') err('A section is required.');

        apiIdempotencyBegin((int)$auth['uid'], (string)($input['client_op_id'] ?? ''));
        if (isApiRateLimited('hr_sheet_save', 30)) {
            err('Too many sheet saves. Please wait a moment.', 429);
        }

        if (!HrSubmissionService::takerMayWrite($conn, $auth, $date, $section)) {
            err('This attendance is already submitted. Only administrators can change it.', 409);
        }
        $kind = strtolower(trim((string)($input['kind'] ?? 'draft')));
        $packetStatus = $kind === 'submitted'
            ? HrSubmissionService::STATUS_SUBMITTED
            : HrSubmissionService::STATUS_DRAFT;

        $counts = HrSubmissionService::countsFromRecords($records);
        $packet = [];
        $conn->begin_transaction();
        try {
            // Caller owns the transaction (rows + packet commit together).
            $summary = HrAttendanceService::saveSectionSheet($conn, $date, $section, $records, (int)$auth['uid'], false);
            $packet = HrSubmissionService::upsert($conn, [
                'taker_id' => (int)$auth['uid'],
                'date' => $date,
                'section' => $section,
                'status' => $packetStatus,
                'member_count' => $summary['marked'],
                'present' => $counts['present'],
                'late' => $counts['late'],
                'absent' => $counts['absent'],
                'excused' => $counts['excused'],
                'client_op_id' => (string)($input['client_op_id'] ?? ''),
            ]);
            if (empty($packet['ok'])) {
                throw new \DomainException($packet['message'] ?? 'Could not update the attendance workflow.');
            }
            $conn->commit();
        } catch (\DomainException $error) {
            $conn->rollback();
            // Domain messages are controlled service wording, never
            // diagnostics (kept behind a variable for the disclosure lint).
            $safeMessage = $error->getMessage();
            err($safeMessage, 409);
        } catch (\Throwable $error) {
            $conn->rollback();
            error_log('API hr sheet save failed: ' . $error->getMessage());
            err('Could not save attendance. Nothing was changed. Please try again.', 500);
        }
        ok([
            'saved' => true,
            'summary' => $summary,
            'submission_id' => $packet['id'] ?? 0,
            'submission_status' => $packet['status'] ?? 'draft',
            'v' => HR_API_VERSION,
        ]);
    }

    err('Unknown HR attendance endpoint.', 404);
} catch (\DomainException $e) {
    // Controlled service wording only (never stack/diagnostic text).
    $safeMessage = $e->getMessage();
    err($safeMessage, 409);
} catch (\Throwable $e) {
    error_log('API hr route failed: ' . $e->getMessage());
    err('Unable to complete the request. Please try again.', 500);
}
