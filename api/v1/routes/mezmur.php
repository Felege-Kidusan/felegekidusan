<?php
/**
 * School API v1 — Mezmur (መዝሙር ክፍል)
 * Session-based attendance for the Mezmur department (section-grouped).
 *
 *   GET  /mezmur/sessions              — list sessions (paginated)
 *   POST /mezmur/sessions              — create session (mezmur_dept+)
 *   GET  /mezmur/sheet/{sessionId}     — roster sheet grouped by section
 *   POST /mezmur/sheet/{sessionId}     — complete-sheet save (validated)
 *   GET  /mezmur/analytics             — member aggregates (mezmur_dept+)
 *
 * All domain logic lives in MezmurAttendanceService (single writer,
 * same one the web dashboard uses). Security lives in core/acl.php:
 * every route re-checks the bearer's role; PII shaping is server-side.
 */

$auth = apiRequireAuth();

// Viewing & taking: mezmur staff, admins, and attendance takers.
// Analytics (decision data): mezmur staff and admins only.
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

    // ── GET /mezmur/sessions ────────────────────────────────────
    if ($method === 'GET' && $action === 'sessions') {
        $out = MezmurAttendanceService::listSessions(
            $conn,
            (string)($_GET['from'] ?? ''),
            (string)($_GET['to'] ?? ''),
            (int)($_GET['page'] ?? 1),
            (int)($_GET['per_page'] ?? 25)
        );
        ok($out);
    }

    // ── POST /mezmur/sessions ───────────────────────────────────
    if ($method === 'POST' && $action === 'sessions') {
        if (!apiRoleIs($auth, $MEZMUR_ANALYTICS_ROLES)) {
            err('Only Mezmur staff and admins can create sessions.', 403);
        }
        $input = getBody();
        if (isApiRateLimited('mezmur_session_create', 30)) {
            err('Too many session creates. Please wait a moment.', 429);
        }
        $id = MezmurAttendanceService::createSession(
            $conn,
            (string)($input['session_date'] ?? ''),
            (string)($input['program_type'] ?? ''),
            (string)($input['title'] ?? ''),
            isset($input['notes']) ? (string)$input['notes'] : null,
            (int)$auth['uid']
        );
        ok(['id' => $id], 201);
    }

    // ── GET /mezmur/sheet/{id} ──────────────────────────────────
    if ($method === 'GET' && $action === 'sheet') {
        $sessionId = (int)($ROUTE['parts'][2] ?? 0);
        if ($sessionId <= 0) err('Session id is required.');
        $out = MezmurAttendanceService::fetchSheet($conn, $sessionId);
        if ($out['session'] === null) err('Session not found.', 404);
        // PII discipline: roster rows carry name/code/section/photo only.
        foreach ($out['sections'] as $sec => $members) {
            foreach ($members as &$m) {
                unset($m['full_name_am']);
            }
            unset($m);
        }
        ok($out);
    }

    // ── POST /mezmur/sheet/{id} ─────────────────────────────────
    if ($method === 'POST' && $action === 'sheet') {
        $sessionId = (int)($ROUTE['parts'][2] ?? 0);
        if ($sessionId <= 0) err('Session id is required.');
        $input = getBody();
        $records = $input['records'] ?? [];
        if (!is_array($records) || $records === []) err('records array is required.');
        if (count($records) > 500000) err('Sheet is too large.');

        apiIdempotencyBegin((int)$auth['uid'], (string)($input['client_op_id'] ?? ''));
        if (isApiRateLimited('mezmur_sheet_save', 30)) {
            err('Too many sheet saves. Please wait a moment.', 429);
        }

        $summary = MezmurAttendanceService::saveSheet($conn, $sessionId, $records, (int)$auth['uid']);
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
