<?php
/**
 * Single source of truth for monthly attendance summaries.
 *
 * WHY THIS SERVICE EXISTS
 * -----------------------
 * Two separate writers used to maintain `attendance_summary`:
 *   - workflow.php keyed rows on TODAY'S Gregorian month and computed
 *     rate = present / total,
 *   - api_attendance.php keyed rows on the RECORDED date's month and
 *     computed rate = (present + 0.5*late) / total.
 * Whichever endpoint saved last overwrote the same (member, year, month)
 * row with different semantics — silently corrupting attendance rates.
 *
 * DESIGN (industry-standard derived-cache pattern)
 * ------------------------------------------------
 * The `attendance` table is the only source of truth. Summary rows are a
 * DERIVED cache: every save recomputes the affected member+month FROM the
 * source rows (idempotent, self-healing, replay-safe) instead of applying
 * incremental deltas that can drift. One formula, one key, one writer:
 *
 *   key     = (member_id, academic_year_id, GC month, GC year) — derived
 *             from the RECORDED attendance_date, never from "today".
 *             The key has no class, so aggregation is per-member across
 *             classes (a transferred student's month stays whole).
 *   formula = (present + 0.5*late) / total — late counts half, matching
 *             the read-side summary UIs.
 *
 * Scale: recomputation touches only the member's own rows through the
 * uq_att_member_class_date (member_id, class_id, attendance_date) prefix
 * index — O(days recorded) regardless of roster size.
 */

namespace App\Services;

final class AttendanceSummaryService
{
    /** Monthly rate below which a low-attendance alert is raised. */
    public const LOW_ATTENDANCE_THRESHOLD = 70.0;

    /** Alerts for the same member are suppressed for this many days. */
    public const ALERT_SUPPRESSION_DAYS = 7;

    /**
     * Recompute and upsert the summary row for one member for the month
     * containing $attendanceDate. Returns the computed stats, or null when
     * the persistence layer is unavailable.
     *
     * @return array{month:int,year:int,total:int,present:int,absent:int,late:int,excused:int,rate:?float}|null
     */
    public static function recompute(
        \mysqli $conn,
        int $memberId,
        string $attendanceDate,
        ?int $academicYearId
    ): ?array {
        if ($memberId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)) {
            return null;
        }
        $timestamp = strtotime($attendanceDate);
        if ($timestamp === false) {
            return null;
        }
        $gcMonth = (int)date('n', $timestamp);
        $gcYear = (int)date('Y', $timestamp);
        $startOfMonth = date('Y-m-01', $timestamp);
        $endOfMonth = date('Y-m-t', $timestamp);

        // Member-level aggregation (summary key carries no class), bounded by
        // the member/date index prefix.
        $statement = $conn->prepare(
            "SELECT
                COUNT(*) AS total_days,
                SUM(status = 'present') AS present_days,
                SUM(status = 'absent')  AS absent_days,
                SUM(status = 'late')    AS late_days,
                SUM(status = 'excused') AS excused_days
             FROM attendance
             WHERE member_id = ?
               AND attendance_date BETWEEN ? AND ?"
        );
        if (!$statement) {
            return null;
        }
        $statement->bind_param('iss', $memberId, $startOfMonth, $endOfMonth);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($row)) {
            return null;
        }

        $totalDays = (int)$row['total_days'];
        $presentDays = (int)$row['present_days'];
        $absentDays = (int)$row['absent_days'];
        $lateDays = (int)$row['late_days'];
        $excusedDays = (int)$row['excused_days'];
        $rate = $totalDays > 0
            ? round((($presentDays + $lateDays * 0.5) / $totalDays) * 100, 2)
            : null;

        try {
            if ($academicYearId === null) {
                // NULL never participates in a UNIQUE match, so an
                // ON DUPLICATE KEY upsert would keep inserting fresh rows.
                // Replace the no-year row deterministically instead.
                $delete = $conn->prepare(
                    'DELETE FROM attendance_summary
                     WHERE member_id = ? AND academic_year_id IS NULL AND month = ? AND year = ?'
                );
                if ($delete) {
                    $delete->bind_param('iii', $memberId, $gcMonth, $gcYear);
                    $delete->execute();
                    $delete->close();
                }
                $insert = $conn->prepare(
                    'INSERT INTO attendance_summary
                        (member_id, academic_year_id, month, year,
                         total_days, present_days, absent_days, late_days, excused_days,
                         attendance_rate)
                     VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($insert) {
                    $insert->bind_param(
                        'iiiiiiiid',
                        $memberId,
                        $gcMonth,
                        $gcYear,
                        $totalDays,
                        $presentDays,
                        $absentDays,
                        $lateDays,
                        $excusedDays,
                        $rate
                    );
                    $insert->execute();
                    $insert->close();
                }
            } else {
                $upsert = $conn->prepare(
                    "INSERT INTO attendance_summary
                        (member_id, academic_year_id, month, year,
                         total_days, present_days, absent_days, late_days, excused_days,
                         attendance_rate)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        total_days = VALUES(total_days),
                        present_days = VALUES(present_days),
                        absent_days = VALUES(absent_days),
                        late_days = VALUES(late_days),
                        excused_days = VALUES(excused_days),
                        attendance_rate = VALUES(attendance_rate)"
                );
                if ($upsert) {
                    $yearValue = (int)$academicYearId;
                    $upsert->bind_param(
                        'iiiiiiiiid',
                        $memberId,
                        $yearValue,
                        $gcMonth,
                        $gcYear,
                        $totalDays,
                        $presentDays,
                        $absentDays,
                        $lateDays,
                        $excusedDays,
                        $rate
                    );
                    $upsert->execute();
                    $upsert->close();
                }
            }
        } catch (\Throwable $error) {
            // A summary failure must never fail the attendance save itself.
            error_log('Attendance summary persistence failed: ' . $error->getMessage());
        }

        return [
            'month' => $gcMonth,
            'year' => $gcYear,
            'total' => $totalDays,
            'present' => $presentDays,
            'absent' => $absentDays,
            'late' => $lateDays,
            'excused' => $excusedDays,
            'rate' => $rate,
        ];
    }

    /**
     * Refresh the member-level rollup (average of monthly rates). The
     * compatibility columns come from PHP migration 002; a deployment
     * without them simply skips this step.
     */
    public static function refreshMemberTotals(\mysqli $conn, int $memberId): void
    {
        if ($memberId <= 0) {
            return;
        }
        try {
            $statement = $conn->prepare(
                'SELECT AVG(attendance_rate) AS avg_rate
                 FROM attendance_summary
                 WHERE member_id = ? AND attendance_rate IS NOT NULL'
            );
            if (!$statement) {
                return;
            }
            $statement->bind_param('i', $memberId);
            $statement->execute();
            $average = $statement->get_result()->fetch_assoc();
            $statement->close();

            if ($average && $average['avg_rate'] !== null) {
                $update = $conn->prepare(
                    'UPDATE members
                     SET total_attendance_rate = ?, last_attendance_date = CURDATE()
                     WHERE id = ?'
                );
                if ($update) {
                    $avgRate = round((float)$average['avg_rate'], 2);
                    $update->bind_param('di', $avgRate, $memberId);
                    $update->execute();
                    $update->close();
                }
            }
        } catch (\Throwable $error) {
            // Compatibility columns may be absent; never fail the save path.
            error_log('Attendance member totals refresh skipped: ' . $error->getMessage());
        }
    }

    /**
     * Raise (at most) one low-attendance alert per member per suppression
     * window. Mirrors the dedupe pattern used by the consecutive-absence
     * alert so repeated saves never spam the bell.
     */
    public static function maybeAlertLowAttendance(\mysqli $conn, int $memberId, ?float $rate): void
    {
        if ($memberId <= 0 || $rate === null || $rate >= self::LOW_ATTENDANCE_THRESHOLD) {
            return;
        }
        try {
            $marker = '%"member_id":' . $memberId . '%';
            $recent = $conn->prepare(
                "SELECT id FROM notifications
                 WHERE type = 'attendance_issue'
                   AND data LIKE ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 LIMIT 1"
            );
            if (!$recent) {
                return;
            }
            $days = self::ALERT_SUPPRESSION_DAYS;
            $recent->bind_param('si', $marker, $days);
            $recent->execute();
            $alreadySent = $recent->get_result()->num_rows > 0;
            $recent->close();
            if ($alreadySent) {
                return;
            }

            $memberStatement = $conn->prepare(
                'SELECT student_name, father_name FROM members WHERE id = ? LIMIT 1'
            );
            if (!$memberStatement) {
                return;
            }
            $memberStatement->bind_param('i', $memberId);
            $memberStatement->execute();
            $member = $memberStatement->get_result()->fetch_assoc();
            $memberStatement->close();
            if (!$member) {
                return;
            }

            if (function_exists('sendNotification')) {
                sendNotification(
                    $conn,
                    'attendance_issue',
                    'Low Attendance Alert',
                    sprintf(
                        '%s %s has %.1f%% attendance this month',
                        (string)$member['student_name'],
                        (string)$member['father_name'],
                        $rate
                    ),
                    [
                        'priority' => 'high',
                        'data' => [
                            'member_id' => $memberId,
                            'attendance_rate' => $rate,
                        ],
                    ]
                );
            }
        } catch (\Throwable $error) {
            error_log('Low attendance alert failed: ' . $error->getMessage());
        }
    }

    /**
     * One-call convenience used by attendance writers: recompute the month
     * of the saved record, refresh the member rollup, then alert if needed.
     * Derived-cache semantics make this safe to call repeatedly.
     *
     * @return array{month:int,year:int,total:int,present:int,absent:int,late:int,excused:int,rate:?float}|null
     */
    public static function recordSaved(
        \mysqli $conn,
        int $memberId,
        string $attendanceDate,
        ?int $academicYearId
    ): ?array {
        $stats = self::recompute($conn, $memberId, $attendanceDate, $academicYearId);
        if ($stats === null) {
            return null;
        }
        self::refreshMemberTotals($conn, $memberId);
        self::maybeAlertLowAttendance($conn, $memberId, $stats['rate']);
        return $stats;
    }
}
