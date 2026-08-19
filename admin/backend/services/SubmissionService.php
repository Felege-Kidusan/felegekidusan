<?php
/**
 * Teacher → Education submissions.
 *
 * One place for attendance and mark-list packets so the website and the
 * phone app stay in sync. Status meaning:
 *   incomplete — teacher sent work, still finishing
 *   submitted  — teacher marked it complete, Education reviews
 *   approved / rejected / revision_needed — Education decision
 *   draft      — leftover from older rows; treated like incomplete in the UI
 */

namespace App\Services;

class SubmissionService
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVISION = 'revision_needed';
    public const STATUS_DRAFT = 'draft';

    public const TYPE_ATTENDANCE = 'attendance';
    public const TYPE_MARKLIST = 'marklist';
    public const TYPE_REPORT = 'report';

    public static function ensureTable(\mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS `grade_submissions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `teacher_id` INT UNSIGNED NOT NULL,
            `class_id` INT UNSIGNED NOT NULL,
            `subject_id` INT UNSIGNED DEFAULT 0,
            `academic_year_id` INT UNSIGNED DEFAULT NULL,
            `term_id` INT UNSIGNED DEFAULT NULL,
            `assessment_id` INT UNSIGNED DEFAULT NULL,
            `attendance_date` DATE DEFAULT NULL,
            `submission_type` ENUM('marklist','attendance','report') NOT NULL DEFAULT 'marklist',
            `status` ENUM('draft','incomplete','submitted','approved','rejected','revision_needed') NOT NULL DEFAULT 'incomplete',
            `student_count` INT UNSIGNED DEFAULT 0,
            `average_score` DECIMAL(5,2) DEFAULT NULL,
            `present_count` INT UNSIGNED DEFAULT 0,
            `absent_count` INT UNSIGNED DEFAULT 0,
            `late_count` INT UNSIGNED DEFAULT 0,
            `excused_count` INT UNSIGNED DEFAULT 0,
            `submitted_at` TIMESTAMP NULL DEFAULT NULL,
            `reviewed_by` INT UNSIGNED DEFAULT NULL,
            `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
            `review_notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `teacher_id` (`teacher_id`),
            KEY `class_id` (`class_id`),
            KEY `status` (`status`),
            KEY `sub_att_lookup` (`teacher_id`, `class_id`, `submission_type`, `attendance_date`),
            KEY `sub_mark_lookup` (`teacher_id`, `assessment_id`, `submission_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::ensureColumn($conn, 'grade_submissions', 'attendance_date', "DATE DEFAULT NULL AFTER `assessment_id`");
        self::ensureColumn($conn, 'grade_submissions', 'present_count', "INT UNSIGNED DEFAULT 0 AFTER `average_score`");
        self::ensureColumn($conn, 'grade_submissions', 'absent_count', "INT UNSIGNED DEFAULT 0 AFTER `present_count`");
        self::ensureColumn($conn, 'grade_submissions', 'late_count', "INT UNSIGNED DEFAULT 0 AFTER `absent_count`");
        self::ensureColumn($conn, 'grade_submissions', 'excused_count', "INT UNSIGNED DEFAULT 0 AFTER `late_count`");

        // Widen status so Save can be "incomplete" (not draft).
        try {
            $conn->query("ALTER TABLE `grade_submissions`
                MODIFY `status` ENUM('draft','incomplete','submitted','approved','rejected','revision_needed')
                NOT NULL DEFAULT 'incomplete'");
        } catch (\Throwable $e) { /* already widened */ }

        try {
            $conn->query("ALTER TABLE `grade_submissions` MODIFY `subject_id` INT UNSIGNED DEFAULT 0");
        } catch (\Throwable $e) { /* ok */ }

        $r = $conn->query("SHOW COLUMNS FROM `academic_records` LIKE 'submission_id'");
        if ($r && $r->num_rows === 0) {
            try {
                $conn->query("ALTER TABLE `academic_records` ADD COLUMN `submission_id` INT UNSIGNED DEFAULT NULL AFTER `assessment_id`");
            } catch (\Throwable $e) { /* ok */ }
        }
    }

    private static function ensureColumn(\mysqli $conn, string $table, string $column, string $definition): void
    {
        try {
            $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");
            if ($r && $r->num_rows === 0) {
                $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) { /* non-critical */ }
    }

    public static function normalizeStatus(?string $status): string
    {
        $status = strtolower(trim((string)$status));
        $ok = [
            self::STATUS_INCOMPLETE,
            self::STATUS_SUBMITTED,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_REVISION,
            self::STATUS_DRAFT,
        ];
        return in_array($status, $ok, true) ? $status : self::STATUS_INCOMPLETE;
    }

    /**
     * Create or update one attendance packet.
     *
     * @param array{teacher_id:int,class_id:int,date:string,status:string,student_count?:int,year_id?:?int,present?:int,absent?:int,late?:int,excused?:int} $opts
     * @return array{ok:bool,id:int,status:string,message:string}
     */
    public static function upsertAttendance(\mysqli $conn, array $opts): array
    {
        self::ensureTable($conn);
        $teacherId = (int)($opts['teacher_id'] ?? 0);
        $classId = (int)($opts['class_id'] ?? 0);
        $date = trim((string)($opts['date'] ?? ''));
        $status = self::normalizeStatus($opts['status'] ?? self::STATUS_INCOMPLETE);
        if ($status === self::STATUS_DRAFT) {
            $status = self::STATUS_INCOMPLETE;
        }
        $count = (int)($opts['student_count'] ?? 0);
        $yearId = isset($opts['year_id']) && $opts['year_id'] ? (int)$opts['year_id'] : null;
        $present = (int)($opts['present'] ?? 0);
        $absent = (int)($opts['absent'] ?? 0);
        $late = (int)($opts['late'] ?? 0);
        $excused = (int)($opts['excused'] ?? 0);
        $zero = 0;

        if ($teacherId <= 0 || $classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'id' => 0, 'status' => $status, 'message' => 'Class, teacher, and date are required.'];
        }

        $existingId = 0;
        $stmt = $conn->prepare(
            "SELECT id FROM grade_submissions
             WHERE teacher_id = ? AND class_id = ? AND submission_type = 'attendance' AND attendance_date = ?
             ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('iis', $teacherId, $classId, $date);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $existingId = (int)($row['id'] ?? 0);
        }

        $submittedAt = $status === self::STATUS_SUBMITTED ? date('Y-m-d H:i:s') : null;

        if ($existingId > 0) {
            $sql = "UPDATE grade_submissions
                    SET status = ?, student_count = ?, present_count = ?, absent_count = ?, late_count = ?, excused_count = ?,
                        academic_year_id = ?, submitted_at = COALESCE(?, submitted_at), updated_at = NOW()
                    WHERE id = ?";
            $up = $conn->prepare($sql);
            if (!$up) {
                return ['ok' => false, 'id' => $existingId, 'status' => $status, 'message' => 'Could not update attendance packet.'];
            }
            $up->bind_param('siiiiiisi', $status, $count, $present, $absent, $late, $excused, $yearId, $submittedAt, $existingId);
            $ok = $up->execute();
            $up->close();
            return [
                'ok' => (bool)$ok,
                'id' => $existingId,
                'status' => $status,
                'message' => $status === self::STATUS_SUBMITTED
                    ? 'Attendance submitted.'
                    : 'Saved as a draft for Education.',
            ];
        }

        $ins = $conn->prepare(
            "INSERT INTO grade_submissions
                (teacher_id, class_id, subject_id, academic_year_id, attendance_date, submission_type, status,
                 student_count, present_count, absent_count, late_count, excused_count, submitted_at)
             VALUES (?, ?, ?, ?, ?, 'attendance', ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$ins) {
            return ['ok' => false, 'id' => 0, 'status' => $status, 'message' => 'Could not create attendance packet.'];
        }
        $ins->bind_param(
            'iiiissiiiiis',
            $teacherId,
            $classId,
            $zero,
            $yearId,
            $date,
            $status,
            $count,
            $present,
            $absent,
            $late,
            $excused,
            $submittedAt
        );
        $ok = $ins->execute();
        $id = (int)$ins->insert_id;
        $ins->close();
        return [
            'ok' => (bool)$ok,
            'id' => $id,
            'status' => $status,
            'message' => $status === self::STATUS_SUBMITTED
                ? 'Attendance submitted.'
                : 'Saved as a draft for Education.',
        ];
    }

    /**
     * Create or update one mark-list packet.
     *
     * @param array{teacher_id:int,class_id:int,subject_id:int,assessment_id:int,status:string,student_count?:int,average?:?float,year_id?:?int,term_id?:?int} $opts
     */
    public static function upsertMarklist(\mysqli $conn, array $opts): array
    {
        self::ensureTable($conn);
        $teacherId = (int)($opts['teacher_id'] ?? 0);
        $classId = (int)($opts['class_id'] ?? 0);
        $subjectId = (int)($opts['subject_id'] ?? 0);
        $assessmentId = (int)($opts['assessment_id'] ?? 0);
        $status = self::normalizeStatus($opts['status'] ?? self::STATUS_DRAFT);
        $count = (int)($opts['student_count'] ?? 0);
        $avg = isset($opts['average']) && $opts['average'] !== null ? (float)$opts['average'] : null;
        $yearId = isset($opts['year_id']) && $opts['year_id'] ? (int)$opts['year_id'] : null;
        $termId = isset($opts['term_id']) && $opts['term_id'] ? (int)$opts['term_id'] : null;

        if ($teacherId <= 0 || $classId <= 0 || $assessmentId <= 0) {
            return ['ok' => false, 'id' => 0, 'status' => $status, 'message' => 'Class, teacher, and assessment are required.'];
        }

        $existingId = 0;
        $stmt = $conn->prepare(
            "SELECT id FROM grade_submissions
             WHERE teacher_id = ? AND assessment_id = ? AND submission_type = 'marklist'
             ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $teacherId, $assessmentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $existingId = (int)($row['id'] ?? 0);
        }

        $submittedAt = $status === self::STATUS_SUBMITTED ? date('Y-m-d H:i:s') : null;

        if ($existingId > 0) {
            $up = $conn->prepare(
                "UPDATE grade_submissions
                 SET status = ?, student_count = ?, average_score = ?, class_id = ?, subject_id = ?,
                     academic_year_id = ?, term_id = ?, submitted_at = COALESCE(?, submitted_at), updated_at = NOW()
                 WHERE id = ?"
            );
            if (!$up) {
                return ['ok' => false, 'id' => $existingId, 'status' => $status, 'message' => 'Could not update mark list.'];
            }
            $up->bind_param('sidiiiisi', $status, $count, $avg, $classId, $subjectId, $yearId, $termId, $submittedAt, $existingId);
            $ok = $up->execute();
            $up->close();
            return [
                'ok' => (bool)$ok,
                'id' => $existingId,
                'status' => $status,
                'message' => $status === self::STATUS_SUBMITTED
                    ? 'Mark list submitted.'
                    : 'Saved as a draft for Education.',
            ];
        }

        $ins = $conn->prepare(
            "INSERT INTO grade_submissions
                (teacher_id, class_id, subject_id, academic_year_id, term_id, assessment_id, submission_type, status,
                 student_count, average_score, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, 'marklist', ?, ?, ?, ?)"
        );
        if (!$ins) {
            return ['ok' => false, 'id' => 0, 'status' => $status, 'message' => 'Could not create mark list.'];
        }
        $ins->bind_param(
            'iiiiiisids',
            $teacherId,
            $classId,
            $subjectId,
            $yearId,
            $termId,
            $assessmentId,
            $status,
            $count,
            $avg,
            $submittedAt
        );
        $ok = $ins->execute();
        $id = (int)$ins->insert_id;
        $ins->close();
        return [
            'ok' => (bool)$ok,
            'id' => $id,
            'status' => $status,
            'message' => $status === self::STATUS_SUBMITTED
                ? 'Mark list submitted.'
                : 'Saved as a draft for Education.',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function list(\mysqli $conn, array $filters): array
    {
        self::ensureTable($conn);

        $where = ['1=1'];
        $params = [];
        $types = '';

        if (!empty($filters['teacher_id'])) {
            $where[] = 'gs.teacher_id = ?';
            $params[] = (int)$filters['teacher_id'];
            $types .= 'i';
        }
        if (!empty($filters['class_id'])) {
            $where[] = 'gs.class_id = ?';
            $params[] = (int)$filters['class_id'];
            $types .= 'i';
        }
        $type = trim((string)($filters['type'] ?? ''));
        if (in_array($type, [self::TYPE_ATTENDANCE, self::TYPE_MARKLIST, self::TYPE_REPORT], true)) {
            $where[] = 'gs.submission_type = ?';
            $params[] = $type;
            $types .= 's';
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status === 'attention') {
            $where[] = "gs.status IN ('incomplete','submitted','draft')";
        } elseif ($status === 'draft') {
            $where[] = "gs.status IN ('draft','incomplete')";
        } elseif ($status !== '' && $status !== 'all') {
            $status = self::normalizeStatus($status);
            $where[] = 'gs.status = ?';
            $params[] = $status;
            $types .= 's';
        }

        $sql = "SELECT gs.id, gs.teacher_id, gs.class_id, gs.subject_id, gs.academic_year_id,
                       gs.term_id, gs.assessment_id, gs.attendance_date, gs.submission_type, gs.status,
                       gs.student_count, gs.average_score, gs.present_count, gs.absent_count,
                       gs.late_count, gs.excused_count, gs.submitted_at, gs.reviewed_by, gs.reviewed_at,
                       gs.review_notes, gs.created_at, gs.updated_at,
                       u.full_name AS teacher_name,
                       c.class_name, c.class_name_en,
                       s.subject_name, s.subject_name_en,
                       a.assessment_name, a.max_score,
                       ay.year_name,
                       rv.full_name AS reviewer_name
                FROM grade_submissions gs
                LEFT JOIN users u ON gs.teacher_id = u.id
                LEFT JOIN classes c ON gs.class_id = c.id
                LEFT JOIN subjects s ON gs.subject_id = s.id AND gs.subject_id > 0
                LEFT JOIN assessments a ON gs.assessment_id = a.id
                LEFT JOIN academic_years ay ON gs.academic_year_id = ay.id
                LEFT JOIN users rv ON gs.reviewed_by = rv.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY gs.updated_at DESC, gs.id DESC
                LIMIT 200";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Could not read submissions.');
        }
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $rows[] = self::presentRow($row);
        }
        $stmt->close();
        return $rows;
    }

    public static function stats(\mysqli $conn, array $filters = []): array
    {
        self::ensureTable($conn);
        $where = ['1=1'];
        $params = [];
        $types = '';
        if (!empty($filters['teacher_id'])) {
            $where[] = 'teacher_id = ?';
            $params[] = (int)$filters['teacher_id'];
            $types .= 'i';
        }

        $sql = "SELECT
                    SUM(status IN ('incomplete','draft')) AS incomplete,
                    SUM(status = 'submitted') AS pending,
                    SUM(status = 'approved') AS approved,
                    SUM(status = 'rejected') AS rejected,
                    SUM(status = 'revision_needed') AS revision_needed,
                    SUM(submission_type = 'attendance') AS attendance_count,
                    SUM(submission_type = 'marklist') AS marklist_count,
                    COUNT(*) AS total
                FROM grade_submissions
                WHERE " . implode(' AND ', $where);
        $stmt = $conn->prepare($sql);
        $out = [
            'incomplete' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'revision_needed' => 0,
            'attendance_count' => 0,
            'marklist_count' => 0,
            'total' => 0,
            'today_present' => 0,
            'today_absent' => 0,
            'today_late' => 0,
            'today_excused' => 0,
            'today_recorded' => 0,
        ];
        if ($stmt) {
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            foreach ($out as $k => $v) {
                if (isset($row[$k])) {
                    $out[$k] = (int)$row[$k];
                }
            }
        }

        $today = date('Y-m-d');
        $att = $conn->prepare(
            "SELECT
                COUNT(*) AS recorded,
                SUM(status = 'present') AS present_count,
                SUM(status = 'absent') AS absent_count,
                SUM(status = 'late') AS late_count,
                SUM(status = 'excused') AS excused_count
             FROM attendance WHERE attendance_date = ?"
        );
        if ($att) {
            $att->bind_param('s', $today);
            $att->execute();
            $a = $att->get_result()->fetch_assoc() ?: [];
            $att->close();
            $out['today_recorded'] = (int)($a['recorded'] ?? 0);
            $out['today_present'] = (int)($a['present_count'] ?? 0);
            $out['today_absent'] = (int)($a['absent_count'] ?? 0);
            $out['today_late'] = (int)($a['late_count'] ?? 0);
            $out['today_excused'] = (int)($a['excused_count'] ?? 0);
        }
        return $out;
    }

    /**
     * Charts + tables for Education. No extra PII.
     *
     * @return array{days:list<array<string,mixed>>,classes:list<array<string,mixed>>,packets:array<string,int>}
     */
    public static function analytics(\mysqli $conn): array
    {
        self::ensureTable($conn);
        $days = [];
        $from = date('Y-m-d', strtotime('-13 days'));
        $st = $conn->prepare(
            "SELECT attendance_date AS d,
                    COUNT(*) AS recorded,
                    SUM(status = 'present') AS present_count,
                    SUM(status = 'absent') AS absent_count,
                    SUM(status = 'late') AS late_count,
                    SUM(status = 'excused') AS excused_count
             FROM attendance
             WHERE attendance_date >= ?
             GROUP BY attendance_date
             ORDER BY attendance_date ASC"
        );
        if ($st) {
            $st->bind_param('s', $from);
            $st->execute();
            $r = $st->get_result();
            while ($row = $r->fetch_assoc()) {
                $rec = (int)$row['recorded'];
                $present = (int)$row['present_count'];
                $days[] = [
                    'date' => $row['d'],
                    'recorded' => $rec,
                    'present' => $present,
                    'absent' => (int)$row['absent_count'],
                    'late' => (int)$row['late_count'],
                    'excused' => (int)$row['excused_count'],
                    'rate' => $rec > 0 ? round($present / $rec * 100, 1) : 0,
                ];
            }
            $st->close();
        }

        $classes = [];
        $today = date('Y-m-d');
        $cs = $conn->prepare(
            "SELECT c.id, c.class_name,
                    COUNT(a.id) AS recorded,
                    SUM(a.status = 'present') AS present_count,
                    SUM(a.status = 'absent') AS absent_count,
                    SUM(a.status = 'late') AS late_count
             FROM classes c
             LEFT JOIN attendance a ON a.class_id = c.id AND a.attendance_date = ?
             WHERE c.is_active = 1
             GROUP BY c.id, c.class_name
             ORDER BY c.level_order, c.class_name"
        );
        if ($cs) {
            $cs->bind_param('s', $today);
            $cs->execute();
            $r = $cs->get_result();
            while ($row = $r->fetch_assoc()) {
                $rec = (int)$row['recorded'];
                $present = (int)($row['present_count'] ?? 0);
                $classes[] = [
                    'id' => (int)$row['id'],
                    'class_name' => $row['class_name'] ?? '',
                    'recorded' => $rec,
                    'present' => $present,
                    'absent' => (int)($row['absent_count'] ?? 0),
                    'late' => (int)($row['late_count'] ?? 0),
                    'rate' => $rec > 0 ? round($present / $rec * 100, 1) : null,
                ];
            }
            $cs->close();
        }

        return [
            'days' => $days,
            'classes' => $classes,
            'packets' => self::stats($conn),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function detail(\mysqli $conn, int $id): ?array
    {
        self::ensureTable($conn);
        if ($id <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT gs.*, u.full_name AS teacher_name, c.class_name, c.class_name_en,
                    s.subject_name, s.subject_name_en, a.assessment_name, a.max_score,
                    ay.year_name, rv.full_name AS reviewer_name
             FROM grade_submissions gs
             LEFT JOIN users u ON gs.teacher_id = u.id
             LEFT JOIN classes c ON gs.class_id = c.id
             LEFT JOIN subjects s ON gs.subject_id = s.id AND gs.subject_id > 0
             LEFT JOIN assessments a ON gs.assessment_id = a.id
             LEFT JOIN academic_years ay ON gs.academic_year_id = ay.id
             LEFT JOIN users rv ON gs.reviewed_by = rv.id
             WHERE gs.id = ? LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $raw = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$raw) {
            return null;
        }
        $packet = self::presentRow($raw);

        $packet['rows'] = [];
        if (($packet['submission_type'] ?? '') === self::TYPE_ATTENDANCE) {
            $packet['rows'] = self::attendanceRows($conn, (int)$packet['class_id'], (string)($packet['attendance_date'] ?? ''));
        } elseif (($packet['submission_type'] ?? '') === self::TYPE_MARKLIST && !empty($packet['assessment_id'])) {
            $packet['rows'] = self::marklistRows($conn, (int)$packet['assessment_id']);
        }
        return $packet;
    }

    /**
     * Attendance sheet for one class/day — name, code, status, note only.
     */
    public static function attendanceRows(\mysqli $conn, int $classId, string $date): array
    {
        if ($classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }
        $stmt = $conn->prepare(
            "SELECT a.member_id, a.status, a.notes, m.student_name, m.father_name, m.member_code
             FROM attendance a
             JOIN members m ON m.id = a.member_id
             WHERE a.class_id = ? AND a.attendance_date = ?
             ORDER BY m.student_name"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $rows = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $rows[] = [
                'member_id' => (int)$row['member_id'],
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
                'member_code' => $row['member_code'] ?? '',
                'status' => $row['status'] ?? '',
                'notes' => $row['notes'] ?? '',
            ];
        }
        $stmt->close();
        return $rows;
    }

    public static function marklistRows(\mysqli $conn, int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }
        $stmt = $conn->prepare(
            "SELECT ar.member_id, ar.score, ar.max_score, ar.remarks,
                    m.student_name, m.father_name, m.member_code
             FROM academic_records ar
             JOIN members m ON m.id = ar.member_id
             WHERE ar.assessment_id = ?
             ORDER BY m.student_name"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $rows = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $score = $row['score'] !== null ? (float)$row['score'] : null;
            $max = $row['max_score'] !== null ? (float)$row['max_score'] : null;
            $rows[] = [
                'member_id' => (int)$row['member_id'],
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
                'member_code' => $row['member_code'] ?? '',
                'score' => $score,
                'max_score' => $max,
                'remarks' => $row['remarks'] ?? '',
            ];
        }
        $stmt->close();
        return $rows;
    }

    public static function countsFromRecords(array $records): array
    {
        $out = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'student_count' => 0];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $out['student_count']++;
            $st = strtolower((string)($rec['status'] ?? 'present'));
            if (isset($out[$st])) {
                $out[$st]++;
            }
        }
        return $out;
    }

    private static function presentRow(array $row): array
    {
        $status = self::normalizeStatus($row['status'] ?? '');
        $uiStatus = ($status === self::STATUS_INCOMPLETE) ? self::STATUS_DRAFT : $status;
        return [
            'id' => (int)$row['id'],
            'teacher_id' => (int)($row['teacher_id'] ?? 0),
            'class_id' => (int)($row['class_id'] ?? 0),
            'subject_id' => (int)($row['subject_id'] ?? 0),
            'academic_year_id' => isset($row['academic_year_id']) ? (int)$row['academic_year_id'] : null,
            'term_id' => isset($row['term_id']) ? (int)$row['term_id'] : null,
            'assessment_id' => isset($row['assessment_id']) ? (int)$row['assessment_id'] : null,
            'attendance_date' => $row['attendance_date'] ?? null,
            'submission_type' => $row['submission_type'] ?? self::TYPE_MARKLIST,
            'status' => $uiStatus,
            'status_label' => self::statusLabel($uiStatus),
            'student_count' => (int)($row['student_count'] ?? 0),
            'average_score' => $row['average_score'] !== null ? (float)$row['average_score'] : null,
            'present_count' => (int)($row['present_count'] ?? 0),
            'absent_count' => (int)($row['absent_count'] ?? 0),
            'late_count' => (int)($row['late_count'] ?? 0),
            'excused_count' => (int)($row['excused_count'] ?? 0),
            'submitted_at' => $row['submitted_at'] ?? null,
            'reviewed_by' => isset($row['reviewed_by']) ? (int)$row['reviewed_by'] : null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'review_notes' => $row['review_notes'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'teacher_name' => $row['teacher_name'] ?? '',
            'class_name' => $row['class_name'] ?? '',
            'class_name_en' => $row['class_name_en'] ?? '',
            'subject_name' => $row['subject_name'] ?? '',
            'subject_name_en' => $row['subject_name_en'] ?? '',
            'assessment_name' => $row['assessment_name'] ?? '',
            'max_score' => isset($row['max_score']) ? (float)$row['max_score'] : null,
            'year_name' => $row['year_name'] ?? '',
            'reviewer_name' => $row['reviewer_name'] ?? '',
        ];
    }

    public static function statusLabel(string $status): string
    {
        switch (self::normalizeStatus($status)) {
            case self::STATUS_INCOMPLETE:
            case self::STATUS_DRAFT:
                return 'Incomplete';
            case self::STATUS_SUBMITTED:
                return 'Complete';
            case self::STATUS_APPROVED:
                return 'Approved';
            case self::STATUS_REJECTED:
                return 'Rejected';
            case self::STATUS_REVISION:
                return 'Needs revision';
            default:
                return $status;
        }
    }
}
