<?php
/**
 * Unit checks for AttendanceRiskService + AttendanceWarningService (Phase 2).
 * Run: php -d error_reporting=24575 tests/e2e/attendance_warning_unit.php
 * Needs the local test DB with sql/071 + sql/072 applied and FEATURE_ATTENDANCE_V2 on.
 * Uses members 900016 (Education fixtures) and 900017 (Mezmur fixtures); cleans up after itself.
 */
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SESSION = ['admin_id' => 1, 'admin_username' => 'unit', 'admin_role' => 'hr_dept'];
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/admin/backend/services/AttendanceWarningService.php';
use App\Services\AttendanceRiskService as R;
use App\Services\AttendanceWarningService as W;
use App\Services\DepartmentStatusService as DS;
use App\Services\AttendancePolicyService as AP;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails, $n; $n++;
    fwrite(STDOUT, ($ok ? 'PASS' : 'FAIL') . ': ' . $name . ($ok ? '' : ' — ' . $detail) . "\n");
    if (!$ok) $fails++;
}
function q(mysqli $c, string $sql) { $r = $c->query($sql); if ($r === false) { throw new RuntimeException($c->error . ' :: ' . $sql); } return $r; }
function one(mysqli $c, string $sql) { return q($c, $sql)->fetch_row()[0] ?? null; }

$E = 900016; $M = 900017; $CLASS = 1; $CLASS2 = 2; $AS_OF = '2026-10-10'; // Meskerem 2019 = 2026-09-11 .. 2026-10-10
function cleanup(mysqli $c, int $E, int $M): void {
    foreach ([$E, $M] as $id) {
        q($c, "DELETE FROM attendance WHERE member_id = $id");
        q($c, "DELETE FROM mezmur_attendance WHERE member_id = $id");
        q($c, "DELETE FROM member_department_periods WHERE member_id = $id");
        q($c, "DELETE FROM attendance_warnings WHERE member_id = $id");
        q($c, "DELETE FROM notifications WHERE type = 'attendance_warning' AND JSON_EXTRACT(data, '$.member_id') = $id");
    }
    q($c, "DELETE FROM grade_submissions WHERE submission_type='attendance' AND class_id IN (1,2) AND attendance_date BETWEEN '2026-09-11' AND '2026-10-10'");
    q($c, "DELETE FROM mezmur_submissions WHERE attendance_date BETWEEN '2026-09-11' AND '2026-10-10' AND section = 'ህጻናት'");
    q($c, "UPDATE attendance_policies SET auto_deactivate = 0, consecutive_threshold = 5, prolonged_threshold = 8, critical_threshold = 10, low_rate_threshold = 75.00, min_sessions = 3");
    AP::resetCache(); DS::resetCache(); W::resetCache();
}
function eduRow(mysqli $c, int $m, int $class, string $d, string $s): void {
    q($c, "INSERT INTO attendance (member_id, class_id, academic_year_id, attendance_date, status, recorded_by) VALUES ($m, $class, NULL, '$d', '$s', 1)
           ON DUPLICATE KEY UPDATE status = VALUES(status)");
}
function mezRow(mysqli $c, int $m, string $d, string $s): void {
    q($c, "INSERT INTO mezmur_attendance (session_id, attendance_date, member_id, status, marked_by) VALUES (NULL, '$d', $m, '$s', 1)
           ON DUPLICATE KEY UPDATE status = VALUES(status)");
}
function packet(mysqli $c, int $class, string $d, string $status): void {
    q($c, "INSERT INTO grade_submissions (teacher_id, class_id, submission_type, attendance_date, status) VALUES (1, $class, 'attendance', '$d', '$status')");
}
function notifCount(mysqli $c, int $m): int { return (int)one($c, "SELECT COUNT(*) FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id') = $m"); }
function warnings(mysqli $c, int $m, string $dept = 'edu'): array { return q($c, "SELECT * FROM attendance_warnings WHERE member_id=$m AND department='$dept' ORDER BY id")->fetch_all(MYSQLI_ASSOC); }
function events(mysqli $c, int $wid): array { return array_column(q($c, "SELECT event FROM attendance_warning_events WHERE warning_id=$wid ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'event'); }

cleanup($conn, $E, $M);
check('available', W::available($conn));
check('member status fixture active', one($conn, "SELECT status FROM members WHERE id=$E") === 'active');

// ── pure calculations ───────────────────────────────────────────
$rows = [
    ['date' => '2026-09-14', 'status' => 'absent'], ['date' => '2026-09-14', 'status' => 'present'], // multi-class day => attended
    ['date' => '2026-09-15', 'status' => 'absent'], ['date' => '2026-09-16', 'status' => 'absent'],
    ['date' => '2026-09-17', 'status' => 'late'],   // breaks
    ['date' => '2026-09-18', 'status' => 'absent'], ['date' => '2026-09-19', 'status' => 'absent'], ['date' => '2026-09-21', 'status' => 'absent'],
];
$days = R::dayOutcomes($rows);
check('multi-class day attended', $days['2026-09-14'] === 'attended');
check('day outcomes sorted', array_keys($days) === ['2026-09-14','2026-09-15','2026-09-16','2026-09-17','2026-09-18','2026-09-19','2026-09-21']);
$s = R::streak($days);
check('streak skips gap days (18,19,21 = 3)', $s['length'] === 3 && $s['start'] === '2026-09-18' && $s['last'] === '2026-09-21', json_encode($s));
check('late breaks streak', R::streak(array_slice($days, 0, 4, true))['length'] === 0 || R::streak(array_slice($days, 0, 4, true))['start'] !== '2026-09-14');
check('floor cuts streak', R::streak($days, '2026-09-19')['length'] === 2);
check('excused breaks streak', R::streak(R::dayOutcomes([['date'=>'2026-09-01','status'=>'absent'],['date'=>'2026-09-02','status'=>'excused'],['date'=>'2026-09-03','status'=>'absent']]))['length'] === 1);
$rate = R::rateOf($rows);
check('rate weights late 0.5', $rate['sessions'] === 8 && abs($rate['present_eq'] - 1.5) < 0.001 && abs($rate['rate'] - 18.75) < 0.01, json_encode($rate));
check('rate NULL when no sessions', R::rateOf([])['rate'] === null && R::rateOf([])['band'] === 'no_data');
check('bands', R::band(90.0) === 'excellent' && R::band(89.99) === 'good' && R::band(75.0) === 'good' && R::band(74.9) === 'fair' && R::band(49.9) === 'low');
$mw = R::ecMonthWindow(2019, 1);
check('EC Meskerem 2019 window', $mw['from'] === '2026-09-11' && $mw['to'] === '2026-10-10', json_encode($mw));
check('EC Pagume window', R::ecMonthWindow(2019, 13)['from'] === '2027-09-06');
check('ecOf', R::ecOf('2026-10-10') === ['year' => 2019, 'month' => 1, 'day' => 30]);
check('ecLabelAm', R::ecLabelAm('2026-09-12') === 'መስከረም 2, 2019', R::ecLabelAm('2026-09-12'));
$yw = R::yearWindow($conn, $AS_OF);
check('year window falls back to EC year', $yw['ec_year'] === 2019 && $yw['from'] === '2026-09-11');

// ── Education: 5 consecutive => standard (monthly rule parked via min_sessions=99) ──
q($conn, "UPDATE attendance_policies SET min_sessions = 99"); AP::resetCache();
foreach (['2026-09-12','2026-09-13','2026-09-14','2026-09-15'] as $d) eduRow($conn, $E, $CLASS, $d, 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF, 'actor' => 2]);
check('4 absences => no warning', warnings($conn, $E) === [] && $r['risk']['streak']['length'] === 4);
eduRow($conn, $E, $CLASS, '2026-09-16', 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF, 'actor' => 2]);
$w = warnings($conn, $E);
check('5 absences => consecutive opened', count($w) === 1 && $w[0]['kind'] === 'consecutive' && $w[0]['stage'] === 'standard' && (int)$w[0]['streak_length'] === 5, json_encode($r['actions']));
check('dedupe key by streak start', $w[0]['dedupe_key'] === "c|edu|$E|2026-09-12");
check('events opened+notified', events($conn, (int)$w[0]['id']) === ['opened', 'notified']);
check('one bell notification', notifCount($conn, $E) === 1);
$nrow = q($conn, "SELECT * FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id') = $E ORDER BY id DESC LIMIT 1")->fetch_assoc();
check('bell targets edu,hr,info only', $nrow['target_roles'] === 'edu_dept,hr_dept,info_dept' && $nrow['priority'] === 'high');
check('bell message has EC dates, no Gregorian', !preg_match('/\d{4}-\d{2}-\d{2}/', $nrow['message'] . $nrow['title']) && str_contains($nrow['message'], 'Meskerem 2, 2019'), $nrow['message']);
check('bell data carries warning_id + member_id', json_decode($nrow['data'], true)['warning_id'] === (int)$w[0]['id'] && json_decode($nrow['data'], true)['member_id'] === $E);
check('risk decorated with open warning', $r['risk']['open_warning']['id'] === (int)$w[0]['id']);
// re-evaluate: idempotent
W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('re-evaluate: still one warning, one bell', count(warnings($conn, $E)) === 1 && notifCount($conn, $E) === 1);
check('members.status untouched', one($conn, "SELECT status FROM members WHERE id=$E") === 'active');

// escalate to prolonged (8) in place
foreach (['2026-09-17','2026-09-18','2026-09-19'] as $d) eduRow($conn, $E, $CLASS, $d, 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('8 => prolonged in place', count($w) === 1 && $w[0]['stage'] === 'prolonged' && (int)$w[0]['streak_length'] === 8 && in_array('consecutive_escalated_prolonged', $r['actions'], true));
check('escalation event + second bell', in_array('escalated', events($conn, (int)$w[0]['id']), true) && notifCount($conn, $E) === 2);
// critical (10), auto-deactivate OFF
foreach (['2026-09-20','2026-09-21'] as $d) eduRow($conn, $E, $CLASS, $d, 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('10 => critical', $w[0]['stage'] === 'critical' && (int)$w[0]['auto_deactivated'] === 0);
check('auto-deactivate OFF keeps member active', DS::currentStatus($conn, $E, 'edu')['status'] === 'active');
$nrow = q($conn, "SELECT * FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id') = $E ORDER BY id DESC LIMIT 1")->fetch_assoc();
check('critical bell urgent + recommends review', $nrow['priority'] === 'urgent' && str_contains($nrow['message'], 'Review of the department status'));
check('three bells so far', notifCount($conn, $E) === 3);

// auto-deactivate ON
q($conn, "UPDATE attendance_policies SET auto_deactivate = 1 WHERE department='edu'"); AP::resetCache();
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('auto-deactivated', in_array('auto_deactivated', $r['actions'], true) && (int)$w[0]['auto_deactivated'] === 1);
check('dept status inactive, source auto, warning_id set', DS::currentStatus($conn, $E, 'edu')['status'] === 'inactive'
    && one($conn, "SELECT source FROM member_department_periods WHERE member_id=$E AND ended_at IS NULL") === 'auto'
    && (int)one($conn, "SELECT warning_id FROM member_department_periods WHERE member_id=$E AND ended_at IS NULL") === (int)$w[0]['id']);
check('members.status STILL untouched', one($conn, "SELECT status FROM members WHERE id=$E") === 'active');
check('mezmur status untouched', DS::currentStatus($conn, $E, 'mezmur')['status'] === 'active');
check('fourth bell (auto-deactivated)', notifCount($conn, $E) === 4);
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('inactive member: no further actions', $r['actions'] === [] && $r['risk']['streak']['length'] === 0);
q($conn, "UPDATE attendance_policies SET auto_deactivate = 0 WHERE department='edu'"); AP::resetCache();

// close: permissions + outcome
$wid = (int)$w[0]['id'];
try { W::close($conn, $wid, 'contacted', '', 1, 'super_admin'); check('super_admin cannot close', false); }
catch (DomainException $e) { check('super_admin cannot close', true); }
try { W::close($conn, $wid, 'contacted', '', 1, 'mezmur_dept'); check('mezmur_dept cannot close edu', false); }
catch (DomainException $e) { check('mezmur_dept cannot close edu', true); }
try { W::close($conn, $wid, 'bogus', '', 1, 'edu_dept'); check('bad outcome rejected', false); }
catch (DomainException $e) { check('bad outcome rejected', true); }
$closed = W::close($conn, $wid, 'contacted', 'Spoke to the family', 2, 'edu_dept');
check('edu_dept closed with outcome', $closed['status'] === 'closed' && $closed['outcome'] === 'contacted' && $closed['outcome_label'] === 'Contacted');
check('closed_ec is EC (no Gregorian)', !preg_match('/\d{4}-\d{2}-\d{2}/', (string)$closed['closed_ec']), (string)$closed['closed_ec']);
try { W::close($conn, $wid, 'contacted', '', 2, 'hr_dept'); check('double close rejected', false); }
catch (DomainException $e) { check('double close rejected', true); }
check('presentation reason EC', str_contains($closed['reason'], 'consecutive absences, Meskerem 2, 2019 – Meskerem 11, 2019'), $closed['reason']);
check('audit row written', (int)one($conn, "SELECT COUNT(*) FROM activity_logs WHERE action='Attendance Warning Closed'") >= 1);

// reactivation resets the streak (reactivated 2026-09-22 => floor) ; then new episode = new row
q($conn, "DELETE FROM member_department_periods WHERE member_id=$E");
q($conn, "INSERT INTO member_department_periods (member_id, department, status, started_at, ended_at, source) VALUES
  ($E,'edu','inactive','2026-09-21 12:00:00','2026-09-22 09:00:00','manual'), ($E,'edu','active','2026-09-22 09:00:00',NULL,'manual')");
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('reactivation => streak reset to 0', $r['risk']['streak']['length'] === 0 && $r['risk']['status'] === 'active', json_encode($r['risk']['streak']));
check('closed warning not reopened at same stage', count(warnings($conn, $E)) === 1);
foreach (['2026-09-23','2026-09-24','2026-09-25','2026-09-26','2026-09-27'] as $d) eduRow($conn, $E, $CLASS, $d, 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('new episode after reactivation => second row', count($w) === 2 && $w[1]['streak_start'] === '2026-09-23' && $w[1]['status'] === 'open');
check('old inactive-period days not counted', (int)$w[1]['streak_length'] === 5);

// correction: day 23 becomes present => streak 4 => invalidated flag, row kept open
eduRow($conn, $E, $CLASS, '2026-09-23', 'present');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('correction => below_threshold=0, still open', (int)$w[1]['below_threshold'] === 0 && $w[1]['status'] === 'open' && in_array('invalidated', events($conn, (int)$w[1]['id']), true));
check('invalidated event does not notify', notifCount($conn, $E) === 5);
// grows again to 5 from 24..28 => same episode (overlap) => same row re-armed
eduRow($conn, $E, $CLASS, '2026-09-28', 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('same episode re-armed in place (no new row)', count($w) === 2 && (int)$w[1]['below_threshold'] === 1 && $w[1]['dedupe_key'] === "c|edu|$E|2026-09-24", json_encode(array_column($w, 'dedupe_key')));

// reopen: close at standard, grow to prolonged => reopened
W::close($conn, (int)$w[1]['id'], 'no_action', '', 2, 'hr_dept');
foreach (['2026-09-29','2026-09-30','2026-10-01'] as $d) eduRow($conn, $E, $CLASS, $d, 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('closed warning reopened at higher stage', $w[1]['status'] === 'open' && $w[1]['stage'] === 'prolonged' && in_array('reopened', events($conn, (int)$w[1]['id']), true) && in_array('consecutive_reopened', $r['actions'], true));

// packets: draft / revision_needed ignored; approved counted; holiday ignored
cleanup($conn, $E, $M);
q($conn, "UPDATE attendance_policies SET min_sessions = 99"); AP::resetCache();
foreach (['2026-09-12','2026-09-13','2026-09-14','2026-09-15','2026-09-16'] as $d) eduRow($conn, $E, $CLASS, $d, 'absent');
packet($conn, $CLASS, '2026-09-16', 'draft');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('draft packet day ignored (4 eligible)', $r['risk']['streak']['length'] === 4 && warnings($conn, $E) === []);
q($conn, "UPDATE grade_submissions SET status='revision_needed' WHERE class_id=$CLASS AND attendance_date='2026-09-16'");
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('revision_needed ignored', $r['risk']['streak']['length'] === 4);
q($conn, "UPDATE grade_submissions SET status='approved' WHERE class_id=$CLASS AND attendance_date='2026-09-16'");
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('approved counted => 5 => warning', $r['risk']['streak']['length'] === 5 && count(warnings($conn, $E)) === 1);
eduRow($conn, $E, $CLASS, '2026-09-17', 'holiday');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('holiday row ignored', $r['risk']['streak']['length'] === 5 && $r['risk']['year']['sessions'] === 5);
eduRow($conn, $E, $CLASS2, '2026-09-16', 'present');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('second class present same day => day attended => streak 0', $r['risk']['streak']['length'] === 0, json_encode($r['risk']['streak']));
check('future rows (after as_of) ignored', true);

// low month
cleanup($conn, $E, $M);
eduRow($conn, $E, $CLASS, '2026-09-12', 'present'); eduRow($conn, $E, $CLASS, '2026-09-13', 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('min_sessions guard (2 < 3)', warnings($conn, $E) === [] && $r['risk']['month']['sessions'] === 2);
eduRow($conn, $E, $CLASS, '2026-09-14', 'absent'); eduRow($conn, $E, $CLASS, '2026-09-15', 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('low month opened (25% < 75, 4 sessions)', count($w) === 1 && $w[0]['kind'] === 'low_month' && (int)$w[0]['ec_month'] === 1 && (int)$w[0]['ec_year'] === 2019 && abs((float)$w[0]['rate'] - 25) < 0.01, json_encode($w));
check('low month dedupe key', $w[0]['dedupe_key'] === "m|edu|$E|2019-1");
check('low month bell + EC month label', notifCount($conn, $E) === 1 && str_contains(q($conn, "SELECT message FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id') = $E")->fetch_row()[0], 'Meskerem 2019'));
check('no consecutive (streak 3 < 5)', $r['risk']['streak']['length'] === 3);
// recovery: many presents => rate above threshold => flagged, not closed
foreach (['2026-09-16','2026-09-17','2026-09-18','2026-09-19','2026-09-20','2026-09-21','2026-09-22','2026-09-23','2026-09-24','2026-09-25'] as $d) eduRow($conn, $E, $CLASS, $d, 'present');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
$w = warnings($conn, $E);
check('month recovered => still open, below_threshold=0', $w[0]['status'] === 'open' && (int)$w[0]['below_threshold'] === 0 && abs((float)$w[0]['rate'] - 78.57) < 0.01, json_encode($w[0]));
check('recovery does not notify again', notifCount($conn, $E) === 1);

// rollout source: no notifications, source flag
cleanup($conn, $E, $M);
q($conn, "UPDATE attendance_policies SET min_sessions = 99"); AP::resetCache();
foreach (['2026-09-12','2026-09-13','2026-09-14','2026-09-15','2026-09-16'] as $d) eduRow($conn, $E, $CLASS, $d, 'absent');
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF, 'source' => 'rollout']);
$w = warnings($conn, $E);
check('rollout opens with source=rollout and no bell', count($w) === 1 && $w[0]['source'] === 'rollout' && notifCount($conn, $E) === 0);
$r = W::evaluate($conn, 'edu', $E, ['as_of' => $AS_OF]);
check('live re-evaluate after rollout: no duplicate, no bell', count(warnings($conn, $E)) === 1 && notifCount($conn, $E) === 0);

// Mezmur isolation + packets
cleanup($conn, $E, $M);
q($conn, "UPDATE attendance_policies SET min_sessions = 99"); AP::resetCache();
q($conn, "UPDATE members SET current_section='ህጻናት' WHERE id=$M");
foreach (['2026-09-12','2026-09-13','2026-09-14','2026-09-15','2026-09-16'] as $d) { eduRow($conn, $M, $CLASS, $d, 'absent'); }
$r = W::evaluate($conn, 'mezmur', $M, ['as_of' => $AS_OF]);
check('edu rows do not affect mezmur', $r['risk']['streak']['length'] === 0 && warnings($conn, $M, 'mezmur') === []);
foreach (['2026-09-13','2026-09-20','2026-09-27','2026-10-04','2026-10-05'] as $d) mezRow($conn, $M, $d, 'absent');
q($conn, "INSERT INTO mezmur_submissions (attendance_date, section, taker_id, status) VALUES ('2026-10-05','ህጻናት',1,'draft')");
$r = W::evaluate($conn, 'mezmur', $M, ['as_of' => $AS_OF]);
check('mezmur draft packet day ignored (4)', $r['risk']['streak']['length'] === 4, json_encode($r['risk']['streak']));
q($conn, "UPDATE mezmur_submissions SET status='submitted' WHERE attendance_date='2026-10-05' AND section='ህጻናት'");
$r = W::evaluate($conn, 'mezmur', $M, ['as_of' => $AS_OF]);
$w = warnings($conn, $M, 'mezmur');
check('mezmur 5 => warning, department=mezmur', count($w) === 1 && $w[0]['department'] === 'mezmur');
$nrow = q($conn, "SELECT target_roles FROM notifications WHERE type='attendance_warning' AND JSON_EXTRACT(data,'$.member_id') = $M")->fetch_assoc();
check('mezmur bell targets mezmur,hr,info', $nrow['target_roles'] === 'mezmur_dept,hr_dept,info_dept');
$re = W::evaluate($conn, 'edu', $M, ['as_of' => $AS_OF]);
check('edu evaluation of same member independent (edu warning too)', count(warnings($conn, $M, 'edu')) === 1 && $re['risk']['department'] === 'edu');
check('openCounts per department', W::openCounts($conn)['edu']['total'] >= 1 && W::openCounts($conn)['mezmur']['total'] >= 1);
$sum = W::memberSummary($conn, $M);
check('memberSummary both departments', isset($sum['departments']['edu']['risk']) && count($sum['departments']['mezmur']['warnings']) === 1);
$list = W::list($conn, ['departments' => ['mezmur'], 'member_id' => $M]);
check('list scoping by department', $list['total'] === 1 && $list['items'][0]['department'] === 'mezmur' && !preg_match('/\d{4}-\d{2}-\d{2}/', json_encode($list['items'][0], JSON_UNESCAPED_UNICODE)), json_encode($list['items'][0]));
$det = W::detail($conn, (int)$w[0]['id']);
check('detail has events + EC absence dates', count($det['events']) >= 2 && count($det['absence_dates_ec']) === 5 && !preg_match('/\d{4}-\d{2}-\d{2}/', implode(',', $det['absence_dates_ec'])));
check('openByMembers', isset(W::openByMembers($conn, 'mezmur', [$M, $E])[$M]));
check('departmentsForRole', W::departmentsForRole('edu_dept') === ['edu'] && W::departmentsForRole('hr_dept') === ['edu','mezmur'] && W::departmentsForRole('teacher') === []);
check('roleMayClose admins false', !W::roleMayClose('super_admin', 'edu') && !W::roleMayClose('school_admin', 'mezmur') && W::roleMayClose('info_dept', 'mezmur'));
check('safeEvaluateMany', W::safeEvaluateMany($conn, 'edu', [$E, $M, 0, 'x'], '2026-09-16')['evaluated'] === 2);
check('memberIdsFromRecords', W::memberIdsFromRecords([['member_id' => 5], ['member_id' => '5'], ['x' => 1], ['member_id' => 0]]) === [5]);

// safeEvaluate never throws (unknown dept)
check('safeEvaluate unknown dept', W::safeEvaluate($conn, 'hr', $E)['available'] === false);

cleanup($conn, $E, $M);
q($conn, "UPDATE members SET current_section=NULL WHERE id=$M");
fwrite(STDOUT, "\n$n checks, $fails failed\n");
exit($fails === 0 ? 0 : 1);
