<?php
/**
 * ============================================================
 * Dashboard Router — frontend/pages/dashboard.php
 * ============================================================
 * Routes the logged-in user to their role-specific dashboard.
 * This replaces admin/dashboard.php as the entry point.
 * ============================================================
 */

require_once __DIR__ . '/../../config.php';

// Must be logged in
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: /frontend/pages/login.php');
    exit;
}

$role = $_SESSION['admin_role'] ?? '';

// Map role → dashboard page
$dashboardMap = [
    'super_admin'      => 'super_admin.php',
    'school_admin'     => 'school_admin.php',
    'info_dept'        => 'info_dept.php',
    'edu_dept'         => 'edu_dept.php',
    'finance_dept'     => 'finance_dept.php',
    'material_dept'    => 'material_dept.php',
    'teacher'          => 'teacher.php',
    'attendance_taker' => 'attendance_taker.php',
];

$dashFile = $dashboardMap[$role] ?? null;

if ($dashFile && file_exists(__DIR__ . '/' . $dashFile)) {
    // New separated dashboard exists → use it
    require __DIR__ . '/' . $dashFile;
} else {
    // Fallback to old admin dashboard during migration
    // This ensures un-migrated dashboards still work
    header('Location: /admin/dashboard.php');
    exit;
}
