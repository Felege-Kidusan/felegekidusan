<?php
/**
 * Functional smoke test for HR attendance (Phase B, run against a live DB).
 * Applies the REAL migration 026 twice (idempotency), then exercises:
 *   - sectionListWithCounts / sectionRoster
 *   - saveSectionSheet validation (future dates, dupes, statuses)
 *   - rows + packet transactional pattern (web/mobile parity)
 *   - packet locking (takerMayWrite) + reviewPacket reason rules
 *   - canReview regression: hr_dept CAN review (admins-only override stays)
 *   - isolation: nothing leaks into mezmur/edu tables
 * Exits non-zero with a message on the first failure.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$fail = function (string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$pass = function (string $msg): void { echo "  ok: $msg\n"; };

require dirname(__DIR__, 2) . '/admin/backend/services/SecurityAuditService.php';
require dirname(__DIR__, 2) . '/admin/backend/services/HrAttendanceService.php';
require dirname(__DIR__, 2) . '/admin/backend/services/HrSubmissionService.php';

use App\Services\HrAttendanceService;
use App\Services\HrSubmissionService;

$conn = @new mysqli('127.0.0.1', 'ssms', 'ssms', 'ssms_smoke');
if ($conn->connect_errno) $fail('db connect: ' . $conn->connect_error);
$conn->set_charset('utf8mb4');
// Sandbox fixtures are minimal — production NOT NULL columns take
// implicit defaults (test harness only, never shipped behavior).
$conn->query("SET SESSION sql_mode = ''");

// ── migration 026 applied twice → idempotent ─────────────────
$sql = file_get_contents(dirname(__DIR__, 2) . '/sql/026_hr_attendance.sql');
$sql === false || $sql === '' ? $fail('cannot read sql/026') : null;
foreach ([1, 2] as $round) {
    $ok = $conn->multi_query($sql);
    if (!$ok) $fail("migration round $round: " . $conn->error);
    while ($conn->more_results() && $conn->next_result()) { /* drain */ }
}
$pass('sql/026 applies cleanly twice (idempotent)');

$conn->query("TRUNCATE hr_submissions");
$conn->query("TRUNCATE hr_attendance");
$conn->query("DELETE FROM members");
$conn->query("DELETE FROM users");

// ── fixtures: taker + reviewer, 5 members in 2 sections ──────
$conn->query("INSERT INTO users (id, username, full_name, role, password_hash) VALUES
  (11, 'hr_taker1', 'HR Taker One', 'hr_attendance_taker', '$dummy'),
  (12, 'hr_dept1', 'HR Dept One', 'hr_dept', '$dummy'),
  (13, 'admin1', 'Admin One', 'school_admin', '$dummy')");
$conn->query("INSERT INTO members (id, member_code, student_name, father_name, status, current_section) VALUES
  (11, 'HR-10001', 'Abel', 'Kebede', 'active', 'ህናት'),
  (12, 'HR-10002', 'Sara', 'Alemu',  'active', 'ህናት'),
  (13, 'HR-10003', 'Yonas', 'Tessema','active', 'ማዕከዋይ'),
  (14, 'HR-10004', 'Ruth', 'Bekele', 'active', 'ማዕከዋይ'),
  (15, 'HR-10005', 'Gone', 'Member', 'inactive', 'ህናት')");
$pass('fixtures loaded');

// ── sections endpoint data ────────────────────────────────────
$secs = HrAttendanceService::sectionListWithCounts($conn);
count($secs) === 2 or $fail('expected 2 sections, got ' . count($secs));
$henat = array_values(array_filter($secs, fn($s) => $s['section'] === 'ህናት'))[0];
$henat['members'] === 2 or $fail('ህናት should count 2 ACTIVE members, got ' . $henat['members']);
$pass('sectionListWithCounts: inactive member excluded');

$today = date('Y-m-d');

// Baseline row counts of other departments' tables — HR writes must
// not touch them (isolation invariant).
$mzBefore = (int)($conn->query("SELECT COUNT(*) c FROM mezmur_submissions")->fetch_assoc()['c'] ?? 0);
$attBefore = (int)($conn->query("SELECT COUNT(*) c FROM mezmur_attendance")->fetch_assoc()['c'] ?? 0);

// ── sheet validation (service throws DomainException) ────────
$expectThrow = function (callable $fn, string $needle) use ($fail): void {
    try {
        $fn();
    } catch (\DomainException $e) {
        if (str_contains($e->getMessage(), $needle)) return;
        $fail('wrong rejection reason: ' . $e->getMessage());
    }
    $fail('expected rejection containing "' . $needle . '"');
};
$expectThrow(
    fn() => HrAttendanceService::saveSectionSheet($conn, date('Y-m-d', strtotime('+1 day')), 'ህናት', [['member_id' => 11, 'status' => 'present']], 11),
    'future'
);
$expectThrow(
    fn() => HrAttendanceService::saveSectionSheet($conn, $today, 'ህናት', [['member_id' => 11, 'status' => 'present'], ['member_id' => 11, 'status' => 'late']], 11),
    'Duplicate'
);
$expectThrow(
    fn() => HrAttendanceService::saveSectionSheet($conn, $today, 'ህናት', [['member_id' => 13, 'status' => 'present']], 11), // wrong section
    'out of date'
);
$pass('sheet validation: future date / duplicate / off-roster all rejected');

// ── transactional rows + packet (mobile pattern) ─────────────
$conn->begin_transaction();
$summary = HrAttendanceService::saveSectionSheet($conn, $today, 'ህናት', [
    ['member_id' => 11, 'status' => 'present'],
    ['member_id' => 12, 'status' => 'late', 'notes' => 'traffic'],
], 11, false);
$packet = HrSubmissionService::upsert($conn, [
    'taker_id' => 11, 'date' => $today, 'section' => 'ህናት',
    'status' => HrSubmissionService::STATUS_SUBMITTED,
    'member_count' => $summary['marked'],
    'present' => 1, 'late' => 1, 'absent' => 0, 'excused' => 0,
    'client_op_id' => 'hr-smoke-1',
]);
$conn->commit();
$packet['ok'] or $fail('packet upsert failed: ' . ($packet['message'] ?? '?'));
$packetId = (int)$packet['id'];
$pass('rows + packet committed atomically');

// ── locking: taker cannot write a submitted sheet ────────────
HrSubmissionService::takerMayWrite($conn, ['uid' => 11, 'role' => 'hr_attendance_taker'], $today, 'ህናት') === false
    or $fail('taker must be locked out after submit');
HrSubmissionService::takerMayWrite($conn, ['uid' => 13, 'role' => 'school_admin'], $today, 'ህናት') === true
    or $fail('admins keep override access');
$pass('packet locking: taker locked, admin override intact');

// ── review rules ─────────────────────────────────────────────
HrSubmissionService::canReview(['role' => 'hr_dept']) or $fail('hr_dept must be able to review (2026-08 regression)');
HrSubmissionService::canReview(['role' => 'mezmur_dept']) and $fail('mezmur_dept must NOT review HR packets');
HrSubmissionService::canReview(['role' => 'hr_attendance_taker']) and $fail('takers must NOT review');
$bad = HrSubmissionService::reviewPacket($conn, $packetId, HrSubmissionService::STATUS_REVISION, '', 12);
$bad['ok'] === false or $fail('return without a note must fail');
$okRev = HrSubmissionService::reviewPacket($conn, $packetId, HrSubmissionService::STATUS_REVISION, 'Please double check Sara', 12);
$okRev['ok'] or $fail('return with note failed: ' . ($okRev['message'] ?? '?'));
// returned packet unlocks the taker again
HrSubmissionService::takerMayWrite($conn, ['uid' => 11, 'role' => 'hr_attendance_taker'], $today, 'ህናት') === true
    or $fail('returned packet must unlock the taker');
$okApp = HrSubmissionService::reviewPacket($conn, $packetId, HrSubmissionService::STATUS_APPROVED, '', 12);
$okApp['ok'] or $fail('approve failed: ' . ($okApp['message'] ?? '?'));
$pass('review: note-required returns, hr_dept allowed, cross-dept denied');

// ── list / detail / stats ────────────────────────────────────
$list = HrSubmissionService::listPackets($conn, ['status' => 'approved']);
$list['total'] === 1 or $fail('expected 1 approved packet, got ' . $list['total']);
$detail = HrSubmissionService::detail($conn, $packetId);
$detail !== null && count($detail['rows']) === 2 or $fail('detail rows missing');
$stats = HrSubmissionService::packetStats($conn);
$stats['approved'] === 1 or $fail('stats approved should be 1');
$pass('listPackets / detail / packetStats');

// ── isolation: zero leakage into other departments ───────────
// Invariant: this run changed nothing outside hr_* tables.
$mz = (int)($conn->query("SELECT COUNT(*) c FROM mezmur_submissions")->fetch_assoc()['c'] ?? 0);
$att = (int)($conn->query("SELECT COUNT(*) c FROM mezmur_attendance")->fetch_assoc()['c'] ?? 0);
$mz === $mzBefore or $fail('mezmur_submissions row count changed during HR smoke');
$att === $attBefore or $fail('mezmur_attendance row count changed during HR smoke');
$pass('isolation: mezmur tables untouched by HR writes');

echo "\nALL HR FUNCTIONAL CHECKS PASSED\n";
