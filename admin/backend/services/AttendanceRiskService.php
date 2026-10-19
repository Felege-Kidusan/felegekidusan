<?php
/**
 * Attendance risk engine (Attendance alerting — Phase 2).
 *
 * ONE calculation for every consumer (dashboards, warnings, mobile, later the
 * monthly reports). Pure PHP + prepared statements, shared-hosting safe.
 *
 * Rules (ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN_2026-10-19 §5 Phase 2):
 *   1. Eligible sessions for (member, department, from, to) are rows of the
 *      department's attendance table whose date lies inside one of the
 *      member's ACTIVE periods (DepartmentStatusService), inside the window,
 *      whose status is not `holiday`, and whose packet is `submitted` or
 *      `approved`. Packets in draft / incomplete / revision_needed / rejected
 *      are NOT eligible. Rows without any packet are treated the way the rest
 *      of the system already treats them (SubmissionService /
 *      MezmurSubmissionService `resolved*Status()` => `submitted`): see
 *      LEGACY_ROWS_ELIGIBLE. This keeps years of web-saved sheets (which never
 *      created packets before Phase 2) countable at rollout.
 *   2. Day outcome: group eligible rows by date; the day is `absent` only when
 *      EVERY row that day is absent, otherwise `attended`
 *      (present / late / excused all break a streak).
 *   3. Streak: day outcomes descending from the latest eligible day; count
 *      consecutive absent days until the first attended day or the start of
 *      the current active period. Days without an eligible session are
 *      skipped (they neither break nor extend the streak).
 *   4. Rate: present_eq = present + excused + 0.5·late, absent_eq = absent +
 *      0.5·late, rate = present_eq / sessions × 100; sessions = 0 => NULL
 *      ("No data", never at risk). Bands: ≥90 excellent, ≥75 good, ≥50 fair,
 *      else low.
 *   5. Departments are fully independent; `members.status` is never read for
 *      eligibility and never written.
 *
 * Dates in and out of this class are Gregorian `Y-m-d` (DB convention). The
 * `ec` helpers return Ethiopian-calendar parts for display and month keys.
 */
namespace App\Services;

require_once __DIR__ . '/DepartmentStatusService.php';
require_once __DIR__ . '/MemberCategory.php';
if (!function_exists('gregorian_to_ethiopian')) {
    require_once dirname(__DIR__) . '/ethiopian_date.php';
}

final class AttendanceRiskService
{
    public const DEPARTMENTS = ['edu', 'mezmur'];
    public const LABELS = ['edu' => 'Education', 'mezmur' => 'Mezmur'];
    public const ELIGIBLE_PACKET_STATUSES = ['submitted', 'approved'];
    /** Rows with no packet at all count as submitted (matches resolved*Status()). */
    public const LEGACY_ROWS_ELIGIBLE = true;
    public const LATE_WEIGHT = 0.5;
    public const BAND_EXCELLENT = 90.0;
    public const BAND_GOOD = 75.0;
    public const BAND_FAIR = 50.0;
    public const MONTHS_AM = [1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ኅዳር', 4 => 'ታኅሣሥ', 5 => 'ጥር', 6 => 'የካቲት',
        7 => 'መጋቢት', 8 => 'ሚያዝያ', 9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ', 13 => 'ጳጉሜ'];

    // ── calendar helpers ──────────────────────────────────────────

    /** @return array{year:int,month:int,day:int} */
    public static function ecOf(string $date): array
    {
        $arr = gregorian_to_ethiopian($date);
        return ['year' => (int)$arr['year'], 'month' => (int)$arr['month'], 'day' => (int)$arr['day']];
    }

    /** Gregorian window of one Ethiopian month. @return array{from:string,to:string} */
    public static function ecMonthWindow(int $ecYear, int $ecMonth): array
    {
        $ecMonth = max(1, min(13, $ecMonth));
        $from = ethiopian_to_gregorian($ecYear, $ecMonth, 1);
        $next = $ecMonth === 13
            ? ethiopian_to_gregorian($ecYear + 1, 1, 1)
            : ethiopian_to_gregorian($ecYear, $ecMonth + 1, 1);
        $to = (clone $next)->modify('-1 day');
        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }

    /** Gregorian window of one Ethiopian year (Meskerem 1 .. Pagume). */
    public static function ecYearWindow(int $ecYear): array
    {
        $from = ethiopian_to_gregorian($ecYear, 1, 1);
        $to = (clone ethiopian_to_gregorian($ecYear + 1, 1, 1))->modify('-1 day');
        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'ec_year' => $ecYear];
    }

    /**
     * The academic-year window used for eligibility: the active row in
     * `academic_years` when it carries dates that contain $asOf, otherwise the
     * Ethiopian year containing $asOf.
     * @return array{from:string,to:string,ec_year:int,year_id:?int,label:string}
     */
    public static function yearWindow(\mysqli $conn, ?string $asOf = null): array
    {
        $asOf = $asOf ?: date('Y-m-d');
        $ec = self::ecOf($asOf);
        $fallback = self::ecYearWindow($ec['year']) + ['year_id' => null, 'label' => (string)$ec['year'] . ' ዓ.ም'];
        try {
            $res = $conn->query("SELECT id, year_name, ec_year, start_date, end_date FROM academic_years WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            if (!$row) {
                $res = $conn->query("SELECT id, year_name, ec_year, start_date, end_date FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
                $row = $res ? $res->fetch_assoc() : null;
            }
        } catch (\Throwable $e) {
            $row = null;
        }
        if (!$row || empty($row['start_date']) || empty($row['end_date'])) {
            return $fallback;
        }
        $from = substr((string)$row['start_date'], 0, 10);
        $to = substr((string)$row['end_date'], 0, 10);
        if ($from > $to || $asOf < $from || $asOf > $to) {
            return $fallback;
        }
        $ecYear = (int)($row['ec_year'] ?? 0) ?: self::ecOf($from)['year'];
        return ['from' => $from, 'to' => $to, 'ec_year' => $ecYear, 'year_id' => (int)$row['id'],
                'label' => (string)($row['year_name'] ?? $ecYear)];
    }

    // ── eligible sessions ─────────────────────────────────────────

    /**
     * Eligible rows (see rule 1), ascending by date.
     * @return array<int,array{date:string,status:string,ref:int|string|null}>
     */
    public static function eligibleRows(\mysqli $conn, string $dept, int $memberId, string $from, string $to): array
    {
        if (!in_array($dept, self::DEPARTMENTS, true) || $memberId <= 0 || $from > $to) {
            return [];
        }
        $rows = $dept === 'edu'
            ? self::eduRows($conn, $memberId, $from, $to)
            : self::mezmurRows($conn, $memberId, $from, $to);
        if ($rows === []) {
            return [];
        }
        $periods = DepartmentStatusService::activePeriods($conn, $memberId, $dept, $from, $to);
        if ($periods === []) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            foreach ($periods as $p) {
                if ($r['date'] >= $p['from'] && $r['date'] <= $p['to']) {
                    $out[] = $r;
                    break;
                }
            }
        }
        return $out;
    }

    /** @return array<int,array{date:string,status:string,ref:int}> */
    private static function eduRows(\mysqli $conn, int $memberId, string $from, string $to): array
    {
        $packetFilter = self::LEGACY_ROWS_ELIGIBLE
            ? "(gs.id IS NULL OR gs.status IN ('submitted','approved'))"
            : "(gs.id IS NOT NULL AND gs.status IN ('submitted','approved'))";
        $sql = "SELECT a.attendance_date AS d, a.status AS s, a.class_id AS ref
                FROM attendance a
                LEFT JOIN grade_submissions gs ON gs.id = (
                    SELECT g2.id FROM grade_submissions g2
                    WHERE g2.class_id = a.class_id AND g2.attendance_date = a.attendance_date
                      AND g2.submission_type = 'attendance'
                    ORDER BY g2.id DESC LIMIT 1)
                WHERE a.member_id = ? AND a.attendance_date BETWEEN ? AND ?
                  AND a.status IN ('present','absent','late','excused')
                  AND $packetFilter
                ORDER BY a.attendance_date ASC, a.class_id ASC";
        return self::fetchRows($conn, $sql, 'iss', [$memberId, $from, $to]);
    }

    /** @return array<int,array{date:string,status:string,ref:int|string|null}> */
    private static function mezmurRows(\mysqli $conn, int $memberId, string $from, string $to): array
    {
        $sectionExpr = MemberCategory::sqlSectionExpr('m');
        $packetFilter = self::LEGACY_ROWS_ELIGIBLE
            ? "(ms.id IS NULL OR ms.status IN ('submitted','approved'))"
            : "(ms.id IS NOT NULL AND ms.status IN ('submitted','approved'))";
        $sql = "SELECT COALESCE(x.attendance_date, s.session_date) AS d, x.status AS s, x.session_id AS ref
                FROM mezmur_attendance x
                JOIN members m ON m.id = x.member_id
                LEFT JOIN mezmur_sessions s ON s.id = x.session_id
                LEFT JOIN mezmur_submissions ms
                       ON ms.attendance_date = COALESCE(x.attendance_date, s.session_date)
                      AND ms.section = $sectionExpr
                WHERE x.member_id = ?
                  AND COALESCE(x.attendance_date, s.session_date) BETWEEN ? AND ?
                  AND x.status IN ('present','absent','late','excused')
                  AND $packetFilter
                ORDER BY d ASC, x.id ASC";
        return self::fetchRows($conn, $sql, 'iss', [$memberId, $from, $to]);
    }

    /** @return array<int,array{date:string,status:string,ref:mixed}> */
    private static function fetchRows(\mysqli $conn, string $sql, string $types, array $params): array
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Attendance risk query failed to prepare.');
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $d = $row['d'] !== null ? substr((string)$row['d'], 0, 10) : null;
            if ($d === null || $d === '') {
                continue;
            }
            $out[] = ['date' => $d, 'status' => strtolower((string)$row['s']), 'ref' => $row['ref']];
        }
        $stmt->close();
        return $out;
    }

    // ── pure calculations (unit-testable without a DB) ─────────────

    /**
     * Rule 2. @param array<int,array{date:string,status:string}> $rows
     * @return array<string,string> date => 'absent'|'attended' (ascending)
     */
    public static function dayOutcomes(array $rows): array
    {
        $days = [];
        foreach ($rows as $r) {
            $d = $r['date'];
            $absent = $r['status'] === 'absent';
            if (!isset($days[$d])) {
                $days[$d] = $absent ? 'absent' : 'attended';
            } elseif (!$absent) {
                $days[$d] = 'attended';
            }
        }
        ksort($days);
        return $days;
    }

    /**
     * Rule 3. $floor = first day of the current active period (streak never
     * crosses it). @param array<string,string> $days
     * @return array{length:int,start:?string,last:?string,dates:string[]}
     */
    public static function streak(array $days, ?string $floor = null): array
    {
        $dates = array_keys($days);
        rsort($dates);
        $run = [];
        foreach ($dates as $d) {
            if ($floor !== null && $d < $floor) {
                break;
            }
            if ($days[$d] !== 'absent') {
                break;
            }
            $run[] = $d;
        }
        if ($run === []) {
            return ['length' => 0, 'start' => null, 'last' => null, 'dates' => []];
        }
        sort($run);
        return ['length' => count($run), 'start' => $run[0], 'last' => $run[count($run) - 1], 'dates' => $run];
    }

    /**
     * Rule 4. @param array<int,array{date:string,status:string}> $rows
     * @return array{sessions:int,present:int,absent:int,late:int,excused:int,present_eq:float,absent_eq:float,rate:?float,band:string}
     */
    public static function rateOf(array $rows): array
    {
        $c = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
        foreach ($rows as $r) {
            $s = $r['status'];
            if (isset($c[$s])) {
                $c[$s]++;
            }
        }
        $sessions = $c['present'] + $c['absent'] + $c['late'] + $c['excused'];
        $presentEq = $c['present'] + $c['excused'] + self::LATE_WEIGHT * $c['late'];
        $absentEq = $c['absent'] + self::LATE_WEIGHT * $c['late'];
        $rate = $sessions > 0 ? round($presentEq / $sessions * 100, 2) : null;
        return $c + [
            'sessions' => $sessions,
            'present_eq' => $presentEq,
            'absent_eq' => $absentEq,
            'rate' => $rate,
            'band' => self::band($rate),
        ];
    }

    public static function band(?float $rate): string
    {
        if ($rate === null) {
            return 'no_data';
        }
        if ($rate >= self::BAND_EXCELLENT) {
            return 'excellent';
        }
        if ($rate >= self::BAND_GOOD) {
            return 'good';
        }
        if ($rate >= self::BAND_FAIR) {
            return 'fair';
        }
        return 'low';
    }

    /** Rows whose date falls inside [$from,$to]. */
    public static function sliceRows(array $rows, string $from, string $to): array
    {
        return array_values(array_filter($rows, static fn($r) => $r['date'] >= $from && $r['date'] <= $to));
    }

    // ── the risk object (rule 5) ──────────────────────────────────

    /**
     * @param array{year:int,month:int}|null $ecMonth month to report (default: month of $asOf)
     * @return array<string,mixed> {department,status,window,streak,month,year,open_warning}
     */
    public static function riskFor(\mysqli $conn, string $dept, int $memberId, ?string $asOf = null, ?array $ecMonth = null): array
    {
        $asOf = $asOf ?: date('Y-m-d');
        $window = self::yearWindow($conn, $asOf);
        $status = DepartmentStatusService::currentStatus($conn, $memberId, $dept);
        $rows = self::eligibleRows($conn, $dept, $memberId, $window['from'], min($window['to'], $asOf));

        // Streak floor: start of the current active period inside the window.
        $periods = DepartmentStatusService::activePeriods($conn, $memberId, $dept, $window['from'], min($window['to'], $asOf));
        $floor = $periods === [] ? null : $periods[count($periods) - 1]['from'];
        $days = self::dayOutcomes($rows);
        $streak = $status['status'] === 'inactive'
            ? ['length' => 0, 'start' => null, 'last' => null, 'dates' => []]
            : self::streak($days, $floor);

        $ecNow = self::ecOf($asOf);
        $month = $ecMonth ?? ['year' => $ecNow['year'], 'month' => $ecNow['month']];
        $mw = self::ecMonthWindow((int)$month['year'], (int)$month['month']);
        $monthRows = self::sliceRows($rows, $mw['from'], $mw['to']);
        $monthRate = self::rateOf($monthRows);
        $yearRate = self::rateOf($rows);

        return [
            'department' => $dept,
            'label' => self::LABELS[$dept],
            'member_id' => $memberId,
            'as_of' => $asOf,
            'status' => $status['status'],
            'status_since' => $status['since'],
            'window' => $window,
            'streak' => $streak + ['start_ec' => $streak['start'] ? self::ecLabel($streak['start']) : null,
                                   'last_ec' => $streak['last'] ? self::ecLabel($streak['last']) : null,
                                   'start_ec_am' => $streak['start'] ? self::ecLabelAm($streak['start']) : null,
                                   'last_ec_am' => $streak['last'] ? self::ecLabelAm($streak['last']) : null],
            'month' => [
                'ec_year' => (int)$month['year'],
                'ec_month' => (int)$month['month'],
                'label' => ethio_month_name((int)$month['month']) . ' ' . (int)$month['year'],
                'label_am' => self::monthLabelAm((int)$month['year'], (int)$month['month']),
                'from' => $mw['from'],
                'to' => $mw['to'],
            ] + $monthRate,
            'year' => ['ec_year' => $window['ec_year']] + $yearRate,
            'open_warning' => null, // filled by AttendanceWarningService::decorate()
        ];
    }

    /** EC label for display (transliterated month, system convention), e.g. "Tikimt 12, 2019". */
    public static function ecLabel(string $date): string
    {
        return ethio_date_format($date, 'F j, Y');
    }

    /** EC label with the Amharic month name, e.g. "ጥቅምት 12, 2019". */
    public static function ecLabelAm(string $date): string
    {
        $e = self::ecOf($date);
        return (self::MONTHS_AM[$e['month']] ?? '') . ' ' . $e['day'] . ', ' . $e['year'];
    }

    public static function monthLabelAm(int $ecYear, int $ecMonth): string
    {
        return (self::MONTHS_AM[$ecMonth] ?? '') . ' ' . $ecYear;
    }
}
