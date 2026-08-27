<?php
/**
 * ════════════════════════════════════════════════════════════
 * MezmurAttendanceService — single writer for Mezmur attendance
 * (መዝሙር ክፍል). DATE-based, section-grouped (product decision:
 * NOT session-driven — the department reasons per date over the
 * whole roster, grouped by section).
 * ════════════════════════════════════════════════════════════
 * Domain rules (same contract as class attendance, separate
 * dataset):
 *
 *   - One attendance sheet per DATE; roster = ALL active members
 *     grouped by section.
 *   - A save is COMPLETE and EXPLICIT: submitted member set must
 *     exactly equal the live roster (DomainException otherwise).
 *   - Saves are transactional replaces; UNIQUE
 *     (attendance_date, member_id) makes resubmits idempotent.
 *   - Every mutation writes an audit row — these records drive
 *     member selection for የዝማሬ/service programs.
 *   - Analytics aggregate SERVER-side, date-bounded (2-year hard
 *     cap), sort columns whitelisted; raw marks never leave here.
 *
 * All methods take an open \mysqli and use prepared statements.
 */

namespace App\Services;

final class MezmurAttendanceService
{
    public const PROGRAM_TYPES = ['rehearsal', 'service', 'feast', 'training', 'other'];
    public const STATUSES = ['present', 'late', 'absent'];

    private const SORTABLE = [
        'name'          => 'm.student_name',
        'section'       => 'm.current_section',
        'attended'      => 'attended',
        'present'       => 'present',
        'late'          => 'late',
        'absent'        => 'absent',
        'rate'          => 'rate',
        'last_attended' => 'last_attended',
    ];

    // ── helpers ─────────────────────────────────────────────────

    private static function clampPerPage(int $perPage): int
    {
        return $perPage < 1 ? 25 : min($perPage, 100);
    }

    /** @return array{from:string,to:string} validated, hard-bounded window */
    private static function window(?string $from, ?string $to): array
    {
        $today = date('Y-m-d');
        $min = date('Y-m-d', strtotime('-2 years'));
        $validate = static function (?string $d, string $fallback): string {
            return ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : $fallback;
        };
        $f = $validate($from, date('Y-m-d', strtotime('-90 days')));
        $t = $validate($to, $today);
        if ($f < $min) $f = $min;
        if ($t > $today) $t = $today;
        if ($f > $t) [$f, $t] = [$t, $f];
        return ['from' => $f, 'to' => $t];
    }

    private static function audit(\mysqli $conn, ?string $date, int $actorId, string $action, string $details): void
    {
        try {
            $sessionId = null; // audit.session_id is legacy-nullable; date goes in details
            $details = mb_substr($details, 0, 500);
            $stmt = $conn->prepare("INSERT INTO mezmur_attendance_audit (session_id, actor_id, action, details) VALUES (?,?,?,?)");
            $stmt->bind_param('iiss', $sessionId, $actorId, $action, $details);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[mezmur-audit] ' . $e->getMessage());
        }
    }

    // ── roster ──────────────────────────────────────────────────

    /** All active members grouped by section. */
    public static function rosterGroupedBySection(\mysqli $conn): array
    {
        $res = $conn->query(
            "SELECT id, member_code, student_name, father_name, full_name_am, photo_url,
                    COALESCE(NULLIF(TRIM(current_section), ''), '—') AS section
             FROM members
             WHERE status = 'active'
             ORDER BY section, student_name, father_name"
        );
        $groups = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $section = (string)$row['section'];
                unset($row['section']);
                $row['id'] = (int)$row['id'];
                $groups[$section][] = $row;
            }
        }
        return $groups;
    }

    private static function rosterIds(\mysqli $conn): array
    {
        $ids = [];
        $res = $conn->query("SELECT id FROM members WHERE status = 'active'");
        if ($res) {
            while ($row = $res->fetch_assoc()) $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    // ── days ────────────────────────────────────────────────────

    public static function listDays(\mysqli $conn, string $from, string $to, int $page, int $perPage): array
    {
        $perPage = self::clampPerPage($perPage);
        $w = self::window($from, $to);
        $page = max(1, $page);

        $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_days WHERE attendance_date BETWEEN ? AND ?");
        $stmt->bind_param('ss', $w['from'], $w['to']);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $conn->prepare(
            "SELECT d.id, d.attendance_date, d.program_type, d.title, d.notes, d.created_at,
                    (SELECT COUNT(*) FROM mezmur_attendance a WHERE a.attendance_date = d.attendance_date) AS marked,
                    (SELECT COUNT(*) FROM mezmur_attendance a
                      WHERE a.attendance_date = d.attendance_date AND a.status IN ('present','late')) AS attended
             FROM mezmur_days d
             WHERE d.attendance_date BETWEEN ? AND ?
             ORDER BY d.attendance_date DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ssii', $w['from'], $w['to'], $perPage, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) {
            $r['id'] = (int)$r['id'];
            $r['marked'] = (int)$r['marked'];
            $r['attended'] = (int)$r['attended'];
            $items[] = $r;
        }
        $stmt->close();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages];
    }

    /** Get-or-create the day record for a date. */
    public static function ensureDay(\mysqli $conn, string $date, string $programType, ?string $title, ?string $notes, int $userId): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \DomainException('Invalid attendance date.');
        }
        $stmt = $conn->prepare("SELECT id, attendance_date, program_type, title, notes FROM mezmur_days WHERE attendance_date = ?");
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $day = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($day) {
            $day['id'] = (int)$day['id'];
            return $day;
        }
        if (!in_array($programType, self::PROGRAM_TYPES, true)) $programType = 'rehearsal';
        $title = $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 255) : null;
        $notes = $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 500) : null;

        $stmt = $conn->prepare("INSERT INTO mezmur_days (attendance_date, program_type, title, notes, created_by) VALUES (?,?,?,?,?)");
        $stmt->bind_param('ssssi', $date, $programType, $title, $notes, $userId);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        self::audit($conn, $date, $userId, 'day_created', "$programType on $date");
        return ['id' => $id, 'attendance_date' => $date, 'program_type' => $programType, 'title' => $title, 'notes' => $notes];
    }

    // ── sheet ───────────────────────────────────────────────────

    /** Sheet for one date: day meta + section-grouped roster with marks. */
    public static function fetchSheet(\mysqli $conn, string $date, int $userId): array
    {
        $day = self::ensureDay($conn, $date, 'rehearsal', null, null, $userId);

        $marks = [];
        $stmt = $conn->prepare("SELECT member_id, status FROM mezmur_attendance WHERE attendance_date = ?");
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $marks[(int)$r['member_id']] = $r['status'];
        $stmt->close();

        $sections = [];
        foreach (self::rosterGroupedBySection($conn) as $section => $members) {
            foreach ($members as &$m) {
                $m['mark'] = $marks[$m['id']] ?? null;
            }
            unset($m);
            $sections[$section] = $members;
        }
        return ['day' => $day, 'sections' => $sections];
    }

    /**
     * Complete-sheet save (transactional replace by date).
     * @param list<array{member_id:int|string,status:string}> $records
     * @return array{marked:int,present:int,late:int,absent:int}
     */
    public static function saveSheet(\mysqli $conn, string $date, array $records, int $userId): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \DomainException('Invalid attendance date.');
        }
        if ($date > date('Y-m-d')) {
            throw new \DomainException('Attendance cannot be recorded for a future date.');
        }

        $roster = self::rosterIds($conn);
        $submitted = [];
        foreach ($records as $rec) {
            $mid = (int)($rec['member_id'] ?? 0);
            $status = (string)($rec['status'] ?? '');
            if ($mid <= 0 || !in_array($status, self::STATUSES, true)) {
                throw new \DomainException('Sheet contains an invalid record.');
            }
            if (isset($submitted[$mid])) throw new \DomainException('Duplicate member in sheet.');
            $submitted[$mid] = $status;
        }
        if (count($submitted) !== count($roster) || array_diff($roster, array_keys($submitted)) !== []) {
            throw new \DomainException('The sheet is out of date with the current roster. Reload and try again.');
        }

        $present = $late = $absent = 0;
        foreach ($submitted as $status) {
            if ($status === 'present') $present++;
            elseif ($status === 'late') $late++;
            else $absent++;
        }

        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM mezmur_attendance WHERE attendance_date = ?");
            $del->bind_param('s', $date);
            $del->execute();
            $del->close();

            $sql = "INSERT INTO mezmur_attendance (session_id, attendance_date, member_id, status, marked_by) VALUES (NULL,?,?,?,?)";
            $ins = $conn->prepare($sql);
            $batch = 0;
            foreach ($submitted as $memberId => $status) {
                $ins->bind_param('sisi', $date, $memberId, $status, $userId);
                $ins->execute();
                // Recycle the statement periodically on very large rosters.
                if (++$batch % 500 === 0) {
                    $ins->close();
                    $ins = $conn->prepare($sql);
                }
            }
            $ins->close();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        self::ensureDay($conn, $date, 'rehearsal', null, null, $userId);
        self::audit($conn, $date, $userId, 'sheet_saved', "marked=" . count($submitted) . " present=$present late=$late absent=$absent");
        return ['marked' => count($submitted), 'present' => $present, 'late' => $late, 'absent' => $absent];
    }

    // ── analytics (server-side aggregation) ─────────────────────

    private static function daysHeld(\mysqli $conn, array $w, string $programType): int
    {
        if ($programType !== '') {
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_days WHERE attendance_date BETWEEN ? AND ? AND program_type = ?");
            $stmt->bind_param('sss', $w['from'], $w['to'], $programType);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM mezmur_days WHERE attendance_date BETWEEN ? AND ?");
            $stmt->bind_param('ss', $w['from'], $w['to']);
        }
        $stmt->execute();
        $held = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        return $held;
    }

    private static function programJoinFilter(string $programType): array
    {
        if ($programType === '') return ['', ''];
        return [
            " JOIN mezmur_days d ON d.attendance_date = a.attendance_date AND d.program_type = ? ",
            's',
        ];
    }

    /**
     * Per-member aggregates — every count paired with percentages.
     * Filters: section, program_type, search, min_rate, min_attended.
     */
    public static function analyticsMembers(\mysqli $conn, array $filters): array
    {
        $w = self::window($filters['from'] ?? null, $filters['to'] ?? null);
        $programType = in_array($filters['program_type'] ?? '', self::PROGRAM_TYPES, true) ? $filters['program_type'] : '';
        $section = trim((string)($filters['section'] ?? ''));
        $search  = trim((string)($filters['search'] ?? ''));
        $minRate = isset($filters['min_rate']) && $filters['min_rate'] !== '' ? max(0, min(100, (float)$filters['min_rate'])) : null;
        $minAttended = isset($filters['min_attended']) && $filters['min_attended'] !== '' ? max(0, (int)$filters['min_attended']) : null;
        $sortKey = (string)($filters['sort'] ?? 'rate');
        $sortCol = self::SORTABLE[$sortKey] ?? 'rate';
        $dir = strtolower((string)($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = self::clampPerPage((int)($filters['per_page'] ?? 25));

        $held = self::daysHeld($conn, $w, $programType);

        $where = ["m.status = 'active'"];
        $types = '';
        $params = [];
        if ($section !== '') {
            $where[] = "COALESCE(NULLIF(TRIM(m.current_section), ''), '—') = ?";
            $types .= 's'; $params[] = $section;
        }
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)) . '%';
            $where[] = "(m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ?)";
            $types .= 'sss'; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);

        [$pJoin, $pType] = self::programJoinFilter($programType);

        $sql = "
            SELECT m.id, m.member_code, m.student_name, m.father_name, m.full_name_am, m.photo_url,
                   COALESCE(NULLIF(TRIM(m.current_section), ''), '—') AS section,
                   $held AS sessions_held,
                   COALESCE(agg.present, 0) AS present,
                   COALESCE(agg.late, 0) AS late,
                   COALESCE(agg.absent, 0) AS absent,
                   COALESCE(agg.present, 0) + COALESCE(agg.late, 0) AS attended,
                   CASE WHEN $held > 0
                        THEN ROUND((COALESCE(agg.present,0) + COALESCE(agg.late,0)) * 100.0 / $held, 1)
                        ELSE NULL END AS rate,
                   agg.last_attended
            FROM members m
            LEFT JOIN (
                SELECT a.member_id,
                       SUM(a.status = 'present') AS present,
                       SUM(a.status = 'late')    AS late,
                       SUM(a.status = 'absent')  AS absent,
                       MAX(CASE WHEN a.status IN ('present','late') THEN a.attendance_date END) AS last_attended
                FROM mezmur_attendance a
                $pJoin
                WHERE a.attendance_date BETWEEN ? AND ?
                GROUP BY a.member_id
            ) agg ON agg.member_id = m.id
            WHERE $whereSql";

        $subTypes = $pType . 'ss';
        $subParams = array_merge($programType !== '' ? [$programType] : [], [$w['from'], $w['to']]);

        $having = [];
        if ($minRate !== null) $having[] = "(rate IS NOT NULL AND rate >= $minRate)";
        if ($minAttended !== null) $having[] = "(attended >= $minAttended)";
        if ($having) $sql .= ' HAVING ' . implode(' AND ', $having);
        $sql .= " ORDER BY $sortCol $dir, m.student_name ASC LIMIT ? OFFSET ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($subTypes . $types . 'ii', ...array_merge($subParams, $params, [$perPage, ($page - 1) * $perPage]));
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) {
            foreach (['id', 'sessions_held', 'present', 'late', 'absent', 'attended'] as $k) $r[$k] = (int)$r[$k];
            $r['rate'] = $r['rate'] !== null ? (float)$r['rate'] : null;
            $r['absent_rate'] = ($r['sessions_held'] > 0) ? round($r['absent'] * 100.0 / $r['sessions_held'], 1) : null;
            $items[] = $r;
        }
        $stmt->close();

        return ['items' => $items, 'page' => $page, 'sessions_held' => $held, 'window' => $w];
    }

    /** Per-section rollup (counts + percentages vs held days). */
    public static function analyticsSections(\mysqli $conn, array $filters): array
    {
        $w = self::window($filters['from'] ?? null, $filters['to'] ?? null);
        $programType = in_array($filters['program_type'] ?? '', self::PROGRAM_TYPES, true) ? $filters['program_type'] : '';
        $held = self::daysHeld($conn, $w, $programType);

        [$pJoin, $pType] = self::programJoinFilter($programType);

        $sql = "
            SELECT COALESCE(NULLIF(TRIM(m.current_section), ''), '—') AS section,
                   COUNT(DISTINCT m.id) AS members,
                   COALESCE(SUM(a.status = 'present'), 0) AS present,
                   COALESCE(SUM(a.status = 'late'), 0)    AS late,
                   COALESCE(SUM(a.status = 'absent'), 0)  AS absent
            FROM members m
            LEFT JOIN mezmur_attendance a ON a.member_id = m.id AND a.attendance_date BETWEEN ? AND ?"
            . ($programType !== ''
                ? " AND EXISTS (SELECT 1 FROM mezmur_days d WHERE d.attendance_date = a.attendance_date AND d.program_type = ?)"
                : '') . "
            WHERE m.status = 'active'
            GROUP BY section
            ORDER BY members DESC";

        $stmt = $conn->prepare($sql);
        $types = 'ss' . ($programType !== '' ? 's' : '');
        $params = array_merge([$w['from'], $w['to']], $programType !== '' ? [$programType] : []);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) {
            $members = (int)$r['members'];
            $capacity = $held * $members;
            $attended = (int)$r['present'] + (int)$r['late'];
            $r['members'] = $members;
            $r['sessions_held'] = $held;
            $r['attended'] = $attended;
            $r['present_pct'] = $capacity > 0 ? round(((int)$r['present']) * 100.0 / $capacity, 1) : null;
            $r['late_pct']    = $capacity > 0 ? round(((int)$r['late']) * 100.0 / $capacity, 1) : null;
            $r['absent_pct']  = $capacity > 0 ? round(((int)$r['absent']) * 100.0 / $capacity, 1) : null;
            $r['rate']        = $capacity > 0 ? round($attended * 100.0 / $capacity, 1) : null;
            $items[] = $r;
        }
        $stmt->close();
        return ['items' => $items, 'sessions_held' => $held, 'window' => $w];
    }

    /** Monthly trend: days held + overall attendance rate. */
    public static function analyticsTrends(\mysqli $conn, array $filters): array
    {
        $w = self::window($filters['from'] ?? null, $filters['to'] ?? null);
        $programType = in_array($filters['program_type'] ?? '', self::PROGRAM_TYPES, true) ? $filters['program_type'] : '';

        $sql = "
            SELECT DATE_FORMAT(d.attendance_date, '%Y-%m') AS month,
                   COUNT(DISTINCT d.id) AS sessions,
                   COUNT(a.id) AS marks,
                   COALESCE(SUM(a.status IN ('present','late')), 0) AS attended
            FROM mezmur_days d
            LEFT JOIN mezmur_attendance a ON a.attendance_date = d.attendance_date
            WHERE d.attendance_date BETWEEN ? AND ?"
            . ($programType !== '' ? " AND d.program_type = ?" : '') . "
            GROUP BY month
            ORDER BY month ASC";

        $stmt = $conn->prepare($sql);
        $types = 'ss' . ($programType !== '' ? 's' : '');
        $params = array_merge([$w['from'], $w['to']], $programType !== '' ? [$programType] : []);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) {
            $marks = (int)$r['marks'];
            $items[] = [
                'month' => $r['month'],
                'sessions' => (int)$r['sessions'],
                'marks' => $marks,
                'attended' => (int)$r['attended'],
                'rate' => $marks > 0 ? round(((int)$r['attended']) * 100.0 / $marks, 1) : null,
            ];
        }
        $stmt->close();
        return ['items' => $items, 'window' => $w];
    }

    /** Distinct section list for filter dropdowns. */
    public static function sectionList(\mysqli $conn): array
    {
        $out = [];
        $res = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(current_section), ''), '—') AS section FROM members WHERE status = 'active' ORDER BY section LIMIT 200");
        if ($res) {
            while ($r = $res->fetch_assoc()) $out[] = $r['section'];
        }
        return $out;
    }

    /** Attendance-taker accounts (role-scoped user listing). */
    public static function takersList(\mysqli $conn): array
    {
        $out = [];
        $res = $conn->query(
            "SELECT u.id, u.username, u.full_name, u.is_active, u.created_at,
                    m.student_name, m.member_code
             FROM users u
             LEFT JOIN members m ON u.member_id = m.id
             WHERE u.role = 'attendance_taker'
             ORDER BY u.full_name
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
}
