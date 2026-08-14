<?php
/**
 * ============================================================
 * Communication API — Teacher ↔ Education Department
 * ============================================================
 * Handles:
 * - Marklist submissions (teacher → edu dept)  
 * - Attendance submissions (teacher → edu dept)
 * - Report card generation
 * - Student performance reports
 * - Submission status tracking
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['admin_id'];
$userRole = $_SESSION['admin_role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired']);
        exit;
    }
}

// ── Auto-create submissions tracking table ──
try {
    $conn->query("CREATE TABLE IF NOT EXISTS `grade_submissions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `teacher_id` INT UNSIGNED NOT NULL,
        `class_id` INT UNSIGNED NOT NULL,
        `subject_id` INT UNSIGNED NOT NULL,
        `academic_year_id` INT UNSIGNED DEFAULT NULL,
        `term_id` INT UNSIGNED DEFAULT NULL,
        `assessment_id` INT UNSIGNED DEFAULT NULL,
        `submission_type` ENUM('marklist','attendance','report') NOT NULL DEFAULT 'marklist',
        `status` ENUM('draft','submitted','approved','rejected','revision_needed') NOT NULL DEFAULT 'draft',
        `student_count` INT UNSIGNED DEFAULT 0,
        `average_score` DECIMAL(5,2) DEFAULT NULL,
        `submitted_at` TIMESTAMP NULL DEFAULT NULL,
        `reviewed_by` INT UNSIGNED DEFAULT NULL,
        `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
        `review_notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `teacher_id` (`teacher_id`),
        KEY `class_id` (`class_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add assessment_id column if missing
    $r = $conn->query("SHOW COLUMNS FROM `academic_records` LIKE 'assessment_id'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE `academic_records` ADD COLUMN `assessment_id` INT UNSIGNED DEFAULT NULL AFTER `term_id`");
    }
    // Add submission_id to academic_records
    $r = $conn->query("SHOW COLUMNS FROM `academic_records` LIKE 'submission_id'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE `academic_records` ADD COLUMN `submission_id` INT UNSIGNED DEFAULT NULL AFTER `assessment_id`");
    }
    // Make academic_year_id nullable in academic_records  
    try { $conn->query("ALTER TABLE `academic_records` MODIFY `academic_year_id` INT UNSIGNED DEFAULT NULL"); } catch(Exception $e) {}
    // Drop FKs from academic_records that might block inserts
    try {
        $fks = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_records' 
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        if ($fks) while ($fk = $fks->fetch_assoc()) {
            try { $conn->query("ALTER TABLE `academic_records` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`"); } catch(Exception $e) {}
        }
    } catch(Exception $e) {}
} catch (Exception $e) { /* non-critical */ }

// Get current academic year
// Effective academic year — single source of truth (resolver, time-travel aware)
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

$currentTerm = null;
try {
    $r = $conn->query("SELECT * FROM academic_terms WHERE is_current = 1 LIMIT 1");
    if ($r) $currentTerm = $r->fetch_assoc();
} catch (Exception $e) {}

$action = $_REQUEST['action'] ?? '';

// ── STEP 3: write-protection ────────────────────────────────────────────────
// Marklist submission stamps the active year; refuse writes while time-travelling.
if (function_exists('ay_require_writable')) {
    if (in_array($action, ['submit_marklist'], true)) {
        ay_require_writable($conn);
    } elseif (in_array($action, ['review_submission'], true)) {
        ay_block_if_readonly($conn);
    }
}

switch ($action) {

    // ============================================================
    // SUBMIT MARKLIST (Teacher → Edu Dept)
    // ============================================================
    case 'submit_marklist':
        if ($userRole !== 'teacher' && !in_array($userRole, ['edu_dept','school_admin','super_admin'])) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']); exit;
        }
        
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $assessmentId = (int)($_POST['assessment_id'] ?? 0);
        $grades = $_POST['grades'] ?? '';
        
        if (!$classId || !$subjectId || !$assessmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Class, subject, and assessment required']); exit;
        }
        
        if (!is_array($grades)) $grades = json_decode($grades, true) ?: [];
        
        $yearId = $currentYear ? $currentYear['id'] : null;
        $termId = $currentTerm ? $currentTerm['id'] : null;
        
        // Get assessment info
        $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param("i", $assessmentId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        if (!$assessment) { echo json_encode(['status' => 'error', 'message' => 'Assessment not found']); exit; }
        
        $conn->begin_transaction();
        try {
            // Create submission record
            $stmt = $conn->prepare("INSERT INTO grade_submissions 
                (teacher_id, class_id, subject_id, academic_year_id, term_id, assessment_id, submission_type, status, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, 'marklist', 'submitted', NOW())");
            $stmt->bind_param("iiiiii", $userId, $classId, $subjectId, $yearId, $termId, $assessmentId);
            $stmt->execute();
            $submissionId = $conn->insert_id;
            
            $saved = 0; $totalScore = 0; $scoreCount = 0;
            
            foreach ($grades as $g) {
                $memberId = (int)($g['member_id'] ?? 0);
                $score = isset($g['score']) && $g['score'] !== '' ? (float)$g['score'] : null;
                $remark = trim($g['remark'] ?? $g['remarks'] ?? '');
                
                if (!$memberId) continue;
                if ($score !== null && ($score < 0 || $score > $assessment['max_score'])) continue;
                
                // Upsert: check if record exists for this student+assessment
                $stmt = $conn->prepare("SELECT id FROM academic_records 
                    WHERE member_id = ? AND assessment_id = ? LIMIT 1");
                $stmt->bind_param("ii", $memberId, $assessmentId);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                
                if ($existing) {
                    $stmt = $conn->prepare("UPDATE academic_records 
                        SET score = ?, remarks = ?, recorded_by = ?, submission_id = ?, updated_at = NOW() 
                        WHERE id = ?");
                    $stmt->bind_param("dsiii", $score, $remark, $userId, $submissionId, $existing['id']);
                    $stmt->execute();
                } else if ($score !== null) {
                    $maxScore = $assessment['max_score'];
                    if ($yearId) {
                        $stmt = $conn->prepare("INSERT INTO academic_records 
                            (member_id, class_id, subject_id, academic_year_id, term_id, assessment_id, submission_id, score, max_score, remarks, recorded_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iiiiiiiddsi", $memberId, $classId, $subjectId, $yearId, $termId, $assessmentId, $submissionId, $score, $maxScore, $remark, $userId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO academic_records 
                            (member_id, class_id, subject_id, term_id, assessment_id, submission_id, score, max_score, remarks, recorded_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iiiiiiddsi", $memberId, $classId, $subjectId, $termId, $assessmentId, $submissionId, $score, $maxScore, $remark, $userId);
                    }
                    $stmt->execute();
                }
                
                $saved++;
                if ($score !== null) { $totalScore += $score; $scoreCount++; }
            }
            
            // Update submission stats
            $avg = $scoreCount > 0 ? $totalScore / $scoreCount : null;
            $stmt = $conn->prepare("UPDATE grade_submissions SET student_count = ?, average_score = ? WHERE id = ?");
            $stmt->bind_param("idi", $saved, $avg, $submissionId);
            $stmt->execute();
            
            $conn->commit();
            echo json_encode([
                'status' => 'success', 
                'message' => "Marklist submitted! $saved grades recorded. Average: " . ($avg ? number_format($avg, 1) : 'N/A'),
                'submission_id' => $submissionId,
                'saved' => $saved,
                'average' => $avg
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    // ============================================================
    // GET SUBMISSIONS (Edu Dept sees all, Teacher sees own)
    // ============================================================
    case 'get_submissions':
        $classId = (int)($_GET['class_id'] ?? 0);
        $statusFilter = $_GET['status_filter'] ?? '';
        
        $where = "1=1";
        $params = []; $types = '';
        
        if ($userRole === 'teacher') {
            $where .= " AND gs.teacher_id = ?";
            $params[] = $userId; $types .= 'i';
        }
        if ($classId) {
            $where .= " AND gs.class_id = ?";
            $params[] = $classId; $types .= 'i';
        }
        if ($statusFilter && in_array($statusFilter, ['draft','submitted','approved','rejected','revision_needed'])) {
            $where .= " AND gs.status = ?";
            $params[] = $statusFilter; $types .= 's';
        }
        
        $sql = "SELECT gs.*, 
                    u.full_name as teacher_name,
                    c.class_name, c.class_name_en,
                    s.subject_name, s.subject_name_en,
                    a.assessment_name, a.max_score,
                    ay.year_name,
                    rv.full_name as reviewer_name
                FROM grade_submissions gs
                LEFT JOIN users u ON gs.teacher_id = u.id
                LEFT JOIN classes c ON gs.class_id = c.id
                LEFT JOIN subjects s ON gs.subject_id = s.id
                LEFT JOIN assessments a ON gs.assessment_id = a.id
                LEFT JOIN academic_years ay ON gs.academic_year_id = ay.id
                LEFT JOIN users rv ON gs.reviewed_by = rv.id
                WHERE $where
                ORDER BY gs.created_at DESC
                LIMIT 100";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $submissions = [];
        while ($row = $result->fetch_assoc()) $submissions[] = $row;
        
        echo json_encode(['status' => 'success', 'submissions' => $submissions]);
        break;

    // ============================================================
    // REVIEW SUBMISSION (Edu Dept approves/rejects)
    // ============================================================
    case 'review_submission':
        if (!in_array($userRole, ['edu_dept','school_admin','super_admin'])) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']); exit;
        }
        
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$submissionId || !in_array($newStatus, ['approved','rejected','revision_needed'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']); exit;
        }
        
        $stmt = $conn->prepare("UPDATE grade_submissions 
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ? 
            WHERE id = ?");
        $stmt->bind_param("sisi", $newStatus, $userId, $notes, $submissionId);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Submission ' . $newStatus]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        break;

    // ============================================================
    // GET STUDENT REPORT CARD
    // ============================================================
    case 'get_report_card':
        $memberId = (int)($_GET['member_id'] ?? 0);
        $classId = (int)($_GET['class_id'] ?? 0);
        $yearId = (int)($_GET['year_id'] ?? ($currentYear ? $currentYear['id'] : 0));
        $termId = (int)($_GET['term_id'] ?? ($currentTerm ? $currentTerm['id'] : 0));
        
        if (!$memberId) {
            echo json_encode(['status' => 'error', 'message' => 'Student ID required']); exit;
        }
        
        // Get student info
        $stmt = $conn->prepare("SELECT m.*, ce.class_id FROM members m 
            LEFT JOIN class_enrollments ce ON ce.member_id = m.id AND ce.status = 'active'
            WHERE m.id = ?");
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        if (!$student) { echo json_encode(['status' => 'error', 'message' => 'Student not found']); exit; }
        
        if (!$classId) $classId = (int)($student['class_id'] ?? 0);
        
        // Get class info
        $classInfo = null;
        if ($classId) {
            $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
            $stmt->bind_param("i", $classId);
            $stmt->execute();
            $classInfo = $stmt->get_result()->fetch_assoc();
        }
        
        // Get all subjects for this class
        $subjects = [];
        try {
            $stmt = $conn->prepare("SELECT DISTINCT s.id, s.subject_name, s.subject_name_en 
                FROM subjects s 
                JOIN class_subjects cs ON cs.subject_id = s.id 
                WHERE cs.class_id = ? AND s.is_active = 1 
                ORDER BY s.subject_name");
            $stmt->bind_param("i", $classId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) $subjects[] = $row;
        } catch (Exception $e) {
            // class_subjects might not exist, try from academic_records
            $stmt = $conn->prepare("SELECT DISTINCT s.id, s.subject_name, s.subject_name_en 
                FROM academic_records ar 
                JOIN subjects s ON ar.subject_id = s.id 
                WHERE ar.member_id = ? AND ar.class_id = ? 
                ORDER BY s.subject_name");
            $stmt->bind_param("ii", $memberId, $classId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) $subjects[] = $row;
        }
        
        // Get grades for each subject
        $subjectGrades = [];
        foreach ($subjects as &$subj) {
            $where = "ar.member_id = ? AND ar.class_id = ? AND ar.subject_id = ?";
            $params = [$memberId, $classId, $subj['id']]; $types = 'iii';
            
            if ($yearId) { $where .= " AND ar.academic_year_id = ?"; $params[] = $yearId; $types .= 'i'; }
            if ($termId) { $where .= " AND ar.term_id = ?"; $params[] = $termId; $types .= 'i'; }
            
            $stmt = $conn->prepare("SELECT ar.*, a.assessment_name, a.weight_percentage, a.max_score as assess_max
                FROM academic_records ar 
                LEFT JOIN assessments a ON ar.assessment_id = a.id
                WHERE $where ORDER BY a.assessment_order, ar.recorded_at");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $assessments = [];
            $totalWeighted = 0; $totalWeight = 0;
            while ($rec = $result->fetch_assoc()) {
                $assessments[] = $rec;
                if ($rec['score'] !== null && $rec['max_score'] > 0) {
                    $pct = ($rec['score'] / $rec['max_score']) * 100;
                    $weight = (float)($rec['weight_percentage'] ?? 100);
                    $totalWeighted += $pct * ($weight / 100);
                    $totalWeight += $weight;
                }
            }
            
            $finalPct = $totalWeight > 0 ? ($totalWeighted / $totalWeight) * 100 : null;
            $subj['assessments'] = $assessments;
            $subj['final_percentage'] = $finalPct;
            $subj['grade_letter'] = $finalPct !== null ? getGradeLetter($finalPct) : null;
            $subjectGrades[] = $subj;
        }
        
        // Attendance summary
        $attendance = ['total' => 0, 'present' => 0, 'absent' => 0, 'late' => 0, 'rate' => 0];
        try {
            $where = "a.member_id = ?";
            $params = [$memberId]; $types = 'i';
            if ($classId) { $where .= " AND a.class_id = ?"; $params[] = $classId; $types .= 'i'; }
            
            $stmt = $conn->prepare("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) as late_count
                FROM attendance a WHERE $where");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $att = $stmt->get_result()->fetch_assoc();
            $attendance['total'] = (int)($att['total'] ?? 0);
            $attendance['present'] = (int)($att['present_count'] ?? 0);
            $attendance['absent'] = (int)($att['absent_count'] ?? 0);
            $attendance['late'] = (int)($att['late_count'] ?? 0);
            $attendance['rate'] = $attendance['total'] > 0 
                ? round(($attendance['present'] + $attendance['late']) / $attendance['total'] * 100, 1) : 0;
        } catch (Exception $e) {}
        
        // Calculate overall GPA
        $overallPct = 0; $subjectCount = 0;
        foreach ($subjectGrades as $sg) {
            if ($sg['final_percentage'] !== null) {
                $overallPct += $sg['final_percentage'];
                $subjectCount++;
            }
        }
        $overallAvg = $subjectCount > 0 ? $overallPct / $subjectCount : null;
        
        // Get rank in class
        $rank = null; $totalInClass = 0;
        if ($classId && $overallAvg !== null) {
            try {
                // Get all students' averages
                $sql = "SELECT ar.member_id, AVG(ar.score / ar.max_score * 100) as avg_pct
                    FROM academic_records ar
                    JOIN class_enrollments ce ON ce.member_id = ar.member_id AND ce.class_id = ? AND ce.status = 'active'
                    WHERE ar.class_id = ?" . ($yearId ? " AND ar.academic_year_id = $yearId" : "") .
                    " GROUP BY ar.member_id ORDER BY avg_pct DESC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $classId, $classId);
                $stmt->execute();
                $ranks = $stmt->get_result();
                $pos = 0;
                while ($rk = $ranks->fetch_assoc()) {
                    $pos++;
                    $totalInClass = $pos;
                    if ((int)$rk['member_id'] === $memberId) $rank = $pos;
                }
            } catch (Exception $e) {}
        }
        
        // Year info
        $yearInfo = null;
        if ($yearId) {
            $stmt = $conn->prepare("SELECT * FROM academic_years WHERE id = ?");
            $stmt->bind_param("i", $yearId);
            $stmt->execute();
            $yearInfo = $stmt->get_result()->fetch_assoc();
        }
        
        $termInfo = null;
        if ($termId) {
            $stmt = $conn->prepare("SELECT * FROM academic_terms WHERE id = ?");
            $stmt->bind_param("i", $termId);
            $stmt->execute();
            $termInfo = $stmt->get_result()->fetch_assoc();
        }
        
        echo json_encode([
            'status' => 'success',
            'student' => $student,
            'class' => $classInfo,
            'year' => $yearInfo,
            'term' => $termInfo,
            'subjects' => $subjectGrades,
            'attendance' => $attendance,
            'overall_average' => $overallAvg ? round($overallAvg, 1) : null,
            'overall_grade' => $overallAvg ? getGradeLetter($overallAvg) : null,
            'rank' => $rank,
            'total_in_class' => $totalInClass
        ]);
        break;

    // ============================================================
    // GET CLASS PERFORMANCE REPORT
    // ============================================================
    case 'get_class_report':
        $classId = (int)($_GET['class_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $yearId = (int)($_GET['year_id'] ?? ($currentYear ? $currentYear['id'] : 0));
        
        if (!$classId) { echo json_encode(['status' => 'error', 'message' => 'Class required']); exit; }
        
        // Get all students in class with their averages
        $sql = "SELECT m.id, m.student_name, m.father_name, m.member_code, m.gender,
                    AVG(ar.score) as avg_score,
                    AVG(ar.score / ar.max_score * 100) as avg_percentage,
                    COUNT(ar.id) as grade_count,
                    (SELECT COUNT(*) FROM attendance att WHERE att.member_id = m.id 
                     AND att.status = 'present'" . ($classId ? " AND att.class_id = $classId" : "") . ") as present_days,
                    (SELECT COUNT(*) FROM attendance att WHERE att.member_id = m.id" . 
                     ($classId ? " AND att.class_id = $classId" : "") . ") as total_days
                FROM members m
                JOIN class_enrollments ce ON ce.member_id = m.id AND ce.class_id = ? AND ce.status = 'active'
                LEFT JOIN academic_records ar ON ar.member_id = m.id AND ar.class_id = ?";
        
        $params = [$classId, $classId]; $types = 'ii';
        if ($subjectId) { $sql .= " AND ar.subject_id = ?"; $params[] = $subjectId; $types .= 'i'; }
        if ($yearId) { $sql .= " AND ar.academic_year_id = ?"; $params[] = $yearId; $types .= 'i'; }
        
        $sql .= " GROUP BY m.id ORDER BY avg_percentage DESC";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $students = []; $pos = 0;
        while ($row = $result->fetch_assoc()) {
            $pos++;
            $row['rank'] = $pos;
            $pct = $row['avg_percentage'];
            $row['grade_letter'] = $pct !== null ? getGradeLetter((float)$pct) : null;
            $row['attendance_rate'] = $row['total_days'] > 0 
                ? round($row['present_days'] / $row['total_days'] * 100, 1) : 0;
            $students[] = $row;
        }
        
        // Class stats
        $pcts = array_filter(array_column($students, 'avg_percentage'), fn($v) => $v !== null);
        $stats = [
            'total_students' => count($students),
            'graded_students' => count($pcts),
            'class_average' => !empty($pcts) ? round(array_sum($pcts) / count($pcts), 1) : null,
            'highest' => !empty($pcts) ? round(max($pcts), 1) : null,
            'lowest' => !empty($pcts) ? round(min($pcts), 1) : null,
            'pass_rate' => !empty($pcts) ? round(count(array_filter($pcts, fn($v) => $v >= 50)) / count($pcts) * 100, 1) : null,
            'grade_distribution' => [
                'A' => count(array_filter($pcts, fn($v) => $v >= 90)),
                'B' => count(array_filter($pcts, fn($v) => $v >= 80 && $v < 90)),
                'C' => count(array_filter($pcts, fn($v) => $v >= 70 && $v < 80)),
                'D' => count(array_filter($pcts, fn($v) => $v >= 60 && $v < 70)),
                'F' => count(array_filter($pcts, fn($v) => $v < 60)),
            ]
        ];
        
        echo json_encode(['status' => 'success', 'students' => $students, 'stats' => $stats]);
        break;

    // ============================================================
    // GET TEACHER SUBMISSION STATS (for teacher dashboard)
    // ============================================================
    case 'get_teacher_stats':
        $teacherId = $userRole === 'teacher' ? $userId : (int)($_GET['teacher_id'] ?? $userId);
        
        $stats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];
        try {
            $stmt = $conn->prepare("SELECT status, COUNT(*) c FROM grade_submissions WHERE teacher_id = ? GROUP BY status");
            $stmt->bind_param("i", $teacherId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $stats[$row['status']] = (int)$row['c'];
                $stats['total'] += (int)$row['c'];
            }
            $stats['pending'] = ($stats['submitted'] ?? 0);
        } catch (Exception $e) {}
        
        echo json_encode(['status' => 'success', 'stats' => $stats]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
}

// ── Helper: Grade Letter ──
function getGradeLetter(float $pct): string {
    if ($pct >= 90) return 'A';
    if ($pct >= 80) return 'B';
    if ($pct >= 70) return 'C';
    if ($pct >= 60) return 'D';
    return 'F';
}

$conn->close();
