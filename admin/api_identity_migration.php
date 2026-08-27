<?php
/**
 * Web-based identity code migration runner.
 * Super Admin only. Runs the same logic as the CLI tool but via HTTP POST
 * with CSRF protection. Supports --dry-run preview and --execute modes.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberCategory.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/IdentityCodeService.php';

use App\Services\IdentityCodeService;
use App\Services\MemberCategory;
use App\Services\SecurityAuditService;

if (empty($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Super Admin only.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST');
    echo json_encode(['status' => 'error', 'message' => 'POST required.']);
    exit;
}
if (!validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security token expired.']);
    exit;
}

$mode = ($_POST['mode'] ?? '') === 'execute' ? 'execute' : 'dry_run';
@set_time_limit(300);

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']); exit;
}
$conn->set_charset('utf8mb4');

// Check schema prerequisite
$check = $conn->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'member_code_migrations'");
if (!$check || (int)$check->fetch_assoc()['c'] === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Run sql/017_identity_codes.sql in phpMyAdmin first.']); exit;
}
$check->free();

$isDryRun = $mode === 'dry_run';
$letters = MemberCategory::letters();
$totalRenumbered = 0; $totalQr = 0; $errors = [];
$log = [];

foreach ($letters as $letter) {
    $group = MemberCategory::groupFor($letter);
    $log[] = "Category {$letter}: scanning…";
    $lastId = 0; $seq = 0;

    while (true) {
        $stmt = $conn->prepare(
            "SELECT id, member_code, student_name, father_name FROM members
             WHERE age_group = ? AND status != 'archived' AND id > ?
               AND member_code NOT REGEXP CONCAT('^', ?, '[0-9]+$')
             ORDER BY student_name ASC, father_name ASC, id ASC LIMIT 100"
        );
        if (!$stmt) { $errors[] = "Query prepare failed for {$letter}"; break; }
        $stmt->bind_param('sis', $group, $lastId, $letter);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) { $stmt->close(); break; }

        while ($row = $result->fetch_assoc()) {
            $mid = (int)$row['id']; $old = (string)$row['member_code']; $lastId = $mid;
            if (strpos($old, '-') !== false) continue; // already staff-coded

            $seq++; $newCode = $letter . $seq;
            $dup = $conn->prepare('SELECT 1 FROM members WHERE member_code = ? AND id != ? LIMIT 1');
            $dup->bind_param('si', $newCode, $mid); $dup->execute();
            if ($dup->get_result()->fetch_row()) { $seq++; $newCode = $letter . $seq; $seq++; }
            $dup->close();

            $log[] = "  {$row['student_name']} {$row['father_name']}: {$old} → {$newCode}";

            if (!$isDryRun) {
                $uStmt = $conn->prepare('UPDATE members SET legacy_member_code=?, member_code=? WHERE id=?');
                $uStmt->bind_param('ssi', $old, $newCode, $mid);
                if ($uStmt->execute()) {
                    $totalRenumbered++;
                    $lStmt = $conn->prepare("INSERT INTO member_code_migrations (member_id, old_code, new_code) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE old_code=VALUES(old_code), new_code=VALUES(new_code)");
                    $lStmt->bind_param('iss', $mid, $old, $newCode); $lStmt->execute(); $lStmt->close();
                    $qrCheck = $conn->prepare('SELECT qr_code_path FROM members WHERE id = ?');
                    $qrCheck->bind_param('i', $mid); $qrCheck->execute();
                    $qrPath = (string)($qrCheck->get_result()->fetch_assoc()['qr_code_path'] ?? '');
                    $qrCheck->close();
                    if ($qrPath !== '') {
                        require_once __DIR__ . '/id_cards/libs/qr_loader.php';
                        if (class_exists('QRcode') && IdentityCodeService::regenerateQr($conn, $mid)) $totalQr++;
                    }
                } else {
                    $errors[] = "Update failed for member {$mid}";
                }
                $uStmt->close();
            }

            // Limit log output for JSON response size.
            if (count($log) > 200) { $log[] = '…(truncated)'; break 2; }
        }
        $result->free(); $stmt->close();
        if ($isDryRun && count($log) > 200) break;
    }
}

// Resync per-letter sequences (sql/018) so post-migration allocations
// continue from the renumbered maxima.
if (!$isDryRun) {
    foreach ($letters as $letter) {
        $maxStmt = $conn->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(member_code, 2) AS UNSIGNED)), 0) AS max_n
             FROM members WHERE member_code REGEXP CONCAT('^', ?, '[0-9]+$')"
        );
        if ($maxStmt) {
            $maxStmt->bind_param('s', $letter);
            $maxStmt->execute();
            $maxN = (int)$maxStmt->get_result()->fetch_assoc()['max_n'];
            $maxStmt->close();
            $syncStmt = $conn->prepare(
                'INSERT INTO member_code_sequences (letter, last_n) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE last_n = GREATEST(last_n, VALUES(last_n))'
            );
            if ($syncStmt) {
                $syncStmt->bind_param('si', $letter, $maxN);
                $syncStmt->execute();
                $syncStmt->close();
            }
        }
    }
}

$conn->close();
SecurityAuditService::record($GLOBALS['conn'] ?? null, 'Identity Migration ' . ucfirst($mode), ['renumbered' => $totalRenumbered], 'system');

echo json_encode([
    'status' => 'success',
    'mode' => $mode,
    'renumbered' => $isDryRun ? null : $totalRenumbered,
    'qr_refreshed' => $isDryRun ? null : $totalQr,
    'log' => $log,
    'error_count' => count($errors),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
