<?php
/**
 * Read-only admin queries for the server-observed sync attempt ledger.
 */
namespace App\Services;

use mysqli;

final class ApiSyncAttemptAdminService
{
    private const DEFAULT_RANGE = '7d';
    private const MAX_PAGE = 1000;
    private const MAX_LIMIT = 100;

    /** @return array<string,mixed> */
    public static function getOverview(mysqli $conn, array $filters = []): array
    {
        $range = self::range($filters['range'] ?? self::DEFAULT_RANGE);
        $where = ['started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $range . ')'];
        [$params, $types] = [[], ''];
        self::appendFilters($where, $params, $types, $filters);
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $sql = "SELECT
            SUM(status='in_flight') AS in_flight,
            SUM(status IN ('failed','rejected')) AS failed,
            SUM(status='completed') AS completed,
            SUM(status='replayed') AS replayed,
            SUM(status='in_flight' AND started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL " . ApiSyncAttemptMonitorService::STALE_AFTER_MINUTES . " MINUTE)) AS stale,
            SUM(status='failed' AND retry_decision='retryable') AS retryable_failures,
            COUNT(*) AS observed
            FROM api_sync_attempts{$whereSql}";
        $row = self::one($conn, $sql, $types, $params);
        return [
            'counts' => [
                'pending' => null,
                'retrying' => null,
                'failed' => (int)($row['failed'] ?? 0),
                'in_flight' => (int)($row['in_flight'] ?? 0),
                'stale' => (int)($row['stale'] ?? 0),
                'recent_successful' => (int)($row['completed'] ?? 0),
                'replayed' => (int)($row['replayed'] ?? 0),
                'retryable_failures' => (int)($row['retryable_failures'] ?? 0),
                'observed' => (int)($row['observed'] ?? 0),
            ],
            'range' => $filters['range'] ?? self::DEFAULT_RANGE,
            'stale_after_minutes' => ApiSyncAttemptMonitorService::STALE_AFTER_MINUTES,
            'by_app_build' => self::byAppBuild($conn, $whereSql, $types, $params),
            'not_server_observable' => [
                'pending' => 'The server cannot see local outbox rows that have not transmitted.',
                'retrying' => 'The server can classify a retryable failure, but cannot see a future client retry until it arrives.',
                'installation_id' => 'App builds that do not send X-Installation-Id have attempts recorded without an installation identifier (shown as not observed).',
            ],
        ];
    }

    /**
     * Per-build attempt/failure breakdown in the filtered window — the
     * baseline for spotting a regressing build. Builds appear once clients
     * that send X-App-Build write attempts; older clients group under null.
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private static function byAppBuild(mysqli $conn, string $whereSql, string $types, array $params): array
    {
        $rows = self::all($conn, "SELECT
            app_build, COUNT(*) AS attempts,
            SUM(status IN ('failed','rejected')) AS failed
            FROM api_sync_attempts{$whereSql}
            GROUP BY app_build
            ORDER BY attempts DESC
            LIMIT 20", $types, $params);
        $out = [];
        foreach ($rows as $row) {
            $attempts = (int)($row['attempts'] ?? 0);
            $failed = (int)($row['failed'] ?? 0);
            $out[] = [
                'app_build' => $row['app_build'] !== null ? (int)$row['app_build'] : null,
                'attempts' => $attempts,
                'failed' => $failed,
                'failure_rate' => $attempts > 0 ? round(($failed / $attempts) * 100, 2) : 0.0,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function getAttempts(mysqli $conn, array $filters = [], int $page = 1, int $limit = 25): array
    {
        $page = max(1, min(self::MAX_PAGE, $page));
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $range = self::range($filters['range'] ?? '30d');
        $where = ['started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $range . ')'];
        [$params, $types] = [[], ''];
        self::appendFilters($where, $params, $types, $filters);
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $totalRow = self::one($conn, 'SELECT COUNT(*) AS total FROM api_sync_attempts' . $whereSql, $types, $params);
        $total = (int)($totalRow['total'] ?? 0);
        $offset = ($page - 1) * $limit;
        $listParams = $params;
        $listTypes = $types . 'ii';
        $listParams[] = $offset;
        $listParams[] = $limit;
        $rows = self::all($conn, "SELECT
            id, client_op_id, attempt_uid, attempt_number, execution_source,
            request_id, user_id, domain, operation, entity_ref,
            CASE WHEN status='in_flight' AND started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL " . ApiSyncAttemptMonitorService::STALE_AFTER_MINUTES . " MINUTE) THEN 'stale' ELSE status END AS status,
            idempotency_state, retry_decision, error_category, error_code,
            http_status, app_version, app_build, installation_id,
            started_at, completed_at,
            TIMESTAMPDIFF(MICROSECOND, started_at, COALESCE(completed_at, CURRENT_TIMESTAMP)) DIV 1000 AS duration_ms
            FROM api_sync_attempts{$whereSql}
            ORDER BY started_at DESC, id DESC LIMIT ?, ?", $listTypes, $listParams);
        return [
            'items' => array_map([self::class, 'safeRow'], $rows),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => max(1, (int)ceil($total / $limit)),
                'has_more' => ($page * $limit) < $total,
            ],
            'ordering' => 'started_at DESC, id DESC',
            'stale_after_minutes' => ApiSyncAttemptMonitorService::STALE_AFTER_MINUTES,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function getAttempt(mysqli $conn, int $id): ?array
    {
        if ($id <= 0) return null;
        $rows = self::all($conn, "SELECT
            id, client_op_id, attempt_uid, attempt_number, execution_source,
            request_id, user_id, domain, operation, entity_ref,
            CASE WHEN status='in_flight' AND started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL " . ApiSyncAttemptMonitorService::STALE_AFTER_MINUTES . " MINUTE) THEN 'stale' ELSE status END AS status,
            idempotency_state, retry_decision, error_category, error_code,
            http_status, app_version, app_build, installation_id,
            started_at, completed_at,
            TIMESTAMPDIFF(MICROSECOND, started_at, COALESCE(completed_at, CURRENT_TIMESTAMP)) DIV 1000 AS duration_ms
            FROM api_sync_attempts WHERE id=? LIMIT 1", 'i', [$id]);
        return $rows ? self::safeRow($rows[0]) : null;
    }

    private static function appendFilters(array &$where, array &$params, string &$types, array $filters): void
    {
        $domain = trim((string)($filters['domain'] ?? ''));
        if ($domain === 'other') {
            $where[] = "domain NOT IN ('attendance','grades','hr','mezmur','notifications','users')";
        } elseif ($domain !== '' && preg_match('/^[a-z0-9_-]{1,48}$/D', $domain)) {
            $where[] = 'domain=?'; $params[] = $domain; $types .= 's';
        }
        $status = trim((string)($filters['status'] ?? ''));
        $statuses = ['in_flight', 'completed', 'failed', 'rejected', 'replayed', 'stale'];
        if (in_array($status, $statuses, true)) {
            if ($status === 'stale') {
                $where[] = "status='in_flight' AND started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL " . ApiSyncAttemptMonitorService::STALE_AFTER_MINUTES . " MINUTE)";
            } else {
                $where[] = 'status=?'; $params[] = $status; $types .= 's';
            }
        }
        $source = trim((string)($filters['source'] ?? ''));
        if ($source === 'not_observed') {
            $where[] = 'execution_source IS NULL';
        } elseif (in_array($source, ['foreground', 'background'], true)) {
            $where[] = 'execution_source=?'; $params[] = $source; $types .= 's';
        }
        $userId = filter_var($filters['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId !== false && $userId !== null && $userId > 0) {
            $where[] = 'user_id=?'; $params[] = (int)$userId; $types .= 'i';
        }
        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $search = substr($search, 0, 80);
            $where[] = '(client_op_id LIKE ? OR attempt_uid LIKE ? OR request_id LIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle; $params[] = $needle; $params[] = $needle;
            $types .= 'sss';
        }
    }

    private static function range($range): string
    {
        return match ((string)$range) {
            '1h' => '1 HOUR',
            '24h', 'today' => '1 DAY',
            '30d' => '30 DAY',
            'all' => '3650 DAY',
            default => '7 DAY',
        };
    }

    /** @return array<string,mixed> */
    private static function safeRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'client_op_id' => $row['client_op_id'] !== null ? (string)$row['client_op_id'] : null,
            'attempt_uid' => $row['attempt_uid'] !== null ? (string)$row['attempt_uid'] : null,
            'attempt_number' => $row['attempt_number'] !== null ? (int)$row['attempt_number'] : null,
            'execution_source' => $row['execution_source'] !== null ? (string)$row['execution_source'] : null,
            'request_id' => (string)($row['request_id'] ?? ''),
            'user_id' => (int)($row['user_id'] ?? 0),
            'domain' => (string)($row['domain'] ?? ''),
            'operation' => (string)($row['operation'] ?? ''),
            'entity_ref' => $row['entity_ref'] !== null ? (string)$row['entity_ref'] : null,
            'status' => (string)($row['status'] ?? ''),
            'idempotency_state' => (string)($row['idempotency_state'] ?? ''),
            'retry_decision' => (string)($row['retry_decision'] ?? ''),
            'error_category' => $row['error_category'] !== null ? (string)$row['error_category'] : null,
            'error_code' => $row['error_code'] !== null ? (string)$row['error_code'] : null,
            'http_status' => $row['http_status'] !== null ? (int)$row['http_status'] : null,
            'app_version' => $row['app_version'] !== null ? (string)$row['app_version'] : null,
            'app_build' => $row['app_build'] !== null ? (int)$row['app_build'] : null,
            'installation_id' => $row['installation_id'] !== null ? (string)$row['installation_id'] : null,
            'started_at' => (string)($row['started_at'] ?? ''),
            'completed_at' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
            'duration_ms' => (int)($row['duration_ms'] ?? 0),
        ];
    }

    /** @param array<int,mixed> $params */
    private static function one(mysqli $conn, string $sql, string $types, array $params): array
    {
        $rows = self::all($conn, $sql, $types, $params);
        return $rows[0] ?? [];
    }

    /** @param array<int,mixed> $params @return array<int,array<string,mixed>> */
    private static function all(mysqli $conn, string $sql, string $types, array $params): array
    {
        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $refs = [];
            foreach ($params as $key => &$value) $refs[$key] = &$value;
            $stmt->bind_param($types, ...$refs);
            unset($value);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}
