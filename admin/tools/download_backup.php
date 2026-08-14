<?php
/**
 * ============================================================
 * SECURE BACKUP DOWNLOAD  (Super Admin only)
 * ============================================================
 * The backups directory (admin/uploads/backups/) is deliberately blocked from
 * direct web access by .htaccess, because a raw .sql dump contains student PII
 * and password hashes. A plain <a href> to the file therefore always fails
 * ("file not found / forbidden").
 *
 * This endpoint is the ONLY sanctioned way to download a backup: it
 *   1. requires a logged-in Super Admin,
 *   2. validates the requested name (no path traversal, .sql only),
 *   3. reads the file from disk server-side and streams it as a download.
 *
 * URL:  /admin/tools/download_backup.php?file=backup_YYYY-MM-DD_HH-ii-ss.sql
 * ============================================================
 */

require_once __DIR__ . '/../config.php';

// ── Super Admin ONLY ──
if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Only a Super Admin can download database backups.');
}

$backupDir = realpath(__DIR__ . '/../uploads/backups');
if ($backupDir === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No backups directory found.');
}

// ── Validate the requested filename (defence in depth) ──
$requested = (string)($_GET['file'] ?? '');
$name = basename($requested); // strip any path components
if ($name === '' || !preg_match('/^backup_[0-9A-Za-z_\-]+\.sql$/', $name)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid backup file name.');
}

$path = $backupDir . DIRECTORY_SEPARATOR . $name;
$real = realpath($path);
// Must exist AND resolve to a path INSIDE the backups directory (anti-traversal).
if ($real === false || strpos($real, $backupDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('That backup file is no longer on the server.');
}

// ── Log the download (non-fatal) ──
try {
    if (isset($conn) && $conn) {
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        $uname = $_SESSION['admin_username'] ?? 'admin';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, 'Backup Downloaded', ?, ?)");
        if ($stmt) { $stmt->bind_param('isss', $uid, $uname, $name, $ip); $stmt->execute(); $stmt->close(); }
    }
} catch (Throwable $e) { /* logging is optional */ }

// ── Stream the file as a download ──
// Clear any output buffering so headers/body are clean.
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Description: File Transfer');
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, no-store, no-cache, private');
header('Pragma: no-cache');
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');

readfile($real);
exit;
