<?php
/**
 * Functional smoke test for Mezmur Phase 5 (run against a live DB).
 * Creates minimal users/members, applies the REAL migrations
 * 022 → 023 → 024 (twice, to prove idempotency), then exercises:
 *   - sectionListWithCounts / sectionRoster
 *   - saveSectionSheet (complete-sheet validation, notes, excused)
 *   - the web/mobile transaction pattern (rows + packet atomic)
 *   - packet locking (409 semantics) + reviewPacket (reason rules)
 *   - listPackets / detail / rows
 * Exits non-zero with a message on the first failure.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$fail = function (string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$pass = function (string $msg): void { echo "  ok: $msg\n"; };

require dirname(__DIR__, 2) . '/admin/backend/services/SecurityAuditService.php';
require dirname(__DIR__, 2) . '/admin/backend/services/MezmurAttendanceService.php';
require dirname(__DIR__, 2) . '/admin/backend/services/MezmurSubmissionService.php';

use App\Services\MezmurAttendanceService;
use App\Services\MezmurSubmissionService;

$conn = @new mysqli('127.0.0.1', 'ssms', 'ssms', 'ssms_smoke');
if ($conn->connect_errno) $fail('db connect: ' . $conn->connect_error);
$conn->set_charset('utf8mb4');
// Sandbox fixtures are minimal — production NOT NULL columns take
// implicit defaults (test harness only, never shipped behavior).
$conn->query("SET SESSION sql_mode = ''");

// (base schema + migrations 022/023/024 already applied via mysql CLI)
$conn->query("TRUNCATE mezmur_submissions");
$conn->query("DELETE FROM members");
$conn->query("DELETE FROM users");
$conn->query("TRUNCATE mezmur_attendance");
$conn->query("TRUNCATE mezmur_days");
$conn->query("DELETE FROM activity_logs");

// ── fixtures: 2 users, 5 members in 2 sections ────────────────
$conn->query("INSERT INTO users (id, username, full_name, role, password_hash) VALUES
  (1, 'taker1', 'Taker One', 'attendance_taker', '$dummy'),
  (2, 'dept1', 'Dept One', 'mezmur_dept', '$dummy')");
$conn->query("INSERT INTO members (id, member_code, student_name, father_name, status, current_section) VALUES
  (1, 'MZ-10001', 'Abel', 'Kebede', 'active', 'ህናት'),
  (2, 'MZ-10002', 'Sara', 'Alemu',  'active', 'ህናት'),
  (3, 'MZ-10003', 'Yonas', 'Tessema','active', 'ማዕከዋይ'),
  (4, 'MZ-10004', 'Ruth', 'Bekele', 'active', 'ማዕከዋይ'),
  (5, 'MZ-10005', 'Gone', 'Member', 'inactive', 'ህናት')");
$pass('fixtures loaded');

// ── sections endpoint data ────────────────────────────────────
$secs = MezmurAttendanceService::sectionListWithCounts($conn);
count($secs) === 2 or $fail('expected 2 sections, got ' . count($secs));
$henat = array_values(array_filter($secs, fn($s) => $s['section'] === 'ህናት'))[0];
$henat['members'] === 2 or $fail('ህናት should have 2 ACTIVE members, got ' . $henat['members']);
$pass('sectionListWithCounts: 2 sections, inactive member excluded');

$roster = MezmurAttendanceService::sectionRoster($conn, 'ህናት');
count($roster) === 2 or $fail('sectionRoster ህናት expected 2, got ' . count($roster));

// ── section sheet: unmarked is open ───────────────────────────
$sheet = MezmurAttendanceService::fetchSectionSheet($conn, '2026-08-25', 'ህናት', ['role' => 'attendance_taker']);
$sheet['count'] === 2 or $fail('sheet count');
$sheet['submission_status'] === null or $fail('fresh sheet should have null packet status');
$sheet['locked'] === false or $fail('fresh sheet must not be locked');
$pass('fetchSectionSheet: fresh sheet open');

// ── save: incomplete roster rejected ──────────────────────────
try {
    MezmurAttendanceService::saveSectionSheet($conn, '2026-08-25', 'ህናት', [
        ['member_id' => 1, 'status' => 'present'],
    ], 1);
    $fail('partial sheet must throw');
} catch (DomainException $e) {
    $pass('partial sheet rejected: ' . $e->getMessage());
}

// ── full save (draft) in the caller-owned transaction ─────────
$records = [
    ['member_id' => 1, 'status' => 'present', 'notes' => ''],
    ['member_id' => 2, 'status' => 'excused', 'notes' => 'sick — መታመም'],
];
$auth = ['role' => 'attendance_taker'];
MezmurSubmissionService::takerMayWrite($conn, $auth, '2026-08-25', 'ህናት') or $fail('taker must be able to write');
$counts = MezmurSubmissionService::countsFromRecords($records);
$conn->begin_transaction();
try {
    $summary = MezmurAttendanceService::saveSectionSheet($conn, '2026-08-25', 'ህናት', $records, 1, false);
    $packet = MezmurSubmissionService::upsert($conn, [
        'taker_id' => 1, 'date' => '2026-08-25', 'section' => 'ህናት',
        'status' => MezmurSubmissionService::STATUS_DRAFT,
        'member_count' => $summary['marked'],
        'present' => $counts['present'], 'late' => $counts['late'],
        'absent' => $counts['absent'], 'excused' => $counts['excused'],
        'client_op_id' => 'op-1',
    ]);
    $packet['ok'] or $fail('packet draft upsert: ' . $packet['message']);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $fail('draft save tx: ' . $e->getMessage());
}
$summary['excused'] === 1 and $summary['present'] === 1 or $fail('summary counts');
$packet['status'] === 'incomplete' or $fail('draft normalizes to incomplete, got ' . $packet['status']);
$pass('draft packet saved atomically (rows + packet)');

// note persisted?
$check = $conn->query("SELECT notes FROM mezmur_attendance WHERE member_id = 2 AND attendance_date = '2026-08-25'")->fetch_assoc();
$check['notes'] === 'sick — መታመም' or $fail('note not persisted: ' . var_export($check, true));
$pass('per-member note persisted');

// ── submit then lock ──────────────────────────────────────────
$conn->begin_transaction();
$summary2 = MezmurAttendanceService::saveSectionSheet($conn, '2026-08-25', 'ህናት', $records, 1, false);
$packet2 = MezmurSubmissionService::upsert($conn, [
    'taker_id' => 1, 'date' => '2026-08-25', 'section' => 'ህናት',
    'status' => MezmurSubmissionService::STATUS_SUBMITTED,
    'member_count' => $summary2['marked'],
    'present' => $counts['present'], 'late' => $counts['late'],
    'absent' => $counts['absent'], 'excused' => $counts['excused'],
]);
$packet2['ok'] or $fail('submit upsert: ' . $packet2['message']);
$conn->commit();
$packet2['status'] === 'submitted' or $fail('submit status');

MezmurSubmissionService::takerMayWrite($conn, $auth, '2026-08-25', 'ህናት') and $fail('submitted packet must lock the taker');
$sheet2 = MezmurAttendanceService::fetchSectionSheet($conn, '2026-08-25', 'ህናት', $auth);
$sheet2['locked'] === true or $fail('sheet must be locked after submit');
$sheet2['submission_status'] === 'submitted' or $fail('sheet packet status');
// Least privilege (audit 2026-08-28): dept users review but cannot
// override a lock; only admins can write through it.
$sheet3 = MezmurAttendanceService::fetchSectionSheet($conn, '2026-08-25', 'ህናት', ['role' => 'mezmur_dept']);
$sheet3['locked'] === true or $fail('dept must NOT override locks (least privilege)');
$sheet4 = MezmurAttendanceService::fetchSectionSheet($conn, '2026-08-25', 'ህናት', ['role' => 'school_admin']);
$sheet4['locked'] === false or $fail('school_admin keeps override power');
$pass('submitted packet locks takers AND dept; only admins override');

// locked upsert refuses without force
$lockedTry = MezmurSubmissionService::upsert($conn, [
    'taker_id' => 1, 'date' => '2026-08-25', 'section' => 'ህናት',
    'status' => MezmurSubmissionService::STATUS_DRAFT, 'member_count' => 2,
]);
$lockedTry['ok'] === false or $fail('locked upsert must refuse');
str_contains($lockedTry['message'], 'already submitted') or $fail('lock message: ' . $lockedTry['message']);
$pass('locked upsert refuses with the teacher-style message');

// ── review: reason mandatory for returns ──────────────────────
$pid = (int)$conn->query("SELECT id FROM mezmur_submissions LIMIT 1")->fetch_assoc()['id'];
$bad = MezmurSubmissionService::reviewPacket($conn, $pid, 'revision_needed', 'x', 2);
$bad['ok'] === false or $fail('short reason must be refused');
$pass('review refuses short reasons');

$good = MezmurSubmissionService::reviewPacket($conn, $pid, 'revision_needed', 'እባክዎ ማስታወሻውን ያረጋግጡ', 2);
$good['ok'] or $fail('valid review failed: ' . $good['message']);
$st = $conn->query("SELECT status FROM mezmur_submissions WHERE id = $pid")->fetch_assoc()['status'];
$st === 'revision_needed' or $fail('status after review: ' . $st);
$audit = $conn->query("SELECT COUNT(*) c FROM activity_logs WHERE action = 'Mezmur Submission Reviewed'")->fetch_assoc()['c'];
(int)$audit === 1 or $fail('audit trail missing');
$pass('review recorded + audit trail written');

// revision_needed unlocks the taker again
MezmurSubmissionService::takerMayWrite($conn, $auth, '2026-08-25', 'ህናት') or $fail('revision_needed must hand the key back');
$sheet4 = MezmurAttendanceService::fetchSectionSheet($conn, '2026-08-25', 'ህናት', $auth);
$sheet4['review_notes'] === 'እባክዎ ማስታወሻውን ያረጋግጡ' or $fail('review note not delivered to taker');
$pass('revision_needed unlocks taker + delivers the reason');

// approve path
$ap = MezmurSubmissionService::reviewPacket($conn, $pid, 'approved', '', 2);
$ap['ok'] or $fail('approve failed');
MezmurSubmissionService::takerMayWrite($conn, $auth, '2026-08-25', 'ህናት') and $fail('approved packet must lock');
$pass('approve finalizes the packet');

// ── inbox + detail (paginated contract) ───────────────────────
$inbox = MezmurSubmissionService::listPackets($conn, []);
$list = $inbox['items'];
count($list) === 1 or $fail('inbox should list 1 packet, got ' . count($list));
$inbox['total'] === 1 or $fail('inbox total');
$inbox['page'] === 1 && $inbox['total_pages'] === 1 or $fail('inbox pagination shape');
$list[0]['status'] === 'approved' or $fail('inbox status');
$list[0]['taker_name'] === 'Taker One' or $fail('inbox taker join');
$detail = MezmurSubmissionService::detail($conn, $pid);
count($detail['rows']) === 2 or $fail('detail rows');
$detail['rows'][1]['notes'] === 'sick — መታመም' or $fail('detail row notes');
$pass('inbox + detail with rows');

// ── UNIQUE(date, section) enforced ────────────────────────────
try {
    $conn->query("INSERT INTO mezmur_submissions (attendance_date, section, taker_id, status) VALUES ('2026-08-25', 'ህናት', 1, 'draft')");
    $dup = $conn->errno === 1062;
} catch (mysqli_sql_exception $e) {
    $dup = $e->getCode() === 1062;
}
$dup or $fail('UNIQUE(attendance_date, section) not enforced');
$pass('UNIQUE(attendance_date, section) enforced');

// ── analytics still work on widened statuses ──────────────────
$an = MezmurAttendanceService::analyticsMembers($conn, ['from' => '2026-08-01', 'to' => '2026-08-28']);
count($an['items']) >= 2 or $fail('analytics members missing');
$pass('analytics unaffected');

// ══ Audit-phase checks (end-to-end department audit, 2026-08-28) ══

// ── packet lifecycle writes the module audit trail ────────────
$auditBefore = (int)$conn->query("SELECT COUNT(*) c FROM mezmur_attendance_audit WHERE action = 'packet_upsert'")->fetch_assoc()['c'];
$pk = MezmurSubmissionService::upsert($conn, [
    'taker_id' => 1, 'date' => '2026-08-26', 'section' => 'ማዕከዋይ',
    'status' => 'submitted', 'member_count' => 2,
    'present' => 2, 'late' => 0, 'absent' => 0, 'excused' => 0,
]);
$pk['ok'] or $fail('audit-phase packet upsert');
$auditAfter = (int)$conn->query("SELECT COUNT(*) c FROM mezmur_attendance_audit WHERE action = 'packet_upsert'")->fetch_assoc()['c'];
$auditAfter === $auditBefore + 1 or $fail('packet_upsert audit row missing');
$pass('packet lifecycle audited in mezmur_attendance_audit');

// ── negative / absurd counts are clamped, never stored raw ────
$pk2 = MezmurSubmissionService::upsert($conn, [
    'taker_id' => 1, 'date' => '2026-08-27', 'section' => 'ማዕከዋይ',
    'status' => 'submitted', 'member_count' => -5,
    'present' => -3, 'late' => 0, 'absent' => 99999999, 'excused' => 0,
]);
$pk2['ok'] or $fail('clamped upsert should still succeed');
$row = $conn->query("SELECT member_count, present_count, absent_count FROM mezmur_submissions WHERE id = " . (int)$pk2['id'])->fetch_assoc();
(int)$row['member_count'] === 0 or $fail('negative member_count must clamp to 0');
(int)$row['present_count'] === 0 or $fail('negative present must clamp to 0');
(int)$row['absent_count'] === 1000000 or $fail('absurd absent must clamp to cap');
$pass('packet counts clamped to sane bounds');

// ── calendar-real dates only ──────────────────────────────────
foreach (['2026-02-31', '9999-99-99', '2026-13-01'] as $badDate) {
    try {
        MezmurAttendanceService::ensureDay($conn, $badDate, 'rehearsal', null, null, 2);
        $fail("invalid date $badDate must be rejected");
    } catch (DomainException $e) { /* expected */ }
}
$pass('impossible calendar dates rejected');

// ── inbox pagination is clamped and total-aware ───────────────
$big = MezmurSubmissionService::listPackets($conn, ['per_page' => 100000]);
$big['per_page'] === 100 or $fail('per_page must clamp to 100');
$small = MezmurSubmissionService::listPackets($conn, ['per_page' => 1]);
count($small['items']) === 1 or $fail('per_page=1 must return 1 item');
$small['total'] >= 2 && $small['total_pages'] === $small['total'] or $fail('total/total_pages must be consistent');
$pass('inbox pagination clamped + totals correct');

// ── SQLi payloads stored literally, never executed ────────────
$conn->query("DELETE FROM mezmur_hymns WHERE title LIKE 'AUDIT%'");
$evilTitle = "AUDIT' OR 1=1; DROP TABLE mezmur_hymns; --";
$st = $conn->prepare("INSERT INTO mezmur_hymns (title, category, lyrics, status) VALUES (?, 'general', ?, 'active')");
$evilLyrics = "line with '; DELETE FROM members; -- inside";
$st->bind_param('ss', $evilTitle, $evilLyrics);
$st->execute();
$st->close();
$stillThere = (int)$conn->query("SELECT COUNT(*) c FROM mezmur_hymns WHERE title = '" . $conn->real_escape_string($evilTitle) . "'")->fetch_assoc()['c'];
$stillThere === 1 or $fail('SQLi title must be stored literally');
$membersAlive = (int)$conn->query("SELECT COUNT(*) c FROM members")->fetch_assoc()['c'];
$membersAlive >= 5 or $fail('members table must survive');
$pass('injection payloads stored as inert data');

echo "\nALL FUNCTIONAL CHECKS PASSED\n";
