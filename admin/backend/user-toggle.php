<?php
/**
 * User Toggle Status API
 * Toggle user active/inactive status
 */

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require __DIR__ . '/config.php';

$userId = (int)($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$userId || $action !== 'toggle_status') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit;
}

$currentRole = $_SESSION['admin_role'] ?? '';

// Check permissions - who can toggle which users
$allowedToToggle = [
    'super_admin' => ['super_admin', 'school_admin', 'info_dept', 'edu_dept', 'finance_dept', 'material_dept', 'teacher', 'attendance_taker'],
    'school_admin' => ['info_dept', 'edu_dept', 'finance_dept', 'material_dept', 'teacher', 'attendance_taker'],
    'info_dept' => ['attendance_taker'],
    'edu_dept' => ['teacher'],
];

// Get target user role
$stmt = $pdo->prepare("SELECT role, is_active FROM users WHERE id = ?");
$stmt->execute([$userId]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

$allowedRoles = $allowedToToggle[$currentRole] ?? [];
if (!in_array($targetUser['role'], $allowedRoles)) {
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to modify this user']);
    exit;
}

// Toggle status
$newStatus = $targetUser['is_active'] ? 0 : 1;

try {
    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $stmt->execute([$newStatus, $userId]);
    
    echo json_encode([
        'status' => 'success',
        'message' => $newStatus ? 'User activated' : 'User deactivated',
        'new_status' => $newStatus
    ]);
} catch (Exception $e) {
    error_log("User toggle error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
