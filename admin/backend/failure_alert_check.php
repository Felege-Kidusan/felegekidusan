<?php
/**
 * CLI-only failure alert evaluation — runs every 15 minutes.
 *
 * Recommended deployment-owned schedule (every fifteen minutes — note the
 * docblock-safe spelling; a literal "star-slash 15" would close this
 * comment early):
 *   0,15,30,45 * * * * umask 077 && /usr/local/bin/php \
 *     "/path/to/SSMS/admin/backend/failure_alert_check.php" \
 *     >> "/home/ACCOUNT/failure_alert_check.log" 2>&1
 *
 * Evaluates the three alert conditions (new crash identities, build
 * velocity vs fleet baseline, multi-window sync error budget) and sends
 * through the notification center (super_admin) and, when configured,
 * the same Telegram bot the error monitor uses. Cooldowns live in
 * failure_alerts (migration 065). This job never modifies the raw
 * telemetry/monitor tables and its output is aggregate-only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('WBWS_API_REQUEST', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/services/FailureAlertService.php';

use App\Services\FailureAlertService;

$projectRoot = dirname(__DIR__, 2);
$lockPath = defined('FAILURE_ALERT_LOCK_PATH') && FAILURE_ALERT_LOCK_PATH !== ''
    ? (string)FAILURE_ALERT_LOCK_PATH
    : dirname($projectRoot) . DIRECTORY_SEPARATOR . 'ssms_failure_alert_check.lock';

$lock = null;

try {
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the failure alert lock.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, json_encode(['lock_acquired' => false], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fclose($lock);
        exit(0);
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    $conn->set_charset('utf8mb4');

    // Fail closed before doing work: the alert ledger, its raw sources and
    // the notification channel must all exist.
    $check = $conn->prepare(
        "SELECT COUNT(*) AS c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
               'failure_alerts', 'failure_issues', 'failure_remediation_catalog',
               'api_sync_attempts', 'app_telemetry_events', 'notifications'
           )"
    );
    if (!$check) {
        throw new RuntimeException('Could not prepare the schema check.');
    }
    $check->execute();
    $tables = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
    $check->close();
    if ($tables !== 6) {
        throw new RuntimeException('Required failure-alert tables are unavailable. Apply migrations 064 and 065.');
    }

    $result = FailureAlertService::evaluate($conn);

    fwrite(STDOUT, json_encode(
        ['lock_acquired' => true] + $result,
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);

    $conn->close();
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('Failure alert check failed: ' . $error->getMessage());
    fwrite(STDERR, "Failure alert check failed.\n");
    exit(1);
}
