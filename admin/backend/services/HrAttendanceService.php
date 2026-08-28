<?php
/**
 * ════════════════════════════════════════════════════════════
 * HrAttendanceService — HR department section-based attendance.
 *
 * Mechanics clone of MezmurAttendanceService (date + section
 * sheets), on HR's OWN tables. Product rule (2026-08-28): HR
 * owns its takers (hr_attendance_taker) and its data; nothing
 * here is ever combined with Education or Mezmur records.
 *
 * Only two role families touch this service:
 *   • hr_attendance_taker (+ hr_dept/admins) — record sheets
 *   • hr_dept (+ admins)                    — review packets
 * The Information department reads through the governed analytics
 * path (Phase C) — never through write methods.
 * ════════════════════════════════════════════════════════════
 */

namespace App\Services;

final class HrAttendanceService
{
    public const STATUSES = ['present', 'late', 'absent', 'excused'];

    private const SECTION_MAX = 80;

    private static function validDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }

    // ── roster & sections ──────────────────────────────────────

    /** Sections with active-member counts (for pickers/filters). */
    public static function sectionListWithCounts(\mysqli $conn): array
    {
        $out = [];
        $res = $conn->query(
            "SELECT COALESCE(NULLIF(TRIM(current_section), ''), '—') AS section, COUNT(*) AS members
             FROM members WHERE status = 'active'
             GROUP BY section ORDER BY section LIMIT 200"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $out[] = ['section' => $r['section'], 'members' => (int)$r['members']];
            }
        }
        return $out;
    }

    /** @return list<array{id:int,member_code:?string,student_name:string,father_name:?string}> */
    public static function sectionRoster(\mysqli $conn, string $section): array
    {
        $stmt = $conn->prepare(
            "SELECT id, member_code, student_name, father_name
             FROM members
             WHERE status = 'active'
               AND COALESCE(NULLIF(TRIM(current_section), ''), '—') = ?
             ORDER BY student_name, father_name
             LIMIT 2000"
        );
        $stmt->bind_param('s', $section);
        $stmt->execute();
        $out = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $out[] = $row;
        }
        $stmt->close();
        return $out;
    }

    // ── sheet read (roster + marks + packet state) ────────────

    public static function fetchSectionSheet(\mysqli $conn, string $date, string $section, array $auth): array
    {
        if (!self::validDate($date)) {
            throw new \DomainException('Invalid attendance date.');
        }
        $section = trim($section);
        if ($section === '' || mb_strlen($section) > self::SECTION_MAX) {
            throw new \DomainException('A valid section is required.');
        }

        $marks = [];
        try {
            $stmt = $conn->prepare(
                "SELECT member_id, status, notes FROM hr_attendance
                 WHERE attendance_date = ? AND section = ?"
            );
            if ($stmt) {
                $stmt->bind_param('ss', $date, $section);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $marks[(int)$row['member_id']] = [
                        'status' => $row['status'],
                        'notes' => (string)($row['notes'] ?? ''),
                    ];
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $marks = [];
        }

        $members = [];
        foreach (self::sectionRoster($conn, $section) as $m) {
            $mark = $marks[$m['id']] ?? null;
            $m['mark'] = $mark['status'] ?? null;
            $m['notes'] = $mark['notes'] ?? '';
            $members[] = $m;
        }

        $packetStatus = HrSubmissionService::resolvedStatus($conn, $date, $section);
        $locked = HrSubmissionService::isLockedForTaker($packetStatus, $auth);
        $review = null;
        if ($packetStatus === HrSubmissionService::STATUS_REVISION) {
            $review = HrSubmissionService::review($conn, $date, $section);
        }

        return [
            'date' => $date,
            'section' => $section,
            'members' => $members,
            'count' => count($members),
            'submission_status' => $packetStatus,
            'locked' => $locked,
            'review_notes' => $review['review_notes'] ?? null,
            'reviewed_at' => $review['reviewed_at'] ?? null,
            'reviewer_name' => $review['reviewer_name'] ?? null,
        ];
    }

    // ── sheet write (transactional replace) ───────────────────

    /**
     * Replace the section's marks for the date. Submitted member set
     * must exactly equal the live section roster (prevents stale
     * sheets overwriting roster changes).
     *
     * @param list<array{member_id:int|string,status:string,notes?:string}> $records
     * @return array{marked:int,present:int,late:int,absent:int,excused:int}
     */
    public static function saveSectionSheet(\mysqli $conn, string $date, string $section, array $records, int $userId, bool $ownTransaction = true): array
    {
        if (!self::validDate($date)) {
            throw new \DomainException('Invalid attendance date.');
        }
        if ($date > date('Y-m-d')) {
            throw new \DomainException('Attendance cannot be recorded for a future date.');
        }
        $section = trim($section);
        if ($section === '' || mb_strlen($section) > self::SECTION_MAX) {
            throw new \DomainException('A valid section is required.');
        }

        $roster = array_map(static fn($m) => (int)$m['id'], self::sectionRoster($conn, $section));
        $submitted = [];
        $notesByMember = [];
        foreach ($records as $rec) {
            $mid = (int)($rec['member_id'] ?? 0);
            $status = (string)($rec['status'] ?? '');
            if ($mid <= 0 || !in_array($status, self::STATUSES, true)) {
                throw new \DomainException('Sheet contains an invalid record.');
            }
            if (isset($submitted[$mid])) {
                throw new \DomainException('Duplicate member in sheet.');
            }
            $submitted[$mid] = $status;
            $note = trim((string)($rec['notes'] ?? $rec['note'] ?? ''));
            if ($note !== '') {
                $notesByMember[$mid] = mb_substr($note, 0, 250);
            }
        }
        if (count($submitted) !== count($roster)
            || array_diff($roster, array_keys($submitted)) !== []
            || array_diff(array_keys($submitted), $roster) !== []) {
            throw new \DomainException('The sheet is out of date with the current roster. Reload and try again.');
        }

        $present = $late = $absent = $excused = 0;
        foreach ($submitted as $status) {
            if ($status === 'present') $present++;
            elseif ($status === 'late') $late++;
            elseif ($status === 'excused') $excused++;
            else $absent++;
        }

        if ($ownTransaction) {
            $conn->begin_transaction();
        }
        try {
            // Section snapshot on the row → delete exactly this section.
            $del = $conn->prepare("DELETE FROM hr_attendance WHERE attendance_date = ? AND section = ?");
            $del->bind_param('ss', $date, $section);
            $del->execute();
            $del->close();

            $ins = $conn->prepare(
                "INSERT INTO hr_attendance (attendance_date, member_id, section, status, notes, marked_by)
                 VALUES (?,?,?,?,?,?)"
            );
            foreach ($submitted as $mid => $status) {
                $note = $notesByMember[$mid] ?? null;
                $ins->bind_param('sisssi', $date, $mid, $section, $status, $note, $userId);
                $ins->execute();
            }
            $ins->close();

            if ($ownTransaction) {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $conn->rollback();
            }
            throw $e;
        }

        self::audit($conn, $date, $section, $userId, 'section_sheet_saved',
            'marked=' . count($submitted) . " present=$present late=$late absent=$absent excused=$excused");

        return ['marked' => count($submitted), 'present' => $present, 'late' => $late, 'absent' => $absent, 'excused' => $excused];
    }

    // ── history (dates derived from marks) ────────────────────

    public static function listDays(\mysqli $conn, string $from, string $to, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $wFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-d', strtotime('-90 days'));
        $wTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d');
        if ($wFrom > $wTo) {
            [$wFrom, $wTo] = [$wTo, $wFrom];
        }

        $stmt = $conn->prepare("SELECT COUNT(DISTINCT attendance_date) c FROM hr_attendance WHERE attendance_date BETWEEN ? AND ?");
        $stmt->bind_param('ss', $wFrom, $wTo);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $items = [];
        if ($total > 0) {
            $stmt = $conn->prepare(
                "SELECT attendance_date,
                        COUNT(*) AS marked,
                        SUM(status IN ('present','late')) AS attended
                 FROM hr_attendance
                 WHERE attendance_date BETWEEN ? AND ?
                 GROUP BY attendance_date
                 ORDER BY attendance_date DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('ssii', $wFrom, $wTo, $perPage, $offset);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $items[] = [
                    'attendance_date' => $row['attendance_date'],
                    'marked' => (int)$row['marked'],
                    'attended' => (int)$row['attended'],
                ];
            }
            $stmt->close();
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages];
    }

    // ── HR's own takers (department-owned) ────────────────────

    public static function takersList(\mysqli $conn): array
    {
        $out = [];
        $res = $conn->query(
            "SELECT id, username, full_name, is_active, created_at
             FROM users
             WHERE role = 'hr_attendance_taker'
             ORDER BY full_name
             LIMIT 500"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['id'] = (int)$r['id'];
                $r['is_active'] = (int)$r['is_active'];
                $out[] = $r;
            }
        }
        return $out;
    }

    private static function audit(\mysqli $conn, string $date, string $section, int $actorId, string $action, string $details): void
    {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO hr_attendance_audit (attendance_date, section, actor_id, action, details)
                 VALUES (?,?,?,?,?)"
            );
            if (!$stmt) {
                return;
            }
            $details = mb_substr($details, 0, 500);
            $stmt->bind_param('ssiss', $date, $section, $actorId, $action, $details);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[hr-audit] ' . $e->getMessage());
        }
    }
}
