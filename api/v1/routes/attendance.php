<?php
/**
 * School API v1 — Attendance
 * GET  /attendance?class_id=&date=   — sheet for one class/day
 * POST /attendance                   — save (transaction + class assignment)
 * GET  /attendance/daily-stats
 * GET  /attendance/summary
 */

$auth = apiRequireAuth();
if (!apiRoleIs($auth, apiRolesAttendance())) {
    err('You cannot take or view attendance.', 403);
}

$action = $ROUTE['id'] ?? '';
$year = getCurrentAcademicYear();
$yearId = $year ? (int)$year['id'] : 0;

/**
 * Resolve and validate a complete, explicit sheet against the current server
 * roster. This protects mobile and future integrations from stale/partial
 * payloads and keeps the HTTP adapter separate from domain validation.
 *
 * @param array<int,mixed> $records
 * @return array<int,array{member_id:int,status:string,note:string}>
 */
function apiValidateAttendanceSheet(\mysqli $conn, int $classId, int $yearId, array $records): array {
    if (!class_exists('\\App\\Services\\AttendanceRecordService')
        || !class_exists('\\App\\Services\\EnrollmentService')) {
        err('Attendance validation is temporarily unavailable.', 503);
    }

    $scope = \App\Services\EnrollmentService::resolveRosterYear(
        $conn,
        $classId,
        $yearId > 0 ? $yearId : null
    );
    $roster = \App\Services\EnrollmentService::fetchRoster(
        $conn,
        $classId,
        $scope['year_id'] ?? null
    );
    try {
        return \App\Services\AttendanceRecordService::normalizeCompleteSheet($records, $roster);
    } catch (\DomainException $error) {
        $safeMessage = $error->getMessage();
        err($safeMessage, 422);
    }
}

/**
 * Persist a validated sheet. The route owns the transaction so the attendance
 * rows and Education workflow packet commit or roll back as one unit.
 *
 * @param array<int,array{member_id:int,status:string,note:string}> $records
 */
function apiReplaceAttendanceRows(
    \mysqli $conn,
    int $classId,
    string $date,
    ?int $yearId,
    int $userId,
    array $records
): int {
    return \App\Services\AttendanceRecordService::replaceSheet(
        $conn,
        $classId,
        $date,
        $yearId,
        $userId,
        $records
    );
}

// ============================================================
// GET /attendance?class_id=X&date=Y
// ============================================================
if ($method === 'GET' && ($action === '' || $action === null)) {
    $classId = (int)($_GET['class_id'] ?? 0);
    $date = validateDate($_GET['date'] ?? '', date('Y-m-d'));

    if (!$classId) err('class_id is required');
    apiRequireClassAccess($conn, $auth, $classId, $yearId);

    $stmt = $conn->prepare("SELECT class_name, class_name_en FROM classes WHERE id = ?");
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$class) err('Class not found', 404);

    $scope = class_exists('\\App\\Services\\EnrollmentService')
        ? \App\Services\EnrollmentService::resolveRosterYear($conn, $classId, $yearId ?: null)
        : ['year_id' => $yearId ?: null, 'fallback' => false, 'year_name' => null];
    $rosterYearId = !empty($scope['year_id']) ? (int)$scope['year_id'] : 0;

    $attByMember = [];
    $attStmt = $conn->prepare(
        "SELECT id, member_id, status, notes, check_in_time
         FROM attendance WHERE class_id = ? AND attendance_date = ?"
    );
    if ($attStmt) {
        $attStmt->bind_param('is', $classId, $date);
        $attStmt->execute();
        $ar = $attStmt->get_result();
        while ($row = $ar->fetch_assoc()) {
            $attByMember[(int)$row['member_id']] = $row;
        }
        $attStmt->close();
    }

    $roster = class_exists('\\App\\Services\\EnrollmentService')
        ? \App\Services\EnrollmentService::fetchRoster($conn, $classId, $rosterYearId ?: null)
        : [];

    // Fallback if the service is missing on an older deploy
    if (!$roster && !class_exists('\\App\\Services\\EnrollmentService')) {
        if ($year) {
            $stmt = $conn->prepare(
                "SELECT ce.member_id, m.student_name, m.father_name, m.member_code, m.gender
                 FROM class_enrollments ce
                 JOIN members m ON ce.member_id = m.id
                 WHERE ce.class_id = ? AND ce.academic_year_id = ? AND ce.status = 'active'
                 ORDER BY m.student_name"
            );
            $stmt->bind_param('ii', $classId, $year['id']);
        } else {
            $stmt = $conn->prepare(
                "SELECT ce.member_id, m.student_name, m.father_name, m.member_code, m.gender
                 FROM class_enrollments ce
                 JOIN members m ON ce.member_id = m.id
                 WHERE ce.class_id = ? AND ce.status = 'active'
                 ORDER BY m.student_name"
            );
            $stmt->bind_param('i', $classId);
        }
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $roster[] = $row;
        }
        $stmt->close();
    }

    $packetStatus = null;
    $locked = false;
    $review = null;
    if (class_exists('\\App\\Services\\SubmissionService')) {
        $packetStatus = \App\Services\SubmissionService::resolvedAttendanceStatus($conn, $classId, $date);
        $locked = \App\Services\SubmissionService::isLockedForTeacher($packetStatus, $auth);
        // Education's reason is delivered only while the packet is actually
        // returned for correction — the teacher's key back to editing.
        if ($packetStatus === \App\Services\SubmissionService::STATUS_REVISION) {
            $review = \App\Services\SubmissionService::attendanceReview($conn, $classId, $date);
        }
    }

    $students = [];
    foreach ($roster as $row) {
        $mid = (int)($row['member_id'] ?? $row['id'] ?? 0);
        if ($mid <= 0) continue;
        $att = $attByMember[$mid] ?? null;
        $students[] = [
            'member_id' => $mid,
            'student_name' => $row['student_name'] ?? '',
            'father_name' => $row['father_name'] ?? '',
            'member_code' => $row['member_code'] ?? '',
            'gender' => $row['gender'] ?? '',
            'attendance_id' => $att && !empty($att['id']) ? (int)$att['id'] : null,
            'att_status' => $att['status'] ?? null,
            'notes' => $att['notes'] ?? null,
            'check_in_time' => $att['check_in_time'] ?? null,
        ];
    }

    ok([
        'class' => $class,
        'date' => $date,
        'students' => $students,
        'count' => count($students),
        'roster_year_id' => $rosterYearId ?: null,
        'roster_year_name' => $scope['year_name'] ?? null,
        'roster_fallback' => !empty($scope['fallback']),
        'submission_status' => $packetStatus,
        'locked' => $locked,
        'review_notes' => $review['review_notes'] ?? null,
        'reviewed_at' => $review['reviewed_at'] ?? null,
        'reviewer_name' => $review['reviewer_name'] ?? null,
    ]);
}

// ============================================================
// POST /attendance — Save (one transaction)
// ============================================================
if ($method === 'POST' && ($action === '' || $action === null)) {
    $input = getBody();
    $classId = (int)($input['class_id'] ?? 0);
    $date = validateDate($input['date'] ?? '', date('Y-m-d'));
    $records = $input['records'] ?? [];

    if (!$classId || empty($records)) err('class_id and records array are required');
    if (!is_array($records)) err('records must be an array');

    apiRequireClassAccess($conn, $auth, $classId, $yearId);
    $userId = (int)$auth['uid'];
    apiIdempotencyBegin($userId, (string)($input['client_op_id'] ?? ''));
    if (isApiRateLimited('attendance_save', 30)) {
        err('Too many attendance saves. Please wait a moment.', 429);
    }
    $records = apiValidateAttendanceSheet($conn, $classId, $yearId, $records);

    if (class_exists('\\App\\Services\\SubmissionService')
        && !\App\Services\SubmissionService::teacherMayWriteAttendance($conn, $auth, $classId, $date)) {
        err('This day’s attendance is already submitted. Only Education can change it.', 409);
    }

    if (!class_exists('\\App\\Services\\SubmissionService')) {
        err('Attendance workflow is temporarily unavailable.', 503);
    }

    $yearIdOrNull = $year ? (int)$year['id'] : null;
    $counts = \App\Services\SubmissionService::countsFromRecords($records);
    $saved = 0;
    $packet = [];
    $conn->begin_transaction();
    try {
        $saved = apiReplaceAttendanceRows(
            $conn,
            $classId,
            $date,
            $yearIdOrNull,
            $userId,
            $records
        );
        $packet = \App\Services\SubmissionService::upsertAttendance($conn, [
            'teacher_id' => $userId,
            'class_id' => $classId,
            'date' => $date,
            'status' => \App\Services\SubmissionService::STATUS_DRAFT,
            'student_count' => $saved,
            'year_id' => $yearIdOrNull,
            'present' => $counts['present'],
            'absent' => $counts['absent'],
            'late' => $counts['late'],
            'excused' => $counts['excused'],
        ]);
        if (empty($packet['ok'])) {
            throw new \DomainException(
                $packet['message'] ?? 'Could not update the attendance workflow.'
            );
        }
        $conn->commit();
    } catch (\DomainException $error) {
        $conn->rollback();
        $safeMessage = $error->getMessage();
        err($safeMessage, 409);
    } catch (\Throwable $error) {
        $conn->rollback();
        error_log('API attendance draft failed: ' . $error->getMessage());
        err('Could not save attendance. Nothing was changed. Please try again.', 500);
    }

    logApiAction($auth['uid'], $auth['usr'], 'Attendance Saved', "Class: {$classId}, Date: {$date}, Records: {$saved}");

    ok([
        'message' => $packet['message'] ?? "{$saved} records saved as a draft for Education",
        'saved' => $saved,
        'class_id' => $classId,
        'date' => $date,
        'submission_id' => $packet['id'] ?? 0,
        'submission_status' => $packet['status'] ?? 'draft',
    ], 201);
}

// ============================================================
// POST /attendance/submit — save + mark sent to Education
// ============================================================
if ($method === 'POST' && $action === 'submit') {
    $input = getBody();
    $classId = (int)($input['class_id'] ?? 0);
    $date = validateDate($input['date'] ?? '', date('Y-m-d'));
    $records = $input['records'] ?? [];

    if (!$classId || empty($records)) err('class_id and records array are required');
    if (!is_array($records)) err('records must be an array');

    apiRequireClassAccess($conn, $auth, $classId, $yearId);
    $yearIdOrNull = $year ? (int)$year['id'] : null;
    $userId = (int)$auth['uid'];
    apiIdempotencyBegin($userId, (string)($input['client_op_id'] ?? ''));
    if (isApiRateLimited('attendance_submit', 20)) {
        err('Too many submits. Please wait a moment.', 429);
    }
    $records = apiValidateAttendanceSheet($conn, $classId, $yearId, $records);
    if (class_exists('\\App\\Services\\SubmissionService')
        && !\App\Services\SubmissionService::teacherMayWriteAttendance($conn, $auth, $classId, $date)) {
        err('This day’s attendance is already submitted. Only Education can change it.', 409);
    }
    if (!class_exists('\\App\\Services\\SubmissionService')) {
        err('Attendance workflow is temporarily unavailable.', 503);
    }

    $counts = \App\Services\SubmissionService::countsFromRecords($records);
    $saved = 0;
    $packet = [];
    $conn->begin_transaction();
    try {
        $saved = apiReplaceAttendanceRows(
            $conn,
            $classId,
            $date,
            $yearIdOrNull,
            $userId,
            $records
        );
        $packet = \App\Services\SubmissionService::upsertAttendance($conn, [
            'teacher_id' => $userId,
            'class_id' => $classId,
            'date' => $date,
            'status' => \App\Services\SubmissionService::STATUS_SUBMITTED,
            'student_count' => $saved,
            'year_id' => $yearIdOrNull,
            'present' => $counts['present'],
            'absent' => $counts['absent'],
            'late' => $counts['late'],
            'excused' => $counts['excused'],
        ]);
        if (empty($packet['ok'])) {
            throw new \DomainException(
                $packet['message'] ?? 'Could not mark attendance complete.'
            );
        }
        $conn->commit();
    } catch (\DomainException $error) {
        $conn->rollback();
        $safeMessage = $error->getMessage();
        err($safeMessage, 409);
    } catch (\Throwable $error) {
        $conn->rollback();
        error_log('API attendance submission failed: ' . $error->getMessage());
        err('Could not submit attendance. Nothing was changed. Please try again.', 500);
    }

    logApiAction($auth['uid'], $auth['usr'], 'Attendance Submitted', "Class: {$classId}, Date: {$date}, Records: {$saved}");

    ok([
        'message' => $packet['message'] ?? "{$saved} attendance records marked complete for Education",
        'saved' => $saved,
        'class_id' => $classId,
        'date' => $date,
        'submission_id' => $packet['id'] ?? 0,
        'submission_status' => $packet['status'] ?? 'submitted',
    ], 201);
}

// ============================================================
// GET /attendance/daily-stats
// ============================================================
if ($action === 'daily-stats' && $method === 'GET') {
    $date = validateDate($_GET['date'] ?? '', date('Y-m-d'));

    $classFilterSql = '';
    $params = [$date];
    $types = 's';
    if (apiIsClassRestricted($auth)) {
        $ids = [];
        $st = $conn->prepare(
            "SELECT DISTINCT class_id FROM teacher_assignments
             WHERE teacher_id = ? AND is_active = 1
               AND (academic_year_id IS NULL OR academic_year_id = ?)"
        );
        $uid = (int)$auth['uid'];
        $st->bind_param('ii', $uid, $yearId);
        $st->execute();
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) $ids[] = (int)$row['class_id'];
        $st->close();
        if (!$ids) {
            ok(['date' => $date, 'classes' => []]);
        }
        $classFilterSql = ' AND c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $cid) {
            $params[] = $cid;
            $types .= 'i';
        }
    }

    $sql = "SELECT c.id as class_id, c.class_name,
                   COUNT(DISTINCT a.member_id) as recorded,
                   COALESCE(SUM(a.status='present'),0) as present,
                   COALESCE(SUM(a.status='absent'),0) as absent,
                   COALESCE(SUM(a.status='late'),0) as late
            FROM classes c
            LEFT JOIN attendance a ON a.class_id = c.id AND a.attendance_date = ?
            WHERE c.is_active = 1 {$classFilterSql}
            GROUP BY c.id ORDER BY c.level_order";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stats = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['recorded'] = (int)$row['recorded'];
        $row['present'] = (int)$row['present'];
        $row['absent'] = (int)$row['absent'];
        $row['late'] = (int)$row['late'];
        $stats[] = $row;
    }
    $stmt->close();

    ok(['date' => $date, 'classes' => $stats]);
}

// ============================================================
// GET /attendance/summary
// ============================================================
if ($action === 'summary' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    $month = validateMonth($_GET['month'] ?? '', date('Y-m'));
    if (!$classId) err('class_id is required');
    apiRequireClassAccess($conn, $auth, $classId, $yearId);

    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $stmt = $conn->prepare(
        "SELECT a.member_id, m.student_name, m.father_name,
                COUNT(*) as total_days,
                SUM(a.status='present') as present,
                SUM(a.status='absent') as absent,
                SUM(a.status='late') as late
         FROM attendance a JOIN members m ON a.member_id = m.id
         WHERE a.class_id = ? AND a.attendance_date BETWEEN ? AND ?
         GROUP BY a.member_id ORDER BY m.student_name"
    );
    $stmt->bind_param('iss', $classId, $startDate, $endDate);
    $stmt->execute();
    $summary = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['total_days'] = (int)$row['total_days'];
        $row['present'] = (int)$row['present'];
        $row['absent'] = (int)$row['absent'];
        $row['late'] = (int)$row['late'];
        $row['rate'] = $row['total_days'] > 0 ? round($row['present'] / $row['total_days'] * 100, 1) : 0;
        $summary[] = $row;
    }
    $stmt->close();

    ok(['class_id' => $classId, 'month' => $month, 'summary' => $summary]);
}

err("No handler for {$method} /attendance" . ($action ? "/{$action}" : ''), 404);
