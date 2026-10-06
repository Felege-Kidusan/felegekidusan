<?php
/**
 * CLI-only nightly reconciliation for the failure-issue registry.
 *
 * Recommended deployment-owned schedule (after mobile_telemetry_retention):
 *   47 3 * * * umask 077 && /usr/local/bin/php \
 *     "/path/to/SSMS/admin/backend/failure_issue_reconcile.php" \
 *     >> "/home/ACCOUNT/failure_issue_reconcile.log" 2>&1
 *
 * This job owns two things and nothing else:
 *   1. Self-healing aggregates — failure_issues counts/first-seen/last-seen
 *      are recomputed from the raw tables (api_sync_attempts,
 *      arkeon_error_log, app_telemetry_events), which stay the source of
 *      truth. Raw tables are never modified.
 *   2. Threshold failure reports — one recorded report per issue per 24h
 *      when the issue qualifies (>= 5 affected installs/users or >= 50
 *      occurrences in 24h, the user-approved defaults).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('WBWS_API_REQUEST', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/services/FailureIssueService.php';

use App\Services\FailureIssueService;

$projectRoot = dirname(__DIR__, 2);
$lockPath = defined('FAILURE_RECONCILE_LOCK_PATH') && FAILURE_RECONCILE_LOCK_PATH !== ''
    ? (string)FAILURE_RECONCILE_LOCK_PATH
    : dirname($projectRoot) . DIRECTORY_SEPARATOR . 'ssms_failure_issue_reconcile.lock';

$lock = null;

try {
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the failure reconcile lock.');
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

    // Fail closed before doing work: the registry and its raw sources must
    // all exist, otherwise counts would be silently wrong.
    $check = $conn->prepare(
        "SELECT COUNT(*) AS c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
               'failure_issues', 'failure_remediation_catalog', 'failure_reports',
               'api_sync_attempts', 'arkeon_error_log', 'app_telemetry_events'
           )"
    );
    if (!$check) {
        throw new RuntimeException('Could not prepare the schema check.');
    }
    $check->execute();
    $tables = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
    $check->close();
    if ($tables !== 6) {
        throw new RuntimeException('Required failure-intelligence tables are unavailable. Apply migration 064.');
    }

    $result = FailureIssueService::reconcile($conn);

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
    error_log('Failure issue reconcile failed: ' . $error->getMessage());
    fwrite(STDERR, "Failure issue reconcile failed.\n");
    exit(1);
}
