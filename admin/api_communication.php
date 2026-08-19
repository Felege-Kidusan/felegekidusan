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
require_once __DIR__ . '/backend/services/SubmissionService.php';
require_once __DIR__ . '/backend/services/ReportCardService.php';

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

// Cheap table check only. Never ALTER / DROP on a read — that locked the mark-list modal.
try {
    \App\Services\SubmissionService::ensureTable($conn);
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
        try {
            $filters = [
                'class_id' => (int)($_GET['class_id'] ?? 0),
                'status' => $_GET['status_filter'] ?? 'attention',
                'type' => $_GET['type'] ?? '',
            ];
            if ($userRole === 'teacher') {
                $filters['teacher_id'] = $userId;
            }
            $submissions = \App\Services\SubmissionService::list($conn, $filters);
            $stats = \App\Services\SubmissionService::stats($conn, $userRole === 'teacher' ? ['teacher_id' => $userId] : []);
            echo json_encode([
                'status' => 'success',
                'submissions' => $submissions,
                'stats' => $stats,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not load submissions. Please try again.']);
        }
        break;

    case 'get_submission_detail':
        try {
            $sid = (int)($_GET['id'] ?? $_GET['submission_id'] ?? 0);
            $detail = \App\Services\SubmissionService::detail($conn, $sid);
            if (!$detail) {
                echo json_encode(['status' => 'error', 'message' => 'Submission not found']);
                break;
            }
            if ($userRole === 'teacher' && (int)$detail['teacher_id'] !== (int)$userId) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                break;
            }
            echo json_encode(['status' => 'success', 'submission' => $detail]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not open this submission.']);
        }
        break;

    case 'get_submission_analytics':
        if (!in_array($userRole, ['edu_dept', 'school_admin', 'super_admin', 'teacher'], true)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            break;
        }
        try {
            echo json_encode([
                'status' => 'success',
                'analytics' => \App\Services\SubmissionService::analytics($conn),
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not load analysis.']);
        }
        break;

    case 'get_submission_stats':
        try {
            $filters = [];
            if ($userRole === 'teacher') {
                $filters['teacher_id'] = $userId;
            }
            echo json_encode([
                'status' => 'success',
                'stats' => \App\Services\SubmissionService::stats($conn, $filters),
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not load summary.']);
        }
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
        $yearId = (int)($_GET['year_id'] ?? 0);
        $termId = (int)($_GET['term_id'] ?? 0);
        if (!$memberId) {
            echo json_encode(['status' => 'error', 'message' => 'Student is required']);
            exit;
        }
        if ($classId && !\App\Services\ReportCardService::canViewClass($conn, (int)$userId, (string)$userRole, $classId)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        try {
            $card = \App\Services\ReportCardService::getCard($conn, $memberId, $classId, $yearId, $termId);
            if (($card['status'] ?? '') === 'success' && $classId <= 0) {
                $resolvedClass = (int)($card['class']['id'] ?? 0);
                if ($resolvedClass && !\App\Services\ReportCardService::canViewClass($conn, (int)$userId, (string)$userRole, $resolvedClass)) {
                    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                    exit;
                }
            }
            echo json_encode($card);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not build this report card.']);
        }
        break;

    case 'get_class_cards':
        $classId = (int)($_GET['class_id'] ?? 0);
        $yearId = (int)($_GET['year_id'] ?? 0);
        $termId = (int)($_GET['term_id'] ?? 0);
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Class is required']);
            exit;
        }
        if (!\App\Services\ReportCardService::canViewClass($conn, (int)$userId, (string)$userRole, $classId)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        try {
            echo json_encode(\App\Services\ReportCardService::getClassCards($conn, $classId, $yearId, $termId));
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not build class report cards.']);
        }
        break;

    case 'get_class_report':
        $classId = (int)($_GET['class_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $yearId = (int)($_GET['year_id'] ?? 0);
        $termId = (int)($_GET['term_id'] ?? 0);
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Class is required']);
            exit;
        }
        if (!\App\Services\ReportCardService::canViewClass($conn, (int)$userId, (string)$userRole, $classId)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        try {
            echo json_encode(\App\Services\ReportCardService::getClassReport($conn, $classId, $subjectId, $yearId, $termId));
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Could not load class performance.']);
        }
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
