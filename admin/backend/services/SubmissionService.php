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

    /** Compatibility hook; schema is deployment-managed by migration 013. */
    public static function ensureTable(\mysqli $conn): void
    {
    }

    /** Compatibility hook; unique keys are deployment-managed by migration 013. */
    public static function hardenUniques(\mysqli $conn): void
    {
    }

    public static function staffCanOverride(array $auth): bool
    {
        $role = (string)($auth['rol'] ?? $auth['role'] ?? '');
        return in_array($role, ['super_admin', 'school_admin', 'edu_dept'], true);
    }

    /** draft / incomplete / needs-revision can be changed by the teacher. */
    public static function statusIsOpen(?string $status): bool
    {
        $status = self::normalizeStatus($status);
        return in_array($status, [self::STATUS_DRAFT, self::STATUS_INCOMPLETE, self::STATUS_REVISION], true)
            || $status === '';
    }

    public static function attendancePacketStatus(\mysqli $conn, int $classId, string $date): ?string
    {
        self::ensureTable($conn);
        if ($classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT status FROM grade_submissions
             WHERE class_id = ? AND attendance_date = ? AND submission_type = 'attendance'
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? self::normalizeStatus($row['status'] ?? '') : null;
    }

    public static function marklistPacketStatus(\mysqli $conn, int $assessmentId): ?string
    {
        self::ensureTable($conn);
        if ($assessmentId <= 0) {
            return null;
        }
        // PATCH C2/H8: a submitted/approved packet is the workflow truth for
        // the assessment — a *newer* draft packet (e.g. a staff correction)
        // must never silently re-open a locked mark list.
        foreach (
            [
                "SELECT status FROM grade_submissions
                 WHERE assessment_id = ? AND submission_type = 'marklist'
                   AND status IN ('submitted','approved')
                 ORDER BY id DESC LIMIT 1",
                "SELECT status FROM grade_submissions
                 WHERE assessment_id = ? AND submission_type = 'marklist'
                 ORDER BY id DESC LIMIT 1",
            ] as $sql
        ) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('i', $assessmentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return self::normalizeStatus($row['status'] ?? '');
            }
        }
        return null;
    }

    /**
     * Reviewer feedback attached to the latest packet, for mobile clients.
     * Returned only while the packet is in revision_needed so the teacher
     * sees WHY Education handed the key back. Null otherwise.
     *
     * @return array{review_notes:string,reviewed_at:string,reviewer_name:string}|null
     */
    public static function attendanceReview(\mysqli $conn, int $classId, string $date): ?array
    {
        self::ensureTable($conn);
        if ($classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT gs.review_notes, gs.reviewed_at, COALESCE(u.full_name, '') AS reviewer_name
             FROM grade_submissions gs
             LEFT JOIN users u ON gs.reviewed_by = u.id
             WHERE gs.class_id = ? AND gs.attendance_date = ?
               AND gs.submission_type = 'attendance' AND gs.status = 'revision_needed'
             ORDER BY gs.id DESC LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'review_notes' => (string)($row['review_notes'] ?? ''),
            'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
            'reviewer_name' => (string)($row['reviewer_name'] ?? ''),
        ];
    }

    /** @return array{review_notes:string,reviewed_at:string,reviewer_name:string}|null */
    public static function marklistReview(\mysqli $conn, int $assessmentId): ?array
    {
        self::ensureTable($conn);
        if ($assessmentId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT gs.review_notes, gs.reviewed_at, COALESCE(u.full_name, '') AS reviewer_name
             FROM grade_submissions gs
             LEFT JOIN users u ON gs.reviewed_by = u.id
             WHERE gs.assessment_id = ? AND gs.submission_type = 'marklist'
               AND gs.status = 'revision_needed'
             ORDER BY gs.id DESC LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'review_notes' => (string)($row['review_notes'] ?? ''),
            'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
            'reviewer_name' => (string)($row['reviewer_name'] ?? ''),
        ];
    }

    public static function attendanceHasRows(\mysqli $conn, int $classId, string $date): bool
    {
        if ($classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $stmt = $conn->prepare(
            "SELECT 1 FROM attendance WHERE class_id = ? AND attendance_date = ? LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    public static function marklistHasRows(\mysqli $conn, int $assessmentId): bool
    {
        if ($assessmentId <= 0) {
            return false;
        }
        $stmt = $conn->prepare(
            "SELECT 1 FROM academic_records WHERE assessment_id = ? LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    /**
     * Packet status, or "submitted" for older sheets that already have
     * marks but never got a packet. Empty days stay open so a teacher
     * can still take a missed Sunday.
     */
    public static function resolvedAttendanceStatus(\mysqli $conn, int $classId, string $date): ?string
    {
        $status = self::attendancePacketStatus($conn, $classId, $date);
        if ($status !== null) {
            return $status;
        }
        return self::attendanceHasRows($conn, $classId, $date) ? self::STATUS_SUBMITTED : null;
    }

    public static function resolvedMarklistStatus(\mysqli $conn, int $assessmentId): ?string
    {
        $status = self::marklistPacketStatus($conn, $assessmentId);
        if ($status !== null) {
            return $status;
        }
        return self::marklistHasRows($conn, $assessmentId) ? self::STATUS_SUBMITTED : null;
    }

    public static function teacherMayWriteAttendance(\mysqli $conn, array $auth, int $classId, string $date): bool
    {
        if (self::staffCanOverride($auth)) {
            return true;
        }
        $status = self::resolvedAttendanceStatus($conn, $classId, $date);
        return $status === null || self::statusIsOpen($status);
    }

    public static function teacherMayWriteMarklist(\mysqli $conn, array $auth, int $assessmentId): bool
    {
        if (self::staffCanOverride($auth)) {
            return true;
        }
        $status = self::resolvedMarklistStatus($conn, $assessmentId);
        return $status === null || self::statusIsOpen($status);
    }

    public static function isLockedForTeacher(?string $status, array $auth): bool
    {
        if (self::staffCanOverride($auth)) {
            return false;
        }
        return $status !== null && $status !== '' && !self::statusIsOpen($status);
    }

    /**
     * H9: reviewer decisions are only valid on packets AWAITING review.
     * Returns null when the transition is allowed, or a user-safe reason
     * explaining why the packet cannot be decided in its current state
     * (reviewing a draft, approving a rejected list, rejecting an approved
     * one, …). Shared by the web and mobile review endpoints.
     */
    public static function reviewTransitionError(?string $current, string $target): ?string
    {
        $cur = self::normalizeStatus($current);
        if ($cur === self::STATUS_SUBMITTED) {
            return null; // awaiting review — approve / return / reject are all valid
        }
        if ($cur === '' || self::statusIsOpen($cur)) {
            return 'This list has not been submitted for review yet. The teacher must submit it first.';
        }
        switch ($cur) {
            case self::STATUS_APPROVED:
                return 'This list is already approved.';
            case self::STATUS_REJECTED:
                return 'This list was rejected. Ask the teacher to submit a corrected list.';
            case self::STATUS_REVISION:
                return 'This list was returned to the teacher and has not been resubmitted yet.';
        }
        return 'This list cannot be reviewed in its current state.';
    }

    /** One score per student per test. Always update the existing row. */
    public static function upsertScore(\mysqli $conn, array $row): int
    {
        self::hardenUniques($conn);
        $assessmentId = (int)($row['assessment_id'] ?? 0);
        $memberId = (int)($row['member_id'] ?? 0);
        if ($assessmentId <= 0 || $memberId <= 0) {
            return 0;
        }
        $score = array_key_exists('score', $row) ? $row['score'] : null;
        $remarks = trim((string)($row['remarks'] ?? $row['remark'] ?? ''));
        $recordedBy = (int)($row['recorded_by'] ?? 0);
        $classId = (int)($row['class_id'] ?? 0);
        $subjectId = (int)($row['subject_id'] ?? 0);
        $yearId = isset($row['year_id']) ? (int)$row['year_id'] : null;
        $termId = isset($row['term_id']) ? (int)$row['term_id'] : null;
        $maxScore = isset($row['max_score']) ? (float)$row['max_score'] : 100;
        $submissionId = isset($row['submission_id']) ? (int)$row['submission_id'] : null;

        $find = $conn->prepare(
            "SELECT id FROM academic_records WHERE assessment_id = ? AND member_id = ? ORDER BY id DESC LIMIT 1"
        );
        $existing = 0;
        if ($find) {
            $find->bind_param('ii', $assessmentId, $memberId);
            $find->execute();
            $got = $find->get_result()->fetch_assoc();
            $find->close();
            $existing = (int)($got['id'] ?? 0);
        }
        if ($existing > 0) {
            $up = $conn->prepare(
                "UPDATE academic_records SET score = ?, remarks = ?, recorded_by = ?,
                        submission_id = COALESCE(?, submission_id)
                 WHERE id = ?"
            );
            if (!$up) {
                return 0;
            }
            $up->bind_param('dsiii', $score, $remarks, $recordedBy, $submissionId, $existing);
            $up->execute();
            $up->close();
            return $existing;
        }
        $ins = $conn->prepare(
            "INSERT INTO academic_records
                (member_id, class_id, subject_id, academic_year_id, term_id, assessment_id, submission_id, score, max_score, remarks, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$ins) {
            return 0;
        }
        $ins->bind_param(
            'iiiiiiiddsi',
            $memberId,
            $classId,
            $subjectId,
            $yearId,
            $termId,
            $assessmentId,
            $submissionId,
            $score,
            $maxScore,
            $remarks,
            $recordedBy
        );
        $ins->execute();
        $id = (int)$ins->insert_id;
        $ins->close();
        return $id;
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
             WHERE class_id = ? AND submission_type = 'attendance' AND attendance_date = ?
             ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('is', $classId, $date);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $existingId = (int)($row['id'] ?? 0);
        }

        $submittedAt = $status === self::STATUS_SUBMITTED ? date('Y-m-d H:i:s') : null;

        if ($existingId > 0) {
            $cur = $conn->prepare("SELECT status FROM grade_submissions WHERE id = ? LIMIT 1");
            $curStatus = '';
            if ($cur) {
                $cur->bind_param('i', $existingId);
                $cur->execute();
                $curStatus = (string)($cur->get_result()->fetch_assoc()['status'] ?? '');
                $cur->close();
            }
            if (!self::statusIsOpen($curStatus) && empty($opts['force'])) {
                return [
                    'ok' => false,
                    'id' => $existingId,
                    'status' => self::normalizeStatus($curStatus),
                    'message' => 'This attendance is already submitted. Only Education can change it.',
                ];
            }
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

        // PATCH C2/H8: the packet is the workflow record OF THE ASSESSMENT.
        // Bind to the canonical packet regardless of which user is saving,
        // preferring a locked (submitted/approved) one over newer drafts.
        $existingId = 0;
        $existingTeacherId = 0;
        foreach (
            [
                "SELECT id, teacher_id FROM grade_submissions
                 WHERE assessment_id = ? AND submission_type = 'marklist'
                   AND status IN ('submitted','approved')
                 ORDER BY id DESC LIMIT 1",
                "SELECT id, teacher_id FROM grade_submissions
                 WHERE assessment_id = ? AND submission_type = 'marklist'
                 ORDER BY id DESC LIMIT 1",
            ] as $sql
        ) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('i', $assessmentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $existingId = (int)$row['id'];
                $existingTeacherId = (int)$row['teacher_id'];
                break;
            }
        }

        $force = !empty($opts['force']);
        // Staff corrections keep ownership with the responsible teacher;
        // a teacher saving claims ownership of their own mark list.
        $ownerId = $existingId > 0 && $force ? $existingTeacherId : $teacherId;

        if ($existingId > 0) {
            $cur = $conn->prepare("SELECT status FROM grade_submissions WHERE id = ? LIMIT 1");
            $curStatus = '';
            if ($cur) {
                $cur->bind_param('i', $existingId);
                $cur->execute();
                $curStatus = (string)($cur->get_result()->fetch_assoc()['status'] ?? '');
                $cur->close();
            }
            if (!self::statusIsOpen($curStatus) && !$force) {
                return [
                    'ok' => false,
                    'id' => $existingId,
                    'status' => self::normalizeStatus($curStatus),
                    'message' => 'This mark list is already submitted. Only Education can change it.',
                ];
            }
            // Never regress a locked packet: a staff correction updates the
            // numbers but the list stays submitted/approved. On an open
            // packet, a draft save keeps the current open status (so a
            // revision_needed signal is not erased); only a submit advances.
            $curNorm = self::normalizeStatus($curStatus);
            if (!self::statusIsOpen($curNorm)) {
                $finalStatus = $curNorm;
                $finalMessage = $curNorm === self::STATUS_APPROVED
                    ? 'Correction saved to the approved list.'
                    : 'Correction saved to the submitted list.';
            } elseif ($status === self::STATUS_SUBMITTED) {
                $finalStatus = $status;
                $finalMessage = 'Mark list submitted.';
            } else {
                $finalStatus = $curNorm === '' ? $status : $curNorm;
                $finalMessage = 'Saved as a draft for Education.';
            }
            $newSubmit = $finalStatus === self::STATUS_SUBMITTED && $curNorm !== self::STATUS_SUBMITTED
                ? date('Y-m-d H:i:s') : null;
            // H10: a (re)submission means the teacher addressed the review
            // note — clear the stale reviewer feedback so the packet's next
            // review cycle starts clean. Corrections that keep the current
            // status leave the review trail untouched.
            $clearReview = $newSubmit !== null ? ', review_notes = NULL, reviewed_by = NULL, reviewed_at = NULL' : '';
            $up = $conn->prepare(
                "UPDATE grade_submissions
                 SET teacher_id = ?, status = ?, student_count = ?, average_score = ?, class_id = ?, subject_id = ?,
                     academic_year_id = ?, term_id = ?, submitted_at = COALESCE(?, submitted_at), updated_at = NOW()
                     $clearReview
                 WHERE id = ?"
            );
            if (!$up) {
                return ['ok' => false, 'id' => $existingId, 'status' => $finalStatus, 'message' => 'Could not update mark list.'];
            }
            $up->bind_param(
                'isidiiiisi',
                $ownerId,
                $finalStatus,
                $count,
                $avg,
                $classId,
                $subjectId,
                $yearId,
                $termId,
                $newSubmit,
                $existingId
            );
            $ok = $up->execute();
            $up->close();
            return [
                'ok' => (bool)$ok,
                'id' => $existingId,
                'status' => $finalStatus,
                'message' => $finalMessage,
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
        $insSubmittedAt = $status === self::STATUS_SUBMITTED ? date('Y-m-d H:i:s') : null;
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
            $insSubmittedAt
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
            "SELECT a.id, a.member_id, a.status, a.notes, m.student_name, m.father_name, m.member_code
             FROM attendance a
             JOIN members m ON m.id = a.member_id
             WHERE a.class_id = ? AND a.attendance_date = ?
             ORDER BY a.id ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('is', $classId, $date);
        $stmt->execute();
        $byMember = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $mid = (int)$row['member_id'];
            $byMember[$mid] = [
                'member_id' => $mid,
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
                'member_code' => $row['member_code'] ?? '',
                'status' => $row['status'] ?? '',
                'notes' => $row['notes'] ?? '',
            ];
        }
        $stmt->close();
        $rows = array_values($byMember);
        usort($rows, static function ($a, $b) {
            return strcasecmp((string)$a['student_name'], (string)$b['student_name']);
        });
        return $rows;
    }

    public static function marklistRows(\mysqli $conn, int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }
        $stmt = $conn->prepare(
            "SELECT ar.id, ar.member_id, ar.score, ar.max_score, ar.remarks,
                    m.student_name, m.father_name, m.member_code
             FROM academic_records ar
             JOIN members m ON m.id = ar.member_id
             WHERE ar.assessment_id = ?
             ORDER BY ar.id ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $assessmentId);
        $stmt->execute();
        $byMember = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $mid = (int)$row['member_id'];
            $score = $row['score'] !== null ? (float)$row['score'] : null;
            $max = $row['max_score'] !== null ? (float)$row['max_score'] : null;
            $byMember[$mid] = [
                'member_id' => $mid,
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
                'member_code' => $row['member_code'] ?? '',
                'score' => $score,
                'max_score' => $max,
                'remarks' => $row['remarks'] ?? '',
            ];
        }
        $stmt->close();
        $rows = array_values($byMember);
        usort($rows, static function ($a, $b) {
            return strcasecmp((string)$a['student_name'], (string)$b['student_name']);
        });
        return $rows;
    }

    public static function countsFromRecords(array $records): array
    {
        $out = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'student_count' => 0];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $st = strtolower(trim((string)($rec['status'] ?? '')));
            if (isset($out[$st])) {
                $out[$st]++;
                $out['student_count']++;
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
