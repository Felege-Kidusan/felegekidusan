<?php
/**
 * ════════════════════════════════════════════════════════════
 * Mezmur Department API (መዝሙር ክፍል) — Hymn Library v1
 * ════════════════════════════════════════════════════════════
 * Single server-authoritative controller for the mezmur module.
 * The front end (frontend/js/mezmur.js) is never trusted:
 *
 *   Defense in depth:
 *     1. admin/access_control.php ROLE_MAP already refuses any
 *        role other than super_admin / school_admin / mezmur_dept
 *        before this file even executes (central guard).
 *     2. This file re-checks login + role itself.
 *     3. FeatureGate: the whole module fails closed when
 *        FEATURE_MEZMUR !== true.
 *     4. CSRF token required on every state-changing POST.
 *     5. Every query is a prepared statement; search terms are
 *        escaped for LIKE; pagination is clamped.
 *     6. Exceptions never leak internals to the client
 *        (server-side error_log only).
 *
 * Actions:
 *   GET  stats      → counts + distinct category list
 *   GET  list       → paginated hymns (search/category/status)
 *   GET  get        → single hymn
 *   POST save       → create or update a hymn
 *   POST set_status → archive / restore (soft delete)
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

use App\Services\FeatureGate;

// Defense in depth: own the exception handler so an uncaught throwable
// can never again be masked by the host's generic error page. The real
// message goes to the error log with a short reference token; the client
// gets a 200 JSON error it can render.
set_exception_handler(static function (\Throwable $e): void {
    $token = bin2hex(random_bytes(3));
    error_log('[mezmur-unhandled #' . $token . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
    }
    echo json_encode([
        'status' => 'error',
        'message' => 'Unexpected server fault (log reference ' . $token . '). Please retry; if it persists, ask the administrator to check the error log.',
    ]);
});

/**
 * Server/code version marker, present in EVERY mezmur response.
 * Clients compare this against what they were built for and show an
 * actionable "server needs updating" message instead of a scary generic
 * error when the deployment is stale (missing migrations / old code).
 * Bump when the mezmur API contract changes.
 */
if (!defined('MEZMUR_API_VERSION')) define('MEZMUR_API_VERSION', 'phase5-offline26');
define('MEZMUR_SCHEMA_MIN', 24); // highest migration the mezmur module relies on

function mezmur_respond(array $payload, int $code = 200): void
{
    $payload['server_meta'] = ['mezmur' => MEZMUR_API_VERSION, 'schema' => MEZMUR_SCHEMA_MIN];
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 1. Session gate ───────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    mezmur_respond(['status' => 'session_expired', 'message' => 'Please log in to continue.', 'action' => 'reload']);
}

// ── 2. Role gate (defense in depth; central guard ran first) ──
$mezmurRole = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($mezmurRole, ['super_admin', 'school_admin', 'mezmur_dept'], true)) {
    mezmur_respond(['status' => 'error', 'message' => 'You do not have permission to use the Mezmur module.']);
}

// ── 3. Feature gate (fail-closed) ─────────────────────────────
if (!FeatureGate::isEnabled('mezmur')) {
    mezmur_respond(['status' => 'error', 'message' => 'The Mezmur module is not enabled for this deployment.']);
}

// ── 4. CSRF for all state-changing requests ───────────────────
requireCsrfForPost();

$action  = $_REQUEST['action'] ?? '';
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// State-changing actions must arrive via POST (CSRF-protected above).
if (in_array($action, ['save', 'set_status', 'save_sheet', 'day_create', 'submission_review', 'migrate'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    mezmur_respond(['status' => 'error', 'message' => 'Use POST for this action.']);
}

require_once __DIR__ . '/backend/services/MezmurAttendanceService.php';
require_once __DIR__ . '/backend/services/MezmurSubmissionService.php';
require_once __DIR__ . '/backend/services/MezmurSchemaReconciler.php';
// NOTE: the rate limiter class is NOT loaded by the admin bootstrap
// (only by api/v1 middleware) — without this require every request
// fatals with "class not found" BEFORE the try/catch (the incident
// the host masked as the generic ref-JSON).
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';

use App\Services\MezmurAttendanceService;
use App\Services\MezmurSubmissionService;

// ── 5. Rate limiting (per user; DB-backed with file fallback) ─
$__rl = new \App\Services\SecurityRateLimiter(
    $pdo ?? null,
    sys_get_temp_dir() . '/ssms_ratelimit'
);
$__rlAction = in_array($action, ['save', 'set_status', 'save_sheet', 'day_create', 'submission_review', 'migrate'], true)
    ? 'mezmur_write' : 'mezmur_read';
$__rlLimit  = $__rlAction === 'mezmur_write' ? 30 : 240;   // per minute
$__rlCheck  = $__rl->consume($__rlAction, 'user:' . $adminId, $__rlLimit, 60);
if (!$__rlCheck['allowed']) {
    mezmur_respond(['status' => 'error', 'message' => 'Too many requests. Please wait a moment and try again.']);
}

// ── 6. Schema probes (clear message instead of a raw 1054) ────
try {
    $probe = $conn->query("SELECT 1 FROM mezmur_hymns LIMIT 0");
    if ($probe === false) { throw new \RuntimeException('mezmur_hymns missing'); }
    $probe->close();
} catch (\Throwable $e) {
    mezmur_respond(['status' => 'error', 'message' => 'Mezmur tables not found. Ask the administrator to run sql/021_mezmur_department.sql.']);
}
$__attendanceActions = ['days_list', 'day_create', 'sheet', 'save_sheet',
    'analytics_members', 'analytics_sections', 'analytics_trends', 'takers_list',
    'sections', 'overview', 'submissions_list', 'submission_detail', 'submission_review'];
if (in_array($action, $__attendanceActions, true)) {
    try {
        $probe = $conn->query("SELECT 1 FROM mezmur_days LIMIT 0");
        if ($probe === false) { throw new \RuntimeException('mezmur_days missing'); }
        $probe->close();
    } catch (\Throwable $e) {
        mezmur_respond(['status' => 'error', 'message' => 'Mezmur attendance tables not found. Ask the administrator to run sql/022_mezmur_attendance.sql and sql/023_mezmur_date_attendance.sql.']);
    }
}
$__packetActions = ['submissions_list', 'submission_detail', 'submission_review', 'overview'];
if (in_array($action, $__packetActions, true)) {
    try {
        $probe = $conn->query("SELECT 1 FROM mezmur_submissions LIMIT 0");
        if ($probe === false) { throw new \RuntimeException('mezmur_submissions missing'); }
        $probe->close();
    } catch (\Throwable $e) {
        mezmur_respond(['status' => 'error', 'message' => 'Mezmur submission tables not found. Ask the administrator to run sql/024_mezmur_submissions.sql.']);
    }
}

try {
    switch ($action) {

        // ── PING (deployment health check) ─────────────────────
        // Lets the administrator verify, in one request, that the
        // code version and every mezmur migration are live:
        //   https://…/backend/api/mezmur.php?action=ping
        case 'ping': {
            $tables = [
                'mezmur_hymns' => 'sql/021_mezmur_department.sql',
                'mezmur_days' => 'sql/022_mezmur_attendance.sql',
                'mezmur_attendance' => 'sql/023_mezmur_date_attendance.sql',
                'mezmur_attendance_audit' => 'sql/023_mezmur_date_attendance.sql',
                'mezmur_submissions' => 'sql/024_mezmur_submissions.sql',
            ];
            $missing = [];
            foreach ($tables as $tbl => $migration) {
                try {
                    $r = $conn->query("SELECT 1 FROM `{$tbl}` LIMIT 0");
                    if ($r === false) { $missing[$tbl] = $migration; continue; }
                    $r->close();
                } catch (\Throwable $e) {
                    $missing[$tbl] = $migration;
                }
            }
            // nullable session_id check (024 block #4)
            $sessionIdOk = null;
            try {
                $r = $conn->query("SHOW COLUMNS FROM mezmur_attendance LIKE 'session_id'");
                if ($r) {
                    $col = $r->fetch_assoc();
                    $sessionIdOk = $col ? ($col['Null'] === 'YES') : true;
                    $r->close();
                }
            } catch (\Throwable $e) { $sessionIdOk = null; }
            mezmur_respond([
                'status' => empty($missing) ? 'success' : 'error',
                'code_version' => MEZMUR_API_VERSION,
                'php' => PHP_VERSION,
                'missing_tables' => $missing,
                'session_id_nullable' => $sessionIdOk,
                'message' => empty($missing)
                    ? 'Mezmur deployment is current — all tables present.'
                    : 'Run these migrations on the server: ' . implode(', ', array_values($missing)),
            ]);
        }

        // ── SCHEMA (drift report) / MIGRATE (guarded apply) ────
        // The schema-drift killer: production broke repeatedly because
        // legacy tables predate the repo and migrations lag the cron
        // code pull. report() shows the drift; apply() closes it with
        // idempotent guarded DDL. migrate is POST + CSRF + role-gated.
        case 'schema': {
            mezmur_respond([
                'status' => 'success',
                'drift' => \App\Services\MezmurSchemaReconciler::report($conn),
            ]);
        }

        case 'migrate': {
            $result = \App\Services\MezmurSchemaReconciler::apply($conn);
            \App\Services\SecurityAuditService::record(
                $conn, 'Mezmur Schema Reconciled',
                ['applied' => $result['applied'], 'failed' => $result['failed']],
                'mezmur_schema', null
            );
            mezmur_respond([
                'status' => 'success',
                'message' => empty($result['failed'])
                    ? (empty($result['applied']) ? 'Schema already current — nothing to do.' : 'Schema reconciled: ' . count($result['applied']) . ' change(s).')
                    : 'Schema reconciled with issues — see failed.',
                'applied' => $result['applied'],
                'failed' => $result['failed'],
                'drift_now' => \App\Services\MezmurSchemaReconciler::report($conn),
            ]);
        }

        // ── STATS ──────────────────────────────────────────────
        case 'stats': {
            $row = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(status='active'),0) active, COUNT(DISTINCT category) categories FROM mezmur_hymns")->fetch_assoc();
            $cats = [];
            $rc = $conn->query("SELECT DISTINCT category FROM mezmur_hymns WHERE category <> '' ORDER BY category LIMIT 100");
            while ($c = $rc->fetch_assoc()) $cats[] = $c['category'];
            mezmur_respond([
                'status' => 'success',
                'total' => (int)$row['total'],
                'active' => (int)$row['active'],
                'categories' => (int)$row['categories'],
                'members' => (int)($conn->query("SELECT COUNT(*) c FROM members WHERE status = 'active'")->fetch_assoc()['c'] ?? 0),
                'category_list' => $cats,
                'section_list' => MezmurAttendanceService::sectionList($conn),
                'program_types' => MezmurAttendanceService::PROGRAM_TYPES,
            ]);
        }

        // ── LIST (server-side pagination + filters) ────────────
        case 'list': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = (int)($_GET['per_page'] ?? 25);
            if ($perPage < 1 || $perPage > 100) $perPage = 25;   // clamp
            $search   = trim((string)($_GET['search'] ?? ''));
            $category = trim((string)($_GET['category'] ?? ''));
            $status   = (string)($_GET['status'] ?? '');

            $where  = [];
            $types  = '';
            $params = [];

            if ($status === 'active' || $status === 'archived') {
                $where[] = 'status = ?';
                $types .= 's';
                $params[] = $status;
            } elseif ($status === '') {
                $where[] = "status = 'active'";   // default view: active only
            }
            if ($category !== '') {
                $where[] = 'category = ?';
                $types .= 's';
                $params[] = mb_substr($category, 0, 50);
            }
            // ── Telegram-grade search: FULLTEXT boolean mode with prefix
            // wildcards (as-you-type feel), title-weighted ranking and a
            // lyrics snippet; LIKE fallback when the index is absent or
            // the term is shorter than the InnoDB token minimum.
            $searchMode = 'none';
            $boolQ = '';
            $like = '';
            $tokens = [];
            $ftIndex = null; // 'full' when the combined FULLTEXT exists
            if ($search !== '') {
                $raw = mb_substr($search, 0, 100);
                $clean = trim((string)preg_replace('/[+\-><()~*"@]+/u', ' ', $raw));
                $tokens = array_values(array_filter(
                    preg_split('/\s+/u', $clean),
                    static fn ($t) => $t !== ''
                ));
                $tokens = array_slice($tokens, 0, 6);
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $raw) . '%';
                try {
                    $ix = $conn->query("SHOW INDEX FROM mezmur_hymns WHERE Key_name = 'ft_mezmur_hymns_search'");
                    $ftIndex = ($ix && $ix->fetch_assoc()) ? 'full' : null;
                    if ($ix) { $ix->close(); }
                } catch (\Throwable $e) { $ftIndex = null; }
                $minLen = min(array_map(static fn ($t) => mb_strlen($t), $tokens) ?: [0]);
                if ($ftIndex === 'full' && $tokens && $minLen >= 3) {
                    $searchMode = 'fulltext';
                    $boolQ = implode(' ', array_map(static fn ($t) => $t . '*', $tokens));
                } else {
                    $searchMode = 'like';
                    $where[] = '(title LIKE ? ESCAPE \'\\\\\' OR title_am LIKE ? ESCAPE \'\\\\\' OR reference LIKE ? ESCAPE \'\\\\\' OR lyrics LIKE ? ESCAPE \'\\\\\')';
                    $types .= 'ssss';
                    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
                }
            }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            if ($searchMode === 'fulltext') {
                $ftWhere = "MATCH(title, title_am, reference, lyrics) AGAINST(? IN BOOLEAN MODE)"
                    . ($whereSql !== '' ? ' AND ' . substr($whereSql, 5) : '');
                $ftTypes = 's' . $types;
                $ftParams = array_merge([$boolQ], $params);
                $scoreExpr = "(3.0 * MATCH(title, title_am) AGAINST(? IN BOOLEAN MODE)
                               + MATCH(title, title_am, reference, lyrics) AGAINST(? IN BOOLEAN MODE))";
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns WHERE $ftWhere");
                $stmt->bind_param($ftTypes, ...$ftParams);
                $stmt->execute();
                $total = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                $perPage = min($perPage, 50); // ranked search pages stay tight
            } else {
                // Total count for pagination
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns $whereSql");
                if ($params) $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $total = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
            }

            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            if ($searchMode === 'fulltext') {
                $sql = "SELECT id, title, title_am, category, reference, status, updated_at, lyrics,
                        $scoreExpr AS score
                        FROM mezmur_hymns
                        WHERE $ftWhere
                        ORDER BY score DESC, updated_at DESC, id DESC
                        LIMIT ? OFFSET ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss' . $ftTypes . 'ii', ...array_merge([$boolQ, $boolQ], $ftParams, [$perPage, $offset]));
            } else {
                $sql = "SELECT id, title, title_am, category, reference, status, updated_at, lyrics,
                        0 AS score
                        FROM mezmur_hymns $whereSql
                        ORDER BY updated_at DESC, id DESC
                        LIMIT ? OFFSET ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $items = [];
            while ($r = $res->fetch_assoc()) {
                // Lyrics never travel in list payloads; a tight context
                // snippet around the first matched token instead.
                $snippet = '';
                if ($search !== '' && !empty($r['lyrics'])) {
                    foreach ($tokens as $t) {
                        $pos = mb_stripos((string)$r['lyrics'], $t);
                        if ($pos !== false) {
                            $start = max(0, $pos - 60);
                            $snippet = ($start > 0 ? '…' : '')
                                . trim(mb_substr((string)$r['lyrics'], $start, 160)) . '…';
                            break;
                        }
                    }
                }
                unset($r['lyrics']);
                $r['snippet'] = $snippet;
                $r['score'] = round((float)($r['score'] ?? 0), 2);
                $items[] = $r;
            }
            $stmt->close();

            mezmur_respond([
                'status' => 'success',
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'total_pages' => $totalPages,
                'search_mode' => $searchMode,
            ]);
        }

        // ── GET ONE ────────────────────────────────────────────
        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) mezmur_respond(['status' => 'error', 'message' => 'Invalid hymn id.']);
            $stmt = $conn->prepare("SELECT id, title, title_am, category, reference, lyrics, status, created_at, updated_at FROM mezmur_hymns WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$item) mezmur_respond(['status' => 'error', 'message' => 'Hymn not found.']);
            mezmur_respond(['status' => 'success', 'item' => $item]);
        }

        // ── SAVE (create / update) ─────────────────────────────
        case 'save': {
            $id        = (int)($_POST['id'] ?? 0);
            $title     = trim((string)($_POST['title'] ?? ''));
            $titleAm   = trim((string)($_POST['title_am'] ?? ''));
            $category  = trim((string)($_POST['category'] ?? ''));
            $reference = trim((string)($_POST['reference'] ?? ''));
            $lyrics    = (string)($_POST['lyrics'] ?? '');

            if ($title === '') mezmur_respond(['status' => 'error', 'message' => 'Title is required.']);
            if (mb_strlen($title) > 255 || mb_strlen($titleAm) > 255 || mb_strlen($reference) > 255) {
                mezmur_respond(['status' => 'error', 'message' => 'A field exceeds its maximum length.']);
            }
            if (mb_strlen($category) > 50) $category = mb_substr($category, 0, 50);
            if ($category === '') $category = 'general';
            // Lyrics cap — generous but bounded (protects storage at scale).
            if (mb_strlen($lyrics) > 200000) {
                mezmur_respond(['status' => 'error', 'message' => 'Lyrics text is too long.']);
            }
            $titleAm   = $titleAm === '' ? null : $titleAm;
            $reference = $reference === '' ? null : $reference;
            $lyrics    = trim($lyrics) === '' ? null : $lyrics;

            if ($id > 0) {
                // Duplicate-title guard (excluding self) — case-insensitive.
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns WHERE LOWER(title) = LOWER(?) AND id <> ?");
                $stmt->bind_param('si', $title, $id);
                $stmt->execute();
                $dup = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                if ($dup > 0) mezmur_respond(['status' => 'error', 'message' => 'A hymn with this title already exists.']);

                $stmt = $conn->prepare("UPDATE mezmur_hymns SET title=?, title_am=?, category=?, reference=?, lyrics=?, updated_by=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param('sssssii', $title, $titleAm, $category, $reference, $lyrics, $adminId, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) mezmur_respond(['status' => 'error', 'message' => 'Unable to update the hymn.']);
                \App\Services\SecurityAuditService::record(
                    $conn, 'Mezmur Hymn Updated',
                    ['id' => $id, 'title' => mb_substr($title, 0, 255), 'category' => $category],
                    'mezmur_hymn', $id
                );
                mezmur_respond(['status' => 'success', 'message' => 'Hymn updated.', 'id' => $id]);
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns WHERE LOWER(title) = LOWER(?)");
                $stmt->bind_param('s', $title);
                $stmt->execute();
                $dup = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
                if ($dup > 0) mezmur_respond(['status' => 'error', 'message' => 'A hymn with this title already exists.']);

                $status = 'active';
                $stmt = $conn->prepare("INSERT INTO mezmur_hymns (title, title_am, category, reference, lyrics, status, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssssii', $title, $titleAm, $category, $reference, $lyrics, $status, $adminId, $adminId);
                $ok = $stmt->execute();
                $newId = $ok ? (int)$stmt->insert_id : 0;
                $stmt->close();
                if (!$ok) mezmur_respond(['status' => 'error', 'message' => 'Unable to save the hymn.']);
                \App\Services\SecurityAuditService::record(
                    $conn, 'Mezmur Hymn Created',
                    ['id' => $newId, 'title' => mb_substr($title, 0, 255), 'category' => $category],
                    'mezmur_hymn', $newId
                );
                mezmur_respond(['status' => 'success', 'message' => 'Hymn added to the library.', 'id' => $newId]);
            }
        }

        // ── ARCHIVE / RESTORE (soft delete) ────────────────────
        case 'set_status': {
            $id     = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if ($id <= 0) mezmur_respond(['status' => 'error', 'message' => 'Invalid hymn id.']);
            if (!in_array($status, ['active', 'archived'], true)) {
                mezmur_respond(['status' => 'error', 'message' => 'Invalid status.']);
            }
            $stmt = $conn->prepare("UPDATE mezmur_hymns SET status=?, updated_by=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param('sii', $status, $adminId, $id);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if (!$ok || $affected === 0) mezmur_respond(['status' => 'error', 'message' => 'Hymn not found or already in that state.']);
            \App\Services\SecurityAuditService::record(
                $conn, $status === 'archived' ? 'Mezmur Hymn Archived' : 'Mezmur Hymn Restored',
                ['id' => $id, 'new_status' => $status],
                'mezmur_hymn', $id
            );
            mezmur_respond([
                'status' => 'success',
                'message' => $status === 'archived' ? 'Hymn archived.' : 'Hymn restored.',
            ]);
        }

        // ── DAYS (date-based attendance) ──────────────────────
        case 'days_list': {
            $out = MezmurAttendanceService::listDays(
                $conn,
                (string)($_GET['from'] ?? ''),
                (string)($_GET['to'] ?? ''),
                (int)($_GET['page'] ?? 1),
                (int)($_GET['per_page'] ?? 25)
            );
            mezmur_respond(['status' => 'success'] + $out);
        }

        // ── SHEET ──────────────────────────────────────────────
        case 'day_create': {
            $day = MezmurAttendanceService::ensureDay(
                $conn,
                (string)($_POST['date'] ?? ''),
                (string)($_POST['program_type'] ?? 'rehearsal'),
                isset($_POST['title']) ? (string)$_POST['title'] : null,
                isset($_POST['notes']) ? (string)$_POST['notes'] : null,
                $adminId
            );
            mezmur_respond(['status' => 'success', 'message' => 'Attendance day ready.', 'day' => $day]);
        }

        case 'sheet': {
            $date = (string)($_GET['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                mezmur_respond(['status' => 'error', 'message' => 'A valid date is required.']);
            }
            $section = trim((string)($_GET['section'] ?? ''));
            if ($section !== '') {
                // Section-scoped sheet with packet status + review note.
                $out = MezmurAttendanceService::fetchSectionSheet($conn, $date, $section, ['role' => $mezmurRole]);
                mezmur_respond(['status' => 'success'] + $out);
            }
            $out = MezmurAttendanceService::fetchSheet($conn, $date, $adminId);
            mezmur_respond(['status' => 'success'] + $out);
        }

        case 'save_sheet': {
            $date = (string)($_POST['date'] ?? '');
            $records = $_POST['records'] ?? '';
            if (is_string($records)) {
                $decoded = json_decode($records, true);
                $records = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($records) || $records === []) {
                mezmur_respond(['status' => 'error', 'message' => 'The sheet is empty.']);
            }
            if (count($records) > 500000) {
                mezmur_respond(['status' => 'error', 'message' => 'The sheet is too large.']);
            }

            $section = trim((string)($_POST['section'] ?? ''));
            if ($section !== '') {
                $kind = strtolower(trim((string)($_POST['kind'] ?? 'draft')));
                $packetStatus = $kind === 'submitted'
                    ? MezmurSubmissionService::STATUS_SUBMITTED
                    : MezmurSubmissionService::STATUS_DRAFT;
                $counts = MezmurSubmissionService::countsFromRecords($records);
                $packet = [];
                $conn->begin_transaction();
                try {
                    // Caller owns the transaction (rows + packet commit together).
                    $summary = MezmurAttendanceService::saveSectionSheet($conn, $date, $section, $records, $adminId, false);
                    $packet = MezmurSubmissionService::upsert($conn, [
                        'taker_id' => $adminId,
                        'date' => $date,
                        'section' => $section,
                        'status' => $packetStatus,
                        'member_count' => $summary['marked'],
                        'present' => $counts['present'],
                        'late' => $counts['late'],
                        'absent' => $counts['absent'],
                        'excused' => $counts['excused'],
                        // Least privilege: only admins may override a
                        // locked (approved/rejected) packet — the review
                        // lock is what makes maker-checker meaningful.
                        'force' => in_array($mezmurRole, ['super_admin', 'school_admin'], true),
                    ]);
                    if (empty($packet['ok'])) {
                        throw new \DomainException($packet['message'] ?? 'Could not update the attendance packet.');
                    }
                    $conn->commit();
                } catch (\DomainException $e) {
                    $conn->rollback();
                    mezmur_respond(['status' => 'error', 'message' => $e->getMessage()]);
                } catch (\Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                mezmur_respond([
                    'status' => 'success',
                    'message' => $packet['message'] ?? 'Attendance saved.',
                    'summary' => $summary,
                    'submission_id' => $packet['id'] ?? 0,
                    'submission_status' => $packet['status'] ?? 'draft',
                ]);
            }

            $result = MezmurAttendanceService::saveSheet($conn, $date, $records, $adminId);
            mezmur_respond(['status' => 'success', 'message' => 'Attendance saved.', 'summary' => $result]);
        }

        // ── ANALYTICS ──────────────────────────────────────────
        case 'analytics_members': {
            $out = MezmurAttendanceService::analyticsMembers($conn, $_GET);
            // Analytics is for decisions — never more PII than name/section/photo.
            $out['items'] = array_map(static function (array $r): array {
                unset($r['full_name_am']);
                return $r;
            }, $out['items']);
            mezmur_respond(['status' => 'success'] + $out);
        }

        case 'analytics_sections': {
            $out = MezmurAttendanceService::analyticsSections($conn, $_GET);
            mezmur_respond(['status' => 'success'] + $out);
        }

        case 'analytics_trends': {
            $out = MezmurAttendanceService::analyticsTrends($conn, $_GET);
            mezmur_respond(['status' => 'success'] + $out);
        }

        // ── ATTENDANCE TAKERS ──────────────────────────────────
        case 'takers_list': {
            mezmur_respond(['status' => 'success', 'items' => MezmurAttendanceService::takersList($conn)]);
        }

        // ── SECTIONS (for [Section ▾] selectors) ───────────────
        case 'sections': {
            mezmur_respond(['status' => 'success', 'items' => MezmurAttendanceService::sectionListWithCounts($conn)]);
        }

        // ── OVERVIEW (single batched read — BFF pattern) ───────
        // One round trip for the whole dashboard overview: stats,
        // month + previous-month aggregates, recent days, recent
        // hymns, and the review queue. Lazy tabs replaced the old
        // 8-parallel-request DOMContentLoaded storm.
        case 'overview': {
            $monthStart = date('Y-m-01');
            $today = date('Y-m-d');
            $prevStart = date('Y-m-01', strtotime('-1 month'));
            $prevEnd = date('Y-m-t', strtotime('-1 month'));

            $aggWindow = static function (\mysqli $c, string $from, string $to): array {
                $zero = ['days' => 0, 'marked' => 0, 'attended' => 0, 'rate' => null];
                try {
                    $stmt = $c->prepare(
                        "SELECT COUNT(*) AS days,
                                (SELECT COUNT(*) FROM mezmur_attendance a WHERE a.attendance_date BETWEEN ? AND ?) AS marked,
                                (SELECT COUNT(*) FROM mezmur_attendance a WHERE a.attendance_date BETWEEN ? AND ?
                                   AND a.status IN ('present','late')) AS attended
                         FROM mezmur_days d WHERE d.attendance_date BETWEEN ? AND ?"
                    );
                    $stmt->bind_param('ssssss', $from, $to, $from, $to, $from, $to);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                    $days = (int)($r['days'] ?? 0);
                    $marked = (int)($r['marked'] ?? 0);
                    $attended = (int)($r['attended'] ?? 0);
                    return [
                        'days' => $days,
                        'marked' => $marked,
                        'attended' => $attended,
                        'rate' => $marked > 0 ? round($attended * 100.0 / $marked, 1) : null,
                    ];
                } catch (\Throwable $e) {
                    // A missing/legacy table must degrade the dashboard to
                    // zeros, never a 500.
                    return $zero;
                }
            };

            try {
                $stats = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(status='active'),0) active FROM mezmur_hymns")->fetch_assoc() ?: [];
            } catch (\Throwable $e) { $stats = ['total' => 0, 'active' => 0]; }
            try {
                $members = $conn->query("SELECT COUNT(*) c FROM members WHERE status = 'active'")->fetch_assoc()['c'] ?? 0;
            } catch (\Throwable $e) { $members = 0; }
            try {
                $takers = MezmurAttendanceService::takersList($conn);
            } catch (\Throwable $e) { $takers = []; }
            $takersActive = count(array_filter($takers, static fn($t) => (int)$t['is_active'] === 1));

            try {
                $recentDays = MezmurAttendanceService::listDays($conn, $monthStart, $today, 1, 5)['items'];
            } catch (\Throwable $e) { $recentDays = []; }
            $recentHymns = [];
            try {
                $rh = $conn->query("SELECT id, title, category, updated_at FROM mezmur_hymns WHERE status = 'active' ORDER BY updated_at DESC, id DESC LIMIT 5");
                if ($rh) {
                    while ($row = $rh->fetch_assoc()) $recentHymns[] = $row;
                }
            } catch (\Throwable $e) { $recentHymns = []; }

            mezmur_respond([
                'status' => 'success',
                'hymns_total' => (int)($stats['total'] ?? 0),
                'members' => (int)$members,
                'takers_active' => $takersActive,
                'takers_total' => count($takers),
                'month' => $aggWindow($conn, $monthStart, $today),
                'prev_month' => $aggWindow($conn, $prevStart, $prevEnd),
                'recent_days' => $recentDays,
                'recent_hymns' => $recentHymns,
                'recent_packets' => array_slice(MezmurSubmissionService::listPackets($conn, ['per_page' => 5])['items'], 0, 5),
            ]);
        }

        // ── SUBMISSIONS INBOX (dept review queue) ──────────────
        case 'submissions_list': {
            $out = MezmurSubmissionService::listPackets($conn, [
                'status' => (string)($_GET['status'] ?? ''),
                'from' => (string)($_GET['from'] ?? ''),
                'to' => (string)($_GET['to'] ?? ''),
                'section' => (string)($_GET['section'] ?? ''),
                'page' => $_GET['page'] ?? 1,
                'per_page' => $_GET['per_page'] ?? 50,
            ]);
            mezmur_respond(['status' => 'success'] + $out);
        }

        case 'submission_detail': {
            $item = MezmurSubmissionService::detail($conn, (int)($_GET['id'] ?? 0));
            if ($item === null) {
                mezmur_respond(['status' => 'error', 'message' => 'Submission not found.']);
            }
            mezmur_respond(['status' => 'success', 'item' => $item]);
        }

        // ── REVIEW (approve / reject / return-with-note) ───────
        // Maker-checker boundary: transfers the editing key back to
        // the taker (revision_needed) or finalizes the packet.
        case 'submission_review': {
            if (!MezmurSubmissionService::canReview(['role' => $mezmurRole])) {
                mezmur_respond(['status' => 'error', 'message' => 'You do not have permission to review submissions.']);
            }
            $result = MezmurSubmissionService::reviewPacket(
                $conn,
                (int)($_POST['submission_id'] ?? 0),
                (string)($_POST['new_status'] ?? ''),
                (string)($_POST['notes'] ?? ''),
                $adminId
            );
            if (!$result['ok']) {
                mezmur_respond(['status' => 'error', 'message' => $result['message']]);
            }
            mezmur_respond(['status' => 'success', 'message' => $result['message']]);
        }

        default:
            mezmur_respond(['status' => 'error', 'message' => 'Unknown action.']);
    }
} catch (\DomainException $e) {
    mezmur_respond(['status' => 'error', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[mezmur] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    mezmur_respond(['status' => 'error', 'message' => 'Unable to complete the request. Please try again.']);
}
