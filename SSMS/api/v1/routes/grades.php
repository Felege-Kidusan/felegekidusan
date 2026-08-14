<?php
/**
 * School API v1 — Grades Routes (v2 — teacher-subject filtering)
 * 
 * FIXES:
 * - Teachers only see subjects they're assigned to teach (not all class subjects)
 * - Subject-level access check for teachers
 * - Admins/edu_dept still see all subjects
 * 
 * GET  /grades/subjects?class_id=X              — Subjects for a class
 * GET  /grades/assessments?class_id=X&subject_id=Y — Assessments list
 * POST /grades/assessments                       — Create assessment
 * GET  /grades/students?assessment_id=X          — Students with scores
 * POST /grades/save                              — Save grades
 * GET  /grades/summary?class_id=X                — Grade summary
 */

$auth = apiRequireAuth();
$action = $ROUTE['id'] ?? '';
$sub = $ROUTE['sub'] ?? '';
$year = getCurrentAcademicYear();

if (!$year) {
    err('No active academic year. Set one in Settings.', 400);
}

$yearId = (int)$year['id'];
$userId = $auth['uid'];
$userRole = $auth['rol'];

$restrictedRoles = ['teacher', 'attendance_taker'];
$isRestricted = in_array($userRole, $restrictedRoles);

// Helper: check teacher has access to class
function checkTeacherClassAccess($conn, $userId, $userRole, $classId, $yearId) {
    if (!in_array($userRole, ['teacher', 'attendance_taker'])) return;
    
    $stmt = $conn->prepare("SELECT id FROM teacher_assignments 
                            WHERE teacher_id = ? AND class_id = ? 
                            AND (academic_year_id IS NULL OR academic_year_id = ?)
                            AND is_active = 1");
    $stmt->bind_param('iii', $userId, $classId, $yearId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        err('You are not assigned to this class', 403);
    }
    $stmt->close();
}

// Helper: check teacher has access to specific subject in class
function checkTeacherSubjectAccess($conn, $userId, $userRole, $classId, $subjectId, $yearId) {
    if (!in_array($userRole, ['teacher', 'attendance_taker'])) return;
    
    $stmt = $conn->prepare("SELECT id FROM teacher_assignments 
                            WHERE teacher_id = ? AND class_id = ? AND subject_id = ?
                            AND (academic_year_id IS NULL OR academic_year_id = ?)
                            AND is_active = 1");
    $stmt->bind_param('iiii', $userId, $classId, $subjectId, $yearId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        err('You are not assigned to teach this subject in this class', 403);
    }
    $stmt->close();
}

// ============================================================
// GET /grades/subjects?class_id=X
// ============================================================
if ($action === 'subjects' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    if (!$classId) err('class_id is required');
    
    checkTeacherClassAccess($conn, $userId, $userRole, $classId, $yearId);
    
    $subjects = [];
    try {
        if ($isRestricted) {
            // TEACHER: Only subjects they're assigned to teach in this class
            $stmt = $conn->prepare("SELECT DISTINCT s.id, s.subject_name, s.subject_name_en, s.subject_code
                                    FROM teacher_assignments ta
                                    JOIN subjects s ON ta.subject_id = s.id
                                    WHERE ta.teacher_id = ? 
                                      AND ta.class_id = ?
                                      AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
                                      AND ta.is_active = 1
                                      AND s.is_active = 1
                                    ORDER BY s.subject_name");
            $stmt->bind_param('iii', $userId, $classId, $yearId);
        } else {
            // ADMIN/EDU_DEPT: All subjects for the class
            $stmt = $conn->prepare("SELECT s.id, s.subject_name, s.subject_name_en, s.subject_code
                                    FROM class_subjects cs
                                    JOIN subjects s ON cs.subject_id = s.id
                                    WHERE cs.class_id = ? AND s.is_active = 1
                                    ORDER BY s.subject_name");
            $stmt->bind_param('i', $classId);
        }
        
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $subjects[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        err('Failed to load subjects: ' . $e->getMessage(), 500);
    }
    
    ok(['subjects' => $subjects, 'count' => count($subjects)]);
}

// ============================================================
// GET /grades/assessments?class_id=X&subject_id=Y
// ============================================================
if ($action === 'assessments' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    $subjectId = (int)($_GET['subject_id'] ?? 0);
    if (!$classId || !$subjectId) err('class_id and subject_id are required');
    
    // Teachers can only see assessments for their assigned subjects
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $classId, $subjectId, $yearId);
    }
    
    $assessments = [];
    try {
        $stmt = $conn->prepare("SELECT a.id, a.assessment_name, a.assessment_type, 
                                       a.weight_percentage, a.max_score, a.description,
                                       a.due_date, a.assessment_order, a.is_published,
                                       (SELECT COUNT(*) FROM academic_records ar WHERE ar.assessment_id = a.id) as grades_entered
                                FROM assessments a
                                WHERE a.class_id = ? AND a.subject_id = ? AND a.academic_year_id = ?
                                ORDER BY a.assessment_order, a.created_at");
        $stmt->bind_param('iii', $classId, $subjectId, $yearId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['max_score'] = (float)$row['max_score'];
            $row['weight_percentage'] = (float)$row['weight_percentage'];
            $row['grades_entered'] = (int)$row['grades_entered'];
            $row['is_published'] = (bool)$row['is_published'];
            $assessments[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        err('Failed to load assessments: ' . $e->getMessage(), 500);
    }
    
    ok(['assessments' => $assessments, 'count' => count($assessments)]);
}

// ============================================================
// POST /grades/assessments — Create new assessment
// ============================================================
if ($action === 'assessments' && $method === 'POST') {
    $body = getBody();
    $classId = (int)($body['class_id'] ?? 0);
    $subjectId = (int)($body['subject_id'] ?? 0);
    $name = trim($body['assessment_name'] ?? '');
    $type = $body['assessment_type'] ?? 'test';
    $maxScore = (float)($body['max_score'] ?? 100);
    $weight = (float)($body['weight_percentage'] ?? 100);
    
    if (!$classId || !$subjectId || !$name) {
        err('class_id, subject_id, and assessment_name are required');
    }
    
    // Teachers can only create assessments for their assigned subjects
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $classId, $subjectId, $yearId);
    }
    
    // Validate max score
    if ($maxScore <= 0 || $maxScore > 1000) {
        err('Max score must be between 1 and 1000');
    }
    
    // Validate weight
    if ($weight <= 0 || $weight > 100) {
        err('Weight percentage must be between 1 and 100');
    }
    
    // Validate type
    $validTypes = ['test', 'quiz', 'midterm', 'final', 'assignment', 'project', 'participation', 'other'];
    if (!in_array($type, $validTypes)) {
        $type = 'test';
    }
    
    // Get current term
    $termId = null;
    try {
        $r = $conn->query("SELECT id FROM academic_terms WHERE is_current = 1 LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) $termId = (int)$row['id'];
    } catch (Exception $e) {}
    
    // Get next order
    $order = 1;
    try {
        $stmt = $conn->prepare("SELECT MAX(assessment_order) as mx FROM assessments WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?");
        $stmt->bind_param('iii', $classId, $subjectId, $yearId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        if ($r && $r['mx']) $order = (int)$r['mx'] + 1;
        $stmt->close();
    } catch (Exception $e) {}
    
    try {
        $stmt = $conn->prepare("INSERT INTO assessments 
            (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, weight_percentage, max_score, assessment_order, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiiissddii', $classId, $subjectId, $yearId, $termId, $name, $type, $weight, $maxScore, $order, $userId);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        
        logApiAction($userId, $auth['usr'], 'create_assessment', 
            "Created assessment '{$name}' for class #{$classId} subject #{$subjectId}");
        
        ok(['id' => $newId, 'message' => 'Assessment created']);
    } catch (Exception $e) {
        err('Failed to create assessment: ' . $e->getMessage(), 500);
    }
}

// ============================================================
// GET /grades/students?assessment_id=X — Students with scores
// ============================================================
if ($action === 'students' && $method === 'GET') {
    $assessmentId = (int)($_GET['assessment_id'] ?? 0);
    if (!$assessmentId) err('assessment_id is required');
    
    // Get assessment info
    $assessment = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {}
    
    if (!$assessment) err('Assessment not found', 404);
    
    $aClassId = (int)$assessment['class_id'];
    $aSubjectId = (int)$assessment['subject_id'];
    
    // Check both class AND subject access for teachers
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $aClassId, $aSubjectId, $yearId);
    }
    
    $students = [];
    try {
        $stmt = $conn->prepare("SELECT ce.member_id, m.student_name, m.father_name, m.member_code, m.gender,
                                       ar.id as record_id, ar.score, ar.remarks
                                FROM class_enrollments ce
                                JOIN members m ON ce.member_id = m.id
                                LEFT JOIN academic_records ar ON ar.member_id = ce.member_id AND ar.assessment_id = ?
                                WHERE ce.class_id = ? AND ce.academic_year_id = ? AND ce.status = 'active'
                                ORDER BY m.student_name");
        $stmt->bind_param('iii', $assessmentId, $aClassId, $assessment['academic_year_id']);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $row['member_id'] = (int)$row['member_id'];
            $row['record_id'] = $row['record_id'] ? (int)$row['record_id'] : null;
            $row['score'] = $row['score'] !== null ? (float)$row['score'] : null;
            $students[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        err('Failed to load students: ' . $e->getMessage(), 500);
    }
    
    ok([
        'assessment' => [
            'id' => (int)$assessment['id'],
            'assessment_name' => $assessment['assessment_name'],
            'max_score' => (float)$assessment['max_score'],
            'weight_percentage' => (float)$assessment['weight_percentage'],
        ],
        'students' => $students,
        'count' => count($students),
    ]);
}

// ============================================================
// POST /grades/save — Save grades for an assessment
// ============================================================
if ($action === 'save' && $method === 'POST') {
    $body = getBody();
    $assessmentId = (int)($body['assessment_id'] ?? 0);
    $grades = $body['grades'] ?? [];
    
    if (!$assessmentId || empty($grades)) {
        err('assessment_id and grades array are required');
    }
    
    // Get assessment
    $assessment = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {}
    
    if (!$assessment) err('Assessment not found', 404);
    
    $aClassId = (int)$assessment['class_id'];
    $aSubjectId = (int)$assessment['subject_id'];
    
    // Check both class AND subject access
    if ($isRestricted) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $aClassId, $aSubjectId, $yearId);
    }
    
    $successCount = 0;
    $errors = [];
    
    $insertStmt = $conn->prepare("INSERT INTO academic_records 
        (member_id, class_id, subject_id, academic_year_id, term_id, assessment_id, score, max_score, remarks, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $updateStmt = $conn->prepare("UPDATE academic_records SET score = ?, remarks = ?, recorded_by = ? WHERE id = ?");
    
    foreach ($grades as $grade) {
        $memberId = (int)($grade['member_id'] ?? 0);
        $score = isset($grade['score']) && $grade['score'] !== '' && $grade['score'] !== null ? (float)$grade['score'] : null;
        $remarks = trim($grade['remarks'] ?? $grade['remark'] ?? '');
        $recordId = isset($grade['record_id']) && $grade['record_id'] ? (int)$grade['record_id'] : 0;
        
        if (!$memberId) continue;
        
        if ($score !== null && ($score < 0 || $score > (float)$assessment['max_score'])) {
            $errors[] = "Invalid score for member $memberId (max: {$assessment['max_score']})";
            continue;
        }
        
        try {
            if ($recordId > 0) {
                $updateStmt->bind_param('dsii', $score, $remarks, $userId, $recordId);
                $updateStmt->execute();
            } elseif ($score !== null) {
                $maxScore = (float)$assessment['max_score'];
                $subjectId = $aSubjectId;
                $ayId = (int)$assessment['academic_year_id'];
                $termId = $assessment['term_id'] ? (int)$assessment['term_id'] : null;
                
                $insertStmt->bind_param('iiiiiiddsi', 
                    $memberId, $aClassId, $subjectId, $ayId, $termId, 
                    $assessmentId, $score, $maxScore, $remarks, $userId);
                $insertStmt->execute();
            }
            $successCount++;
        } catch (Exception $e) {
            $errors[] = "Error saving grade for member $memberId";
        }
    }
    
    logApiAction($userId, $auth['usr'], 'save_grades', 
        "Saved $successCount grades for assessment #{$assessmentId}");
    
    ok([
        'message' => "$successCount grade(s) saved",
        'saved' => $successCount,
        'errors' => $errors,
    ]);
}

// ============================================================
// GET /grades/summary?class_id=X&subject_id=Y — Grade report
// ============================================================
if ($action === 'summary' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    $subjectId = (int)($_GET['subject_id'] ?? 0);
    
    if (!$classId) err('class_id is required');
    
    checkTeacherClassAccess($conn, $userId, $userRole, $classId, $yearId);
    
    // For teachers, only show summary for their assigned subjects
    if ($isRestricted && $subjectId) {
        checkTeacherSubjectAccess($conn, $userId, $userRole, $classId, $subjectId, $yearId);
    }
    
    $data = [];
    try {
        $sql = "SELECT a.id as assessment_id, a.assessment_name, a.assessment_type, 
                       a.weight_percentage, a.max_score, a.subject_id,
                       s.subject_name,
                       ar.member_id, ar.score, ar.remarks,
                       m.student_name, m.father_name, m.member_code
                FROM assessments a
                JOIN subjects s ON a.subject_id = s.id
                LEFT JOIN academic_records ar ON ar.assessment_id = a.id
                LEFT JOIN members m ON ar.member_id = m.id
                WHERE a.class_id = ? AND a.academic_year_id = ?";
        $params = [$classId, $yearId];
        $types = 'ii';
        
        if ($subjectId) {
            $sql .= " AND a.subject_id = ?";
            $params[] = $subjectId;
            $types .= 'i';
        } elseif ($isRestricted) {
            // If no subject specified, only show teacher's assigned subjects
            $sql .= " AND a.subject_id IN (SELECT ta2.subject_id FROM teacher_assignments ta2 
                       WHERE ta2.teacher_id = ? AND ta2.class_id = ? 
                       AND (ta2.academic_year_id IS NULL OR ta2.academic_year_id = ?)
                       AND ta2.is_active = 1)";
            $params[] = $userId;
            $params[] = $classId;
            $params[] = $yearId;
            $types .= 'iii';
        }
        
        $sql .= " ORDER BY a.assessment_order, m.student_name";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $data[] = $row;
        $stmt->close();
    } catch (Exception $e) {
        err('Failed to load summary: ' . $e->getMessage(), 500);
    }
    
    ok(['data' => $data, 'class_id' => $classId, 'subject_id' => $subjectId]);
}

err("No handler for {$method} /grades" . ($action ? "/{$action}" : ''), 404);
