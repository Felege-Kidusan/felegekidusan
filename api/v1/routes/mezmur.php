<?php
/**
 * School API v1 — Mezmur (መዝሙር ክፍል)
 * DATE-based, section-grouped attendance for the Mezmur department.
 *
 *   GET  /mezmur/days                — list attendance days (paginated)
 *   POST /mezmur/days                — get-or-create a day w/ program label
 *   GET  /mezmur/sheet?date=…        — roster sheet grouped by section
 *   POST /mezmur/sheet               — complete-sheet save (validated)
 *   GET  /mezmur/analytics           — member aggregates (mezmur_dept+)
 *
 * All domain logic lives in MezmurAttendanceService (single writer,
 * same one the web dashboard uses). Security lives in core/acl.php:
 * every route re-checks the bearer's role; PII shaping is server-side.
 */

$auth = apiRequireAuth();

// Viewing & taking: mezmur staff, admins, and attendance takers.
// Analytics & day labelling (decision data): mezmur staff + admins only.
$MEZMUR_ROLES = ['mezmur_dept', 'school_admin', 'super_admin', 'attendance_taker'];
$MEZMUR_ANALYTICS_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];

if (!apiRoleIs($auth, $MEZMUR_ROLES)) {
    err('You cannot access the Mezmur module.', 403);
}

require_once dirname(__DIR__, 2) . '/admin/backend/services/MezmurAttendanceService.php';

use App\Services\MezmurAttendanceService;

$action = $ROUTE['id'] ?? '';
$method = $ROUTE['method'] ?? 'GET';

try {

    // ── GET /mezmur/days ────────────────────────────────────────
    if ($method === 'GET' && $action === 'days') {
        $out = MezmurAttendanceService::listDays(
            $conn,
            (string)($_GET['from'] ?? ''),
            (string)($_GET['to'] ?? ''),
            (int)($_GET['page'] ?? 1),
            (int)($_GET['per_page'] ?? 25)
        );
        ok($out);
    }

    // ── POST /mezmur/days ───────────────────────────────────────
    if ($method === 'POST' && $action === 'days') {
        if (!apiRoleIs($auth, $MEZMUR_ANALYTICS_ROLES)) {
            err('Only Mezmur staff and admins can manage attendance days.', 403);
        }
        $input = getBody();
        if (isApiRateLimited('mezmur_day_create', 30)) {
            err('Too many requests. Please wait a moment.', 429);
        }
        $day = MezmurAttendanceService::ensureDay(
            $conn,
            (string)($input['date'] ?? ''),
            (string)($input['program_type'] ?? 'rehearsal'),
            isset($input['title']) ? (string)$input['title'] : null,
            isset($input['notes']) ? (string)$input['notes'] : null,
            (int)$auth['uid']
        );
        ok(['day' => $day], 200);
    }

    // ── GET /mezmur/sheet?date=… ────────────────────────────────
    if ($method === 'GET' && $action === 'sheet') {
        $date = (string)($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) err('A valid date is required.');
        $out = MezmurAttendanceService::fetchSheet($conn, $date, (int)$auth['uid']);
        // PII discipline: roster rows carry name/code/section/photo only.
        foreach ($out['sections'] as $sec => $members) {
            foreach ($members as &$m) {
                unset($m['full_name_am']);
            }
            unset($m);
        }
        ok($out);
    }

    // ── POST /mezmur/sheet ──────────────────────────────────────
    if ($method === 'POST' && $action === 'sheet') {
        $input = getBody();
        $date = (string)($input['date'] ?? '');
        $records = $input['records'] ?? [];
        if (!is_array($records) || $records === []) err('records array is required.');
        if (count($records) > 500000) err('Sheet is too large.');

        apiIdempotencyBegin((int)$auth['uid'], (string)($input['client_op_id'] ?? ''));
        if (isApiRateLimited('mezmur_sheet_save', 30)) {
            err('Too many sheet saves. Please wait a moment.', 429);
        }

        $summary = MezmurAttendanceService::saveSheet($conn, $date, $records, (int)$auth['uid']);
        ok(['saved' => true, 'summary' => $summary]);
    }

    // ── GET /mezmur/analytics ───────────────────────────────────
    if ($method === 'GET' && $action === 'analytics') {
        if (!apiRoleIs($auth, $MEZMUR_ANALYTICS_ROLES)) {
            err('Analytics are available to Mezmur staff and admins only.', 403);
        }
        $out = MezmurAttendanceService::analyticsMembers($conn, $_GET);
        // Strip everything beyond decision fields.
        $out['items'] = array_map(static function (array $r): array {
            unset($r['full_name_am'], $r['photo_url']);
            return $r;
        }, $out['items']);
        ok($out);
    }

} catch (\DomainException $e) {
    // DomainException messages are controlled strings thrown by
    // MezmurAttendanceService (same pattern as class attendance).
    $safeMessage = $e->getMessage();
    err($safeMessage, 422);
}

err('Unknown mezmur endpoint.', 404);
