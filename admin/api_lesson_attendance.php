<?php
/**
 * ============================================================
 * Lesson‑level Education attendance API — Attendance alerting Phase 6
 * ============================================================
 * Requirements §3 / §8; plan docs/planning/ATTENDANCE_PHASE6_LESSON_LEVEL_PLAN_2026-10-20.md
 *
 * GET  ?action=subjects&class_id=            subjects the signed‑in teacher may take in that class
 * GET  ?action=sheet&class_id=&subject_id=&date=&lesson_no=
 * GET  ?action=day&class_id=&date=           lessons already saved for that class/day
 * GET  ?action=member&member_id=&limit=&offset=   lesson history (Edu, HR, Info, School Admin; teacher = own classes)
 * POST action=save   class_id, subject_id, date, lesson_no, records(JSON), csrf_token   (teacher of that class+subject, or Education)
 * POST action=cancel session_id, reason, csrf_token                                      (Education only)
 *
 * Until FEATURE_ATTENDANCE_V2 is on and sql/076 is applied every action
 * answers available:false and the UI hides the lesson selector.
 * Roles: access_control.php $ROLE_MAP + the matrices in LessonAttendanceService.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/academic_year.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/LessonAttendanceService.php';
require_once __DIR__ . '/backend/services/SchoolCalendarService.php';

use App\Services\AttendanceRecordService;
use App\Services\EnrollmentService;
use App\Services\LessonAttendanceService;
use App\Services\SchoolCalendarService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $message, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
};
$ok = static function (array $data): never {
    echo json_encode(['status' => 'success'] + $data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    $fail(401, 'Unauthorized');
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, LessonAttendanceService::READ_ROLES, true)) {
    $fail(403, 'You do not have permission to use lesson attendance.');
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$input = $_POST;
if ($method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($json)) {
        $input = $json + $_POST;
        $action = (string)($json['action'] ?? $action);
    }
}
$readActions = ['subjects', 'sheet', 'day', 'member'];
$writeActions = ['save', 'cancel'];
if (!in_array($action, array_merge($readActions, $writeActions), true)) {
    $fail(400, 'Unknown action');
}
if (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        $fail(405, 'Use POST for changes.');
    }
    if (!in_array($role, LessonAttendanceService::WRITE_ROLES, true)) {
        $fail(403, 'Only the assigned teacher or the Education department can change lesson attendance.');
    }
    $token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!validateCsrf($token)) {
        $fail(403, 'Security token expired. Please refresh the page.');
    }
}
if (isset($pdo) && $pdo instanceof PDO) {
    $rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
    $check = $rl->consume($method === 'POST' ? 'la_write' : 'la_read', 'user:' . $adminId, $method === 'POST' ? 60 : 240, 60);
    if (!$check['allowed']) {
        $fail(429, 'Too many requests. Please wait a moment and try again.');
    }
}

$available = LessonAttendanceService::available($conn);
$meta = ['available' => $available, 'role' => $role, 'max_lesson_no' => LessonAttendanceService::MAX_LESSON_NO];
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;
$preferYear = $currentYear ? (int)$currentYear['id'] : null;
$auditActor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
    $adminId, (string)($_SESSION['admin_username'] ?? ''), 'admin', $_SERVER
);

$dateParam = static function (string $raw) use ($fail): string {
    $d = trim($raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) {
        $fail(400, 'A valid date is required.');
    }
    return $d;
};
/** Teachers: the class must be theirs (and, for a subject, the subject too). Education may act on every class. */
$assertScope = static function (int $classId, ?int $subjectId) use ($conn, $role, $adminId, $preferYear, $fail): void {
    if ($classId <= 0) {
        $fail(400, 'Class is required.');
    }
    if ($role === 'teacher') {
        $allowed = $subjectId !== null
            ? LessonAttendanceService::teacherMayTake($conn, $adminId, $classId, $subjectId, $preferYear)
            : LessonAttendanceService::teacherHasClass($conn, $adminId, $classId);
        if (!$allowed) {
            $fail(403, 'You are not assigned to this class / subject.');
        }
    }
};
/** Classes a teacher is assigned to (history scoping). @return list<int> */
$teacherClassIds = static function () use ($conn, $adminId): array {
    $stmt = $conn->prepare('SELECT DISTINCT class_id FROM teacher_assignments WHERE teacher_id = ? AND is_active = 1');
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $ids = array_map(static fn($r) => (int)$r['class_id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return $ids;
};

switch ($action) {
    case 'subjects':
        $classId = (int)($_GET['class_id'] ?? 0);
        if (!$available || $classId <= 0) {
            $ok($meta + ['subjects' => []]);
        }
        if ($role === 'teacher') {
            $ok($meta + ['subjects' => LessonAttendanceService::subjectsForTeacher($conn, $adminId, $classId)]);
        }
        // Education: every subject offered to the class
        $stmt = $conn->prepare('SELECT s.id, s.subject_name, s.subject_name_en FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id WHERE cs.class_id = ? ORDER BY s.subject_name');
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $subjects = array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)($r['subject_name'] ?: $r['subject_name_en']), 'name_en' => (string)($r['subject_name_en'] ?? '')],
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
        $ok($meta + ['subjects' => $subjects]);

    case 'sheet':
        if (!$available) {
            $ok($meta + ['session' => null, 'students' => []]);
        }
        $classId = (int)($_GET['class_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $lessonNo = min(LessonAttendanceService::MAX_LESSON_NO, max(1, (int)($_GET['lesson_no'] ?? 1)));
        $date = $dateParam((string)($_GET['date'] ?? ''));
        if ($subjectId <= 0) {
            $fail(400, 'Subject is required.');
        }
        $assertScope($classId, $subjectId);
        $ok($meta + ['date_status' => SchoolCalendarService::classDayStatus($conn, $classId, $date)]
            + LessonAttendanceService::sheet($conn, $classId, $subjectId, $date, $lessonNo, $preferYear));

    case 'day':
        if (!$available) {
            $ok($meta + ['sessions' => []]);
        }
        $classId = (int)($_GET['class_id'] ?? 0);
        $date = $dateParam((string)($_GET['date'] ?? ''));
        if ($role === 'teacher') {
            $assertScope($classId, null);
        }
        $ok($meta + ['date_status' => SchoolCalendarService::classDayStatus($conn, $classId, $date),
            'sessions' => LessonAttendanceService::sessionsForDay($conn, $classId, $date, true)]);

    case 'member':
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId <= 0) {
            $fail(400, 'Member is required.');
        }
        if (!$available) {
            $ok($meta + ['items' => [], 'total' => 0]);
        }
        $scopeIds = $role === 'teacher' ? $teacherClassIds() : null;
        if ($scopeIds !== null) {
            // Teachers may only look up members enrolled in one of their classes
            // (no member-name enumeration by id).
            $ids = array_values(array_filter(array_map('intval', $scopeIds), static fn($i) => $i > 0));
            $inScope = false;
            if ($ids !== []) {
                $chk = $conn->prepare('SELECT 1 FROM class_enrollments WHERE member_id = ? AND class_id IN (' . implode(',', $ids) . ') LIMIT 1');
                $chk->bind_param('i', $memberId);
                $chk->execute();
                $inScope = $chk->get_result()->num_rows > 0;
                $chk->close();
            }
            if (!$inScope) {
                $fail(403, 'This member is not in one of your classes.');
            }
        }
        $ms = $conn->prepare('SELECT id, student_name, father_name, member_code FROM members WHERE id = ? LIMIT 1');
        $ms->bind_param('i', $memberId);
        $ms->execute();
        $member = $ms->get_result()->fetch_assoc();
        $ms->close();
        if (!$member) {
            $fail(404, 'Member not found.');
        }
        $ok($meta + ['member' => ['id' => (int)$member['id'], 'name' => trim((string)$member['student_name'] . ' ' . (string)$member['father_name']), 'member_code' => (string)$member['member_code']]]
            + LessonAttendanceService::memberHistory($conn, $memberId, (int)($_GET['limit'] ?? 100), (int)($_GET['offset'] ?? 0), $scopeIds));

    case 'save':
        if (!$available) {
            $fail(409, 'Lesson attendance is not available yet.');
        }
        if (function_exists('ay_require_writable')) {
            ay_require_writable($conn); // 403 read‑only past year / 409 no active year
        }
        $classId = (int)($input['class_id'] ?? 0);
        $subjectId = (int)($input['subject_id'] ?? 0);
        $lessonNo = (int)($input['lesson_no'] ?? 1);
        $date = $dateParam((string)($input['date'] ?? ''));
        if ($date > (new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')))->format('Y-m-d')) {
            $fail(422, 'A lesson sheet cannot be saved for a future date.');
        }
        if ($subjectId <= 0) {
            $fail(400, 'Subject is required.');
        }
        $assertScope($classId, $subjectId);
        // After the scope check (audit F-S1): no class-schedule verdicts for
        // classes the caller cannot access.
        $dayStatus = SchoolCalendarService::classDayStatus($conn, $classId, $date);
        if (empty($dayStatus['ok'])) {
            $fail(422, $dayStatus['detail'] !== '' ? $dayStatus['detail'] : 'This is not a class day.',
                ['code' => $dayStatus['code'], 'date_status' => $dayStatus]);
        }
        $records = $input['records'] ?? [];
        if (!is_array($records)) {
            $records = json_decode((string)$records, true);
        }
        if (!is_array($records) || $records === []) {
            $fail(400, 'No attendance records provided.');
        }
        $scope = EnrollmentService::resolveRosterYear($conn, $classId, $preferYear);
        $roster = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null, ['on_date' => $date]);
        try {
            $records = AttendanceRecordService::normalizeCompleteSheet($records, $roster);
        } catch (\DomainException $e) {
            $fail(422, $e->getMessage());
        }
        $yearId = isset($scope['year_id']) && $scope['year_id'] ? (int)$scope['year_id'] : $preferYear;
        try {
            $res = LessonAttendanceService::saveSheet($conn, $classId, $subjectId, $date, $lessonNo, $yearId, $adminId, $records, $auditActor);
        } catch (\DomainException $e) {
            $fail(422, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('lesson attendance save failed: ' . $e->getMessage());
            $fail(500, 'Could not save the lesson sheet.');
        }
        $ok($meta + $res + ['message' => 'Lesson attendance saved.', 'date_ec' => LessonAttendanceService::ecLabel($date)]);

    case 'cancel':
        if ($role !== 'edu_dept') {
            $fail(403, 'Only the Education department can cancel a lesson.');
        }
        if (!$available) {
            $fail(409, 'Lesson attendance is not available yet.');
        }
        try {
            $res = LessonAttendanceService::cancelSession($conn, (int)($input['session_id'] ?? 0), (string)($input['reason'] ?? ''), $adminId, $auditActor);
        } catch (\DomainException $e) {
            $fail(422, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('lesson attendance cancel failed: ' . $e->getMessage());
            $fail(500, 'Could not cancel the lesson.');
        }
        $ok($meta + $res + ['message' => 'Lesson cancelled. Day results were recalculated from the remaining lessons.']);
}
$fail(400, 'Unknown action');
