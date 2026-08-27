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

function mezmur_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 1. Session gate ───────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    mezmur_respond(['status' => 'session_expired', 'message' => 'Please log in to continue.', 'action' => 'reload'], 401);
}

// ── 2. Role gate (defense in depth; central guard ran first) ──
$mezmurRole = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($mezmurRole, ['super_admin', 'school_admin', 'mezmur_dept'], true)) {
    mezmur_respond(['status' => 'error', 'message' => 'You do not have permission to use the Mezmur module.'], 403);
}

// ── 3. Feature gate (fail-closed) ─────────────────────────────
if (!FeatureGate::isEnabled('mezmur')) {
    mezmur_respond(['status' => 'error', 'message' => 'The Mezmur module is not enabled for this deployment.'], 403);
}

// ── 4. CSRF for all state-changing requests ───────────────────
requireCsrfForPost();

$action  = $_REQUEST['action'] ?? '';
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// State-changing actions must arrive via POST (CSRF-protected above).
if (in_array($action, ['save', 'set_status', 'save_sheet', 'day_create'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    mezmur_respond(['status' => 'error', 'message' => 'Use POST for this action.'], 405);
}

require_once __DIR__ . '/backend/services/MezmurAttendanceService.php';

use App\Services\MezmurAttendanceService;

// ── 5. Rate limiting (per user; DB-backed with file fallback) ─
$__rl = new \App\Services\SecurityRateLimiter(
    $pdo ?? null,
    sys_get_temp_dir() . '/ssms_ratelimit'
);
$__rlAction = in_array($action, ['save', 'set_status', 'save_sheet', 'day_create'], true)
    ? 'mezmur_write' : 'mezmur_read';
$__rlLimit  = $__rlAction === 'mezmur_write' ? 30 : 240;   // per minute
$__rlCheck  = $__rl->consume($__rlAction, 'user:' . $adminId, $__rlLimit, 60);
if (!$__rlCheck['allowed']) {
    mezmur_respond(['status' => 'error', 'message' => 'Too many requests. Please wait a moment and try again.'], 429);
}

// ── 6. Schema probes (clear message instead of a raw 1054) ────
try {
    $conn->query("SELECT 1 FROM mezmur_hymns LIMIT 0");
} catch (\Throwable $e) {
    mezmur_respond(['status' => 'error', 'message' => 'Mezmur tables not found. Ask the administrator to run sql/021_mezmur_department.sql.']);
}
$__attendanceActions = ['days_list', 'day_create', 'sheet', 'save_sheet',
    'analytics_members', 'analytics_sections', 'analytics_trends', 'takers_list'];
if (in_array($action, $__attendanceActions, true)) {
    try {
        $conn->query("SELECT 1 FROM mezmur_days LIMIT 0");
    } catch (\Throwable $e) {
        mezmur_respond(['status' => 'error', 'message' => 'Mezmur attendance tables not found. Ask the administrator to run sql/022_mezmur_attendance.sql and sql/023_mezmur_date_attendance.sql.']);
    }
}

try {
    switch ($action) {

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
            if ($search !== '') {
                // Escape LIKE wildcards inside the user term.
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)) . '%';
                $where[] = '(title LIKE ? ESCAPE \'\\\\\' OR title_am LIKE ? ESCAPE \'\\\\\' OR reference LIKE ? ESCAPE \'\\\\\')';
                $types .= 'sss';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            // Total count for pagination
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_hymns $whereSql");
            if ($params) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            $sql = "SELECT id, title, title_am, category, reference, status, updated_at
                    FROM mezmur_hymns $whereSql
                    ORDER BY updated_at DESC, id DESC
                    LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            $allParams = array_merge($params, [$perPage, $offset]);
            $stmt->bind_param($types . 'ii', ...$allParams);
            $stmt->execute();
            $res = $stmt->get_result();
            $items = [];
            while ($r = $res->fetch_assoc()) $items[] = $r;
            $stmt->close();

            mezmur_respond([
                'status' => 'success',
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'total_pages' => $totalPages,
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
            if (!$item) mezmur_respond(['status' => 'error', 'message' => 'Hymn not found.'], 404);
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

        default:
            mezmur_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }
} catch (\DomainException $e) {
    mezmur_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log('[mezmur] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    mezmur_respond(['status' => 'error', 'message' => 'Unable to complete the request. Please try again.'], 500);
}
