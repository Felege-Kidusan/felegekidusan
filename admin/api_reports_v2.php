<?php
/**
 * ============================================================================
 * Reporting Centre API (V2) — read‑only, registry‑governed.
 * ============================================================================
 *
 *   GET ?action=schema                      → fields/metrics/operators/starters for the caller's role
 *   GET ?action=list&q={json}               → paged rows (≤200/page, ≤2 000 deep)
 *   GET ?action=aggregate&q={json}          → group_by buckets + metrics
 *   GET ?action=kpis&q={json}               → metrics over the filtered population
 *   GET ?action=options&field=city&prefix=  → distinct values for a picker
 *   GET ?action=export&format=csv|xlsx&q={json}&lang=en|am → audited file (≤5 000 rows)
 *
 * `q` is a URL‑encoded JSON report definition (≤ 8 KB). Clients never send SQL.
 * Roles: super_admin, school_admin, info_dept, hr_dept (also pinned in
 * access_control.php and FeatureGate 'reports').
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/reporting/ReportRegistry.php';
require_once __DIR__ . '/backend/reporting/ReportQueryService.php';
require_once __DIR__ . '/backend/reporting/ReportExportService.php';

use App\Reporting\ReportExportService;
use App\Reporting\ReportQueryService;
use App\Reporting\ReportRegistry;
use App\Services\SecurityAuditService;

$isExport = (($_GET['action'] ?? '') === 'export');

$fail = static function (int $code, string $message) use ($isExport): never {
    http_response_code($code);
    if ($isExport) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
};

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    $fail(401, 'Unauthorized');
}
$adminId = (int)$_SESSION['admin_id'];
$role    = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ReportRegistry::READER_ROLES, true)) {
    $fail(403, 'You do not have permission to use the reporting centre.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $fail(405, 'Use GET for report reads.');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fail(503, 'The report service is unavailable.');
}

$action = (string)($_GET['action'] ?? '');
if (!in_array($action, ['schema', 'list', 'aggregate', 'kpis', 'options', 'export'], true)) {
    $fail(400, 'Unknown action');
}

// Per‑user rate limits: reads share one bucket, exports a stricter one.
$rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
$bucket = $isExport ? ['member_report_export', 20, 60] : ['member_reports_v2_read', 240, 60];
$check = $rl->consume($bucket[0], 'user:' . $adminId, $bucket[1], $bucket[2]);
if (!$check['allowed']) {
    $fail(429, 'Too many requests. Please wait a moment and try again.');
}

// Parse the definition (schema needs none).
$definition = [];
if ($action !== 'schema' && $action !== 'options') {
    $q = (string)($_GET['q'] ?? '{}');
    if (strlen($q) > 8192) {
        $fail(400, 'Report definition too large.');
    }
    $decoded = json_decode($q, true);
    if (!is_array($decoded)) {
        $fail(400, 'Report definition must be a JSON object.');
    }
    $definition = $decoded;
}
$lang = (($_GET['lang'] ?? 'en') === 'am') ? 'am' : 'en';

try {
    $service = new ReportQueryService($pdo, $role);

    if ($action === 'export') {
        $format = strtolower((string)($_GET['format'] ?? 'csv'));
        if (!in_array($format, ReportExportService::FORMATS, true)) {
            $fail(400, 'Invalid export format.');
        }
        $export = $service->openExport($definition);
        $fallback = false;
        if ($format === 'xlsx' && !ReportExportService::xlsxAvailable()) {
            $format = 'csv';
            $fallback = true;
        }
        // Audit BEFORE streaming (same policy as export_pdf.php / api_export_members.php).
        $summary = ReportQueryService::auditSummary($export['definition']);
        $summary['format'] = $format;
        $summary['pii_fields'] = $export['pii_fields'];
        SecurityAuditService::record($conn, 'Member Report Exported', $summary, 'member_analytics');

        $title = (string)($_GET['title'] ?? 'members');
        $filename = ReportExportService::safeFilename($title, $format);
        if ($fallback) {
            header('X-Report-Format-Fallback: xlsx-unavailable');
        }
        if ($format === 'xlsx') {
            ReportExportService::streamXlsx($export, $filename, $lang, $title);
        } else {
            ReportExportService::streamCsv($export, $filename, $lang);
        }
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    switch ($action) {
        case 'schema':
            $payload = ReportRegistry::schemaForRole($role);
            $payload['xlsx_available'] = ReportExportService::xlsxAvailable();
            break;
        case 'list':
            // Bulk member rows (PII) → audited like the old explorer.
            SecurityAuditService::record($conn, 'Member Analytics Explorer Queried', ReportQueryService::auditSummary($definition), 'member_analytics');
            $payload = $service->list($definition);
            break;
        case 'aggregate':
            $payload = $service->aggregate($definition);
            break;
        case 'kpis':
            $payload = $service->kpis($definition);
            break;
        case 'options':
            $payload = $service->options((string)($_GET['field'] ?? ''), (string)($_GET['prefix'] ?? ''));
            break;
        default:
            $fail(400, 'Unknown action');
    }

    echo json_encode(['status' => 'success', 'data' => $payload, 'generated_at' => date('c')], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $fail(400, $e->getMessage()); // registry validation messages are user‑safe by construction
} catch (Throwable $e) {
    error_log('api_reports_v2 ' . $action . ' failed: ' . $e->getMessage());
    $fail(500, 'The report could not be produced.');
}
