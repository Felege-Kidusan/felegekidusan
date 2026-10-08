<?php
/**
 * Subject & Assessment Management API
 * Handles subjects, assessments, and grade recording
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/AssignmentService.php';
require_once __DIR__ . '/backend/services/EnrollmentService.php';
require_once __DIR__ . '/backend/services/SubmissionService.php';
require_once __DIR__ . '/backend/services/SubjectDurationPolicy.php';

use App\Services\AssignmentService;
use App\Services\SubjectDurationPolicy;
use App\Services\EnrollmentService;

// Check authentication
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Validate CSRF for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)$_REQUEST['action'] : '';
requirePostActions($action, ['create_subject', 'update_subject', 'delete_subject', 'assign_subject_to_classes', 'create_assessment', 'update_assessment', 'delete_assessment', 'apply_assessment_template', 'save_grades']);
$__gradeActions = [
    'get_assessments', 'create_assessment', 'update_assessment', 'delete_assessment', 'apply_assessment_template',
    'get_students_for_grading', 'save_grades', 'get_grade_summary',
];
if (in_array($action, $__gradeActions, true) && !feature_enabled('grades')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Grades are not enabled for this deployment.']);
    exit;
}

// ── Action-level authorization ──
// Teachers are allowed in to grade (get_students_for_grading, save_grades,
// read subjects/assessments). But creating/editing/deleting subjects and
// assessments is education-staff work. Block those for teachers.
$__manageActions = [
    'create_subject', 'update_subject', 'delete_subject', 'assign_subject_to_classes',
    'create_assessment', 'update_assessment', 'delete_assessment', 'apply_assessment_template',
    // Subject duration is academic configuration: same Education-staff tier as
    // the rest of subject management. The read is gated too — a teacher has no
    // reason to see or change how a subject is scheduled across semesters.
    'get_class_subject_durations', 'save_class_subject_duration',
];
if (in_array($action, $__manageActions, true)) {
    $__role = $_SESSION['admin_role'] ?? '';
    if (!in_array($__role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only the Education department can manage subjects and assessments.']);
        exit;
    }
}

// Effective academic year — single source of truth (resolver, time-travel aware)
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

// ── Teacher assignment scoping (PATCH C3) ───────────────────────────────────
// Web parity with the mobile API (api/v1 checkTeacherSubjectAccess): teachers
// and attendance takers may only see and grade (class, subject) pairs they are
// actively assigned to. Staff roles are not restricted.
$__restrictedRole = in_array($_SESSION['admin_role'] ?? '', ['teacher', 'attendance_taker'], true);
$__uid = (int)$_SESSION['admin_id'];

/** 403 unless $teacherId actively teaches $subjectId in $classId for $yearId. */
function edu_require_assignment(mysqli $conn, int $teacherId, int $classId, int $subjectId, $yearId): void
{
    $stmt = $conn->prepare(
        "SELECT id FROM teacher_assignments
         WHERE teacher_id = ? AND class_id = ? AND subject_id = ?
           AND (academic_year_id IS NULL OR academic_year_id = ?)
           AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('iiii', $teacherId, $classId, $subjectId, $yearId);
    $stmt->execute();
    $has = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if (!$has) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'You are not assigned to teach this subject in this class',
        ]);
        exit;
    }
}

// ── STEP 3: write-protection ────────────────────────────────────────────────
// Refuse writes while time-travelling. Year-scoped writes (assessments, grades,
// class-subject assignment) additionally require an active year to stamp.
if (function_exists('ay_require_writable')) {
    $ayYearScopedWrites = ['assign_subject_to_classes','create_assessment','update_assessment','delete_assessment','apply_assessment_template','save_grades'];
    $ayReadonlyBlocked  = ['create_subject','update_subject','delete_subject'];
    if (in_array($action, $ayYearScopedWrites, true)) {
        ay_require_writable($conn);
    } elseif (in_array($action, $ayReadonlyBlocked, true)) {
        ay_block_if_readonly($conn);
    }
}

// ── Grade-entry term fence (2026-10-08, semester-boundary gap 1) ────────────
// Marks are stamped with the ASSESSMENT's term (save_grades reads
// assessments.term_id), and assessments are stamped with the CURRENT term at
// creation. Before this patch that boundary was invisible and unoverridable:
// a test created after the semester flip silently belonged to the new
// semester, and a teacher entering marks could not see which semester they
// were landing in. The fence makes the boundary explicit:
//   • creation may target another semester of the ACTIVE year (validated),
//   • every read surface names the semester marks will land in,
//   • the teacher UI shows a fence notice when the assessment's semester
//     differs from the current one (back-fill stays allowed, never silent).
function _subj_current_term(mysqli $conn): ?array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $row = null;
    try {
        $activeId = 0;
        if (function_exists('ay_resolve')) $activeId = (int)ay_resolve($conn)['active_id'];
        $st = $conn->prepare(
            "SELECT t.id, t.term_name, t.term_number FROM academic_terms t
              JOIN academic_years y ON y.id = t.academic_year_id
             WHERE t.is_current = 1 AND y.status = 'active'
             ORDER BY t.id DESC LIMIT 1"
        );
        if ($st) {
            $st->execute();
            $row = $st->get_result()->fetch_assoc() ?: null;
            $st->close();
        }
        if (!$row) {
            // Legacy fallback: any current-flagged term (pre-scoping data).
            $r = $conn->query("SELECT id, term_name, term_number FROM academic_terms WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
            if ($r) $row = $r->fetch_assoc() ?: null;
        }
    } catch (Throwable $e) {
        $row = null;
    }
    return $cache = $row;
}

/**
 * A posted term_id is a legal fence override only when it is a semester of
 * the ACTIVE academic year. Returns the term row or null.
 */
function _subj_term_of_active_year(mysqli $conn, int $termId): ?array {
    if ($termId <= 0) return null;
    try {
        $st = $conn->prepare(
            "SELECT t.id, t.term_name, t.term_number FROM academic_terms t
              JOIN academic_years y ON y.id = t.academic_year_id
             WHERE t.id = ? AND y.status = 'active' LIMIT 1"
        );
        if (!$st) return null;
        $st->bind_param('i', $termId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}


try {
switch ($action) {
    // ============================================================
    // SUBJECT MANAGEMENT
    // ============================================================
    
    // Opt-in list controls, exactly as get_classes in api_education.php:
    // called with no parameters (or the long-standing include_inactive=1)
    // the response is unchanged, so the smoke tests and any existing caller
    // keep working. q / status / sort / page / per_page turn it into a root
    // list for Academic Tracking.
    //
    // assigned_classes stays a correlated COUNT over class_subjects -- a
    // cheap relational count. Phase 1 deliberately does NOT compute subject
    // averages here: that would mean a ReportCardService pack per class and
    // would reintroduce the cost Phase 0 measured at +12 queries per class.
    case 'get_subjects':
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
        $subQ = trim((string)($_GET['q'] ?? ''));
        $subStatus = trim((string)($_GET['status'] ?? ''));
        $subSort = trim((string)($_GET['sort'] ?? 'name'));
        $subDir = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $subPaged = isset($_GET['page']) || isset($_GET['per_page']);
        $subPage = max(1, (int)($_GET['page'] ?? 1));
        $subPerPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));

        // Allowlist; never interpolate a caller-supplied column name.
        $subOrderMap = [
            'name' => 's.subject_name',
            'code' => 's.subject_code',
            'classes' => 'assigned_classes',
        ];
        $subOrderCol = $subOrderMap[$subSort] ?? 's.subject_name';

        $subW = [];
        $subP = [];
        $subT = '';
        // `status` wins when supplied; otherwise the historic include_inactive
        // behaviour (active only unless asked) is preserved exactly.
        if ($subStatus === 'active') {
            $subW[] = 's.is_active = 1';
        } elseif ($subStatus === 'inactive') {
            $subW[] = 's.is_active = 0';
        } elseif ($subStatus !== 'all' && !$includeInactive) {
            $subW[] = 's.is_active = 1';
        }
        if ($subQ !== '') {
            $subW[] = '(s.subject_name LIKE ? OR s.subject_name_en LIKE ? OR s.subject_code LIKE ?)';
            $subLike = '%' . $subQ . '%';
            array_push($subP, $subLike, $subLike, $subLike);
            $subT .= 'sss';
        }
        $subWhere = $subW ? ('WHERE ' . implode(' AND ', $subW)) : '';

        $countSql = "SELECT COUNT(*) AS total FROM subjects s $subWhere";
        if ($subT !== '') {
            $st = $conn->prepare($countSql);
            $st->bind_param($subT, ...$subP);
            $st->execute();
            $subTotal = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
            $st->close();
        } else {
            $rc = $conn->query($countSql);
            $subTotal = $rc ? (int)$rc->fetch_assoc()['total'] : 0;
        }

        $sql = "SELECT s.*,
                (SELECT COUNT(DISTINCT cs.class_id) FROM class_subjects cs WHERE cs.subject_id = s.id) as assigned_classes
                FROM subjects s
                $subWhere
                ORDER BY $subOrderCol $subDir, s.subject_name ASC";
        $subFp = $subP;
        $subFt = $subT;
        if ($subPaged) {
            $sql .= " LIMIT ? OFFSET ?";
            $subFp[] = $subPerPage;
            $subFp[] = ($subPage - 1) * $subPerPage;
            $subFt .= 'ii';
        }

        $subjects = [];
        if ($subFt !== '') {
            $st = $conn->prepare($sql);
            $st->bind_param($subFt, ...$subFp);
            $st->execute();
            $result = $st->get_result();
            while ($row = $result->fetch_assoc()) {
                $subjects[] = $row;
            }
            $st->close();
        } else {
            $result = $conn->query($sql);
            while ($row = $result->fetch_assoc()) {
                $subjects[] = $row;
            }
        }

        echo json_encode([
            'status' => 'success',
            'subjects' => $subjects,
            'total' => $subTotal,
            'page' => $subPaged ? $subPage : 1,
            'per_page' => $subPaged ? $subPerPage : max(1, count($subjects)),
            'pages' => $subPaged && $subPerPage > 0 ? (int)ceil($subTotal / $subPerPage) : 1,
        ], JSON_UNESCAPED_UNICODE);
        break;
    
    case 'create_subject':
        $name = trim($_POST['subject_name'] ?? '');
        $nameEn = trim($_POST['subject_name_en'] ?? '');
        $code = trim($_POST['subject_code'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // ---- Validation (audit patch 8): friendly, field-level, Amharic-aware.
        // mb_strlen counts CHARACTERS, so Amharic letters are never over-counted.
        if ($name === '') {
            respondApiError('Subject name is required.', 422, 'validation_error', ['field' => 'subject_name']);
        }
        if (mb_strlen($name, 'UTF-8') > 150) {
            respondApiError(
                'The subject name is too long: ' . mb_strlen($name, 'UTF-8') . ' characters (maximum is 150). Please shorten it.',
                422, 'validation_error', ['field' => 'subject_name', 'max' => 150]
            );
        }
        if (mb_strlen($nameEn, 'UTF-8') > 150) {
            respondApiError(
                'The English name is too long: ' . mb_strlen($nameEn, 'UTF-8') . ' characters (maximum is 150).',
                422, 'validation_error', ['field' => 'subject_name_en', 'max' => 150]
            );
        }
        if (mb_strlen($description, 'UTF-8') > 2000) {
            respondApiError(
                'The description is too long: ' . mb_strlen($description, 'UTF-8') . ' characters (maximum is 2000).',
                422, 'validation_error', ['field' => 'description', 'max' => 2000]
            );
        }

        // Subject code: script-safe generation (never underscores-only, never
        // longer than the column). Fixes "Data too long" on long Amharic names.
        if (!class_exists('\\App\\Services\\CodeGenService')) {
            require_once __DIR__ . '/backend/services/CodeGenService.php';
        }
        $code = \App\Services\CodeGenService::subjectCode($conn, $name, $nameEn, $code);

        $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_name_en, subject_code, description, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param("ssss", $name, $nameEn, $code, $description);

        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Subject created successfully!',
                'subject_id' => $conn->insert_id,
                'subject_code' => $code,
            ]);
        } else {
            reportInternalError('Subject creation failed', $conn->error);
            respondApiError('Unable to save the subject. Please try again.', 500, 'server_error');
        }
        break;
    
    case 'update_subject':
        $id = (int)($_POST['subject_id'] ?? 0);
        $name = trim($_POST['subject_name'] ?? '');
        $nameEn = trim($_POST['subject_name_en'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        
        if (!$id || $name === '') {
            respondApiError('Subject ID and name are required.', 422, 'validation_error');
        }
        if (mb_strlen($name, 'UTF-8') > 150) {
            respondApiError(
                'The subject name is too long: ' . mb_strlen($name, 'UTF-8') . ' characters (maximum is 150). Please shorten it.',
                422, 'validation_error', ['field' => 'subject_name', 'max' => 150]
            );
        }
        if (mb_strlen($nameEn, 'UTF-8') > 150) {
            respondApiError(
                'The English name is too long: ' . mb_strlen($nameEn, 'UTF-8') . ' characters (maximum is 150).',
                422, 'validation_error', ['field' => 'subject_name_en', 'max' => 150]
            );
        }
        if (mb_strlen($description, 'UTF-8') > 2000) {
            respondApiError(
                'The description is too long: ' . mb_strlen($description, 'UTF-8') . ' characters (maximum is 2000).',
                422, 'validation_error', ['field' => 'description', 'max' => 2000]
            );
        }
        
        $stmt = $conn->prepare("UPDATE subjects SET subject_name = ?, subject_name_en = ?, description = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sssii", $name, $nameEn, $description, $isActive, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Subject updated successfully!']);
        } else {
            reportInternalError('api_subjects db write failed', $conn->error);
            respondApiError('The change could not be saved. Please try again.', 500, 'server_error');
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
            // Soft delete - just deactivate (grade history must survive)
            $stmt = $conn->prepare("UPDATE subjects SET is_active = 0 WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(['status' => 'success', 'message' => 'Subject deactivated (has existing grades)']);
        } else {
            // Hard delete — no grade history exists, so remove the subject AND
            // every link row in one transaction (H4: orphan links used to break
            // other teachers' dropdowns and the assignment matrix).
            try {
                $conn->begin_transaction();
                foreach (
                    [
                        "DELETE FROM class_subjects WHERE subject_id = ?",
                        "DELETE FROM teacher_assignments WHERE subject_id = ?",
                        "DELETE FROM grade_submissions WHERE subject_id = ?",
                        "DELETE FROM timetable_entries WHERE subject_id = ?",
                        "DELETE FROM assessments WHERE subject_id = ?",
                        "DELETE FROM subjects WHERE id = ?",
                    ] as $sql
                ) {
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Subject deleted']);
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
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
        echo json_encode(AssignmentService::setClassSubjects($conn, $subjectId, $classIds), JSON_UNESCAPED_UNICODE);
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

        // PATCH C3: restricted roles see only the subjects they teach in the
        // class (same rule as the mobile bootstrap endpoint).
        if ($__restrictedRole) {
            $__scopedYear = (int)($currentYear['id'] ?? 0);
            $stmt = $conn->prepare("
                SELECT DISTINCT s.* FROM subjects s
                JOIN teacher_assignments ta ON ta.subject_id = s.id
                WHERE ta.teacher_id = ? AND ta.class_id = ?
                  AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
                  AND ta.is_active = 1 AND s.is_active = 1
                ORDER BY s.subject_name
            ");
            $stmt->bind_param("iii", $__uid, $classId, $__scopedYear);
            $stmt->execute();
            $result = $stmt->get_result();

            $subjects = [];
            while ($row = $result->fetch_assoc()) {
                $subjects[] = $row;
            }

            // H1: explain WHY the list is empty — class teachers (subject_id
            // NULL assignment) get a specific, actionable message.
            $noSubjNotice = 'You have no subject assignments in this class yet. Ask Education to assign you.';
            if (!$subjects) {
                $nullStmt = $conn->prepare(
                    "SELECT id FROM teacher_assignments
                     WHERE teacher_id = ? AND class_id = ? AND subject_id IS NULL AND is_active = 1 LIMIT 1"
                );
                $nullStmt->bind_param("ii", $__uid, $classId);
                $nullStmt->execute();
                if ($nullStmt->get_result()->num_rows > 0) {
                    $noSubjNotice = 'You are the class teacher here, but no subjects are assigned to you yet. Ask Education to assign you subjects.';
                }
                $nullStmt->close();
            }
            echo json_encode([
                'status' => 'success',
                'subjects' => $subjects,
                'linked' => true,
                'message' => $subjects ? null : $noSubjNotice,
            ]);
            break;
        }

        // Staff: class_subjects links, with an all-subjects fallback when the
        // class has none configured (audit: empty-dropdown symptom).
        $catalog = \App\Services\AssignmentService::subjectsForClass($conn, $classId);
        echo json_encode([
            'status' => 'success',
            'subjects' => $catalog['subjects'],
            'linked' => $catalog['linked'],
            'message' => $catalog['message'],
        ]);
        break;

    // ============================================================
    // SUBJECT DURATION (SEMESTER_ONLY / FULL_YEAR) — migration 056
    // ============================================================
    // Reads and writes class_subjects.duration_type / .term_id, which
    // SubjectDurationPolicy uses to decide whether a subject closes at the end
    // of its semester or is combined across both using the academic year's
    // weights. No calculation happens here: this endpoint only stores the
    // classification the report service reads.

    case 'get_class_subject_durations': {
        $classId = (int)($_GET['class_id'] ?? 0);
        if ($classId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'A class is required.']);
            break;
        }
        $yearId = (int)($_GET['year_id'] ?? ($currentYear['id'] ?? 0));

        // Semesters of the selected year, so the UI offers the EXISTING term
        // structure rather than inventing its own semester list.
        $terms = [];
        $tStmt = $conn->prepare(
            "SELECT id, term_name, term_number, academic_year_id
             FROM academic_terms
             WHERE (? = 0 OR academic_year_id = ?)
             ORDER BY academic_year_id, term_number"
        );
        if ($tStmt) {
            $tStmt->bind_param('ii', $yearId, $yearId);
            $tStmt->execute();
            $tr = $tStmt->get_result();
            while ($row = $tr->fetch_assoc()) {
                $terms[] = [
                    'id' => (int)$row['id'],
                    'term_name' => (string)$row['term_name'],
                    'term_number' => (int)$row['term_number'],
                    'academic_year_id' => (int)$row['academic_year_id'],
                ];
            }
            $tStmt->close();
        }

        $offerings = [];
        $stmt = !SubjectDurationPolicy::supportsOfferingDuration($conn) ? false : $conn->prepare(
            "SELECT cs.id AS offering_id, cs.class_id, cs.subject_id,
                    cs.duration_type, cs.term_id,
                    s.subject_name, s.subject_name_en,
                    c.class_name,
                    t.term_name, t.term_number
             FROM class_subjects cs
             INNER JOIN subjects s ON s.id = cs.subject_id
             LEFT JOIN classes c ON c.id = cs.class_id
             LEFT JOIN academic_terms t ON t.id = cs.term_id
             WHERE cs.class_id = ?
             ORDER BY s.subject_name"
        );
        if (!$stmt) {
            // Migration 056 not applied yet — say so plainly instead of failing.
            echo json_encode([
                'status' => 'error',
                'message' => 'Subject duration is not available yet: migration 056 has not been applied to this database.',
                'migration_required' => '056_subject_duration_and_semester_weights.sql',
            ]);
            break;
        }
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $offerings[] = [
                'offering_id' => (int)$row['offering_id'],
                'class_id' => (int)$row['class_id'],
                'class_name' => (string)($row['class_name'] ?? ''),
                'subject_id' => (int)$row['subject_id'],
                'subject_name' => (string)$row['subject_name'],
                'subject_name_en' => (string)($row['subject_name_en'] ?? ''),
                'duration_type' => $row['duration_type'] !== null ? (string)$row['duration_type'] : null,
                'term_id' => $row['term_id'] !== null ? (int)$row['term_id'] : null,
                'term_name' => $row['term_name'] !== null ? (string)$row['term_name'] : null,
                'term_number' => $row['term_number'] !== null ? (int)$row['term_number'] : null,
            ];
        }
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'class_id' => $classId,
            'year_id' => $yearId,
            'terms' => $terms,
            'offerings' => $offerings,
        ]);
        break;
    }

    case 'save_class_subject_duration': {
        $offeringId = (int)($_POST['offering_id'] ?? 0);
        $rawDuration = trim((string)($_POST['duration_type'] ?? ''));
        $rawTermId = $_POST['term_id'] ?? '';
        $termId = ($rawTermId === '' || $rawTermId === null) ? 0 : (int)$rawTermId;

        if ($offeringId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'A subject offering is required.']);
            break;
        }

        // Allowed values only. Anything else is rejected rather than coerced.
        $upper = strtoupper($rawDuration);
        if ($upper !== '' && $upper !== 'SEMESTER_ONLY' && $upper !== 'FULL_YEAR') {
            echo json_encode([
                'status' => 'error',
                'message' => 'Duration must be Semester Only, Full Year, or Unclassified.',
            ]);
            break;
        }
        $duration = ($upper === '') ? null : $upper;

        // The offering must exist.
        $chk = !SubjectDurationPolicy::supportsOfferingDuration($conn) ? false : $conn->prepare(
            "SELECT cs.id, cs.class_id, cs.subject_id, cs.duration_type, cs.term_id, s.subject_name
             FROM class_subjects cs
             INNER JOIN subjects s ON s.id = cs.subject_id
             WHERE cs.id = ? LIMIT 1"
        );
        if (!$chk) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Subject duration is not available yet: migration 056 has not been applied to this database.',
                'migration_required' => '056_subject_duration_and_semester_weights.sql',
            ]);
            break;
        }
        $chk->bind_param('i', $offeringId);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$existing) {
            echo json_encode(['status' => 'error', 'message' => 'That subject offering no longer exists.']);
            break;
        }

        // SEMESTER_ONLY needs a real semester; FULL_YEAR and Unclassified must
        // not carry one. The term must exist in the EXISTING term structure.
        if ($duration === 'SEMESTER_ONLY') {
            if ($termId <= 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Choose which semester this subject runs in.',
                ]);
                break;
            }
            $tv = $conn->prepare("SELECT id FROM academic_terms WHERE id = ? LIMIT 1");
            $tv->bind_param('i', $termId);
            $tv->execute();
            $termOk = (bool)$tv->get_result()->fetch_assoc();
            $tv->close();
            if (!$termOk) {
                echo json_encode(['status' => 'error', 'message' => 'That semester does not exist.']);
                break;
            }
        } else {
            // A full-year or unclassified offering is not tied to one semester.
            $termId = 0;
        }
        $termParam = $termId > 0 ? $termId : null;

        $upd = !SubjectDurationPolicy::supportsOfferingDuration($conn) ? false : $conn->prepare("UPDATE class_subjects SET duration_type = ?, term_id = ? WHERE id = ?");
        if (!$upd) {
            echo json_encode(['status' => 'error', 'message' => 'Unable to save the subject duration.']);
            break;
        }
        $upd->bind_param('sii', $duration, $termParam, $offeringId);
        if (!$upd->execute()) {
            $upd->close();
            echo json_encode(['status' => 'error', 'message' => 'Unable to save the subject duration.']);
            break;
        }
        $upd->close();

        // Audit through the existing activity_logs mechanism; no new system.
        try {
            $log = $conn->prepare(
                "INSERT INTO activity_logs (user_id, username, action, details, entity_type, entity_id, ip_address)
                 VALUES (?, ?, 'Subject Duration Changed', ?, 'class_subject', ?, ?)"
            );
            if ($log) {
                $uname = (string)($_SESSION['admin_username'] ?? '');
                $uid = (int)($_SESSION['admin_id'] ?? 0);
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                $details = json_encode([
                    'subject' => $existing['subject_name'] ?? '',
                    'class_id' => (int)$existing['class_id'],
                    'from' => ['duration_type' => $existing['duration_type'], 'term_id' => $existing['term_id']],
                    'to' => ['duration_type' => $duration, 'term_id' => $termParam],
                ], JSON_UNESCAPED_UNICODE);
                $log->bind_param('issis', $uid, $uname, $details, $offeringId, $ip);
                $log->execute();
                $log->close();
            }
        } catch (\Throwable $e) {
            // Audit failure must not lose the saved configuration.
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Subject duration saved.',
            'offering_id' => $offeringId,
            'duration_type' => $duration,
            'term_id' => $termParam,
        ]);
        break;
    }

    // ============================================================
    // DYNAMIC ASSESSMENT TYPES (EDUCATION DEPT MANAGEMENT)
    // ============================================================

    case 'get_assessment_types':
        require_once __DIR__ . '/backend/services/AssessmentTypeService.php';
        $activeOnly = isset($_GET['active_only']) && ($_GET['active_only'] === '1' || $_GET['active_only'] === 'true');
        $types = \App\Services\AssessmentTypeService::getAll($conn, $activeOnly);
        echo json_encode(['status' => 'success', 'types' => $types, 'count' => count($types)], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_assessment_type':
        if (!in_array($userRole, ['super_admin', 'school_admin', 'edu_dept'], true)) {
            respondApiError('Only Education Department and administrators can manage assessment types.', 403);
        }
        require_once __DIR__ . '/backend/services/AssessmentTypeService.php';
        $res = \App\Services\AssessmentTypeService::save($conn, $_POST, $userId);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'toggle_assessment_type':
        if (!in_array($userRole, ['super_admin', 'school_admin', 'edu_dept'], true)) {
            respondApiError('Only Education Department and administrators can manage assessment types.', 403);
        }
        require_once __DIR__ . '/backend/services/AssessmentTypeService.php';
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            respondApiError('Invalid assessment type ID.', 422);
        }
        $res = \App\Services\AssessmentTypeService::toggleActive($conn, $id);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;

    case 'delete_assessment_type':
        if (!in_array($userRole, ['super_admin', 'school_admin', 'edu_dept'], true)) {
            respondApiError('Only Education Department and administrators can manage assessment types.', 403);
        }
        require_once __DIR__ . '/backend/services/AssessmentTypeService.php';
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            respondApiError('Invalid assessment type ID.', 422);
        }
        $res = \App\Services\AssessmentTypeService::delete($conn, $id);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
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
        
        $sql = "SELECT a.*, t.term_name, t.term_number,
                (SELECT COUNT(*) FROM academic_records ar WHERE ar.assessment_id = a.id AND ar.score IS NOT NULL) as grades_entered
                FROM assessments a
                LEFT JOIN academic_terms t ON t.id = a.term_id
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

        // PATCH C3: restricted roles only see assessments for (class, subject)
        // pairs they actively teach.
        if ($__restrictedRole) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM teacher_assignments ta
                WHERE ta.teacher_id = ? AND ta.class_id = a.class_id
                  AND ta.subject_id = a.subject_id
                  AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
                  AND ta.is_active = 1
            )";
            $params[] = $__uid;
            $params[] = (int)($currentYear['id'] ?? 0);
            $types .= "ii";
        }

        $sql .= " ORDER BY a.class_id, a.subject_id, a.assessment_order";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $classCounts = [];
        $assessments = [];
        while ($row = $result->fetch_assoc()) {
            $cid = (int)$row['class_id'];
            $yid = (int)$row['academic_year_id'];
            $ckey = $cid . '-' . $yid;
            if (!isset($classCounts[$ckey])) {
                $totalSt = 0;
                if (class_exists('\\App\\Services\\EnrollmentService')) {
                    $scope = \App\Services\EnrollmentService::resolveRosterYear($conn, $cid, $yid);
                    $totalSt = (int)($scope['count'] ?? 0);
                }
                if ($totalSt <= 0) {
                    $cstmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM class_enrollments WHERE class_id = ? AND status = 'active' AND (academic_year_id = ? OR academic_year_id IS NULL OR academic_year_id = 0)");
                    if ($cstmt) {
                        $cstmt->bind_param('ii', $cid, $yid);
                        $cstmt->execute();
                        $totalSt = (int)($cstmt->get_result()->fetch_assoc()['cnt'] ?? 0);
                        $cstmt->close();
                    }
                }
                $classCounts[$ckey] = $totalSt;
            }

            $ge = (int)($row['grades_entered'] ?? 0);
            $ts = $classCounts[$ckey];
            $row['id'] = (int)$row['id'];
            $row['class_id'] = (int)$row['class_id'];
            $row['subject_id'] = (int)$row['subject_id'];
            $row['academic_year_id'] = (int)$row['academic_year_id'];
            $row['weight_percentage'] = (float)$row['weight_percentage'];
            $row['max_score'] = (float)$row['max_score'];
            $row['grades_entered'] = $ge;
            $row['graded_count'] = $ge;
            $row['total_students'] = $ts;
            $row['student_count'] = $ts;
            $row['pending_count'] = max(0, $ts - $ge);
            $row['completion_percentage'] = $ts > 0 ? round(($ge / $ts) * 100, 1) : 0;
            $row['is_complete'] = ($ts > 0 && $ge >= $ts);
            // Term-fence fields: which semester this test belongs to, and
            // (resolved once below) whether that is the current one.
            $row['term_id'] = $row['term_id'] !== null ? (int)$row['term_id'] : null;

            $assessments[] = $row;
        }

        // Current semester context for the fence UI (null when none set).
        $cterm = _subj_current_term($conn);
        $ctermOut = $cterm ? [
            'id' => (int)$cterm['id'],
            'term_name' => (string)$cterm['term_name'],
            'term_number' => (int)$cterm['term_number'],
        ] : null;
        foreach ($assessments as &$a) {
            $a['is_current_term'] = ($ctermOut !== null && $a['term_id'] !== null
                && (int)$a['term_id'] === (int)$ctermOut['id']);
        }
        unset($a);

        // 1.6.5 term-close model: per-row write state for THIS actor — a
        // teacher sees which tests sit in a closed semester (term_locked)
        // and which closed ones the department has reopened for
        // corrections (term_reopened). Staff rows report term_locked =
        // false because staff always pass the write gate.
        $reopenedIds = [];
        try {
            $rr = $conn->query("SELECT id FROM academic_terms WHERE is_reopened = 1");
            if ($rr) {
                while ($rrow = $rr->fetch_assoc()) $reopenedIds[(int)$rrow['id']] = true;
            }
        } catch (Throwable $eReopened) { /* pre-067 database */ }
        $listAuth = [
            'uid' => (int)($_SESSION['admin_id'] ?? 0),
            'usr' => (string)($_SESSION['admin_username'] ?? ''),
            'rol' => (string)($_SESSION['admin_role'] ?? ''),
        ];
        foreach ($assessments as &$a) {
            if ($a['term_id'] === null) {
                $a['term_reopened'] = false; // legacy NULL-term: writable, flagged elsewhere
            } else {
                $a['term_reopened'] = isset($reopenedIds[(int)$a['term_id']]);
            }
            $a['term_locked'] = !\App\Services\SubmissionService::termWritableForTeachers(
                $conn,
                $listAuth,
                $a['term_id']
            );
        }
        unset($a);

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
            'totals' => $totals,
            'current_term' => $ctermOut
        ]);
        break;
    
    case 'create_assessment':
        $name = trim($_POST['assessment_name'] ?? '');
        $type = $_POST['assessment_type'] ?? 'test';
        $weight = (float)($_POST['weight_percentage'] ?? $_POST['weight'] ?? 0);
        $maxScore = (float)($_POST['max_score'] ?? 100);
        $description = trim($_POST['description'] ?? '');
        $dueDate = $_POST['due_date'] ?? null;
        if (empty($dueDate)) $dueDate = null;
        
        if (empty($name) || $weight <= 0 || $maxScore <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment name, valid max score, and positive weight are required']);
            exit;
        }
        
        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year']);
            exit;
        }

        // Resolve target class IDs (support single class_id or array/comma-separated class_ids or 'all')
        $targetClassIds = [];
        $rawClasses = $_POST['class_ids'] ?? $_POST['class_id'] ?? [];
        if ($rawClasses === 'all') {
            $cRes = $conn->query("SELECT id FROM classes WHERE is_active = 1 ORDER BY level_order, id");
            while ($cRow = $cRes->fetch_assoc()) $targetClassIds[] = (int)$cRow['id'];
        } elseif (is_array($rawClasses)) {
            $targetClassIds = array_map('intval', array_filter($rawClasses));
        } elseif (is_string($rawClasses)) {
            $decoded = json_decode($rawClasses, true);
            if (is_array($decoded)) {
                $targetClassIds = array_map('intval', array_filter($decoded));
            } elseif (strpos($rawClasses, ',') !== false) {
                $targetClassIds = array_map('intval', array_filter(explode(',', $rawClasses)));
            } elseif ((int)$rawClasses > 0) {
                $targetClassIds = [(int)$rawClasses];
            }
        } elseif (is_int($rawClasses) && $rawClasses > 0) {
            $targetClassIds = [$rawClasses];
        }

        if (empty($targetClassIds)) {
            echo json_encode(['status' => 'error', 'message' => 'Class selection is required']);
            exit;
        }

        // Resolve target subject IDs (support single subject_id, array/comma-separated subject_ids, 0 or 'all')
        $explicitSubjectIds = [];
        $rawSubjects = $_POST['subject_ids'] ?? $_POST['subject_id'] ?? 0;
        $allSubjectsRequested = false;
        if ($rawSubjects === 'all' || $rawSubjects === 0 || $rawSubjects === '0') {
            $allSubjectsRequested = true;
        } elseif (is_array($rawSubjects)) {
            $explicitSubjectIds = array_map('intval', array_filter($rawSubjects));
            if (empty($explicitSubjectIds) || in_array(0, $explicitSubjectIds, true)) $allSubjectsRequested = true;
        } elseif (is_string($rawSubjects)) {
            $decoded = json_decode($rawSubjects, true);
            if (is_array($decoded)) {
                $explicitSubjectIds = array_map('intval', array_filter($decoded));
                if (empty($explicitSubjectIds) || in_array(0, $explicitSubjectIds, true)) $allSubjectsRequested = true;
            } elseif (strpos($rawSubjects, ',') !== false) {
                $explicitSubjectIds = array_map('intval', array_filter(explode(',', $rawSubjects)));
            } elseif ((int)$rawSubjects > 0) {
                $explicitSubjectIds = [(int)$rawSubjects];
            } else {
                $allSubjectsRequested = true;
            }
        } elseif (is_int($rawSubjects) && $rawSubjects > 0) {
            $explicitSubjectIds = [$rawSubjects];
        } else {
            $allSubjectsRequested = true;
        }

        // Single target assessment creation
        if (count($targetClassIds) === 1 && !$allSubjectsRequested && count($explicitSubjectIds) === 1) {
            $classId = $targetClassIds[0];
            $subjectId = $explicitSubjectIds[0];

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(weight_percentage), 0) as total 
                FROM assessments 
                WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?
            ");
            $stmt->bind_param("iii", $classId, $subjectId, $currentYear['id']);
            $stmt->execute();
            $currentTotal = (float)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            
            if ($currentTotal + $weight > 100) {
                $remaining = 100 - $currentTotal;
                echo json_encode([
                    'status' => 'error', 
                    'message' => "Cannot add {$weight}%. Current total is {$currentTotal}%, only {$remaining}% remaining."
                ]);
                exit;
            }
            
            $stmt = $conn->prepare("
                SELECT COALESCE(MAX(assessment_order), 0) + 1 as next_order 
                FROM assessments 
                WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?
            ");
            $stmt->bind_param("iii", $classId, $subjectId, $currentYear['id']);
            $stmt->execute();
            $nextOrder = (int)$stmt->get_result()->fetch_assoc()['next_order'];
            $stmt->close();
            
            $termId = null; $termName = null;
            $ct = _subj_current_term($conn);
            if ($ct) { $termId = (int)$ct['id']; $termName = (string)$ct['term_name']; }
            // Fence override: the creator may explicitly target another
            // semester of the ACTIVE year (back-fill after a flip). Anything
            // else — foreign year, closed year, invented id — is rejected.
            $postedTermId = (int)($_POST['term_id'] ?? 0);
            if ($postedTermId > 0) {
                $vt = _subj_term_of_active_year($conn, $postedTermId);
                if (!$vt) {
                    echo json_encode(['status' => 'error', 'message' => 'That semester is not part of the active academic year.']);
                    exit;
                }
                $termId = (int)$vt['id'];
                $termName = (string)$vt['term_name'];
            }
            
            $stmt = $conn->prepare("
                INSERT INTO assessments 
                (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, 
                 weight_percentage, max_score, description, due_date, assessment_order, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $createdBy = (int)$_SESSION['admin_id'];
            $stmt->bind_param(
                "iiiissddssii",
                $classId, $subjectId, $currentYear['id'], $termId, $name, $type,
                $weight, $maxScore, $description, $dueDate, $nextOrder, $createdBy
            );
            
            if ($stmt->execute()) {
                $newId = $conn->insert_id;
                $stmt->close();
                $newTotal = $currentTotal + $weight;
                echo json_encode([
                    'status' => 'success', 
                    'message' => "Assessment created! Total weight now: {$newTotal}%",
                    'assessment_id' => $newId,
                    'new_total' => $newTotal,
                    'term_id' => $termId,
                    'term_name' => $termName
                ]);
            } else {
                $err = $conn->error;
                $stmt->close();
                reportInternalError('Assessment creation failed', $err);
                echo json_encode(['status' => 'error', 'message' => 'Unable to save the record.']);
            }
            break;
        }

        // Multi-target batch creation across multiple classes / subjects
        $termId = null;
        $termResult = $conn->query("SELECT id FROM academic_terms WHERE is_current = 1 LIMIT 1");
        if ($termResult && $term = $termResult->fetch_assoc()) {
            $termId = $term['id'];
        }
        $createdBy = (int)$_SESSION['admin_id'];
        $createdCount = 0;
        $exceededCount = 0;

        $conn->begin_transaction();
        try {
            foreach ($targetClassIds as $cid) {
                $sids = [];
                if ($allSubjectsRequested) {
                    $stmt = $conn->prepare("SELECT subject_id FROM class_subjects WHERE class_id = ?");
                    $stmt->bind_param("i", $cid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) $sids[] = (int)$r['subject_id'];
                    $stmt->close();

                    if (empty($sids)) {
                        $res = $conn->query("SELECT id FROM subjects WHERE is_active = 1");
                        while ($r = $res->fetch_assoc()) $sids[] = (int)$r['id'];
                    }
                } else {
                    $sids = $explicitSubjectIds;
                }

                foreach ($sids as $sid) {
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(weight_percentage), 0) as total,
                               COALESCE(MAX(assessment_order), 0) + 1 as next_order
                        FROM assessments 
                        WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?
                    ");
                    $stmt->bind_param("iii", $cid, $sid, $currentYear['id']);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $currTot = (float)($row['total'] ?? 0);
                    $nextOrd = (int)($row['next_order'] ?? 1);

                    if ($currTot + $weight > 100) {
                        $exceededCount++;
                        continue;
                    }

                    $ins = $conn->prepare("
                        INSERT INTO assessments 
                        (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, 
                         weight_percentage, max_score, description, due_date, assessment_order, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->bind_param(
                        "iiiissddssii",
                        $cid, $sid, $currentYear['id'], $termId, $name, $type,
                        $weight, $maxScore, $description, $dueDate, $nextOrd, $createdBy
                    );
                    $ins->execute();
                    $ins->close();
                    $createdCount++;
                }
            }

            $conn->commit();
            $msg = "Assessment created across {$createdCount} class-subject(s).";
            if ($exceededCount > 0) {
                $msg .= " ({$exceededCount} skipped because total weight would exceed 100%).";
            }
            echo json_encode([
                'status' => 'success',
                'message' => $msg,
                'created_count' => $createdCount,
                'exceeded_count' => $exceededCount
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            reportInternalError('Batch assessment create failed', $e);
            echo json_encode(['status' => 'error', 'message' => 'Failed to create assessments. Please try again.']);
        }
        break;

    case 'apply_assessment_template':
        $rawItems = $_POST['items'] ?? '[]';
        $items = is_array($rawItems) ? $rawItems : (json_decode($rawItems, true) ?: []);

        if (empty($items)) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment items are required']);
            exit;
        }

        if (!$currentYear) {
            echo json_encode(['status' => 'error', 'message' => 'No active academic year']);
            exit;
        }

        // Validate template items
        $totalWeight = 0;
        foreach ($items as $it) {
            $w = (float)($it['weight_percentage'] ?? $it['weight'] ?? 0);
            $maxS = (float)($it['max_score'] ?? 100);
            $name = trim($it['name'] ?? $it['assessment_name'] ?? '');
            if (empty($name) || $w <= 0 || $maxS <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Each assessment item must have a name, valid max score, and positive weight.']);
                exit;
            }
            $totalWeight += $w;
        }

        if ($totalWeight > 100) {
            echo json_encode(['status' => 'error', 'message' => "Total template weight is {$totalWeight}%, which exceeds 100%."]);
            exit;
        }

        // Resolve target class IDs (support single class_id or array/comma-separated class_ids or 'all')
        $targetClassIds = [];
        $rawClasses = $_POST['class_ids'] ?? $_POST['class_id'] ?? [];
        if ($rawClasses === 'all') {
            $cRes = $conn->query("SELECT id FROM classes WHERE is_active = 1 ORDER BY level_order, id");
            while ($cRow = $cRes->fetch_assoc()) $targetClassIds[] = (int)$cRow['id'];
        } elseif (is_array($rawClasses)) {
            $targetClassIds = array_map('intval', array_filter($rawClasses));
        } elseif (is_string($rawClasses)) {
            $decoded = json_decode($rawClasses, true);
            if (is_array($decoded)) {
                $targetClassIds = array_map('intval', array_filter($decoded));
            } elseif (strpos($rawClasses, ',') !== false) {
                $targetClassIds = array_map('intval', array_filter(explode(',', $rawClasses)));
            } elseif ((int)$rawClasses > 0) {
                $targetClassIds = [(int)$rawClasses];
            }
        } elseif (is_int($rawClasses) && $rawClasses > 0) {
            $targetClassIds = [$rawClasses];
        }

        if (empty($targetClassIds)) {
            echo json_encode(['status' => 'error', 'message' => 'Target class(es) are required']);
            exit;
        }

        // Resolve target subject IDs (support single subject_id, array/comma-separated subject_ids, 0 or 'all')
        $explicitSubjectIds = [];
        $rawSubjects = $_POST['subject_ids'] ?? $_POST['subject_id'] ?? 0;
        $allSubjectsRequested = false;
        if ($rawSubjects === 'all' || $rawSubjects === 0 || $rawSubjects === '0') {
            $allSubjectsRequested = true;
        } elseif (is_array($rawSubjects)) {
            $explicitSubjectIds = array_map('intval', array_filter($rawSubjects));
            if (empty($explicitSubjectIds) || in_array(0, $explicitSubjectIds, true)) $allSubjectsRequested = true;
        } elseif (is_string($rawSubjects)) {
            $decoded = json_decode($rawSubjects, true);
            if (is_array($decoded)) {
                $explicitSubjectIds = array_map('intval', array_filter($decoded));
                if (empty($explicitSubjectIds) || in_array(0, $explicitSubjectIds, true)) $allSubjectsRequested = true;
            } elseif (strpos($rawSubjects, ',') !== false) {
                $explicitSubjectIds = array_map('intval', array_filter(explode(',', $rawSubjects)));
            } elseif ((int)$rawSubjects > 0) {
                $explicitSubjectIds = [(int)$rawSubjects];
            } else {
                $allSubjectsRequested = true;
            }
        } elseif (is_int($rawSubjects) && $rawSubjects > 0) {
            $explicitSubjectIds = [$rawSubjects];
        } else {
            $allSubjectsRequested = true;
        }

        // Get current term
        $termId = null;
        $termResult = $conn->query("SELECT id FROM academic_terms WHERE is_current = 1 LIMIT 1");
        if ($termResult && $term = $termResult->fetch_assoc()) {
            $termId = (int)$term['id'];
        }

        $createdBy = (int)$_SESSION['admin_id'];
        $appliedCount = 0;
        $skippedCount = 0;

        $conn->begin_transaction();
        try {
            foreach ($targetClassIds as $cid) {
                $subjectIdsForClass = [];
                if ($allSubjectsRequested) {
                    $stmt = $conn->prepare("SELECT subject_id FROM class_subjects WHERE class_id = ?");
                    $stmt->bind_param("i", $cid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) {
                        $subjectIdsForClass[] = (int)$r['subject_id'];
                    }
                    $stmt->close();

                    if (empty($subjectIdsForClass)) {
                        $res = $conn->query("SELECT id FROM subjects WHERE is_active = 1");
                        while ($r = $res->fetch_assoc()) {
                            $subjectIdsForClass[] = (int)$r['id'];
                        }
                    }
                } else {
                    $subjectIdsForClass = $explicitSubjectIds;
                }

                foreach ($subjectIdsForClass as $sid) {
                    // Check if existing assessments have grades recorded
                    $stmt = $conn->prepare("
                        SELECT COUNT(*) as grade_count 
                        FROM academic_records ar
                        JOIN assessments a ON ar.assessment_id = a.id
                        WHERE a.class_id = ? AND a.subject_id = ? AND a.academic_year_id = ?
                    ");
                    $stmt->bind_param("iii", $cid, $sid, $currentYear['id']);
                    $stmt->execute();
                    $hasGrades = (int)$stmt->get_result()->fetch_assoc()['grade_count'] > 0;
                    $stmt->close();

                    if ($hasGrades) {
                        $skippedCount++;
                        continue; // Do not overwrite existing graded assessments
                    }

                    // Delete previous un-graded assessments for this class-subject
                    $stmt = $conn->prepare("DELETE FROM assessments WHERE class_id = ? AND subject_id = ? AND academic_year_id = ?");
                    $stmt->bind_param("iii", $cid, $sid, $currentYear['id']);
                    $stmt->execute();
                    $stmt->close();

                    // Insert new assessment items
                    $order = 1;
                    foreach ($items as $it) {
                        $name = trim($it['name'] ?? $it['assessment_name'] ?? '');
                        $type = $it['type'] ?? $it['assessment_type'] ?? 'test';
                        $w = (float)($it['weight_percentage'] ?? $it['weight'] ?? 0);
                        $maxS = (float)($it['max_score'] ?? 100);
                        $desc = trim($it['description'] ?? '');

                        $ins = $conn->prepare("
                            INSERT INTO assessments 
                            (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, 
                             weight_percentage, max_score, description, assessment_order, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $ins->bind_param("iiiissddssi", $cid, $sid, $currentYear['id'], $termId, $name, $type, $w, $maxS, $desc, $order, $createdBy);
                        $ins->execute();
                        $ins->close();
                        $order++;
                    }
                    $appliedCount++;
                }
            }

            $conn->commit();

            $msg = "Assessment scheme applied to {$appliedCount} class-subject(s) across " . count($targetClassIds) . " class(es).";
            if ($skippedCount > 0) {
                $msg .= " ({$skippedCount} class-subject(s) skipped because student grades were already recorded).";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $msg,
                'applied_count' => $appliedCount,
                'skipped_count' => $skippedCount,
                'classes_count' => count($targetClassIds)
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            reportInternalError('apply_assessment_template failed', $e);
            echo json_encode(['status' => 'error', 'message' => 'Failed to apply assessment template. Please try again.']);
        }
        break;
    
    case 'update_assessment':
        $id = (int)($_POST['assessment_id'] ?? 0);
        $name = trim($_POST['assessment_name'] ?? '');
        $type = $_POST['assessment_type'] ?? 'test';
        $weight = (float)($_POST['weight_percentage'] ?? $_POST['weight'] ?? 0);
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
            $stmt->close();
            // ── Term fence remediation ─────────────────────────────────────
            // Moving an assessment to another semester (of the ACTIVE year)
            // also re-stamps that assessment's existing marks, so all marks
            // of one test always sit in one semester. One transaction: the
            // assessment row and its academic_records move together or not
            // at all. This is the escape hatch for legacy NULL-term tests.
            $postedTermId = isset($_POST['term_id']) && $_POST['term_id'] !== '' ? (int)$_POST['term_id'] : 0;
            if ($postedTermId > 0) {
                $vt = _subj_term_of_active_year($conn, $postedTermId);
                if (!$vt) {
                    echo json_encode(['status' => 'error', 'message' => 'That semester is not part of the active academic year. The other changes were saved; the semester was not changed.']);
                    exit;
                }
                try {
                    $conn->begin_transaction();
                    $u1 = $conn->prepare("UPDATE assessments SET term_id=? WHERE id=?");
                    $u1->bind_param('ii', $postedTermId, $id);
                    $u1->execute(); $u1->close();
                    $u2 = $conn->prepare("UPDATE academic_records SET term_id=? WHERE assessment_id=?");
                    $u2->bind_param('ii', $postedTermId, $id);
                    $u2->execute(); $u2->close();
                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Assessment updated and moved to '.$vt['term_name'].' (its recorded marks were re-stamped to that semester).']);
                } catch (Exception $eMove) {
                    try { $conn->rollback(); } catch (Throwable $rIgnore) {}
                    reportInternalError('Assessment term move failed', $eMove);
                    echo json_encode(['status' => 'error', 'message' => 'Assessment updated, but moving it to the other semester failed. No marks were changed.']);
                }
            } else {
                echo json_encode(['status' => 'success', 'message' => 'Assessment updated!']);
            }
        } else {
            reportInternalError('api_subjects db write failed', $conn->error);
            respondApiError('The change could not be saved. Please try again.', 500, 'server_error');
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
            reportInternalError('api_subjects db write failed', $conn->error);
            respondApiError('The change could not be saved. Please try again.', 500, 'server_error');
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

        // PATCH C3: restricted roles may only read a roster for grading if
        // they teach this class + subject.
        if ($__restrictedRole) {
            edu_require_assignment(
                $conn, $__uid,
                (int)$assessment['class_id'], (int)$assessment['subject_id'],
                (int)($assessment['academic_year_id'] ?: ($currentYear['id'] ?? 0))
            );
        }

        $classId = (int)$assessment['class_id'];
        $preferYear = (int)($assessment['academic_year_id'] ?: ($currentYear['id'] ?? 0));
        $scope = class_exists('\\App\\Services\\EnrollmentService')
            ? \App\Services\EnrollmentService::resolveRosterYear($conn, $classId, $preferYear ?: null)
            : ['year_id' => $preferYear ?: null, 'fallback' => false, 'year_name' => null];
        $roster = class_exists('\\App\\Services\\EnrollmentService')
            ? \App\Services\EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null)
            : [];

        $gradesByMember = [];
        $gstmt = $conn->prepare("SELECT id, member_id, score, remarks FROM academic_records WHERE assessment_id = ?");
        if ($gstmt) {
            $gstmt->bind_param('i', $assessmentId);
            $gstmt->execute();
            $gr = $gstmt->get_result();
            while ($grow = $gr->fetch_assoc()) {
                $gradesByMember[(int)$grow['member_id']] = $grow;
            }
            $gstmt->close();
        }

        $students = [];
        $gradedCount = 0;
        foreach ($roster as $row) {
            $mid = (int)($row['member_id'] ?? $row['id'] ?? 0);
            if ($mid <= 0) continue;
            $g = $gradesByMember[$mid] ?? null;
            $hasScore = ($g && $g['score'] !== null && $g['score'] !== '');
            if ($hasScore) {
                $gradedCount++;
            }
            $students[] = [
                'member_id' => $mid,
                'id' => $mid,
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
                'member_code' => $row['member_code'] ?? '',
                'gender' => $row['gender'] ?? '',
                'record_id' => $g && !empty($g['id']) ? (int)$g['id'] : null,
                'score' => $g && $g['score'] !== null ? (float)$g['score'] : null,
                'remarks' => $g['remarks'] ?? ($g['remark'] ?? ''),
                'remark' => $g['remarks'] ?? ($g['remark'] ?? ''),
            ];
        }
        
        $totalStudents = count($students);
        $pendingCount = max(0, $totalStudents - $gradedCount);
        $completionPercentage = $totalStudents > 0 ? round(($gradedCount / $totalStudents) * 100, 1) : 0;

        // ── Term-fence context: which semester these marks land in ──
        $cterm = _subj_current_term($conn);
        if ($cterm) {
            $assessment['current_term_id'] = (int)$cterm['id'];
            $assessment['current_term_name'] = (string)$cterm['term_name'];
        }
        if (!empty($assessment['term_id'])) {
            $atid = (int)$assessment['term_id'];
            $at = _subj_term_of_active_year($conn, $atid);
            if (!$at) {
                // The term may belong to a non-active year (closed year) —
                // resolve its name directly instead.
                try {
                    $st = $conn->prepare("SELECT id, term_name, term_number FROM academic_terms WHERE id=? LIMIT 1");
                    if ($st) { $st->bind_param('i', $atid); $st->execute(); $at = $st->get_result()->fetch_assoc() ?: null; $st->close(); }
                } catch (Throwable $eAt) { $at = null; }
            }
            if ($at) {
                $assessment['term_name'] = (string)$at['term_name'];
                $assessment['term_number'] = (int)$at['term_number'];
            }
            $assessment['is_current_term'] = ($cterm && $atid === (int)$cterm['id']);
        } else {
            $assessment['term_id'] = null;
            $assessment['is_current_term'] = null; // legacy: no semester assigned
        }
        // ── 1.6.5 term-close gate: is THIS actor allowed to write marks
        // into this assessment's semester? (Teachers: current or reopened
        // semesters only; staff always pass.) The entry screens use this to
        // lock the UI before a keystroke is wasted; the save path enforces
        // the same rule server-side via teacherWriteRefusal().
        $wAuth = [
            'uid' => (int)($_SESSION['admin_id'] ?? 0),
            'usr' => (string)($_SESSION['admin_username'] ?? ''),
            'rol' => (string)($_SESSION['admin_role'] ?? ''),
        ];
        $assessment['term_locked'] = !\App\Services\SubmissionService::termWritableForTeachers(
            $conn,
            $wAuth,
            !empty($assessment['term_id']) ? (int)$assessment['term_id'] : null
        );
        if (!empty($assessment['term_id'])) {
            try {
                $rst = $conn->prepare("SELECT is_reopened FROM academic_terms WHERE id=? LIMIT 1");
                if ($rst) {
                    $rst->bind_param('i', (int)$assessment['term_id']);
                    $rst->execute();
                    $rrow = $rst->get_result()->fetch_assoc();
                    $rst->close();
                    $assessment['term_reopened'] = $rrow !== null && (int)$rrow['is_reopened'] === 1;
                }
            } catch (Throwable $eRr) { /* pre-067 database */ }
        }

        echo json_encode([
            'status' => 'success',
            'assessment' => $assessment,
            'students' => $students,
            'count' => $totalStudents,
            'total_students' => $totalStudents,
            'graded_count' => $gradedCount,
            'pending_count' => $pendingCount,
            'completion_percentage' => $completionPercentage,
            'roster_year_id' => $scope['year_id'] ?? null,
            'roster_year_name' => $scope['year_name'] ?? null,
            'roster_fallback' => !empty($scope['fallback']),
        ], JSON_UNESCAPED_UNICODE);
        break;
    
    case 'save_grades':
        $assessmentId = (int)($_POST['assessment_id'] ?? 0);
        $grades = $_POST['grades'] ?? []; // Array of {member_id, score, remarks, record_id?}
        
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
        $stmt->close();
        
        if (!$assessment) {
            echo json_encode(['status' => 'error', 'message' => 'Assessment not found']);
            exit;
        }

        // PATCH C3: a teacher may only grade their own class + subject.
        if ($__restrictedRole) {
            edu_require_assignment(
                $conn, $__uid,
                (int)$assessment['class_id'], (int)$assessment['subject_id'],
                (int)($assessment['academic_year_id'] ?: ($currentYear['id'] ?? 0))
            );
        }

        // PATCH C2: the web path used to write academic_records directly and
        // never consulted the teacher→Education submission workflow, so a
        // teacher could silently overwrite an already-submitted or approved
        // mark list from the website even though the app locks it. Enforce
        // the same rule the mobile API applies (staff may always override).
        // 1.6.5 term-close model: the same gate now also refuses writes into
        // a CLOSED semester (not current, not reopened by the department).
        $auth = [
            'uid' => (int)($_SESSION['admin_id']),
            'usr' => (string)($_SESSION['admin_username'] ?? ''),
            'rol' => (string)($_SESSION['admin_role'] ?? ''),
        ];
        $refusal = \App\Services\SubmissionService::teacherWriteRefusal($conn, $auth, $assessmentId);
        if ($refusal !== null) {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => $refusal]);
            exit;
        }

        $recordedBy = (int)($_SESSION['admin_id']);
        $maxScore = (float)$assessment['max_score'];
        $yearId = (int)($assessment['academic_year_id'] ?: ($currentYear['id'] ?? 0));
        $termId = !empty($assessment['term_id']) ? (int)$assessment['term_id'] : null;
        $successCount = 0;
        $errors = [];
        
        // PATCH C1: re-saving a sheet used to run a blind INSERT that hit the
        // uq_ar_assessment_member unique key and failed for every existing
        // row. All writes now go through the same canonical upsert the mobile
        // app uses (lookup by assessment+member, then update-or-insert), so
        // first save and every re-save behave identically.
        foreach ($grades as $grade) {
            if (!is_array($grade)) {
                continue;
            }
            $memberId = (int)($grade['member_id'] ?? 0);
            $score = isset($grade['score']) && $grade['score'] !== '' ? (float)$grade['score'] : null;
            $remarks = trim((string)($grade['remarks'] ?? $grade['remark'] ?? ''));
            
            if (!$memberId) {
                continue;
            }
            
            // Validate score against the assessment's max
            if ($score !== null && ($score < 0 || $score > $maxScore)) {
                $errors[] = "Invalid score for member $memberId (max: $maxScore)";
                continue;
            }
            
            // Nothing to store (blank score, no remark) — skip silently,
            // matching the previous behaviour of not inserting empty rows.
            if ($score === null && $remarks === '') {
                continue;
            }
            
            try {
                $rid = \App\Services\SubmissionService::upsertScore($conn, [
                    'assessment_id' => $assessmentId,
                    'member_id' => $memberId,
                    'score' => $score,
                    'remarks' => $remarks,
                    'recorded_by' => $recordedBy,
                    'class_id' => (int)$assessment['class_id'],
                    'subject_id' => (int)$assessment['subject_id'],
                    'year_id' => $yearId ?: null,
                    'term_id' => $termId,
                    'max_score' => $maxScore,
                ]);
                if ($rid > 0) {
                    $successCount++;
                } else {
                    $errors[] = "Could not save the grade for member $memberId.";
                }
            } catch (Throwable $e) {
                reportInternalError('Web grade save failed for member ' . $memberId, $e);
                $errors[] = "Could not save the grade for member $memberId.";
            }
        }
        
        // PATCH C2: mirror the mobile flow — a web save also creates/updates
        // the mark-list packet so Education's review inbox stays the single
        // source of truth for what has and hasn't been graded.
        $scoreSum = 0.0;
        $scoreN = 0;
        foreach ($grades as $grade) {
            if (is_array($grade) && isset($grade['score']) && $grade['score'] !== '' && $grade['score'] !== null) {
                $scoreSum += (float)$grade['score'];
                $scoreN++;
            }
        }
        $average = $scoreN > 0 ? $scoreSum / $scoreN : null;
        $packet = \App\Services\SubmissionService::upsertMarklist($conn, [
            'teacher_id' => $recordedBy,
            'class_id' => (int)$assessment['class_id'],
            'subject_id' => (int)$assessment['subject_id'],
            'assessment_id' => $assessmentId,
            'status' => \App\Services\SubmissionService::STATUS_DRAFT,
            'student_count' => $successCount,
            'average' => $average,
            'year_id' => $yearId ?: null,
            'term_id' => $termId,
            'force' => \App\Services\SubmissionService::staffCanOverride($auth),
        ]);
        if (empty($packet['ok'])) {
            // Should be unreachable thanks to the pre-check (race only).
            $errors[] = $packet['message'] ?? 'Could not update the submission packet.';
        }

        $msg = $successCount > 0
            ? ($packet['message'] ?? "$successCount grade(s) saved successfully")
            : ($errors ? 'No grades could be saved.' : 'Nothing to save.');
        // 1.6.5: the web save path now audits like the mobile one. Every
        // successful save leaves an activity_logs row with the semester
        // context, so "everything is tracked" holds on both surfaces —
        // including department overrides into closed semesters.
        if ($successCount > 0) {
            try {
                $logDetails = "Saved {$successCount} grade(s) for assessment #{$assessmentId}"
                    . ($termId !== null ? " (term #{$termId})" : ' (no semester)');
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                $lstmt = $conn->prepare(
                    "INSERT INTO activity_logs (user_id, username, action, details, entity_type, entity_id, ip_address)
                     VALUES (?, ?, 'save_grades', ?, 'assessment', ?, ?)"
                );
                if ($lstmt) {
                    $lstmt->bind_param('issis', $auth['uid'], $auth['usr'], $logDetails, $assessmentId, $ip);
                    $lstmt->execute();
                    $lstmt->close();
                }
            } catch (Exception $eLog) {
                // auditing must never block a legitimate save
            }
        }
        echo json_encode([
            'status' => 'success',
            'message' => $msg,
            'saved' => $successCount,
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

        // PATCH C3: restricted roles must name the subject they teach — no
        // bulk pulls of a whole class' gradebook.
        if ($__restrictedRole) {
            if (!$classId || !$subjectId) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'class_id and subject_id are required']);
                exit;
            }
            edu_require_assignment($conn, $__uid, $classId, $subjectId, (int)$currentYear['id']);
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
} catch (Throwable $e) {
    // Enterprise error handling (audit patch 8): friendly mapped message +
    // correlation reference; internals only in the server log.
    respondApiThrowable('api_subjects error', $e);
}

$conn->close();
