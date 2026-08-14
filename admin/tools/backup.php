<?php
/**
 * ============================================================
 * DATABASE BACKUP   —   admin/tools/backup.php
 * ============================================================
 * Exports the whole database to a timestamped .sql file, keeps the
 * newest 7, and deletes older ones. Can be run by a person in the
 * browser OR automatically by a daily cron job.
 *
 * Like the health check, this does NOT use the normal login — it has
 * its own key (BACKUP_KEY, from your .fkss_env.php). That lets the cron
 * job run it without a browser session.
 *
 * IT STREAMS the export row-by-row straight to disk, so it stays fast
 * and does not run out of memory even when the database is large. (The
 * old backup script built the whole file in memory and would silently
 * fail once the data grew — this one does not.)
 *
 * RUN IT MANUALLY (browser):
 *   https://your-site/admin/tools/backup.php?key=YOUR_BACKUP_KEY
 *
 * RUN IT DAILY (cPanel → Cron Jobs), once per day at 2 AM:
 *   0 2 * * * /usr/local/bin/php /home/USERNAME/public_html/admin/tools/backup.php key=YOUR_BACKUP_KEY >/dev/null 2>&1
 *   (On some hosts the PHP path is /usr/bin/php. Replace USERNAME and the key.)
 * ============================================================
 */

@set_time_limit(0);
$isCli = (PHP_SAPI === 'cli');

// ── Load ONLY the secrets file (DB creds + BACKUP_KEY) ──
$envNames = ['.fkss_env.php', '.wbws_env.php'];
$envDirs  = [dirname(__DIR__, 2), dirname(__DIR__, 3), dirname(__DIR__, 4)];
$envLoaded = false;
foreach ($envDirs as $d) {
    foreach ($envNames as $n) {
        if (is_file($d . '/' . $n)) { require_once $d . '/' . $n; $envLoaded = true; break 2; }
    }
}

function backup_fail($msg, $code = 500) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo "BACKUP FAILED: $msg\n";
    exit;
}

if (!$envLoaded) {
    backup_fail('secrets file (.fkss_env.php) not found above the web root.');
}
if (!defined('BACKUP_KEY') || BACKUP_KEY === '' || strpos(BACKUP_KEY, 'REPLACE_WITH') === 0) {
    backup_fail('BACKUP_KEY is not set in your secrets file. Set it, then try again.');
}

// ── Key check (CLI runs are trusted; web/cron runs need the key) ──
if (!$isCli) {
    $providedKey = $_GET['key'] ?? ($_POST['key'] ?? '');
    if (!is_string($providedKey) || !hash_equals(BACKUP_KEY, $providedKey)) {
        backup_fail('invalid or missing key.', 403);
    }
}
// (CLI cron can also pass key=... as an argument; accept it but do not require it)
if ($isCli && isset($argv)) {
    foreach ($argv as $a) { if (strpos($a, 'key=') === 0) { /* accepted, not required for CLI */ } }
}

// ── Choose a backup directory: OUTSIDE the web root if we can, else the
//    .htaccess-protected folder inside it. ──
// __DIR__ is .../admin/tools ; dirname(__DIR__,2) is the project root
// (public_html); dirname(__DIR__,3) is the account home ABOVE the web root.
$outsideDir = dirname(__DIR__, 3) . '/wbss_secure_backups';
$insideDir  = dirname(__DIR__, 2) . '/admin/uploads/backups';

$backupDir = null;
foreach ([$outsideDir, $insideDir] as $cand) {
    if (!$cand) continue;
    if (!is_dir($cand)) { @mkdir($cand, 0755, true); }
    if (is_dir($cand) && is_writable($cand)) { $backupDir = $cand; break; }
}
if (!$backupDir) {
    backup_fail('no writable backup directory. Create ' . htmlspecialchars($insideDir) . ' and make it writable (0755).');
}

// Make sure the inside dir (if used) blocks web download of the dumps.
if ($backupDir === $insideDir && !is_file($insideDir . '/.htaccess')) {
    @file_put_contents($insideDir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder Allow,Deny\nDeny from all\n</IfModule>\n");
}

// ── Connect ──
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn || $conn->connect_error) {
    backup_fail('database connection failed: ' . ($conn->connect_error ?? 'unknown'));
}
$conn->set_charset('utf8mb4');

// ── Stream the dump to a file ──
$timestamp = date('Y-m-d_H-i-s');
$fileName  = 'backup_' . $timestamp . '.sql';
$filePath  = $backupDir . '/' . $fileName;

$fh = @fopen($filePath, 'w');
if (!$fh) {
    backup_fail('could not open backup file for writing: ' . $filePath);
}

$rowsTotal = 0;
try {
    fwrite($fh, "-- " . (defined('BACKUP_HEADER') ? BACKUP_HEADER : 'Database Backup') . "\n");
    fwrite($fh, "-- Date: " . date('Y-m-d H:i:s T') . "\n");
    fwrite($fh, "-- Database: " . DB_NAME . "\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET NAMES utf8mb4;\n\n");

    // List tables
    $tables = [];
    if ($res = $conn->query("SHOW TABLES")) {
        while ($r = $res->fetch_row()) { $tables[] = $r[0]; }
        $res->free();
    }

    foreach ($tables as $table) {
        // Structure
        if ($cr = $conn->query("SHOW CREATE TABLE `$table`")) {
            $row = $cr->fetch_assoc();
            $cr->free();
            $create = $row['Create Table'] ?? ($row['Create View'] ?? '');
            fwrite($fh, "\n-- ----- Table: $table -----\n");
            fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
            fwrite($fh, $create . ";\n\n");
        }

        // Data — UNBUFFERED read so we never hold the whole table in memory
        $data = $conn->query("SELECT * FROM `$table`", MYSQLI_USE_RESULT);
        if ($data) {
            while ($row = $data->fetch_assoc()) {
                $vals = array_map(function ($v) use ($conn) {
                    return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                }, array_values($row));
                fwrite($fh, "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n");
                $rowsTotal++;
            }
            $data->free();
        }
    }

    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
} catch (Throwable $e) {
    fclose($fh);
    @unlink($filePath); // don't leave a half-written backup
    backup_fail('error while writing backup: ' . $e->getMessage());
}

$sizeMb = round(filesize($filePath) / 1048576, 2);

// ── Keep only the newest 7 backups ──
$all = glob($backupDir . '/*.sql') ?: [];
usort($all, fn($a, $b) => filemtime($b) <=> filemtime($a));
$deleted = 0;
foreach (array_slice($all, 7) as $old) {
    if (@unlink($old)) { $deleted++; }
}

// ── Log it (best effort; ignore if the table isn't there) ──
try {
    $stmt = $conn->prepare("INSERT INTO activity_logs (username, action, details, created_at) VALUES ('BACKUP', 'Database Backup', ?, NOW())");
    if ($stmt) {
        $details = "$fileName ($sizeMb MB, $rowsTotal rows)";
        $stmt->bind_param('s', $details);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) { /* ignore */ }

$conn->close();

// ── Report ──
$outsideWebRoot = ($backupDir !== $insideDir);
$msg = "BACKUP OK\n"
     . "File: $fileName\n"
     . "Size: $sizeMb MB\n"
     . "Rows: $rowsTotal\n"
     . "Location: " . ($outsideWebRoot ? 'outside web root (best)' : 'inside web root (.htaccess protected)') . "\n"
     . "Old backups deleted: $deleted (keeping newest 7)\n";

if ($isCli) {
    echo $msg;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
}
