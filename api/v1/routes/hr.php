<?php
/**
 * School API v1 — HR department attendance. FULLY RETIRED.
 *
 *   ANY  /hr/*   → 410 HR_ATTENDANCE_RETIRED
 *
 * Product decisions:
 *   2026-10-07  HR no longer takes attendance (writes → 410).
 *   2026-10-18  HR attendance history removed everywhere: the read
 *               endpoints (sections / days / sheet / submissions /
 *               submission) are retired too. HR now analyses the
 *               Education + Mezmur attendance through the shared
 *               Attendance Analytics Hub on the web dashboard.
 *
 * The hr_* tables stay in the database untouched (silent archive —
 * no migration, no data destroyed); nothing reads them any more.
 *
 * Old app versions that still drain an outbox packet via POST /hr/sheet
 * keep receiving the exact same 410 + machine code the outbox classifier
 * expects ("needs attention", never silent loss). Auth is still required
 * so the retired surface leaks nothing to anonymous callers.
 */

$auth = apiRequireAuth();

if (!defined('HR_API_VERSION')) define('HR_API_VERSION', 'hr-retired-2');

// Historical role set kept for the handshake only — every role gets 410.
$HR_ROLES = ['hr_attendance_taker', 'hr_dept', 'school_admin', 'super_admin'];

$action = $ROUTE['id'] ?? '';
$method = $ROUTE['method'] ?? 'GET';

if ($method === 'POST' && $action === 'sheet') {
    err('HR attendance was retired. HR no longer takes attendance — update the app to see the new attendance reports.', 410,
        ['code' => 'HR_ATTENDANCE_RETIRED', 'v' => HR_API_VERSION]);
}

if ($method === 'POST' && $action === 'submission-review') {
    err('Reviewing HR attendance was retired with HR attendance itself.', 410,
        ['code' => 'HR_ATTENDANCE_RETIRED', 'v' => HR_API_VERSION]);
}

// Reads (sections, days, sheet, submissions, submission) and anything else.
err('HR attendance was retired. HR analyses the Education and Mezmur attendance in the web Analytics Hub instead.', 410,
    ['code' => 'HR_ATTENDANCE_RETIRED', 'v' => HR_API_VERSION]);
