<?php
/**
 * Authenticated download controller for encrypted and read-only legacy backups.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/services/BackupService.php';

use App\Services\BackupService;

if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Access denied.');
}

$name = (string)($_GET['file'] ?? '');
if ($name === '' || basename($name) !== $name) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid backup file name.');
}

$path = BackupService::resolveForDownload($name, ROOT_PATH);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Backup not found.');
}

try {
    if (isset($conn) && $conn) {
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        $username = (string)($_SESSION['admin_username'] ?? 'admin');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, 'Backup Downloaded', ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('isss', $uid, $username, $name, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
} catch (Throwable $ignored) {
    // Availability of audit storage must not corrupt an authorized download.
}

$size = @filesize($path);
if ($size === false) {
    http_response_code(404);
    exit('Backup not found.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . (int)$size);
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Referrer-Policy: no-referrer');

$stream = @fopen($path, 'rb');
if ($stream === false) {
    http_response_code(404);
    exit;
}
while (!feof($stream)) {
    $chunk = fread($stream, 1024 * 1024);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
}
fclose($stream);
exit;
