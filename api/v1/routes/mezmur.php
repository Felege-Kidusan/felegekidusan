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
// Version handshake: every /mezmur/* response carries this marker so
// clients can distinguish a current server from a stale deployment.
if (!defined('MEZMUR_API_VERSION')) define('MEZMUR_API_VERSION', 'phase5-audit25');

$MEZMUR_ROLES = ['mezmur_dept', 'school_admin', 'super_admin', 'attendance_taker'];
$MEZMUR_ANALYTICS_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];

if (!apiRoleIs($auth, $MEZMUR_ROLES)) {
    err('You cannot access the Mezmur module.', 403);
}

require_once __DIR__ . '/../../../admin/backend/services/MezmurAttendanceService.php';
require_once __DIR__ . '/../../../admin/backend/services/MezmurSubmissionService.php';
require_once __DIR__ . '/../../../admin/backend/services/MezmurHymnService.php';

use App\Services\MezmurAttendanceService;
use App\Services\MezmurSubmissionService;
use App\Services\MezmurHymnService;

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

    // ── GET /mezmur/sections ────────────────────────────────────
    if ($method === 'GET' && $action === 'sections') {
        ok(['sections' => MezmurAttendanceService::sectionListWithCounts($conn)]);
    }

    // ── GET /mezmur/sheet?date=…&section=… ──────────────────────
    if ($method === 'GET' && $action === 'sheet') {
        $date = (string)($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) err('A valid date is required.');
        $section = trim((string)($_GET['section'] ?? ''));

        // Section-scoped sheet (teacher-clone): roster of that section
        // + marks + packet status + the department's review note.
        if ($section !== '') {
            $out = MezmurAttendanceService::fetchSectionSheet($conn, $date, $section, $auth);
            // PII discipline: roster rows carry name/code/photo only.
            foreach ($out['members'] as &$m) {
                unset($m['full_name_am']);
            }
            unset($m);
            ok($out);
        }

        // Legacy full-roster sheet (older clients).
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
        $section = trim((string)($input['section'] ?? ''));
        if (!is_array($records) || $records === []) err('records array is required.');
        if (count($records) > 500000) err('Sheet is too large.');

        apiIdempotencyBegin((int)$auth['uid'], (string)($input['client_op_id'] ?? ''));
        if (isApiRateLimited('mezmur_sheet_save', 30)) {
            err('Too many sheet saves. Please wait a moment.', 429);
        }

        // Section-scoped save + submission packet (teacher-clone).
        if ($section !== '') {
            if (!MezmurSubmissionService::takerMayWrite($conn, $auth, $date, $section)) {
                err('This attendance is already submitted. Only administrators can change it.', 409);
            }
            $kind = strtolower(trim((string)($input['kind'] ?? 'draft')));
            $packetStatus = $kind === 'submitted'
                ? MezmurSubmissionService::STATUS_SUBMITTED
                : MezmurSubmissionService::STATUS_DRAFT;

            $counts = MezmurSubmissionService::countsFromRecords($records);
            $packet = [];
            $conn->begin_transaction();
            try {
                // Caller owns the transaction (rows + packet commit together).
                $summary = MezmurAttendanceService::saveSectionSheet($conn, $date, $section, $records, (int)$auth['uid'], false);
                $packet = MezmurSubmissionService::upsert($conn, [
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
                $safeMessage = $error->getMessage();
                err($safeMessage, 409);
            } catch (\Throwable $error) {
                $conn->rollback();
                error_log('API mezmur sheet save failed: ' . $error->getMessage());
                err('Could not save attendance. Nothing was changed. Please try again.', 500);
            }
            ok([
                'saved' => true,
                'summary' => $summary,
                'submission_id' => $packet['id'] ?? 0,
                'submission_status' => $packet['status'] ?? 'draft',
            ]);
        }

        // Legacy date-only save (older clients).
        $summary = MezmurAttendanceService::saveSheet($conn, $date, $records, (int)$auth['uid']);
        ok(['saved' => true, 'summary' => $summary]);
    }

    // ── GET /mezmur/analytics[/sections] ────────────────────────
    if ($method === 'GET' && $action === 'analytics') {
        if (!apiRoleIs($auth, $MEZMUR_ANALYTICS_ROLES)) {
            err('Analytics are available to Mezmur staff and admins only.', 403);
        }
        if (($ROUTE['parts'][2] ?? '') === 'sections') {
            ok(MezmurAttendanceService::analyticsSections($conn, $_GET));
        }
        $out = MezmurAttendanceService::analyticsMembers($conn, $_GET);
        // Strip everything beyond decision fields.
        $out['items'] = array_map(static function (array $r): array {
            unset($r['full_name_am'], $r['photo_url']);
            return $r;
        }, $out['items']);
        ok($out);
    }

    // ── GET /mezmur/hymns ─────────────────────────────────────
    if ($method === 'GET' && $action === 'hymns') {
        $out = MezmurHymnService::listHymns($conn, $_GET);
        ok($out);
    }

    // ── GET /mezmur/hymn?id=… ─────────────────────────────────
    if ($method === 'GET' && $action === 'hymn') {
        $item = MezmurHymnService::getHymn($conn, (int)($_GET['id'] ?? 0));
        if ($item === null) err('Hymn not found.', 404);
        ok(['item' => $item]);
    }

} catch (\DomainException $e) {
    // DomainException messages are controlled strings thrown by
    // MezmurAttendanceService (same pattern as class attendance).
    $safeMessage = $e->getMessage();
    err($safeMessage, 422);
}

err('Unknown mezmur endpoint.', 404);
