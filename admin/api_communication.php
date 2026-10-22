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
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/AttendanceSubmissionEventService.php';
require_once __DIR__ . '/backend/services/AttendanceWarningService.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['admin_id'];
$userRole = $_SESSION['admin_role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        http_response_code(403);
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

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)$_REQUEST['action'] : '';
$__gradeActions = ['submit_marklist', 'get_report_card', 'get_class_cards', 'get_class_report'];
if (in_array($action, $__gradeActions, true) && !feature_enabled('grades')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Grades are not enabled for this deployment.']);
    exit;
}

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

        $assessmentId = (int)($_POST['assessment_id'] ?? 0);
        $grades = $_POST['grades'] ?? '';
        if (!$assessmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment required']); exit;
        }
        if (!is_array($grades)) $grades = json_decode($grades, true) ?: [];

        // Audit 2026-10-20: this path used to trust class_id / subject_id from
        // the form, skip the teacher-assignment check, ignore the submitted /
        // approved lock and INSERT a brand-new packet on every click. It now
        // shares the single canonical write path with api_subjects.php
        // save_grades and the mobile API: the assessment is the authority for
        // class/subject, teachers must be assigned, locked packets refuse,
        // scores upsert, and the packet is upserted as SUBMITTED.
        $stmt = $conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param("i", $assessmentId);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$assessment) { echo json_encode(['status' => 'error', 'message' => 'Assessment not found']); exit; }
        $classId = (int)$assessment['class_id'];
        $subjectId = (int)$assessment['subject_id'];
        $yearId = (int)($assessment['academic_year_id'] ?: ($currentYear['id'] ?? 0)) ?: null;
        $termId = !empty($assessment['term_id']) ? (int)$assessment['term_id'] : ($currentTerm ? (int)$currentTerm['id'] : null);

        if ($userRole === 'teacher') {
            $as = $conn->prepare(
                "SELECT 1 FROM teacher_assignments
                 WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND is_active = 1
                   AND (academic_year_id IS NULL OR academic_year_id = ?) LIMIT 1"
            );
            $as->bind_param('iiii', $userId, $classId, $subjectId, $yearId);
            $as->execute();
            $assigned = (bool)$as->get_result()->fetch_row();
            $as->close();
            if (!$assigned) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'You are not assigned to teach this subject in this class']); exit;
            }
        }
        $auth = ['uid' => (int)$userId, 'usr' => (string)($_SESSION['admin_username'] ?? ''), 'rol' => (string)$userRole];
        $refusal = \App\Services\SubmissionService::teacherWriteRefusal($conn, $auth, $assessmentId);
        if ($refusal !== null) {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => $refusal]); exit;
        }

        $maxScore = (float)$assessment['max_score'];
        // Score-limit rule (2026-10-22): an invalid score used to be skipped
        // while the rest was submitted and locked — that student vanished.
        $badScores = \App\Services\SubmissionService::invalidScores($conn, $grades, $maxScore);
        if ($badScores) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'code' => 'INVALID_SCORES',
                'message' => \App\Services\SubmissionService::invalidScoresMessage($badScores, $maxScore),
                'invalid' => $badScores,
                'max_score' => $maxScore,
            ]);
            exit;
        }
        $conn->begin_transaction();
        try {
            $saved = 0; $totalScore = 0.0; $scoreCount = 0; $skipped = [];
            foreach ($grades as $g) {
                if (!is_array($g)) continue;
                $memberId = (int)($g['member_id'] ?? 0);
                $score = isset($g['score']) && $g['score'] !== '' ? (float)$g['score'] : null;
                $remark = trim((string)($g['remark'] ?? $g['remarks'] ?? ''));
                if (!$memberId) continue;
                if ($score !== null && ($score < 0 || $score > $maxScore)) { $skipped[] = "Invalid score for member $memberId (max: $maxScore)"; continue; }
                if ($score === null && $remark === '') continue;
                $rid = \App\Services\SubmissionService::upsertScore($conn, [
                    'assessment_id' => $assessmentId,
                    'member_id' => $memberId,
                    'score' => $score,
                    'remarks' => $remark,
                    'recorded_by' => (int)$userId,
                    'class_id' => $classId,
                    'subject_id' => $subjectId,
                    'year_id' => $yearId,
                    'term_id' => $termId,
                    'max_score' => $maxScore,
                ]);
                if ($rid <= 0) { $skipped[] = "Member $memberId is not enrolled in this class — mark not saved."; continue; }
                $saved++;
                if ($score !== null) { $totalScore += $score; $scoreCount++; }
            }
            if ($saved === 0) {
                throw new DomainException('No valid scores to submit.');
            }
            $avg = $scoreCount > 0 ? $totalScore / $scoreCount : null;
            $packet = \App\Services\SubmissionService::upsertMarklist($conn, [
                'teacher_id' => (int)$userId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'assessment_id' => $assessmentId,
                'status' => \App\Services\SubmissionService::STATUS_SUBMITTED,
                'student_count' => $saved,
                'average' => $avg,
                'year_id' => $yearId,
                'term_id' => $termId,
                'force' => \App\Services\SubmissionService::staffCanOverride($auth),
            ]);
            if (empty($packet['ok'])) {
                throw new DomainException((string)($packet['message'] ?? 'Could not submit the mark list.'));
            }
            $conn->commit();
            try {
                $lstmt = $conn->prepare(
                    "INSERT INTO activity_logs (user_id, username, action, details, entity_type, entity_id, ip_address)
                     VALUES (?, ?, 'submit_marklist', ?, 'assessment', ?, ?)"
                );
                if ($lstmt) {
                    $ld = "Submitted {$saved} grade(s) for assessment #{$assessmentId}" . ($termId !== null ? " (term #{$termId})" : '');
                    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                    $lstmt->bind_param('issis', $auth['uid'], $auth['usr'], $ld, $assessmentId, $ip);
                    $lstmt->execute();
                    $lstmt->close();
                }
            } catch (Throwable $eLog) { /* auditing never blocks a legitimate submit */ }
            echo json_encode([
                'status' => 'success',
                'message' => "Marklist submitted! $saved grades recorded. Average: " . ($avg !== null ? number_format($avg, 1) : 'N/A'),
                'submission_id' => (int)($packet['id'] ?? 0),
                'saved' => $saved,
                'average' => $avg,
                'errors' => $skipped,
            ]);
        } catch (DomainException $e) {
            $conn->rollback();
            http_response_code(409);
            $safeMessage = $e->getMessage();
            echo json_encode(['status' => 'error', 'message' => $safeMessage]);
        } catch (Throwable $e) {
            $conn->rollback();
            reportInternalError('Mark-list submission failed', $e);
            echo json_encode(['status' => 'error', 'message' => 'Unable to submit the mark list.']);
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
            $stats = \App\Services\SubmissionService::stats($conn, $userRole === 'teacher' ? ['teacher_id' => $userId] : []);
            if (isset($_GET['page'])) {
                // Phase 3 paging (review inbox): page / per_page / total.
                $out = \App\Services\SubmissionService::listPaged(
                    $conn, $filters, (int)$_GET['page'], (int)($_GET['per_page'] ?? 50)
                );
                echo json_encode([
                    'status' => 'success',
                    'submissions' => $out['items'],
                    'total' => $out['total'],
                    'page' => $out['page'],
                    'total_pages' => $out['total_pages'],
                    'per_page' => $out['per_page'],
                    'stats' => $stats,
                ]);
                break;
            }
            $submissions = \App\Services\SubmissionService::list($conn, $filters);
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
    // REVIEW SUBMISSION (Edu Dept approves / rejects / returns for correction)
    // Maker-checker boundary: this action transfers the editing key back to
    // the teacher (revision_needed) or finalizes the packet. Every decision
    // is audited with the actor, previous status, and the written reason.
    // ============================================================
    case 'review_submission':
        if (!in_array($userRole, ['edu_dept','school_admin','super_admin'])) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']); exit;
        }

        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $notes = trim((string)($_POST['notes'] ?? ''));

        if (!$submissionId || !in_array($newStatus, ['approved','rejected','revision_needed'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']); exit;
        }
        // A returned/rejected packet must tell the teacher what to fix —
        // otherwise the unlock is meaningless.
        if ($newStatus !== 'approved' && mb_strlen($notes) < 3) {
            echo json_encode(['status' => 'error', 'message' => 'Write a short reason so the teacher knows what to fix.']); exit;
        }

        // Phase 3 (attendance alerting): ONE shared decision path for web
        // and mobile — transition guard (H9), race-safe guarded UPDATE,
        // SecurityAudit trail, per-packet history, and the attendance
        // warning recalculation after every transition. ATTENDANCE sheets:
        // every non-approve decision (return / reject / reopen, submitted or
        // approved) is edu_dept only — owner decision Q-1, 2026-10-19.
        $review = \App\Services\SubmissionService::reviewPacket(
            $conn, $submissionId, (string)$newStatus, $notes, (int)$userId, (string)$userRole, 'web'
        );
        if (empty($review['ok'])) {
            $code = (string)($review['code'] ?? '');
            if ($code === 'invalid_transition') {
                respondApiError((string)$review['message'], 422, 'invalid_transition');
            }
            if ($code === 'not_awaiting_review') {
                // The packet stopped awaiting review between read and write
                // (concurrent decision/resubmit).
                http_response_code(409);
                echo json_encode(['status' => 'error', 'code' => 'not_awaiting_review', 'message' => (string)$review['message']]); exit;
            }
            if ($code === 'forbidden') {
                http_response_code(403);
            }
            echo json_encode(['status' => 'error', 'message' => (string)$review['message']]); exit;
        }
        echo json_encode(['status' => 'success', 'message' => (string)$review['message'], 'reopened' => !empty($review['reopened'])]);
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
