<?php
/**
 * Bounded, constant-memory member report controller.
 *
 * Supported compatibility formats:
 * - pdf: print-ready HTML that opens the browser print dialog
 * - docx: Word-compatible HTML downloaded with the historical .doc extension
 * - csv: bounded report CSV (complete roster CSV lives at api_export_members.php)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/MemberReportService.php';
require_once __DIR__ . '/backend/services/MemberReportRenderer.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';

use App\Services\MemberReportRenderer;
use App\Services\MemberReportService;
use App\Services\SecurityAuditService;

$role = (string)($_SESSION['admin_role'] ?? '');
if (empty($_SESSION['admin_logged_in'])
    || !in_array($role, ['super_admin', 'school_admin', 'info_dept', 'hr_dept'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    exit('The report service is unavailable.');
}

$formatValue = $_GET['format'] ?? 'pdf';
$format = is_string($formatValue) ? strtolower(trim($formatValue)) : '';
if (!in_array($format, ['pdf', 'docx', 'csv'], true)) {
    http_response_code(400);
    exit('Invalid report format.');
}
if ($format === 'pdf' && !feature_enabled('export_pdf')) {
    http_response_code(403);
    exit('PDF export is not enabled for this deployment.');
}

try {
    $report = new MemberReportService($pdo, $_GET);
    $summary = $report->summary();

    $requestedTitle = $_GET['title'] ?? '';
    if (!is_string($requestedTitle)) {
        throw new InvalidArgumentException('Invalid report title.');
    }
    $requestedTitle = trim($requestedTitle);
    $titleLength = function_exists('mb_strlen')
        ? mb_strlen($requestedTitle, 'UTF-8')
        : strlen($requestedTitle);
    if ($titleLength > 120
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $requestedTitle)) {
        throw new InvalidArgumentException('Invalid report title.');
    }
    $title = $report->presetTitle() !== ''
        ? $report->presetTitle()
        : ($requestedTitle !== '' ? $requestedTitle : SCHOOL_NAME_SHORT . ' Member Report');

    $truncated = $summary['total'] > MemberReportService::MAX_ROWS;
    $filterValue = $_GET['filter'] ?? 'all';
    $filter = is_string($filterValue) ? $filterValue : 'invalid';
    $auditRecorded = SecurityAuditService::record($pdo, 'Member Report Requested', [
        'format' => $format,
        'filter' => $filter,
        'matching_rows' => $summary['total'],
        'row_limit' => MemberReportService::MAX_ROWS,
        'truncated' => $truncated,
    ], 'member_report');
    if (!$auditRecorded) {
        http_response_code(503);
        exit('The report cannot start because its audit event could not be recorded.');
    }

    // Execute the bounded, unbuffered query before sending headers so startup
    // failures still produce a clean HTTP error rather than a partial document.
    $rows = $report->openRows($format);
    $now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $generatedAt = ethio_date_format($now, 'F j, Y') . ' ' . $now->format('g:i A');
    $baseFilename = EXPORT_PREFIX . '_member_report_' . date('Y-m-d');

    if ($format === 'csv') {
        MemberReportRenderer::streamCsv(
            $rows,
            $summary,
            $baseFilename . '.csv',
            $truncated
        );
        exit;
    }

    if ($format === 'docx') {
        MemberReportRenderer::streamWord(
            $rows,
            $summary,
            $title,
            $generatedAt,
            $baseFilename . '.doc',
            $truncated
        );
        exit;
    }

    $csvQuery = $_GET;
    $csvQuery['format'] = 'csv';
    unset($csvQuery['title']);
    foreach ($csvQuery as $key => $value) {
        if (!is_scalar($value)) {
            unset($csvQuery[$key]);
        }
    }
    $csvUrl = '/admin/export_pdf.php?' . http_build_query($csvQuery, '', '&', PHP_QUERY_RFC3986);
    MemberReportRenderer::streamPrintPage(
        $rows,
        $summary,
        $title,
        $generatedAt,
        $csvUrl,
        SCHOOL_NAME_SHORT . ' ' . SCHOOL_TYPE . ' ' . SCHOOL_TAGLINE,
        ADMIN_FOOTER_TEXT,
        $truncated,
        true
    );
    exit;
} catch (InvalidArgumentException $error) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        $safeMessage = $error->getMessage();
        exit($safeMessage);
    }
    exit;
} catch (Throwable $error) {
    reportInternalError('Member report failed', $error);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('The report could not be completed.');
    }
    exit;
}
