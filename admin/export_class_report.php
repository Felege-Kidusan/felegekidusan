<?php
/**
 * Branded Excel for one class report card list.
 * Read-only. Same numbers as the Education Report Cards screen.
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
$classId = (int)($_GET['class_id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$yearId = (int)($_GET['year_id'] ?? 0);
$termId = (int)($_GET['term_id'] ?? 0);

if ($classId <= 0) {
    http_response_code(400);
    echo 'Select a class first.';
    exit;
}
if (!\App\Services\ReportCardService::canViewClass($conn, $userId, $role, $classId)) {
    http_response_code(403);
    echo 'You do not have permission to export this class.';
    exit;
}

try {
    \App\Services\ReportCardService::streamExcel($conn, $classId, $subjectId, $yearId, $termId);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Could not build the Excel file.';
}
