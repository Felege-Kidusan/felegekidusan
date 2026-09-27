<?php
/**
 * Multi-Sheet Executive Education Intelligence Excel (.xlsx) Generator.
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
require_once __DIR__ . '/backend/services/EducationAnalyticsService.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo 'Please log in.';
    exit;
}

$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
    http_response_code(403);
    echo 'You do not have permission to export executive reports.';
    exit;
}

try {
    \App\Services\EducationAnalyticsService::streamExecutiveExcel($conn, $_GET);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Executive Excel export failed: ' . $e->getMessage());
    echo 'Could not build executive Excel workbook. Please try again.';
}
