<?php
/**
 * ════════════════════════════════════════════════════════════
 * Information Department — Attendance Analytics Service (Phase C)
 * ════════════════════════════════════════════════════════════
 * The Information department is the school's analytics hub. It
 * READS all three independent attendance sources:
 *
 *   edu     class-based attendance recorded by teachers
 *   mezmur  section-based attendance recorded by mezmur takers
 *   hr      section-based attendance recorded by HR takers
 *
 * Product rules enforced here (2026-08-28):
 *   • READ-ONLY: this service exposes no member writes, no packet
 *     writes, no status changes. The only write is refreshRollup()
 *     against attendance_rollup — the hub's OWN read model.
 *   • Sources are compared, never merged: every figure keeps its
 *     source label; there is no combined member identity.
 *   • Scale: the hub reads pre-aggregated attendance_rollup rows
 *     (O(days × groups)), never full raw-table scans.
 */

namespace App\Services;

class InfoAnalyticsService
{
    public const SOURCES = ['edu', 'mezmur', 'hr'];

    public const SOURCE_LABELS = [
        'edu' => 'Education',
        'mezmur' => 'Mezmur',
        'hr' => 'HR',
    ];

    /** Read budget: analytics queries never page past this. */
    private const MAX_PER_PAGE = 200;
    private const MAX_TREND_DAYS = 366;

    // ────────────────────────────────────────────────────────────
    // ROLLUP REFRESH (the ONLY write — rebuilds the read model)
    // ────────────────────────────────────────────────────────────

    /**
     * Rebuild attendance_rollup from the three source tables.
     * Transactional: the hub either sees the old model or the new
     * one, never a half-built state. Returns summary counts.
     */
    public static function refreshRollup(\mysqli $conn): array
    {
        $inserted = 0;
        $conn->begin_transaction();
        try {
            $conn->query('DELETE FROM attendance_rollup');

            // ── Education: class-based rows from `attendance` ──
            $stmt = $conn->prepare(
                "INSERT INTO attendance_rollup
                    (source, rollup_date, group_key, packets, members_marked,
                     present_count, late_count, absent_count, excused_count, approved_packets)
                 SELECT 'edu', a.attendance_date,
                        COALESCE(c.class_name, '—'),
                        COUNT(DISTINCT CONCAT_WS('|', IFNULL(a.recorded_by,0), IFNULL(a.class_id,0))),
                        COUNT(*),
                        COALESCE(SUM(a.status = 'present'), 0),
                        COALESCE(SUM(a.status = 'late'), 0),
                        COALESCE(SUM(a.status = 'absent'), 0),
                        COALESCE(SUM(a.status = 'excused'), 0),
                        0
                 FROM attendance a
                 LEFT JOIN classes c ON c.id = a.class_id
                 WHERE a.status <> 'holiday'
                 GROUP BY a.attendance_date, COALESCE(c.class_name, '—')"
            );
            $stmt->execute();
            $inserted += (int)$conn->affected_rows;
            $stmt->close();

            // ── Mezmur + HR: section sheets from their own tables ──
            // Mezmur rows carry no section snapshot — the department
            // derives it from members.current_section (its canonical
            // expression). HR rows snapshot the section on the row.
            foreach ([['mezmur', 'mezmur_attendance', 'mezmur_submissions',
                       "SELECT ?, m.attendance_date,
                               COALESCE(NULLIF(TRIM(mb.current_section), ''), '—'),
                               0,
                               COUNT(*),
                               COALESCE(SUM(m.status = 'present'), 0),
                               COALESCE(SUM(m.status = 'late'), 0),
                               COALESCE(SUM(m.status = 'absent'), 0),
                               COALESCE(SUM(m.status = 'excused'), 0),
                               0
                        FROM `mezmur_attendance` m
                        LEFT JOIN members mb ON mb.id = m.member_id
                        GROUP BY m.attendance_date,
                                 COALESCE(NULLIF(TRIM(mb.current_section), ''), '—')"],
                      ['hr', 'hr_attendance', 'hr_submissions',
                       "SELECT ?, m.attendance_date, m.section,
                               0,
                               COUNT(*),
                               COALESCE(SUM(m.status = 'present'), 0),
                               COALESCE(SUM(m.status = 'late'), 0),
                               COALESCE(SUM(m.status = 'absent'), 0),
                               COALESCE(SUM(m.status = 'excused'), 0),
                               0
                        FROM `hr_attendance` m
                        GROUP BY m.attendance_date, m.section"]] as [$source, $attTable, $subTable, $selectSql]) {
                $stmt = $conn->prepare(
                    "INSERT INTO attendance_rollup
                        (source, rollup_date, group_key, packets, members_marked,
                         present_count, late_count, absent_count, excused_count, approved_packets)
                     $selectSql"
                );
                $stmt->bind_param('s', $source);
                $stmt->execute();
                $inserted += (int)$conn->affected_rows;
                $stmt->close();

                // Packet counters (submissions per date+section).
                $stmt = $conn->prepare(
                    "INSERT INTO attendance_rollup
                        (source, rollup_date, group_key, packets, approved_packets)
                     SELECT ?, s.attendance_date, s.section,
                            COUNT(*),
                            COALESCE(SUM(s.status = 'approved'), 0)
                     FROM `{$subTable}` s
                     GROUP BY s.attendance_date, s.section
                     ON DUPLICATE KEY UPDATE
                        packets = VALUES(packets),
                        approved_packets = VALUES(approved_packets)"
                );
                $stmt->bind_param('s', $source);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        return ['ok' => true, 'rows' => $inserted];
    }

    // ────────────────────────────────────────────────────────────
    // READ MODEL QUERIES (all bounded, all prepared)
    // ────────────────────────────────────────────────────────────

    private static function clampDate(?string $value): ?string
    {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /** Default window: last 30 days. */
    private static function window(?string $from, ?string $to): array
    {
        $to = self::clampDate($to) ?? date('Y-m-d');
        $from = self::clampDate($from)
            ?? date('Y-m-d', strtotime('-29 days', strtotime($to)));
        // Hard read budget on the window width.
        if (strtotime($to) - strtotime($from) > self::MAX_TREND_DAYS * 86400) {
            $from = date('Y-m-d', strtotime($to) - self::MAX_TREND_DAYS * 86400);
        }
        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    /**
     * KPI band — per-source totals for the window. Comparison stays
     * visible-but-separate: one row per source, never merged.
     */
    public static function kpiBand(\mysqli $conn, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = self::window($from, $to);
        $stmt = $conn->prepare(
            "SELECT source,
                    COUNT(DISTINCT rollup_date) AS days,
                    COUNT(DISTINCT group_key)   AS groups_active,
                    SUM(packets)                AS packets,
                    SUM(approved_packets)       AS approved,
                    SUM(members_marked)         AS marked,
                    SUM(present_count + late_count) AS attended,
                    SUM(present_count)          AS present,
                    SUM(late_count)             AS late,
                    SUM(absent_count)           AS absent,
                    SUM(excused_count)          AS excused
             FROM attendance_rollup
             WHERE rollup_date BETWEEN ? AND ?
             GROUP BY source"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $bySource = [];
        foreach (self::SOURCES as $s) {
            $bySource[$s] = [
                'source' => $s,
                'label' => self::SOURCE_LABELS[$s],
                'days' => 0, 'groups_active' => 0, 'packets' => 0, 'approved' => 0,
                'marked' => 0, 'attended' => 0, 'present' => 0, 'late' => 0,
                'absent' => 0, 'excused' => 0, 'rate' => null,
            ];
        }
        foreach ($rows as $r) {
            $s = (string)$r['source'];
            if (!isset($bySource[$s])) continue;
            $marked = (int)$r['marked'];
            $bySource[$s] = [
                'source' => $s,
                'label' => self::SOURCE_LABELS[$s],
                'days' => (int)$r['days'],
                'groups_active' => (int)$r['groups_active'],
                'packets' => (int)$r['packets'],
                'approved' => (int)$r['approved'],
                'marked' => $marked,
                'attended' => (int)$r['attended'],
                'present' => (int)$r['present'],
                'late' => (int)$r['late'],
                'absent' => (int)$r['absent'],
                'excused' => (int)$r['excused'],
                'rate' => $marked > 0 ? round(((int)$r['attended']) * 1000 / $marked) / 10 : null,
            ];
        }
        return ['from' => $from, 'to' => $to, 'items' => array_values($bySource)];
    }

    /** Daily trend rows for one source (bounded window). */
    public static function trends(\mysqli $conn, string $source, ?string $from = null, ?string $to = null): array
    {
        if (!in_array($source, self::SOURCES, true)) {
            return ['items' => []];
        }
        [$from, $to] = self::window($from, $to);
        $stmt = $conn->prepare(
            "SELECT rollup_date,
                    SUM(members_marked) AS marked,
                    SUM(present_count + late_count) AS attended,
                    SUM(absent_count) AS absent,
                    SUM(packets) AS packets
             FROM attendance_rollup
             WHERE source = ? AND rollup_date BETWEEN ? AND ?
             GROUP BY rollup_date
             ORDER BY rollup_date DESC
             LIMIT 400"
        );
        $stmt->bind_param('sss', $source, $from, $to);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $items = array_map(static function (array $r): array {
            $marked = (int)$r['marked'];
            $attended = (int)$r['attended'];
            return [
                'date' => $r['rollup_date'],
                'marked' => $marked,
                'attended' => $attended,
                'absent' => (int)$r['absent'],
                'packets' => (int)$r['packets'],
                'rate' => $marked > 0 ? round($attended * 1000 / $marked) / 10 : null,
            ];
        }, $rows);
        return ['source' => $source, 'from' => $from, 'to' => $to, 'items' => $items];
    }

    /** Per-group (section/class) aggregate table with pagination. */
    public static function groupTable(
        \mysqli $conn,
        string $source,
        ?string $from = null,
        ?string $to = null,
        int $page = 1,
        int $perPage = 50,
        string $sort = 'marked',
        string $dir = 'desc'
    ): array {
        if (!in_array($source, self::SOURCES, true)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        }
        [$from, $to] = self::window($from, $to);
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $sortWhitelist = ['group_key' => 'group_key', 'marked' => 'marked', 'rate' => 'rate', 'absent' => 'absent', 'days' => 'days'];
        $sortCol = $sortWhitelist[$sort] ?? 'marked';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $base = "SELECT group_key,
                        COUNT(DISTINCT rollup_date) AS days,
                        SUM(members_marked) AS marked,
                        SUM(present_count + late_count) AS attended,
                        SUM(absent_count) AS absent,
                        SUM(late_count) AS late,
                        SUM(excused_count) AS excused,
                        SUM(packets) AS packets
                 FROM attendance_rollup
                 WHERE source = ? AND rollup_date BETWEEN ? AND ?
                 GROUP BY group_key";
        $stmt = $conn->prepare($base);
        $stmt->bind_param('sss', $source, $from, $to);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Compute rate + sort in PHP on the bounded aggregate set.
        $items = array_map(static function (array $r): array {
            $marked = (int)$r['marked'];
            $attended = (int)$r['attended'];
            return [
                'group_key' => $r['group_key'],
                'days' => (int)$r['days'],
                'marked' => $marked,
                'attended' => $attended,
                'absent' => (int)$r['absent'],
                'late' => (int)$r['late'],
                'excused' => (int)$r['excused'],
                'packets' => (int)$r['packets'],
                'rate' => $marked > 0 ? round($attended * 1000 / $marked) / 10 : null,
            ];
        }, $rows);
        usort($items, static function (array $a, array $b) use ($sortCol, $dir): int {
            $cmp = $sortCol === 'group_key'
                ? strcmp((string)$a['group_key'], (string)$b['group_key'])
                : (($a[$sortCol] ?? 0) <=> ($b[$sortCol] ?? 0));
            return $dir === 'ASC' ? $cmp : -$cmp;
        });

        $total = count($items);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);
        return [
            'source' => $source, 'from' => $from, 'to' => $to,
            'items' => $slice, 'total' => $total,
            'page' => $page, 'total_pages' => $totalPages, 'per_page' => $perPage,
        ];
    }

    /**
     * Cross-department comparison — side-by-side per-source metrics.
     * The sources stay separate rows; the hub never joins their
     * member data together.
     */
    public static function comparison(\mysqli $conn, ?string $from = null, ?string $to = null): array
    {
        $kpi = self::kpiBand($conn, $from, $to);
        $items = [];
        foreach ($kpi['items'] as $row) {
            $items[] = [
                'source' => $row['source'],
                'label' => $row['label'],
                'days' => $row['days'],
                'groups_active' => $row['groups_active'],
                'marked' => $row['marked'],
                'rate' => $row['rate'],
                'absent' => $row['absent'],
                'late' => $row['late'],
                'excused' => $row['excused'],
                'packets' => $row['packets'],
                'approved' => $row['approved'],
            ];
        }
        return ['from' => $kpi['from'], 'to' => $kpi['to'], 'items' => $items];
    }

    /** Filter metadata: groups per source (for the UI pickers). */
    public static function sourceMeta(\mysqli $conn): array
    {
        $out = ['sources' => [], 'generated_at' => null];
        $res = $conn->query('SELECT MAX(refreshed_at) m FROM attendance_rollup');
        if ($res) {
            $out['generated_at'] = $res->fetch_assoc()['m'] ?? null;
            $res->close();
        }
        foreach (self::SOURCES as $s) {
            $stmt = $conn->prepare(
                'SELECT DISTINCT group_key FROM attendance_rollup
                 WHERE source = ? ORDER BY group_key LIMIT 300'
            );
            $stmt->bind_param('s', $s);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $out['sources'][] = [
                'source' => $s,
                'label' => self::SOURCE_LABELS[$s],
                'groups' => array_map(static fn($r) => $r['group_key'], $rows),
            ];
        }
        return $out;
    }
}
