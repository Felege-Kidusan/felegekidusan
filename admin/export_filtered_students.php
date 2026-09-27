<?php
/**
 * Server-side Excel export for advanced filtered student performance & attendance.
 */
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo 'Excel library is missing.';
    exit;
}
require_once $autoload;
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/ReportCardService.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo 'Please log in.';
    exit;
}

$userId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');

if (!in_array($role, ['super_admin', 'school_admin', 'edu_dept', 'teacher'], true)) {
    http_response_code(403);
    echo 'You do not have permission to export performance data.';
    exit;
}

try {
    \App\Services\ReportCardService::streamFilteredExcel($conn, $_GET);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Filtered students Excel export failed: ' . $e->getMessage());
    echo 'Could not build the Excel file. Please try again.';
}
