<?php
/**
 * Unit checks for DepartmentStatusService period math (Phase 1).
 * Run: php -d error_reporting=24575 tests/e2e/department_status_unit.php
 * Needs the local test DB with sql/071 applied and FEATURE_ATTENDANCE_V2 on.
 */
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SESSION = ['admin_id' => 1, 'admin_username' => 'unit', 'admin_role' => 'hr_dept'];
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/admin/backend/services/DepartmentStatusService.php';
require_once dirname(__DIR__, 2) . '/admin/backend/services/AttendancePolicyService.php';
use App\Services\DepartmentStatusService as DS;
use App\Services\AttendancePolicyService as AP;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails, $n; $n++;
    fwrite(STDOUT, ($ok ? 'PASS' : 'FAIL') . ': ' . $name . ($ok ? '' : ' — ' . $detail) . "\n");
    if (!$ok) $fails++;
}
$mid = 900016;
$conn->query("DELETE FROM member_department_periods WHERE member_id = $mid");
DS::resetCache();
check('available', DS::available($conn));
check('no row => active', DS::currentStatus($conn, $mid, 'edu')['status'] === 'active');
check('no row => active on any date', DS::isActiveOn($conn, $mid, 'edu', '2020-01-01'));
check('no row => one full active range', DS::activePeriods($conn, $mid, 'edu', '2026-01-01', '2026-01-31') === [['from' => '2026-01-01', 'to' => '2026-01-31']]);

// reason required for manual
try { DS::setStatus($conn, $mid, 'edu', 'inactive', 1, 'x'); check('short reason rejected', false); }
catch (DomainException $e) { check('short reason rejected', true); }
try { DS::setStatus($conn, $mid, 'edu', 'warning', 1, 'reason ok'); check('bad status rejected', false); }
catch (DomainException $e) { check('bad status rejected', true); }
try { DS::setStatus($conn, $mid, 'hr', 'inactive', 1, 'reason ok'); check('bad dept rejected', false); }
catch (DomainException $e) { check('bad dept rejected', true); }

$r = DS::setStatus($conn, $mid, 'edu', 'inactive', 1, 'left for a while');
check('deactivate changed', $r['changed'] && $r['previous'] === 'active' && $r['status'] === 'inactive');
check('current inactive (edu)', DS::currentStatus($conn, $mid, 'edu')['status'] === 'inactive');
check('mezmur untouched', DS::currentStatus($conn, $mid, 'mezmur')['status'] === 'active');
check('inactive today', !DS::isActiveOn($conn, $mid, 'edu', date('Y-m-d')));
check('active yesterday', DS::isActiveOn($conn, $mid, 'edu', date('Y-m-d', strtotime('-1 day'))));
$r2 = DS::setStatus($conn, $mid, 'edu', 'inactive', 1, 'again');
check('idempotent', $r2['changed'] === false);
check('one open period', (int)$conn->query("SELECT COUNT(*) FROM member_department_periods WHERE member_id=$mid AND ended_at IS NULL")->fetch_row()[0] === 1);

// exclusion SQL
$sql = "SELECT COUNT(*) FROM members m WHERE m.id = $mid" . DS::exclusionSql($conn, 'edu', 'm.id');
check('exclusion removes member today', (int)$conn->query($sql)->fetch_row()[0] === 0);
$sql = "SELECT COUNT(*) FROM members m WHERE m.id = $mid" . DS::exclusionSql($conn, 'mezmur', 'm.id');
check('mezmur exclusion keeps member', (int)$conn->query($sql)->fetch_row()[0] === 1);
$sql = "SELECT COUNT(*) FROM members m WHERE m.id = $mid" . DS::exclusionSql($conn, 'edu', 'm.id', date('Y-m-d', strtotime('-1 day')));
check('exclusion on past date keeps member', (int)$conn->query($sql)->fetch_row()[0] === 1);
try { DS::exclusionSql($conn, 'edu', 'm.id; DROP'); check('unsafe expr rejected', false); }
catch (InvalidArgumentException $e) { check('unsafe expr rejected', true); }

// same-day reactivation => day counts active, new period opened
sleep(1);
$r3 = DS::setStatus($conn, $mid, 'edu', 'active', 1, 'back');
check('reactivate changed', $r3['changed'] && $r3['previous'] === 'inactive');
check('same-day reactivation => active today', DS::isActiveOn($conn, $mid, 'edu', date('Y-m-d')));
check('two periods, one open', (int)$conn->query("SELECT COUNT(*) FROM member_department_periods WHERE member_id=$mid")->fetch_row()[0] === 2
    && (int)$conn->query("SELECT COUNT(*) FROM member_department_periods WHERE member_id=$mid AND ended_at IS NULL")->fetch_row()[0] === 1);
check('history newest first', DS::history($conn, $mid)[0]['status'] === 'active');

// activePeriods with a synthetic past inactive gap: 2026-03-10 .. 2026-03-20 (reactivated on 03-20)
$conn->query("DELETE FROM member_department_periods WHERE member_id = $mid");
$conn->query("INSERT INTO member_department_periods (member_id, department, status, started_at, ended_at, source) VALUES
  ($mid,'edu','inactive','2026-03-10 09:00:00','2026-03-20 11:00:00','manual'),
  ($mid,'edu','active','2026-03-20 11:00:00',NULL,'manual')");
$ranges = DS::activePeriods($conn, $mid, 'edu', '2026-03-01', '2026-03-31');
check('gap splits ranges', $ranges === [['from' => '2026-03-01', 'to' => '2026-03-09'], ['from' => '2026-03-20', 'to' => '2026-03-31']], json_encode($ranges));
check('inactive mid-gap', !DS::isActiveOn($conn, $mid, 'edu', '2026-03-15'));
check('active on reactivation day', DS::isActiveOn($conn, $mid, 'edu', '2026-03-20'));
check('inactive on deactivation day', !DS::isActiveOn($conn, $mid, 'edu', '2026-03-10'));
$ranges = DS::activePeriods($conn, $mid, 'edu', '2026-03-12', '2026-03-18');
check('window fully inside gap => none', $ranges === [], json_encode($ranges));
// open-ended inactive
$conn->query("UPDATE member_department_periods SET ended_at = NULL WHERE member_id=$mid AND status='inactive'");
$conn->query("DELETE FROM member_department_periods WHERE member_id=$mid AND status='active'");
$ranges = DS::activePeriods($conn, $mid, 'edu', '2026-03-01', '2026-03-31');
check('open-ended inactive truncates', $ranges === [['from' => '2026-03-01', 'to' => '2026-03-09']], json_encode($ranges));

// archived member refused
$conn->query("UPDATE members SET status='archived' WHERE id=900017");
try { DS::setStatus($conn, 900017, 'edu', 'inactive', 1, 'reason ok'); check('archived refused', false); }
catch (DomainException $e) { check('archived refused', true); }
$conn->query("UPDATE members SET status='active' WHERE id=900017");

// policy
$p = AP::for($conn, 'edu');
check('policy defaults', $p['consecutive_threshold'] === 5 && $p['prolonged_threshold'] === 8 && $p['critical_threshold'] === 10 && $p['auto_deactivate'] === 0 && abs($p['low_rate_threshold'] - 75) < 0.001 && $p['min_sessions'] === 3);
$clean = [];
check('validate ok', AP::validate(['consecutive_threshold' => 4, 'prolonged_threshold' => 7, 'critical_threshold' => 9, 'auto_deactivate' => '1', 'low_rate_threshold' => '70', 'min_sessions' => 2], $clean) === []);
check('validate order', AP::validate(['consecutive_threshold' => 8, 'prolonged_threshold' => 5, 'critical_threshold' => 9, 'low_rate_threshold' => 70, 'min_sessions' => 2], $clean) !== []);
check('validate rate', AP::validate(['consecutive_threshold' => 4, 'prolonged_threshold' => 7, 'critical_threshold' => 9, 'low_rate_threshold' => 0, 'min_sessions' => 2], $clean) !== []);
check('validate max', AP::validate(['consecutive_threshold' => 4, 'prolonged_threshold' => 7, 'critical_threshold' => 61, 'low_rate_threshold' => 70, 'min_sessions' => 2], $clean) !== []);
check('validate int', AP::validate(['consecutive_threshold' => 4.5, 'prolonged_threshold' => 7, 'critical_threshold' => 9, 'low_rate_threshold' => 70, 'min_sessions' => 2], $clean) !== []);
AP::validate(['consecutive_threshold' => 4, 'prolonged_threshold' => 7, 'critical_threshold' => 9, 'auto_deactivate' => 0, 'low_rate_threshold' => 70, 'min_sessions' => 2], $clean);
$s = AP::save($conn, 'edu', $clean, 1);
check('save changed', $s['changed'] && $s['policy']['consecutive_threshold'] === 4);
$s2 = AP::save($conn, 'edu', $clean, 1);
check('save idempotent', $s2['changed'] === false);
check('mezmur unchanged', AP::for($conn, 'mezmur')['consecutive_threshold'] === 5);
// restore defaults
AP::validate(AP::DEFAULTS, $clean); AP::save($conn, 'edu', $clean, 1);
$conn->query("DELETE FROM member_department_periods WHERE member_id = $mid");
fwrite(STDOUT, "\n$n checks, $fails failed\n");
exit($fails ? 1 : 0);
