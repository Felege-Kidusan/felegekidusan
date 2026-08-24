<?php
/**
 * Member export controller.
 *
 * - CSV is streamed in constant PHP memory for complete large-roster exports.
 * - Styled XLSX remains the editable round-trip format and is deliberately
 *   bounded to the importer's 2,000-row transaction limit.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/ExcelColumnMap.php';
require_once __DIR__ . '/backend/services/EnrollmentService.php';
require_once __DIR__ . '/backend/services/MemberExportService.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';

use App\Services\EnrollmentService;
use App\Services\ExcelColumnMap;
use App\Services\MemberExportService;
use App\Services\SecurityAuditService;

$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ['super_admin', 'school_admin', 'info_dept', 'hr_dept'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
if (!isset($pdo) || !($pdo instanceof PDO) || !isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(503);
    exit('The export service is unavailable.');
}

$tier = (string)($_GET['tier'] ?? 'permanent');
if (!in_array($tier, ['temporary', 'permanent', 'all'], true)) {
    http_response_code(400);
    exit('Invalid membership tier.');
}
$format = strtolower((string)($_GET['format'] ?? 'xlsx'));
if (!in_array($format, ['xlsx', 'csv'], true) || ($tier === 'all' && $format !== 'csv')) {
    http_response_code(400);
    exit('Invalid export format.');
}

$columns = ExcelColumnMap::columns($tier);
$headerLabels = ExcelColumnMap::headersFor($columns);
$dateFormatter = static function (string $raw): string {
    try {
        $date = new DateTime($raw, new DateTimeZone('Africa/Addis_Ababa'));
        return ethio_date_format($date, 'Y-m-d');
    } catch (Throwable $error) {
        return $raw;
    }
};

try {
    $year = EnrollmentService::activeYear($conn);
    $yearId = $year ? (int)$year['id'] : 0;
    $expectedRows = MemberExportService::count($pdo, $tier);
} catch (Throwable $error) {
    reportInternalError('Member export count failed', $error);
    http_response_code(500);
    exit('The export could not be prepared.');
}

$tooLargeForWorkbook = $format === 'xlsx'
    && $expectedRows > MemberExportService::MAX_EDITABLE_ROWS;
$auditRecorded = SecurityAuditService::record($pdo, 'Member Export Requested', [
    'tier' => $tier,
    'format' => $format,
    'expected_rows' => $expectedRows,
    'result' => $tooLargeForWorkbook ? 'bounded_workbook_rejected' : 'started',
], 'member_export');
if (!$auditRecorded) {
    http_response_code(503);
    exit('The export cannot start because its audit event could not be recorded.');
}

if ($tooLargeForWorkbook) {
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        'This editable workbook would exceed the safe 2,000-row sync limit. '
        . 'Download the complete streaming CSV export instead, or narrow the roster before editing.'
    );
}

$baseFilename = 'sundayschool_' . $tier . '_members_' . date('Y-m-d');
try {
    if ($format === 'csv') {
        MemberExportService::streamCsv(
            $pdo,
            $tier,
            $yearId,
            $columns,
            $headerLabels,
            $baseFilename . '.csv',
            $dateFormatter
        );
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        http_response_code(500);
        exit('Excel export support is unavailable.');
    }
    require_once $autoload;
    require_once __DIR__ . '/backend/services/ExcelExportService.php';

    $classOptions = [];
    try {
        $classStatement = $conn->prepare(
            'SELECT class_name FROM classes WHERE is_active = 1 ORDER BY level_order, class_name LIMIT 1000'
        );
        if ($classStatement && $classStatement->execute()) {
            $classResult = $classStatement->get_result();
            while ($class = $classResult->fetch_assoc()) {
                if (!empty($class['class_name'])) {
                    $classOptions[] = $class['class_name'];
                }
            }
            $classStatement->close();
        }
    } catch (Throwable $error) {
        reportInternalError('Member export class options unavailable', $error);
    }

    $data = MemberExportService::collectEditable(
        $pdo,
        $tier,
        $yearId,
        $dateFormatter
    );
    $title = $tier === 'temporary' ? 'Temporary Members Sync' : 'Permanent Members Sync';
    \App\Services\ExcelExportService::export(
        $title,
        $columns,
        $data,
        $baseFilename . '.xlsx',
        ExcelColumnMap::locked($tier),
        $headerLabels,
        $classOptions
    );
} catch (Throwable $error) {
    reportInternalError('Member export failed', $error);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('The export could not be completed.');
    }
    exit;
}
