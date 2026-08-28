<?php
/**
 * Functional smoke test for the Information analytics hub (Phase C).
 * Runs against a live DB. Applies REAL migration 027 twice (idempotent),
 * seeds all THREE independent attendance sources, then proves:
 *   - refreshRollup rebuilds attendance_rollup correctly (per source,
 *     never merged; mezmur section derived from members; edu holiday
 *     rows excluded)
 *   - kpiBand / trends / groupTable / comparison / sourceMeta read back
 *     correct figures from the rollup only
 *   - READ-ONLY ISOLATION: source tables keep identical row counts
 *     before/after every refresh — the hub never writes source data
 *   - unknown sources are rejected (empty results, no errors)
 * Exits non-zero with a message on the first failure.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$fail = function (string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$pass = function (string $msg): void { echo "  ok: $msg\n"; };

require dirname(__DIR__, 2) . '/admin/backend/services/InfoAnalyticsService.php';

use App\Services\InfoAnalyticsService;

$conn = @new mysqli('127.0.0.1', 'ssms', 'ssms', 'ssms_smoke');
if ($conn->connect_errno) $fail('db connect: ' . $conn->connect_error);
$conn->set_charset('utf8mb4');
// Sandbox fixtures are minimal — production NOT NULL columns take
// implicit defaults (test harness only, never shipped behavior).
$conn->query("SET SESSION sql_mode = ''");

// ── migration 027 applies twice → idempotent ─────────────────
$sql = file_get_contents(dirname(__DIR__, 2) . '/sql/027_attendance_rollup.sql');
$sql === false || $sql === '' ? $fail('cannot read sql/027') : null;
foreach ([1, 2] as $round) {
    $ok = $conn->multi_query($sql);
    if (!$ok) $fail("migration round $round: " . $conn->error);
    while ($conn->more_results() && $conn->next_result()) { /* drain */ }
}
$pass('sql/027 applies cleanly twice (idempotent)');

$day = '2026-08-20';

// ── clean fixture rows (dependents first, then parents) ──────
// Hermetic: clear everything on the fixture date so leftover rows from
// other harnesses cannot skew the rollup window.
$conn->query("DELETE FROM attendance_rollup");
$conn->query("DELETE FROM mezmur_attendance WHERE attendance_date = '$day'");
$conn->query("DELETE FROM hr_attendance WHERE attendance_date = '$day'");
$conn->query("DELETE FROM attendance WHERE attendance_date = '$day'");
$conn->query("DELETE FROM mezmur_submissions WHERE attendance_date = '$day'");
$conn->query("DELETE FROM hr_submissions WHERE attendance_date = '$day'");
$conn->query("DELETE FROM members WHERE id BETWEEN 901 AND 904");
$conn->query("DELETE FROM users WHERE id BETWEEN 901 AND 904");
$conn->query("DELETE FROM classes WHERE id BETWEEN 901 AND 902");
$conn->query("DELETE FROM mezmur_sessions WHERE id = 901");

// ── fixtures ─────────────────────────────────────────────────
$conn->query("INSERT INTO users (id, username, full_name, role, password_hash) VALUES
  (901, 'mz_taker_fx', 'Mezmur Taker FX', 'mezmur_attendance_taker', '\$dummy'),
  (902, 'hr_taker_fx', 'HR Taker FX', 'hr_attendance_taker', '\$dummy'),
  (903, 'info_fx', 'Info FX', 'info_dept', '\$dummy'),
  (904, 'teacher_fx', 'Teacher FX', 'teacher', '\$dummy')") or $fail('users fixture: ' . $conn->error);

$conn->query("INSERT INTO members (id, member_code, student_name, father_name, status, current_section) VALUES
  (901, 'FX-9001', 'Abel',  'Kebede',  'active', 'ህናት'),
  (902, 'FX-9002', 'Sara',  'Alemu',   'active', 'ህናት'),
  (903, 'FX-9003', 'Yonas', 'Tessema', 'active', 'ማዕከዋይ'),
  (904, 'FX-9004', 'Ruth',  'Bekele',  'active', '')") or $fail('members fixture: ' . $conn->error);

$conn->query("INSERT INTO classes (id, class_name) VALUES
  (901, 'Grade 7A'),
  (902, 'Grade 8B')") or $fail('classes fixture: ' . $conn->error);

$conn->query("INSERT INTO mezmur_sessions (id, session_date, program_type, title, created_by) VALUES
  (901, '$day', 'rehearsal', 'FX session', 901)") or $fail('mezmur_sessions fixture: ' . $conn->error);

// Education: class-based, recorded by teacher. Includes one holiday
// row that MUST be excluded from the rollup.
$conn->query("INSERT INTO attendance
    (member_id, class_id, attendance_date, status, recorded_by) VALUES
  (901, 901, '$day', 'present', 904),
  (902, 901, '$day', 'late',    904),
  (903, 902, '$day', 'absent',  904),
  (904, 902, '$day', 'holiday', 904)") or $fail('edu attendance fixture: ' . $conn->error);

// Mezmur: section-based rows with NO section column (derived via
// members.current_section; member 904 has empty section → '—').
$conn->query("INSERT INTO mezmur_attendance
    (session_id, attendance_date, member_id, status, marked_by) VALUES
  (901, '$day', 901, 'present', 901),
  (901, '$day', 902, 'present', 901),
  (901, '$day', 904, 'absent',  901)") or $fail('mezmur attendance fixture: ' . $conn->error);

// HR: section-based rows with the section snapshotted on the row.
$conn->query("INSERT INTO hr_attendance
    (attendance_date, member_id, section, status, marked_by) VALUES
  ('$day', 901, 'ህናት',   'absent', 902),
  ('$day', 903, 'ማዕከዋይ', 'present', 902)") or $fail('hr attendance fixture: ' . $conn->error);

// Submission packets (for packets / approved_packets counters).
$conn->query("INSERT INTO mezmur_submissions
    (attendance_date, section, taker_id, status, member_count) VALUES
  ('$day', 'ህናት', 901, 'approved', 3)") or $fail('mezmur_submissions fixture: ' . $conn->error);
$conn->query("INSERT INTO hr_submissions
    (attendance_date, section, taker_id, status, member_count) VALUES
  ('$day', 'ህናት',   902, 'submitted', 2),
  ('$day', 'ማዕከዋይ', 902, 'approved',  1)") or $fail('hr_submissions fixture: ' . $conn->error);
$pass('fixtures loaded (edu + mezmur + hr sources)');

// ── READ-ONLY ISOLATION: snapshot every source table ─────────
$sourceTables = ['attendance', 'mezmur_attendance', 'hr_attendance',
                 'mezmur_submissions', 'hr_submissions', 'members', 'users', 'classes'];
$snapshot = function (mysqli $conn, array $tables): array {
    $out = [];
    foreach ($tables as $t) {
        $r = $conn->query("SELECT COUNT(*) c FROM `$t`")->fetch_assoc();
        $out[$t] = (int)$r['c'];
    }
    return $out;
};
$before = $snapshot($conn, $sourceTables);

// ── refresh #1 ───────────────────────────────────────────────
$res = InfoAnalyticsService::refreshRollup($conn);
empty($res['ok']) ? $fail('refreshRollup did not return ok') : null;
$rollupRows1 = (int)$conn->query("SELECT COUNT(*) c FROM attendance_rollup")->fetch_assoc()['c'];
$rollupRows1 > 0 ? $pass("refreshRollup built rollup (rows=$rollupRows1)")
                 : $fail('rollup empty after refresh');

// ── refresh #2 → idempotent rebuild ──────────────────────────
InfoAnalyticsService::refreshRollup($conn);
$rollupRows2 = (int)$conn->query("SELECT COUNT(*) c FROM attendance_rollup")->fetch_assoc()['c'];
$rollupRows2 === $rollupRows1 ? $pass('refresh is idempotent (same row count)')
                              : $fail("refresh not idempotent: $rollupRows1 vs $rollupRows2");

// ── isolation assertion: sources untouched ─────────────────────
$after = $snapshot($conn, $sourceTables);
foreach ($sourceTables as $t) {
    if ($before[$t] !== $after[$t]) {
        $fail("hub wrote to source table `$t`: {$before[$t]} -> {$after[$t]}");
    }
}
$pass('READ-ONLY isolation: all source tables unchanged after refreshes');

// ── KPI band ─────────────────────────────────────────────────
$kpi = InfoAnalyticsService::kpiBand($conn, $day, $day);
$bySrc = [];
foreach ($kpi['items'] as $row) $bySrc[$row['source']] = $row;
count($kpi['items']) === 3 ? $pass('kpiBand returns one row per source (never merged)')
                           : $fail('kpiBand must return exactly 3 source rows');

// edu: 3 non-holiday marks (present+late+absent), holiday excluded
$bySrc['edu']['marked'] === 3 ? $pass('edu marked=3 (holiday row excluded)')
                              : $fail('edu marked=' . $bySrc['edu']['marked'] . ' (holiday not excluded?)');
$bySrc['edu']['attended'] === 2 ? $pass('edu attended=2 (present+late)')
                                : $fail('edu attended=' . $bySrc['edu']['attended']);
abs((float)$bySrc['edu']['rate'] - 66.7) < 0.05 ? $pass('edu rate=66.7%')
                                                : $fail('edu rate=' . $bySrc['edu']['rate']);

// mezmur: 3 marks → 2 attended (present), 1 absent
$bySrc['mezmur']['marked'] === 3 ? $pass('mezmur marked=3') : $fail('mezmur marked=' . $bySrc['mezmur']['marked']);
$bySrc['mezmur']['attended'] === 2 ? $pass('mezmur attended=2') : $fail('mezmur attended=' . $bySrc['mezmur']['attended']);
$bySrc['mezmur']['packets'] === 1 ? $pass('mezmur packets=1') : $fail('mezmur packets=' . $bySrc['mezmur']['packets']);

// hr: 2 marks → 1 attended, packet counters per section
$bySrc['hr']['marked'] === 2 ? $pass('hr marked=2') : $fail('hr marked=' . $bySrc['hr']['marked']);
$bySrc['hr']['packets'] === 2 ? $pass('hr packets=2') : $fail('hr packets=' . $bySrc['hr']['packets']);
$bySrc['hr']['approved'] === 1 ? $pass('hr approved=1') : $fail('hr approved=' . $bySrc['hr']['approved']);

// ── mezmur section derivation (members.current_section) ──────
$grp = InfoAnalyticsService::groupTable($conn, 'mezmur', $day, $day);
$keys = array_map(fn($g) => $g['group_key'], $grp['items']);
sort($keys);
$keys === ['ህናት', '—'] ? $pass('mezmur groups derived from members (ህናት + empty→—)')
                       : $fail('mezmur groups: ' . implode(',', $keys));

// ── hr groups come from the row snapshot ─────────────────────
$grpHr = InfoAnalyticsService::groupTable($conn, 'hr', $day, $day);
$hrKeys = array_map(fn($g) => $g['group_key'], $grpHr['items']);
sort($hrKeys);
$hrKeys === ['ህናት', 'ማዕከዋይ'] ? $pass('hr groups use row section snapshot')
                              : $fail('hr groups: ' . implode(',', $hrKeys));

// ── edu groups are class names ───────────────────────────────
$grpEdu = InfoAnalyticsService::groupTable($conn, 'edu', $day, $day);
$eduKeys = array_map(fn($g) => $g['group_key'], $grpEdu['items']);
sort($eduKeys);
$eduKeys === ['Grade 7A', 'Grade 8B'] ? $pass('edu groups are class names')
                                      : $fail('edu groups: ' . implode(',', $eduKeys));

// ── trends ───────────────────────────────────────────────────
$trend = InfoAnalyticsService::trends($conn, 'mezmur', $day, $day);
count($trend['items']) === 1 ? $pass('mezmur trends: 1 recorded day') : $fail('mezmur trends rows=' . count($trend['items']));
$trend['items'][0]['marked'] === 3 ? $pass('mezmur trend marked=3') : $fail('mezmur trend marked=' . $trend['items'][0]['marked']);

// ── comparison keeps sources separate ────────────────────────
$cmp = InfoAnalyticsService::comparison($conn, $day, $day);
count($cmp['items']) === 3 ? $pass('comparison: side-by-side rows, never merged')
                           : $fail('comparison rows=' . count($cmp['items']));

// ── source metadata ──────────────────────────────────────────
$meta = InfoAnalyticsService::sourceMeta($conn);
count($meta['sources']) === 3 ? $pass('sourceMeta: 3 sources') : $fail('sourceMeta sources=' . count($meta['sources']));
!empty($meta['generated_at']) ? $pass('sourceMeta: generated_at stamped') : $fail('sourceMeta generated_at empty');

// ── unknown source guard (no error, empty results) ───────────
$badTrend = InfoAnalyticsService::trends($conn, 'bogus');
$badGroup = InfoAnalyticsService::groupTable($conn, 'bogus');
(count($badTrend['items']) === 0 && count($badGroup['items']) === 0)
    ? $pass('unknown source → empty results, no error')
    : $fail('unknown source not guarded');

// ── pagination budget ────────────────────────────────────────
$big = InfoAnalyticsService::groupTable($conn, 'edu', null, null, 1, 999999);
$big['per_page'] === 200 ? $pass('per_page clamped to read budget (200)')
                         : $fail('per_page clamp failed: ' . $big['per_page']);

// ── cleanup fixture rows (leave rollup of other data intact) ──
$conn->query("DELETE FROM attendance_rollup");
$conn->query("DELETE FROM mezmur_attendance WHERE attendance_date = '$day'");
$conn->query("DELETE FROM hr_attendance WHERE attendance_date = '$day'");
$conn->query("DELETE FROM attendance WHERE attendance_date = '$day'");
$conn->query("DELETE FROM mezmur_submissions WHERE attendance_date = '$day'");
$conn->query("DELETE FROM hr_submissions WHERE attendance_date = '$day'");
$conn->query("DELETE FROM members WHERE id BETWEEN 901 AND 904");
$conn->query("DELETE FROM users WHERE id BETWEEN 901 AND 904");
$conn->query("DELETE FROM classes WHERE id BETWEEN 901 AND 902");
$conn->query("DELETE FROM mezmur_sessions WHERE id = 901");
InfoAnalyticsService::refreshRollup($conn); // rebuild without fixtures

echo "ALL INFO ANALYTICS FUNCTIONAL CHECKS PASSED\n";
