<?php
// CLI-only deployment tooling; deny before loading credentials or data.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Attendance alerting — Phase 2 rollout back-fill (run ONCE by the owner).
 *
 * For every non-archived member × department (Education, Mezmur) it computes
 * the current consecutive-absence streak and the current Ethiopian month over
 * the current Ethiopian academic year, using exactly the same rules as the
 * live evaluator (AttendanceRiskService / AttendanceWarningService: packet
 * eligibility, active periods, holidays excluded, late/excused break streaks),
 * and opens warnings with `source = 'rollout'` where the policy thresholds
 * are met. Rollout rows NEVER send a bell (they appear in the list flagged
 * "system-generated at rollout"). Idempotent (dedupe keys): re-running
 * changes nothing. Never writes `members.status`.
 *
 * Usage (from the repo root, on the server):
 *   php tools/attendance_rollout_2026.php                 # DRY RUN — prints what would open
 *   php tools/attendance_rollout_2026.php --apply         # writes the warnings
 *   php tools/attendance_rollout_2026.php --department=edu|mezmur
 *   php tools/attendance_rollout_2026.php --as-of=YYYY-MM-DD   (default: today)
 *   php tools/attendance_rollout_2026.php --limit=N       # first N members (smoke test)
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
use App\Services\SecurityAuditService;

$opts = getopt('', ['apply', 'department:', 'as-of:', 'limit:', 'quiet']);
$apply = array_key_exists('apply', $opts);
$quiet = array_key_exists('quiet', $opts);
$asOf = isset($opts['as-of']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$opts['as-of']) ? (string)$opts['as-of'] : date('Y-m-d');
$limit = isset($opts['limit']) ? max(0, (int)$opts['limit']) : 0;
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

$out('Attendance alerting rollout — ' . ($apply ? 'APPLY' : 'DRY RUN (nothing written; add --apply)'));
$out('As of: ' . $asOf . ' (' . AttendanceRiskService::ecLabel($asOf) . ' EC)');
if (!AttendanceWarningService::enabled()) {
    fwrite(STDERR, "FEATURE_ATTENDANCE_V2 is OFF — enable it in .fkss_env.php / config before running.\n");
    exit(3);
}
if (!AttendanceWarningService::tableExists($conn)) {
    fwrite(STDERR, "attendance_warnings tables missing — apply sql/072_attendance_warnings.sql first.\n");
    exit(3);
}
$window = AttendanceRiskService::yearWindow($conn, $asOf);
$out('Academic-year window: ' . $window['label'] . ' (' . AttendanceRiskService::ecLabel($window['from']) . ' – ' . AttendanceRiskService::ecLabel($window['to']) . ')');
foreach ($departments as $d) {
    $p = AttendancePolicyService::for($conn, $d);
    $out(sprintf('Policy %-7s consecutive %d / prolonged %d / critical %d · auto-deactivate %s · low month < %s%% (min %d sessions)%s',
        $d, $p['consecutive_threshold'], $p['prolonged_threshold'], $p['critical_threshold'],
        $p['auto_deactivate'] ? 'ON' : 'off', $p['low_rate_threshold'], $p['min_sessions'], $p['is_default'] ? ' [defaults]' : ''));
}

$sql = "SELECT id, member_code, student_name, father_name FROM members WHERE status <> 'archived' ORDER BY id";
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}
$members = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
$out('Members to scan: ' . count($members));

$ecNow = AttendanceRiskService::ecOf($asOf);
$month = ['year' => $ecNow['year'], 'month' => $ecNow['month']];
$summary = [];
foreach ($departments as $d) {
    $summary[$d] = ['scanned' => 0, 'with_sessions' => 0, 'consecutive' => 0, 'low_month' => 0, 'already' => 0, 'errors' => 0,
                    'stages' => ['standard' => 0, 'prolonged' => 0, 'critical' => 0]];
}
$lines = [];
$started = microtime(true);
foreach ($members as $m) {
    $mid = (int)$m['id'];
    $name = trim((string)$m['student_name'] . ' ' . (string)$m['father_name']);
    foreach ($departments as $d) {
        $s =& $summary[$d];
        $s['scanned']++;
        try {
            $policy = AttendancePolicyService::for($conn, $d);
            if ($apply) {
                $res = AttendanceWarningService::evaluate($conn, $d, $mid, ['as_of' => $asOf, 'month' => $month, 'source' => 'rollout', 'notify' => false]);
                $risk = $res['risk'];
                foreach ($res['actions'] as $a) {
                    if (str_starts_with($a, 'consecutive_opened')) { $s['consecutive']++; $s['stages'][$risk['open_warning']['stage'] ?? 'standard']++; }
                    if ($a === 'low_month_opened') { $s['low_month']++; }
                }
                if ($res['actions'] === [] && $risk && $risk['open_warning']) { $s['already']++; }
            } else {
                $risk = AttendanceRiskService::riskFor($conn, $d, $mid, $asOf, $month);
                $risk = AttendanceWarningService::decorate($conn, $risk);
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
            }
            if ($risk && (int)$risk['year']['sessions'] > 0) { $s['with_sessions']++; }
        } catch (\Throwable $e) {
            $s['errors']++;
            fwrite(STDERR, "  error {$d} #{$mid}: " . $e->getMessage() . "\n");
        }
        unset($s);
    }
}
$elapsed = round(microtime(true) - $started, 1);

if (!$apply && $lines !== []) {
    $out('');
    $out('Would open:');
    foreach ($lines as $l) { $out($l); }
}
$out('');
$out('Summary (' . $elapsed . 's):');
foreach ($summary as $d => $s) {
    $out(sprintf('  %-7s scanned %d · with sessions %d · consecutive %d (standard %d / prolonged %d / critical %d) · low month %d · already open %d · errors %d',
        $d, $s['scanned'], $s['with_sessions'], $s['consecutive'], $s['stages']['standard'], $s['stages']['prolonged'], $s['stages']['critical'],
        $s['low_month'], $s['already'], $s['errors']));
}
if ($apply) {
    try {
        SecurityAuditService::record($conn, 'Attendance Warnings Rollout', ['as_of' => $asOf, 'departments' => $departments, 'summary' => $summary], 'system', 0);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'audit failed: ' . $e->getMessage() . "\n");
    }
    $out('Done. Rollout rows carry source=rollout and sent no notifications.');
} else {
    $out('Dry run only. Re-run with --apply to write these warnings (no bells are sent for rollout rows).');
}
exit(0);
