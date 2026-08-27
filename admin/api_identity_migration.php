<?php
/**
 * Web-based format-v2 identity code migration runner.
 * Super Admin only, CSRF-protected POST. Dry-run preview and execute
 * modes. Thin HTTP shell over IdentityMigrationService — the exact same
 * engine the CLI tool uses, so behaviour can never drift.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/IdentityMigrationService.php';

use App\Services\IdentityMigrationService;
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
@set_time_limit(600);

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']); exit;
}
$conn->set_charset('utf8mb4');

$check = $conn->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'member_code_migrations'");
if (!$check || (int)$check->fetch_assoc()['c'] === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Run sql/017_identity_codes.sql in phpMyAdmin first.']); exit;
}
$check->free();

require_once __DIR__ . '/id_cards/libs/qr_loader.php';

$outcome = IdentityMigrationService::renumberAll($conn, $mode === 'dry_run');
$conn->close();

SecurityAuditService::record(
    $GLOBALS['conn'] ?? null,
    'Identity Migration ' . ucfirst($mode),
    ['renumbered' => $outcome['renumbered']],
    'system'
);

echo json_encode([
    'status' => 'success',
    'mode' => $mode,
    'renumbered' => $outcome['renumbered'],
    'qr_refreshed' => $mode === 'dry_run' ? null : $outcome['qr'],
    'pending' => $outcome['skipped_pending'],
    'log' => $outcome['log'],
    'error_count' => count($outcome['errors']),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
