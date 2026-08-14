<?php
/**
 * ============================================================
 * Teacher Management API
 * ============================================================
 * Complete CRUD for teachers:
 * - Create teacher accounts
 * - Edit teacher info
 * - Assign classes & subjects
 * - Deactivate/delete teachers
 * - Get teacher assignments
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/member_sync.php';

// Check authentication
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Only edu_dept, school_admin, super_admin can manage teachers
$allowedRoles = ['super_admin', 'school_admin', 'edu_dept'];
$currentRole = $_SESSION['admin_role'] ?? '';
$isTeacher = $currentRole === 'teacher';

// Teachers can only access their own data
if (!$isTeacher && !in_array($currentRole, $allowedRoles)) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
    exit;
}

// Validate CSRF for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

$action = $_REQUEST['action'] ?? '';

// Safe column check helper
function _teacherSafeColExists($conn, $table, $col) {
    try { $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'"); return $r && $r->num_rows > 0; }
    catch (Exception $e) { return false; }
}

// Ensure users table has needed columns
try {
    if (!_teacherSafeColExists($conn, 'users', 'member_id')) {
        $conn->query("ALTER TABLE `users` ADD COLUMN `member_id` INT UNSIGNED DEFAULT NULL AFTER `is_active`");
    }
    if (!_teacherSafeColExists($conn, 'users', 'last_login')) {
        $conn->query("ALTER TABLE `users` ADD COLUMN `last_login` DATETIME DEFAULT NULL AFTER `is_active`");
    }
    // Ensure role column can hold 'teacher' value
    $conn->query("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'info_dept'");
} catch (Exception $e) { /* non-critical */ }

// Get current academic year (table may not exist yet)
// Effective academic year — single source of truth (resolver, time-travel aware)
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

// ── Auto-fix teacher_assignments table ──
try {
    $r = $conn->query("SHOW TABLES LIKE 'teacher_assignments'");
    if ($r && $r->num_rows > 0) {
        // Drop FK that wrongly references members instead of users
        try {
            $fks = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_assignments' 
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
            if ($fks) {
                while ($fk = $fks->fetch_assoc()) {
                    $conn->query("ALTER TABLE `teacher_assignments` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
                }
            }
        } catch (Exception $e) {}
        // Make academic_year_id nullable
        try { $conn->query("ALTER TABLE `teacher_assignments` MODIFY `academic_year_id` INT UNSIGNED DEFAULT NULL"); } catch (Exception $e) {}
        // Add is_active if missing
        if (!_teacherSafeColExists($conn, 'teacher_assignments', 'is_active')) {
            $conn->query("ALTER TABLE `teacher_assignments` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
        }
        // Add is_primary if missing
        if (!_teacherSafeColExists($conn, 'teacher_assignments', 'is_primary')) {
            $conn->query("ALTER TABLE `teacher_assignments` ADD COLUMN `is_primary` TINYINT(1) NOT NULL DEFAULT 0");
        }
    } else {
        // Create table from scratch without FK constraints
        $conn->query("CREATE TABLE IF NOT EXISTS `teacher_assignments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `teacher_id` INT UNSIGNED NOT NULL COMMENT 'users.id of the teacher',
            `class_id` INT UNSIGNED NOT NULL,
            `subject_id` INT UNSIGNED NOT NULL,
            `academic_year_id` INT UNSIGNED DEFAULT NULL,
            `is_class_teacher` TINYINT(1) NOT NULL DEFAULT 0,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `assigned_by` INT UNSIGNED DEFAULT NULL,
            `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `teacher_id` (`teacher_id`),
            KEY `class_id` (`class_id`),
            KEY `subject_id` (`subject_id`),
            KEY `academic_year_id` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) { /* non-critical */ }

try {
switch ($action) {

    // ============================================================
    // GET ALL TEACHERS
    // ============================================================
    case 'get_teachers':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
        
        // Check if teacher_assignments table exists
        $hasTaTable = false;
        try {
            $r = $conn->query("SHOW TABLES LIKE 'teacher_assignments'");
            $hasTaTable = $r && $r->num_rows > 0;
        } catch (Exception $e) {}
        
        // Check if member_id and last_login columns exist
        $hasMemberId = _teacherSafeColExists($conn, 'users', 'member_id');
        $hasLastLogin = _teacherSafeColExists($conn, 'users', 'last_login');
        
        $memberIdCol = $hasMemberId ? "u.member_id," : "";
        $lastLoginCol = $hasLastLogin ? "u.last_login," : "";
        $memberJoin = $hasMemberId ? "LEFT JOIN members m ON u.member_id = m.id" : "";
        $memberCols = $hasMemberId ? "m.student_name as member_name, m.father_name as member_father,
                    m.phone_number as member_phone, m.member_code," : "";
        
        if ($hasTaTable) {
            $assignedClasses = "(SELECT COUNT(DISTINCT ta.class_id) FROM teacher_assignments ta 
                     WHERE ta.teacher_id = u.id AND ta.is_active = 1) as assigned_classes,";
            $assignedSubjects = "(SELECT COUNT(DISTINCT ta.subject_id) FROM teacher_assignments ta 
                     WHERE ta.teacher_id = u.id AND ta.is_active = 1) as assigned_subjects";
        } else {
            $assignedClasses = "0 as assigned_classes,";
            $assignedSubjects = "0 as assigned_subjects";
        }
        
        $sql = "SELECT 
                    u.id, u.username, u.email, u.full_name, u.is_active, 
                    $memberIdCol u.created_at, $lastLoginCol
                    $memberCols
                    $assignedClasses
                    $assignedSubjects
                FROM users u
                $memberJoin
                WHERE u.role = 'teacher'";
        
        if (!$includeInactive) {
            $sql .= " AND u.is_active = 1";
        }
        $sql .= " ORDER BY u.full_name";
        
        try {
            $result = $conn->query($sql);
            $teachers = [];
            while ($row = $result->fetch_assoc()) {
                $teachers[] = $row;
            }
            echo json_encode(['status' => 'success', 'teachers' => $teachers]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Query failed: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // GET SINGLE TEACHER DETAILS
    // ============================================================
    case 'get_teacher':
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        
        // Teachers can only view their own profile
        if ($isTeacher && $teacherId != $_SESSION['admin_id']) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        if (!$teacherId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID required']);
            exit;
        }
        
        // Get teacher info (use safe column references)
        $hasMemberId = _teacherSafeColExists($conn, 'users', 'member_id');
        $memberIdRef = $hasMemberId ? "u.member_id," : "";
        $memberJoinRef = $hasMemberId ? "LEFT JOIN members m ON u.member_id = m.id" : "";
        $memberColsRef = $hasMemberId ? "m.student_name as member_name, m.father_name as member_father,
                   m.phone_number, m.member_code" : "NULL as member_name, NULL as member_father,
                   NULL as phone_number, NULL as member_code";
        
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.email, u.full_name, u.is_active, $memberIdRef u.created_at,
                   $memberColsRef
            FROM users u
            $memberJoinRef
            WHERE u.id = ? AND u.role = 'teacher'
        ");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        $teacher = $stmt->get_result()->fetch_assoc();
        
        if (!$teacher) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
            exit;
        }
        
        // Get assignments
        $yearId = $currentYear ? $currentYear['id'] : 0;
        $stmt = $conn->prepare("
            SELECT ta.id, ta.class_id, ta.subject_id, ta.is_primary,
                   c.class_name, c.class_name_en, c.level_order,
                   s.subject_name, s.subject_name_en,
                   (SELECT COUNT(*) FROM class_enrollments ce 
                    WHERE ce.class_id = ta.class_id AND ce.status = 'active'
                    AND (ce.academic_year_id = ? OR ? = 0)) as student_count
            FROM teacher_assignments ta
            JOIN classes c ON ta.class_id = c.id
            JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $teacherId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
        
        $teacher['assignments'] = $assignments;
        
        echo json_encode(['status' => 'success', 'teacher' => $teacher]);
        break;

    // ============================================================
    // CREATE TEACHER
    // ============================================================
    case 'create_teacher':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
        
        // Validation
        if (empty($fullName) || empty($username) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Full name, username, and password are required']);
            exit;
        }
        
        if (strlen($password) < 4) {
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 4 characters']);
            exit;
        }
        
        // Check if username exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }
        
        // Check if email exists (if provided)
        if (!empty($email)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
                exit;
            }
        }
        
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert teacher
        $stmt = $conn->prepare("
            INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)
            VALUES (?, ?, ?, 'teacher', ?, 1, ?)
        ");
        $emailDb = !empty($email) ? $email : null;
        $stmt->bind_param("ssssi", $username, $emailDb, $fullName, $passwordHash, $memberId);
        
        if ($stmt->execute()) {
            $newTeacherId = $conn->insert_id;
            
            // If linked to member, mark member as teacher AND sync member_type
            if ($memberId) {
                syncMemberTeacherFlag($conn, $memberId, true);
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Teacher account created successfully!',
                'teacher_id' => $newTeacherId
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        }
        break;

    // ============================================================
    // UPDATE TEACHER
    // ============================================================
    case 'update_teacher':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
        $newPassword = $_POST['new_password'] ?? '';
        
        if (!$teacherId || empty($fullName) || empty($username)) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID, full name, and username required']);
            exit;
        }
        
        // Get current teacher data
        $stmt = $conn->prepare("SELECT member_id, username FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        $currentTeacher = $stmt->get_result()->fetch_assoc();
        
        if (!$currentTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
            exit;
        }
        
        // Check username uniqueness (if changed)
        if ($username !== $currentTeacher['username']) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->bind_param("si", $username, $teacherId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Username already in use']);
                exit;
            }
        }
        
        // Check email uniqueness (if provided and changed)
        if (!empty($email)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $email, $teacherId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Email already in use']);
                exit;
            }
        }
        
        // Build update query
        $emailDb = !empty($email) ? $email : null;
        
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 4) {
                echo json_encode(['status' => 'error', 'message' => 'Password must be at least 4 characters']);
                exit;
            }
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, member_id = ?, password_hash = ? WHERE id = ?");
            $stmt->bind_param("sssisi", $fullName, $username, $emailDb, $memberId, $passwordHash, $teacherId);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, member_id = ? WHERE id = ?");
            $stmt->bind_param("sssii", $fullName, $username, $emailDb, $memberId, $teacherId);
        }
        
        if ($stmt->execute()) {
            // Update member is_teacher flags AND sync member_type
            $oldMemberId = $currentTeacher['member_id'];
            
            // Remove old member's teacher flag if changed
            if ($oldMemberId && $oldMemberId != $memberId) {
                syncMemberTeacherFlag($conn, (int)$oldMemberId, false);
            }
            
            // Set new member's teacher flag and sync type
            if ($memberId) {
                syncMemberTeacherFlag($conn, $memberId, true);
            }
            
            echo json_encode(['status' => 'success', 'message' => 'Teacher updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;

    // ============================================================
    // TOGGLE TEACHER STATUS (Activate/Deactivate)
    // ============================================================
    case 'toggle_status':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        
        if (!$teacherId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID required']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        $teacher = $stmt->get_result()->fetch_assoc();
        
        if (!$teacher) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
            exit;
        }
        
        $newStatus = $teacher['is_active'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $teacherId);
        
        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => $newStatus ? 'Teacher activated' : 'Teacher deactivated',
                'new_status' => $newStatus
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;

    // ============================================================
    // DELETE TEACHER
    // ============================================================
    case 'delete_teacher':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        
        if (!$teacherId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID required']);
            exit;
        }
        
        // Get member_id before deletion
        $stmt = $conn->prepare("SELECT member_id FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        $teacher = $stmt->get_result()->fetch_assoc();
        
        if (!$teacher) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher not found']);
            exit;
        }
        
        // Delete assignments first
        $stmt = $conn->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ?");
        $stmt->bind_param("i", $teacherId);
        $stmt->execute();
        
        // Delete user
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $teacherId);
        
        if ($stmt->execute()) {
            // Sync member is_teacher flag and member_type
            if ($teacher['member_id']) {
                syncMemberTeacherFlag($conn, (int)$teacher['member_id'], false);
            }
            
            echo json_encode(['status' => 'success', 'message' => 'Teacher deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;

    // ============================================================
    // GET TEACHER ASSIGNMENTS
    // ============================================================
    case 'get_assignments':
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        
        // Teachers can only view their own assignments
        if ($isTeacher && $teacherId != $_SESSION['admin_id']) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        if (!$teacherId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher ID required']);
            exit;
        }
        
        $yearId = $currentYear ? $currentYear['id'] : 0;
        
        $stmt = $conn->prepare("
            SELECT 
                ta.id, ta.class_id, ta.subject_id, ta.is_primary,
                c.class_name, c.class_name_en, c.level_order,
                s.subject_name, s.subject_name_en,
                (SELECT COUNT(*) FROM class_enrollments ce 
                 WHERE ce.class_id = ta.class_id AND ce.status = 'active'
                 AND (ce.academic_year_id = ? OR ? = 0)) as student_count
            FROM teacher_assignments ta
            JOIN classes c ON ta.class_id = c.id
            JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $teacherId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'assignments' => $assignments]);
        break;

    // ============================================================
    // ASSIGN CLASS & SUBJECT TO TEACHER
    // ============================================================
    case 'add_assignment':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $isPrimary = isset($_POST['is_primary']) ? (int)$_POST['is_primary'] : 0;
        
        if (!$teacherId || !$classId || !$subjectId) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher, class, and subject are required']);
            exit;
        }
        
        $yearId = $currentYear ? $currentYear['id'] : null;
        
        // Check if assignment already exists
        $stmt = $conn->prepare("
            SELECT id FROM teacher_assignments 
            WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND is_active = 1
        ");
        $stmt->bind_param("iii", $teacherId, $classId, $subjectId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'This assignment already exists']);
            exit;
        }
        
        // Insert assignment — handle NULL academic_year_id properly
        $assignedBy = $_SESSION['admin_id'];
        if ($yearId) {
            $stmt = $conn->prepare("
                INSERT INTO teacher_assignments 
                (teacher_id, class_id, subject_id, academic_year_id, is_primary, is_active, assigned_by)
                VALUES (?, ?, ?, ?, ?, 1, ?)
            ");
            $stmt->bind_param("iiiiii", $teacherId, $classId, $subjectId, $yearId, $isPrimary, $assignedBy);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO teacher_assignments 
                (teacher_id, class_id, subject_id, academic_year_id, is_primary, is_active, assigned_by)
                VALUES (?, ?, ?, NULL, ?, 1, ?)
            ");
            $stmt->bind_param("iiiii", $teacherId, $classId, $subjectId, $isPrimary, $assignedBy);
        }
        
        if ($stmt->execute()) {
            // Get class and subject names for response
            $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
            $stmt->bind_param("i", $classId);
            $stmt->execute();
            $className = $stmt->get_result()->fetch_assoc()['class_name'] ?? '';
            
            $stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE id = ?");
            $stmt->bind_param("i", $subjectId);
            $stmt->execute();
            $subjectName = $stmt->get_result()->fetch_assoc()['subject_name'] ?? '';
            
            echo json_encode([
                'status' => 'success',
                'message' => "Assigned: $className - $subjectName",
                'assignment_id' => $conn->insert_id
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        }
        break;

    // ============================================================
    // REMOVE ASSIGNMENT
    // ============================================================
    case 'remove_assignment':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        
        if (!$assignmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Assignment ID required']);
            exit;
        }
        
        // Soft delete - just deactivate
        $stmt = $conn->prepare("UPDATE teacher_assignments SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $assignmentId);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Assignment removed']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;

    // ============================================================
    // GET AVAILABLE CLASSES & SUBJECTS FOR ASSIGNMENT
    // ============================================================
    case 'get_available_for_assignment':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        
        // Get all active classes
        $classes = [];
        $result = $conn->query("SELECT id, class_name, class_name_en FROM classes WHERE is_active = 1 ORDER BY level_order");
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
        
        // Get all active subjects
        $subjects = [];
        $result = $conn->query("SELECT id, subject_name, subject_name_en FROM subjects WHERE is_active = 1 ORDER BY subject_name");
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
        
        // Get existing assignments for this teacher (to disable already assigned)
        $existing = [];
        if ($teacherId) {
            $stmt = $conn->prepare("
                SELECT CONCAT(class_id, '-', subject_id) as combo 
                FROM teacher_assignments 
                WHERE teacher_id = ? AND is_active = 1
            ");
            $stmt->bind_param("i", $teacherId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $existing[] = $row['combo'];
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'classes' => $classes,
            'subjects' => $subjects,
            'existing' => $existing
        ]);
        break;

    // ============================================================
    // GET MY ASSIGNMENTS (For Teacher Dashboard)
    // ============================================================
    case 'get_my_assignments':
        $userId = $_SESSION['admin_id'];
        $role = $_SESSION['admin_role'] ?? '';
        
        if ($role !== 'teacher') {
            echo json_encode(['status' => 'error', 'message' => 'Not a teacher account']);
            exit;
        }
        
        $yearId = $currentYear ? $currentYear['id'] : 0;
        
        $stmt = $conn->prepare("
            SELECT 
                ta.id, ta.class_id, ta.subject_id, ta.is_primary,
                c.class_name, c.class_name_en, c.level_order,
                s.subject_name, s.subject_name_en,
                (SELECT COUNT(*) FROM class_enrollments ce 
                 WHERE ce.class_id = ta.class_id AND ce.status = 'active'
                 AND (ce.academic_year_id = ? OR ? = 0)) as student_count
            FROM teacher_assignments ta
            JOIN classes c ON ta.class_id = c.id
            JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
        
        echo json_encode([
            'status' => 'success',
            'assignments' => $assignments,
            'academic_year' => $currentYear
        ]);
        break;

    // ============================================================
    // GET MEMBERS FOR LINKING (Teachers who are also members)
    // ============================================================
    case 'get_members_for_linking':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        // Get adult members who could be teachers
        $result = $conn->query("
            SELECT id, member_code, student_name, father_name, grandfather_name, phone_number
            FROM members 
            WHERE status = 'active' 
            AND (age_group = '18+' OR age_group IS NULL OR TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 18)
            ORDER BY student_name
            LIMIT 500
        ");
        
        $members = [];
        while ($row = $result->fetch_assoc()) {
            $members[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'members' => $members]);
        break;

    // ============================================================
    // DEBUG: Check teacher_assignments table health
    // ============================================================
    case 'debug_assignments':
        $debug = ['table_exists' => false, 'columns' => [], 'foreign_keys' => [], 'row_count' => 0, 'current_year' => $currentYear ? $currentYear['id'] : null];
        try {
            $r = $conn->query("SHOW TABLES LIKE 'teacher_assignments'");
            $debug['table_exists'] = $r && $r->num_rows > 0;
            if ($debug['table_exists']) {
                $cols = $conn->query("SHOW COLUMNS FROM teacher_assignments");
                while ($c = $cols->fetch_assoc()) $debug['columns'][] = $c['Field'] . ' (' . $c['Type'] . ')' . ($c['Null'] === 'YES' ? ' NULL' : ' NOT NULL');
                $fks = $conn->query("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_assignments' AND REFERENCED_TABLE_NAME IS NOT NULL");
                if ($fks) while ($fk = $fks->fetch_assoc()) $debug['foreign_keys'][] = $fk;
                $cnt = $conn->query("SELECT COUNT(*) c FROM teacher_assignments");
                if ($cnt) $debug['row_count'] = (int)$cnt->fetch_assoc()['c'];
                // Try a test insert to see what error we get
                $debug['test_insert'] = 'skipped';
            }
        } catch (Exception $e) { $debug['error'] = $e->getMessage(); }
        echo json_encode(['status' => 'success', 'debug' => $debug]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
}
} catch (Exception $e) {
    error_log("api_teachers error [{$action}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}

$conn->close();
