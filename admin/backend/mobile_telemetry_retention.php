<?php
/**
 * CLI-only retention for first-party mobile telemetry.
 *
 * Recommended deployment-owned schedule:
 *   23 3 * * * umask 077 && /usr/local/bin/php "/path/to/SSMS/admin/backend/mobile_telemetry_retention.php" >> "/home/ACCOUNT/mobile_telemetry_retention.log" 2>&1
 *
 * This job owns diagnostic telemetry retention only. It never deletes from
 * app_installations, api_sync_attempts, mobile outboxes, or user-data tables.
 * Retention is deployment/cron work, never request-time work.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('WBWS_API_REQUEST', true);
require_once __DIR__ . '/../config.php';

const MOBILE_TELEMETRY_RETENTION_DAYS = 90;
const MOBILE_TELEMETRY_BATCH_SIZE = 5000;
const MOBILE_TELEMETRY_MAX_BATCHES_PER_TABLE = 10;

$projectRoot = dirname(__DIR__, 2);
$lockPath = defined('TELEMETRY_RETENTION_LOCK_PATH') && TELEMETRY_RETENTION_LOCK_PATH !== ''
    ? (string)TELEMETRY_RETENTION_LOCK_PATH
    : dirname($projectRoot) . DIRECTORY_SEPARATOR . 'ssms_mobile_telemetry_retention.lock';

$lock = null;

try {
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the telemetry retention lock.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, json_encode([
            'lock_acquired' => false,
            'retention_days' => MOBILE_TELEMETRY_RETENTION_DAYS,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fclose($lock);
        exit(0);
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    $conn->set_charset('utf8mb4');

    $tables = [
        'app_telemetry_events' => 'created_at',
        'app_downloads' => 'downloaded_at',
    ];
    foreach ($tables as $table => $timeColumn) {
        $check = $conn->prepare(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        if (!$check) {
            throw new RuntimeException('Could not prepare the telemetry schema check.');
        }
        $check->bind_param('s', $table);
        if (!$check->execute() || $check->get_result()->num_rows !== 1) {
            $check->close();
            throw new RuntimeException('Required telemetry table is unavailable.');
        }
        $check->close();
    }

    $deleted = [
        'app_telemetry_events' => 0,
        'app_downloads' => 0,
    ];
    $batches = [
        'app_telemetry_events' => 0,
        'app_downloads' => 0,
    ];
    $backlogPossible = false;

    foreach ($tables as $table => $timeColumn) {
        for ($batch = 0; $batch < MOBILE_TELEMETRY_MAX_BATCHES_PER_TABLE; $batch++) {
            $sql = "DELETE FROM `{$table}`
                    WHERE `{$timeColumn}` < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL "
                . MOBILE_TELEMETRY_RETENTION_DAYS . " DAY)
                    ORDER BY `id` ASC LIMIT " . MOBILE_TELEMETRY_BATCH_SIZE;
            if (!$conn->query($sql)) {
                throw new RuntimeException('Telemetry retention delete failed.');
            }
            $affected = (int)$conn->affected_rows;
            $deleted[$table] += $affected;
            $batches[$table]++;
            if ($affected < MOBILE_TELEMETRY_BATCH_SIZE) {
                break;
            }
            if ($batch === MOBILE_TELEMETRY_MAX_BATCHES_PER_TABLE - 1) {
                $backlogPossible = true;
            }
        }
    }

    fwrite(STDOUT, json_encode([
        'lock_acquired' => true,
        'retention_days' => MOBILE_TELEMETRY_RETENTION_DAYS,
        'batch_size' => MOBILE_TELEMETRY_BATCH_SIZE,
        'max_batches_per_table' => MOBILE_TELEMETRY_MAX_BATCHES_PER_TABLE,
        'deleted_events' => $deleted['app_telemetry_events'],
        'deleted_downloads' => $deleted['app_downloads'],
        'event_batches' => $batches['app_telemetry_events'],
        'download_batches' => $batches['app_downloads'],
        'backlog_possible' => $backlogPossible,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $conn->close();
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
} catch (Throwable $error) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('Mobile telemetry retention failed: ' . $error->getMessage());
    fwrite(STDERR, "Mobile telemetry retention failed.\n");
    exit(1);
}
