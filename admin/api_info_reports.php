<?php
/**
 * ════════════════════════════════════════════════════════════
 * Information Department — Report Downloads (web console, Phase D)
 * ════════════════════════════════════════════════════════════
 * Streams governed PDF reports built by PdfReportService over the
 * three independent attendance sources. GET-only (a report render
 * writes nothing), CSRF-free because it mutates nothing.
 *
 *   GET ?type=general|sections|classes|member|full
 *       [&source=mezmur|hr]  (sections template)
 *       [&member_id=N]       (member template)
 *       [&from=YYYY-MM-DD&to=YYYY-MM-DD]
 *
 * Defense in depth: ROLE_MAP gate + re-checked session/role here,
 * per-user rate limiting, audit log, no internal leakage.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/InfoAnalyticsService.php';
require_once __DIR__ . '/backend/services/PdfReportService.php';

use App\Services\PdfReportService;
use App\Services\SecurityAuditService;

if (!defined('INFO_REPORTS_API_VERSION')) define('INFO_REPORTS_API_VERSION', 'phase7-pdf27');

function info_rp_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => $message,
        'v' => INFO_REPORTS_API_VERSION,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    $token = bin2hex(random_bytes(3));
    error_log('[info-reports-unhandled #' . $token . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    info_rp_fail('Unexpected server fault (log reference ' . $token . '). Please retry.', 500);
});

// ── 1. Auth (same gate as the analytics hub) ─────────────────
if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    info_rp_fail('Please sign in again.', 401);
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ['super_admin', 'school_admin', 'info_dept'], true)) {
    info_rp_fail('You do not have permission to generate reports.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    info_rp_fail('Use GET to download reports.', 405);
}

// ── 2. Rate limiting (per user) — PDF builds are heavier ─────
$rl = new \App\Services\SecurityRateLimiter($pdo ?? null, sys_get_temp_dir() . '/ssms_ratelimit');
$rlCheck = $rl->consume('info_reports_build', 'user:' . $adminId, 30, 60);
if (!$rlCheck['allowed']) {
    info_rp_fail('Too many reports requested. Please wait a moment and try again.', 429);
}

$type = (string)($_GET['type'] ?? 'general');
$source = (string)($_GET['source'] ?? 'mezmur');
$memberId = (int)($_GET['member_id'] ?? 0);
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');

// ── 3. Audit every report generation (governed layer rule) ───
SecurityAuditService::record(
    $conn,
    'Info Report Generated',
    ['type' => $type, 'source' => $source, 'member_id' => $memberId ?: null,
     'from' => $from ?: null, 'to' => $to ?: null],
    'info_reports', $memberId ?: null
);

// ── 4. Build + stream ────────────────────────────────────────
$result = PdfReportService::build($conn, $type, [
    'source' => $source,
    'member_id' => $memberId,
    'from' => $from,
    'to' => $to,
]);

if (empty($result['ok'])) {
    info_rp_fail($result['message'] ?? 'Report could not be generated.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
header('Content-Length: ' . strlen($result['data']));
header('Cache-Control: no-store');
header('X-Report-Version: ' . INFO_REPORTS_API_VERSION);
echo $result['data'];
exit;
