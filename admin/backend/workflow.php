<?php
/**
 * ============================================================
 * School Workflow & Notification System
 * ============================================================
 * 
 * This library handles:
 * 1. Sending notifications to departments/users
 * 2. Logging member changes for department sync
 * 3. Creating cross-department tasks
 * 4. Auto-updating related member data
 * 
 * Include this file in any page that needs these features:
 * require_once __DIR__ . '/backend/workflow.php';
 * ============================================================
 */

// Ensure config is loaded
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config.php';
}

// ============================================================
// AUTO-CREATE NOTIFICATIONS TABLE IF MISSING
// ============================================================
if (isset($conn) && $conn && !$conn->connect_error) {
    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            $conn->query("
                CREATE TABLE IF NOT EXISTS `notifications` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `type` VARCHAR(50) NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `message` TEXT NOT NULL,
                    `data` JSON DEFAULT NULL,
                    `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
                    `source_dept` VARCHAR(50) DEFAULT NULL,
                    `source_user_id` INT UNSIGNED DEFAULT NULL,
                    `target_roles` VARCHAR(255) DEFAULT NULL,
                    `target_user_id` INT UNSIGNED DEFAULT NULL,
                    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `read_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `type` (`type`),
                    KEY `target_roles` (`target_roles`),
                    KEY `target_user_id` (`target_user_id`),
                    KEY `is_read` (`is_read`),
                    KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (Exception $e) {
        // Table creation may fail - config.php will also try to create it
        error_log("workflow.php: Could not create notifications table: " . $e->getMessage());
    }
}

/**
 * Department role mappings for notifications
 */
$DEPT_ROLES = [
    'super_admin' => 'Super Admin',
    'school_admin' => 'School Admin',
    'info_dept' => 'Information Dept',
    'edu_dept' => 'Education Dept',
    'finance_dept' => 'Finance Dept',
    'material_dept' => 'Material Dept',
];

/**
 * Which departments should be notified for each event type
 */
$NOTIFICATION_MATRIX = [
    'member_registered' => ['super_admin', 'school_admin', 'edu_dept'],
    'member_updated' => ['super_admin', 'school_admin'],
    'member_archived' => ['super_admin', 'school_admin', 'edu_dept', 'finance_dept'],
    'member_restored' => ['super_admin', 'school_admin', 'edu_dept'],
    'role_changed' => ['super_admin', 'school_admin', 'info_dept', 'edu_dept'],
    'teacher_assigned' => ['super_admin', 'school_admin', 'info_dept'],
    'class_enrolled' => ['super_admin', 'school_admin', 'info_dept'],
    'grade_recorded' => ['super_admin', 'school_admin'],
    'attendance_issue' => ['super_admin', 'school_admin', 'info_dept'],
    'document_shared' => ['super_admin', 'school_admin', 'info_dept', 'edu_dept', 'finance_dept', 'material_dept'],
    'task_assigned' => [], // Specific to target
];

// ============================================================
// NOTIFICATION FUNCTIONS
// ============================================================

/**
 * Send a notification to specific roles or user
 * 
 * @param mysqli $conn Database connection
 * @param string $type Notification type (e.g., 'member_registered')
 * @param string $title Notification title
 * @param string $message Notification message
 * @param array $options Optional settings:
 *   - 'data' => array of additional data
 *   - 'priority' => 'low', 'normal', 'high', 'urgent'
 *   - 'target_roles' => array of roles to notify
 *   - 'target_user_id' => specific user ID
 *   - 'source_dept' => department that triggered this
 * @return int|false Notification ID or false on failure
 */
function sendNotification($conn, $type, $title, $message, $options = []) {
    global $NOTIFICATION_MATRIX;
    
    // Check if table exists first (exception-safe)
    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            return false; // Silently fail if table doesn't exist
        }
    } catch (Exception $e) {
        return false;
    }
    
    try {
        $data = isset($options['data']) ? json_encode($options['data']) : null;
        $priority = $options['priority'] ?? 'normal';
        $sourceDept = $options['source_dept'] ?? ($_SESSION['admin_role'] ?? null);
        $sourceUserId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        
        // Determine target roles
        $targetRoles = $options['target_roles'] ?? ($NOTIFICATION_MATRIX[$type] ?? []);
        $targetRolesStr = is_array($targetRoles) ? implode(',', $targetRoles) : $targetRoles;
        
        $targetUserId = isset($options['target_user_id']) ? (int)$options['target_user_id'] : null;
        
        $stmt = $conn->prepare("
            INSERT INTO notifications 
            (type, title, message, data, priority, source_dept, source_user_id, target_roles, target_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) return false;
        
        $stmt->bind_param(
            "sssssssss",
            $type, $title, $message, $data, $priority, 
            $sourceDept, $sourceUserId, $targetRolesStr, $targetUserId
        );
        
        if ($stmt->execute()) {
            return $conn->insert_id;
        }
    } catch (Exception $e) {
        // Silently fail - notifications should never crash the main operation
        error_log("sendNotification error: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get unread notifications for current user
 */
function getUnreadNotifications($conn, $limit = 20) {
    // Check if table exists first (exception-safe)
    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            return [];
        }
    } catch (Exception $e) {
        return [];
    }
    
    $userRole = $_SESSION['admin_role'] ?? '';
    $userId = $_SESSION['admin_id'] ?? 0;
    
    try {
        $stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE is_read = 0 
            AND (
                FIND_IN_SET(?, target_roles) > 0 
                OR target_user_id = ?
                OR (target_roles IS NULL AND target_user_id IS NULL)
            )
            ORDER BY created_at DESC
            LIMIT ?
        ");
        
        if (!$stmt) return [];
        
        $stmt->bind_param("sii", $userRole, $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $row['data'] = $row['data'] ? json_decode($row['data'], true) : null;
            $notifications[] = $row;
        }
        
        return $notifications;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get unread notification count
 */
function getUnreadNotificationCount($conn) {
    // Check if table exists first (exception-safe)
    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            return 0;
        }
    } catch (Exception $e) {
        return 0;
    }
    
    $userRole = $_SESSION['admin_role'] ?? '';
    $userId = $_SESSION['admin_id'] ?? 0;
    
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as cnt FROM notifications 
            WHERE is_read = 0 
            AND (
                FIND_IN_SET(?, target_roles) > 0 
                OR target_user_id = ?
                OR (target_roles IS NULL AND target_user_id IS NULL)
            )
        ");
        
        if (!$stmt) return 0;
        
        $stmt->bind_param("si", $userRole, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return (int)($result['cnt'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Mark notification as read
 */
function markNotificationRead($conn, $notificationId) {
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $notificationId);
        return $stmt->execute();
    } catch (Exception $e) { return false; }
}

/**
 * Mark all notifications as read for current user
 */
function markAllNotificationsRead($conn) {
    try {
        $userRole = $_SESSION['admin_role'] ?? '';
        $userId = $_SESSION['admin_id'] ?? 0;
        
        $stmt = $conn->prepare("
            UPDATE notifications SET is_read = 1, read_at = NOW()
            WHERE is_read = 0 
            AND (
                FIND_IN_SET(?, target_roles) > 0 
                OR target_user_id = ?
            )
        ");
        
        if (!$stmt) return false;
        $stmt->bind_param("si", $userRole, $userId);
        return $stmt->execute();
    } catch (Exception $e) { return false; }
}

// ============================================================
// MEMBER CHANGE LOGGING
// ============================================================

/**
 * Log a member change for department sync
 * 
 * @param mysqli $conn Database connection
 * @param int $memberId Member ID
 * @param string $changeType Type of change
 * @param array $options Optional settings:
 *   - 'field_changed' => specific field name
 *   - 'old_value' => previous value
 *   - 'new_value' => new value
 *   - 'summary' => human-readable summary
 *   - 'requires_sync' => whether other depts need to know
 * @return int|false Change log ID or false on failure
 */
function logMemberChange($conn, $memberId, $changeType, $options = []) {
    try {
        $fieldChanged = $options['field_changed'] ?? null;
        $oldValue = $options['old_value'] ?? null;
        $newValue = $options['new_value'] ?? null;
        $summary = $options['summary'] ?? null;
        $requiresSync = $options['requires_sync'] ?? 1;
        $changedByDept = $_SESSION['admin_role'] ?? null;
        $changedByUser = $_SESSION['admin_id'] ?? null;
        
        $stmt = $conn->prepare("
            INSERT INTO member_changes 
            (member_id, change_type, field_changed, old_value, new_value, change_summary, 
             changed_by_dept, changed_by_user, requires_sync)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) return false;
        
        $stmt->bind_param(
            "issssssis",
            $memberId, $changeType, $fieldChanged, $oldValue, $newValue, 
            $summary, $changedByDept, $changedByUser, $requiresSync
        );
        
        if ($stmt->execute()) {
            return $conn->insert_id;
        }
    } catch (Exception $e) {
        error_log("logMemberChange error: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get recent member changes that require sync
 */
function getUnsyncedChanges($conn, $forDept = null, $limit = 50) {
    try {
        $forDept = $forDept ?? ($_SESSION['admin_role'] ?? '');
        
        $stmt = $conn->prepare("
            SELECT mc.*, m.student_name, m.father_name, m.member_code
            FROM member_changes mc
            LEFT JOIN members m ON mc.member_id = m.id
            WHERE mc.requires_sync = 1
            AND (mc.synced_to IS NULL OR FIND_IN_SET(?, mc.synced_to) = 0)
            AND mc.changed_by_dept != ?
            ORDER BY mc.created_at DESC
            LIMIT ?
        ");
        
        if (!$stmt) return [];
        
        $stmt->bind_param("ssi", $forDept, $forDept, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $changes = [];
        while ($row = $result->fetch_assoc()) {
            $changes[] = $row;
        }
        
        return $changes;
    } catch (Exception $e) { return []; }
}

/**
 * Mark a change as synced for current department
 */
function markChangeSynced($conn, $changeId) {
    try {
        $dept = $_SESSION['admin_role'] ?? '';
        
        $stmt = $conn->prepare("SELECT synced_to FROM member_changes WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $changeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        
        $syncedTo = $row['synced_to'] ?? '';
        $depts = $syncedTo ? explode(',', $syncedTo) : [];
        if (!in_array($dept, $depts)) {
            $depts[] = $dept;
        }
        $newSyncedTo = implode(',', $depts);
        
        $stmt = $conn->prepare("UPDATE member_changes SET synced_to = ? WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("si", $newSyncedTo, $changeId);
        return $stmt->execute();
    } catch (Exception $e) { return false; }
}

// ============================================================
// CROSS-DEPARTMENT TASKS
// ============================================================

/**
 * Create a task for another department
 * 
 * @param mysqli $conn Database connection
 * @param string $title Task title
 * @param array $options Task options:
 *   - 'description' => detailed description
 *   - 'task_type' => 'approval', 'review', 'action', 'info'
 *   - 'priority' => 'low', 'normal', 'high', 'urgent'
 *   - 'to_dept' => target department
 *   - 'to_user_id' => specific user
 *   - 'related_member_id' => member this is about
 *   - 'due_date' => YYYY-MM-DD
 *   - 'related_data' => additional data array
 * @return int|false Task ID or false on failure
 */
function createDepartmentTask($conn, $title, $options = []) {
    try {
        $description = $options['description'] ?? null;
        $taskType = $options['task_type'] ?? 'action';
        $priority = $options['priority'] ?? 'normal';
        $fromDept = $_SESSION['admin_role'] ?? null;
        $fromUserId = $_SESSION['admin_id'] ?? null;
        $toDept = $options['to_dept'] ?? null;
        $toUserId = $options['to_user_id'] ?? null;
        $relatedMemberId = $options['related_member_id'] ?? null;
        $relatedData = isset($options['related_data']) ? json_encode($options['related_data']) : null;
        $dueDate = $options['due_date'] ?? null;
        
        $stmt = $conn->prepare("
            INSERT INTO department_tasks 
            (title, description, task_type, priority, from_dept, from_user_id, 
             to_dept, to_user_id, related_member_id, related_data, due_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) return false;
        
        $stmt->bind_param(
            "sssssisisss",
            $title, $description, $taskType, $priority, $fromDept, $fromUserId,
            $toDept, $toUserId, $relatedMemberId, $relatedData, $dueDate
        );
        
        if ($stmt->execute()) {
            $taskId = $conn->insert_id;
            
            sendNotification($conn, 'task_assigned', "New Task: $title", $description ?: $title, [
                'priority' => $priority,
                'target_roles' => $toDept ? [$toDept] : [],
                'target_user_id' => $toUserId,
                'data' => ['task_id' => $taskId, 'related_member_id' => $relatedMemberId]
            ]);
            
            return $taskId;
        }
    } catch (Exception $e) {
        error_log("createDepartmentTask error: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get pending tasks for current user/department
 */
function getPendingTasks($conn, $limit = 20) {
    try {
        $dept = $_SESSION['admin_role'] ?? '';
        $userId = $_SESSION['admin_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT dt.*, m.student_name, m.father_name, m.member_code,
                   u.full_name as from_user_name
            FROM department_tasks dt
            LEFT JOIN members m ON dt.related_member_id = m.id
            LEFT JOIN users u ON dt.from_user_id = u.id
            WHERE dt.status IN ('pending', 'in_progress')
            AND (dt.to_dept = ? OR dt.to_user_id = ?)
            ORDER BY 
                FIELD(dt.priority, 'urgent', 'high', 'normal', 'low'),
                dt.due_date ASC,
                dt.created_at DESC
            LIMIT ?
        ");
        
        if (!$stmt) return [];
        
        $stmt->bind_param("sii", $dept, $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $row['related_data'] = $row['related_data'] ? json_decode($row['related_data'], true) : null;
            $tasks[] = $row;
        }
        
        return $tasks;
    } catch (Exception $e) { return []; }
}

/**
 * Update task status
 */
function updateTaskStatus($conn, $taskId, $status, $notes = null) {
    try {
        $completedAt = in_array($status, ['completed', 'cancelled']) ? date('Y-m-d H:i:s') : null;
        $completedBy = in_array($status, ['completed', 'cancelled']) ? ($_SESSION['admin_id'] ?? null) : null;
        
        $stmt = $conn->prepare("
            UPDATE department_tasks 
            SET status = ?, notes = CONCAT(IFNULL(notes, ''), ?), 
                completed_at = ?, completed_by = ?
            WHERE id = ?
        ");
        
        if (!$stmt) return false;
        
        $noteText = $notes ? "\n[" . date('Y-m-d H:i') . "] " . ($_SESSION['admin_full_name'] ?? 'System') . ": $notes" : '';
        
        $stmt->bind_param("sssii", $status, $noteText, $completedAt, $completedBy, $taskId);
        return $stmt->execute();
    } catch (Exception $e) { return false; }
}

// ============================================================
// AUTO-UPDATE FUNCTIONS (System Workflows)
// ============================================================

/**
 * When Education Dept assigns a teaching role, auto-update member flags
 */
function autoUpdateTeacherStatus($conn, $memberId, $isTeacher = true) {
    try {
        $flag = $isTeacher ? 1 : 0;
    
    // Get current status
    $stmt = $conn->prepare("SELECT is_teacher FROM members WHERE id = ?");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    
    if ($current && (int)$current['is_teacher'] !== $flag) {
        // Update the flag
        $stmt = $conn->prepare("UPDATE members SET is_teacher = ? WHERE id = ?");
        $stmt->bind_param("ii", $flag, $memberId);
        $stmt->execute();
        
        // Log the change
        $summary = $isTeacher ? 'Member assigned as teacher' : 'Teacher role removed';
        logMemberChange($conn, $memberId, 'role_changed', [
            'field_changed' => 'is_teacher',
            'old_value' => $current['is_teacher'],
            'new_value' => $flag,
            'summary' => $summary
        ]);
        
        // Notify Info Dept
        $stmt = $conn->prepare("SELECT student_name, father_name FROM members WHERE id = ?");
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $memberName = $member['student_name'] . ' ' . $member['father_name'];
        
        sendNotification($conn, 'role_changed', 
            ($isTeacher ? "New Teacher Assigned" : "Teacher Role Removed"),
            "$memberName has been " . ($isTeacher ? "assigned as a teacher" : "removed from teaching role"),
            [
                'data' => ['member_id' => $memberId],
                'target_roles' => ['info_dept', 'school_admin']
            ]
        );
        
        return true;
    }
    
    return false;
    } catch (Exception $e) { return false; }
}

/**
 * When a member is enrolled in a class, update their class info
 */
function autoUpdateMemberClass($conn, $memberId, $classId, $academicYearId) {
    try {
    // Get class info
    $stmt = $conn->prepare("SELECT class_code, class_name FROM classes WHERE id = ?");
    $stmt->bind_param("i", $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
    
    if (!$class) return false;
    
    // Update member
    $stmt = $conn->prepare("
        UPDATE members 
        SET current_class_id = ?, spiritual_education = ?, academic_status = 'active'
        WHERE id = ?
    ");
    $stmt->bind_param("isi", $classId, $class['class_code'], $memberId);
    $stmt->execute();
    
    // Log the change
    logMemberChange($conn, $memberId, 'class_enrolled', [
        'field_changed' => 'current_class_id',
        'new_value' => $classId,
        'summary' => "Enrolled in {$class['class_name']}"
    ]);
    
    return true;
    } catch (Exception $e) { return false; }
}

/**
 * Update attendance summary after recording attendance
 */
function updateAttendanceSummary($conn, $memberId, $academicYearId = null) {
    try {
    // IMPORTANT: attendance_date is stored as a normal (Gregorian) date, and
    // the other copy of this routine in api_attendance.php also keys the
    // summary table by Gregorian month/year. We MUST match it — using the
    // Ethiopian year here would both (a) look up the wrong date range and
    // (b) write a second, conflicting row for the same real month. Keep both
    // functions on Gregorian so the (member_id, year, month) key never clashes.
    $gcMonth = (int)date('n');
    $gcYear  = (int)date('Y');

    // Calculate summary for the current Gregorian month
    $startOfMonth = date('Y-m-01');
    $endOfMonth   = date('Y-m-t');
    
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_days,
            SUM(status = 'present') as present_days,
            SUM(status = 'absent') as absent_days,
            SUM(status = 'late') as late_days,
            SUM(status = 'excused') as excused_days
        FROM attendance
        WHERE member_id = ?
        AND attendance_date BETWEEN ? AND ?
    ");
    
    $stmt->bind_param("iss", $memberId, $startOfMonth, $endOfMonth);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    $totalDays = (int)$stats['total_days'];
    $presentDays = (int)$stats['present_days'];
    $attendanceRate = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : null;
    
    // Upsert summary
    $stmt = $conn->prepare("
        INSERT INTO attendance_summary 
        (member_id, academic_year_id, month, year, total_days, present_days, absent_days, late_days, excused_days, attendance_rate)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            total_days = VALUES(total_days),
            present_days = VALUES(present_days),
            absent_days = VALUES(absent_days),
            late_days = VALUES(late_days),
            excused_days = VALUES(excused_days),
            attendance_rate = VALUES(attendance_rate)
    ");
    
    $stmt->bind_param(
        "iiiiiiiid",
        $memberId, $academicYearId, $gcMonth, $gcYear,
        $totalDays, $presentDays, $stats['absent_days'], $stats['late_days'], $stats['excused_days'],
        $attendanceRate
    );
    $stmt->execute();
    
    // Update member's overall attendance rate
    $stmt = $conn->prepare("
        SELECT AVG(attendance_rate) as avg_rate
        FROM attendance_summary
        WHERE member_id = ? AND attendance_rate IS NOT NULL
    ");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $avg = $stmt->get_result()->fetch_assoc();
    
    if ($avg && $avg['avg_rate'] !== null) {
        $stmt = $conn->prepare("UPDATE members SET total_attendance_rate = ?, last_attendance_date = CURDATE() WHERE id = ?");
        $avgRate = round($avg['avg_rate'], 2);
        $stmt->bind_param("di", $avgRate, $memberId);
        $stmt->execute();
    }
    
    // Check for attendance issues
    if ($attendanceRate !== null && $attendanceRate < 70) {
        $stmt = $conn->prepare("SELECT student_name, father_name FROM members WHERE id = ?");
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        
        sendNotification($conn, 'attendance_issue',
            "Low Attendance Alert",
            "{$member['student_name']} {$member['father_name']} has {$attendanceRate}% attendance this month",
            [
                'priority' => 'high',
                'data' => ['member_id' => $memberId, 'attendance_rate' => $attendanceRate]
            ]
        );
    }
    
    return true;
    } catch (Exception $e) { return false; }
}

/**
 * Handle new member registration - notify all relevant departments
 */
function onMemberRegistered($conn, $memberId) {
    try {
    $stmt = $conn->prepare("SELECT student_name, father_name, member_code, registration_type FROM members WHERE id = ?");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    
    if (!$member) return false;
    
    $memberName = $member['student_name'] . ' ' . $member['father_name'];
    $regType = $member['registration_type'];
    
    // Log the change
    logMemberChange($conn, $memberId, 'registered', [
        'summary' => "New member registered: $memberName"
    ]);
    
    // Send notification
    sendNotification($conn, 'member_registered',
        "New Member Registered",
        "$memberName has been registered (Type: $regType)",
        [
            'data' => [
                'member_id' => $memberId,
                'member_code' => $member['member_code'],
                'registration_type' => $regType
            ]
        ]
    );
    
    // If direct or transfer registration, create task for Education Dept to assign class
    if ($regType === 'direct' || $regType === 'transfer') {
        createDepartmentTask($conn, "Assign class for new member: $memberName", [
            'description' => "New member $memberName needs to be assigned to a class based on their age and spiritual education level.",
            'task_type' => 'action',
            'priority' => 'normal',
            'to_dept' => 'edu_dept',
            'related_member_id' => $memberId
        ]);
    }
    
    return true;
    } catch (Exception $e) { return false; }
}

/**
 * Handle member archival
 */
function onMemberArchived($conn, $memberId, $reason = null) {
    try {
    $stmt = $conn->prepare("SELECT student_name, father_name, member_code FROM members WHERE id = ?");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    
    if (!$member) return false;
    
    $memberName = $member['student_name'] . ' ' . $member['father_name'];
    
    logMemberChange($conn, $memberId, 'archived', [
        'summary' => "Member archived: $memberName" . ($reason ? " (Reason: $reason)" : "")
    ]);
    
    sendNotification($conn, 'member_archived',
        "Member Archived",
        "$memberName has been moved to archive" . ($reason ? " (Reason: $reason)" : ""),
        [
            'priority' => 'normal',
            'data' => ['member_id' => $memberId, 'reason' => $reason]
        ]
    );
    
    return true;
    } catch (Exception $e) { return false; }
}

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

/**
 * Get current academic year
 */
function getCurrentAcademicYear($conn) {
    // Delegates to the central resolver — the ACTIVE year (used for stamping).
    if (function_exists('ay_active_year')) return ay_active_year($conn);
    try {
        $result = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    } catch (Exception $e) { return null; }
}

/**
 * Get current term
 */
function getCurrentTerm($conn) {
    try {
        $result = $conn->query("SELECT * FROM academic_terms WHERE is_current = 1 LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    } catch (Exception $e) { return null; }
}

/**
 * Calculate grade letter from score
 */
function calculateGradeLetter($score, $maxScore = 100) {
    if ($score === null || $maxScore == 0) return null;
    
    $percentage = ($score / $maxScore) * 100;
    
    if ($percentage >= 90) return 'A';
    if ($percentage >= 80) return 'B';
    if ($percentage >= 70) return 'C';
    if ($percentage >= 60) return 'D';
    return 'F';
}

/**
 * Get department display name
 */
function getDeptDisplayName($roleCode) {
    global $DEPT_ROLES;
    return $DEPT_ROLES[$roleCode] ?? ucfirst(str_replace('_', ' ', $roleCode));
}
