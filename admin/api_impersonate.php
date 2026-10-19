<?php
/**
 * Impersonate API — Switch Role Without Password
 * Only school_admin and super_admin can use this.
 * Stores original role in session so they can switch back.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$originalRole = $_SESSION['original_admin_role'] ?? $_SESSION['admin_role'] ?? '';

// CSRF protection for all POST requests
requireCsrfForPost();

// Only school_admin and super_admin can impersonate
if (!in_array($originalRole, ['school_admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only School Admin or Super Admin can switch roles']);
    exit;
}

// SECURITY: state-changing actions must arrive via POST (CSRF-protected by
// requireCsrfForPost above). Accepting $_REQUEST would let a cross-site
// <img>/link request switch an admin's role. 'status' is read-only and may
// use GET.
$action = $_POST['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['action'] ?? '') : '');
if ($action !== 'status' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Use POST for this action']);
    exit;
}

// Audit 2026-10-20: every role switch / restore is written to activity_logs
// (who, from which role, to which role) — the switch grants department
// powers without a password, so it must be traceable.
$impersonationAudit = static function (string $what, string $from, string $to) use ($conn): void {
    try {
        $stmt = $conn->prepare('INSERT INTO activity_logs (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        if ($stmt) {
            $uid = (int)($_SESSION['admin_id'] ?? 0);
            $usr = (string)($_SESSION['admin_username'] ?? '');
            $details = $from . ' -> ' . $to;
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $stmt->bind_param('issss', $uid, $usr, $what, $details, $ip);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // auditing never blocks the action, but it is attempted every time
    }
};

switch ($action) {
    case 'switch':
        $targetRole = $_POST['role'] ?? '';
        $allowedRoles = ['school_admin','hr_dept','info_dept','edu_dept','finance_dept','material_dept','mezmur_dept','teacher','attendance_taker'];
        
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
        $fromRole = (string)($_SESSION['admin_role'] ?? '');
        $_SESSION['admin_role'] = $targetRole;
        $impersonationAudit('Role Switched', $fromRole, $targetRole);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Switched to ' . str_replace('_', ' ', $targetRole),
            'role' => $targetRole,
            'original_role' => $_SESSION['original_admin_role']
        ]);
        break;

    case 'restore':
        if (!empty($_SESSION['original_admin_role'])) {
            $impersonationAudit('Role Restored', (string)($_SESSION['admin_role'] ?? ''), (string)$_SESSION['original_admin_role']);
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
