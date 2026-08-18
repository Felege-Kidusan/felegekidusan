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
require_once __DIR__ . '/backend/services/AssignmentService.php';

use App\Services\AssignmentService;

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

// Effective academic year — single source of truth (resolver, time-travel aware)
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

// Schema is owned by AssignmentService + sql/006 (no ALTER on every request).
AssignmentService::ensureSchema($conn);

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
        $q = trim((string)($_GET['q'] ?? ''));
        $bind = [];
        $types = '';
        if ($q !== '') {
            $sql .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?";
            $st = '%' . $q . '%';
            $bind = [$st, $st, $st];
            $types = 'sss';
            if ($hasMemberId) {
                $sql .= " OR m.member_code LIKE ? OR m.student_name LIKE ?";
                $bind[] = $st;
                $bind[] = $st;
                $types .= 'ss';
            }
            $sql .= ")";
        }
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 100)));
        $sql .= " ORDER BY u.full_name LIMIT " . $limit;
        
        try {
            if ($types !== '') {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$bind);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $conn->query($sql);
            }
            $teachers = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $teachers[] = $row;
                }
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
            SELECT ta.id, ta.class_id, ta.subject_id, ta.is_primary, ta.is_class_teacher,
                   c.class_name, c.class_name_en, c.level_order,
                   s.subject_name, s.subject_name_en,
                   (SELECT COUNT(*) FROM class_enrollments ce 
                    WHERE ce.class_id = ta.class_id AND ce.status = 'active'
                    AND (ce.academic_year_id = ? OR ? = 0)) as student_count
            FROM teacher_assignments ta
            JOIN classes c ON ta.class_id = c.id
            LEFT JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $teacherId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            if (empty($row['subject_name']) && !empty($row['is_class_teacher'])) {
                $row['subject_name'] = 'Class Teacher';
            }
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
            LEFT JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $teacherId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            if (empty($row['subject_name']) && !empty($row['is_class_teacher'])) {
                $row['subject_name'] = 'Class Teacher';
            }
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
        if (function_exists('ay_require_writable')) {
            ay_require_writable($conn);
        }
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
        $isPrimary = isset($_POST['is_primary']) ? (int)$_POST['is_primary'] : 1;
        $isClassTeacher = !empty($_POST['is_class_teacher']);
        $asgRole = $isClassTeacher && !$subjectId ? 'homeroom' : ($isPrimary ? 'primary' : 'assistant');
        echo json_encode(AssignmentService::assign(
            $conn, $teacherId, $classId, $subjectId, $asgRole, null, (int)$_SESSION['admin_id']
        ), JSON_UNESCAPED_UNICODE);
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
        
        if (function_exists('ay_require_writable')) {
            ay_require_writable($conn);
        }
        echo json_encode(AssignmentService::unassign($conn, $assignmentId), JSON_UNESCAPED_UNICODE);
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
            LEFT JOIN subjects s ON ta.subject_id = s.id
            WHERE ta.teacher_id = ? AND ta.is_active = 1
            ORDER BY c.level_order, s.subject_name
        ");
        $stmt->bind_param("iii", $yearId, $yearId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            if (empty($row['subject_name']) && !empty($row['is_class_teacher'])) {
                $row['subject_name'] = 'Class Teacher';
            }
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
    // SEARCH MEMBERS TO LINK (name + code only — no phone/address)
    // ============================================================
    case 'search_members_for_teacher':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if (strlen($q) < 1) {
            echo json_encode(['status' => 'success', 'members' => []]);
            break;
        }
        $st = '%' . $q . '%';
        $limit = 15;
        $stmt = $conn->prepare(
            "SELECT id, member_code, student_name, father_name
             FROM members
             WHERE status = 'active'
               AND (student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR baptismal_name LIKE ?)
             ORDER BY student_name
             LIMIT ?"
        );
        if (!$stmt) {
            echo json_encode(['status' => 'success', 'members' => []]);
            break;
        }
        $stmt->bind_param('ssssi', $st, $st, $st, $st, $limit);
        $stmt->execute();
        $members = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $members[] = [
                'id' => (int)$row['id'],
                'member_code' => $row['member_code'] ?? '',
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
            ];
        }
        $stmt->close();
        echo json_encode(['status' => 'success', 'members' => $members], JSON_UNESCAPED_UNICODE);
        break;

    // ============================================================
    // SAVE TEACHER + LOGIN + ASSIGNMENTS IN ONE REQUEST
    // ============================================================
    case 'save_teacher_bundle':
        if ($isTeacher) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }

        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
        $dutiesRaw = $_POST['duties'] ?? '[]';
        $duties = is_array($dutiesRaw) ? $dutiesRaw : (json_decode((string)$dutiesRaw, true) ?: []);

        if ($fullName === '' || $username === '') {
            echo json_encode(['status' => 'error', 'message' => 'Full name and username are required.']);
            exit;
        }
        if ($teacherId <= 0 && strlen($password) < 4) {
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 4 characters so the teacher can log in.']);
            exit;
        }
        if ($teacherId > 0 && $password !== '' && strlen($password) < 4) {
            echo json_encode(['status' => 'error', 'message' => 'New password must be at least 4 characters.']);
            exit;
        }

        if ($memberId) {
            $mchk = $conn->prepare("SELECT id, student_name, status FROM members WHERE id = ? LIMIT 1");
            if ($mchk) {
                $mchk->bind_param('i', $memberId);
                $mchk->execute();
                $mrow = $mchk->get_result()->fetch_assoc();
                $mchk->close();
                if (!$mrow || ($mrow['status'] ?? '') === 'archived') {
                    echo json_encode(['status' => 'error', 'message' => 'That member was not found.']);
                    exit;
                }
                if ($fullName === '') {
                    $fullName = (string)$mrow['student_name'];
                }
            }
        }

        $unameChk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $otherId = $teacherId ?: 0;
        $unameChk->bind_param('si', $username, $otherId);
        $unameChk->execute();
        if ($unameChk->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'That username is already used. Choose another.']);
            exit;
        }
        $unameChk->close();

        if ($email !== '') {
            $emChk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $emChk->bind_param('si', $email, $otherId);
            $emChk->execute();
            if ($emChk->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'That email is already used.']);
                exit;
            }
            $emChk->close();
        }

        $emailDb = $email !== '' ? $email : null;
        $created = false;

        if ($teacherId > 0) {
            $cur = $conn->prepare("SELECT id, member_id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            $cur->bind_param('i', $teacherId);
            $cur->execute();
            $current = $cur->get_result()->fetch_assoc();
            $cur->close();
            if (!$current) {
                echo json_encode(['status' => 'error', 'message' => 'Teacher not found.']);
                exit;
            }
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, member_id = ?, password_hash = ? WHERE id = ?");
                $stmt->bind_param('sssisi', $fullName, $username, $emailDb, $memberId, $hash, $teacherId);
            } else {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, member_id = ? WHERE id = ?");
                $stmt->bind_param('sssii', $fullName, $username, $emailDb, $memberId, $teacherId);
            }
            if (!$stmt->execute()) {
                echo json_encode(['status' => 'error', 'message' => 'Could not update teacher login.']);
                exit;
            }
            $stmt->close();
            $oldMid = (int)($current['member_id'] ?? 0);
            if ($oldMid && $oldMid !== (int)$memberId) {
                syncMemberTeacherFlag($conn, $oldMid, false);
            }
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)
                 VALUES (?, ?, ?, 'teacher', ?, 1, ?)"
            );
            $stmt->bind_param('ssssi', $username, $emailDb, $fullName, $hash, $memberId);
            if (!$stmt->execute()) {
                echo json_encode(['status' => 'error', 'message' => 'Could not create teacher login.']);
                exit;
            }
            $teacherId = (int)$conn->insert_id;
            $stmt->close();
            $created = true;
        }

        if ($memberId) {
            syncMemberTeacherFlag($conn, $memberId, true);
        }

        $assigned = 0;
        $assignErrors = [];
        if ($duties && function_exists('ay_require_writable')) {
            $okYear = ay_require_writable($conn, true);
            if ($okYear === false) {
                $assignErrors[] = 'Teacher login was saved. Assignments were skipped because there is no writable academic year.';
                $duties = [];
            }
        }
        foreach ($duties as $duty) {
            if (!is_array($duty)) {
                continue;
            }
            $kind = strtolower(trim((string)($duty['type'] ?? 'regular')));
            $classIds = $duty['class_ids'] ?? [];
            if (!is_array($classIds)) {
                $classIds = [];
            }
            $classIds = array_values(array_unique(array_filter(array_map('intval', $classIds))));
            if (!$classIds) {
                continue;
            }
            if ($kind === 'homeroom') {
                foreach ($classIds as $cid) {
                    $res = AssignmentService::setHomeroom($conn, $teacherId, $cid, null, (int)$_SESSION['admin_id']);
                    if (($res['status'] ?? '') === 'success') {
                        $assigned++;
                    } else {
                        $assignErrors[] = $res['message'] ?? 'Could not set Class Teacher.';
                    }
                }
            } else {
                $subjectId = !empty($duty['subject_id']) ? (int)$duty['subject_id'] : null;
                $role = (string)($duty['role'] ?? 'primary');
                $res = AssignmentService::assignBulk($conn, $teacherId, $classIds, $subjectId, $role, null, (int)$_SESSION['admin_id']);
                $assigned += (int)($res['assigned'] ?? 0);
                if (($res['status'] ?? '') !== 'success' && empty($res['assigned'])) {
                    $assignErrors[] = $res['message'] ?? 'Could not assign classes.';
                }
            }
        }

        $msg = $created
            ? 'Teacher login created. They can sign in with username “' . $username . '”.'
            : 'Teacher updated.';
        if ($assigned) {
            $msg .= ' ' . $assigned . ' class assignment(s) saved.';
        }
        if ($assignErrors) {
            $msg .= ' ' . $assignErrors[0];
        }

        echo json_encode([
            'status' => 'success',
            'message' => $msg,
            'teacher_id' => $teacherId,
            'created' => $created,
            'assigned' => $assigned,
        ], JSON_UNESCAPED_UNICODE);
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
