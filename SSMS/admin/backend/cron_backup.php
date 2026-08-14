<?php
/**
 * ============================================================
 * Automated Daily Backup — Cron Script
 * ============================================================
 * 
 * PURPOSE: Creates daily SQL backups, keeps last 7 days, deletes old ones.
 * 
 * HOW TO SET UP IN cPanel:
 * 1. Go to cPanel → Cron Jobs
 * 2. Set: Once Per Day (0 2 * * *)  ← runs at 2:00 AM
 * 3. Command: /usr/bin/php /home/{HOSTING_USER}/domains/{SITE_DOMAIN}/public_html/admin/backend/cron_backup.php
 * 4. Save
 * 
 * TEST: Visit in browser: https://' . SITE_DOMAIN . '/admin/backend/cron_backup.php?key={backup_key}_backup_2026
 * (Uses a secret key to prevent unauthorized access via browser)
 * 
 * BACKUP LOCATION: /admin/uploads/backups/
 * RETENTION: 7 days (configurable below)
 * ============================================================
 */

// ── Configuration ──
// BACKUP_KEY is loaded from env file (via config.php)
// If not defined, use a secure fallback that blocks browser access
define('KEEP_DAYS', 7);                    // Keep backups for 7 days

// ── Security: Only allow cron (CLI) or browser with correct key ──
$isCli = (php_sapi_name() === 'cli');

// Load config to get BACKUP_KEY from env file
if (!$isCli) {
    // For browser access, load config first to get the key
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/admin/backend/cron_backup.php';
    $_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? '/admin/backend/cron_backup.php';
}

$hasKey = isset($_GET['key']) && defined('BACKUP_KEY') && $_GET['key'] === BACKUP_KEY;

if (!$isCli && !$hasKey) {
    http_response_code(403);
    die('Access denied.');
}

// ── Load database config ──
// Suppress session/header errors when running from cron (CLI)
if ($isCli) {
    // Prevent session_start() from running in config.php
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/admin/backend/cron_backup.php';
    $_SERVER['PHP_SELF'] = '/admin/backend/cron_backup.php';
}

require_once __DIR__ . '/config.php';

// ── Setup ──
$backupDir = ROOT_PATH . '/admin/uploads/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$timestamp = date('Y-m-d_H-i-s');
$filename = "auto_backup_{$timestamp}.sql";
$filepath = $backupDir . '/' . $filename;
$log = [];

$log[] = "=== " . (defined('BACKUP_HEADER') ? BACKUP_HEADER : 'Auto Backup') . "  — " . date('Y-m-d H:i:s') . " ===";

// ── Create Backup ──
try {
    if (!isset($conn) || !$conn || $conn->connect_error) {
        throw new Exception("Database connection failed");
    }
    
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    $log[] = "Found " . count($tables) . " tables";
    
    $sql = "-- ============================================================\n";
    $sql .= "-- ' . BACKUP_HEADER . '\n";
    $sql .= "-- Date: " . date('Y-m-d H:i:s T') . "\n";
    $sql .= "-- Database: " . DB_NAME . "\n";
    $sql .= "-- Tables: " . count($tables) . "\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $sql .= "SET time_zone = '+03:00';\n";
    $sql .= "SET NAMES utf8mb4;\n\n";
    
    foreach ($tables as $table) {
        // Table structure
        $createResult = $conn->query("SHOW CREATE TABLE `$table`");
        $createRow = $createResult->fetch_row();
        $sql .= "-- Table: $table\n";
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $createRow[1] . ";\n\n";
        
        // Table data
        $dataResult = $conn->query("SELECT * FROM `$table`");
        $rowCount = 0;
        
        if ($dataResult && $dataResult->num_rows > 0) {
            while ($row = $dataResult->fetch_assoc()) {
                $values = array_map(function($v) use ($conn) {
                    if ($v === null) return 'NULL';
                    return "'" . $conn->real_escape_string($v) . "'";
                }, array_values($row));
                $sql .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                $rowCount++;
            }
        }
        
        $sql .= "\n";
        $log[] = "  $table: $rowCount rows";
    }
    
    // Write file
    $bytes = file_put_contents($filepath, $sql);
    
    if ($bytes === false) {
        throw new Exception("Failed to write backup file");
    }
    
    $sizeMb = round($bytes / 1024 / 1024, 2);
    $log[] = "Backup saved: $filename ($sizeMb MB)";
    
    // Log to activity_logs if table exists
    try {
        $logDetail = $filename . ' (' . $sizeMb . ' MB)';
        $logStmt = $conn->prepare("INSERT INTO activity_logs (username, action, details, ip_address, created_at) VALUES ('CRON', 'Auto Backup', ?, 'localhost', NOW())");
        if ($logStmt) {
            $logStmt->bind_param('s', $logDetail);
            $logStmt->execute();
            $logStmt->close();
        }
    } catch (Exception $e) { /* activity_logs might not exist */ }
    
} catch (Exception $e) {
    $log[] = "ERROR: " . $e->getMessage();
    error_log("Auto Backup FAILED: " . $e->getMessage());
}

// ── Cleanup old backups ──
$log[] = "";
$log[] = "Cleaning backups older than " . KEEP_DAYS . " days...";

$cutoff = time() - (KEEP_DAYS * 86400);
$deleted = 0;

foreach (glob($backupDir . '/*.sql') as $file) {
    if (filemtime($file) < $cutoff) {
        if (@unlink($file)) {
            $deleted++;
            $log[] = "  Deleted: " . basename($file);
        }
    }
}

$log[] = "Deleted $deleted old backup(s)";

// ── Count remaining backups ──
$remaining = count(glob($backupDir . '/*.sql'));
$log[] = "Backups on disk: $remaining";

// ── Output ──
$output = implode("\n", $log);

if ($isCli) {
    echo $output . "\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<html><head><title><?= defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School' ?> Auto Backup</title>";
    echo "<style>body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:2rem;max-width:700px;margin:0 auto}";
    echo "pre{background:#1e293b;padding:1.5rem;border-radius:8px;line-height:1.6}</style></head><body>";
    echo "<h1>🗄️ " . (defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School') . " Auto Backup</h1>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
    echo "<p><a href='/admin/dashboard.php' style='color:#60a5fa'>← Back to Dashboard</a></p>";
    echo "</body></html>";
}
