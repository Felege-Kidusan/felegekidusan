<?php
/**
 * ============================================================
 * LessonAttendanceService — Attendance alerting, Phase 6 (Option B)
 * ============================================================
 * Requirements §3 (Education attendance tracked by class AND lesson; every
 * lesson outcome preserved; subject / lesson / teacher / date / outcome
 * visible to Education, HR and Information), §4–§5 (same formula, drafts
 * never count), §13 (nothing deleted, audited). Owner decisions 2026‑10‑20:
 * Option B, timetable‑free lesson creation by the assigned teacher,
 * precedence present › late › excused › absent, monthly rate stays per
 * class/day, class/day sheet read‑only once a day has lessons, takers stay
 * on class/day sheets, no mobile in this phase, keep it simple.
 *
 * How it works (one idea): a teacher saves a LESSON sheet (class + subject
 * + date + lesson number). The lesson rows are stored in
 * `attendance_session_records`; the existing class/day row in `attendance`
 * is then DERIVED from all submitted lessons of that member on that day and
 * written through AttendanceRecordService::mergeSheet, so every existing
 * consumer (warnings, roll‑ups, report cards, sync feed, HR / Info views)
 * keeps working without change.
 *
 * Available when FEATURE_ATTENDANCE_V2 is on AND sql/076 is applied (no
 * extra flag — the SQL file is the switch).
 * ============================================================
 */

namespace App\Services;

require_once __DIR__ . '/FeatureGate.php';
require_once __DIR__ . '/SecurityAuditService.php';
require_once __DIR__ . '/AttendanceRecordService.php';
require_once __DIR__ . '/EnrollmentService.php';
require_once __DIR__ . '/SubmissionService.php';
require_once __DIR__ . '/AttendanceWarningService.php';
require_once __DIR__ . '/../ethiopian_date.php';

final class LessonAttendanceService
{
    public const SESSIONS = 'attendance_sessions';
    public const RECORDS = 'attendance_session_records';
    public const MAX_LESSON_NO = 6;
    /** Roles that may read lesson history (HR / Info read‑only; School Admin reports only; Super Admin none — §8). */
    public const READ_ROLES = ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'];
    /** Roles that may save lesson sheets (teachers only inside their class + subject assignment). */
    public const WRITE_ROLES = ['teacher', 'edu_dept'];

    private static array $tableCache = [];

    // ── availability ──────────────────────────────────────────────

    public static function enabled(): bool
    {
        return FeatureGate::isEnabled('attendance_v2');
    }

    public static function tableExists(\mysqli $conn): bool
    {
        $key = spl_object_id($conn);
        if (!isset(self::$tableCache[$key])) {
            $ok = false;
            try {
                $r = $conn->query("SHOW TABLES LIKE '" . self::RECORDS . "'");
                $ok = $r && $r->num_rows > 0;
            } catch (\Throwable $e) {
                $ok = false;
            }
            self::$tableCache[$key] = $ok;
        }
        return self::$tableCache[$key];
    }

    public static function available(\mysqli $conn): bool
    {
        return self::enabled() && self::tableExists($conn);
    }

    public static function resetCache(): void
    {
        self::$tableCache = [];
    }

    // ── pure rule (unit‑testable) ─────────────────────────────────

    /**
     * Day outcome from the member's submitted lesson outcomes (§3 + owner
     * Q6‑3): present › late › excused › absent; holiday only when every
     * lesson is holiday. Empty input → null (no day row).
     * @param list<string> $statuses
     */
    public static function deriveDayStatus(array $statuses): ?string
    {
        $statuses = array_values(array_filter($statuses, static fn($s) => is_string($s) && $s !== ''));
        if ($statuses === []) {
            return null;
        }
        foreach (['present', 'late', 'excused'] as $s) {
            if (in_array($s, $statuses, true)) {
                return $s;
            }
        }
        if (in_array('absent', $statuses, true)) {
            return 'absent';
        }
        return 'holiday';
    }

    // ── reads ─────────────────────────────────────────────────────

    /** Submitted (non‑cancelled) lessons of a class on a date. @return list<array<string,mixed>> */
    public static function sessionsForDay(\mysqli $conn, int $classId, string $date, bool $includeCancelled = false): array
    {
        if (!self::available($conn) || $classId <= 0) {
            return [];
        }
        $sql = 'SELECT s.*, sub.subject_name, sub.subject_name_en, u.full_name AS teacher_name
                  FROM ' . self::SESSIONS . ' s
                  LEFT JOIN subjects sub ON sub.id = s.subject_id
                  LEFT JOIN users u ON u.id = s.teacher_id
                 WHERE s.class_id = ? AND s.session_date = ?' .
               ($includeCancelled ? '' : " AND s.status = 'submitted'") .
               ' ORDER BY s.subject_id, s.lesson_no';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[] = self::presentSession($r);
        }
        $stmt->close();
        return $out;
    }

    /** True when the class/day already has at least one submitted lesson sheet (class/day sheet becomes read‑only — Q6‑5). */
    public static function dayHasLessons(\mysqli $conn, int $classId, string $date): bool
    {
        if (!self::available($conn) || $classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $stmt = $conn->prepare('SELECT 1 FROM ' . self::SESSIONS . " WHERE class_id = ? AND session_date = ? AND status = 'submitted' LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $has = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $has;
    }

    /**
     * Guard for every class/day WRITE path (web sheet, Education sheet, QR
     * scan, /api/v1 save + submit): once a day has submitted lesson sheets
     * the class/day row is derived and read‑only (owner Q6‑5).
     * @throws \DomainException
     */
    public static function assertClassDayWritable(\mysqli $conn, int $classId, string $date): void
    {
        if (self::dayHasLessons($conn, $classId, $date)) {
            throw new \DomainException(self::DAY_LOCKED_MESSAGE);
        }
    }

    public const DAY_LOCKED_MESSAGE = 'This day already has lesson attendance sheets. The class/day result is calculated from the lessons — open the lesson sheet to change it.';

    /**
     * The sheet for (class, subject, date, lesson_no): roster + existing
     * outcomes (default absent, as the class/day sheet). Returns the stored
     * session when it exists (null otherwise) — nothing is created on read.
     */
    public static function sheet(\mysqli $conn, int $classId, int $subjectId, string $date, int $lessonNo, ?int $preferYearId): array
    {
        $scope = EnrollmentService::resolveRosterYear($conn, $classId, $preferYearId);
        $roster = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null, ['on_date' => $date]);
        $session = self::findSession($conn, $classId, $subjectId, $date, $lessonNo);
        $byMember = [];
        if ($session) {
            $stmt = $conn->prepare('SELECT member_id, status, note FROM ' . self::RECORDS . ' WHERE session_id = ?');
            $stmt->bind_param('i', $session['id']);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $byMember[(int)$r['member_id']] = $r;
            }
            $stmt->close();
        }
        $students = [];
        foreach ($roster as $row) {
            $mid = (int)($row['member_id'] ?? $row['id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $rec = $byMember[$mid] ?? null;
            $students[] = [
                'member_id' => $mid,
                'student_name' => (string)($row['student_name'] ?? ''),
                'father_name' => (string)($row['father_name'] ?? ''),
                'member_code' => (string)($row['member_code'] ?? ''),
                'status' => $rec['status'] ?? null,
                'note' => (string)($rec['note'] ?? ''),
            ];
        }
        return [
            'session' => $session ? self::presentSession($session) : null,
            'students' => $students,
            'count' => count($students),
            'roster_year_id' => $scope['year_id'] ?? null,
            'date_ec' => self::ecLabel($date),
            'other_lessons' => array_values(array_filter(
                self::sessionsForDay($conn, $classId, $date),
                static fn($s) => !($s['subject_id'] === $subjectId && $s['lesson_no'] === $lessonNo)
            )),
        ];
    }

    /**
     * Lesson‑level history of one member (§3: subject, lesson, teacher, date,
     * outcome), newest first, EC labels. @return array{items:list<array>,total:int}
     */
    public static function memberHistory(\mysqli $conn, int $memberId, int $limit = 100, int $offset = 0, ?array $onlyClassIds = null): array
    {
        if (!self::available($conn) || $memberId <= 0) {
            return ['items' => [], 'total' => 0];
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $where = 'r.member_id = ?';
        $types = 'i';
        $params = [$memberId];
        if ($onlyClassIds !== null) {
            // teacher scope: only classes the teacher is assigned to
            $ids = array_values(array_filter(array_map('intval', $onlyClassIds), static fn($i) => $i > 0));
            if ($ids === []) {
                return ['items' => [], 'total' => 0];
            }
            $where .= ' AND s.class_id IN (' . implode(',', $ids) . ')';
        }
        $count = $conn->prepare('SELECT COUNT(*) AS c FROM ' . self::RECORDS . ' r JOIN ' . self::SESSIONS . ' s ON s.id = r.session_id WHERE ' . $where);
        $count->bind_param($types, ...$params);
        $count->execute();
        $total = (int)($count->get_result()->fetch_assoc()['c'] ?? 0);
        $count->close();

        $stmt = $conn->prepare(
            'SELECT r.id, r.status, r.note, r.recorded_at, s.id AS session_id, s.class_id, s.subject_id, s.session_date, s.lesson_no,
                    s.status AS session_status, s.teacher_id, c.class_name, sub.subject_name, sub.subject_name_en, u.full_name AS teacher_name
               FROM ' . self::RECORDS . ' r
               JOIN ' . self::SESSIONS . ' s ON s.id = r.session_id
               LEFT JOIN classes c ON c.id = s.class_id
               LEFT JOIN subjects sub ON sub.id = s.subject_id
               LEFT JOIN users u ON u.id = s.teacher_id
              WHERE ' . $where . "
              ORDER BY s.session_date DESC, s.subject_id, s.lesson_no
              LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $items = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $items[] = [
                'id' => (int)$r['id'],
                'session_id' => (int)$r['session_id'],
                'date_ec' => self::ecLabel((string)$r['session_date']),
                'class_id' => (int)$r['class_id'],
                'class_name' => (string)($r['class_name'] ?? ''),
                'subject_id' => (int)$r['subject_id'],
                'subject_name' => (string)($r['subject_name'] ?? ''),
                'subject_name_en' => (string)($r['subject_name_en'] ?? ''),
                'lesson_no' => (int)$r['lesson_no'],
                'teacher_id' => (int)$r['teacher_id'],
                'teacher_name' => (string)($r['teacher_name'] ?? ''),
                'status' => (string)$r['status'],
                'note' => (string)($r['note'] ?? ''),
                'session_status' => (string)$r['session_status'],
                'recorded_at_ec' => self::ecDateTime((string)$r['recorded_at']),
            ];
        }
        $stmt->close();
        return ['items' => $items, 'total' => $total];
    }

    // ── write ─────────────────────────────────────────────────────

    /**
     * Save (= submit, owner T‑2) one lesson sheet and re‑derive the class/day
     * rows of every member on it. One transaction. Returns counts.
     *
     * @param list<array{member_id:int,status:string,note:string}> $records already normalised against the roster
     * @throws \DomainException user‑facing
     */
    public static function saveSheet(\mysqli $conn, int $classId, int $subjectId, string $date, int $lessonNo, ?int $yearId, int $teacherId, array $records, ?SecurityAuditActor $auditActor = null): array
    {
        if (!self::available($conn)) {
            throw new \DomainException('Lesson attendance is not available yet.');
        }
        if ($classId <= 0 || $subjectId <= 0 || $teacherId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \DomainException('Class, subject, date and teacher are required.');
        }
        if ($lessonNo < 1 || $lessonNo > self::MAX_LESSON_NO) {
            throw new \DomainException('Lesson number must be between 1 and ' . self::MAX_LESSON_NO . '.');
        }
        if ($records === []) {
            throw new \DomainException('There are no attendance records to save.');
        }
        $counts = SubmissionService::countsFromRecords($records);

        $conn->begin_transaction();
        try {
            // 1. the lesson (upsert; a cancelled slot is revived by saving it again)
            $up = $conn->prepare(
                'INSERT INTO ' . self::SESSIONS . ' (class_id, academic_year_id, subject_id, session_date, lesson_no, teacher_id, status,
                        present_count, absent_count, late_count, excused_count)
                 VALUES (?, ?, ?, ?, ?, ?, \'submitted\', ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), academic_year_id = VALUES(academic_year_id), status = \'submitted\',
                        present_count = VALUES(present_count), absent_count = VALUES(absent_count),
                        late_count = VALUES(late_count), excused_count = VALUES(excused_count),
                        cancelled_by = NULL, cancelled_at = NULL, cancel_reason = NULL'
            );
            $up->bind_param('iiisiiiiii', $classId, $yearId, $subjectId, $date, $lessonNo, $teacherId,
                $counts['present'], $counts['absent'], $counts['late'], $counts['excused']);
            $up->execute();
            $up->close();
            $session = self::findSession($conn, $classId, $subjectId, $date, $lessonNo);
            if (!$session) {
                throw new \RuntimeException('Lesson could not be stored.');
            }
            $sessionId = (int)$session['id'];

            // 2. the lesson rows — the complete sheet replaces the previous one
            $del = $conn->prepare('DELETE FROM ' . self::RECORDS . ' WHERE session_id = ?');
            $del->bind_param('i', $sessionId);
            $del->execute();
            $del->close();
            $ins = $conn->prepare('INSERT INTO ' . self::RECORDS . ' (session_id, member_id, status, note, recorded_by) VALUES (?, ?, ?, ?, ?)');
            $memberIds = [];
            foreach ($records as $rec) {
                $mid = (int)$rec['member_id'];
                $status = (string)$rec['status'];
                if (!in_array($status, AttendanceRecordService::VALID_STATUSES, true)) {
                    throw new \InvalidArgumentException('Unvalidated attendance status.');
                }
                $note = (string)($rec['note'] ?? '');
                $noteOrNull = $note !== '' ? mb_substr($note, 0, 255) : null;
                $ins->bind_param('iissi', $sessionId, $mid, $status, $noteOrNull, $teacherId);
                $ins->execute();
                $memberIds[] = $mid;
            }
            $ins->close();

            // 3. derive the class/day rows (the only writer of day rows for lesson days)
            $derived = self::deriveDay($conn, $classId, $date, $yearId, $teacherId, $memberIds);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        // after commit: warnings / roll‑ups (never fail the save)
        AttendanceWarningService::safeEvaluateMany($conn, 'edu', $memberIds, $date, $teacherId);
        $details = ['lesson_id' => $sessionId, 'class_id' => $classId, 'subject_id' => $subjectId, 'date_ec' => self::ecLabel($date),
                    'lesson_no' => $lessonNo, 'records' => count($records), 'derived_rows' => $derived] + $counts;
        self::audit($conn, $auditActor, 'Lesson Attendance Saved', $details, 'class', $classId);
        return ['session_id' => $sessionId, 'saved' => count($records), 'derived' => $derived] + $counts;
    }

    /**
     * Education cancels a lesson (kept, flagged; its rows no longer count).
     * The day rows of its members are re‑derived from the remaining lessons.
     * @throws \DomainException
     */
    public static function cancelSession(\mysqli $conn, int $sessionId, string $reason, int $actorId, ?SecurityAuditActor $auditActor = null): array
    {
        if (!self::available($conn)) {
            throw new \DomainException('Lesson attendance is not available yet.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \DomainException('A reason (at least 3 characters) is required.');
        }
        $stmt = $conn->prepare('SELECT * FROM ' . self::SESSIONS . ' WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $s = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$s) {
            throw new \DomainException('Lesson not found.');
        }
        if ($s['status'] === 'cancelled') {
            throw new \DomainException('This lesson is already cancelled.');
        }
        $memberIds = [];
        $r = $conn->prepare('SELECT member_id FROM ' . self::RECORDS . ' WHERE session_id = ?');
        $r->bind_param('i', $sessionId);
        $r->execute();
        foreach ($r->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $memberIds[] = (int)$row['member_id'];
        }
        $r->close();

        $conn->begin_transaction();
        try {
            $u = $conn->prepare('UPDATE ' . self::SESSIONS . " SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?");
            $u->bind_param('isi', $actorId, $reason, $sessionId);
            $u->execute();
            $u->close();
            $derived = self::deriveDay($conn, (int)$s['class_id'], (string)$s['session_date'], $s['academic_year_id'] !== null ? (int)$s['academic_year_id'] : null, $actorId, $memberIds);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        AttendanceWarningService::safeEvaluateMany($conn, 'edu', $memberIds, (string)$s['session_date'], $actorId);
        self::audit($conn, $auditActor, 'Lesson Attendance Cancelled', ['lesson_id' => $sessionId, 'class_id' => (int)$s['class_id'],
            'subject_id' => (int)$s['subject_id'], 'date_ec' => self::ecLabel((string)$s['session_date']), 'lesson_no' => (int)$s['lesson_no'], 'reason' => $reason], 'class', (int)$s['class_id']);
        return ['cancelled' => true, 'derived' => $derived];
    }

    // ── derivation (inside the caller's transaction) ──────────────

    /**
     * Re‑derive the class/day rows of $memberIds from every submitted lesson
     * of (class, date) and write them through AttendanceRecordService::mergeSheet
     * (so the Phase 2/4 machinery and the sync triggers see a normal save).
     * Members with no submitted lesson left keep whatever day row they had
     * (nothing is deleted — §13); the day packet is refreshed as `submitted`.
     * @param list<int> $memberIds
     */
    private static function deriveDay(\mysqli $conn, int $classId, string $date, ?int $yearId, int $actorId, array $memberIds): int
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return 0;
        }
        $in = implode(',', $memberIds);
        $stmt = $conn->prepare(
            'SELECT r.member_id, r.status FROM ' . self::RECORDS . ' r JOIN ' . self::SESSIONS . ' s ON s.id = r.session_id
              WHERE s.class_id = ? AND s.session_date = ? AND s.status = \'submitted\' AND r.member_id IN (' . $in . ')'
        );
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $byMember = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $byMember[(int)$r['member_id']][] = (string)$r['status'];
        }
        $stmt->close();

        $records = [];
        foreach ($byMember as $mid => $statuses) {
            $day = self::deriveDayStatus($statuses);
            if ($day === null) {
                continue;
            }
            $records[] = ['member_id' => $mid, 'status' => $day, 'note' => 'derived from ' . count($statuses) . ' lesson(s)'];
        }
        if ($records === []) {
            return 0;
        }
        $saved = AttendanceRecordService::mergeSheet($conn, $classId, $date, $yearId, $actorId, $records);
        // Day packet (T‑2: a saved sheet is a submission). Counts = all day rows of the class/date.
        $cnt = $conn->prepare("SELECT status, COUNT(*) AS c FROM attendance WHERE class_id = ? AND attendance_date = ? GROUP BY status");
        $cnt->bind_param('is', $classId, $date);
        $cnt->execute();
        $c = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0];
        foreach ($cnt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            if (isset($c[$row['status']])) {
                $c[$row['status']] = (int)$row['c'];
            }
            $c['total'] += (int)$row['c'];
        }
        $cnt->close();
        SubmissionService::upsertAttendance($conn, [
            'teacher_id' => $actorId, 'class_id' => $classId, 'date' => $date,
            'status' => SubmissionService::STATUS_SUBMITTED, 'student_count' => $c['total'], 'year_id' => $yearId,
            'present' => $c['present'], 'absent' => $c['absent'], 'late' => $c['late'], 'excused' => $c['excused'], 'force' => true,
        ]);
        return $saved;
    }

    // ── helpers ───────────────────────────────────────────────────

    /** @return array<string,mixed>|null raw row */
    public static function findSession(\mysqli $conn, int $classId, int $subjectId, string $date, int $lessonNo): ?array
    {
        $stmt = $conn->prepare(
            'SELECT s.*, sub.subject_name, sub.subject_name_en, u.full_name AS teacher_name FROM ' . self::SESSIONS . ' s
               LEFT JOIN subjects sub ON sub.id = s.subject_id LEFT JOIN users u ON u.id = s.teacher_id
              WHERE s.class_id = ? AND s.subject_id = ? AND s.session_date = ? AND s.lesson_no = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('iisi', $classId, $subjectId, $date, $lessonNo);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $r ?: null;
    }

    /** True when $teacherId actively teaches $subjectId in $classId (teacher_assignments; class‑wide rows count). */
    public static function teacherMayTake(\mysqli $conn, int $teacherId, int $classId, int $subjectId, ?int $yearId = null): bool
    {
        if ($teacherId <= 0 || $classId <= 0 || $subjectId <= 0) {
            return false;
        }
        $sql = 'SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND is_active = 1 AND (subject_id = ? OR subject_id IS NULL)';
        if ($yearId !== null && $yearId > 0) {
            $sql .= ' AND (academic_year_id IS NULL OR academic_year_id = ' . (int)$yearId . ')';
        }
        $sql .= ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $teacherId, $classId, $subjectId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    /** True when $teacherId has any active assignment in $classId. */
    public static function teacherHasClass(\mysqli $conn, int $teacherId, int $classId): bool
    {
        if ($teacherId <= 0 || $classId <= 0) {
            return false;
        }
        $stmt = $conn->prepare('SELECT 1 FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND is_active = 1 LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $teacherId, $classId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    /** Subjects a teacher may take lessons for in a class (class‑wide assignment → every subject offered to the class). @return list<array{id:int,name:string}> */
    public static function subjectsForTeacher(\mysqli $conn, int $teacherId, int $classId): array
    {
        $stmt = $conn->prepare(
            'SELECT DISTINCT s.id, s.subject_name, s.subject_name_en
               FROM teacher_assignments ta
               JOIN subjects s ON (s.id = ta.subject_id OR (ta.subject_id IS NULL AND s.id IN (SELECT cs.subject_id FROM class_subjects cs WHERE cs.class_id = ta.class_id)))
              WHERE ta.teacher_id = ? AND ta.class_id = ? AND ta.is_active = 1
              ORDER BY s.subject_name'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $teacherId, $classId);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[] = ['id' => (int)$r['id'], 'name' => (string)($r['subject_name'] ?: $r['subject_name_en']), 'name_en' => (string)($r['subject_name_en'] ?? '')];
        }
        $stmt->close();
        return $out;
    }

    private static function presentSession(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'class_id' => (int)$r['class_id'],
            'subject_id' => (int)$r['subject_id'],
            'subject_name' => (string)($r['subject_name'] ?? ''),
            'subject_name_en' => (string)($r['subject_name_en'] ?? ''),
            'lesson_no' => (int)$r['lesson_no'],
            'date_ec' => self::ecLabel((string)$r['session_date']),
            'teacher_id' => (int)$r['teacher_id'],
            'teacher_name' => (string)($r['teacher_name'] ?? ''),
            'status' => (string)$r['status'],
            'present' => (int)$r['present_count'], 'absent' => (int)$r['absent_count'],
            'late' => (int)$r['late_count'], 'excused' => (int)$r['excused_count'],
            'cancel_reason' => (string)($r['cancel_reason'] ?? ''),
            'updated_at_ec' => self::ecDateTime((string)($r['updated_at'] ?? '')),
        ];
    }

    private static function audit(\mysqli $conn, ?SecurityAuditActor $actor, string $action, array $details, string $entity, int $entityId): void
    {
        try {
            if ($actor !== null) {
                SecurityAuditService::recordTrusted($conn, $actor, $action, $details, $entity, $entityId);
            } else {
                SecurityAuditService::record($conn, $action, $details, $entity, $entityId);
            }
        } catch (\Throwable $e) {
            error_log('Lesson attendance audit failed: ' . $e->getMessage());
        }
    }

    public static function ecLabel(string $date): string
    {
        return ethio_date_format($date, 'F j, Y');
    }

    private static function ecDateTime(string $dt): string
    {
        if ($dt === '' || strpos($dt, '0000-00-00') === 0) {
            return '';
        }
        $time = substr($dt, 11, 5);
        return ethio_date_format(substr($dt, 0, 10), 'F j, Y') . ($time ? ' ' . $time : '');
    }
}
