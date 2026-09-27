<?php
/**
 * Publication-Ready Executive Education Intelligence PDF Generator.
 */
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
    echo 'You do not have permission to generate executive education reports.';
    exit;
}

try {
    \App\Services\EducationAnalyticsService::streamExecutivePdf($conn, $_GET);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Could not generate executive report: ' . $e->getMessage();
}
