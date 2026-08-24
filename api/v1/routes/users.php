<?php
/**
 * School API v1 — User Routes
 * POST /users/change-password    — Change own password
 * GET  /users/me                 — Get current user profile
 */

$auth = apiRequireAuth();
$action = $ROUTE['id'] ?? '';
$userId = $auth['uid'];

// ============================================================
// GET /users/me — Current user profile
// ============================================================
if ($action === 'me' && $method === 'GET') {
    $stmt = $conn->prepare("SELECT id, username, email, full_name, role, is_active, last_login, created_at, member_id
                            FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user) err('User not found', 404);
    
    // Get teacher assignments if teacher
    if ($user['role'] === 'teacher' || $user['role'] === 'attendance_taker') {
        $year = getCurrentAcademicYear();
        $yearId = $year ? (int)$year['id'] : 0;
        
        $stmt = $conn->prepare("SELECT ta.class_id, c.class_name, ta.subject_id, s.subject_name, ta.is_class_teacher
                                FROM teacher_assignments ta
                                JOIN classes c ON ta.class_id = c.id
                                LEFT JOIN subjects s ON ta.subject_id = s.id
                                WHERE ta.teacher_id = ? AND ta.is_active = 1
                                AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)");
        $stmt->bind_param('ii', $userId, $yearId);
        $stmt->execute();
        $r = $stmt->get_result();
        $assignments = [];
        while ($row = $r->fetch_assoc()) $assignments[] = $row;
        $stmt->close();
        $user['assignments'] = $assignments;
    }
    
    ok($user);
}

// ============================================================
// POST /users/change-password
// ============================================================
if ($action === 'change-password' && $method === 'POST') {
    $body = getBody();
    $currentPassword = $body['current_password'] ?? '';
    $newPassword = $body['new_password'] ?? '';
    $confirmPassword = $body['confirm_password'] ?? '';
    
    // Validate through the same policy used by browser administration.
    if (!is_string($currentPassword) || $currentPassword === '' || strlen($currentPassword) > 4096) {
        err('Current password is required');
    }
    if (!is_string($newPassword) || !is_string($confirmPassword)) {
        err('Invalid password input');
    }
    $passwordErrors = validatePassword($newPassword);
    if ($passwordErrors !== []) err(implode(' ', $passwordErrors));
    if ($newPassword !== $confirmPassword) err('New passwords do not match');
    if ($currentPassword === $newPassword) err('New password must be different from current');
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user) err('User not found', 404);
    
    if (!password_verify($currentPassword, $user['password_hash'])) {
        err('Current password is incorrect');
    }
    
    // Update password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->bind_param('si', $newHash, $userId);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Revoke every refresh-token family. The current access token remains
        // short-lived, while no device can mint another token with the old
        // password session after this change.
        try {
            $revoke = $conn->prepare(
                'UPDATE api_refresh_sessions
                 SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
                 WHERE user_id = ?'
            );
            $revoke->bind_param('i', $userId);
            $revoke->execute();
            $revoke->close();
        } catch (Throwable $error) {
            // Migration 010 can be rolling out; the password update remains
            // authoritative and existing access tokens still expire quickly.
        }
        logApiAction($userId, $auth['usr'], 'password_change', 'User changed their password');
        ok(['message' => 'Password changed successfully']);
    } else {
        $stmt->close();
        err('Failed to update password', 500);
    }
}

err("No handler for {$method} /users" . ($action ? "/{$action}" : ''), 404);
