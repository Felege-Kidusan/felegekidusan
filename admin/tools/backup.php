<?php
/**
 * Create an encrypted database backup.
 *
 * Recommended cron command:
 *   0 2 * * * /usr/local/bin/php /path/to/public_html/admin/tools/backup.php
 *
 * Decrypt a downloaded/stored backup before a controlled database restore:
 *   php admin/tools/backup.php --decrypt=BACKUP_NAME --output=/private/restore.sql
 *
 * Browser requests require a Super Admin session, POST, and CSRF token. Secrets
 * are never accepted in URLs. Legacy cron_backup.php delegates here for CLI.
 */

@set_time_limit(0);
@ignore_user_abort(true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/services/BackupService.php';

use App\Services\BackupService;

$isCli = PHP_SAPI === 'cli';

function backupOutput(bool $ok, string $message, array $metadata = []): void
{
    global $isCli;
    if ($isCli) {
        $stream = $ok ? STDOUT : STDERR;
        fwrite($stream, ($ok ? 'BACKUP OK: ' : 'BACKUP FAILED: ') . $message . PHP_EOL);
        if ($ok && $metadata) {
            fwrite($stream, 'File: ' . $metadata['name'] . PHP_EOL);
            fwrite($stream, 'Encrypted size: ' . number_format((int)$metadata['size']) . ' bytes' . PHP_EOL);
            fwrite($stream, 'Rows: ' . number_format((int)$metadata['rows']) . PHP_EOL);
        }
        exit($ok ? 0 : 1);
    }

    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('Location: /admin/dashboards/super-admin.php?section=backup&backup_status=' . ($ok ? 'created' : 'failed'), true, 303);
    }
    exit;
}

if (!$isCli) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        exit('Access denied.');
    }
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        backupOutput(false, 'Security token expired.');
    }
    // A large backup must not block the administrator's other requests.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

if ($isCli && isset($argv)) {
    $decryptName = null;
    $outputPath = null;
    foreach ($argv as $argument) {
        if (strpos($argument, '--decrypt=') === 0) {
            $decryptName = substr($argument, strlen('--decrypt='));
        } elseif (strpos($argument, '--output=') === 0) {
            $outputPath = substr($argument, strlen('--output='));
        }
    }
    if ($decryptName !== null || $outputPath !== null) {
        if (!$decryptName || !$outputPath) {
            fwrite(STDERR, "RESTORE FAILED: provide both --decrypt=BACKUP_NAME and --output=/absolute/path.sql\n");
            exit(1);
        }
        try {
            $bytes = BackupService::decryptToSql($decryptName, $outputPath, ROOT_PATH);
            fwrite(STDOUT, 'RESTORE OK: wrote ' . number_format($bytes) . " SQL bytes to the requested private path.\n");
            exit(0);
        } catch (Throwable $error) {
            reportInternalError('Backup decryption failed', $error);
            fwrite(STDERR, "RESTORE FAILED: the backup could not be decrypted.\n");
            exit(1);
        }
    }
}

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    reportInternalError('Backup database connection unavailable');
    backupOutput(false, 'Database service is unavailable.');
}

try {
    $metadata = BackupService::create($conn, ROOT_PATH);

    try {
        $uid = $isCli ? 0 : (int)($_SESSION['admin_id'] ?? 0);
        $username = $isCli ? 'CRON' : (string)($_SESSION['admin_username'] ?? 'admin');
        $ip = $isCli ? 'localhost' : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $details = sprintf(
            '%s (%d bytes encrypted, %d rows)',
            $metadata['name'],
            $metadata['size'],
            $metadata['rows']
        );
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, 'Encrypted Backup Created', ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('isss', $uid, $username, $details, $ip);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $ignored) {
        // Backup success must not depend on optional audit persistence.
    }

    backupOutput(true, 'Encrypted backup created.', $metadata);
} catch (Throwable $error) {
    reportInternalError('Encrypted backup creation failed', $error);
    backupOutput(false, 'The encrypted backup could not be created.');
}
