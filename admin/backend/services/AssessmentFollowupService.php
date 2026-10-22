<?php
/**
 * ============================================================
 * AssessmentFollowupService — Attendance alerting, Phase 5
 * ============================================================
 * Requirements §11–§12 (owner-approved, locked) + plan §Phase 5 (T-8):
 *
 *   • When a member's EDUCATION department status returns to `active`
 *     (HR / Info reactivation after a manual or automatic deactivation),
 *     find the assessments of the member's class(es) that fell inside the
 *     inactive period and for which the member has NO score, and open one
 *     `assessment_followups` row each (decision = pending).
 *   • Nothing is auto-scored: there is NO automatic zero. The responsible
 *     teacher (teacher_assignments, class + subject) or the Education
 *     department records the decision: makeup_required / exempt /
 *     not_applicable (or leaves it pending).
 *   • A make-up result lives in `assessment_makeup_attempts`, a SEPARATE
 *     table linked to the follow-up and (when present) to the ORIGINAL
 *     academic_records row, which is never overwritten (T-8, §12 "nothing
 *     erased").
 *   • Idempotent: UNIQUE(member_id, period_id, assessment_id) means a
 *     repeated reactivation of the same period never duplicates rows.
 *   • Bell: the responsible teachers (per user) and the Education
 *     department (role) are notified in-app only (§7 — no e-mail/SMS).
 *   • Dates returned to callers are Ethiopian-calendar labels.
 *
 * Availability: FEATURE_ATTENDANCE_V2 on AND sql/075 applied. Every public
 * entry point degrades to "not available" / no-op before that.
 * Shared-hosting safe: plain mysqli, no cron (detection is synchronous in
 * the reactivation request).
 * ============================================================
 */

namespace App\Services;

require_once __DIR__ . '/FeatureGate.php';
require_once __DIR__ . '/SecurityAuditService.php';
require_once __DIR__ . '/../ethiopian_date.php';

final class AssessmentFollowupService
{
    public const TABLE = 'assessment_followups';
    public const ATTEMPTS = 'assessment_makeup_attempts';
    public const NOTIFICATION_TYPE = 'assessment_followup';

    public const DECISIONS = ['pending', 'makeup_required', 'exempt', 'not_applicable'];
    public const DECISION_LABELS = [
        'pending' => 'Pending · በመጠባበቅ ላይ',
        'makeup_required' => 'Make-up required · ማካካሻ ያስፈልጋል',
        'exempt' => 'Exempt · ነፃ',
        'not_applicable' => 'Not applicable · አይመለከትም',
    ];

    /** Roles that may READ the queue (web + mobile). */
    public const READ_ROLES = ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'];
    /** Roles that may decide / record make-up attempts (teachers only inside their assignment). */
    public const WRITE_ROLES = ['edu_dept', 'teacher'];

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
                $r = $conn->query("SHOW TABLES LIKE '" . self::ATTEMPTS . "'");
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

    public static function roleMayWrite(string $role): bool
    {
        return in_array($role, self::WRITE_ROLES, true);
    }

    // ── detection on reactivation ─────────────────────────────────

    /**
     * Never throws: called from DepartmentStatusService after its commit; a
     * failure here must not undo or hide the status change.
     *
     * @return array{created:int,assessments:list<int>}
     */
    public static function safeOnEducationReactivation(\mysqli $conn, int $memberId, int $inactivePeriodId, ?int $actorId, ?SecurityAuditActor $auditActor = null): array
    {
        try {
            return self::onEducationReactivation($conn, $memberId, $inactivePeriodId, $actorId, $auditActor);
        } catch (\Throwable $e) {
            error_log('Assessment follow-up detection failed: ' . $e->getMessage());
            return ['created' => 0, 'assessments' => []];
        }
    }

    /**
     * Detect the assessments missed during the closed inactive period and
     * open pending follow-ups (idempotent).
     *
     * @return array{created:int,assessments:list<int>}
     */
    public static function onEducationReactivation(\mysqli $conn, int $memberId, int $inactivePeriodId, ?int $actorId, ?SecurityAuditActor $auditActor = null): array
    {
        $none = ['created' => 0, 'assessments' => []];
        if (!self::available($conn) || $memberId <= 0 || $inactivePeriodId <= 0) {
            return $none;
        }
        $period = self::period($conn, $inactivePeriodId);
        if (!$period || $period['department'] !== 'edu' || $period['status'] !== 'inactive' || (int)$period['member_id'] !== $memberId) {
            return $none;
        }
        $from = substr((string)$period['started_at'], 0, 10);
        $to = $period['ended_at'] !== null ? substr((string)$period['ended_at'], 0, 10) : date('Y-m-d');
        $missed = self::detectMissed($conn, $memberId, $from, $to);
        if (!$missed) {
            return $none;
        }

        $created = [];
        $ins = $conn->prepare(
            'INSERT IGNORE INTO ' . self::TABLE .
            ' (member_id, period_id, assessment_id, class_id, subject_id, teacher_id, decision, created_at)
              VALUES (?, ?, ?, ?, ?, ?, \'pending\', NOW())'
        );
        if (!$ins) {
            throw new \RuntimeException('Unable to prepare follow-up insert.');
        }
        foreach ($missed as $a) {
            $teachers = self::responsibleTeachers($conn, (int)$a['class_id'], (int)$a['subject_id'], (int)$a['academic_year_id']);
            $primary = $teachers[0] ?? null;
            $aid = (int)$a['id'];
            $cid = (int)$a['class_id'];
            $sid = (int)$a['subject_id'];
            $ins->bind_param('iiiiii', $memberId, $inactivePeriodId, $aid, $cid, $sid, $primary);
            $ins->execute();
            if ($ins->affected_rows > 0) {
                $created[] = ['id' => (int)$conn->insert_id, 'assessment' => $a, 'teachers' => $teachers];
            }
        }
        $ins->close();
        if (!$created) {
            return $none;
        }

        $ids = array_map(static fn($c) => (int)$c['assessment']['id'], $created);
        self::notifyDetected($conn, $memberId, $created, $from, $to, $actorId);
        $details = [
            'member_id' => $memberId, 'period_id' => $inactivePeriodId,
            'window_from_ec' => self::ecLabel($from), 'window_to_ec' => self::ecLabel($to),
            'assessment_ids' => $ids, 'created' => count($created),
        ];
        self::audit($conn, $auditActor, 'Assessment Follow-ups Opened', $details, $memberId);
        return ['created' => count($created), 'assessments' => $ids];
    }

    /**
     * Assessments of the member's enrolled class(es) whose due date — or,
     * when the assessment has no due date, whose term window — falls inside
     * [$from, $to] and for which the member has no scored academic_records
     * row. An assessment that already has a follow-up for this member (from
     * an earlier inactive period — e.g. a term-window match) is not opened
     * again. Assessments with neither a due date nor term dates cannot be
     * placed in time and are skipped (documented limitation).
     *
     * @return list<array<string,mixed>>
     */
    public static function detectMissed(\mysqli $conn, int $memberId, string $from, string $to): array
    {
        $sql = "SELECT a.id, a.class_id, a.subject_id, a.academic_year_id, a.term_id, a.assessment_name,
                       a.assessment_type, a.max_score, a.due_date, t.start_date AS term_start, t.end_date AS term_end
                FROM assessments a
                LEFT JOIN academic_terms t ON t.id = a.term_id
                WHERE EXISTS (
                        SELECT 1 FROM class_enrollments ce
                        WHERE ce.member_id = ? AND ce.class_id = a.class_id
                          AND ce.academic_year_id = a.academic_year_id
                          AND ce.status IN ('active', 'completed'))
                  AND (
                        (a.due_date IS NOT NULL AND a.due_date BETWEEN ? AND ?)
                     OR (a.due_date IS NULL AND t.start_date IS NOT NULL AND t.end_date IS NOT NULL
                         AND t.start_date <= ? AND t.end_date >= ?)
                  )
                  AND NOT EXISTS (
                        SELECT 1 FROM academic_records ar
                        WHERE ar.member_id = ? AND ar.assessment_id = a.id AND ar.score IS NOT NULL)
                  AND NOT EXISTS (
                        SELECT 1 FROM " . self::TABLE . " f
                        WHERE f.member_id = ? AND f.assessment_id = a.id)
                ORDER BY a.due_date ASC, a.id ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('issssii', $memberId, $from, $to, $to, $from, $memberId, $memberId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** users.id of the active teachers assigned to class (+ subject, or class-wide). @return list<int> */
    public static function responsibleTeachers(\mysqli $conn, int $classId, int $subjectId, int $yearId): array
    {
        $stmt = $conn->prepare(
            'SELECT DISTINCT ta.teacher_id
               FROM teacher_assignments ta
               JOIN users u ON u.id = ta.teacher_id AND u.is_active = 1
              WHERE ta.class_id = ? AND ta.is_active = 1
                AND (ta.subject_id = ? OR ta.subject_id IS NULL)
                AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)
              ORDER BY (ta.subject_id IS NULL) ASC, ta.is_primary DESC, ta.id ASC'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iii', $classId, $subjectId, $yearId);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[] = (int)$r['teacher_id'];
        }
        $stmt->close();
        return $out;
    }

    /** True when $teacherId actively teaches $subjectId in $classId (or is class-wide assigned). */
    public static function teacherMayHandle(\mysqli $conn, int $teacherId, int $classId, int $subjectId, ?int $yearId = null): bool
    {
        if ($teacherId <= 0 || $classId <= 0) {
            return false;
        }
        $sql = 'SELECT 1 FROM teacher_assignments
                 WHERE teacher_id = ? AND class_id = ? AND is_active = 1
                   AND (subject_id = ? OR subject_id IS NULL)';
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

    // ── queue reads ───────────────────────────────────────────────

    /**
     * @param array{teacher_id?:int,decision?:string,class_id?:int,subject_id?:int,member_id?:int,q?:string,limit?:int,offset?:int,open_only?:bool} $f
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function listQueue(\mysqli $conn, array $f = []): array
    {
        if (!self::available($conn)) {
            return ['items' => [], 'total' => 0];
        }
        [$where, $types, $params] = self::filterSql($f);
        $limit = max(1, min(200, (int)($f['limit'] ?? 50)));
        $offset = max(0, (int)($f['offset'] ?? 0));

        $count = $conn->prepare('SELECT COUNT(*) AS c FROM ' . self::TABLE . ' f ' . self::joins() . ' WHERE ' . $where);
        if (!$count) {
            return ['items' => [], 'total' => 0];
        }
        if ($types !== '') {
            $count->bind_param($types, ...$params);
        }
        $count->execute();
        $total = (int)($count->get_result()->fetch_assoc()['c'] ?? 0);
        $count->close();

        $sql = 'SELECT ' . self::selectCols() . ' FROM ' . self::TABLE . ' f ' . self::joins() .
               ' WHERE ' . $where .
               " ORDER BY FIELD(f.decision, 'pending', 'makeup_required', 'exempt', 'not_applicable'), f.created_at DESC, f.id DESC
                 LIMIT {$limit} OFFSET {$offset}";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['items' => [], 'total' => $total];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $items = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $items[] = self::present($row);
        }
        $stmt->close();
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,int> decision => count (same scope filter as listQueue, decision ignored) */
    public static function counts(\mysqli $conn, array $f = []): array
    {
        $out = array_fill_keys(self::DECISIONS, 0) + ['total' => 0, 'with_attempt' => 0];
        if (!self::available($conn)) {
            return $out;
        }
        unset($f['decision'], $f['open_only']);
        [$where, $types, $params] = self::filterSql($f);
        $stmt = $conn->prepare(
            'SELECT f.decision, COUNT(*) AS c,
                    SUM(EXISTS(SELECT 1 FROM ' . self::ATTEMPTS . ' m WHERE m.followup_id = f.id)) AS with_attempt
               FROM ' . self::TABLE . ' f ' . self::joins() . ' WHERE ' . $where . ' GROUP BY f.decision'
        );
        if (!$stmt) {
            return $out;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(string)$r['decision']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
            $out['with_attempt'] += (int)$r['with_attempt'];
        }
        $stmt->close();
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(\mysqli $conn, int $id): ?array
    {
        if (!self::available($conn) || $id <= 0) {
            return null;
        }
        $stmt = $conn->prepare('SELECT ' . self::selectCols() . ' FROM ' . self::TABLE . ' f ' . self::joins() . ' WHERE f.id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $item = self::present($row);
        $item['attempts'] = self::attempts($conn, $id);
        return $item;
    }

    /** @return list<array<string,mixed>> newest first */
    public static function attempts(\mysqli $conn, int $followupId): array
    {
        $stmt = $conn->prepare(
            'SELECT m.id, m.followup_id, m.original_record_id, m.score, m.max_score, m.recorded_by, m.recorded_at, m.remarks,
                    u.full_name AS recorded_by_name
               FROM ' . self::ATTEMPTS . ' m LEFT JOIN users u ON u.id = m.recorded_by
              WHERE m.followup_id = ? ORDER BY m.id DESC'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $followupId);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'original_record_id' => $r['original_record_id'] !== null ? (int)$r['original_record_id'] : null,
                'score' => (float)$r['score'],
                'max_score' => (float)$r['max_score'],
                'percentage' => (float)$r['max_score'] > 0 ? round((float)$r['score'] / (float)$r['max_score'] * 100, 1) : null,
                'recorded_by' => (int)$r['recorded_by'],
                'recorded_by_name' => (string)($r['recorded_by_name'] ?? ''),
                'recorded_at_ec' => self::ecDateTime((string)$r['recorded_at']),
                'remarks' => (string)($r['remarks'] ?? ''),
            ];
        }
        $stmt->close();
        return $out;
    }

    // ── writes ────────────────────────────────────────────────────

    /**
     * Record the teacher / Education decision. Rules:
     *   • $decision ∈ DECISIONS; `pending` re-opens an undecided item.
     *   • Once a make-up attempt exists the decision is locked to
     *     makeup_required (history must stay consistent; nothing erased).
     *   • Teachers only inside their (class, subject) assignment.
     * @throws \DomainException user-facing
     */
    public static function decide(\mysqli $conn, int $id, string $decision, string $note, int $actorId, string $actorRole, ?SecurityAuditActor $auditActor = null): array
    {
        if (!self::available($conn)) {
            throw new \DomainException('Assessment follow-ups are not available yet.');
        }
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new \DomainException('Unknown decision.');
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new \DomainException('The note must be 500 characters or fewer.');
        }
        $row = self::find($conn, $id);
        if (!$row) {
            throw new \DomainException('Follow-up not found.');
        }
        self::assertMayWrite($conn, $row, $actorId, $actorRole);
        if ($row['attempt_count'] > 0 && $decision !== 'makeup_required') {
            throw new \DomainException('A make-up attempt has been recorded; the decision stays "make-up required".');
        }
        if (in_array($decision, ['exempt', 'not_applicable'], true) && $note === '') {
            throw new \DomainException('Please give a short reason for this decision.');
        }
        $previous = (string)$row['decision'];
        if ($previous === $decision && (string)$row['note'] === $note) {
            return ['changed' => false, 'item' => $row];
        }
        if ($decision === 'pending') {
            $stmt = $conn->prepare('UPDATE ' . self::TABLE . ' SET decision = ?, decided_by = NULL, decided_at = NULL, note = ? WHERE id = ?');
            $noteOrNull = $note !== '' ? $note : null;
            $stmt->bind_param('ssi', $decision, $noteOrNull, $id);
        } else {
            $stmt = $conn->prepare('UPDATE ' . self::TABLE . ' SET decision = ?, decided_by = ?, decided_at = NOW(), note = ? WHERE id = ?');
            $noteOrNull = $note !== '' ? $note : null;
            $stmt->bind_param('sisi', $decision, $actorId, $noteOrNull, $id);
        }
        $stmt->execute();
        $stmt->close();
        $details = ['followup_id' => $id, 'member_id' => (int)$row['member_id'], 'assessment_id' => (int)$row['assessment_id'],
                    'class_id' => (int)$row['class_id'], 'subject_id' => (int)$row['subject_id'],
                    'from' => $previous, 'to' => $decision, 'note' => $note];
        self::audit($conn, $auditActor, 'Assessment Follow-up Decided', $details, (int)$row['member_id']);
        return ['changed' => true, 'item' => self::find($conn, $id)];
    }

    /**
     * Record a make-up attempt (decision must be makeup_required). The
     * original academic_records row, if any, is linked — never changed.
     * @throws \DomainException user-facing
     */
    public static function recordAttempt(\mysqli $conn, int $id, $score, $maxScore, string $remarks, int $actorId, string $actorRole, ?SecurityAuditActor $auditActor = null): array
    {
        if (!self::available($conn)) {
            throw new \DomainException('Assessment follow-ups are not available yet.');
        }
        $row = self::find($conn, $id);
        if (!$row) {
            throw new \DomainException('Follow-up not found.');
        }
        self::assertMayWrite($conn, $row, $actorId, $actorRole);
        if ($row['decision'] !== 'makeup_required') {
            throw new \DomainException('Mark the follow-up as "make-up required" before recording a result.');
        }
        if (!is_numeric($score)) {
            throw new \DomainException('Score must be a number.');
        }
        $score = round((float)$score, 2);
        // Score-limit rule (2026-10-22): the assessment's own max is the
        // ceiling. A client-sent max may only be LOWER — a higher one is
        // REFUSED (never silently changed: 5/20 must not become 5/10).
        $assessMax = round((float)($row['assessment_max_score'] ?? 0), 2);
        $max = is_numeric($maxScore) && (float)$maxScore > 0 ? round((float)$maxScore, 2) : $assessMax;
        if ($assessMax > 0 && $max > $assessMax) {
            throw new \DomainException('"Out of" cannot be more than the test\'s maximum '
                . rtrim(rtrim(number_format($assessMax, 2, '.', ''), '0'), '.') . '.');
        }
        if ($max <= 0) {
            throw new \DomainException('The assessment has no maximum score.');
        }
        if ($score < 0 || $score > $max) {
            throw new \DomainException('Score must be between 0 and ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '.');
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > 500) {
            throw new \DomainException('Remarks must be 500 characters or fewer.');
        }
        $originalId = self::originalRecordId($conn, (int)$row['member_id'], (int)$row['assessment_id']);
        $stmt = $conn->prepare(
            'INSERT INTO ' . self::ATTEMPTS . ' (followup_id, original_record_id, score, max_score, recorded_by, recorded_at, remarks)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)'
        );
        $remarksOrNull = $remarks !== '' ? $remarks : null;
        $stmt->bind_param('iiddis', $id, $originalId, $score, $max, $actorId, $remarksOrNull);
        $stmt->execute();
        $attemptId = (int)$conn->insert_id;
        $stmt->close();
        $details = ['followup_id' => $id, 'attempt_id' => $attemptId, 'member_id' => (int)$row['member_id'],
                    'assessment_id' => (int)$row['assessment_id'], 'original_record_id' => $originalId,
                    'score' => $score, 'max_score' => $max, 'remarks' => $remarks];
        self::audit($conn, $auditActor, 'Assessment Make-up Recorded', $details, (int)$row['member_id']);
        return ['attempt_id' => $attemptId, 'item' => self::find($conn, $id)];
    }

    // ── report-card / marklist precedence ─────────────────────────

    /**
     * Latest make-up per (member, assessment) for a class, with the
     * assessment columns the report card needs to synthesize a row when the
     * member had no original record. Display precedence (plan §Phase 5,
     * flagged for owner confirmation): the make-up SUPERSEDES the original
     * on cards; the original row stays untouched in academic_records.
     *
     * @return array<int,array<int,array<string,mixed>>> member_id => assessment_id => row
     */
    public static function latestMakeupsForClass(\mysqli $conn, int $classId): array
    {
        if (!self::available($conn) || $classId <= 0) {
            return [];
        }
        $stmt = $conn->prepare(
            'SELECT f.member_id, f.assessment_id, f.class_id, f.subject_id, m.id AS attempt_id, m.original_record_id,
                    m.score, m.max_score, m.remarks, m.recorded_at,
                    a.assessment_name, a.weight_percentage, a.max_score AS assess_max, a.academic_year_id, a.term_id,
                    s.subject_name, s.subject_name_en
               FROM ' . self::ATTEMPTS . ' m
               JOIN ' . self::TABLE . ' f ON f.id = m.followup_id
               JOIN assessments a ON a.id = f.assessment_id
               LEFT JOIN subjects s ON s.id = f.subject_id
              WHERE f.class_id = ?
              ORDER BY m.id ASC'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int)$r['member_id']][(int)$r['assessment_id']] = $r; // ascending id → last wins = latest
        }
        $stmt->close();
        return $out;
    }

    // ── internals ─────────────────────────────────────────────────

    private static function assertMayWrite(\mysqli $conn, array $row, int $actorId, string $actorRole): void
    {
        if (!self::roleMayWrite($actorRole)) {
            throw new \DomainException('Only the responsible teacher or the Education department can do this.');
        }
        if ($actorRole === 'teacher' && !self::teacherMayHandle($conn, $actorId, (int)$row['class_id'], (int)$row['subject_id'])) {
            throw new \DomainException('You are not assigned to this class and subject.');
        }
    }

    private static function originalRecordId(\mysqli $conn, int $memberId, int $assessmentId): ?int
    {
        $stmt = $conn->prepare('SELECT id FROM academic_records WHERE member_id = ? AND assessment_id = ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $memberId, $assessmentId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $r ? (int)$r['id'] : null;
    }

    /** @return array<string,mixed>|null */
    private static function period(\mysqli $conn, int $periodId): ?array
    {
        $stmt = $conn->prepare('SELECT id, member_id, department, status, started_at, ended_at FROM member_department_periods WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $periodId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $r ?: null;
    }

    private static function joins(): string
    {
        return ' JOIN members mb ON mb.id = f.member_id
                 JOIN assessments a ON a.id = f.assessment_id
                 LEFT JOIN classes c ON c.id = f.class_id
                 LEFT JOIN subjects s ON s.id = f.subject_id
                 LEFT JOIN member_department_periods p ON p.id = f.period_id
                 LEFT JOIN users du ON du.id = f.decided_by';
    }

    private static function selectCols(): string
    {
        return 'f.id, f.member_id, f.period_id, f.assessment_id, f.class_id, f.subject_id, f.teacher_id, f.decision,
                f.decided_by, f.decided_at, f.note, f.created_at,
                mb.student_name, mb.father_name, mb.member_code,
                a.assessment_name, a.assessment_type, a.max_score AS assessment_max_score, a.due_date, a.academic_year_id, a.term_id,
                c.class_name, s.subject_name, s.subject_name_en,
                p.started_at AS period_started_at, p.ended_at AS period_ended_at,
                du.full_name AS decided_by_name,
                (SELECT COUNT(*) FROM ' . self::ATTEMPTS . ' m WHERE m.followup_id = f.id) AS attempt_count,
                (SELECT m.score FROM ' . self::ATTEMPTS . ' m WHERE m.followup_id = f.id ORDER BY m.id DESC LIMIT 1) AS latest_score,
                (SELECT m.max_score FROM ' . self::ATTEMPTS . ' m WHERE m.followup_id = f.id ORDER BY m.id DESC LIMIT 1) AS latest_max_score,
                (SELECT ar.score FROM academic_records ar WHERE ar.member_id = f.member_id AND ar.assessment_id = f.assessment_id ORDER BY ar.id DESC LIMIT 1) AS original_score';
    }

    /** @return array{0:string,1:string,2:list<mixed>} */
    private static function filterSql(array $f): array
    {
        $where = ['1=1'];
        $types = '';
        $params = [];
        if (!empty($f['teacher_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM teacher_assignments ta WHERE ta.teacher_id = ? AND ta.class_id = f.class_id
                                  AND ta.is_active = 1 AND (ta.subject_id = f.subject_id OR ta.subject_id IS NULL))';
            $types .= 'i';
            $params[] = (int)$f['teacher_id'];
        }
        if (!empty($f['decision']) && in_array($f['decision'], self::DECISIONS, true)) {
            $where[] = 'f.decision = ?';
            $types .= 's';
            $params[] = $f['decision'];
        } elseif (!empty($f['open_only'])) {
            $where[] = "f.decision IN ('pending', 'makeup_required')";
        }
        foreach (['class_id' => 'f.class_id', 'subject_id' => 'f.subject_id', 'member_id' => 'f.member_id', 'assessment_id' => 'f.assessment_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $where[] = $col . ' = ?';
                $types .= 'i';
                $params[] = (int)$f[$k];
            }
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(mb.student_name LIKE ? OR mb.father_name LIKE ? OR mb.member_code LIKE ? OR a.assessment_name LIKE ?)';
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $types, $params];
    }

    /** @param array<string,mixed> $r */
    private static function present(array $r): array
    {
        $max = (float)($r['assessment_max_score'] ?? 0);
        $latest = $r['latest_score'] !== null ? (float)$r['latest_score'] : null;
        $latestMax = $r['latest_max_score'] !== null ? (float)$r['latest_max_score'] : null;
        return [
            'id' => (int)$r['id'],
            'member_id' => (int)$r['member_id'],
            'member_code' => (string)($r['member_code'] ?? ''),
            'member_name' => trim((string)($r['student_name'] ?? '') . ' ' . (string)($r['father_name'] ?? '')),
            'period_id' => (int)$r['period_id'],
            'inactive_from_ec' => $r['period_started_at'] ? self::ecLabel(substr((string)$r['period_started_at'], 0, 10)) : null,
            'inactive_to_ec' => $r['period_ended_at'] ? self::ecLabel(substr((string)$r['period_ended_at'], 0, 10)) : null,
            'assessment_id' => (int)$r['assessment_id'],
            'assessment_name' => (string)($r['assessment_name'] ?? ''),
            'assessment_type' => (string)($r['assessment_type'] ?? ''),
            'assessment_max_score' => $max,
            'due_date_ec' => $r['due_date'] ? self::ecLabel((string)$r['due_date']) : null,
            'academic_year_id' => (int)($r['academic_year_id'] ?? 0),
            'term_id' => $r['term_id'] !== null ? (int)$r['term_id'] : null,
            'class_id' => (int)$r['class_id'],
            'class_name' => (string)($r['class_name'] ?? ''),
            'subject_id' => (int)$r['subject_id'],
            'subject_name' => (string)($r['subject_name'] ?? ''),
            'subject_name_en' => (string)($r['subject_name_en'] ?? ''),
            'teacher_id' => $r['teacher_id'] !== null ? (int)$r['teacher_id'] : null,
            'decision' => (string)$r['decision'],
            'decision_label' => self::DECISION_LABELS[(string)$r['decision']] ?? (string)$r['decision'],
            'decided_by' => $r['decided_by'] !== null ? (int)$r['decided_by'] : null,
            'decided_by_name' => (string)($r['decided_by_name'] ?? ''),
            'decided_at_ec' => $r['decided_at'] ? self::ecDateTime((string)$r['decided_at']) : null,
            'note' => (string)($r['note'] ?? ''),
            'created_at_ec' => self::ecDateTime((string)$r['created_at']),
            'original_score' => $r['original_score'] !== null ? (float)$r['original_score'] : null,
            'attempt_count' => (int)($r['attempt_count'] ?? 0),
            'latest_score' => $latest,
            'latest_max_score' => $latestMax,
            'latest_percentage' => ($latest !== null && $latestMax > 0) ? round($latest / $latestMax * 100, 1) : null,
        ];
    }

    private static function notifyDetected(\mysqli $conn, int $memberId, array $created, string $from, string $to, ?int $actorId): void
    {
        try {
            $member = self::memberName($conn, $memberId);
            $n = count($created);
            $names = array_slice(array_map(static fn($c) => (string)$c['assessment']['assessment_name'], $created), 0, 4);
            $list = implode(', ', $names) . ($n > 4 ? ', …' : '');
            $title = "Education: {$n} missed assessment(s) need a follow-up decision · የማካካሻ ውሳኔ ያስፈልጋል";
            $msg = "{$member} was reactivated in Education. {$n} assessment(s) fell inside the inactive period ("
                 . self::ecLabel($from) . ' – ' . self::ecLabel($to) . ") with no score: {$list}. "
                 . "Decide: make-up required, exempt or not applicable. No score is recorded automatically.\n"
                 . "{$member} በትምህርት ክፍል እንደገና ንቁ ሆኗል። ኢ-ንቁ በነበረበት ወቅት {$n} ፈተና(ዎች) ተሰጥተዋል፤ ውሳኔ ይስጡ።";
            $data = ['member_id' => $memberId, 'followup_ids' => array_map(static fn($c) => (int)$c['id'], $created),
                     'assessment_ids' => array_map(static fn($c) => (int)$c['assessment']['id'], $created),
                     'window_from_ec' => self::ecLabel($from), 'window_to_ec' => self::ecLabel($to), 'event' => 'detected'];
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            $type = self::NOTIFICATION_TYPE;
            $priority = 'high';
            $sourceDept = 'edu_dept';

            $teachers = [];
            foreach ($created as $c) {
                foreach ($c['teachers'] as $t) {
                    $teachers[(int)$t] = true;
                }
            }
            $stmtUser = $conn->prepare(
                'INSERT INTO notifications (type, title, message, data, priority, source_dept, source_user_id, target_roles, target_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
            );
            if ($stmtUser) {
                foreach (array_keys($teachers) as $tid) {
                    $stmtUser->bind_param('ssssssii', $type, $title, $msg, $json, $priority, $sourceDept, $actorId, $tid);
                    $stmtUser->execute();
                }
                $stmtUser->close();
            }
            $stmtRole = $conn->prepare(
                'INSERT INTO notifications (type, title, message, data, priority, source_dept, source_user_id, target_roles, target_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)'
            );
            if ($stmtRole) {
                $roles = 'edu_dept';
                $stmtRole->bind_param('ssssssis', $type, $title, $msg, $json, $priority, $sourceDept, $actorId, $roles);
                $stmtRole->execute();
                $stmtRole->close();
            }
        } catch (\Throwable $e) {
            error_log('Assessment follow-up notification failed: ' . $e->getMessage());
        }
    }

    private static function audit(\mysqli $conn, ?SecurityAuditActor $actor, string $action, array $details, int $memberId): void
    {
        try {
            if ($actor !== null) {
                SecurityAuditService::recordTrusted($conn, $actor, $action, $details, 'member', $memberId);
            } else {
                SecurityAuditService::record($conn, $action, $details, 'member', $memberId);
            }
        } catch (\Throwable $e) {
            error_log('Assessment follow-up audit failed: ' . $e->getMessage());
        }
    }

    private static function memberName(\mysqli $conn, int $memberId): string
    {
        $stmt = $conn->prepare('SELECT student_name, father_name, member_code FROM members WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return '#' . $memberId;
        }
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return '#' . $memberId;
        }
        $name = trim((string)$row['student_name'] . ' ' . (string)$row['father_name']);
        return $name . ($row['member_code'] ? ' (' . $row['member_code'] . ')' : '');
    }

    public static function ecLabel(string $date): string
    {
        return ethio_date_format($date, 'F j, Y');
    }

    private static function ecDateTime(string $dt): string
    {
        if ($dt === '' || $dt === '0000-00-00 00:00:00') {
            return '';
        }
        $time = substr($dt, 11, 5);
        return ethio_date_format(substr($dt, 0, 10), 'F j, Y') . ($time ? ' ' . $time : '');
    }
}
