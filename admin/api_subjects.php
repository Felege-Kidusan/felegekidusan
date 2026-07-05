<?php
/**
 * Subject & Assessment Management API
 * Handles subjects, assessments, and grade recording
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

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

$action = $_REQUEST['action'] ?? '';

// ── Action-level authorization ──
// Teachers are allowed in to grade (get_students_for_grading, save_grades,
// read subjects/assessments). But creating/editing/deleting subjects and
// assessments is education-staff work. Block those for teachers.
$__manageActions = [
    'create_subject', 'update_subject', 'delete_subject', 'assign_subject_to_classes',
    'create_assessment', 'update_assessment', 'delete_assessment',
];
if (in_array($action, $__manageActions, true)) {
    $__role = $_SESSION['admin_role'] ?? '';
    if (!in_array($__role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only the Education department can manage subjects and assessments.']);
        exit;
    }
}

// Get current academic year
$currentYear = null;
$result = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
if ($result) {
    $currentYear = $result->fetch_assoc();
}

try {
switch ($action) {
    // ============================================================
    // SUBJECT MANAGEMENT
    // ============================================================
    
    case 'get_subjects':
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
        
        $sql = "SELECT s.*, 
                (SELECT COUNT(DISTINCT cs.class_id) FROM class_subjects cs WHERE cs.subject_id = s.id) as assigned_classes
                FROM subjects s";
        if (!$includeInactive) {
            $sql .= " WHERE s.is_active = 1";
        }
        $sql .= " ORDER BY s.subject_name";
        
        $result = $conn->query($sql);
        $subjects = [];
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        break;
    
    case 'create_subject':
        $name = trim($_POST['subject_name'] ?? '');
        $nameEn = trim($_POST['subject_name_en'] ?? '');
        $code = trim($_POST['subject_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Subject name is required']);
            exit;
        }
        
        // Generate code if not provided
        if (empty($code)) {
            $code = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $nameEn ?: $name));
            $code = substr($code, 0, 20);
        }
        
        // Check if code exists
        $stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $code .= '_' . time();
        }
        
        $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_name_en, subject_code, description, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param("ssss", $name, $nameEn, $code, $description);
        
        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success', 
                'message' => 'Subject created successfully!',
                'subject_id' => $conn->insert_id
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        }
        break;
    
    case 'update_subject':
        $id = (int)($_POST['subject_id'] ?? 0);
        $name = trim($_POST['subject_name'] ?? '');
        $nameEn = trim($_POST['subject_name_en'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        
        if (!$id || empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Subject ID and name are required']);
            exit;
        }
        
        $stmt = $conn->prepare("UPDATE subjects SET subject_name = ?, subject_name_en = ?, description = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sssii", $name, $nameEn, $description, $isActive, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Subject updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;
    
    case 'delete_subject':
        $id = (int)($_POST['subject_id'] ?? 0);
        
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Subject ID required']);
            exit;
        }
        
        // Check if subject has grades
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM academic_records WHERE subject_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];
        
        if ($count > 0) {
            // Soft delete - just deactivate
            $stmt = $conn->prepare("UPDATE subjects SET is_active = 0 WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(['status' => 'success', 'message' => 'Subject deactivated (has existing grades)']);
        } else {
            // Hard delete
            $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(['status' => 'success', 'message' => 'Subject deleted']);
        }
        break;
    
    case 'assign_subject_to_classes':
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $classIds = $_POST['class_ids'] ?? []; // Array of class IDs
        
        if (!$subjectId) {
            echo json_encode(['status' => 'error', 'message' => 'Subject ID required']);
            exit;
        }
        
        if (!is_array($classIds)) {
            $classIds = json_decode($classIds, true) ?: [];
        }
        
        // First, remove all existing assignments for this subject
        $stmt = $conn->prepare("DELETE FROM class_subjects WHERE subject_id = ?");
        $stmt->bind_param("i", $subjectId);
        $stmt->execute();
        
        // Insert new assignments
        if (!empty($classIds)) {
            $stmt = $conn->prepare("INSERT INTO class_subjects (class_id, subject_id) VALUES (?, ?)");
            foreach ($classIds as $classId) {
                $classId = (int)$classId;
                if ($classId > 0) {
                    $stmt->bind_param("ii", $classId, $subjectId);
                    $stmt->execute();
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Subject assigned to ' . count($classIds) . ' class(es)']);
        break;
    
    case 'get_subject_classes':
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        
        $stmt = $conn->prepare("
            SELECT c.* FROM classes c
            JOIN class_subjects cs ON c.id = cs.class_id
            WHERE cs.subject_id = ? AND c.is_active = 1
            ORDER BY c.level_order
        ");
        $stmt->bind_param("i", $subjectId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $classes = [];
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'classes' => $classes]);
        break;
    
    case 'get_class_subjects':
        $classId = (int)($_GET['class_id'] ?? 0);
        
        $stmt = $conn->prepare("
            SELECT s.* FROM subjects s
            JOIN class_subjects cs ON s.id = cs.subject_id
            WHERE cs.class_id = ? AND s.is_active = 1
            ORDER BY s.subject_name
        ");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $subjects = [];
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        break;

    // ============================================================
    // ASSESSMENT CONFIGURATION
    // ============================================================
    
    case 'get_assessments':
        $classId = (int)($_GET['class_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year']);
            exit;
        }
        
        $sql = "SELECT a.*, 
                (SELECT COUNT(*) FROM academic_records ar WHERE ar.assessment_id = a.id) as grades_entered
                FROM assessments a
                WHERE a.academic_year_id = ?";
        $params = [$currentYear['id']];
        $types = "i";
        
        if ($classId) {
            $sql .= " AND a.class_id = ?";
            $params[] = $classId;
            $types .= "i";
        }
        if ($subjectId) {
            $sql .= " AND a.subject_id = ?";
            $params[] = $subjectId;
            $types .= "i";
        }
        
        $sql .= " ORDER BY a.class_id, a.subject_id, a.assessment_order";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assessments = [];
        while ($row = $result->fetch_assoc()) {
            $assessments[] = $row;
        }
        
        // Calculate total percentage per class-subject
        $totals = [];
        foreach ($assessments as $a) {
            $key = $a['class_id'] . '-' . $a['subject_id'];
            if (!isset($totals[$key])) $totals[$key] = 0;
            $totals[$key] += (float)$a['weight_percentage'];
        }
        
        echo json_encode([
            'status' => 'success', 
            'assessments' => $assessments,
            'totals' => $totals
        ]);
        break;
    
    case 'create_assessment':
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $name = trim($_POST['assessment_name'] ?? '');
        $type = $_POST['assessment_type'] ?? 'test';
        $weight = (float)($_POST['weight_percentage'] ?? 0);
        $maxScore = (float)($_POST['max_score'] ?? 100);
        $description = trim($_POST['description'] ?? '');
        $dueDate = $_POST['due_date'] ?? null;
        
        if (!$classId || !$subjectId || empty($name) || $weight <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Class, subject, name, and weight are required']);
            exit;
        }
        
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year']);
            exit;
        }
        
        // Check current total weight for this class-subject
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(weight_percentage), 0) as total 
            FROM assessments 
            WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?
        ");
        $stmt->bind_param("iii", $classId, $subjectId, $currentYear['id']);
        $stmt->execute();
        $currentTotal = (float)$stmt->get_result()->fetch_assoc()['total'];
        
        if ($currentTotal + $weight > 100) {
            $remaining = 100 - $currentTotal;
            echo json_encode([
                'status' => 'error', 
                'message' => "Cannot add {$weight}%. Current total is {$currentTotal}%, only {$remaining}% remaining."
            ]);
            exit;
        }
        
        // Get next order
        $stmt = $conn->prepare("
            SELECT COALESCE(MAX(assessment_order), 0) + 1 as next_order 
            FROM assessments 
            WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?
        ");
        $stmt->bind_param("iii", $classId, $subjectId, $currentYear['id']);
        $stmt->execute();
        $nextOrder = (int)$stmt->get_result()->fetch_assoc()['next_order'];
        
        // Get current term
        $termId = null;
        $termResult = $conn->query("SELECT id FROM academic_terms WHERE is_current = 1 LIMIT 1");
        if ($termResult && $term = $termResult->fetch_assoc()) {
            $termId = $term['id'];
        }
        
        $stmt = $conn->prepare("
            INSERT INTO assessments 
            (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, 
             weight_percentage, max_score, description, due_date, assessment_order, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $createdBy = $_SESSION['admin_id'];
        $stmt->bind_param(
            "iiiissddssii",
            $classId, $subjectId, $currentYear['id'], $termId, $name, $type,
            $weight, $maxScore, $description, $dueDate, $nextOrder, $createdBy
        );
        
        if ($stmt->execute()) {
            $newTotal = $currentTotal + $weight;
            echo json_encode([
                'status' => 'success', 
                'message' => "Assessment created! Total weight now: {$newTotal}%",
                'assessment_id' => $conn->insert_id,
                'new_total' => $newTotal
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        }
        break;
    
    case 'update_assessment':
        $id = (int)($_POST['assessment_id'] ?? 0);
        $name = trim($_POST['assessment_name'] ?? '');
        $type = $_POST['assessment_type'] ?? 'test';
        $weight = (float)($_POST['weight_percentage'] ?? 0);
        $maxScore = (float)($_POST['max_score'] ?? 100);
        $description = trim($_POST['description'] ?? '');
        $dueDate = $_POST['due_date'] ?? null;
        
        if (!$id || empty($name) || $weight <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
            exit;
        }
        
        // Get current assessment info
        $stmt = $conn->prepare("SELECT class_id, subject_id, academic_year_id, weight_percentage FROM assessments WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        
        if (!$current) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment not found']);
            exit;
        }
        
        // Calculate new total (excluding current assessment)
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(weight_percentage), 0) as total 
            FROM assessments 
            WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND id != ?
        ");
        $stmt->bind_param("iiii", $current['class_id'], $current['subject_id'], $current['academic_year_id'], $id);
        $stmt->execute();
        $otherTotal = (float)$stmt->get_result()->fetch_assoc()['total'];
        
        if ($otherTotal + $weight > 100) {
            $remaining = 100 - $otherTotal;
            echo json_encode([
                'status' => 'error', 
                'message' => "Cannot set {$weight}%. Other assessments total {$otherTotal}%, max allowed: {$remaining}%"
            ]);
            exit;
        }
        
        $stmt = $conn->prepare("
            UPDATE assessments SET 
            assessment_name = ?, assessment_type = ?, weight_percentage = ?, 
            max_score = ?, description = ?, due_date = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssddssi", $name, $type, $weight, $maxScore, $description, $dueDate, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Assessment updated!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;
    
    case 'delete_assessment':
        $id = (int)($_POST['assessment_id'] ?? 0);
        
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment ID required']);
            exit;
        }
        
        // Check if has grades
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM academic_records WHERE assessment_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];
        
        if ($count > 0) {
            echo json_encode(['status' => 'error', 'message' => "Cannot delete - {$count} grades already recorded. Delete grades first."]);
            exit;
        }
        
        $stmt = $conn->prepare("DELETE FROM assessments WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Assessment deleted']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;

    // ============================================================
    // GRADE ENTRY
    // ============================================================
    
    case 'get_students_for_grading':
        $assessmentId = (int)($_GET['assessment_id'] ?? 0);
        
        if (!$assessmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment ID required']);
            exit;
        }
        
        // Get assessment info
        $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param("i", $assessmentId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        
        if (!$assessment) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment not found']);
            exit;
        }
        
        // Get enrolled students with their existing grades
        $stmt = $conn->prepare("
            SELECT 
                ce.member_id,
                m.student_name, m.father_name, m.member_code, m.gender,
                ar.id as record_id, ar.score, ar.remarks
            FROM class_enrollments ce
            JOIN members m ON ce.member_id = m.id
            LEFT JOIN academic_records ar ON ar.member_id = ce.member_id 
                AND ar.assessment_id = ?
            WHERE ce.class_id = ? 
                AND ce.academic_year_id = ?
                AND ce.status = 'active'
            ORDER BY m.student_name
        ");
        $stmt->bind_param("iii", $assessmentId, $assessment['class_id'], $assessment['academic_year_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        
        echo json_encode([
            'status' => 'success',
            'assessment' => $assessment,
            'students' => $students
        ]);
        break;
    
    case 'save_grades':
        $assessmentId = (int)($_POST['assessment_id'] ?? 0);
        $grades = $_POST['grades'] ?? []; // Array of {member_id, score, remarks}
        
        if (!$assessmentId || empty($grades)) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment ID and grades required']);
            exit;
        }
        
        if (!is_array($grades)) {
            $grades = json_decode($grades, true) ?: [];
        }
        
        // Get assessment info
        $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param("i", $assessmentId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        
        if (!$assessment) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment not found']);
            exit;
        }
        
        $recordedBy = $_SESSION['admin_id'];
        $successCount = 0;
        $errors = [];
        
        // Prepare statements
        $insertStmt = $conn->prepare("
            INSERT INTO academic_records 
            (member_id, class_id, subject_id, academic_year_id, term_id, assessment_id, score, max_score, remarks, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $updateStmt = $conn->prepare("
            UPDATE academic_records SET score = ?, remarks = ?, recorded_by = ? WHERE id = ?
        ");
        
        foreach ($grades as $grade) {
            $memberId = (int)($grade['member_id'] ?? 0);
            $score = isset($grade['score']) && $grade['score'] !== '' ? (float)$grade['score'] : null;
            $remarks = trim($grade['remarks'] ?? '');
            $recordId = isset($grade['record_id']) ? (int)$grade['record_id'] : 0;
            
            if (!$memberId) continue;
            
            // Validate score
            if ($score !== null && ($score < 0 || $score > $assessment['max_score'])) {
                $errors[] = "Invalid score for member $memberId";
                continue;
            }
            
            try {
                if ($recordId > 0) {
                    // Update existing
                    $updateStmt->bind_param("dsii", $score, $remarks, $recordedBy, $recordId);
                    $updateStmt->execute();
                } else if ($score !== null) {
                    // Insert new
                    $insertStmt->bind_param(
                        "iiiiiiddsi",
                        $memberId, $assessment['class_id'], $assessment['subject_id'], 
                        $assessment['academic_year_id'], $assessment['term_id'], $assessmentId,
                        $score, $assessment['max_score'], $remarks, $recordedBy
                    );
                    $insertStmt->execute();
                }
                $successCount++;
            } catch (Exception $e) {
                $errors[] = "Error for member $memberId: " . $e->getMessage();
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => "$successCount grade(s) saved successfully",
            'errors' => $errors
        ]);
        break;
    
    case 'get_grade_summary':
        $classId = (int)($_GET['class_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $memberId = (int)($_GET['member_id'] ?? 0);
        
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year']);
            exit;
        }
        
        // Get all assessments and grades for this class-subject
        $sql = "
            SELECT 
                a.id as assessment_id, a.assessment_name, a.assessment_type, 
                a.weight_percentage, a.max_score,
                ar.member_id, ar.score,
                m.student_name, m.father_name
            FROM assessments a
            LEFT JOIN academic_records ar ON ar.assessment_id = a.id
            LEFT JOIN members m ON ar.member_id = m.id
            WHERE a.class_id = ? AND a.academic_year_id = ?
        ";
        $params = [$classId, $currentYear['id']];
        $types = "ii";
        
        if ($subjectId) {
            $sql .= " AND a.subject_id = ?";
            $params[] = $subjectId;
            $types .= "i";
        }
        if ($memberId) {
            $sql .= " AND (ar.member_id = ? OR ar.member_id IS NULL)";
            $params[] = $memberId;
            $types .= "i";
        }
        
        $sql .= " ORDER BY a.assessment_order, m.student_name";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
} catch (Exception $e) {
    error_log("api_subjects error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}

$conn->close();
