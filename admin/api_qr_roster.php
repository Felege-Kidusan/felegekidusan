<?php
/**
 * ════════════════════════════════════════════════════════════
 * QR Roster Printing (Phase 8 — QR-scan attendance)  [phase8-qr28]
 * ════════════════════════════════════════════════════════════
 * Streams a printable PDF grid of member QR tiles so every
 * department can hand members a scannable code (HR members already
 * carry one on their ID card; Education and Mezmur get rosters).
 *
 *   GET ?dept=edu&class_id=N[&page=P]
 *   GET ?dept=mezmur|hr&section=S[&page=P]
 *
 * The QR payload is IDENTICAL to the ID-card payload
 *   {SITE_URL}/member.php?code={member_code}
 * i.e. the code is an identifier, never a credential: every scan is
 * re-validated against class/section membership and duplicate rules
 * on the device AND again server-side at sync (defence in depth).
 *
 * Governance (same layer as api_info_reports.php):
 *  - ROLE_MAP gate (access_control.php) + re-checked session/role here,
 *    department ownership enforced (edu_dept prints edu rosters only…),
 *  - per-user rate limit, audit log,
 *  - bounded pages (≤200 tiles) over indexed columns
 *    (class_enrollments idx_ce_member_year_status_id, members
 *    current_section / idx_members_code) — no full-table scans.
 * GET-only: renders nothing mutable, therefore CSRF-free.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/pdf/tcpdf/tcpdf.php';

use App\Services\SecurityAuditService;

if (!defined('QR_ROSTER_API_VERSION')) define('QR_ROSTER_API_VERSION', 'phase8-qr28');

function qr_roster_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => $message,
        'v' => QR_ROSTER_API_VERSION,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    $token = bin2hex(random_bytes(3));
    error_log('[qr-roster-unhandled #' . $token . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    qr_roster_fail('Unexpected server fault (log reference ' . $token . '). Please retry.', 500);
});

// ── 1. Auth + department ownership ───────────────────────────
if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    qr_roster_fail('Please sign in again.', 401);
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');

$dept = (string)($_GET['dept'] ?? '');
$deptRoleMap = [
    'edu'    => 'edu_dept',
    'mezmur' => 'mezmur_dept',
    'hr'     => 'hr_dept',
];
if (!isset($deptRoleMap[$dept])) {
    qr_roster_fail('Unknown department.');
}
if (!in_array($role, ['super_admin', 'school_admin', $deptRoleMap[$dept]], true)) {
    qr_roster_fail('You do not have permission to print this roster.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    qr_roster_fail('Use GET to download rosters.', 405);
}

// ── 2. Rate limiting (per user) ──────────────────────────────
$rl = new \App\Services\SecurityRateLimiter($pdo ?? null, sys_get_temp_dir() . '/ssms_ratelimit');
$rlCheck = $rl->consume('qr_roster_build', 'user:' . $adminId, 20, 60);
if (!$rlCheck['allowed']) {
    qr_roster_fail('Too many rosters requested. Please wait a moment and try again.', 429);
}

$classId = (int)($_GET['class_id'] ?? 0);
$section = trim((string)($_GET['section'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

if ($dept === 'edu' && $classId <= 0) {
    qr_roster_fail('Pick a class first.');
}
if ($dept !== 'edu' && $section === '') {
    qr_roster_fail('Pick a section first.');
}

// ── 3. Audit every render (governed layer rule) ──────────────
SecurityAuditService::record(
    $conn,
    'QR Roster Printed',
    ['dept' => $dept, 'class_id' => $classId ?: null, 'section' => $section ?: null, 'page' => $page],
    'qr_roster',
    null
);

// ── 4. Bounded roster query (page budget ≤ 200) ──────────────
const QR_ROSTER_PAGE_SIZE = 200;
$offset = ($page - 1) * QR_ROSTER_PAGE_SIZE;

if ($dept === 'edu') {
    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM class_enrollments ce
         JOIN academic_years y ON y.id = ce.academic_year_id AND y.is_current = 1
         WHERE ce.class_id = ? AND ce.status = 'active'"
    );
    $countStmt->bind_param('i', $classId);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['c'];

    $stmt = $conn->prepare(
        "SELECT m.id, m.student_name, m.member_code
         FROM class_enrollments ce
         JOIN academic_years y ON y.id = ce.academic_year_id AND y.is_current = 1
         JOIN members m ON m.id = ce.member_id
         WHERE ce.class_id = ? AND ce.status = 'active'
         ORDER BY m.member_code, m.id
         LIMIT ? OFFSET ?"
    );
    $limit = QR_ROSTER_PAGE_SIZE;
    $stmt->bind_param('iii', $classId, $limit, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $labelStmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ? LIMIT 1");
    $labelStmt->bind_param('i', $classId);
    $labelStmt->execute();
    $groupLabel = (string)($labelStmt->get_result()->fetch_assoc()['class_name'] ?? ('Class #' . $classId));
} else {
    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM members
         WHERE current_section = ? AND status = 'active'"
    );
    $countStmt->bind_param('s', $section);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['c'];

    $stmt = $conn->prepare(
        "SELECT id, student_name, member_code
         FROM members
         WHERE current_section = ? AND status = 'active'
         ORDER BY member_code, id
         LIMIT ? OFFSET ?"
    );
    $limit = QR_ROSTER_PAGE_SIZE;
    $stmt->bind_param('sii', $section, $limit, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $groupLabel = $section;
}

if ($total === 0) {
    qr_roster_fail('No active members in this ' . ($dept === 'edu' ? 'class' : 'section') . '.');
}

// ── 5. Render the tile grid ──────────────────────────────────
$baseUrl = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';

$pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('SSMS');
$pdf->SetAuthor('SSMS');
$pdf->SetTitle('QR Roster — ' . $groupLabel);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false, 0);
$pdf->setFontSubsetting(true);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetFont('notosansethiopic', '', 9);
$pdf->AddPage();

$deptTitle = ['edu' => 'Education — Class', 'mezmur' => 'Mezmur — Section', 'hr' => 'HR — Section'][$dept];
$pages = (int)ceil($total / QR_ROSTER_PAGE_SIZE);

$pdf->SetFont('notosansethiopicb', '', 13);
$pdf->Cell(0, 8, $deptTitle . ' ' . $groupLabel . ' — QR Roster', 0, 1);
$pdf->SetFont('notosansethiopic', '', 9);
$pdf->Cell(0, 5, 'Cut the tiles and hand one to each member. Scanned by attendance takers in the mobile app.', 0, 1);
$pdf->Cell(0, 5, 'Page ' . $page . ' of ' . $pages . ' • ' . $total . ' members • Generated ' . date('Y-m-d H:i'), 0, 1);
$pdf->Ln(3);

$tileW = 92; $tileH = 40; $gap = 4;
$x0 = 10; $y0 = $pdf->GetY();
$col = 0; $row = 0;

foreach ($rows as $r) {
    $code = (string)($r['member_code'] ?? '');
    if ($code === '') {
        continue; // members without a code cannot carry a QR
    }
    $x = $x0 + $col * ($tileW + $gap);
    $y = $y0 + $row * ($tileH + $gap);
    if ($y + $tileH > 287) {
        $pdf->AddPage();
        $y0 = 12; $y = $y0; $row = 0; $col = 0; $x = $x0;
    }

    $pdf->SetDrawColor(170, 170, 170);
    $pdf->Rect($x, $y, $tileW, $tileH);

    // QR (same payload as the ID card): identifier, not a credential.
    $payload = $baseUrl . '/member.php?code=' . rawurlencode($code);
    $pdf->write2DBarcode($payload, 'QRCODE,M', $x + 2, $y + 3, 26, 26, [], 'N');

    $pdf->SetFont('notosansethiopicb', '', 9.5);
    $pdf->SetXY($x + 31, $y + 4);
    $pdf->MultiCell($tileW - 34, 12, (string)$r['student_name'], 0, 'L');
    $pdf->SetFont('notosansethiopic', '', 9);
    $pdf->SetXY($x + 31, $y + 20);
    $pdf->Cell($tileW - 34, 5, $code, 0, 1, 'L');
    $pdf->SetFont('notosansethiopic', '', 7.5);
    $pdf->SetXY($x + 31, $y + 26);
    $pdf->Cell($tileW - 34, 5, $groupLabel, 0, 1, 'L');

    $col++;
    if ($col > 1) { $col = 0; $row++; }
}

$pdf->Output('qr-roster-' . $dept . '-' . ($dept === 'edu' ? $classId : preg_replace('/[^A-Za-z0-9_-]/', '_', $section)) . '-p' . $page . '.pdf', 'I');
exit;
