<?php
/**
 * Server-observed sync attempt monitoring.
 *
 * This service deliberately stores a bounded, allow-listed projection of a
 * request. It never stores authorization headers, tokens, raw bodies, response
 * bodies, passwords, secrets, or private notes. The monitor is advisory to the
 * business transaction: a bookkeeping failure must not turn a valid business
 * response into a different response.
 */
namespace App\Services;

use mysqli;

// Failure-issue bookkeeping (migration 064). Loaded via class_exists so this
// file stays loadable even if the failure intelligence files are absent from
// an older deployment.
if (!class_exists('\\App\\Services\\FailureIssueService')) {
    require_once __DIR__ . '/FailureIssueService.php';
}

final class ApiSyncAttemptMonitorService
{
    public const STALE_AFTER_MINUTES = 15;
    private const RETENTION_DAYS = 90;
    private const MAX_ENTITY_REFERENCE = 255;

    /** @var array<int,string> */
    private const SAFE_SOURCES = ['foreground', 'background'];

    /** Terminal statuses that count as failures for issue tracking. */
    private const FAILURE_STATUSES = ['failed', 'rejected'];

    /**
     * Extract only bounded identifiers and dates useful to an administrator.
     * Free text, record values, notes, lyrics and other body content are never
     * copied into the monitoring row.
     */
    public static function entityReference(array $payload): ?string
    {
        $allowed = [
            'id', 'class_id', 'assessment_id', 'member_id', 'record_id',
            'hymn_id', 'category_id', 'zemarian_id', 'submission_id',
            'date', 'section', 'kind', 'program_type',
        ];
        $parts = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $payload) || is_array($payload[$key]) || is_object($payload[$key])) {
                continue;
            }
            $value = trim((string)$payload[$key]);
            if ($value === '' || strlen($value) > 64) {
                continue;
            }
            if (in_array($key, ['date'], true) && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                continue;
            }
            if (in_array($key, ['section', 'kind', 'program_type'], true)
                && !preg_match('/^[A-Za-z0-9_. -]{1,64}$/D', $value)) {
                continue;
            }
            if (!in_array($key, ['date', 'section', 'kind', 'program_type'], true)
                && !preg_match('/^-?\d{1,18}$/D', $value)) {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }
        if (isset($payload['records']) && is_array($payload['records'])) {
            $parts[] = 'records_count=' . min(count($payload['records']), 500000);
        }
        if (!$parts) {
            return null;
        }
        return substr(implode(';', $parts), 0, self::MAX_ENTITY_REFERENCE);
    }

    /**
     * Record an acquired idempotent request. Returns the monitor row id or null
     * when the optional monitoring table is unavailable or the insert fails.
     */
    public static function recordStart(?mysqli $conn, array $context): ?int
    {
        $source = self::validatedSource($context['execution_source'] ?? null);
        $attemptNumber = self::validatedAttemptNumber($context['attempt_number'] ?? null);
        return self::insert($conn, $context, 'in_flight', 'acquired', 'pending', null, null, null, $source, $attemptNumber);
    }

    /** Record a replay/conflict/processing event without exposing response data. */
    public static function recordEvent(
        ?mysqli $conn,
        array $context,
        string $status,
        string $idempotencyState,
        int $httpStatus,
        string $retryDecision,
        ?string $errorCategory = null,
        ?string $errorCode = null
    ): ?int {
        $source = self::validatedSource($context['execution_source'] ?? null);
        $attemptNumber = self::validatedAttemptNumber($context['attempt_number'] ?? null);
        return self::insert(
            $conn,
            $context,
            $status,
            $idempotencyState,
            $retryDecision,
            $errorCategory,
            self::safeErrorCode($errorCode),
            $httpStatus,
            $source,
            $attemptNumber
        );
    }

    /**
     * Complete a monitor row inside the caller's transaction. No commit is
     * performed here. A false return is intentionally non-fatal; the normal
     * post-commit completion path will make a best effort.
     */
    public static function completeWithinTransaction(
        ?mysqli $conn,
        int $monitorId,
        string $json,
        int $statusCode,
        string $idempotencyState = 'completed'
    ): bool {
        if ($monitorId <= 0 || !($conn instanceof mysqli)) {
            return false;
        }
        $idempotencyState = in_array($idempotencyState, ['completed', 'abandoned'], true)
            ? $idempotencyState
            : 'completed';
        [$status, $retryDecision, $category, $errorCode] = self::outcome($statusCode, $json);
        try {
            $stmt = $conn->prepare(
                "UPDATE api_sync_attempts
                 SET status=?, idempotency_state=?, retry_decision=?,
                     error_category=?, error_code=?, http_status=?, completed_at=NOW(),
                     updated_at=NOW()
                 WHERE id=? AND status='in_flight'"
            );
            $stmt->bind_param('sssssii', $status, $idempotencyState, $retryDecision, $category, $errorCode, $statusCode, $monitorId);
            $stmt->execute();
            $changed = $stmt->affected_rows === 1;
            $stmt->close();
            // Failure-intelligence bookkeeping (advisory, never-throw). Inside
            // the caller's transaction by necessity — if that transaction
            // rolls back, the issue upsert rolls back with it (consistent).
            if ($changed && in_array($status, self::FAILURE_STATUSES, true)) {
                self::recordFailureIssueForAttempt($conn, $monitorId);
            }
            return $changed;
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    /** Complete a row after the business transaction has returned. */
    public static function complete(
        ?mysqli $conn,
        int $monitorId,
        string $json,
        int $statusCode,
        string $idempotencyState = 'completed'
    ): void {
        if ($monitorId <= 0 || !($conn instanceof mysqli)) {
            return;
        }
        $idempotencyState = in_array($idempotencyState, ['completed', 'abandoned'], true)
            ? $idempotencyState
            : 'completed';
        [$status, $retryDecision, $category, $errorCode] = self::outcome($statusCode, $json);
        try {
            $stmt = $conn->prepare(
                "UPDATE api_sync_attempts
                 SET status=?, idempotency_state=?, retry_decision=?,
                     error_category=?, error_code=?, http_status=?, completed_at=NOW(),
                     updated_at=NOW()
                 WHERE id=? AND status='in_flight'"
            );
            $stmt->bind_param('sssssii', $status, $idempotencyState, $retryDecision, $category, $errorCode, $statusCode, $monitorId);
            $stmt->execute();
            $changed = $stmt->affected_rows === 1;
            $stmt->close();
            // Failure-intelligence bookkeeping, post-commit (never-throw).
            if ($changed && in_array($status, self::FAILURE_STATUSES, true)) {
                self::recordFailureIssueForAttempt($conn, $monitorId);
            }
            self::maybePrune($conn);
        } catch (\Throwable $ignored) {
            // Observability must not alter the already-produced API response.
        }
    }

    /** Return a conservative safe classification of a response. */
    private static function outcome(int $statusCode, string $json): array
    {
        $statusCode = max(100, min($statusCode, 599));
        if ($statusCode >= 200 && $statusCode < 300) {
            return ['completed', 'not_required', null, null];
        }
        $retryable = in_array($statusCode, [408, 425, 429, 500, 502, 503, 504], true);
        $status = $statusCode >= 500 || $statusCode === 429 ? 'failed' : 'rejected';
        $retryDecision = $retryable ? 'retryable' : 'not_retryable';
        $category = self::errorCategory($statusCode);
        $code = null;
        $decoded = json_decode($json, true);
        if (is_array($decoded) && isset($decoded['code']) && is_scalar($decoded['code'])) {
            $code = self::safeErrorCode((string)$decoded['code']);
        }
        return [$status, $retryDecision, $category, $code];
    }

    private static function errorCategory(int $statusCode): string
    {
        if ($statusCode === 401) return 'authentication';
        if ($statusCode === 403) return 'authorization';
        if ($statusCode === 409) return 'conflict';
        if ($statusCode === 408 || $statusCode === 425 || $statusCode === 429) return 'throttled_or_timeout';
        if ($statusCode >= 500) return 'server';
        if ($statusCode >= 400) return 'client';
        return 'unknown';
    }

    private static function safeErrorCode(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9_.-]+$/D', $value)) {
            return null;
        }
        return $value;
    }

    private static function validatedSource($value): ?string
    {
        $value = trim((string)$value);
        return in_array($value, self::SAFE_SOURCES, true) ? $value : null;
    }

    /** Fleet context column: version-shaped string, ≤32 chars, or null. */
    private static function validatedAppVersion($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 32) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,31}$/D', $value) ? $value : null;
    }

    /** Fleet context column: build int 1..4294967295, or null. */
    private static function validatedAppBuild($value): ?int
    {
        if ($value === null || $value === '' || !filter_var($value, FILTER_VALIDATE_INT)) {
            return null;
        }
        $value = (int)$value;
        return ($value >= 1 && $value <= 4294967295) ? $value : null;
    }

    /**
     * Fleet context column: the anonymous installation UUID, validated with
     * the same shape the public telemetry route enforces, or null. This is
     * the join key between the sync-failure and telemetry channels.
     */
    private static function validatedInstallationId($value): ?string
    {
        $value = strtolower(trim((string)$value));
        if ($value === ''
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            return null;
        }
        return $value;
    }

    private static function validatedAttemptNumber($value): ?int
    {
        if ($value === null || $value === '' || !filter_var($value, FILTER_VALIDATE_INT)) {
            return null;
        }
        $value = (int)$value;
        return $value >= 1 && $value <= 100000 ? $value : null;
    }

    private static function validatedId($value): ?int
    {
        if ($value === null || $value === '' || !filter_var($value, FILTER_VALIDATE_INT)) {
            return null;
        }
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    /** @return array{domain:string,operation:string} */
    private static function routeParts(array $context): array
    {
        $scope = trim((string)($context['scope'] ?? ''));
        $route = preg_replace('/^[A-Z]+\s+/', '', $scope) ?: $scope;
        $route = '/' . ltrim($route, '/');
        if (strpos($route, '/api/v1/') !== 0) {
            $route = '/api/v1/' . ltrim($route, '/');
        }
        $path = trim(parse_url($route, PHP_URL_PATH) ?: '', '/');
        $domain = strtolower((string)(explode('/', $path)[2] ?? explode('/', $path)[0] ?? 'unknown'));
        if ($domain === '') $domain = 'unknown';
        return [
            'domain' => substr($domain, 0, 48),
            'operation' => substr($route, 0, 160),
        ];
    }

    private static function insert(
        ?mysqli $conn,
        array $context,
        string $status,
        string $idempotencyState,
        string $retryDecision,
        ?string $errorCategory,
        ?string $errorCode,
        ?int $httpStatus,
        ?string $executionSource,
        ?int $attemptNumber
    ): ?int {
        if (!($conn instanceof mysqli)) {
            return null;
        }
        $requestId = trim((string)($context['request_id'] ?? ''));
        $clientOpId = trim((string)($context['client_op_id'] ?? ''));
        $attemptUid = trim((string)($context['attempt_uid'] ?? ''));
        $userId = self::validatedId($context['user_id'] ?? null);
        $route = self::routeParts($context);
        if (!preg_match('/^req_[A-Za-z0-9_-]{8,64}$/D', $requestId)
            || ($clientOpId !== '' && !preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $clientOpId))
            || ($attemptUid !== '' && !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $attemptUid))
            || $userId === null) {
            return null;
        }
        $clientOpId = $clientOpId !== '' ? $clientOpId : null;
        $attemptUid = $attemptUid !== '' ? $attemptUid : null;
        $entityRef = isset($context['entity_ref']) ? substr((string)$context['entity_ref'], 0, self::MAX_ENTITY_REFERENCE) : null;
        $appVersion = self::validatedAppVersion($context['app_version'] ?? null);
        $appBuild = self::validatedAppBuild($context['app_build'] ?? null);
        $installationId = self::validatedInstallationId($context['installation_id'] ?? null);
        try {
            $stmt = $conn->prepare(
                'INSERT INTO api_sync_attempts
                 (client_op_id, attempt_uid, attempt_number, execution_source, request_id,
                  user_id, domain, operation, entity_ref, status, idempotency_state,
                  retry_decision, error_category, error_code, http_status, app_version,
                  app_build, installation_id, started_at,
                  completed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())'
            );
            // started_at and completed_at are written by the database session
            // clock — the same clock that later completes rows
            // (completed_at=NOW()) and drives the staleness window and range
            // filters in ApiSyncAttemptAdminService. A PHP-side timestamp
            // would skew duration_ms and every range comparison by the
            // PHP/DB timezone offset.
            $stmt->bind_param(
                'ssississssssssisis',
                $clientOpId,
                $attemptUid,
                $attemptNumber,
                $executionSource,
                $requestId,
                $userId,
                $route['domain'],
                $route['operation'],
                $entityRef,
                $status,
                $idempotencyState,
                $retryDecision,
                $errorCategory,
                $errorCode,
                $httpStatus,
                $appVersion,
                $appBuild,
                $installationId
            );
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
            // Failure-intelligence bookkeeping (advisory, never-throw):
            // direct failure/rejected inserts (idempotency conflicts).
            if ($id > 0 && in_array($status, self::FAILURE_STATUSES, true)) {
                FailureIssueService::recordSyncFailure($conn, [
                    'domain' => $route['domain'],
                    'operation' => $route['operation'],
                    'error_category' => $errorCategory,
                    'error_code' => $errorCode,
                    'user_id' => $userId,
                    'installation_id' => $installationId ?? null,
                    'app_version' => $appVersion ?? null,
                    'app_build' => $appBuild ?? null,
                ]);
            }
            self::maybePrune($conn);
            return $id > 0 ? $id : null;
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    /**
     * Failure-intelligence bookkeeping for an attempt that just completed as
     * failed/rejected. Reads the row's own columns (single indexed lookup)
     * and hands them to FailureIssueService. Advisory: never throws, never
     * alters the attempt or the caller's transaction result.
     */
    private static function recordFailureIssueForAttempt(?mysqli $conn, int $monitorId): void
    {
        try {
            if (!($conn instanceof mysqli) || $monitorId <= 0) {
                return;
            }
            $stmt = $conn->prepare(
                'SELECT domain, operation, error_category, error_code, user_id,
                        installation_id, app_version, app_build
                 FROM api_sync_attempts WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('i', $monitorId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row !== null) {
                FailureIssueService::recordSyncFailure($conn, $row);
            }
        } catch (\Throwable $ignored) {
            // Observability must not alter the already-produced API response.
        }
    }

    private static function maybePrune(?mysqli $conn): void
    {
        try {
            if (random_int(1, 100) !== 1) return;
            $conn->query(
                "DELETE FROM api_sync_attempts
                 WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL " . self::RETENTION_DAYS . " DAY)
                   AND status <> 'in_flight'
                 ORDER BY id ASC LIMIT 5000"
            );
        } catch (\Throwable $ignored) {
            // Cleanup is best effort and never participates in a business result.
        }
    }
}
