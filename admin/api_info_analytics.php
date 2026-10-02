<?php
/**
 * ════════════════════════════════════════════════════════════
 * Information Department — Analytics Hub API (web console)
 * ════════════════════════════════════════════════════════════
 * The Information department is the school's READ-ONLY analytics
 * hub over three independent attendance sources (Education classes,
 * Mezmur sections, HR sections). This endpoint is the single
 * governed surface for that hub:
 *
 *   GET  kpi        — KPI band, one row per source (never merged)
 *   GET  trends     — daily trend rows for one source
 *   GET  groups     — per-section/class aggregate table (paged)
 *   GET  comparison — side-by-side source comparison
 *   GET  meta       — source/group filter metadata
 *   POST refresh    — rebuild the rollup read model (ADMINS ONLY;
 *                     the Information department itself never writes)
 *
 * Defense in depth:
 *   1. access_control.php ROLE_MAP gates this file.
 *   2. This file re-checks login + role itself.
 *   3. CSRF token required on the only POST (refresh).
 *   4. Per-user rate limiting (reads generous, refresh tight).
 *   5. Every analytics access is audit-logged (governed layer rule).
 *   6. Exceptions never leak internals.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/InfoAnalyticsService.php';

use App\Services\InfoAnalyticsService;
use App\Services\SecurityAuditService;

if (!defined('INFO_ANALYTICS_API_VERSION')) define('INFO_ANALYTICS_API_VERSION', 'phase7-info27');

function info_an_respond(array $payload): void
{
    $payload['v'] = INFO_ANALYTICS_API_VERSION;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    $token = bin2hex(random_bytes(3));
    error_log('[info-analytics-unhandled #' . $token . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    info_an_respond([
        'status' => 'error',
        'message' => 'Unexpected server fault (log reference ' . $token . '). Please retry.',
    ]);
});

// ── 1. Auth ───────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    info_an_respond(['status' => 'error', 'message' => 'Please sign in again.']);
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
// Read-only analytics: Information department + admins. No other role.
if (!in_array($role, ['super_admin', 'school_admin', 'info_dept'], true)) {
    http_response_code(403);
    info_an_respond(['status' => 'error', 'message' => 'You do not have permission to view analytics.']);
}

$action = (string)($_REQUEST['action'] ?? '');

// ── 2. Schema probe (clear message instead of a raw 1054) ────
try {
    $probe = $conn->query('SELECT 1 FROM attendance_rollup LIMIT 0');
    if ($probe === false) {
        throw new \RuntimeException('attendance_rollup missing');
    }
    $probe->close();
} catch (\Throwable $e) {
    info_an_respond(['status' => 'error', 'message' => 'Analytics tables not found. Ask the administrator to run sql/027_attendance_rollup.sql.']);
}

// ── 3. CSRF on POST + write-permission gate ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($role, ['super_admin', 'school_admin'], true)) {
        http_response_code(403);
        info_an_respond(['status' => 'error', 'message' => 'The Information department is read-only. Only administrators can refresh the analytics data.']);
    }
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        info_an_respond(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
    }
} elseif ($action === 'refresh') {
    info_an_respond(['status' => 'error', 'message' => 'Use POST for this action.']);
}

// ── 4. Rate limiting (per user) ──────────────────────────────
$rl = new \App\Services\SecurityRateLimiter($pdo ?? null, sys_get_temp_dir() . '/ssms_ratelimit');
$rlKey = $action === 'refresh' ? 'info_analytics_write' : 'info_analytics_read';
$rlLimit = $action === 'refresh' ? 10 : 240; // per minute
$rlCheck = $rl->consume($rlKey, 'user:' . $adminId, $rlLimit, 60);
if (!$rlCheck['allowed']) {
    info_an_respond(['status' => 'error', 'message' => 'Too many requests. Please wait a moment and try again.']);
}

// ── 5. Audit every analytics access (governed layer rule) ────
SecurityAuditService::record(
    $conn,
    $action === 'refresh' ? 'Info Analytics Refreshed' : 'Info Analytics Viewed',
    ['action' => $action, 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null,
     'source' => $_GET['source'] ?? null],
    'info_analytics', null
);

try {
    switch ($action) {
        case 'kpi': {
            info_an_respond(['status' => 'success'] + InfoAnalyticsService::kpiBand(
                $conn, (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? '')
            ));
        }

        case 'trends': {
            info_an_respond(['status' => 'success'] + InfoAnalyticsService::trends(
                $conn, (string)($_GET['source'] ?? ''),
                (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? '')
            ));
        }

        case 'groups': {
            info_an_respond(['status' => 'success'] + InfoAnalyticsService::groupTable(
                $conn,
                (string)($_GET['source'] ?? ''),
                (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''),
                (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 50),
                (string)($_GET['sort'] ?? 'marked'), (string)($_GET['dir'] ?? 'desc')
            ));
        }

        case 'comparison': {
            info_an_respond(['status' => 'success'] + InfoAnalyticsService::comparison(
                $conn, (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? '')
            ));
        }

        case 'meta': {
            info_an_respond(['status' => 'success'] + InfoAnalyticsService::sourceMeta($conn));
        }

        case 'refresh': {
            $result = InfoAnalyticsService::refreshRollup($conn);
            info_an_respond(['status' => 'success', 'message' => 'Analytics data refreshed.', 'rows' => $result['rows']]);
        }

        default:
            info_an_respond(['status' => 'error', 'message' => 'Unknown action.']);
    }
} catch (\DomainException $e) {
    info_an_respond(['status' => 'error', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[info-analytics] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    info_an_respond(['status' => 'error', 'message' => 'Unable to complete the request. Please try again.']);
}
