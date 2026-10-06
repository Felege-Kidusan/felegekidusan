<?php
/**
 * ============================================================
 * FailureAlertService — symptom-based alert evaluation
 * ============================================================
 * Three alert conditions (user-approved thresholds), evaluated on a
 * 15-minute CLI schedule (admin/backend/failure_alert_check.php):
 *
 *   crash_new   — a crash identity first seen < 24h ago has already hit
 *                 >= 5 distinct installations in 24h.
 *   velocity    — an app build first seen < 7 days old fails at >= 2x the
 *                 trailing 30-day failure rate of the rest of the fleet,
 *                 with a noise floor (>= 20 attempts, >= 5% rate).
 *   sync_budget — the fleet sync-failure rate is >= 10% over BOTH the last
 *                 hour and the last 6 hours (multi-window suppresses blips;
 *                 >= 20 attempts per window).
 *
 * Channels: the existing notification center (sendNotification,
 * target_roles = super_admin) and — when configured — the same Telegram
 * bot the error monitor uses. Every alert carries the remediation
 * one-liner for its dominant failure category (runbook pairing).
 *
 * Cooldowns live in failure_alerts (migration 065): 24h for per-condition
 * alerts, 6h for the fleet error budget. All evaluation is read-only over
 * the raw tables; the only writes are failure_alerts rows and
 * notifications. Nothing here ever throws.
 */

namespace App\Services;

use mysqli;

final class FailureAlertService
{
    // ── Thresholds (user-approved defaults; constants are the config) ────────
    public const CRASH_NEW_MIN_INSTALLS = 5;
    public const VELOCITY_MAX_BUILD_AGE_DAYS = 7;
    public const VELOCITY_BASELINE_DAYS = 30;
    public const VELOCITY_MULTIPLIER = 2.0;
    public const VELOCITY_MIN_ATTEMPTS = 20;
    public const VELOCITY_MIN_RATE = 5.0;
    public const SYNC_BUDGET_RATE_THRESHOLD = 10.0;
    public const SYNC_BUDGET_MIN_ATTEMPTS = 20;
    public const COOLDOWN_HOURS_CONDITION = 24;
    public const COOLDOWN_HOURS_BUDGET = 6;

    private const CRASH_EVENT_TYPES = ['crash', 'crash_recorded'];
    private const FAILURE_STATUSES = "status IN ('failed','rejected')";

    /** @var array<string,bool> */
    private static $workflowLoaded = [];

    // ============================================================
    // Evaluation entry point (never throws)
    // ============================================================

    /** @return array<string,int> */
    public static function evaluate(mysqli $conn): array
    {
        $out = [
            'crash_new' => 0,
            'velocity' => 0,
            'sync_budget' => 0,
            'sent' => 0,
            'suppressed' => 0,
        ];
        try {
            self::checkCrashNew($conn, $out);
        } catch (\Throwable $ignored) {
            error_log('Failure alert crash_new check failed: ' . $ignored->getMessage());
        }
        try {
            self::checkVelocity($conn, $out);
        } catch (\Throwable $ignored) {
            error_log('Failure alert velocity check failed: ' . $ignored->getMessage());
        }
        try {
            self::checkSyncBudget($conn, $out);
        } catch (\Throwable $ignored) {
            error_log('Failure alert sync_budget check failed: ' . $ignored->getMessage());
        }
        return $out;
    }

    // ============================================================
    // Alert history for the dashboard
    // ============================================================

    /** @return array<int,array<string,mixed>> */
    public static function getRecentAlerts(mysqli $conn, int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));
        return self::all($conn,
            'SELECT alert_key, kind, severity, title, payload_json,
                    first_sent_at, last_sent_at, sent_count
             FROM failure_alerts
             ORDER BY last_sent_at DESC, id DESC LIMIT ' . $limit);
    }

    // ============================================================
    // Condition 1: new crash identity spreading across the fleet
    // ============================================================

    private static function checkCrashNew(mysqli $conn, array &$out): void
    {
        $types = "'" . implode("','", self::CRASH_EVENT_TYPES) . "'";
        $rows = self::all($conn,
            "SELECT dedupe_key AS crash_key, COUNT(DISTINCT installation_id) AS installs
             FROM app_telemetry_events
             WHERE event_type IN ({$types}) AND dedupe_key IS NOT NULL
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)
             GROUP BY dedupe_key
             HAVING installs >= " . self::CRASH_NEW_MIN_INSTALLS);
        foreach ($rows as $row) {
            $crashKey = (string)$row['crash_key'];
            $installs = (int)$row['installs'];
            // Newness comes from the issue registry's lifetime first_seen_at.
            $issue = self::one($conn,
                "SELECT id, title, category, first_seen_at, remediation_cause
                 FROM failure_issues
                 WHERE issue_key = ? AND source = 'crash' LIMIT 1", 's', [$crashKey]);
            if ($issue === null) {
                continue; // Registry not built yet — reconcile will create it.
            }
            $ageHours = self::hoursSince($issue['first_seen_at']);
            if ($ageHours === null || $ageHours > 24) {
                continue; // Not a new identity.
            }
            $sig = self::one($conn,
                'SELECT signature_class FROM app_crash_signatures WHERE crash_key = ? LIMIT 1',
                's', [$crashKey]);
            $builds = self::all($conn,
                "SELECT app_build, COUNT(*) AS c FROM app_telemetry_events
                 WHERE event_type IN ({$types}) AND dedupe_key = ?
                   AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)
                 GROUP BY app_build ORDER BY c DESC LIMIT 5", 's', [$crashKey]);
            $buildParts = [];
            foreach ($builds as $b) {
                $buildParts[] = ($b['app_build'] !== null ? 'build ' . $b['app_build'] : 'unknown build') . ' (' . $b['c'] . ')';
            }
            $lines = [
                $installs . ' distinct installations crashed with this identity in the last 24h.',
                'Signature: ' . ($sig !== null && $sig['signature_class'] !== ''
                    ? (string)$sig['signature_class'] : 'not yet reported (pre-signature build)'),
                'Where: ' . ($buildParts ? implode(', ', $buildParts) : 'builds not observed'),
                'Fix: ' . self::remediationLine(
                    $conn,
                    'crash',
                    (string)$issue['category'],
                    $issue['remediation_cause'],
                    'Open the Failure Intelligence issue for the crash signature and the affected builds.'
                ),
            ];
            $out['crash_new']++;
            self::dispatch(
                $conn,
                'crash_new:' . $crashKey,
                'crash_new',
                $installs >= 10 ? 'critical' : 'high',
                '[crash] New crash identity: ' . ($sig !== null && $sig['signature_class'] !== ''
                    ? (string)$sig['signature_class'] : substr($crashKey, 0, 8) . '…'),
                $lines,
                $out
            );
        }
    }

    // ============================================================
    // Condition 2: velocity — a new build failing above the baseline
    // ============================================================

    private static function checkVelocity(mysqli $conn, array &$out): void
    {
        $candidates = self::all($conn,
            'SELECT app_build, COUNT(*) AS attempts,
                    SUM(' . self::FAILURE_STATUSES . ') AS failed
             FROM api_sync_attempts
             WHERE app_build IS NOT NULL
               AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)
             GROUP BY app_build
             HAVING attempts >= ' . self::VELOCITY_MIN_ATTEMPTS);
        foreach ($candidates as $candidate) {
            $build = (int)$candidate['app_build'];
            // All-time first appearance of this build (build age).
            $first = self::one($conn,
                'SELECT MIN(started_at) AS first_seen FROM api_sync_attempts WHERE app_build = ?',
                'i', [$build]);
            $ageHours = $first !== null ? self::hoursSince($first['first_seen']) : null;
            if ($ageHours === null
                || $ageHours > self::VELOCITY_MAX_BUILD_AGE_DAYS * 24) {
                continue; // Not a new build.
            }
            $attempts = (int)$candidate['attempts'];
            $failed = (int)$candidate['failed'];
            $rate = ($attempts > 0) ? ($failed / $attempts) * 100 : 0.0;
            if ($rate < self::VELOCITY_MIN_RATE) {
                continue; // Below the noise floor.
            }
            // Trailing baseline: the rest of the fleet over 30 days.
            $baseline = self::one($conn,
                'SELECT COUNT(*) AS attempts, SUM(' . self::FAILURE_STATUSES . ') AS failed
                 FROM api_sync_attempts
                 WHERE app_build IS NOT NULL AND app_build <> ?
                   AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL '
                    . self::VELOCITY_BASELINE_DAYS . ' DAY)', 'i', [$build]);
            $baseAttempts = (int)($baseline['attempts'] ?? 0);
            $baseRate = $baseAttempts > 0
                ? ((int)($baseline['failed'] ?? 0) / $baseAttempts) * 100 : null;
            if ($baseRate !== null && $rate <= $baseRate * self::VELOCITY_MULTIPLIER) {
                continue; // Within 2x of the fleet baseline.
            }
            $top = self::topCategory($conn,
                'SELECT error_category, COUNT(*) AS c FROM api_sync_attempts
                 WHERE ' . self::FAILURE_STATUSES . ' AND app_build = ?
                   AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)
                 GROUP BY error_category ORDER BY c DESC LIMIT 1', 'i', [$build]);
            $lines = [
                'Build ' . $build . ' (first seen ' . round((float)$ageHours) . 'h ago) is failing at '
                    . round($rate, 2) . '% over 24h (' . $failed . '/' . $attempts . ' attempts).',
                $baseRate !== null
                    ? 'Fleet baseline (other builds, ' . self::VELOCITY_BASELINE_DAYS . 'd): '
                        . round($baseRate, 2) . '% — this build is '
                        . round($rate / max($baseRate, 0.01), 1) . 'x the baseline.'
                    : 'No fleet baseline yet — threshold is the ' . self::VELOCITY_MIN_RATE . '% floor.',
                $top !== null
                    ? 'Dominant failure: ' . $top['error_category'] . ' (' . $top['c'] . ')'
                    : '',
                'Fix: ' . self::remediationLine(
                    $conn,
                    'sync',
                    $top !== null ? (string)$top['error_category'] : '',
                    null,
                    'Compare this build\'s failures in Failure Intelligence; consider rolling back the release channel.'
                ),
            ];
            $lines = array_values(array_filter($lines, static fn ($l) => $l !== ''));
            $out['velocity']++;
            self::dispatch(
                $conn,
                'velocity:' . $build,
                'velocity',
                $rate >= 25.0 ? 'critical' : 'high',
                '[velocity] Build ' . $build . ' failing at ' . round($rate, 2) . '% (fleet baseline '
                    . ($baseRate !== null ? round($baseRate, 2) . '%' : 'n/a') . ')',
                $lines,
                $out
            );
        }
    }

    // ============================================================
    // Condition 3: fleet sync error budget (multi-window)
    // ============================================================

    private static function checkSyncBudget(mysqli $conn, array &$out): void
    {
        $short = self::windowRate($conn, 1);
        $long = self::windowRate($conn, 6);
        foreach ([$short, $long] as $window) {
            if ($window['attempts'] < self::SYNC_BUDGET_MIN_ATTEMPTS) {
                return; // Not enough traffic to judge.
            }
        }
        if ($short['rate'] < self::SYNC_BUDGET_RATE_THRESHOLD
            || $long['rate'] < self::SYNC_BUDGET_RATE_THRESHOLD) {
            return; // Multi-window: BOTH windows must breach.
        }
        $top = self::topCategory($conn,
            'SELECT error_category, COUNT(*) AS c FROM api_sync_attempts
             WHERE ' . self::FAILURE_STATUSES . '
               AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 6 HOUR)
             GROUP BY error_category ORDER BY c DESC LIMIT 1', '', []);
        $lines = [
            'Fleet sync failure rate: ' . round($short['rate'], 2) . '% over the last hour ('
                . $short['failed'] . '/' . $short['attempts'] . ') and '
                . round($long['rate'], 2) . '% over the last 6 hours ('
                . $long['failed'] . '/' . $long['attempts'] . ').',
            'Both windows exceed the ' . self::SYNC_BUDGET_RATE_THRESHOLD . '% error budget.',
            $top !== null
                ? 'Dominant failure: ' . $top['error_category'] . ' (' . $top['c'] . ' in 6h)'
                : '',
            'Fix: ' . self::remediationLine(
                $conn,
                'sync',
                $top !== null ? (string)$top['error_category'] : '',
                null,
                'Open Failure Intelligence — the top issues list shows what changed.'
            ),
        ];
        $lines = array_values(array_filter($lines, static fn ($l) => $l !== ''));
        $out['sync_budget']++;
        self::dispatch(
            $conn,
            'sync_budget:default',
            'sync_budget',
            $long['rate'] >= 25.0 ? 'critical' : 'high',
            '[error budget] Fleet sync failure rate ' . round($long['rate'], 2) . '% (6h) / '
                . round($short['rate'], 2) . '% (1h)',
            $lines,
            $out
        );
    }

    /** @return array{attempts:int,failed:int,rate:float} */
    private static function windowRate(mysqli $conn, int $hours): array
    {
        $row = self::one($conn,
            'SELECT COUNT(*) AS attempts, SUM(' . self::FAILURE_STATUSES . ') AS failed
             FROM api_sync_attempts
             WHERE started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . max(1, $hours) . ' HOUR)');
        $attempts = (int)($row['attempts'] ?? 0);
        $failed = (int)($row['failed'] ?? 0);
        return [
            'attempts' => $attempts,
            'failed' => $failed,
            'rate' => $attempts > 0 ? ($failed / $attempts) * 100 : 0.0,
        ];
    }

    // ============================================================
    // Dispatch: cooldown ledger + channels
    // ============================================================

    /** @param array<int,string> $lines */
    private static function dispatch(
        mysqli $conn,
        string $alertKey,
        string $kind,
        string $severity,
        string $title,
        array $lines,
        array &$out
    ): void {
        $cooldownHours = $kind === 'sync_budget'
            ? self::COOLDOWN_HOURS_BUDGET
            : self::COOLDOWN_HOURS_CONDITION;
        $existing = self::one($conn,
            'SELECT last_sent_at FROM failure_alerts WHERE alert_key = ? LIMIT 1',
            's', [$alertKey]);
        if ($existing !== null) {
            $since = self::hoursSince($existing['last_sent_at']);
            if ($since !== null && $since < $cooldownHours) {
                $out['suppressed']++;
                return;
            }
        }

        $payload = json_encode(['title' => $title, 'lines' => $lines], JSON_UNESCAPED_UNICODE) ?: '{}';
        $stmt = $conn->prepare(
            'INSERT INTO failure_alerts
             (alert_key, kind, severity, title, payload_json, first_sent_at, last_sent_at, sent_count)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1)
             ON DUPLICATE KEY UPDATE
                severity = VALUES(severity),
                title = VALUES(title),
                payload_json = VALUES(payload_json),
                last_sent_at = NOW(),
                sent_count = sent_count + 1'
        );
        if ($stmt) {
            try {
                $stmt->bind_param('sssss', $alertKey, $kind, $severity, $title, $payload);
                $stmt->execute();
                $stmt->close();
            } catch (\Throwable $ignored) {
                // Cooldown ledger failure must not prevent the notification.
            }
        }

        $message = mb_substr($title . "\n" . implode("\n", $lines), 0, 1500);
        self::notify($conn, $severity, $title, $message);
        $out['sent']++;
    }

    private static function notify(mysqli $conn, string $severity, string $title, string $message): void
    {
        // Channel 1: the existing notification center, super_admin only.
        try {
            if (!function_exists('sendNotification')) {
                $workflow = dirname(__DIR__, 2) . '/admin/backend/workflow.php';
                if (!isset(self::$workflowLoaded[$workflow]) && is_file($workflow)) {
                    require_once $workflow;
                    self::$workflowLoaded[$workflow] = true;
                }
            }
            if (function_exists('sendNotification')) {
                sendNotification($conn, 'failure_alert', $title, $message, [
                    // The notifications table enum is low/normal/high/urgent —
                    // 'critical' maps to 'urgent'.
                    'priority' => $severity === 'critical' ? 'urgent' : 'high',
                    'target_roles' => ['super_admin'],
                ]);
            }
        } catch (\Throwable $ignored) {
            error_log('Failure alert notification-center send failed: ' . $ignored->getMessage());
        }

        // Channel 2: the same Telegram bot the error monitor uses, when the
        // deployment has configured it (identical gating constants).
        try {
            if (!defined('MONITOR_TELEGRAM_ENABLED') || !MONITOR_TELEGRAM_ENABLED) return;
            $token = defined('MONITOR_TELEGRAM_BOT_TOKEN') ? MONITOR_TELEGRAM_BOT_TOKEN : '';
            $chatId = defined('MONITOR_TELEGRAM_CHAT_ID') ? MONITOR_TELEGRAM_CHAT_ID : '';
            if ($token === '' || $chatId === '' || !function_exists('curl_init')) return;
            $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
            if ($ch === false) return;
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => true,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $ignored) {
            // Telegram is best-effort, exactly like the error monitor's sender.
        }
    }

    // ============================================================
    // Helpers
    // ============================================================

    /** One-line remediation for the dominant category (runbook pairing). */
    private static function remediationLine(
        mysqli $conn,
        string $source,
        string $category,
        ?string $issueOverride,
        string $fallback
    ): string {
        if ($issueOverride !== null && trim($issueOverride) !== '') {
            $first = trim(explode("\n", trim($issueOverride))[0]);
            return $first !== '' ? $first : $fallback;
        }
        if ($category !== '') {
            $row = self::one($conn,
                'SELECT cause FROM failure_remediation_catalog
                 WHERE source = ? AND category = ? LIMIT 1', 'ss', [$source, $category]);
            if ($row !== null && $row['cause'] !== null) {
                $first = trim(explode("\n", trim((string)$row['cause']))[0]);
                if ($first !== '') return $first;
            }
        }
        return $fallback;
    }

    /** @return array<string,mixed>|null */
    private static function topCategory(mysqli $conn, string $sql, string $types, array $params): ?array
    {
        $rows = self::all($conn, $sql, $types, $params);
        return $rows ? $rows[0] : null;
    }

    private static function hoursSince(?string $datetime): ?float
    {
        if ($datetime === null || $datetime === '') return null;
        try {
            $ts = (new \DateTimeImmutable($datetime))->getTimestamp();
            return max(0.0, (time() - $ts) / 3600);
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private static function one(mysqli $conn, string $sql, string $types = '', array $params = [])
    {
        $rows = self::all($conn, $sql, $types, $params);
        return $rows ? $rows[0] : null;
    }

    /** @return array<int,array<string,mixed>> */
    private static function all(mysqli $conn, string $sql, string $types = '', array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        try {
            if ($types !== '' && $params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $rows;
        } catch (\Throwable $ignored) {
            return [];
        }
    }
}
