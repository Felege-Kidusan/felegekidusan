<?php
/**
 * ════════════════════════════════════════════════════════════
 * HrSubmissionService — submission packets for HR department
 * attendance. Exact workflow clone of MezmurSubmissionService
 * (which itself clones SubmissionService for teachers→education),
 * scoped by (attendance_date, section).
 *
 * Product rule (2026-08-28): HR owns this dataset exclusively —
 * its own takers (hr_attendance_taker), its own reviewers
 * (hr_dept), never combined with Education or Mezmur records.
 * Only the Information department reads it (analytics, later),
 * through the governed read path.
 *
 * Status vocabulary mirrors the edu submission packet table exactly:
 *   draft / incomplete / submitted / approved / rejected /
 *   revision_needed.
 * ════════════════════════════════════════════════════════════
 */

namespace App\Services;

final class HrSubmissionService
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVISION = 'revision_needed';
    public const STATUS_DRAFT = 'draft';

    /** HR dept + admins review/override (edu_dept analogue). */
    private const REVIEW_ROLES = ['hr_dept', 'school_admin', 'super_admin'];

    /**
     * Least privilege (audit 2026-08-28): REVIEWING a packet stays open
     * to the whole department, but OVERRIDING a locked packet (writing
     * through an approved/rejected state) is an admin power only — the
     * lock is what makes maker-checker meaningful.
     */
    private const WRITE_OVERRIDE_ROLES = ['school_admin', 'super_admin'];

    private const SECTION_MAX = 80;

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

    /** draft / incomplete / needs-revision can be changed by the taker. */
    public static function statusIsOpen(?string $status): bool
    {
        $status = self::normalizeStatus($status);
        return in_array($status, [self::STATUS_DRAFT, self::STATUS_INCOMPLETE, self::STATUS_REVISION], true)
            || $status === '';
    }

    public static function staffCanOverride(array $auth): bool
    {
        $role = (string)($auth['rol'] ?? $auth['role'] ?? '');
        return in_array($role, self::WRITE_OVERRIDE_ROLES, true);
    }

    public static function canReview(array $auth): bool
    {
        return self::staffCanOverride($auth);
    }

    public static function isLockedForTaker(?string $status, array $auth): bool
    {
        if (self::staffCanOverride($auth)) {
            return false;
        }
        return $status !== null && $status !== '' && !self::statusIsOpen($status);
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

    public static function validateSection(string $section): string
    {
        $section = trim($section);
        if ($section === '' || mb_strlen($section) > self::SECTION_MAX) {
            throw new \DomainException('A valid section is required.');
        }
        return $section;
    }

    private static function validDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        // Calendar-real dates only (rejects 2026-02-31, 9999-99-99...).
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }

    /** Immutable module trail for packet lifecycle (draft/submit/lock overrides). */
    private static function auditPacket(\mysqli $conn, string $date, string $section, int $takerId, string $status, int $count): void
    {
        try {
            $details = mb_substr("section=$section status=$status marked=$count", 0, 500);
            $stmt = $conn->prepare("INSERT INTO hr_attendance_audit (attendance_date, section, actor_id, action, details) VALUES (?,?,?,?,?)");
            if (!$stmt) {
                return;
            }
            $action = 'packet_upsert';
            $stmt->bind_param('ssiss', $date, $section, $takerId, $action, $details);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[hr-audit] ' . $e->getMessage());
        }
    }

    // ── packet reads ────────────────────────────────────────────

    public static function packetStatus(\mysqli $conn, string $date, string $section): ?string
    {
        if (!self::validDate($date) || trim($section) === '') {
            return null;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT status FROM hr_submissions
                 WHERE attendance_date = ? AND section = ?
                 ORDER BY id DESC LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('ss', $date, $section);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ? self::normalizeStatus($row['status'] ?? '') : null;
        } catch (\Throwable $e) {
            // Missing/unready packet table (migration not run yet) must
            // degrade to "no packet", never a 500.
            return null;
        }
    }

    public static function packetHasRows(\mysqli $conn, string $date, string $section): bool
    {
        if (!self::validDate($date)) {
            return false;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT 1
                 FROM hr_attendance a
                 JOIN members m ON m.id = a.member_id
                 WHERE a.attendance_date = ?
                   AND COALESCE(NULLIF(TRIM(m.current_section), ''), '—') = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $date, $section);
            $stmt->execute();
            $ok = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            return $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Packet status, or "submitted" for older sheets that already have
     * marks but never got a packet. Empty sections stay open.
     */
    public static function resolvedStatus(\mysqli $conn, string $date, string $section): ?string
    {
        $status = self::packetStatus($conn, $date, $section);
        if ($status !== null) {
            return $status;
        }
        return self::packetHasRows($conn, $date, $section) ? self::STATUS_SUBMITTED : null;
    }

    /**
     * Reviewer feedback attached to the packet, delivered only while the
     * packet is revision_needed — the taker's key back to editing.
     *
     * @return array{review_notes:string,reviewed_at:string,reviewer_name:string}|null
     */
    public static function review(\mysqli $conn, string $date, string $section): ?array
    {
        if (!self::validDate($date) || trim($section) === '') {
            return null;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT ms.review_notes, ms.reviewed_at, COALESCE(u.full_name, '') AS reviewer_name
                 FROM hr_submissions ms
                 LEFT JOIN users u ON ms.reviewed_by = u.id
                 WHERE ms.attendance_date = ? AND ms.section = ? AND ms.status = 'revision_needed'
                 ORDER BY ms.id DESC LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('ss', $date, $section);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return [
            'review_notes' => (string)($row['review_notes'] ?? ''),
            'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
            'reviewer_name' => (string)($row['reviewer_name'] ?? ''),
        ];
    }

    public static function takerMayWrite(\mysqli $conn, array $auth, string $date, string $section): bool
    {
        if (self::staffCanOverride($auth)) {
            return true;
        }
        $status = self::resolvedStatus($conn, $date, $section);
        return $status === null || self::statusIsOpen($status);
    }

    // ── packet writes ───────────────────────────────────────────

    /**
     * Create or update one (date, section) packet.
     *
     * @param array{taker_id:int,date:string,section:string,status:string,member_count?:int,present?:int,late?:int,absent?:int,excused?:int,client_op_id?:?string} $opts
     * @return array{ok:bool,id:int,status:string,message:string}
     */
    public static function upsert(\mysqli $conn, array $opts): array
    {
        $takerId = (int)($opts['taker_id'] ?? 0);
        $date = trim((string)($opts['date'] ?? ''));
        $section = trim((string)($opts['section'] ?? ''));
        $status = self::normalizeStatus($opts['status'] ?? self::STATUS_INCOMPLETE);
        if ($status === self::STATUS_DRAFT) {
            $status = self::STATUS_INCOMPLETE;
        }
        $count = max(0, min(1000000, (int)($opts['member_count'] ?? 0)));
        $present = max(0, min(1000000, (int)($opts['present'] ?? 0)));
        $late = max(0, min(1000000, (int)($opts['late'] ?? 0)));
        $absent = max(0, min(1000000, (int)($opts['absent'] ?? 0)));
        $excused = max(0, min(1000000, (int)($opts['excused'] ?? 0)));
        $opId = isset($opts['client_op_id']) ? mb_substr((string)$opts['client_op_id'], 0, 64) : null;
        if ($opId === '') {
            $opId = null;
        }

        if ($takerId <= 0 || !self::validDate($date) || $section === '' || mb_strlen($section) > self::SECTION_MAX) {
            return ['ok' => false, 'id' => 0, 'status' => $status, 'message' => 'Section, taker, and date are required.'];
        }

        $existingId = 0;
        try {
            $stmt = $conn->prepare(
                "SELECT id FROM hr_submissions
                 WHERE attendance_date = ? AND section = ?
                 ORDER BY id DESC LIMIT 1"
            );
        } catch (\Throwable $e) {
            $stmt = false;
        }
        if ($stmt) {
            $stmt->bind_param('ss', $date, $section);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $existingId = (int)($row['id'] ?? 0);
        }

        $submittedAt = $status === self::STATUS_SUBMITTED ? date('Y-m-d H:i:s') : null;

        if ($existingId > 0) {
            $cur = $conn->prepare("SELECT status FROM hr_submissions WHERE id = ? LIMIT 1");
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
                    'message' => 'This attendance is already submitted. Only administrators can change it.',
                ];
            }
            try {
                $up = $conn->prepare(
                    "UPDATE hr_submissions
                     SET status = ?, taker_id = ?, member_count = ?, present_count = ?, late_count = ?,
                         absent_count = ?, excused_count = ?, client_op_id = COALESCE(?, client_op_id),
                         submitted_at = COALESCE(?, submitted_at)
                     WHERE id = ?"
                );
            } catch (\Throwable $e) {
                $up = false;
            }
            if (!$up) {
                return ['ok' => false, 'id' => $existingId, 'status' => $status, 'message' => 'Could not update the attendance packet.'];
            }
            $up->bind_param('siiiiiissi', $status, $takerId, $count, $present, $late, $absent, $excused, $opId, $submittedAt, $existingId);
            $ok = $up->execute();
            $up->close();
            if ($ok) {
                self::auditPacket($conn, $date, $section, $takerId, $status, $count);
            }
            return [
                'ok' => (bool)$ok,
                'id' => $existingId,
                'status' => $status,
                'message' => $status === self::STATUS_SUBMITTED
                    ? 'Attendance submitted to the HR department.'
                    : 'Saved as a draft for the HR department.',
            ];
        }

        try {
            $ins = $conn->prepare(
                "INSERT INTO hr_submissions
                    (attendance_date, section, taker_id, status, member_count,
                     present_count, late_count, absent_count, excused_count,
                     submitted_at, client_op_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
        } catch (\Throwable $e) {
            $ins = false;
        }
        if (!$ins) {
            return ['ok' => false, 'id' => 0, 'status' => $status,
                'message' => 'The submission tables are not ready on this server. Ask the administrator to run sql/024_hr_submissions.sql.'];
        }
        $ins->bind_param(
            'ssisiiiiiss',
            $date,
            $section,
            $takerId,
            $status,
            $count,
            $present,
            $late,
            $absent,
            $excused,
            $submittedAt,
            $opId
        );
        $ok = $ins->execute();
        $id = (int)$ins->insert_id;
        $ins->close();
        if ($ok) {
            self::auditPacket($conn, $date, $section, $takerId, $status, $count);
        }
        return [
            'ok' => (bool)$ok,
            'id' => $id,
            'status' => $status,
            'message' => $status === self::STATUS_SUBMITTED
                ? 'Attendance submitted to the HR department.'
                : 'Saved as a draft for the HR department.',
        ];
    }

    // ── review (HR dept + admins only; callers gate roles) ──

    /**
     * Approve / reject / return-with-note. Returns require a reason of
     * at least 3 characters — otherwise the unlock is meaningless.
     *
     * @return array{ok:bool,message:string}
     */
    public static function reviewPacket(\mysqli $conn, int $packetId, string $newStatus, string $notes, int $actorId): array
    {
        if ($packetId <= 0 || !in_array($newStatus, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_REVISION], true)) {
            return ['ok' => false, 'message' => 'Invalid review parameters.'];
        }
        $notes = trim($notes);
        if ($newStatus !== self::STATUS_APPROVED && mb_strlen($notes) < 3) {
            return ['ok' => false, 'message' => 'Write a short reason so the taker knows what to fix.'];
        }
        if (mb_strlen($notes) > 500) {
            $notes = mb_substr($notes, 0, 500);
        }

        try {
            $stmt = $conn->prepare(
                "SELECT attendance_date, section, taker_id, status
                 FROM hr_submissions WHERE id = ? LIMIT 1"
            );
        } catch (\Throwable $e) {
            $stmt = false;
        }
        if (!$stmt) {
            return ['ok' => false, 'message' => 'The submission tables are not ready on this server. Ask the administrator to run sql/024_hr_submissions.sql.'];
        }
        $stmt->bind_param('i', $packetId);
        $stmt->execute();
        $packet = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$packet) {
            return ['ok' => false, 'message' => 'Submission not found.'];
        }
        $previousStatus = (string)($packet['status'] ?? '');

        try {
            $up = $conn->prepare(
                "UPDATE hr_submissions
                 SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
                 WHERE id = ?"
            );
        } catch (\Throwable $e) {
            $up = false;
        }
        if (!$up) {
            return ['ok' => false, 'message' => 'Could not update the packet.'];
        }
        $up->bind_param('sisi', $newStatus, $actorId, $notes, $packetId);
        $ok = $up->execute();
        $up->close();
        if (!$ok) {
            return ['ok' => false, 'message' => 'Could not update the packet.'];
        }

        // Immutable trail: who decided, from which state, and why.
        \App\Services\SecurityAuditService::record(
            $conn,
            'HR Submission Reviewed',
            [
                'new_status' => $newStatus,
                'previous_status' => $previousStatus,
                'reason' => $notes,
                'section' => (string)($packet['section'] ?? ''),
                'attendance_date' => (string)($packet['attendance_date'] ?? ''),
                'taker_id' => (int)($packet['taker_id'] ?? 0),
            ],
            'hr_submission',
            $packetId
        );

        $friendly = [
            self::STATUS_APPROVED => 'Approved.',
            self::STATUS_REJECTED => 'Rejected.',
            self::STATUS_REVISION => 'Returned to the taker for correction.',
        ];
        return ['ok' => true, 'message' => $friendly[$newStatus]];
    }

    // ── inbox / detail ──────────────────────────────────────────

    /**
     * Paginated inbox. Scale-safe: clamped page sizes, total count for
     * pagination UI, newest activity first.
     *
     * @param array{status?:string,from?:string,to?:string,section?:string,page?:int|string,per_page?:int|string} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,total_pages:int,per_page:int}
     */
    public static function listPackets(\mysqli $conn, array $filters): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = (int)($filters['per_page'] ?? 50);
        if ($perPage < 1) $perPage = 50;
        if ($perPage > 100) $perPage = 100;

        $where = ['1=1'];
        $params = [];
        $types = '';

        $status = trim((string)($filters['status'] ?? ''));
        if ($status === 'attention') {
            $where[] = "ms.status IN ('incomplete','draft','submitted','revision_needed')";
        } elseif ($status !== '' && $status !== 'all') {
            $status = self::normalizeStatus($status);
            $where[] = 'ms.status = ?';
            $params[] = $status;
            $types .= 's';
        }
        if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['from'])) {
            $where[] = 'ms.attendance_date >= ?';
            $params[] = (string)$filters['from'];
            $types .= 's';
        }
        if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['to'])) {
            $where[] = 'ms.attendance_date <= ?';
            $params[] = (string)$filters['to'];
            $types .= 's';
        }
        $section = trim((string)($filters['section'] ?? ''));
        if ($section !== '') {
            $where[] = 'ms.section = ?';
            $params[] = mb_substr($section, 0, self::SECTION_MAX);
            $types .= 's';
        }

        $whereSql = implode(' AND ', $where);
        $empty = ['items' => [], 'total' => 0, 'page' => $page, 'total_pages' => 1, 'per_page' => $perPage];

        try {
            $cstmt = $conn->prepare("SELECT COUNT(*) c FROM hr_submissions ms WHERE $whereSql");
            if (!$cstmt) {
                return $empty;
            }
            if ($params) {
                $cstmt->bind_param($types, ...$params);
            }
            $cstmt->execute();
            $total = (int)$cstmt->get_result()->fetch_assoc()['c'];
            $cstmt->close();
        } catch (\Throwable $e) {
            return $empty;
        }
        if ($total === 0) {
            return $empty;
        }

        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT ms.id, ms.attendance_date, ms.section, ms.taker_id, ms.status,
                       ms.member_count, ms.present_count, ms.late_count, ms.absent_count,
                       ms.excused_count, ms.submitted_at, ms.reviewed_by, ms.reviewed_at,
                       ms.review_notes, ms.created_at, ms.updated_at,
                       u.full_name AS taker_name,
                       rv.full_name AS reviewer_name
                FROM hr_submissions ms
                LEFT JOIN users u ON ms.taker_id = u.id
                LEFT JOIN users rv ON ms.reviewed_by = rv.id
                WHERE $whereSql
                ORDER BY ms.updated_at DESC, ms.id DESC
                LIMIT ? OFFSET ?";

        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return $empty;
            }
            $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
            $stmt->execute();
            $rows = [];
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $rows[] = self::presentRow($row);
            }
            $stmt->close();
            return ['items' => $rows, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages, 'per_page' => $perPage];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /** @return array<string,mixed>|null */
    public static function detail(\mysqli $conn, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT ms.*, u.full_name AS taker_name, rv.full_name AS reviewer_name
                 FROM hr_submissions ms
                 LEFT JOIN users u ON ms.taker_id = u.id
                 LEFT JOIN users rv ON ms.reviewed_by = rv.id
                 WHERE ms.id = ? LIMIT 1"
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
        } catch (\Throwable $e) {
            return null;
        }
        $packet = self::presentRow($raw);
        $packet['rows'] = self::rows($conn, (string)$packet['attendance_date'], (string)$packet['section']);
        return $packet;
    }

    /** Attendance rows for one (date, section) — name, code, status, note. */
    public static function rows(\mysqli $conn, string $date, string $section): array
    {
        if (!self::validDate($date) || trim($section) === '') {
            return [];
        }
        try {
            $stmt = $conn->prepare(
                "SELECT a.member_id, a.status, a.notes, m.student_name, m.father_name, m.member_code
                 FROM hr_attendance a
                 JOIN members m ON m.id = a.member_id
                 WHERE a.attendance_date = ?
                   AND COALESCE(NULLIF(TRIM(m.current_section), ''), '—') = ?
                 ORDER BY m.student_name, m.father_name
                 LIMIT 100000"
            );
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('ss', $date, $section);
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
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function countsFromRecords(array $records): array
    {
        $out = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'member_count' => 0];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $st = strtolower(trim((string)($rec['status'] ?? '')));
            if (isset($out[$st])) {
                $out[$st]++;
                $out['member_count']++;
            }
        }
        return $out;
    }

    /**
     * Inbox insight strip (edu Submissions parity): counts per state
     * plus today's marks. Two bounded queries on indexed columns —
     * safe at scale, computed on demand (no stale caches).
     */
    public static function packetStats(\mysqli $conn): array
    {
        $stats = [
            'drafts' => 0, 'submitted' => 0, 'approved' => 0, 'returned' => 0, 'rejected' => 0,
            'today_packets' => 0, 'today_present' => 0, 'today_absent' => 0, 'today_late' => 0,
        ];
        try {
            $res = $conn->query(
                "SELECT status, COUNT(*) c FROM hr_submissions GROUP BY status"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $st = self::normalizeStatus($row['status']);
                    if ($st === self::STATUS_INCOMPLETE) $st = self::STATUS_DRAFT;
                    if ($st === self::STATUS_DRAFT) $stats['drafts'] += (int)$row['c'];
                    elseif ($st === self::STATUS_SUBMITTED) $stats['submitted'] = (int)$row['c'];
                    elseif ($st === self::STATUS_APPROVED) $stats['approved'] = (int)$row['c'];
                    elseif ($st === self::STATUS_REVISION) $stats['returned'] = (int)$row['c'];
                    elseif ($st === self::STATUS_REJECTED) $stats['rejected'] = (int)$row['c'];
                }
            }
            $stmt = $conn->prepare(
                "SELECT COUNT(*) packets,
                        COALESCE(SUM(present_count),0) p,
                        COALESCE(SUM(absent_count),0) a,
                        COALESCE(SUM(late_count),0) l
                 FROM hr_submissions WHERE attendance_date = CURDATE()"
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $stats['today_packets'] = (int)$row['packets'];
                $stats['today_present'] = (int)$row['p'];
                $stats['today_absent'] = (int)$row['a'];
                $stats['today_late'] = (int)$row['l'];
            }
        } catch (\Throwable $e) {
            // Stats are decorative — a failure must never break the inbox.
        }
        return $stats;
    }

    private static function presentRow(array $row): array
    {
        $status = self::normalizeStatus($row['status'] ?? '');
        $uiStatus = ($status === self::STATUS_INCOMPLETE) ? self::STATUS_DRAFT : $status;
        return [
            'id' => (int)$row['id'],
            'attendance_date' => $row['attendance_date'] ?? null,
            'section' => $row['section'] ?? '',
            'taker_id' => isset($row['taker_id']) ? (int)$row['taker_id'] : null,
            'status' => $uiStatus,
            'status_label' => self::statusLabel($uiStatus),
            'member_count' => (int)($row['member_count'] ?? 0),
            'present_count' => (int)($row['present_count'] ?? 0),
            'late_count' => (int)($row['late_count'] ?? 0),
            'absent_count' => (int)($row['absent_count'] ?? 0),
            'excused_count' => (int)($row['excused_count'] ?? 0),
            'submitted_at' => $row['submitted_at'] ?? null,
            'reviewed_by' => isset($row['reviewed_by']) ? (int)$row['reviewed_by'] : null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'review_notes' => $row['review_notes'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'taker_name' => $row['taker_name'] ?? '',
            'reviewer_name' => $row['reviewer_name'] ?? '',
        ];
    }
}
