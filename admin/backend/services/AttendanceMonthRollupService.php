<?php
/**
 * ============================================================================
 * AttendanceMonthRollupService — per-member, per-Ethiopian-month roll-up
 * (Attendance alerting — Phase 4, requirements §6 / §9, owner T-5)
 * ============================================================================
 * ONE derived table (`attendance_month_rollup`, sql/074) that caches the
 * SHARED risk formula of AttendanceRiskService for every member and every
 * Ethiopian month, per department (Education and Mezmur stay separate):
 *
 *   sessions    = eligible rows with status present|absent|late|excused
 *   present_eq  = present + excused + LATE_WEIGHT·late
 *   absent_eq   = absent + LATE_WEIGHT·late
 *   rate        = present_eq / sessions · 100 (NULL when no sessions)
 *   band        = AttendanceRiskService::band()  (90 / 75 / 50)
 *   below       = sessions ≥ policy.min_sessions AND rate < policy.low_rate_threshold
 *
 * "Eligible" is exactly AttendanceRiskService::eligibleRows (same SQL rules,
 * written set-based here): packet submitted|approved (legacy rows without a
 * packet count), holidays never count, rows inside an inactive department
 * period are skipped (DepartmentStatusService semantics: inactive from the
 * day of `started_at` up to — not including — the day of `ended_at`).
 *
 * Refresh policy (no cron on shared hosting):
 *   • after every official write / review transition the members on that
 *     sheet are recomputed for the sheet's month (hook in
 *     AttendanceWarningService::safeEvaluateMany);
 *   • opening the Monthly attendance report recomputes a whole
 *     (department, month) when it is missing or stale (10 min for the current
 *     month, 24 h for past months).
 * Rows are never deleted: a member who no longer has eligible sessions in a
 * month is reset to sessions = 0 / band no_data (history preserved, §13).
 *
 * All user-facing dates produced here are Ethiopian calendar strings; the
 * Gregorian window is internal only.
 */
declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/FeatureGate.php';
require_once __DIR__ . '/DepartmentStatusService.php';
require_once __DIR__ . '/AttendancePolicyService.php';
require_once __DIR__ . '/AttendanceRiskService.php';
require_once __DIR__ . '/MemberCategory.php';
require_once __DIR__ . '/AttendanceWarningService.php';

final class AttendanceMonthRollupService
{
    public const TABLE = 'attendance_month_rollup';
    /** Current month is recomputed when older than this. */
    public const STALE_CURRENT_SECONDS = 600;
    /** Past months (only change through reopen/resubmit, which refresh them directly). */
    public const STALE_PAST_SECONDS = 86400;
    /** Oldest month offered in the picker (history further back is still queryable by URL). */
    public const MAX_MONTHS_BACK = 36;
    public const PAGE_MAX = 500;

    /** @var array<int,bool> */
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
                $r = $conn->query("SHOW TABLES LIKE '" . self::TABLE . "'");
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

    // ── refresh ───────────────────────────────────────────────────

    /**
     * Recompute one (department, EC month) — for every member with rows in
     * that month, or only for $memberIds when given. Idempotent; wrapped in a
     * transaction so readers never see a half-reset month.
     *
     * @param int[]|null $memberIds
     * @return array{ok:bool,rows:int,reset:int}
     */
    /** Message of the last failed refresh in this request (null when the last refresh succeeded). */
    public static ?string $lastError = null;

    public static function refreshMonth(\mysqli $conn, string $dept, int $ecYear, int $ecMonth, ?array $memberIds = null): array
    {
        $out = ['ok' => false, 'rows' => 0, 'reset' => 0];
        if (!self::available($conn) || !in_array($dept, AttendanceRiskService::DEPARTMENTS, true)) {
            return $out;
        }
        $ecMonth = max(1, min(13, $ecMonth));
        $ids = null;
        if ($memberIds !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn($i) => $i > 0)));
            if ($ids === []) {
                $out['ok'] = true;
                return $out;
            }
        }
        $window = AttendanceRiskService::ecMonthWindow($ecYear, $ecMonth);
        $policy = AttendancePolicyService::for($conn, $dept);
        $threshold = (float)($policy['low_rate_threshold'] ?? 75.0);
        $minSessions = (int)($policy['min_sessions'] ?? 3);

        $idSql = $ids === null ? '' : ' AND %s IN (' . implode(',', $ids) . ')';
        $conn->begin_transaction();
        try {
            // 1. Reset: members whose sheet was reopened/rejected may have no
            //    eligible rows left — their row must show "no data", not the old rate.
            $reset = $conn->prepare(
                'UPDATE ' . self::TABLE . "
                 SET sessions = 0, present = 0, absent = 0, late = 0, excused = 0, present_eq = 0, absent_eq = 0,
                     rate = NULL, band = 'no_data', below_threshold = 0, threshold_value = ?, computed_at = NOW()
                 WHERE department = ? AND ec_year = ? AND ec_month = ?" . ($ids === null ? '' : sprintf($idSql, 'member_id'))
            );
            if (!$reset) {
                throw new \RuntimeException('rollup reset prepare failed');
            }
            $reset->bind_param('dsii', $threshold, $dept, $ecYear, $ecMonth);
            $reset->execute();
            $out['reset'] = $reset->affected_rows;
            $reset->close();

            // 2. Set-based recompute of the shared formula.
            $src = $dept === 'edu'
                ? self::eduSourceSql($conn, $ids === null ? '' : sprintf($idSql, 'a.member_id'))
                : self::mezmurSourceSql($conn, $ids === null ? '' : sprintf($idSql, 'x.member_id'));
            $w = AttendanceRiskService::LATE_WEIGHT;
            $bE = AttendanceRiskService::BAND_EXCELLENT;
            $bG = AttendanceRiskService::BAND_GOOD;
            $bF = AttendanceRiskService::BAND_FAIR;
            $sql = 'INSERT INTO ' . self::TABLE . "
                    (department, member_id, ec_year, ec_month, sessions, present, absent, late, excused,
                     present_eq, absent_eq, rate, band, below_threshold, threshold_value, computed_at)
                SELECT ?, t.member_id, ?, ?, t.sessions, t.present, t.absent, t.late, t.excused,
                       t.present + t.excused + {$w} * t.late,
                       t.absent + {$w} * t.late,
                       ROUND((t.present + t.excused + {$w} * t.late) / t.sessions * 100, 2),
                       CASE WHEN t.sessions = 0 THEN 'no_data'
                            WHEN (t.present + t.excused + {$w} * t.late) / t.sessions * 100 >= {$bE} THEN 'excellent'
                            WHEN (t.present + t.excused + {$w} * t.late) / t.sessions * 100 >= {$bG} THEN 'good'
                            WHEN (t.present + t.excused + {$w} * t.late) / t.sessions * 100 >= {$bF} THEN 'fair'
                            ELSE 'low' END,
                       CASE WHEN t.sessions >= ? AND ROUND((t.present + t.excused + {$w} * t.late) / t.sessions * 100, 2) < ? THEN 1 ELSE 0 END,
                       ?, NOW()
                FROM ({$src}) t
                ON DUPLICATE KEY UPDATE
                    sessions = VALUES(sessions), present = VALUES(present), absent = VALUES(absent),
                    late = VALUES(late), excused = VALUES(excused), present_eq = VALUES(present_eq),
                    absent_eq = VALUES(absent_eq), rate = VALUES(rate), band = VALUES(band),
                    below_threshold = VALUES(below_threshold), threshold_value = VALUES(threshold_value),
                    computed_at = VALUES(computed_at)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new \RuntimeException('rollup insert prepare failed');
            }
            $from = $window['from'];
            $to = $window['to'];
            $stmt->bind_param('siiiddss', $dept, $ecYear, $ecMonth, $minSessions, $threshold, $threshold, $from, $to);
            // mysqli reporting is off project-wide: a failed execute (e.g. a
            // missing column on a server where a migration was not run) must
            // not pass as "0 rows, ok" — surface it as a rollup failure.
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new \RuntimeException('rollup insert failed: ' . $err);
            }
            $out['rows'] = max(0, $stmt->affected_rows);
            $stmt->close();
            $conn->commit();
            $out['ok'] = true;
        } catch (\Throwable $e) {
            try {
                $conn->rollback();
            } catch (\Throwable $e2) {
                // ignore
            }
            $out['error'] = $e->getMessage();
            self::$lastError = $e->getMessage();
            error_log('[AttendanceMonthRollupService::refreshMonth] ' . $e->getMessage());
        }
        return $out;
    }

    /** Recompute the month that contains $date for the given members (post-write hook). */
    public static function refreshForDate(\mysqli $conn, string $dept, array $memberIds, string $date): array
    {
        try {
            $ec = AttendanceRiskService::ecOf($date);
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => 0, 'reset' => 0];
        }
        return self::refreshMonth($conn, $dept, (int)$ec['year'], (int)$ec['month'], $memberIds);
    }

    /** Never throws. */
    public static function safeRefreshForDate(\mysqli $conn, string $dept, array $memberIds, string $date): void
    {
        try {
            self::refreshForDate($conn, $dept, $memberIds, $date);
        } catch (\Throwable $e) {
            error_log('[AttendanceMonthRollupService::safeRefreshForDate] ' . $e->getMessage());
        }
    }

    /** Lazy refresh on report open: missing or stale month → recompute. @return bool refreshed */
    public static function ensureFresh(\mysqli $conn, string $dept, int $ecYear, int $ecMonth, bool $force = false): bool
    {
        if (!self::available($conn)) {
            return false;
        }
        $refresh = $force;
        if (!$refresh) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS c, MAX(computed_at) AS m FROM ' . self::TABLE . ' WHERE department = ? AND ec_year = ? AND ec_month = ?');
            if ($stmt) {
                $stmt->bind_param('sii', $dept, $ecYear, $ecMonth);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                $count = (int)($row['c'] ?? 0);
                $last = $row['m'] ?? null;
                $now = AttendanceRiskService::ecOf(date('Y-m-d'));
                $isCurrent = (int)$now['year'] === $ecYear && (int)$now['month'] === $ecMonth;
                $maxAge = $isCurrent ? self::STALE_CURRENT_SECONDS : self::STALE_PAST_SECONDS;
                $refresh = $count === 0 || $last === null || (time() - strtotime((string)$last)) > $maxAge;
            }
        }
        if ($refresh) {
            self::$lastError = null;
            self::refreshMonth($conn, $dept, $ecYear, $ecMonth);
        }
        return $refresh;
    }

    // ── source SQL (mirrors AttendanceRiskService::eduRows / mezmurRows) ──

    private static function periodExclusionSql(\mysqli $conn, string $dept, string $memberExpr, string $dateExpr): string
    {
        if (!DepartmentStatusService::available($conn)) {
            return '';
        }
        return " AND NOT EXISTS (SELECT 1 FROM member_department_periods dp
                     WHERE dp.member_id = {$memberExpr} AND dp.department = '{$dept}' AND dp.status = 'inactive'
                       AND DATE(dp.started_at) <= {$dateExpr}
                       AND (dp.ended_at IS NULL OR DATE(dp.ended_at) > {$dateExpr}))";
    }

    private static function eduSourceSql(\mysqli $conn, string $idFilter): string
    {
        $packetFilter = AttendanceRiskService::legacyRowsEligible()
            ? "(gs.id IS NULL OR gs.status IN ('submitted','approved'))"
            : "(gs.id IS NOT NULL AND gs.status IN ('submitted','approved'))";
        return "SELECT a.member_id,
                       COUNT(*) AS sessions,
                       SUM(a.status = 'present') AS present, SUM(a.status = 'absent') AS absent,
                       SUM(a.status = 'late') AS late, SUM(a.status = 'excused') AS excused
                FROM attendance a
                JOIN members m ON m.id = a.member_id
                LEFT JOIN grade_submissions gs ON gs.id = (
                    SELECT g2.id FROM grade_submissions g2
                    WHERE g2.class_id = a.class_id AND g2.attendance_date = a.attendance_date
                      AND g2.submission_type = 'attendance'
                    ORDER BY g2.id DESC LIMIT 1)
                WHERE a.attendance_date BETWEEN ? AND ?
                  AND a.status IN ('present','absent','late','excused')
                  AND {$packetFilter}" . self::periodExclusionSql($conn, 'edu', 'a.member_id', 'a.attendance_date') . $idFilter . "
                GROUP BY a.member_id";
    }

    private static function mezmurSourceSql(\mysqli $conn, string $idFilter): string
    {
        $sectionExpr = MemberCategory::sqlSectionExpr('m');
        $packetFilter = AttendanceRiskService::legacyRowsEligible()
            ? "(ms.id IS NULL OR ms.status IN ('submitted','approved'))"
            : "(ms.id IS NOT NULL AND ms.status IN ('submitted','approved'))";
        $d = 'COALESCE(x.attendance_date, s.session_date)';
        return "SELECT x.member_id,
                       COUNT(*) AS sessions,
                       SUM(x.status = 'present') AS present, SUM(x.status = 'absent') AS absent,
                       SUM(x.status = 'late') AS late, SUM(x.status = 'excused') AS excused
                FROM mezmur_attendance x
                JOIN members m ON m.id = x.member_id
                LEFT JOIN mezmur_sessions s ON s.id = x.session_id
                LEFT JOIN mezmur_submissions ms
                       ON ms.attendance_date = {$d} AND ms.section = {$sectionExpr}
                WHERE {$d} BETWEEN ? AND ?
                  AND x.status IN ('present','absent','late','excused')
                  AND {$packetFilter}" . self::periodExclusionSql($conn, 'mezmur', 'x.member_id', $d) . $idFilter . "
                GROUP BY x.member_id";
    }

    // ── reads ─────────────────────────────────────────────────────

    /**
     * EC months offered by the picker: from the department's oldest marked
     * date (capped at MAX_MONTHS_BACK) up to the current month, newest first.
     * @return list<array{ec_year:int,ec_month:int,label:string,label_am:string,is_current:bool}>
     */
    public static function months(\mysqli $conn, string $dept): array
    {
        $today = date('Y-m-d');
        $now = AttendanceRiskService::ecOf($today);
        $oldest = $today;
        try {
            $sql = $dept === 'edu'
                ? "SELECT MIN(attendance_date) AS d FROM attendance WHERE status IN ('present','absent','late','excused')"
                : "SELECT MIN(COALESCE(x.attendance_date, s.session_date)) AS d FROM mezmur_attendance x LEFT JOIN mezmur_sessions s ON s.id = x.session_id";
            $r = $conn->query($sql);
            $row = $r ? $r->fetch_assoc() : null;
            if ($row && !empty($row['d'])) {
                $oldest = substr((string)$row['d'], 0, 10);
            }
        } catch (\Throwable $e) {
            // keep today
        }
        $old = AttendanceRiskService::ecOf($oldest);
        $out = [];
        $y = (int)$now['year'];
        $m = (int)$now['month'];
        for ($i = 0; $i < self::MAX_MONTHS_BACK; $i++) {
            $out[] = [
                'ec_year' => $y, 'ec_month' => $m,
                'label' => ethio_month_name($m) . ' ' . $y,
                'label_am' => AttendanceRiskService::monthLabelAm($y, $m),
                'is_current' => $i === 0,
            ];
            if ($y < (int)$old['year'] || ($y === (int)$old['year'] && $m <= (int)$old['month'])) {
                break;
            }
            $m--;
            if ($m < 1) {
                $m = 13;
                $y--;
            }
        }
        return $out;
    }

    /**
     * One month of one department — paged, filterable, joined with the member
     * directory, class/section and the member's open warnings.
     * @param array{below?:bool,band?:string,q?:string,group?:string,limit?:int,offset?:int} $f
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public static function listMonth(\mysqli $conn, string $dept, int $ecYear, int $ecMonth, array $f = []): array
    {
        $limit = max(1, min(self::PAGE_MAX, (int)($f['limit'] ?? 100)));
        $offset = max(0, (int)($f['offset'] ?? 0));
        $empty = ['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        if (!self::available($conn) || !in_array($dept, AttendanceRiskService::DEPARTMENTS, true)) {
            return $empty;
        }
        $sectionExpr = MemberCategory::sqlSectionExpr('m');
        $where = 'r.department = ? AND r.ec_year = ? AND r.ec_month = ?';
        $types = 'sii';
        $params = [$dept, $ecYear, $ecMonth];
        if (!empty($f['below'])) {
            $where .= ' AND r.below_threshold = 1';
        }
        $band = (string)($f['band'] ?? '');
        if (in_array($band, ['no_data', 'low', 'fair', 'good', 'excellent'], true)) {
            $where .= ' AND r.band = ?';
            $types .= 's';
            $params[] = $band;
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (m.student_name LIKE ? OR m.father_name LIKE ? OR m.member_code LIKE ?)';
            $like = '%' . $q . '%';
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }
        $group = trim((string)($f['group'] ?? ''));
        if ($group !== '') {
            $where .= $dept === 'edu' ? ' AND cl.id = ?' : " AND {$sectionExpr} = ?";
            $types .= $dept === 'edu' ? 'i' : 's';
            $params[] = $dept === 'edu' ? (int)$group : $group;
        }
        $joins = "JOIN members m ON m.id = r.member_id
                  LEFT JOIN class_enrollments ce ON ce.id = (
                      SELECT MAX(e.id) FROM class_enrollments e WHERE e.member_id = m.id AND e.status = 'active')
                  LEFT JOIN classes cl ON cl.id = ce.class_id";

        $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM " . self::TABLE . " r {$joins} WHERE {$where}");
        if (!$cnt) {
            return $empty;
        }
        $cnt->bind_param($types, ...$params);
        $cnt->execute();
        $total = (int)(($cnt->get_result()->fetch_assoc() ?: ['c' => 0])['c']);
        $cnt->close();

        $warnTable = AttendanceWarningService::TABLE;
        $openWarnings = AttendanceWarningService::tableExists($conn)
            ? "(SELECT COUNT(*) FROM {$warnTable} w WHERE w.member_id = r.member_id AND w.department = r.department AND w.status = 'open')"
            : '0';
        $sql = "SELECT r.member_id, r.sessions, r.present, r.absent, r.late, r.excused, r.present_eq, r.absent_eq,
                       r.rate, r.band, r.below_threshold, r.threshold_value, r.computed_at,
                       m.member_code, m.student_name, m.father_name, m.gender, m.status AS member_status,
                       cl.id AS class_id, cl.class_name, {$sectionExpr} AS section,
                       {$openWarnings} AS open_warnings
                FROM " . self::TABLE . " r {$joins}
                WHERE {$where}
                ORDER BY (r.rate IS NULL) ASC, r.rate ASC, m.student_name ASC, m.father_name ASC
                LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $empty;
        }
        $types2 = $types . 'ii';
        $params2 = array_merge($params, [$limit, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['member_id'];
            $items[] = self::present($row, $dept);
        }
        $stmt->close();
        // Department status (active / inactive) for the page — never members.status.
        $statuses = $ids === [] ? [] : DepartmentStatusService::statusesFor($conn, $ids);
        foreach ($items as &$it) {
            $st = $statuses[$it['member_id']][$dept] ?? null;
            $it['department_status'] = is_array($st) ? (string)($st['status'] ?? 'active') : (is_string($st) ? $st : 'active');
        }
        unset($it);
        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array<string,mixed> */
    private static function present(array $row, string $dept): array
    {
        $rate = $row['rate'] === null ? null : (float)$row['rate'];
        return [
            'member_id' => (int)$row['member_id'],
            'member_code' => (string)($row['member_code'] ?? ''),
            'name' => trim((string)($row['student_name'] ?? '') . ' ' . (string)($row['father_name'] ?? '')),
            'student_name' => (string)($row['student_name'] ?? ''),
            'father_name' => (string)($row['father_name'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
            'member_status' => (string)($row['member_status'] ?? ''),
            'group' => $dept === 'edu' ? (string)($row['class_name'] ?? '—') : (string)($row['section'] ?? '—'),
            'group_id' => $dept === 'edu' ? (int)($row['class_id'] ?? 0) : (string)($row['section'] ?? ''),
            'sessions' => (int)$row['sessions'],
            'present' => (int)$row['present'],
            'absent' => (int)$row['absent'],
            'late' => (int)$row['late'],
            'excused' => (int)$row['excused'],
            'present_eq' => (float)$row['present_eq'],
            'absent_eq' => (float)$row['absent_eq'],
            'rate' => $rate,
            'band' => (string)$row['band'],
            'below_threshold' => (int)$row['below_threshold'] === 1,
            'threshold_value' => $row['threshold_value'] === null ? null : (float)$row['threshold_value'],
            'open_warnings' => (int)($row['open_warnings'] ?? 0),
            'computed_at_ec' => self::ecDateTime((string)$row['computed_at']),
        ];
    }

    /**
     * Band counts, below-threshold count and the policy for one month.
     * @return array<string,mixed>
     */
    public static function summary(\mysqli $conn, string $dept, int $ecYear, int $ecMonth): array
    {
        $policy = AttendancePolicyService::for($conn, $dept);
        $out = [
            'department' => $dept,
            'label' => AttendanceRiskService::LABELS[$dept] ?? $dept,
            'ec_year' => $ecYear,
            'ec_month' => $ecMonth,
            'month_label' => ethio_month_name($ecMonth) . ' ' . $ecYear,
            'month_label_am' => AttendanceRiskService::monthLabelAm($ecYear, $ecMonth),
            'threshold' => (float)($policy['low_rate_threshold'] ?? 75.0),
            'min_sessions' => (int)($policy['min_sessions'] ?? 3),
            'members' => 0, 'with_data' => 0, 'below' => 0, 'avg_rate' => null,
            'bands' => ['excellent' => 0, 'good' => 0, 'fair' => 0, 'low' => 0, 'no_data' => 0],
            'computed_at_ec' => null,
        ];
        if (!self::available($conn)) {
            return $out;
        }
        $stmt = $conn->prepare('SELECT band, COUNT(*) AS c, SUM(below_threshold) AS b, AVG(rate) AS avg_rate, MAX(computed_at) AS m
                                FROM ' . self::TABLE . ' WHERE department = ? AND ec_year = ? AND ec_month = ? GROUP BY band');
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('sii', $dept, $ecYear, $ecMonth);
        $stmt->execute();
        $res = $stmt->get_result();
        $sum = 0.0;
        $n = 0;
        $latest = null;
        while ($row = $res->fetch_assoc()) {
            $band = (string)$row['band'];
            $c = (int)$row['c'];
            $out['bands'][$band] = $c;
            $out['members'] += $c;
            $out['below'] += (int)$row['b'];
            if ($band !== 'no_data') {
                $out['with_data'] += $c;
                $sum += (float)$row['avg_rate'] * $c;
                $n += $c;
            }
            if ($row['m'] !== null && ($latest === null || $row['m'] > $latest)) {
                $latest = (string)$row['m'];
            }
        }
        $stmt->close();
        $out['avg_rate'] = $n > 0 ? round($sum / $n, 2) : null;
        $out['computed_at_ec'] = $latest === null ? null : self::ecDateTime($latest);
        return $out;
    }

    /** Groups for the filter: Education classes / Mezmur sections present in the month. @return list<array{id:string,label:string}> */
    public static function groupsForMonth(\mysqli $conn, string $dept, int $ecYear, int $ecMonth): array
    {
        if (!self::available($conn)) {
            return [];
        }
        $sectionExpr = MemberCategory::sqlSectionExpr('m');
        $sql = $dept === 'edu'
            ? "SELECT DISTINCT cl.id AS id, cl.class_name AS label FROM " . self::TABLE . " r
               JOIN class_enrollments ce ON ce.id = (SELECT MAX(e.id) FROM class_enrollments e WHERE e.member_id = r.member_id AND e.status = 'active')
               JOIN classes cl ON cl.id = ce.class_id
               WHERE r.department = ? AND r.ec_year = ? AND r.ec_month = ? ORDER BY cl.class_name"
            : "SELECT DISTINCT {$sectionExpr} AS id, {$sectionExpr} AS label FROM " . self::TABLE . " r
               JOIN members m ON m.id = r.member_id
               WHERE r.department = ? AND r.ec_year = ? AND r.ec_month = ? ORDER BY label";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('sii', $dept, $ecYear, $ecMonth);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = ['id' => (string)$row['id'], 'label' => (string)$row['label']];
        }
        $stmt->close();
        return $out;
    }

    /** Rows of a member across months (profile / drill-down), newest first. @return list<array<string,mixed>> */
    public static function memberMonths(\mysqli $conn, string $dept, int $memberId, int $limit = 12): array
    {
        if (!self::available($conn) || $memberId <= 0) {
            return [];
        }
        $stmt = $conn->prepare('SELECT ec_year, ec_month, sessions, present, absent, late, excused, present_eq, absent_eq, rate, band, below_threshold
                                FROM ' . self::TABLE . ' WHERE department = ? AND member_id = ? ORDER BY ec_year DESC, ec_month DESC LIMIT ?');
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('sii', $dept, $memberId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'ec_year' => (int)$row['ec_year'], 'ec_month' => (int)$row['ec_month'],
                'label' => ethio_month_name((int)$row['ec_month']) . ' ' . (int)$row['ec_year'],
                'label_am' => AttendanceRiskService::monthLabelAm((int)$row['ec_year'], (int)$row['ec_month']),
                'sessions' => (int)$row['sessions'], 'present' => (int)$row['present'], 'absent' => (int)$row['absent'],
                'late' => (int)$row['late'], 'excused' => (int)$row['excused'],
                'present_eq' => (float)$row['present_eq'], 'absent_eq' => (float)$row['absent_eq'],
                'rate' => $row['rate'] === null ? null : (float)$row['rate'], 'band' => (string)$row['band'],
                'below_threshold' => (int)$row['below_threshold'] === 1,
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * Dashboard card for the CURRENT Ethiopian month (Education / Mezmur / HR /
     * Information). Lazily refreshes each requested department (10-min bound)
     * and never throws — dashboards must render even when the roll-up is off.
     * @param string[] $departments
     * @return array{available:bool,month_label:string,month_label_am:string,ec_year:int,ec_month:int,below:int,members:int,avg_rate:?float,per_dept:array<string,array{below:int,members:int,avg_rate:?float,threshold:float}>}
     */
    public static function dashboardCard(\mysqli $conn, array $departments): array
    {
        $now = AttendanceRiskService::ecOf(date('Y-m-d'));
        $y = (int)$now['year'];
        $m = (int)$now['month'];
        $out = [
            'available' => false,
            'month_label' => ethio_month_name($m) . ' ' . $y,
            'month_label_am' => AttendanceRiskService::monthLabelAm($y, $m),
            'ec_year' => $y, 'ec_month' => $m,
            'below' => 0, 'members' => 0, 'avg_rate' => null, 'per_dept' => [],
        ];
        try {
            if (!self::available($conn)) {
                return $out;
            }
            $out['available'] = true;
            $sum = 0.0;
            $n = 0;
            foreach ($departments as $d) {
                if (!in_array($d, AttendanceRiskService::DEPARTMENTS, true)) {
                    continue;
                }
                self::ensureFresh($conn, $d, $y, $m);
                $s = self::summary($conn, $d, $y, $m);
                $out['per_dept'][$d] = [
                    'below' => (int)$s['below'], 'members' => (int)$s['members'],
                    'avg_rate' => $s['avg_rate'], 'threshold' => (float)$s['threshold'],
                ];
                $out['below'] += (int)$s['below'];
                $out['members'] += (int)$s['members'];
                if ($s['avg_rate'] !== null && (int)$s['with_data'] > 0) {
                    $sum += (float)$s['avg_rate'] * (int)$s['with_data'];
                    $n += (int)$s['with_data'];
                }
            }
            $out['avg_rate'] = $n > 0 ? round($sum / $n, 1) : null;
        } catch (\Throwable $e) {
            error_log('[AttendanceMonthRollupService::dashboardCard] ' . $e->getMessage());
        }
        return $out;
    }

    /** EC date-time label for a DATETIME string, e.g. "Tikimt 9, 2019 14:05". */
    public static function ecDateTime(string $dt): string
    {
        if ($dt === '') {
            return '';
        }
        try {
            $d = new \DateTime($dt);
            return ethio_date_format($d, 'F j, Y') . ' ' . $d->format('H:i');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
