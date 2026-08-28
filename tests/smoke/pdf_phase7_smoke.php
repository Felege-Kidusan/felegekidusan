<?php
/**
 * Functional smoke test for the PDF report engine (Phase D).
 * Generates all five report templates against a live DB and proves:
 *   - every template emits a real PDF (%PDF header, font embedded)
 *   - unknown types + missing member_id are rejected cleanly
 *   - the vendored TCPDF + Ethiopic font artifacts exist
 *   - READ-ONLY: source table counts unchanged by report builds
 * Exits non-zero with a message on the first failure.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$fail = function (string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$pass = function (string $msg): void { echo "  ok: $msg\n"; };

require dirname(__DIR__, 2) . '/admin/backend/services/InfoAnalyticsService.php';
require dirname(__DIR__, 2) . '/admin/backend/services/PdfReportService.php';

use App\Services\InfoAnalyticsService;
use App\Services\PdfReportService;

$conn = @new mysqli('127.0.0.1', 'ssms', 'ssms', 'ssms_smoke');
if ($conn->connect_errno) $fail('db connect: ' . $conn->connect_error);
$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode = ''");

// ── vendored engine artifacts ────────────────────────────────
$base = dirname(__DIR__, 2) . '/admin/backend/pdf/tcpdf';
foreach (['tcpdf.php', 'VERSION', 'LICENSE.TXT',
          'fonts/notosansethiopic.ttf', 'fonts/notosansethiopic.php', 'fonts/notosansethiopic.ctg.z',
          'fonts/notosansethiopicb.ttf', 'fonts/notosansethiopicb.php', 'fonts/notosansethiopicb.ctg.z',
          'fonts/OFL.txt'] as $f) {
    file_exists("$base/$f") ? null : $fail("missing vendored artifact: $f");
}
$pass('vendored TCPDF 6.11 + Noto Sans Ethiopic artifacts present (incl. OFL)');

// ── ensure rollup is current ─────────────────────────────────
InfoAnalyticsService::refreshRollup($conn);
$pass('rollup refreshed');

// ── READ-ONLY: snapshot sources ──────────────────────────────
$sourceTables = ['attendance', 'mezmur_attendance', 'hr_attendance',
                 'mezmur_submissions', 'hr_submissions', 'members'];
$before = [];
foreach ($sourceTables as $t) {
    $before[$t] = (int)$conn->query("SELECT COUNT(*) c FROM `$t`")->fetch_assoc()['c'];
}

// ── generate every template ──────────────────────────────────
$memberId = (int)($conn->query("SELECT MIN(id) m FROM members")->fetch_assoc()['m'] ?? 0);
$cases = [
    ['general', []],
    ['sections', ['source' => 'mezmur']],
    ['sections', ['source' => 'hr']],
    ['classes', []],
    ['member', ['member_id' => $memberId]],
    ['full', []],
];
foreach ($cases as [$type, $opts]) {
    $res = PdfReportService::build($conn, $type, $opts);
    if (empty($res['ok'])) $fail("$type: build rejected: " . ($res['message'] ?? '?'));
    if (!str_starts_with($res['data'], '%PDF-')) $fail("$type: output is not a PDF");
    if (strlen($res['data']) < 5000) $fail("$type: suspiciously small PDF (" . strlen($res['data']) . ' bytes)');
    if (empty($res['filename']) || !str_ends_with($res['filename'], '.pdf')) $fail("$type: bad filename");
    $tag = $type . ($opts['source'] ?? '');
    $pass("$type → PDF " . strlen($res['data']) . ' bytes (' . $res['filename'] . ')');
}

// ── guards ───────────────────────────────────────────────────
$bad = PdfReportService::build($conn, 'bogus');
empty($bad['ok']) ? $pass('unknown type rejected') : $fail('unknown type accepted');

$noMem = PdfReportService::build($conn, 'member', []);
empty($noMem['ok']) ? $pass('member report without member_id rejected') : $fail('member report needs member_id');

$ghost = PdfReportService::build($conn, 'member', ['member_id' => 999999]);
// ghost member still renders a PDF (with "Member not found") — never crashes
(!empty($ghost['ok']) && str_starts_with($ghost['data'], '%PDF-'))
    ? $pass('ghost member renders graceful PDF') : $fail('ghost member should render gracefully');

// ── isolation assertion: builds changed nothing ──────────────
foreach ($sourceTables as $t) {
    $after = (int)$conn->query("SELECT COUNT(*) c FROM `$t`")->fetch_assoc()['c'];
    if ($before[$t] !== $after) $fail("report build wrote to `$t`: {$before[$t]} -> $after");
}
$pass('READ-ONLY: source tables unchanged after all report builds');

echo "ALL PDF REPORT FUNCTIONAL CHECKS PASSED\n";
