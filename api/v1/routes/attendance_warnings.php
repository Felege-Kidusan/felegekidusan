<?php
/**
 * School API v1 — Attendance warnings (Attendance alerting — Phase 2)
 * Mounted from routes/attendance.php when the first segment is `warnings`.
 *
 *   GET  /attendance/warnings?department=&status=&kind=&stage=&member_id=&q=&limit=&offset=
 *   GET  /attendance/warnings/counts
 *   GET  /attendance/warnings/{id}
 *   POST /attendance/warnings/{id}/close   {outcome, note?}
 *
 * Roles: edu_dept (Education), mezmur_dept (Mezmur), hr_dept / info_dept
 * (both) may read and close; school_admin / super_admin read only (§8);
 * teachers / attendance takers: 403 (their sheets get a read-only indicator
 * through the sheet endpoints, no warning PII).
 * Dates in the payload are Ethiopian-calendar labels (service-presented);
 * raw Gregorian keys are not exposed by this route.
 */

require_once dirname(__DIR__, 3) . '/admin/backend/services/AttendanceWarningService.php';
require_once dirname(__DIR__, 3) . '/admin/backend/services/AttendanceRiskService.php';
if (!class_exists('\\App\\Services\\AttendanceWarningService')) {
    err('Attendance warnings are temporarily unavailable.', 503);
}
require_once dirname(__DIR__, 3) . '/admin/backend/services/SecurityAuditService.php';

$awRole = apiRoleOf($auth);
if (!in_array($awRole, \App\Services\AttendanceWarningService::READ_ROLES, true)) {
    err('You cannot view attendance warnings.', 403);
}
$awDepartments = \App\Services\AttendanceWarningService::departmentsForRole($awRole);
$awAvailable = \App\Services\AttendanceWarningService::available($conn);
$awSub = $ROUTE['sub'] ?? null;                 // null | 'counts' | {id}
$awTail = $ROUTE['parts'][3] ?? null;           // 'close' | null
$awCanClose = [];
foreach (\App\Services\AttendanceRiskService::DEPARTMENTS as $d) {
    $awCanClose[$d] = $awAvailable && \App\Services\AttendanceWarningService::roleMayClose($awRole, $d);
}
$awMeta = [
    'available' => $awAvailable,
    'departments' => $awDepartments,
    'can_close' => $awCanClose,
    'outcomes' => \App\Services\AttendanceWarningService::OUTCOME_LABELS,
];

if (isApiRateLimited('attendance_warnings', $method === 'POST' ? 30 : 120)) {
    err('Too many requests. Please wait a moment.', 429);
}

// GET /attendance/warnings
if ($method === 'GET' && ($awSub === null || $awSub === '')) {
    if (!$awAvailable) {
        ok($awMeta + ['items' => [], 'total' => 0]);
    }
    $req = trim((string)($_GET['department'] ?? ''));
    $depts = $awDepartments;
    if ($req !== '' && $req !== 'all') {
        if (!in_array($req, \App\Services\AttendanceRiskService::DEPARTMENTS, true)) err('department must be edu or mezmur.');
        if (!in_array($req, $awDepartments, true)) err('You cannot view those warnings.', 403);
        $depts = [$req];
    }
    $res = \App\Services\AttendanceWarningService::list($conn, [
        'departments' => $depts,
        'status' => (string)($_GET['status'] ?? 'open'),
        'kind' => (string)($_GET['kind'] ?? ''),
        'stage' => (string)($_GET['stage'] ?? ''),
        'member_id' => (int)($_GET['member_id'] ?? 0),
        'source' => (string)($_GET['source'] ?? ''),
        'q' => (string)($_GET['q'] ?? ''),
        'limit' => (int)($_GET['limit'] ?? 50),
        'offset' => (int)($_GET['offset'] ?? 0),
    ]);
    ok($awMeta + $res);
}

// GET /attendance/warnings/counts
if ($method === 'GET' && $awSub === 'counts') {
    ok($awMeta + ['counts' => \App\Services\AttendanceWarningService::openCounts($conn, $awDepartments)]);
}

$awId = is_string($awSub) && ctype_digit($awSub) ? (int)$awSub : (is_int($awSub) ? $awSub : 0);
if ($awId <= 0) {
    err('Unknown attendance warnings endpoint.', 404);
}
if (!$awAvailable) {
    err('Attendance warnings are not available yet.', 409, ['available' => false]);
}

// GET /attendance/warnings/{id}
if ($method === 'GET' && $awTail === null) {
    $row = \App\Services\AttendanceWarningService::detail($conn, $awId);
    if (!$row) err('Warning not found.', 404);
    if (!in_array($row['department'], $awDepartments, true)) err('You cannot view those warnings.', 403);
    $payload = $awMeta + ['warning' => $row];
    if (count($awDepartments) === 2) {
        $payload['member_summary'] = \App\Services\AttendanceWarningService::memberSummary($conn, (int)$row['member_id']);
    }
    ok($payload);
}

// POST /attendance/warnings/{id}/close
if ($method === 'POST' && $awTail === 'close') {
    $existing = \App\Services\AttendanceWarningService::find($conn, $awId);
    if (!$existing) err('Warning not found.', 404);
    if (!\App\Services\AttendanceWarningService::roleMayClose($awRole, (string)$existing['department'])) {
        err('Only the owning department, HR or Information may close this warning.', 403);
    }
    $input = getBody();
    $outcome = is_string($input['outcome'] ?? null) ? trim($input['outcome']) : '';
    $note = is_string($input['note'] ?? null) ? trim($input['note']) : '';
    try {
        $actor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
            (int)$auth['uid'],
            (string)($auth['usr'] ?? ''),
            'api',
            $_SERVER
        );
        $closed = \App\Services\AttendanceWarningService::close($conn, $awId, $outcome, $note, (int)$auth['uid'], $awRole, $actor);
    } catch (\DomainException $e) {
        $__awMessage = $e->getMessage();
        err($__awMessage, 422);
    } catch (\Throwable $e) {
        reportInternalError('Attendance warning close failed', $e->getMessage());
        err('Unable to close the warning right now.', 500);
    }
    logApiAction($auth['uid'], $auth['usr'], 'Attendance Warning Closed', "Warning: {$awId}, Outcome: {$closed['outcome']}");
    ok($awMeta + ['closed' => true, 'warning' => $closed, 'message' => 'Warning closed (' . $closed['outcome_label'] . ').']);
}

err('Unknown attendance warnings endpoint.', 404);
