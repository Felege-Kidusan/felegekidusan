<?php
// CLI-only deployment tooling; deny before loading credentials or data.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Attendance alerting — Phase 2 rollout back-fill (run by the owner, in batches).
 *
 * For every non-archived member × department (Education, Mezmur) it computes
 * the current consecutive-absence streak and the current Ethiopian month over
 * the current Ethiopian academic year, using exactly the same rules as the
 * live evaluator (AttendanceRiskService / AttendanceWarningService: packet
 * eligibility, active periods, holidays excluded, late/excused break streaks),
 * and opens warnings with `source = 'rollout'` where the policy thresholds
 * are met. Rollout rows NEVER send a bell (they appear in the list flagged
 * "system-generated at rollout"). Idempotent (dedupe keys): re-running
 * changes nothing. Never writes `members.status`. Never deletes anything.
 *
 * Owner decisions 2026-10-19: existing `members.status` rows are left
 * unchanged; valid historical rows WITHOUT a packet stay eligible
 * (LEGACY_ROWS_ELIGIBLE) after the records are verified; apply runs in
 * `--limit=` batches with safeguards.
 *
 * Modes (from the repo root, on the server):
 *   php scripts/attendance_rollout_2026.php --verify          # 1. read-only data check of the historical rows
 *   php scripts/attendance_rollout_2026.php                   # 2. DRY RUN — prints what would open
 *   php scripts/attendance_rollout_2026.php --apply --limit=50   # 3. first batch (asks for confirmation)
 *   php scripts/attendance_rollout_2026.php --apply --limit=50 --after-id=<printed cursor>   # next batch …
 *
 * Options:
 *   --department=edu|mezmur   one department only (default both)
 *   --as-of=YYYY-MM-DD        evaluation date (default today)
 *   --limit=N                 members per batch, ordered by member id (0 = all)
 *   --after-id=N              resume cursor: only members with id > N (printed at the end of each batch)
 *   --max-open=N              safeguard: stop the apply batch after N new warnings (default 100; 0 = no cap)
 *   --max-errors=N            safeguard: abort the apply batch after N member errors (default 5)
 *   --yes                     skip the interactive "type APPLY" confirmation (for non-interactive shells)
 *   --quiet                   summary only
 *
 * Requirements: sql/071 + sql/072 applied and FEATURE_ATTENDANCE_V2 = true
 * (the evaluator is a no-op otherwise — the script tells you).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/backend/services/AttendanceWarningService.php';
while (ob_get_level() > 0) { @ob_end_clean(); }

use App\Services\AttendancePolicyService;
use App\Services\AttendanceRiskService;
use App\Services\AttendanceWarningService;
use App\Services\MemberCategory;
use App\Services\SecurityAuditService;

$opts = getopt('', ['apply', 'verify', 'department:', 'as-of:', 'limit:', 'after-id:', 'max-open:', 'max-errors:', 'yes', 'quiet']);
$apply = array_key_exists('apply', $opts);
$verify = array_key_exists('verify', $opts);
$quiet = array_key_exists('quiet', $opts);
$yes = array_key_exists('yes', $opts);
$asOf = isset($opts['as-of']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$opts['as-of']) ? (string)$opts['as-of'] : date('Y-m-d');
$limit = isset($opts['limit']) ? max(0, (int)$opts['limit']) : 0;
$afterId = isset($opts['after-id']) ? max(0, (int)$opts['after-id']) : 0;
$maxOpen = isset($opts['max-open']) ? max(0, (int)$opts['max-open']) : 100;
$maxErrors = isset($opts['max-errors']) ? max(1, (int)$opts['max-errors']) : 5;
if ($apply && $verify) {
    fwrite(STDERR, "--verify is read-only; do not combine it with --apply.\n");
    exit(2);
}
$departments = AttendanceRiskService::DEPARTMENTS;
if (isset($opts['department'])) {
    $d = (string)$opts['department'];
    if (!in_array($d, $departments, true)) {
        fwrite(STDERR, "--department must be edu or mezmur\n");
        exit(2);
    }
    $departments = [$d];
}

$out = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $line . "\n");
    }
};
$say = static function (string $line): void { fwrite(STDOUT, $line . "\n"); };

$mode = $verify ? 'VERIFY (read-only data check)' : ($apply ? 'APPLY' : 'DRY RUN (nothing written; add --apply)');
$say('Attendance alerting rollout — ' . $mode);
$say('As of: ' . AttendanceRiskService::ecLabel($asOf) . ' EC (' . $asOf . ')');
if (!AttendanceWarningService::enabled()) {
    fwrite(STDERR, "FEATURE_ATTENDANCE_V2 is OFF — enable it in .fkss_env.php / config before running.\n");
    exit(3);
}
if (!AttendanceWarningService::tableExists($conn)) {
    fwrite(STDERR, "attendance_warnings tables missing — apply sql/072_attendance_warnings.sql first.\n");
    exit(3);
}

// ── safeguard: one run at a time ─────────────────────────────────────────────
$lockPath = sys_get_temp_dir() . '/ssms_attendance_rollout.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another rollout run is in progress (lock: $lockPath). Wait for it to finish.\n");
    exit(4);
}

$window = AttendanceRiskService::yearWindow($conn, $asOf);
$say('Academic-year window: ' . $window['label'] . ' (' . AttendanceRiskService::ecLabel($window['from']) . ' – ' . AttendanceRiskService::ecLabel($window['to']) . ')');
foreach ($departments as $d) {
    $p = AttendancePolicyService::for($conn, $d);
    $out(sprintf('Policy %-7s consecutive %d / prolonged %d / critical %d · auto-deactivate %s · low month < %s%% (min %d sessions)%s',
        $d, $p['consecutive_threshold'], $p['prolonged_threshold'], $p['critical_threshold'],
        $p['auto_deactivate'] ? 'ON' : 'off', $p['low_rate_threshold'], $p['min_sessions'], $p['is_default'] ? ' [defaults]' : ''));
}
$out('Legacy rows (attendance saved before Phase 2, no packet): ' . (AttendanceRiskService::legacyRowsEligible() ? 'ELIGIBLE (owner decision 2026-10-19)' : 'ignored'));

/** One-row scalar/assoc helper for the verification queries. */
$q1 = static function (string $sql, string $types = '', array $params = []) use ($conn): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new \RuntimeException('verify query failed to prepare');
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
};
$qAll = static function (string $sql, string $types = '', array $params = []) use ($conn): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new \RuntimeException('verify query failed to prepare');
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};

// ═════════════════════════════════════════════════════════════════════════════
// --verify : read-only integrity report of the rows the rollout will read
// ═════════════════════════════════════════════════════════════════════════════
if ($verify) {
    $from = $window['from'];
    $to = $window['to'];
    $flags = 0;
    $flag = static function (int $n, string $text) use (&$flags, $say): void {
        if ($n > 0) {
            $flags++;
            $say('  ⚠ ' . $text);
        } else {
            $say('  ✓ ' . $text);
        }
    };
    $eduPacket = "(SELECT g2.status FROM grade_submissions g2
                    WHERE g2.class_id = a.class_id AND g2.attendance_date = a.attendance_date
                      AND g2.submission_type = 'attendance' ORDER BY g2.id DESC LIMIT 1)";

    if (in_array('edu', $departments, true)) {
        $say('');
        $say('EDUCATION — attendance rows in the window');
        $t = $q1("SELECT COUNT(*) n,
                         SUM(CASE WHEN $eduPacket IS NULL THEN 1 ELSE 0 END) legacy,
                         SUM(CASE WHEN $eduPacket IN ('submitted','approved') THEN 1 ELSE 0 END) eligible,
                         SUM(CASE WHEN $eduPacket IS NOT NULL AND $eduPacket NOT IN ('submitted','approved') THEN 1 ELSE 0 END) ignored,
                         SUM(CASE WHEN a.status = 'holiday' THEN 1 ELSE 0 END) holiday,
                         MIN(CASE WHEN $eduPacket IS NULL THEN a.attendance_date END) legacy_from,
                         MAX(CASE WHEN $eduPacket IS NULL THEN a.attendance_date END) legacy_to,
                         COUNT(DISTINCT CASE WHEN $eduPacket IS NULL THEN a.attendance_date END) legacy_days,
                         COUNT(DISTINCT CASE WHEN $eduPacket IS NULL THEN a.class_id END) legacy_classes
                  FROM attendance a WHERE a.attendance_date BETWEEN ? AND ?", 'ss', [$from, $to]);
        $say(sprintf('  rows %d · with submitted/approved packet %d · legacy (no packet) %d · under draft/rejected packet (ignored) %d · holiday rows (ignored) %d',
            (int)$t['n'], (int)$t['eligible'], (int)$t['legacy'], (int)$t['ignored'], (int)$t['holiday']));
        if ((int)$t['legacy'] > 0) {
            $say(sprintf('  legacy rows span %s – %s · %d distinct days · %d classes',
                AttendanceRiskService::ecLabel((string)$t['legacy_from']), AttendanceRiskService::ecLabel((string)$t['legacy_to']),
                (int)$t['legacy_days'], (int)$t['legacy_classes']));
        }
        $say('  checks:');
        $n = (int)($q1("SELECT COUNT(*) n FROM attendance a WHERE a.attendance_date BETWEEN ? AND ? AND a.attendance_date > ?", 'sss', [$from, $to, $asOf])['n'] ?? 0);
        $flag($n, "$n rows dated after the as-of date (future dates)");
        $n = (int)($q1("SELECT COUNT(*) n FROM attendance a LEFT JOIN classes c ON c.id = a.class_id WHERE a.attendance_date BETWEEN ? AND ? AND c.id IS NULL", 'ss', [$from, $to])['n'] ?? 0);
        $flag($n, "$n rows whose class no longer exists");
        $n = (int)($q1("SELECT COUNT(*) n FROM attendance a LEFT JOIN members m ON m.id = a.member_id WHERE a.attendance_date BETWEEN ? AND ? AND m.id IS NULL", 'ss', [$from, $to])['n'] ?? 0);
        $flag($n, "$n rows whose member no longer exists");
        $n = (int)($q1("SELECT COUNT(*) n FROM attendance a JOIN members m ON m.id = a.member_id WHERE a.attendance_date BETWEEN ? AND ? AND m.status = 'archived'", 'ss', [$from, $to])['n'] ?? 0);
        $say("  · $n rows belong to archived members (skipped by the rollout, nothing to fix)");
        $n = (int)($q1("SELECT COUNT(*) n FROM attendance a WHERE a.attendance_date BETWEEN ? AND ?
                          AND NOT EXISTS (SELECT 1 FROM class_enrollments ce WHERE ce.member_id = a.member_id AND ce.class_id = a.class_id)", 'ss', [$from, $to])['n'] ?? 0);
        $flag($n, "$n rows for a member with no enrollment (any status) in that class");
        $n = (int)($q1("SELECT COUNT(*) n FROM attendance a WHERE a.attendance_date BETWEEN ? AND ? AND $eduPacket IS NULL AND (a.recorded_by IS NULL OR a.recorded_by = 0)", 'ss', [$from, $to])['n'] ?? 0);
        $flag($n, "$n legacy rows with no recorder (recorded_by empty)");
        // Class-wide all-absent days: the classic sign of a sheet saved blank or
        // a holiday marked as absences. These would manufacture streaks.
        $blank = $qAll("SELECT a.class_id, a.attendance_date, COUNT(*) n
                        FROM attendance a
                        WHERE a.attendance_date BETWEEN ? AND ? AND a.status IN ('present','absent','late','excused')
                        GROUP BY a.class_id, a.attendance_date
                        HAVING SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) = COUNT(*) AND COUNT(*) >= 3
                        ORDER BY a.attendance_date DESC LIMIT 200", 'ss', [$from, $to]);
        $flag(count($blank), count($blank) . ' class-days where EVERY student is marked absent (blank sheet or holiday saved as absences?)');
        foreach (array_slice($blank, 0, 15) as $b) {
            $say(sprintf('      class #%d · %s · %d students', (int)$b['class_id'], AttendanceRiskService::ecLabel((string)$b['attendance_date']), (int)$b['n']));
        }
        if (count($blank) > 15) {
            $say('      … ' . (count($blank) - 15) . ' more');
        }
    }

    if (in_array('mezmur', $departments, true)) {
        $say('');
        $say('MEZMUR — mezmur_attendance rows in the window');
        $sectionExpr = MemberCategory::sqlSectionExpr('m');
        $base = "FROM mezmur_attendance x
                 LEFT JOIN members m ON m.id = x.member_id
                 LEFT JOIN mezmur_sessions s ON s.id = x.session_id
                 LEFT JOIN mezmur_submissions ms ON ms.attendance_date = COALESCE(x.attendance_date, s.session_date) AND ms.section = $sectionExpr
                 WHERE COALESCE(x.attendance_date, s.session_date) BETWEEN ? AND ?";
        $t = $q1("SELECT COUNT(*) n,
                         SUM(CASE WHEN ms.id IS NULL THEN 1 ELSE 0 END) legacy,
                         SUM(CASE WHEN ms.status IN ('submitted','approved') THEN 1 ELSE 0 END) eligible,
                         SUM(CASE WHEN ms.id IS NOT NULL AND ms.status NOT IN ('submitted','approved') THEN 1 ELSE 0 END) ignored,
                         SUM(CASE WHEN x.session_id IS NULL THEN 1 ELSE 0 END) no_session,
                         MIN(CASE WHEN ms.id IS NULL THEN COALESCE(x.attendance_date, s.session_date) END) legacy_from,
                         MAX(CASE WHEN ms.id IS NULL THEN COALESCE(x.attendance_date, s.session_date) END) legacy_to,
                         COUNT(DISTINCT CASE WHEN ms.id IS NULL THEN COALESCE(x.attendance_date, s.session_date) END) legacy_days
                  $base", 'ss', [$from, $to]);
        $say(sprintf('  rows %d · with submitted/approved packet %d · legacy (no packet) %d · under draft/rejected packet (ignored) %d · rows without a session link %d',
            (int)$t['n'], (int)$t['eligible'], (int)$t['legacy'], (int)$t['ignored'], (int)$t['no_session']));
        if ((int)$t['legacy'] > 0) {
            $say(sprintf('  legacy rows span %s – %s · %d distinct days',
                AttendanceRiskService::ecLabel((string)$t['legacy_from']), AttendanceRiskService::ecLabel((string)$t['legacy_to']), (int)$t['legacy_days']));
        }
        $say('  checks:');
        $n = (int)($q1("SELECT COUNT(*) n $base AND COALESCE(x.attendance_date, s.session_date) > ?", 'sss', [$from, $to, $asOf])['n'] ?? 0);
        $flag($n, "$n rows dated after the as-of date (future dates)");
        $n = (int)($q1("SELECT COUNT(*) n FROM mezmur_attendance x WHERE x.attendance_date IS NULL AND (x.session_id IS NULL OR NOT EXISTS (SELECT 1 FROM mezmur_sessions s WHERE s.id = x.session_id))")['n'] ?? 0);
        $flag($n, "$n rows with no usable date (no attendance_date and no session) — invisible to the rollout");
        $n = (int)($q1("SELECT COUNT(*) n $base AND m.id IS NULL", 'ss', [$from, $to])['n'] ?? 0);
        $flag($n, "$n rows whose member no longer exists");
        $n = (int)($q1("SELECT COUNT(*) n $base AND m.status = 'archived'", 'ss', [$from, $to])['n'] ?? 0);
        $say("  · $n rows belong to archived members (skipped by the rollout, nothing to fix)");
        $n = (int)($q1("SELECT COUNT(*) n $base AND x.session_id IS NOT NULL AND s.id IS NULL", 'ss', [$from, $to])['n'] ?? 0);
        $flag($n, "$n rows pointing at a deleted/missing session");
        $n = (int)($q1("SELECT COUNT(*) n $base AND $sectionExpr = '—'", 'ss', [$from, $to])['n'] ?? 0);
        $flag($n, "$n rows for members with no Mezmur section (never matched to a packet)");
        $blank = $qAll("SELECT $sectionExpr sec, COALESCE(x.attendance_date, s.session_date) d, COUNT(*) n
                        FROM mezmur_attendance x JOIN members m ON m.id = x.member_id LEFT JOIN mezmur_sessions s ON s.id = x.session_id
                        WHERE COALESCE(x.attendance_date, s.session_date) BETWEEN ? AND ? AND x.status IN ('present','absent','late','excused')
                        GROUP BY sec, d
                        HAVING SUM(CASE WHEN x.status = 'absent' THEN 1 ELSE 0 END) = COUNT(*) AND COUNT(*) >= 3
                        ORDER BY d DESC LIMIT 200", 'ss', [$from, $to]);
        $flag(count($blank), count($blank) . ' section-days where EVERY member is marked absent (blank sheet or holiday saved as absences?)');
        foreach (array_slice($blank, 0, 15) as $b) {
            $say(sprintf('      %s · %s · %d members', (string)$b['sec'], AttendanceRiskService::ecLabel((string)$b['d']), (int)$b['n']));
        }
        if (count($blank) > 15) {
            $say('      … ' . (count($blank) - 15) . ' more');
        }
    }

    // Which would-open warnings depend on legacy rows? Computed twice per
    // candidate: with legacy rows (production setting) and without.
    $say('');
    $say('Legacy dependence of the warnings a dry run would open (all non-archived members):');
    $ecNow = AttendanceRiskService::ecOf($asOf);
    $month = ['year' => $ecNow['year'], 'month' => $ecNow['month']];
    $members = $conn->query("SELECT id FROM members WHERE status <> 'archived' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    foreach ($departments as $d) {
        $policy = AttendancePolicyService::for($conn, $d);
        $withLegacy = 0; $packetOnly = 0; $legacyOnly = []; $errors = 0;
        foreach ($members as $m) {
            $mid = (int)$m['id'];
            try {
                $would = static function (array $risk) use ($policy): bool {
                    if ($risk['status'] !== 'active' || !empty($risk['open_warning'])) {
                        return false;
                    }
                    $mo = $risk['month'];
                    return (int)$risk['streak']['length'] >= (int)$policy['consecutive_threshold']
                        || ($mo['rate'] !== null && $mo['sessions'] >= (int)$policy['min_sessions'] && (float)$mo['rate'] < (float)$policy['low_rate_threshold']);
                };
                AttendanceRiskService::$legacyRowsOverride = true;
                $a = $would(AttendanceWarningService::decorate($conn, AttendanceRiskService::riskFor($conn, $d, $mid, $asOf, $month)));
                if (!$a) {
                    continue;
                }
                $withLegacy++;
                AttendanceRiskService::$legacyRowsOverride = false;
                $b = $would(AttendanceWarningService::decorate($conn, AttendanceRiskService::riskFor($conn, $d, $mid, $asOf, $month)));
                if ($b) {
                    $packetOnly++;
                } else {
                    $legacyOnly[] = $mid;
                }
            } catch (\Throwable $e) {
                $errors++;
            } finally {
                AttendanceRiskService::$legacyRowsOverride = null;
            }
        }
        $say(sprintf('  %-7s would open %d · also provable from packet-backed rows alone %d · depend on legacy rows %d%s',
            $d, $withLegacy, $packetOnly, count($legacyOnly), $errors ? " · errors $errors" : ''));
        if ($legacyOnly !== []) {
            $say('      legacy-dependent member ids: ' . implode(', ', array_slice($legacyOnly, 0, 60)) . (count($legacyOnly) > 60 ? ' …' : ''));
        }
    }
    AttendanceRiskService::$legacyRowsOverride = null;

    $say('');
    if ($flags === 0) {
        $say('VERDICT: no data problems found. Historical rows look valid — proceed with the dry run, then --apply in batches.');
    } else {
        $say("VERDICT: $flags check(s) flagged. Review them with Education / Mezmur (correct through their screens — this tool never deletes or edits attendance), then re-run --verify. Rollout warnings opened from flagged days can also be closed later with outcome \"Excused\".");
    }
    flock($lock, LOCK_UN);
    exit($flags === 0 ? 0 : 1);
}

// ═════════════════════════════════════════════════════════════════════════════
// dry run / apply
// ═════════════════════════════════════════════════════════════════════════════
$sql = "SELECT id, member_code, student_name, father_name FROM members WHERE status <> 'archived' AND id > ? ORDER BY id";
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $afterId);
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$remaining = (int)($q1("SELECT COUNT(*) n FROM members WHERE status <> 'archived' AND id > ?", 'i', [$afterId])['n'] ?? 0);
$say(sprintf('Batch: %d member(s)%s%s · %d non-archived member(s) remain after cursor %d',
    count($members), $limit > 0 ? " (limit $limit)" : '', $afterId > 0 ? " after id $afterId" : '', $remaining, $afterId));
if ($members === []) {
    $say('Nothing to scan. Done.');
    flock($lock, LOCK_UN);
    exit(0);
}
$lastId = (int)end($members)['id'];
reset($members);

$ecNow = AttendanceRiskService::ecOf($asOf);
$month = ['year' => $ecNow['year'], 'month' => $ecNow['month']];
$policies = [];
foreach ($departments as $d) {
    $policies[$d] = AttendancePolicyService::for($conn, $d);
}

/**
 * Pass 1 (always): dry-run plan for this batch. Pass 2 (apply only): write.
 * The plan is shown before anything is written so the owner confirms counts.
 */
$plan = static function () use ($members, $departments, $policies, $conn, $asOf, $month): array {
    $summary = [];
    $lines = [];
    foreach ($departments as $d) {
        $summary[$d] = ['scanned' => 0, 'with_sessions' => 0, 'consecutive' => 0, 'low_month' => 0, 'already' => 0, 'errors' => 0,
                        'stages' => ['standard' => 0, 'prolonged' => 0, 'critical' => 0]];
    }
    foreach ($members as $m) {
        $mid = (int)$m['id'];
        $name = trim((string)$m['student_name'] . ' ' . (string)$m['father_name']);
        foreach ($departments as $d) {
            $s =& $summary[$d];
            $policy = $policies[$d];
            $s['scanned']++;
            try {
                $risk = AttendanceWarningService::decorate($conn, AttendanceRiskService::riskFor($conn, $d, $mid, $asOf, $month));
                $len = (int)$risk['streak']['length'];
                $wouldC = $risk['status'] === 'active' && $len >= (int)$policy['consecutive_threshold'];
                $mo = $risk['month'];
                $wouldM = $risk['status'] === 'active' && $mo['rate'] !== null && $mo['sessions'] >= (int)$policy['min_sessions'] && (float)$mo['rate'] < (float)$policy['low_rate_threshold'];
                if ($risk['open_warning']) { $s['already']++; }
                if ($wouldC && !$risk['open_warning']) {
                    $stage = $len >= (int)$policy['critical_threshold'] ? 'critical' : ($len >= (int)$policy['prolonged_threshold'] ? 'prolonged' : 'standard');
                    $s['consecutive']++; $s['stages'][$stage]++;
                    $lines[] = sprintf('  %-7s #%-6d %-28s %-9s %2d consecutive  %s – %s', $d, $mid, mb_substr($name, 0, 28), $stage, $len, $risk['streak']['start_ec'], $risk['streak']['last_ec']);
                }
                if ($wouldM && !$risk['open_warning']) {
                    $s['low_month']++;
                    $lines[] = sprintf('  %-7s #%-6d %-28s low month %s: %s%% (%d sessions)', $d, $mid, mb_substr($name, 0, 28), $mo['label'], $mo['rate'], $mo['sessions']);
                }
                if ((int)$risk['year']['sessions'] > 0) { $s['with_sessions']++; }
            } catch (\Throwable $e) {
                $s['errors']++;
                fwrite(STDERR, "  error {$d} #{$mid}: " . $e->getMessage() . "\n");
            }
            unset($s);
        }
    }
    return [$summary, $lines];
};
$printSummary = static function (array $summary, string $title) use ($say): void {
    $say($title);
    foreach ($summary as $d => $s) {
        $say(sprintf('  %-7s scanned %d · with sessions %d · consecutive %d (standard %d / prolonged %d / critical %d) · low month %d · already open %d · errors %d',
            $d, $s['scanned'], $s['with_sessions'], $s['consecutive'], $s['stages']['standard'], $s['stages']['prolonged'], $s['stages']['critical'],
            $s['low_month'], $s['already'], $s['errors']));
    }
};

$started = microtime(true);
[$summary, $lines] = $plan();
$planned = 0;
foreach ($summary as $s) { $planned += $s['consecutive'] + $s['low_month']; }
if ($lines !== []) {
    $out('');
    $out($apply ? 'Will open in this batch:' : 'Would open:');
    foreach ($lines as $l) { $out($l); }
}
$out('');
$printSummary($summary, ($apply ? 'Plan' : 'Summary') . ' (' . round(microtime(true) - $started, 1) . 's):');

if (!$apply) {
    $say('Dry run only. Re-run with --apply (same --limit/--after-id) to write these warnings; no bells are sent for rollout rows.');
    if ($limit > 0 && $remaining > count($members)) {
        $say("Next batch cursor: --after-id=$lastId");
    }
    flock($lock, LOCK_UN);
    exit(0);
}

// ── safeguards before writing ────────────────────────────────────────────────
$planErrors = array_sum(array_column($summary, 'errors'));
if ($planErrors >= $maxErrors) {
    fwrite(STDERR, "Aborting: $planErrors member(s) failed during planning (--max-errors=$maxErrors). Fix the errors above first.\n");
    flock($lock, LOCK_UN);
    exit(5);
}
if ($maxOpen > 0 && $planned > $maxOpen) {
    fwrite(STDERR, "Aborting: this batch would open $planned warnings, above --max-open=$maxOpen. Use a smaller --limit, or raise --max-open deliberately (0 = no cap).\n");
    flock($lock, LOCK_UN);
    exit(5);
}
if ($planned === 0) {
    $say('Nothing to open in this batch (already open or below threshold).');
    if ($limit > 0 && $remaining > count($members)) {
        $say("Next batch cursor: --after-id=$lastId");
    }
    flock($lock, LOCK_UN);
    exit(0);
}
if (!$yes) {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "Refusing to write without confirmation: re-run with --yes (non-interactive) or from an interactive shell.\n");
        flock($lock, LOCK_UN);
        exit(6);
    }
    fwrite(STDOUT, "Type APPLY to open these $planned warning(s) now (anything else aborts): ");
    $answer = trim((string)fgets(STDIN));
    if ($answer !== 'APPLY') {
        $say('Aborted. Nothing written.');
        flock($lock, LOCK_UN);
        exit(0);
    }
}

// ── write ────────────────────────────────────────────────────────────────────
$applied = [];
$opened = 0;
$errors = 0;
$stopped = null;
foreach ($departments as $d) {
    $applied[$d] = ['consecutive' => 0, 'low_month' => 0, 'already' => 0, 'errors' => 0, 'ids' => [],
                    'stages' => ['standard' => 0, 'prolonged' => 0, 'critical' => 0]];
}
foreach ($members as $m) {
    $mid = (int)$m['id'];
    foreach ($departments as $d) {
        $a =& $applied[$d];
        try {
            $res = AttendanceWarningService::evaluate($conn, $d, $mid, ['as_of' => $asOf, 'month' => $month, 'source' => 'rollout', 'notify' => false]);
            $risk = $res['risk'];
            foreach ($res['actions'] as $act) {
                if (str_starts_with($act, 'consecutive_opened')) {
                    $a['consecutive']++; $opened++;
                    $a['stages'][$risk['open_warning']['stage'] ?? 'standard']++;
                    if (!empty($risk['open_warning']['id'])) { $a['ids'][] = (int)$risk['open_warning']['id']; }
                }
                if ($act === 'low_month_opened') { $a['low_month']++; $opened++; }
            }
            if ($res['actions'] === [] && $risk && $risk['open_warning']) { $a['already']++; }
        } catch (\Throwable $e) {
            $a['errors']++; $errors++;
            fwrite(STDERR, "  error {$d} #{$mid}: " . $e->getMessage() . "\n");
        }
        unset($a);
        if ($errors >= $maxErrors) {
            $stopped = "error cap --max-errors=$maxErrors reached at member #$mid";
            break 2;
        }
        if ($maxOpen > 0 && $opened >= $maxOpen) {
            $stopped = "open cap --max-open=$maxOpen reached at member #$mid";
            break 2;
        }
    }
    $lastId = $mid;
}

$say('');
$say('Applied (' . round(microtime(true) - $started, 1) . 's):');
foreach ($applied as $d => $a) {
    $say(sprintf('  %-7s opened consecutive %d (standard %d / prolonged %d / critical %d) · low month %d · already open %d · errors %d',
        $d, $a['consecutive'], $a['stages']['standard'], $a['stages']['prolonged'], $a['stages']['critical'], $a['low_month'], $a['already'], $a['errors']));
}
if ($stopped !== null) {
    $say("Stopped early: $stopped. Re-run the same command with --after-id=$lastId to continue (already-opened members are skipped).");
}
try {
    SecurityAuditService::record($conn, 'Attendance Warnings Rollout', [
        'as_of' => $asOf, 'departments' => $departments, 'limit' => $limit, 'after_id' => $afterId, 'last_id' => $lastId,
        'max_open' => $maxOpen, 'opened' => $opened, 'stopped' => $stopped, 'summary' => $applied,
    ], 'system', 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'audit failed: ' . $e->getMessage() . "\n");
}
$say('Done. Rollout rows carry source=rollout and sent no notifications.');
if ($stopped !== null || ($limit > 0 && $remaining > count($members))) {
    $say("Next batch cursor: --after-id=$lastId");
}
flock($lock, LOCK_UN);
exit($stopped !== null && $errors >= $maxErrors ? 5 : 0);
