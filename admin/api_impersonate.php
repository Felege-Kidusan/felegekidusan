<?php
/**
 * Impersonate API — Switch Role Without Password
 * Only school_admin and super_admin can use this.
 * Stores original role in session so they can switch back.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$originalRole = $_SESSION['original_admin_role'] ?? $_SESSION['admin_role'] ?? '';

// CSRF protection for all POST requests
requireCsrfForPost();

// Only school_admin and super_admin can impersonate
if (!in_array($originalRole, ['school_admin', 'super_admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Only School Admin or Super Admin can switch roles']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'switch':
        $targetRole = $_POST['role'] ?? '';
        $allowedRoles = ['school_admin','info_dept','edu_dept','finance_dept','material_dept','teacher','attendance_taker'];
        
        if (!in_array($targetRole, $allowedRoles)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid role']);
            exit;
        }
        
        // Save original role if not already saved
        if (empty($_SESSION['original_admin_role'])) {
            $_SESSION['original_admin_role'] = $_SESSION['admin_role'];
            $_SESSION['original_admin_full_name'] = $_SESSION['admin_full_name'] ?? '';
        }
        
        // Switch role
        $_SESSION['admin_role'] = $targetRole;
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Switched to ' . str_replace('_', ' ', $targetRole),
            'role' => $targetRole,
            'original_role' => $_SESSION['original_admin_role']
        ]);
        break;

    case 'restore':
        if (!empty($_SESSION['original_admin_role'])) {
            $_SESSION['admin_role'] = $_SESSION['original_admin_role'];
            if (!empty($_SESSION['original_admin_full_name'])) {
                $_SESSION['admin_full_name'] = $_SESSION['original_admin_full_name'];
            }
            unset($_SESSION['original_admin_role'], $_SESSION['original_admin_full_name']);
            echo json_encode(['status' => 'success', 'message' => 'Restored to original role', 'role' => $_SESSION['admin_role']]);
        } else {
            echo json_encode(['status' => 'info', 'message' => 'Already on original role']);
        }
        break;

    case 'status':
        echo json_encode([
            'status' => 'success',
            'current_role' => $_SESSION['admin_role'] ?? '',
            'original_role' => $_SESSION['original_admin_role'] ?? null,
            'is_impersonating' => !empty($_SESSION['original_admin_role'])
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action. Use: switch, restore, status']);
}
