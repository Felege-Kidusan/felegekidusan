<?php
/**
 * Education Department API
 * Handles enrollments, teacher assignments, grades, and attendance
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/workflow.php';
require_once __DIR__ . '/backend/member_sync.php';
require_once __DIR__ . '/backend/services/EnrollmentService.php';
require_once __DIR__ . '/backend/services/AssignmentService.php';
require_once __DIR__ . '/backend/services/AttendanceRecordService.php';

use App\Services\AssignmentService;
use App\Services\AttendanceRecordService;
use App\Services\EnrollmentService;

// Check authentication
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
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

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)$_REQUEST['action'] : '';
$__featureActions = [
    'grades' => ['record_grade'],
    'attendance' => ['record_attendance', 'batch_attendance'],
];
foreach ($__featureActions as $__feature => $__actions) {
    if (in_array($action, $__actions, true) && !feature_enabled($__feature)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This feature is not enabled for this deployment.']);
        exit;
    }
}

// ── Action-level authorization (two tiers) ──
// Teachers and attendance-takers are allowed in to READ classes and record
// grades/attendance, but management is restricted:
$__role = $_SESSION['admin_role'] ?? '';

// HR may only fetch the live class catalog for the registration dropdown.
if ($__role === 'hr_dept' && !in_array($action, ['get_classes'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to use this Education action.']);
    exit;
}

// TIER 1 — Academic YEAR / SEMESTER management: School Admin & Super Admin ONLY.
// (Education dept can VIEW the year for context but cannot create/change it.)
$__yearActions = ['save_academic_year', 'set_current_year', 'reopen_year', 'delete_year', 'save_term', 'delete_term', 'set_current_term'];
if (in_array($action, $__yearActions, true)
        && !in_array($__role, ['super_admin', 'school_admin'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only a School Admin or Super Admin can manage academic years and semesters.']);
    exit;
}

// TIER 2 — Class / enrolment management: Education dept + admins.
$__manageActions = [
    'enroll', 'unenroll_student', 'promote', 'bulk_enroll', 'transfer_student',
    'assign_teacher', 'save_class', 'delete_class',
    'sync_member_types',
];
if (in_array($action, $__manageActions, true)) {
    if (!in_array($__role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only the Education department can manage classes and enrolments.']);
        exit;
    }
}

// Effective academic year — single source of truth (resolver, time-travel aware)
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

// Education schema is deployment-managed by migrations 004, 006, and 013.

// ── STEP 3: write-protection ────────────────────────────────────────────────
// Year-scoped writes stamp the ACTIVE year and are refused while time-travelling
// (viewing a past year) or when no active year is set. Year-MANAGEMENT actions
// (save_academic_year, set_current_year, save_term, …) are deliberately exempt —
// they are how the active year is administered, not year-scoped data writes.
if (function_exists('ay_require_writable')) {
    $ayYearScopedWrites = ['enroll','assign_teacher','record_grade','record_attendance','batch_attendance','promote','bulk_enroll','transfer_student'];
    $ayReadonlyBlocked  = ['save_class','delete_class','unenroll_student','sync_member_types'];
    if (in_array($action, $ayYearScopedWrites, true)) {
        ay_require_writable($conn);
    } elseif (in_array($action, $ayReadonlyBlocked, true)) {
        ay_block_if_readonly($conn);
    }
}

try {
switch ($action) {

    // ============================================================
    // ENROLL STUDENT IN CLASS
    // ============================================================
    case 'enroll':
        $memberId = (int)($_POST['member_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);

        if (!$memberId || !$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Please select both member and class']);
            exit;
        }

        $enr = EnrollmentService::enroll($conn, $memberId, $classId, $currentYear['id'] ?? null, (int)($_SESSION['admin_id'] ?? 0));
        if (($enr['status'] ?? '') !== 'success') {
            echo json_encode($enr);
            exit;
        }

        $stmt = $conn->prepare("SELECT student_name, father_name, member_code FROM members WHERE id = ?");
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $memberName = trim(($member['student_name'] ?? '') . ' ' . ($member['father_name'] ?? ''));
        $className = $class['class_name'] ?? 'class';

        if (empty($enr['skipped']) && function_exists('sendNotification')) {
            sendNotification($conn, 'class_enrolled',
                "Student Enrolled in Class",
                "$memberName has been enrolled in {$className}",
                [
                    'data' => ['member_id' => $memberId, 'class_id' => $classId],
                    'target_roles' => ['info_dept', 'school_admin']
                ]
            );
        }

        $msg = !empty($enr['transferred'])
            ? "$memberName transferred to {$className}."
            : (!empty($enr['skipped'])
                ? "$memberName is already in {$className}."
                : "$memberName enrolled in {$className} successfully!");
        echo json_encode(['status' => 'success', 'message' => $msg]);
        break;
    
    // ============================================================
    // ASSIGN TEACHER TO CLASS
    // ============================================================
    case 'assign_teacher':
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
        $isClassTeacher = !empty($_POST['is_class_teacher']);
        $assignedBy = (int)($_SESSION['admin_id'] ?? 0);

        if ($isClassTeacher && !$subjectId) {
            echo json_encode(AssignmentService::setHomeroom($conn, $teacherId, $classId, null, $assignedBy), JSON_UNESCAPED_UNICODE);
            break;
        }

        $res = AssignmentService::assign($conn, $teacherId, $classId, $subjectId, 'primary', null, $assignedBy);
        if (($res['status'] ?? '') === 'success' && $isClassTeacher) {
            $home = AssignmentService::setHomeroom($conn, $teacherId, $classId, null, $assignedBy);
            if (($home['status'] ?? '') === 'success') {
                $res['message'] = ($res['message'] ?? 'Assigned.') . ' Also set as Class Teacher.';
            }
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;
    
    // ============================================================
    // RECORD GRADES
    // ============================================================
    case 'record_grade':
        $memberId = (int)($_POST['member_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $assessmentType = $_POST['assessment_type'] ?? 'test';
        $score = isset($_POST['score']) ? (float)$_POST['score'] : null;
        $maxScore = isset($_POST['max_score']) ? (float)$_POST['max_score'] : 100;
        $remarks = trim($_POST['remarks'] ?? '');
        
        if (!$memberId || !$classId || !$subjectId) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }
        
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year.']);
            exit;
        }
        
        // Get current term
        $currentTerm = null;
        $result = $conn->query("SELECT id FROM academic_terms WHERE is_current = 1 LIMIT 1");
        if ($result) $currentTerm = $result->fetch_assoc();
        $termId = $currentTerm ? $currentTerm['id'] : null;
        
        // Calculate grade letter
        $gradeLetter = calculateGradeLetter($score, $maxScore);
        
        // Insert grade record
        $stmt = $conn->prepare("
            INSERT INTO academic_records 
            (member_id, class_id, subject_id, academic_year_id, term_id, assessment_type, 
             score, max_score, grade_letter, remarks, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $recordedBy = $_SESSION['admin_id'];
        $stmt->bind_param("iiiissddssi", 
            $memberId, $classId, $subjectId, $currentYear['id'], $termId,
            $assessmentType, $score, $maxScore, $gradeLetter, $remarks, $recordedBy
        );
        
        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Grade recorded successfully!',
                'grade_letter' => $gradeLetter
            ]);
        } else {
            reportInternalError('Grade record failed', $stmt->error ?: $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to record the grade.']);
        }
        break;
    
    // ============================================================
    // RECORD ATTENDANCE
    // ============================================================
    case 'record_attendance':
        $memberId = (int)($_POST['member_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $attendanceDate = validateDate($_POST['attendance_date'] ?? '', date('Y-m-d'));
        $statusValue = $_POST['status'] ?? null;
        $status = is_string($statusValue) ? strtolower(trim($statusValue)) : '';
        $checkInTime = $_POST['check_in_time'] ?? null;
        $checkInTime = is_string($checkInTime) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $checkInTime)
            ? $checkInTime
            : null;
        $noteValue = $_POST['notes'] ?? '';
        $notes = is_string($noteValue) ? trim($noteValue) : '';
        
        if (!$memberId || !$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Member ID and class ID are required']);
            exit;
        }
        
        if (!in_array($status, ['present', 'absent', 'late', 'excused', 'holiday'], true)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid attendance status']);
            exit;
        }
        
        if ((function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes)) > AttendanceRecordService::MAX_NOTE_LENGTH) {
            echo json_encode(['status' => 'error', 'message' => 'Attendance notes are too long.']);
            exit;
        }

        $yearId = $currentYear ? (int)$currentYear['id'] : null;
        $recordedBy = (int)$_SESSION['admin_id'];
        $enrollment = $conn->prepare(
            "SELECT 1 FROM class_enrollments
             WHERE member_id = ? AND class_id = ? AND status = 'active'
               AND (? IS NULL OR academic_year_id = ?)
             LIMIT 1"
        );
        $enrollment->bind_param('iiii', $memberId, $classId, $yearId, $yearId);
        $enrollment->execute();
        $isEnrolled = $enrollment->get_result()->num_rows > 0;
        $enrollment->close();
        if (!$isEnrolled) {
            echo json_encode(['status' => 'error', 'message' => 'The member is not actively enrolled in this class.']);
            exit;
        }
        
        // Upsert one explicitly selected attendance record.
        $stmt = $conn->prepare("
            INSERT INTO attendance 
            (member_id, class_id, academic_year_id, attendance_date, status, check_in_time, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                check_in_time = VALUES(check_in_time),
                notes = VALUES(notes),
                recorded_by = VALUES(recorded_by)
        ");
        $stmt->bind_param("iiissssi",
            $memberId, $classId, $yearId, $attendanceDate, 
            $status, $checkInTime, $notes, $recordedBy
        );
        
        if ($stmt->execute()) {
            // Update attendance summary for the RECORDED date's month.
            updateAttendanceSummary($conn, $memberId, $yearId, $attendanceDate);
            
            echo json_encode(['status' => 'success', 'message' => 'Attendance recorded!']);
        } else {
            reportInternalError('Attendance record failed', $stmt->error ?: $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to record attendance.']);
        }
        break;
    
    // ============================================================
    // BATCH RECORD ATTENDANCE
    // ============================================================
    case 'batch_attendance':
        $classId = (int)($_POST['class_id'] ?? 0);
        $attendanceDate = validateDate($_POST['attendance_date'] ?? '', date('Y-m-d'));
        $records = json_decode($_POST['records'] ?? '[]', true);
        
        if (!$classId || !is_array($records) || empty($records)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing class or records']);
            exit;
        }
        
        $yearId = $currentYear ? (int)$currentYear['id'] : null;
        $recordedBy = (int)$_SESSION['admin_id'];
        $scope = EnrollmentService::resolveRosterYear($conn, $classId, $yearId);
        $roster = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null);
        try {
            $records = AttendanceRecordService::normalizeCompleteSheet($records, $roster);
        } catch (DomainException $error) {
            http_response_code(422);
            $safeMessage = $error->getMessage();
            echo json_encode(['status' => 'error', 'message' => $safeMessage]);
            exit;
        }

        $conn->begin_transaction();
        try {
            $successCount = AttendanceRecordService::replaceSheet(
                $conn,
                $classId,
                $attendanceDate,
                $yearId,
                $recordedBy,
                $records
            );
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            error_log('batch_attendance failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Attendance was not saved. The previous sheet is unchanged.',
            ]);
            exit;
        }

        foreach ($records as $record) {
            updateAttendanceSummary($conn, (int)$record['member_id'], $yearId, $attendanceDate);
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => "$successCount attendance records saved!"
        ]);
        break;
    
    // ============================================================
    // GET STUDENTS IN CLASS
    // ============================================================
    case 'get_class_students':
        $classId = (int)($_GET['class_id'] ?? 0);
        
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID required']);
            exit;
        }

        $preferYear = $currentYear ? (int)$currentYear['id'] : null;
        $scope = EnrollmentService::resolveRosterYear($conn, $classId, $preferYear);
        $students = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null);
        
        echo json_encode([
            'status' => 'success',
            'students' => $students,
            'count' => count($students),
            'roster_year_id' => $scope['year_id'] ?? null,
            'roster_year_name' => $scope['year_name'] ?? null,
            'roster_fallback' => !empty($scope['fallback']),
        ], JSON_UNESCAPED_UNICODE);
        break;
    
    // ============================================================
    // PROMOTE STUDENT
    // ============================================================
    case 'promote':
        // ATOMICITY GUARANTEE: a promotion either completes fully (old
        // enrollment closed + new enrollment created + member denormalized)
        // or makes NO changes at all. Previously the four steps ran as
        // independent autocommits, so a mid-sequence failure could leave a
        // member enrolled in two classes or in none.
        $memberId = (int)($_POST['member_id'] ?? 0);
        $fromClassId = (int)($_POST['from_class_id'] ?? 0);
        $toClassId = (int)($_POST['to_class_id'] ?? 0);

        if (!$memberId || !$fromClassId || !$toClassId) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year']);
            exit;
        }
        if ($fromClassId === $toClassId) {
            echo json_encode(['status' => 'error', 'message' => 'Source and target class are the same.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            // The member must actually hold an active enrollment in the
            // source class (prevents promoting from stale UI state).
            $check = $conn->prepare(
                "SELECT id FROM class_enrollments
                 WHERE member_id = ? AND class_id = ? AND status = 'active' LIMIT 1"
            );
            $check->bind_param('ii', $memberId, $fromClassId);
            $check->execute();
            $sourceEnrollment = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$sourceEnrollment) {
                throw new RuntimeException('No active enrollment found in the source class.');
            }

            // Target class must exist.
            $classCheck = $conn->prepare('SELECT id FROM classes WHERE id = ? LIMIT 1');
            $classCheck->bind_param('i', $toClassId);
            $classCheck->execute();
            $targetClass = $classCheck->get_result()->fetch_assoc();
            $classCheck->close();
            if (!$targetClass) {
                throw new RuntimeException('Target class does not exist.');
            }

            // Mark old enrollment as completed
            $stmt = $conn->prepare("UPDATE class_enrollments SET status = 'completed' WHERE id = ?");
            $stmt->bind_param("i", $sourceEnrollment['id']);
            $stmt->execute();
            $stmt->close();

            // Create new enrollment
            $stmt = $conn->prepare("
                INSERT INTO class_enrollments
                (member_id, class_id, academic_year_id, enrolled_at, status, promoted_from, enrolled_by)
                VALUES (?, ?, ?, CURDATE(), 'active', ?, ?)
            ");
            $enrolledBy = (int)($_SESSION['admin_id'] ?? 0);
            $yearId = (int)$currentYear['id'];
            $stmt->bind_param("iiiii", $memberId, $toClassId, $yearId, $fromClassId, $enrolledBy);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to create the new enrollment.');
            }
            $stmt->close();

            // Update member's denormalized class reference
            autoUpdateMemberClass($conn, $memberId, $toClassId, $yearId);

            // Update promoted_at (column added by migration/config auto-fix)
            try {
                $stmt = $conn->prepare("UPDATE members SET promoted_at = CURDATE() WHERE id = ?");
                $stmt->bind_param("i", $memberId);
                $stmt->execute();
                $stmt->close();
            } catch (Exception $e) { /* promoted_at column may not exist yet */ }

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Student promoted successfully!']);
        } catch (Exception $e) {
            $conn->rollback();
            error_log('promote failed: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Promotion failed: ' . $e->getMessage() . ' No changes were made.']);
        }
        break;
    

    // ============================================================
    // GET ENROLLED STUDENTS (alias for get_class_students)
    // ============================================================
    case 'get_enrolled_students':
        $classId = (int)($_GET['class_id'] ?? 0);
        $search = trim($_GET['search'] ?? '');
        $genderFilter = trim($_GET['gender'] ?? '');
        $memberTypeFilter = trim($_GET['member_type'] ?? '');
        $sortBy = trim($_GET['sort'] ?? 'name');
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Please pick a class first.']);
            exit;
        }
        try {
            $preferYear = $currentYear ? (int)$currentYear['id'] : null;
            $scope = EnrollmentService::resolveRosterYear($conn, $classId, $preferYear);
            $students = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null, [
                'search' => $search,
                'gender' => $genderFilter,
                'member_type' => $memberTypeFilter,
                'sort' => $sortBy,
            ]);
            $stats = ['total' => count($students), 'male' => 0, 'female' => 0, 'regular' => 0, 'special_regular' => 0, 'honorary' => 0, 'teachers' => 0];
            foreach ($students as $s) {
                if (($s['gender'] ?? '') === 'male') $stats['male']++; else $stats['female']++;
                $mt = $s['member_type'] ?? 'regular';
                if (isset($stats[$mt])) $stats[$mt]++; else $stats['regular']++;
                if (!empty($s['is_teacher'])) $stats['teachers']++;
            }
            echo json_encode([
                'status' => 'success',
                'students' => $students,
                'stats' => $stats,
                'roster_year_id' => $scope['year_id'] ?? null,
                'roster_year_name' => $scope['year_name'] ?? null,
                'roster_fallback' => !empty($scope['fallback']),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not load enrolled students. Please try again.']);
        }
        break;

    // ============================================================
    // UNENROLL STUDENT
    // ============================================================
    case 'unenroll_student':
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        if (!$enrollmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Enrollment ID required']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE class_enrollments SET status = 'withdrawn' WHERE id = ?");
        $stmt->bind_param("i", $enrollmentId);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Student removed from class']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to unenroll']);
        }
        break;

    // ============================================================
    // DASHBOARD STATS
    // ============================================================
    case 'dashboard':
        $data = [];
        try {
            $yearCond = $currentYear ? " AND ce.academic_year_id={$currentYear['id']}" : "";
            $r = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id=c.id AND ce.status='active'{$yearCond}) as student_count FROM classes c WHERE c.is_active=1 ORDER BY c.level_order");
            $data['classes'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $data['classes'][] = $row;
            
            // Also include teacher info if teacher_assignments table exists
            try {
                foreach ($data['classes'] as &$cls) {
                    $cid = (int)$cls['id'];
                    $yc = $currentYear ? " AND ta.academic_year_id={$currentYear['id']}" : "";
                    $tr = $conn->query("SELECT u.full_name FROM teacher_assignments ta JOIN users u ON ta.teacher_id=u.id WHERE ta.class_id=$cid AND ta.is_active=1{$yc} LIMIT 1");
                    $cls['teacher_name'] = ($tr && $trow = $tr->fetch_assoc()) ? $trow['full_name'] : null;
                }
                unset($cls);
            } catch (Exception $e) { /* teacher_assignments may not exist */ }
            
            echo json_encode(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            // classes table may not exist
            echo json_encode(['status' => 'success', 'data' => ['classes' => []], 'note' => 'Education tables may need setup']);
        }
        break;

    // ============================================================
    // CLASS MANAGEMENT
    // ============================================================
    case 'get_classes':
        $yearCond2 = $currentYear ? " AND ce.academic_year_id=" . (int)$currentYear['id'] : "";
        $classes = [];
        try {
            $sql = "SELECT c.*, COALESCE((SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id=c.id AND ce.status='active'{$yearCond2}), 0) as student_count FROM classes c ORDER BY c.level_order";
            $r = $conn->query($sql);
            if ($r) {
                while ($row = $r->fetch_assoc()) $classes[] = $row;
            }
        } catch (Exception $e) {
            // class_enrollments or classes table may not exist yet
            try {
                $r2 = $conn->query("SELECT c.*, 0 as student_count FROM classes c ORDER BY c.level_order");
                if ($r2) while ($row = $r2->fetch_assoc()) $classes[] = $row;
            } catch (Exception $e2) { /* classes table doesn't exist */ }
        }
        echo json_encode(['status' => 'success', 'classes' => $classes]);
        break;

    case 'save_class':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['class_name'] ?? '');
        $nameEn = trim($_POST['class_name_en'] ?? '');
        $code = trim($_POST['class_code'] ?? '');
        $level = (int)($_POST['level_order'] ?? 0);
        $section = trim($_POST['section'] ?? '');
        $ageGroup = $_POST['age_group'] ?? null;
        // ENUM columns reject empty strings — convert to NULL
        if ($ageGroup === '' || $ageGroup === null) {
            $ageGroup = null;
        }
        $desc = trim($_POST['description'] ?? '');
        $isActive = (int)($_POST['is_active'] ?? 1);
        if (!$name || !$code) {
            echo json_encode(['status' => 'error', 'message' => 'Name and code required']);
            exit;
        }
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE classes SET class_name=?,class_name_en=?,class_code=?,level_order=?,section=?,age_group=?,description=?,is_active=? WHERE id=?");
            $stmt->bind_param("sssisssii", $name, $nameEn, $code, $level, $section, $ageGroup, $desc, $isActive, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO classes (class_name,class_name_en,class_code,level_order,section,age_group,description,is_active) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sssisssi", $name, $nameEn, $code, $level, $section, $ageGroup, $desc, $isActive);
        }
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Class saved', 'id' => $id ?: $conn->insert_id]);
        } else {
            reportInternalError('Class save failed', $stmt->error ?: $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Unable to save the class.']);
        }
        break;

    case 'delete_class':
        $id = (int)($_POST['class_id'] ?? 0);
        if (!$id) { echo json_encode(['status'=>'error','message'=>'ID required']); exit; }
        
        // Use prepared statement to check enrollments
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM class_enrollments WHERE class_id = ? AND status = 'active'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        
        if ($cnt > 0) {
            echo json_encode(['status'=>'error','message'=>"Cannot delete: $cnt active enrollments"]);
        } else {
            $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['status'=>'success','message'=>'Class deleted']);
        }
        break;

    // ============================================================
    // ACADEMIC YEAR MANAGEMENT
    // ============================================================
    case 'get_academic_years':
        try {
            $r = $conn->query("SELECT ay.*, COALESCE((SELECT COUNT(*) FROM academic_terms WHERE academic_year_id=ay.id), 0) as term_count FROM academic_years ay ORDER BY ay.ec_year DESC, ay.id DESC");
            $years = [];
            if ($r) {
                while ($row = $r->fetch_assoc()) $years[] = $row;
            } else {
                // academic_terms might not exist — try without
                $r2 = $conn->query("SELECT ay.*, 0 as term_count FROM academic_years ay ORDER BY ay.id DESC");
                if ($r2) while ($row = $r2->fetch_assoc()) $years[] = $row;
            }
            echo json_encode(['status' => 'success', 'years' => $years]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'success', 'years' => []]);
        }
        break;

    case 'save_academic_year':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['year_name'] ?? '');
        $ecYear = (int)($_POST['ec_year'] ?? 0);
        $yearGc = trim($_POST['year_gc'] ?? '');
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '');
        $isCurrent = (int)($_POST['is_current'] ?? 0);
        if (!$name) { echo json_encode(['status'=>'error','message'=>'Year name required']); exit; }
        
        // Convert empty strings to NULL for DATE columns
        $startDate = ($start !== '') ? $start : null;
        $endDate = ($end !== '') ? $end : null;
        $ecYearVal = ($ecYear > 0) ? $ecYear : null;
        $yearGcVal = ($yearGc !== '') ? $yearGc : null;
        
        // LIFECYCLE: this form NEVER flips the active year directly. A new year
        // is created as 'upcoming'; it becomes active only through the explicit
        // "Set Active" switch (ay_switch_active) with typed confirmation. The
        // one exception is first-time setup: if no active year exists yet, a
        // newly created year is auto-activated (nothing to close). This keeps a
        // single source of truth and makes two-active-years impossible.
        $isCurrent = 0; // ignored for lifecycle; kept for backward-compat only.

        // M3: application-level uniqueness guard. Migration 018 adds the real
        // UNIQUE(year_name) index (skipped automatically on deployments that
        // still contain duplicate names); this pre-check enforces uniqueness
        // even where that migration has not run yet.
        try {
            if ($id > 0) {
                $dupCheck = $conn->prepare('SELECT id FROM academic_years WHERE year_name = ? AND id <> ? LIMIT 1');
                $dupCheck->bind_param('si', $name, $id);
            } else {
                $dupCheck = $conn->prepare('SELECT id FROM academic_years WHERE year_name = ? LIMIT 1');
                $dupCheck->bind_param('s', $name);
            }
            $dupCheck->execute();
            $duplicateYear = $dupCheck->get_result()->fetch_assoc();
            $dupCheck->close();
            if ($duplicateYear) {
                echo json_encode(['status'=>'error','message'=>'An academic year with this name already exists — please choose a different year name.']);
                exit;
            }
        } catch (Exception $dupError) {
            // Fall through to the write path; the UNIQUE index (when present)
            // still rejects duplicates with errno 1062 handled below.
        }

        try {
            if ($id > 0) {
                // UPDATE existing — descriptive fields only. The active-year
                // lifecycle (status/is_current) changes ONLY through the explicit
                // "Set Active" switch, never from this edit form.
                $sql = "UPDATE academic_years SET year_name=?, ec_year=?, year_gc=?, start_date=?, end_date=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    reportInternalError('Academic year update prepare failed', $conn->error);
                    echo json_encode(['status'=>'error','message'=>'Academic year storage is temporarily unavailable.']);
                    exit;
                }
                $stmt->bind_param("sisssi", $name, $ecYearVal, $yearGcVal, $startDate, $endDate, $id);
                if ($stmt->execute()) {
                    echo json_encode(['status'=>'success','message'=>'Academic year updated','id'=>$id]);
                } else {
                    if ($stmt->errno == 1062) {
                        echo json_encode(['status'=>'error','message'=>'An academic year with this name already exists — please choose a different year name.']);
                    } else {
                        reportInternalError('Academic year update failed', $stmt->error);
                        echo json_encode(['status'=>'error','message'=>'Unable to update the academic year.']);
                    }
                }
            } else {
                // INSERT new — always starts as 'upcoming' (is_current=0). It
                // becomes active only via the explicit "Set Active" switch.
                $sql = "INSERT INTO academic_years (year_name, ec_year, year_gc, start_date, end_date, is_current) VALUES (?,?,?,?,?,0)";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    reportInternalError('Academic year insert prepare failed', $conn->error);
                    echo json_encode(['status'=>'error','message'=>'Academic year storage is temporarily unavailable.']);
                    exit;
                }
                $stmt->bind_param("sisss", $name, $ecYearVal, $yearGcVal, $startDate, $endDate);
                if ($stmt->execute()) {
                    $newId = $conn->insert_id;
                    // Auto-create 2 semesters
                    if ($newId) {
                        try {
                            $term1 = '1ኛ ሴሚስተር'; $term2 = '2ኛ ሴሚስተር';
                            $stmt2 = $conn->prepare("INSERT IGNORE INTO academic_terms (academic_year_id, term_name, term_number, is_current) VALUES (?,?,?,?)");
                            if ($stmt2) {
                                $tn1=1; $tn2=2; $cur1=1; $cur0=0;
                                $stmt2->bind_param("isii", $newId, $term1, $tn1, $cur1);
                                $stmt2->execute();
                                $stmt2->bind_param("isii", $newId, $term2, $tn2, $cur0);
                                $stmt2->execute();
                            }
                        } catch (Exception $e) { /* terms table might not exist */ }
                    }
                    // Bootstrap: if there is no active year yet, make this one
                    // active — there is no running year to close, so it is safe.
                    $activated = false;
                    if ($newId) {
                        $hasActive = false;
                        try {
                            $ac = $conn->query("SELECT COUNT(*) c FROM academic_years WHERE status='active'");
                            if ($ac) $hasActive = ((int)$ac->fetch_assoc()['c'] > 0);
                        } catch (Exception $e) {
                            try { $ac = $conn->query("SELECT COUNT(*) c FROM academic_years WHERE is_current=1"); if ($ac) $hasActive = ((int)$ac->fetch_assoc()['c'] > 0); } catch (Exception $e2) {}
                        }
                        if (!$hasActive) {
                            if (function_exists('ay_switch_active')) {
                                $sw = ay_switch_active($conn, $newId, false);
                                $activated = (($sw['status'] ?? '') === 'success');
                            } else {
                                $conn->query("UPDATE academic_years SET is_current=1 WHERE id=".(int)$newId);
                                $activated = true;
                            }
                        }
                    }
                    $msg = $activated
                        ? 'Academic year created and set as the ACTIVE year (first year).'
                        : 'Academic year created with 2 semesters. Use "Set Active" to make it the current year.';
                    echo json_encode(['status'=>'success','message'=>$msg,'id'=>$newId,'activated'=>$activated]);
                } else {
                    if ($stmt->errno == 1062) {
                        echo json_encode(['status'=>'error','message'=>'An academic year with this name already exists — please choose a different year name.']);
                    } else {
                        reportInternalError('Academic year insert failed', $stmt->error);
                        echo json_encode(['status'=>'error','message'=>'Unable to create the academic year.']);
                    }
                }
            }
        } catch (Exception $e) {
            reportInternalError('Academic year save failed', $e);
            echo json_encode(['status'=>'error','message'=>'Unable to save the academic year.']);
        }
        break;

    case 'set_current_year':
        // STEP 4 — SAFE, ATOMIC active-year switch. Never leaves zero or two
        // active years. Requires typed confirmation; reopening a CLOSED (past)
        // year requires the stronger "REOPEN" confirmation.
        $yid = (int)($_POST['year_id'] ?? 0);
        if (!$yid) { echo json_encode(['status'=>'error','message'=>'Year ID required']); exit; }

        if (!function_exists('ay_switch_active')) {
            // Fallback: legacy transactional flip (resolver unavailable).
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE academic_years SET is_current=0");
                $stmt = $conn->prepare("UPDATE academic_years SET is_current=1 WHERE id = ?");
                if (!$stmt) { throw new Exception($conn->error); }
                $stmt->bind_param("i", $yid);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                echo json_encode(['status'=>'success','message'=>'Current year updated']);
            } catch (Exception $e) {
                $conn->rollback();
                error_log("set_current_year failed: " . $e->getMessage());
                echo json_encode(['status'=>'error','message'=>'Could not change the current year. No changes were made.']);
            }
            break;
        }

        $target = ay_year_by_id($conn, $yid);
        if (!$target) { echo json_encode(['status'=>'error','message'=>'That academic year does not exist.']); break; }
        $tstatus = $target['status'] ?? ((int)($target['is_current'] ?? 0) === 1 ? 'active' : 'upcoming');

        if ($tstatus === 'active') {
            echo json_encode(['status'=>'success','message'=>'That year is already the active year.']);
            break;
        }

        $reopen  = ($tstatus === 'closed');
        $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
        $need    = $reopen ? 'REOPEN' : 'SWITCH';
        if ($confirm !== $need) {
            echo json_encode([
                'status'             => 'error',
                'code'               => $reopen ? 'confirm_reopen' : 'confirm_switch',
                'needs_confirmation' => true,
                'reopen'             => $reopen,
                'target_name'        => $target['year_name'] ?? '',
                'message'            => $reopen
                    ? 'This year is CLOSED. Reopening a past year makes it active again and NEW records will be stamped to it. This is unusual — only do it to correct a mistake. Type REOPEN to confirm.'
                    : 'Switching the active year will CLOSE the current year. New records will then belong to the newly selected year. Type SWITCH to confirm.'
            ]);
            break;
        }

        $res = ay_switch_active($conn, $yid, $reopen);
        echo json_encode($res);
        break;

    case 'delete_year':
        // STEP 6 — deletion protection. Only an EMPTY 'upcoming' year may be
        // deleted. The active year and any closed (past) year — and any year
        // that already holds records — are permanently protected.
        $yid = (int)($_POST['year_id'] ?? 0);
        if (!$yid) { echo json_encode(['status'=>'error','message'=>'Year ID required']); exit; }
        $target = function_exists('ay_year_by_id') ? ay_year_by_id($conn, $yid) : null;
        if (!$target) {
            $rr = $conn->query("SELECT * FROM academic_years WHERE id=".(int)$yid." LIMIT 1");
            $target = $rr ? $rr->fetch_assoc() : null;
        }
        if (!$target) { echo json_encode(['status'=>'error','message'=>'That academic year does not exist.']); break; }
        $tstatus = $target['status'] ?? ((int)($target['is_current'] ?? 0) === 1 ? 'active' : 'upcoming');
        if ($tstatus === 'active') { echo json_encode(['status'=>'error','message'=>'The ACTIVE year cannot be deleted. Switch to another year first.']); break; }
        if ($tstatus === 'closed') { echo json_encode(['status'=>'error','message'=>'Closed (past) years are permanently protected and cannot be deleted.']); break; }
        // 'upcoming' — allowed only if it holds NO year-scoped records.
        $recCount = 0;
        foreach (['class_enrollments','attendance','academic_records','teacher_assignments','submissions','assessments'] as $tbl) {
            try { $rc = $conn->query("SELECT COUNT(*) c FROM `$tbl` WHERE academic_year_id=".(int)$yid); if ($rc) $recCount += (int)$rc->fetch_assoc()['c']; } catch (Exception $e) {}
        }
        if ($recCount > 0) { echo json_encode(['status'=>'error','message'=>"This year already holds $recCount record(s) and cannot be deleted. Only an empty upcoming year can be removed."]); break; }
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM academic_terms WHERE academic_year_id=".(int)$yid);
            $st = $conn->prepare("DELETE FROM academic_years WHERE id=?");
            if (!$st) { throw new Exception($conn->error); }
            $st->bind_param('i', $yid); $st->execute(); $st->close();
            $conn->commit();
            echo json_encode(['status'=>'success','message'=>'Empty upcoming year deleted.']);
        } catch (Exception $e) {
            $conn->rollback();
            error_log('delete_year failed: '.$e->getMessage());
            echo json_encode(['status'=>'error','message'=>'Could not delete the year. No changes were made.']);
        }
        break;

    case 'get_terms':
        $yid = (int)($_GET['year_id'] ?? 0);
        $terms = [];
        try {
            $stmt = $conn->prepare("SELECT * FROM academic_terms WHERE academic_year_id = ? ORDER BY term_number");
            if ($stmt) {
                $stmt->bind_param("i", $yid);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) $terms[] = $row;
                $stmt->close();
            }
        } catch (Exception $e) { /* table may not exist */ }
        echo json_encode(['status'=>'success','terms'=>$terms]);
        break;

    case 'save_term':
        $tid = (int)($_POST['term_id'] ?? 0);
        $ayid = (int)($_POST['academic_year_id'] ?? 0);
        $tname = trim($_POST['term_name'] ?? '');
        $tnum = (int)($_POST['term_number'] ?? 1);
        $tstart = trim($_POST['start_date'] ?? '');
        $tend = trim($_POST['end_date'] ?? '');
        $tstartVal = ($tstart !== '') ? $tstart : null;
        $tendVal = ($tend !== '') ? $tend : null;
        if (!$tname) { echo json_encode(['status'=>'error','message'=>'Semester name required']); exit; }
        try {
            if ($tid > 0) {
                // UPDATE existing term
                $stmt = $conn->prepare("UPDATE academic_terms SET term_name=?, term_number=?, start_date=?, end_date=? WHERE id=?");
                if ($stmt) {
                    $stmt->bind_param("sissi", $tname, $tnum, $tstartVal, $tendVal, $tid);
                    if ($stmt->execute()) echo json_encode(['status'=>'success','message'=>'Semester updated']);
                    else { reportInternalError('Academic term update failed', $stmt->error); echo json_encode(['status'=>'error','message'=>'Unable to update the semester.']); }
                } else {
                    reportInternalError('Academic term update prepare failed', $conn->error);
                    echo json_encode(['status'=>'error','message'=>'Semester storage is temporarily unavailable.']);
                }
            } else {
                // INSERT new term
                if (!$ayid) { echo json_encode(['status'=>'error','message'=>'Year ID required']); exit; }
                $stmt = $conn->prepare("INSERT INTO academic_terms (academic_year_id, term_name, term_number, start_date, end_date, is_current) VALUES (?,?,?,?,?,0)");
                if ($stmt) {
                    $stmt->bind_param("isiss", $ayid, $tname, $tnum, $tstartVal, $tendVal);
                    if ($stmt->execute()) echo json_encode(['status'=>'success','message'=>'Semester added']);
                    else { reportInternalError('Academic term insert failed', $stmt->error); echo json_encode(['status'=>'error','message'=>'Unable to add the semester.']); }
                } else {
                    reportInternalError('Academic term insert prepare failed', $conn->error);
                    echo json_encode(['status'=>'error','message'=>'Semester storage is temporarily unavailable.']);
                }
            }
        } catch (Exception $e) {
            reportInternalError('Academic term save failed', $e);
            echo json_encode(['status'=>'error','message'=>'Unable to save the semester.']);
        }
        break;

    case 'set_current_term':
        $tid = (int)($_POST['term_id'] ?? 0);
        if (!$tid) { echo json_encode(['status'=>'error','message'=>'Term ID required']); exit; }
        $conn->query("UPDATE academic_terms SET is_current=0");
        $stmt = $conn->prepare("UPDATE academic_terms SET is_current=1 WHERE id = ?");
        $stmt->bind_param("i", $tid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status'=>'success','message'=>'Current semester updated']);
        break;

    case 'delete_term':
        $tid = (int)($_POST['term_id'] ?? 0);
        if (!$tid) { echo json_encode(['status'=>'error','message'=>'Term ID required']); exit; }
        // STEP 6 — protect historical data: semesters of a CLOSED (past) year
        // cannot be deleted.
        try {
            $chk = $conn->prepare("SELECT ay.status FROM academic_terms t JOIN academic_years ay ON ay.id=t.academic_year_id WHERE t.id=?");
            if ($chk) {
                $chk->bind_param('i', $tid);
                $chk->execute();
                $row = $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($row && ($row['status'] ?? '') === 'closed') {
                    echo json_encode(['status'=>'error','message'=>'This semester belongs to a closed (past) year and is protected. It cannot be deleted.']);
                    break;
                }
            }
        } catch (Exception $e) { /* status column may not exist yet — allow */ }
        $stmt = $conn->prepare("DELETE FROM academic_terms WHERE id = ?");
        $stmt->bind_param("i", $tid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status'=>'success','message'=>'Semester deleted']);
        break;

    // ============================================================
    // BULK ENROLL STUDENTS
    // ============================================================
    case 'bulk_enroll':
        $classId = (int)($_POST['class_id'] ?? 0);
        $memberIds = json_decode($_POST['member_ids'] ?? '[]', true);
        if (!$classId || empty($memberIds)) {
            echo json_encode(['status' => 'error', 'message' => 'Pick a class and at least one student.', 'field' => !$classId ? 'class_id' : 'member_ids']);
            exit;
        }
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'There is no active academic year. Ask a School Admin to set one first.']);
            exit;
        }
        $by = (int)($_SESSION['admin_id'] ?? 0); $ok=0; $skip=0; $fail=0;
        foreach ($memberIds as $mid) {
            $mid = (int)$mid; if (!$mid) continue;
            $res = EnrollmentService::enroll($conn, $mid, $classId, (int)$currentYear['id'], $by);
            if (($res['status'] ?? '') === 'success') {
                if (!empty($res['skipped'])) $skip++; else $ok++;
            } else {
                $fail++;
            }
        }
        $msg = "$ok student(s) enrolled!"; if ($skip) $msg .= " ($skip already enrolled)"; if ($fail) $msg .= " ($fail failed)";
        echo json_encode(['status'=>'success','message'=>$msg,'enrolled'=>$ok,'skipped'=>$skip,'failed'=>$fail]);
        break;

    // ============================================================
    // TRANSFER STUDENT BETWEEN CLASSES
    // ============================================================
    case 'transfer_student':
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $toClassId = (int)($_POST['to_class_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if (!$enrollmentId || !$toClassId) { echo json_encode(['status'=>'error','message'=>'Enrollment and target class required']); exit; }
        if (!$currentYear) { echo json_encode(['status'=>'error','message'=>'No active academic year']); exit; }
        $stmt = $conn->prepare("SELECT ce.*, m.student_name, m.father_name FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.id=?");
        $stmt->bind_param("i", $enrollmentId); $stmt->execute(); $enr = $stmt->get_result()->fetch_assoc();
        if (!$enr) { echo json_encode(['status'=>'error','message'=>'Enrollment not found']); exit; }
        $stmt = $conn->prepare("SELECT id FROM class_enrollments WHERE member_id=? AND class_id=? AND academic_year_id=? AND status='active'");
        $stmt->bind_param("iii", $enr['member_id'], $toClassId, $currentYear['id']); $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) { echo json_encode(['status'=>'error','message'=>'Already in target class']); exit; }
        // Target class must exist before mutating anything.
        $classCheck = $conn->prepare('SELECT id FROM classes WHERE id = ? LIMIT 1');
        $classCheck->bind_param('i', $toClassId); $classCheck->execute();
        if (!$classCheck->get_result()->fetch_assoc()) { $classCheck->close(); echo json_encode(['status'=>'error','message'=>'Target class does not exist.']); exit; }
        $classCheck->close();

        // ATOMICITY GUARANTEE: closing the old enrollment and creating the
        // new one commit together or not at all — a member can never end up
        // stranded (no active enrollment) or double-enrolled by a
        // mid-sequence failure.
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE class_enrollments SET status='transferred', notes=CONCAT(IFNULL(notes,''),' [Transferred: ',?,']') WHERE id=?");
            $stmt->bind_param("si", $reason, $enrollmentId);
            if (!$stmt->execute()) { throw new RuntimeException('Unable to close the source enrollment.'); }
            $stmt->close();
            $by = (int)($_SESSION['admin_id'] ?? 0); $dt = date('Y-m-d'); $from = $enr['class_id'];
            $tnote = "Transferred from class #$from".($reason ? ": $reason" : '');
            $yearId = (int)$currentYear['id']; $memberId = (int)$enr['member_id'];
            $stmt = $conn->prepare("INSERT INTO class_enrollments (member_id,class_id,academic_year_id,enrolled_at,status,notes,promoted_from,enrolled_by) VALUES (?,?,?,?,'active',?,?,?)");
            $stmt->bind_param("iiissii", $memberId, $toClassId, $yearId, $dt, $tnote, $from, $by);
            if (!$stmt->execute()) { throw new RuntimeException('Unable to create the target enrollment.'); }
            $stmt->close();
            if (function_exists('autoUpdateMemberClass')) autoUpdateMemberClass($conn, $memberId, $toClassId, $yearId);
            $conn->commit();
            echo json_encode(['status'=>'success','message'=>$enr['student_name'].' '.$enr['father_name'].' transferred!']);
        } catch (Exception $e) {
            $conn->rollback();
            reportInternalError('Enrollment transfer failed', $e->getMessage());
            echo json_encode(['status'=>'error','message'=>'Unable to transfer the enrollment. No changes were made.']);
        }
        break;

    // ============================================================
    // GET UNASSIGNED MEMBERS (not enrolled in any class this year)
    // ============================================================
    case 'get_unassigned_members':
        $search=trim($_GET['search']??''); $genderFilter=trim($_GET['gender']??'');
        $ageGroupFilter=trim($_GET['age_group']??''); $memberTypeFilter=trim($_GET['member_type']??'');
        $limit=min(100,max(10,(int)($_GET['limit']??50)));
        $offset=max(0,(int)($_GET['offset']??0));
        if (!$currentYear) { echo json_encode(['status'=>'success','members'=>[],'total'=>0]); exit; }
        $w=["m.status='active'"]; $p=[]; $t='';
        if ($search!=='') { $w[]="(m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ?)"; $st="%$search%"; $p=array_merge($p,[$st,$st,$st,$st]); $t.='ssss'; }
        if ($genderFilter!=='' && in_array($genderFilter,['male','female'])) { $w[]="m.gender=?"; $p[]=$genderFilter; $t.='s'; }
        if ($ageGroupFilter!=='' && in_array($ageGroupFilter,['7_13','14_17','18_plus'])) { $w[]="m.age_group=?"; $p[]=$ageGroupFilter; $t.='s'; }
        if ($memberTypeFilter!=='' && in_array($memberTypeFilter,['regular','special_regular','honorary'])) { $w[]="m.member_type=?"; $p[]=$memberTypeFilter; $t.='s'; }
        $wc=implode(' AND ',$w);
        $csql="SELECT COUNT(*) as total FROM members m WHERE $wc AND m.id NOT IN (SELECT ce.member_id FROM class_enrollments ce WHERE ce.academic_year_id=? AND ce.status='active')";
        $cp=array_merge($p,[$currentYear['id']]); $ct=$t.'i';
        if(!empty($cp)) { $stmt=$conn->prepare($csql); $stmt->bind_param($ct,...$cp); $stmt->execute(); }
        else { $stmt=$conn->prepare($csql); $stmt->execute(); }
        $total=(int)$stmt->get_result()->fetch_assoc()['total'];
        $sql="SELECT m.id, m.student_name, m.father_name, m.grandfather_name, m.member_code, m.gender, m.phone_number, m.phone_primary, m.age_group, m.current_section, m.date_of_birth, m.age, m.baptismal_name, m.education_level, m.is_teacher, m.member_type, m.is_staff, m.is_committee, m.is_volunteer, m.registered_at FROM members m WHERE $wc AND m.id NOT IN (SELECT ce.member_id FROM class_enrollments ce WHERE ce.academic_year_id=? AND ce.status='active') ORDER BY m.student_name LIMIT ? OFFSET ?";
        $fp=array_merge($p,[$currentYear['id'],$limit,$offset]); $ft=$t.'iii';
        $stmt=$conn->prepare($sql); $stmt->bind_param($ft,...$fp); $stmt->execute();
        $members=[]; $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $members[]=$row;
        echo json_encode(['status'=>'success','members'=>$members,'total'=>$total,'limit'=>$limit,'offset'=>$offset]);
        break;

    // ============================================================
    // GET UNASSIGNED TEACHERS
    // ============================================================
    case 'get_unassigned_teachers':
        $search=trim($_GET['search']??'');
        $w=["u.role='teacher'","u.is_active=1"]; $p=[]; $t='';
        if ($search!=='') { $w[]="(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)"; $st="%$search%"; $p=[$st,$st,$st]; $t='sss'; }
        $wc=implode(' AND ',$w);
        $yc=$currentYear ? " AND ta.academic_year_id=".(int)$currentYear['id'] : "";
        $sql="SELECT u.id, u.full_name, u.username, u.email, u.member_id, COALESCE(m.member_code,'') as member_code, COALESCE(m.phone_number,'') as phone FROM users u LEFT JOIN members m ON u.member_id=m.id WHERE $wc AND u.id NOT IN (SELECT ta.teacher_id FROM teacher_assignments ta WHERE ta.is_active=1 $yc) ORDER BY u.full_name";
        if (!empty($p)) { $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute(); $result=$stmt->get_result(); }
        else $result=$conn->query($sql);
        $teachers=[]; if($result) while($row=$result->fetch_assoc()) $teachers[]=$row;
        echo json_encode(['status'=>'success','teachers'=>$teachers]);
        break;

    // ============================================================
    // ENROLLMENT OVERVIEW (stats per class for dashboard sync)
    // ============================================================
    case 'enrollment_overview':
        if (!$currentYear) { echo json_encode(['status'=>'success','classes'=>[],'summary'=>[]]); exit; }
        $classes = [];
        try {
            // Try full query with teacher info
            $sql="SELECT c.id, c.class_name, c.class_name_en, c.class_code, c.level_order, c.section, c.age_group,
                COALESCE(enr.total,0) as enrolled_count, COALESCE(enr.male_count,0) as male_count, COALESCE(enr.female_count,0) as female_count,
                COALESCE(tch.teacher_count,0) as teacher_count, COALESCE(tch.class_teacher_name,'') as class_teacher_name
            FROM classes c
            LEFT JOIN (SELECT ce.class_id, COUNT(*) as total, SUM(CASE WHEN m.gender='male' THEN 1 ELSE 0 END) as male_count, SUM(CASE WHEN m.gender='female' THEN 1 ELSE 0 END) as female_count FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.academic_year_id=? AND ce.status='active' GROUP BY ce.class_id) enr ON c.id=enr.class_id
            LEFT JOIN (SELECT ta.class_id, COUNT(DISTINCT ta.teacher_id) as teacher_count, (SELECT u2.full_name FROM teacher_assignments ta2 JOIN users u2 ON ta2.teacher_id=u2.id WHERE ta2.class_id=ta.class_id AND ta2.is_class_teacher=1 AND ta2.is_active=1 LIMIT 1) as class_teacher_name FROM teacher_assignments ta WHERE ta.is_active=1 GROUP BY ta.class_id) tch ON c.id=tch.class_id
            WHERE c.is_active=1 ORDER BY c.level_order";
            $stmt=$conn->prepare($sql); $stmt->bind_param("i",$currentYear['id']); $stmt->execute();
            $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $classes[]=$row;
        } catch (Exception $e) {
            // Fallback without teacher_assignments join
            try {
                $stmt=$conn->prepare("SELECT c.id, c.class_name, c.class_name_en, c.class_code, c.level_order, c.section, c.age_group,
                    COALESCE(enr.total,0) as enrolled_count, COALESCE(enr.male_count,0) as male_count, COALESCE(enr.female_count,0) as female_count,
                    0 as teacher_count, '' as class_teacher_name
                FROM classes c
                LEFT JOIN (SELECT ce.class_id, COUNT(*) as total, SUM(CASE WHEN m.gender='male' THEN 1 ELSE 0 END) as male_count, SUM(CASE WHEN m.gender='female' THEN 1 ELSE 0 END) as female_count FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.academic_year_id=? AND ce.status='active' GROUP BY ce.class_id) enr ON c.id=enr.class_id
                WHERE c.is_active=1 ORDER BY c.level_order");
                $stmt->bind_param("i",$currentYear['id']); $stmt->execute();
                $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $classes[]=$row;
            } catch (Exception $e2) { /* tables don't exist yet */ }
        }
        $totalM=0; $r=$conn->query("SELECT COUNT(*) c FROM members WHERE status='active'"); if($r) $totalM=(int)$r->fetch_assoc()['c'];
        $totalE=0; $stmt=$conn->prepare("SELECT COUNT(DISTINCT ce.member_id) c FROM class_enrollments ce WHERE ce.academic_year_id=? AND ce.status='active'");
        $stmt->bind_param("i",$currentYear['id']); $stmt->execute(); $r=$stmt->get_result(); if($r) $totalE=(int)$r->fetch_assoc()['c'];
        $totalT=0; $r2=$conn->query("SELECT COUNT(*) c FROM users WHERE role='teacher' AND is_active=1"); if($r2) $totalT=(int)$r2->fetch_assoc()['c'];
        $assignedT=0; try { $yc2=$currentYear?" AND ta.academic_year_id=".(int)$currentYear['id']:""; $r3=$conn->query("SELECT COUNT(DISTINCT ta.teacher_id) c FROM teacher_assignments ta WHERE ta.is_active=1 $yc2"); if($r3) $assignedT=(int)$r3->fetch_assoc()['c']; } catch(Exception $e){}
        // Member type breakdown
        $typeBreakdown=['regular'=>0,'special_regular'=>0,'honorary'=>0];
        try { $r4=$conn->query("SELECT member_type, COUNT(*) as c FROM members WHERE status='active' GROUP BY member_type");
            if($r4) while($rw=$r4->fetch_assoc()) { $typeBreakdown[$rw['member_type']??'regular']=(int)$rw['c']; }
        } catch(Exception $e){}
        // Enrolled by type
        $enrolledByType=['regular'=>0,'special_regular'=>0,'honorary'=>0];
        if($currentYear) { try { $stmt4=$conn->prepare("SELECT m.member_type, COUNT(DISTINCT ce.member_id) c FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.academic_year_id=? AND ce.status='active' GROUP BY m.member_type");
            $stmt4->bind_param("i",$currentYear['id']); $stmt4->execute(); $r5=$stmt4->get_result();
            if($r5) while($rw=$r5->fetch_assoc()) { $enrolledByType[$rw['member_type']??'regular']=(int)$rw['c']; } $stmt4->close();
        } catch(Exception $e){} }
        echo json_encode(['status'=>'success','classes'=>$classes,'summary'=>[
            'total_members'=>$totalM,'total_enrolled'=>$totalE,'unassigned_members'=>$totalM-$totalE,
            'total_teachers'=>$totalT,'assigned_teachers'=>$assignedT,'unassigned_teachers'=>$totalT-$assignedT,
            'total_classes'=>count($classes),'year_name'=>$currentYear['year_name']??'',
            'type_breakdown'=>$typeBreakdown,'enrolled_by_type'=>$enrolledByType
        ]]);
        break;

    // ============================================================
    // BATCH SYNC MEMBER TYPES (admin maintenance)
    // ============================================================
    case 'sync_member_types':
        $result = batchSyncMemberTypes($conn);
        echo json_encode([
            'status' => 'success',
            'message' => "Checked {$result['checked']} members, fixed {$result['fixed']} member types",
            'details' => $result
        ]);
        break;

    // ============================================================
    // SEARCH MEMBERS (live search for enrollment)
    // ============================================================
    case 'search_members':
        $q=trim($_GET['q']??''); $excClass=(int)($_GET['exclude_class']??0);
        $limit=min(30,max(5,(int)($_GET['limit']??15))); $unassigned=isset($_GET['unassigned'])&&$_GET['unassigned']==='1';
        if (strlen($q)<1) { echo json_encode(['status'=>'success','members'=>[]]); exit; }
        $w=["m.status='active'"]; $p=[]; $t='';
        $w[]="(m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ?)";
        $st="%$q%"; $p=[$st,$st,$st,$st]; $t='ssss';
        if ($excClass>0 && $currentYear) { $w[]="m.id NOT IN (SELECT ce.member_id FROM class_enrollments ce WHERE ce.class_id=? AND ce.academic_year_id=? AND ce.status='active')"; $p[]=$excClass; $p[]=$currentYear['id']; $t.='ii'; }
        if ($unassigned && $currentYear) { $w[]="m.id NOT IN (SELECT ce.member_id FROM class_enrollments ce WHERE ce.academic_year_id=? AND ce.status='active')"; $p[]=$currentYear['id']; $t.='i'; }
        $wc=implode(' AND ',$w); $p[]=$limit; $t.='i';
        $sql="SELECT m.id, m.student_name, m.father_name, m.member_code, m.gender, m.age_group, m.phone_number, m.current_section, m.is_teacher, m.member_type FROM members m WHERE $wc ORDER BY m.student_name LIMIT ?";
        $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute();
        $members=[]; $r=$stmt->get_result(); while($row=$r->fetch_assoc()) $members[]=$row;
        echo json_encode(['status'=>'success','members'=>$members]);
        break;

    // ============================================================
    // SCHOOL-WIDE ROSTER (server-side search / filter / page)
    // ============================================================
    case 'roster':
        $q = trim((string)($_GET['q'] ?? ''));
        $classId = (int)($_GET['class_id'] ?? 0);
        $unassigned = isset($_GET['unassigned']) && $_GET['unassigned'] === '1';
        $gender = trim((string)($_GET['gender'] ?? ''));
        $ageGroup = trim((string)($_GET['age_group'] ?? ''));
        $memberType = trim((string)($_GET['member_type'] ?? ''));
        $status = trim((string)($_GET['status'] ?? 'active'));
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        $sort = trim((string)($_GET['sort'] ?? 'name'));
        $dir = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $yearId = $currentYear ? (int)$currentYear['id'] : 0;
        $w = [];
        $p = [];
        $t = '';

        if ($status !== '' && $status !== 'all') {
            $w[] = 'm.status = ?';
            $p[] = $status;
            $t .= 's';
        } else {
            $w[] = "m.status != 'archived'";
        }
        if ($gender !== '' && in_array($gender, ['male', 'female'], true)) {
            $w[] = 'm.gender = ?';
            $p[] = $gender;
            $t .= 's';
        }
        if ($ageGroup !== '' && in_array($ageGroup, ['7_13', '14_17', '18_plus'], true)) {
            $w[] = 'm.age_group = ?';
            $p[] = $ageGroup;
            $t .= 's';
        }
        if ($memberType !== '' && in_array($memberType, ['regular', 'special_regular', 'honorary'], true)) {
            $w[] = 'm.member_type = ?';
            $p[] = $memberType;
            $t .= 's';
        }
        if ($q !== '') {
            $w[] = '(m.student_name LIKE ? OR m.father_name LIKE ? OR m.grandfather_name LIKE ? OR m.member_code LIKE ? OR m.baptismal_name LIKE ? OR m.phone_number LIKE ?)';
            $st = '%' . $q . '%';
            array_push($p, $st, $st, $st, $st, $st, $st);
            $t .= 'ssssss';
        }

        if ($yearId) {
            $join = "LEFT JOIN class_enrollments ce
                        ON ce.member_id = m.id AND ce.status = 'active' AND ce.academic_year_id = ?
                     LEFT JOIN classes c ON c.id = ce.class_id";
            $p = array_merge([$yearId], $p);
            $t = 'i' . $t;
        } else {
            $join = "LEFT JOIN class_enrollments ce
                        ON ce.member_id = m.id AND ce.status = 'active'
                     LEFT JOIN classes c ON c.id = ce.class_id";
        }

        if ($unassigned) {
            $w[] = 'ce.id IS NULL';
        } elseif ($classId > 0) {
            $w[] = 'ce.class_id = ?';
            $p[] = $classId;
            $t .= 'i';
        }

        if ($teacherId > 0 && $yearId) {
            $w[] = 'ce.class_id IN (SELECT ta.class_id FROM teacher_assignments ta WHERE ta.teacher_id = ? AND ta.is_active = 1 AND ta.academic_year_id = ?)';
            $p[] = $teacherId;
            $p[] = $yearId;
            $t .= 'ii';
        }

        $orderMap = [
            'name' => 'm.student_name',
            'code' => 'm.member_code',
            'class' => 'c.level_order',
            'gender' => 'm.gender',
            'date' => 'ce.enrolled_at',
        ];
        $orderCol = $orderMap[$sort] ?? 'm.student_name';
        $wc = implode(' AND ', $w);

        $countSql = "SELECT COUNT(DISTINCT m.id) AS total FROM members m $join WHERE $wc";
        $stmt = $conn->prepare($countSql);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Query prepare failed.']);
            exit;
        }
        if ($t !== '') {
            $stmt->bind_param($t, ...$p);
        }
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $sql = "SELECT m.id, m.member_code, m.student_name, m.father_name, m.grandfather_name,
                       m.baptismal_name, m.gender, m.age, m.age_group, m.member_type, m.status,
                       m.phone_number, m.current_section, m.education_level, m.is_teacher,
                       ce.id AS enrollment_id, ce.enrolled_at, ce.status AS enrollment_status,
                       c.id AS class_id, c.class_name, c.class_name_en, c.class_code
                FROM members m
                $join
                WHERE $wc
                ORDER BY $orderCol $dir, m.student_name ASC
                LIMIT ? OFFSET ?";
        $fp = $p;
        $ft = $t . 'ii';
        $fp[] = $perPage;
        $fp[] = $offset;
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Query prepare failed.']);
            exit;
        }
        $stmt->bind_param($ft, ...$fp);
        $stmt->execute();
        $rows = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
            'year_id' => $yearId,
            'year_name' => $currentYear['year_name'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'list_teachers':
        $q = trim((string)($_GET['q'] ?? ''));
        $assigned = trim((string)($_GET['assigned'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;
        $yearId = $currentYear ? (int)$currentYear['id'] : 0;

        $w = ["u.role = 'teacher'"];
        $p = [];
        $t = '';
        if (empty($_GET['include_inactive'])) {
            $w[] = 'u.is_active = 1';
        }
        if ($q !== '') {
            $w[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR m.member_code LIKE ?)';
            $st = '%' . $q . '%';
            array_push($p, $st, $st, $st, $st);
            $t .= 'ssss';
        }
        $yc = $yearId ? ' AND ta.academic_year_id = ' . $yearId : '';
        if ($assigned === '1') {
            $w[] = "u.id IN (SELECT ta.teacher_id FROM teacher_assignments ta WHERE ta.is_active = 1 $yc)";
        } elseif ($assigned === '0') {
            $w[] = "u.id NOT IN (SELECT ta.teacher_id FROM teacher_assignments ta WHERE ta.is_active = 1 $yc)";
        }
        $wc = implode(' AND ', $w);

        $csql = "SELECT COUNT(*) AS total FROM users u LEFT JOIN members m ON u.member_id = m.id WHERE $wc";
        if ($t !== '') {
            $stmt = $conn->prepare($csql);
            $stmt->bind_param($t, ...$p);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $r = $conn->query($csql);
            $total = $r ? (int)$r->fetch_assoc()['total'] : 0;
        }

        $sql = "SELECT u.id, u.full_name, u.username, u.email, u.is_active, u.member_id,
                       COALESCE(m.member_code,'') AS member_code,
                       COALESCE(m.phone_number,'') AS phone,
                       (SELECT COUNT(DISTINCT ta.class_id) FROM teacher_assignments ta WHERE ta.teacher_id = u.id AND ta.is_active = 1 $yc) AS assigned_classes
                FROM users u
                LEFT JOIN members m ON u.member_id = m.id
                WHERE $wc
                ORDER BY u.full_name
                LIMIT ? OFFSET ?";
        $fp = $p;
        $ft = $t . 'ii';
        $fp[] = $perPage;
        $fp[] = $offset;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($ft, ...$fp);
        $stmt->execute();
        $teachers = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $teachers[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'teachers' => $teachers,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
} catch (Exception $e) {
    error_log("api_education error [{$action}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again or contact admin.']);
}

$conn->close();
