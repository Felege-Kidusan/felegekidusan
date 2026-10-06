<?php
/**
 * ============================================================
 * FailureIssueService — unified failure intelligence engine
 * ============================================================
 * One issue registry for the three failure channels:
 *   sync   — failed/rejected rows in api_sync_attempts
 *            (fingerprint: md5(category|code|domain|operation))
 *   server — rows in arkeon_error_log (monitor/error_monitor.php)
 *            (fingerprint: md5(error_type|file_path|line_number))
 *   crash  — crash identities in the telemetry event log
 *            (fingerprint: the SHA-256 crash key itself)
 *
 * Design rules:
 *   * Ingest hooks are NEVER allowed to affect the host write path:
 *     every hook catches everything and returns void.
 *   * failure_issues stores only what an upsert maintains correctly
 *     (identity, title, first/last seen, lifetime occurrences, status,
 *     regression count, admin remediation override). Window counts,
 *     affected users/installs and distributions are computed at read
 *     time from the raw tables, which remain the source of truth.
 *   * Recorded reports (failure_reports) are generated on demand and by
 *     the nightly reconcile job (threshold-guarded, once per 24h per
 *     issue) — never inside a caller's business transaction.
 */

namespace App\Services;

use mysqli;

final class FailureIssueService
{
    /** Auto-report thresholds (user-approved defaults). */
    public const AUTO_REPORT_MIN_AFFECTED = 5;
    public const AUTO_REPORT_MIN_OCCURRENCES = 50;
    public const REPORT_WINDOW_DAYS = 7;
    public const TIMELINE_DAYS = 14;

    private const SYNC_FAILURE_STATUSES = ['failed', 'rejected'];
    private const CRASH_EVENT_TYPES = ['crash', 'crash_recorded'];

    // ============================================================
    // Ingest hooks (never-throw)
    // ============================================================

    /**
     * Called after a failed/rejected attempt row is written or completed.
     * $row keys: domain, operation, error_category, error_code, user_id,
     * installation_id, app_version, app_build (all optional except
     * domain/operation).
     */
    public static function recordSyncFailure(?mysqli $conn, array $row): void
    {
        try {
            if (!($conn instanceof mysqli)) return;
            $category = self::norm((string)($row['error_category'] ?? ''));
            $code = self::norm((string)($row['error_code'] ?? ''));
            $domain = self::norm((string)($row['domain'] ?? ''));
            $operation = self::norm((string)($row['operation'] ?? ''));
            if ($domain === '' && $operation === '') return;
            $issueKey = md5(strtolower($category . '|' . $code . '|' . $domain . '|' . $operation));
            $title = self::titleSync($category, $code, $domain, $operation);
            self::upsert($conn, $issueKey, 'sync', $category, $title);
        } catch (\Throwable $ignored) {
            // Failure-intelligence bookkeeping must never break a sync write.
        }
    }

    /**
     * Called after an error row is persisted by monitor/error_monitor.php.
     * $row keys: error_type, file_path, line_number.
     */
    public static function recordServerError(?mysqli $conn, array $row): void
    {
        try {
            if (!($conn instanceof mysqli)) return;
            $errorType = self::norm((string)($row['error_type'] ?? ''));
            $file = self::norm((string)($row['file_path'] ?? ''));
            $line = (string)((int)($row['line_number'] ?? 0));
            if ($errorType === '' && $file === '') return;
            $issueKey = md5(strtolower($errorType . '|' . $file . '|' . $line));
            // Catalog family: the text before any ':' ("Uncaught Exception: X"
            // -> "Uncaught Exception"; "Fatal Error" stays itself).
            $family = trim(explode(':', $errorType, 2)[0]);
            if ($family === '') $family = $errorType;
            $title = self::titleServer($errorType, $file, $line);
            self::upsert($conn, $issueKey, 'server_error', $family, $title);
        } catch (\Throwable $ignored) {
            // The monitor's own write already succeeded; nothing here may fail it.
        }
    }

    /**
     * Called from the telemetry route whenever a crash identity or its
     * signature is recorded. $signatureClass may be '' when only the count
     * upsert ran (crash event without a signature yet).
     */
    public static function recordCrashIssue(
        ?mysqli $conn,
        string $crashKey,
        string $signatureClass,
        array $frames
    ): void {
        try {
            if (!($conn instanceof mysqli)) return;
            $crashKey = strtolower(trim($crashKey));
            if (!preg_match('/^[0-9a-f]{64}$/D', $crashKey)) return;
            $class = self::norm($signatureClass);
            $title = $class !== ''
                ? self::titleCrash($class, $frames)
                : ('Crash ' . substr($crashKey, 0, 8) . '…');
            self::upsert($conn, $crashKey, 'crash', $class, $title);
        } catch (\Throwable $ignored) {
            // Crash telemetry must never fail because of issue bookkeeping.
        }
    }

    // ============================================================
    // Overview (golden signals)
    // ============================================================

    /** @return array<string,mixed> */
    public static function getOverview(mysqli $conn): array
    {
        $out = [
            'sync' => [],
            'issues' => ['open' => 0],
            'crash_free' => null,
            'generated_at' => null,
        ];

        // Sync signals (24h).
        $row = self::one($conn,
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status IN ('failed','rejected')), 0) AS failed
             FROM api_sync_attempts
             WHERE started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)");
        $total = (int)($row['total'] ?? 0);
        $failed = (int)($row['failed'] ?? 0);
        $out['sync'] = [
            'attempts_24h' => $total,
            'failures_24h' => $failed,
            'failure_rate_24h' => $total > 0 ? round(($failed / $total) * 100, 2) : 0.0,
            'attempts_per_min_24h' => $total > 0 ? round($total / 1440, 2) : 0.0,
        ];

        // p95 duration of completed attempts (24h). Window functions need a
        // recent MariaDB; degrade gracefully to a null on failure.
        try {
            $p = self::one($conn,
                "SELECT PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY
                    TIMESTAMPDIFF(MICROSECOND, started_at, completed_at) DIV 1000) AS p95
                 FROM api_sync_attempts
                 WHERE status = 'completed'
                   AND completed_at IS NOT NULL
                   AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)");
            $out['sync']['p95_duration_ms_24h'] = $p['p95'] !== null ? (int)$p['p95'] : null;
        } catch (\Throwable $ignored) {
            $out['sync']['p95_duration_ms_24h'] = null;
        }

        // Issues.
        $row = self::one($conn, "SELECT COUNT(*) AS c FROM failure_issues WHERE status <> 'resolved'");
        $out['issues']['open'] = (int)($row['c'] ?? 0);
        $row = self::one($conn, "SELECT COUNT(*) AS c FROM failure_issues WHERE regression_count > 0");
        $out['issues']['regressed'] = (int)($row['c'] ?? 0);

        // Server errors (24h, error/critical).
        $row = self::one($conn,
            "SELECT COUNT(*) AS c FROM arkeon_error_log
             WHERE severity IN ('error','critical')
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)");
        $out['server_errors_24h'] = (int)($row['c'] ?? 0);

        // Crash-free installs (24h): installs active vs installs that crashed.
        $active = self::one($conn,
            "SELECT COUNT(*) AS c FROM app_installations
             WHERE last_seen_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)");
        $crashed = self::one($conn,
            "SELECT COUNT(DISTINCT installation_id) AS c FROM app_telemetry_events
             WHERE event_type IN ('crash','crash_recorded')
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)");
        $activeCount = (int)($active['c'] ?? 0);
        $crashedCount = (int)($crashed['c'] ?? 0);
        $out['crash_free'] = [
            'active_installs_24h' => $activeCount,
            'crashed_installs_24h' => $crashedCount,
            'crash_free_percent_24h' => $activeCount > 0
                ? round((1 - ($crashedCount / $activeCount)) * 100, 2)
                : null,
        ];

        $out['generated_at'] = date('c');
        return $out;
    }

    // ============================================================
    // Issues list / detail
    // ============================================================

    /**
     * Issues with window aggregates merged from the raw tables, sorted by
     * impact (affected, then occurrences) in the selected window.
     * @return array<string,mixed>
     */
    public static function getIssues(mysqli $conn, array $filters = [], int $page = 1, int $limit = 25): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $window = self::windowDays($filters['window'] ?? '7d');

        $where = [];
        $params = [];
        $types = '';
        $source = (string)($filters['source'] ?? '');
        if (in_array($source, ['sync', 'server_error', 'crash'], true)) {
            $where[] = 'source = ?';
            $params[] = $source;
            $types .= 's';
        }
        $status = (string)($filters['status'] ?? '');
        if (in_array($status, ['open', 'acknowledged', 'resolved'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
            $types .= 's';
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $rows = self::all($conn,
            "SELECT id, issue_key, source, category, title, first_seen_at, last_seen_at,
                    total_occurrences, status, regression_count,
                    (remediation_cause IS NOT NULL OR remediation_steps IS NOT NULL) AS has_override
             FROM failure_issues{$whereSql}
             ORDER BY last_seen_at DESC, id DESC LIMIT 2000", $types, $params);

        // Window aggregates from the raw tables (one grouped query per source).
        $aggregates = self::windowAggregates($conn, $window, $source ?: null);

        $items = [];
        foreach ($rows as $row) {
            $key = (string)$row['issue_key'];
            $agg = $aggregates[$key] ?? ['occurrences' => 0, 'affected' => 0];
            $items[] = [
                'id' => (int)$row['id'],
                'issue_key' => $key,
                'source' => (string)$row['source'],
                'category' => (string)$row['category'],
                'title' => (string)$row['title'],
                'first_seen_at' => (string)$row['first_seen_at'],
                'last_seen_at' => (string)$row['last_seen_at'],
                'total_occurrences' => (int)$row['total_occurrences'],
                'occurrences' => $agg['occurrences'],
                'affected' => $agg['affected'],
                'status' => (string)$row['status'],
                'regression_count' => (int)$row['regression_count'],
                'has_remediation_override' => (int)$row['has_override'] === 1,
            ];
        }

        // Impact ranking: most affected first, then occurrences (Play
        // Console / App Insights triage order).
        usort($items, static function (array $a, array $b): int {
            if ($b['affected'] !== $a['affected']) return $b['affected'] <=> $a['affected'];
            if ($b['occurrences'] !== $a['occurrences']) return $b['occurrences'] <=> $a['occurrences'];
            return strcmp($b['last_seen_at'], $a['last_seen_at']);
        });

        $total = count($items);
        $offset = ($page - 1) * $limit;
        return [
            'items' => array_slice($items, $offset, $limit),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => max(1, (int)ceil($total / $limit)),
            ],
            'window' => $filters['window'] ?? '7d',
        ];
    }

    /** @return array<string,mixed>|null */
    public static function getIssue(mysqli $conn, int $id): ?array
    {
        if ($id <= 0) return null;
        $rows = self::all($conn,
            'SELECT id, issue_key, source, category, title, first_seen_at, last_seen_at,
                    total_occurrences, status, resolved_at, resolved_note, resolved_by,
                    regression_count, remediation_cause, remediation_steps, remediation_link
             FROM failure_issues WHERE id = ? LIMIT 1', 'i', [$id]);
        if (!$rows) return null;
        $issue = $rows[0];

        // Remediation: per-issue override wins, else the seeded catalog.
        $remediation = [
            'cause' => $issue['remediation_cause'],
            'steps' => $issue['remediation_steps'],
            'link' => $issue['remediation_link'],
            'source_of_truth' => $issue['remediation_cause'] !== null || $issue['remediation_steps'] !== null
                ? 'admin_override' : 'catalog_or_none',
        ];
        if ($remediation['cause'] === null && $remediation['steps'] === null) {
            $cat = self::all($conn,
                'SELECT cause, fix_steps, doc_link FROM failure_remediation_catalog
                 WHERE source = ? AND category = ? LIMIT 1',
                'ss', [(string)$issue['source'], (string)$issue['category']]);
            if ($cat) {
                $remediation['cause'] = $cat[0]['cause'];
                $remediation['steps'] = $cat[0]['fix_steps'];
                $remediation['link'] = $cat[0]['doc_link'];
                $remediation['source_of_truth'] = 'catalog_seed';
            }
        }

        $aggregates = self::windowAggregates($conn, 1, (string)$issue['source']);
        $agg24 = $aggregates[(string)$issue['issue_key']] ?? ['occurrences' => 0, 'affected' => 0];
        $aggregates7 = self::windowAggregates($conn, 7, (string)$issue['source']);
        $agg7 = $aggregates7[(string)$issue['issue_key']] ?? ['occurrences' => 0, 'affected' => 0];

        return [
            'id' => (int)$issue['id'],
            'issue_key' => (string)$issue['issue_key'],
            'source' => (string)$issue['source'],
            'category' => (string)$issue['category'],
            'title' => (string)$issue['title'],
            'first_seen_at' => (string)$issue['first_seen_at'],
            'last_seen_at' => (string)$issue['last_seen_at'],
            'total_occurrences' => (int)$issue['total_occurrences'],
            'occurrences_24h' => $agg24['occurrences'],
            'affected_24h' => $agg24['affected'],
            'occurrences_7d' => $agg7['occurrences'],
            'affected_7d' => $agg7['affected'],
            'status' => (string)$issue['status'],
            'resolved_at' => $issue['resolved_at'] !== null ? (string)$issue['resolved_at'] : null,
            'resolved_note' => $issue['resolved_note'] !== null ? (string)$issue['resolved_note'] : null,
            'resolved_by' => $issue['resolved_by'] !== null ? (string)$issue['resolved_by'] : null,
            'regression_count' => (int)$issue['regression_count'],
            'remediation' => $remediation,
            'evidence' => self::evidence($conn, $issue),
            'timeline' => self::timeline($conn, $issue, self::TIMELINE_DAYS),
        ];
    }

    // ============================================================
    // Admin mutations
    // ============================================================

    /** @return array<string,mixed>|null */
    public static function updateIssue(mysqli $conn, int $id, array $fields, string $actor): ?array
    {
        if ($id <= 0) return null;
        $sets = [];
        $params = [];
        $types = '';
        $status = (string)($fields['status'] ?? '');
        if ($status !== '') {
            if (!in_array($status, ['open', 'acknowledged', 'resolved'], true)) return null;
            $sets[] = 'status = ?';
            $params[] = $status;
            $types .= 's';
            if ($status === 'resolved') {
                $sets[] = 'resolved_at = NOW()';
                $note = mb_substr(trim((string)($fields['resolved_note'] ?? '')), 0, 500);
                if ($note !== '') {
                    $sets[] = 'resolved_note = ?';
                    $params[] = $note;
                    $types .= 's';
                }
                $sets[] = 'resolved_by = ?';
                $params[] = mb_substr($actor, 0, 100);
                $types .= 's';
            } else {
                $sets[] = 'resolved_at = NULL';
                $sets[] = 'resolved_note = NULL';
                $sets[] = 'resolved_by = NULL';
            }
        }
        foreach ([
            'remediation_cause' => 4000,
            'remediation_steps' => 4000,
            'remediation_link' => 255,
        ] as $field => $max) {
            if (array_key_exists($field, $fields)) {
                $value = mb_substr(trim((string)$fields[$field]), 0, $max);
                $sets[] = "{$field} = ?";
                $params[] = $value !== '' ? $value : null;
                $types .= 's';
            }
        }
        if (!$sets) return null;
        $params[] = $id;
        $types .= 'i';
        $stmt = $conn->prepare("UPDATE failure_issues SET " . implode(', ', $sets) . ' WHERE id = ?');
        if (!$stmt) return null;
        try {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            return self::getIssue($conn, $id);
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public static function generateReport(mysqli $conn, int $issueId, string $trigger, string $actor): ?array
    {
        if ($issueId <= 0) return null;
        if (!in_array($trigger, ['threshold', 'manual', 'reconcile'], true)) $trigger = 'manual';
        $issue = self::getIssue($conn, $issueId);
        if ($issue === null) return null;

        $windowEnd = new \DateTimeImmutable('now');
        $windowStart = $windowEnd->modify('-' . self::REPORT_WINDOW_DAYS . ' days');
        $severity = self::severityFor($issue);

        $markdown = self::buildReportMarkdown($issue, $windowStart, $windowEnd, $severity, $actor);

        $stmt = $conn->prepare(
            'INSERT INTO failure_reports
             (issue_id, severity, window_start, window_end, `trigger`, report_markdown, generated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) return null;
        try {
            $wStart = $windowStart->format('Y-m-d H:i:s');
            $wEnd = $windowEnd->format('Y-m-d H:i:s');
            $stmt->bind_param(
                'issssss',
                $issueId,
                $severity,
                $wStart,
                $wEnd,
                $trigger,
                $markdown,
                $actor
            );
            $stmt->execute();
            $reportId = (int)$stmt->insert_id;
            $stmt->close();
            $conn->query(
                'UPDATE failure_issues SET last_auto_report_at = NOW() WHERE id = '
                . (int)$issueId
            );
            return self::getReport($conn, $reportId);
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    public static function getReports(mysqli $conn, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $totalRow = self::one($conn, 'SELECT COUNT(*) AS c FROM failure_reports');
        $total = (int)($totalRow['c'] ?? 0);
        $offset = ($page - 1) * $limit;
        $rows = self::all($conn,
            "SELECT r.id, r.issue_id, r.severity, r.window_start, r.window_end, r.`trigger`,
                    r.generated_by, r.created_at, i.title AS issue_title, i.source
             FROM failure_reports r
             JOIN failure_issues i ON i.id = r.issue_id
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?, ?", 'ii', [$offset, $limit]);
        return [
            'items' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => max(1, (int)ceil($total / $limit)),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function getReport(mysqli $conn, int $id): ?array
    {
        if ($id <= 0) return null;
        $rows = self::all($conn,
            'SELECT r.id, r.issue_id, r.severity, r.window_start, r.window_end, r.`trigger`,
                    r.report_markdown, r.generated_by, r.created_at, i.title AS issue_title, i.source
             FROM failure_reports r
             JOIN failure_issues i ON i.id = r.issue_id
             WHERE r.id = ? LIMIT 1', 'i', [$id]);
        return $rows ? $rows[0] : null;
    }

    // ============================================================
    // Reconcile (nightly CLI) — self-healing aggregates + threshold reports
    // ============================================================

    /** @return array<string,int> */
    public static function reconcile(mysqli $conn): array
    {
        $out = ['sync_issues' => 0, 'server_issues' => 0, 'crash_issues' => 0, 'reports_generated' => 0];

        // Sync failures.
        $rows = self::all($conn,
            "SELECT error_category, error_code, domain, operation,
                    COUNT(*) AS c, MIN(started_at) AS f, MAX(started_at) AS l
             FROM api_sync_attempts
             WHERE status IN ('failed','rejected')
             GROUP BY error_category, error_code, domain, operation LIMIT 5000");
        foreach ($rows as $row) {
            $category = (string)$row['error_category'];
            $code = (string)$row['error_code'];
            $domain = (string)$row['domain'];
            $operation = (string)$row['operation'];
            $key = md5(strtolower($category . '|' . $code . '|' . $domain . '|' . $operation));
            if (self::upsertReconcile($conn, $key, 'sync', (string)$category,
                self::titleSync($category, $code, $domain, $operation),
                (int)$row['c'], $row['f'], $row['l'])) {
                $out['sync_issues']++;
            }
        }

        // Server errors.
        $rows = self::all($conn,
            "SELECT error_type, file_path, line_number,
                    COUNT(*) AS c, MIN(created_at) AS f, MAX(created_at) AS l
             FROM arkeon_error_log
             GROUP BY error_type, file_path, line_number LIMIT 5000");
        foreach ($rows as $row) {
            $errorType = (string)$row['error_type'];
            $file = (string)$row['file_path'];
            $line = (string)$row['line_number'];
            $key = md5(strtolower($errorType . '|' . $file . '|' . $line));
            $family = trim(explode(':', $errorType, 2)[0]);
            if ($family === '') $family = $errorType;
            if (self::upsertReconcile($conn, $key, 'server_error', $family,
                self::titleServer($errorType, $file, $line),
                (int)$row['c'], $row['f'], $row['l'])) {
                $out['server_issues']++;
            }
        }

        // Crashes.
        $rows = self::all($conn,
            "SELECT dedupe_key, COUNT(*) AS c, MIN(created_at) AS f, MAX(created_at) AS l
             FROM app_telemetry_events
             WHERE event_type IN ('crash','crash_recorded') AND dedupe_key IS NOT NULL
             GROUP BY dedupe_key LIMIT 5000");
        foreach ($rows as $row) {
            $key = (string)$row['dedupe_key'];
            // Refresh the title from the signature table when available.
            $sig = self::one($conn,
                'SELECT signature_class, signature_frames FROM app_crash_signatures
                 WHERE crash_key = ? LIMIT 1', 's', [$key]);
            $class = $sig !== null ? (string)$sig['signature_class'] : '';
            $frames = $sig !== null && $sig['signature_frames'] !== null
                ? (array)json_decode((string)$sig['signature_frames'], true) : [];
            $title = $class !== ''
                ? self::titleCrash($class, is_array($frames) ? $frames : [])
                : ('Crash ' . substr($key, 0, 8) . '…');
            if (self::upsertReconcile($conn, $key, 'crash', $class, $title,
                (int)$row['c'], $row['f'], $row['l'])) {
                $out['crash_issues']++;
            }
        }

        // Threshold reports: at most one per issue per 24h.
        $candidates = self::all($conn,
            "SELECT id, issue_key, source FROM failure_issues
             WHERE last_auto_report_at IS NULL
                OR last_auto_report_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)
             LIMIT 500");
        foreach ($candidates as $candidate) {
            $aggregates = self::windowAggregates($conn, 1, (string)$candidate['source']);
            $agg = $aggregates[(string)$candidate['issue_key']] ?? null;
            if ($agg === null) continue;
            if ($agg['affected'] >= self::AUTO_REPORT_MIN_AFFECTED
                || $agg['occurrences'] >= self::AUTO_REPORT_MIN_OCCURRENCES) {
                if (self::generateReport($conn, (int)$candidate['id'], 'reconcile', 'system') !== null) {
                    $out['reports_generated']++;
                }
            }
        }

        return $out;
    }

    // ============================================================
    // Internals
    // ============================================================

    private static function upsert(mysqli $conn, string $issueKey, string $source, string $category, string $title): void
    {
        $stmt = $conn->prepare(
            'INSERT INTO failure_issues
             (issue_key, source, category, title, first_seen_at, last_seen_at, total_occurrences)
             VALUES (?, ?, ?, ?, NOW(), NOW(), 1)
             ON DUPLICATE KEY UPDATE
                last_seen_at = NOW(),
                total_occurrences = total_occurrences + 1,
                title = IF(VALUES(title) != \'\', VALUES(title), title),
                category = IF(VALUES(category) != \'\', VALUES(category), category),
                regression_count = regression_count + IF(status = \'resolved\', 1, 0),
                resolved_at = IF(status = \'resolved\', NULL, resolved_at),
                status = IF(status = \'resolved\', \'open\', status)'
        );
        if (!$stmt) return;
        // Assignment order matters: ON DUPLICATE KEY UPDATE assignments are
        // evaluated left-to-right and later assignments see the NEW values of
        // earlier ones. The regression_count/resolved_at expressions must
        // therefore read the pre-update status BEFORE status itself is
        // reassigned last — a resolved issue that recurs reopens as a
        // regression (Sentry semantics).
        $stmt->bind_param('ssss', $issueKey, $source, $category, $title);
        $stmt->execute();
        $stmt->close();
    }

    private static function upsertReconcile(
        mysqli $conn,
        string $issueKey,
        string $source,
        string $category,
        string $title,
        int $count,
        string $firstSeen,
        string $lastSeen
    ): bool {
        $stmt = $conn->prepare(
            'INSERT INTO failure_issues
             (issue_key, source, category, title, first_seen_at, last_seen_at, total_occurrences)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_occurrences = VALUES(total_occurrences),
                first_seen_at = LEAST(first_seen_at, VALUES(first_seen_at)),
                last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at)),
                title = IF(VALUES(title) != \'\', VALUES(title), title),
                category = IF(VALUES(category) != \'\', VALUES(category), category)'
        );
        if (!$stmt) return false;
        try {
            $stmt->bind_param('sssssis', $issueKey, $source, $category, $title, $count, $firstSeen, $lastSeen);
            $stmt->execute();
            $stmt->close();
            return true;
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    /**
     * Window aggregates per issue key, computed from the raw tables.
     * @return array<string,array{occurrences:int,affected:int}>
     */
    private static function windowAggregates(mysqli $conn, int $days, ?string $sourceOnly = null): array
    {
        $days = max(1, min(90, $days));
        $out = [];
        $filter = static function (string $source) use ($sourceOnly): bool {
            return $sourceOnly === null || $sourceOnly === $source;
        };

        if ($filter('sync')) {
            $rows = self::all($conn,
                "SELECT error_category, error_code, domain, operation,
                        COUNT(*) AS occurrences, COUNT(DISTINCT user_id) AS affected
                 FROM api_sync_attempts
                 WHERE status IN ('failed','rejected')
                   AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)
                 GROUP BY error_category, error_code, domain, operation LIMIT 2000");
            foreach ($rows as $row) {
                $key = md5(strtolower(
                    (string)$row['error_category'] . '|' . (string)$row['error_code']
                    . '|' . (string)$row['domain'] . '|' . (string)$row['operation']
                ));
                $out[$key] = [
                    'occurrences' => (int)$row['occurrences'],
                    'affected' => (int)$row['affected'],
                ];
            }
        }

        if ($filter('server_error')) {
            $rows = self::all($conn,
                "SELECT error_type, file_path, line_number, COUNT(*) AS occurrences
                 FROM arkeon_error_log
                 WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)
                 GROUP BY error_type, file_path, line_number LIMIT 2000");
            foreach ($rows as $row) {
                $key = md5(strtolower(
                    (string)$row['error_type'] . '|' . (string)$row['file_path']
                    . '|' . (string)$row['line_number']
                ));
                $out[$key] = [
                    'occurrences' => (int)$row['occurrences'],
                    'affected' => (int)$row['occurrences'],
                ];
            }
        }

        if ($filter('crash')) {
            $types = "'" . implode("','", self::CRASH_EVENT_TYPES) . "'";
            $rows = self::all($conn,
                "SELECT dedupe_key, COUNT(*) AS occurrences,
                        COUNT(DISTINCT installation_id) AS affected
                 FROM app_telemetry_events
                 WHERE event_type IN ({$types}) AND dedupe_key IS NOT NULL
                   AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)
                 GROUP BY dedupe_key LIMIT 2000");
            foreach ($rows as $row) {
                $out[(string)$row['dedupe_key']] = [
                    'occurrences' => (int)$row['occurrences'],
                    'affected' => (int)$row['affected'],
                ];
            }
        }

        return $out;
    }

    /**
     * Correlated evidence for an issue detail view, straight from the raw
     * tables (request ids for the sync monitor; samples for the server
     * monitor; builds and device cohorts for crashes).
     * @return array<string,mixed>
     */
    private static function evidence(mysqli $conn, array $issue): array
    {
        $evidence = ['request_ids' => [], 'samples' => [], 'by_app_build' => [], 'by_device_model' => []];
        $source = (string)$issue['source'];

        if ($source === 'sync') {
            $category = (string)$issue['category'];
            // Recover the fingerprint parts from the raw columns by matching
            // the computed key in PHP over the recent window.
            $rows = self::all($conn,
                "SELECT error_category, error_code, domain, operation
                 FROM api_sync_attempts
                 WHERE status IN ('failed','rejected')
                   AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
                 GROUP BY error_category, error_code, domain, operation LIMIT 2000");
            $parts = null;
            foreach ($rows as $row) {
                $key = md5(strtolower(
                    (string)$row['error_category'] . '|' . (string)$row['error_code']
                    . '|' . (string)$row['domain'] . '|' . (string)$row['operation']
                ));
                if ($key === (string)$issue['issue_key']) {
                    $parts = $row;
                    break;
                }
            }
            if ($parts !== null) {
                $predicate = "error_category = ? AND error_code <=> ? AND domain = ? AND operation = ?";
                $args = [
                    (string)$parts['error_category'], (string)$parts['error_code'],
                    (string)$parts['domain'], (string)$parts['operation'],
                ];
                $evidence['request_ids'] = self::all($conn,
                    "SELECT request_id, started_at, status, http_status, error_code
                     FROM api_sync_attempts
                     WHERE status IN ('failed','rejected') AND {$predicate}
                     ORDER BY started_at DESC LIMIT 10", 'ssss', $args);
                $evidence['by_app_build'] = self::all($conn,
                    "SELECT app_build, COUNT(*) AS c
                     FROM api_sync_attempts
                     WHERE status IN ('failed','rejected') AND {$predicate}
                       AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)
                     GROUP BY app_build ORDER BY c DESC LIMIT 10", 'ssss', $args);
            }
        } elseif ($source === 'server_error') {
            $rows = self::all($conn,
                'SELECT error_type, file_path, line_number FROM arkeon_error_log
                 WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
                 GROUP BY error_type, file_path, line_number LIMIT 2000');
            $parts = null;
            foreach ($rows as $row) {
                $key = md5(strtolower(
                    (string)$row['error_type'] . '|' . (string)$row['file_path']
                    . '|' . (string)$row['line_number']
                ));
                if ($key === (string)$issue['issue_key']) {
                    $parts = $row;
                    break;
                }
            }
            if ($parts !== null) {
                $evidence['samples'] = self::all($conn,
                    'SELECT error_type, severity, message, file_path, line_number, url, created_at
                     FROM arkeon_error_log
                     WHERE error_type = ? AND file_path = ? AND line_number = ?
                     ORDER BY created_at DESC LIMIT 5',
                    'ssi', [(string)$parts['error_type'], (string)$parts['file_path'], (int)$parts['line_number']]);
            }
        } else {
            $key = (string)$issue['issue_key'];
            $types = "'" . implode("','", self::CRASH_EVENT_TYPES) . "'";
            $evidence['by_app_build'] = self::all($conn,
                "SELECT app_build, COUNT(*) AS c
                 FROM app_telemetry_events
                 WHERE event_type IN ({$types}) AND dedupe_key = ?
                 GROUP BY app_build ORDER BY c DESC LIMIT 10", 's', [$key]);
            $evidence['by_device_model'] = self::all($conn,
                "SELECT i.device_model, COUNT(*) AS c
                 FROM app_telemetry_events e
                 JOIN app_installations i ON i.installation_id = e.installation_id
                 WHERE e.event_type IN ({$types}) AND e.dedupe_key = ?
                 GROUP BY i.device_model ORDER BY c DESC LIMIT 10", 's', [$key]);
            $sig = self::one($conn,
                'SELECT signature_class, signature_frames, first_seen_at, last_seen_at, total_events
                 FROM app_crash_signatures WHERE crash_key = ? LIMIT 1', 's', [$key]);
            if ($sig !== null) {
                $evidence['crash_signature'] = $sig;
            }
        }

        return $evidence;
    }

    /** @return array<int,array<string,mixed>> */
    private static function timeline(mysqli $conn, array $issue, int $days): array
    {
        $source = (string)$issue['source'];
        if ($source === 'crash') {
            $types = "'" . implode("','", self::CRASH_EVENT_TYPES) . "'";
            return self::all($conn,
                "SELECT DATE(created_at) AS day, COUNT(*) AS c
                 FROM app_telemetry_events
                 WHERE event_type IN ({$types}) AND dedupe_key = ?
                   AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)
                 GROUP BY DATE(created_at) ORDER BY day", 's', [(string)$issue['issue_key']]);
        }
        if ($source === 'server_error') {
            // Server timelines group by family + location key from raw rows.
            $rows = self::all($conn,
                'SELECT error_type, file_path, line_number FROM arkeon_error_log
                 WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
                 GROUP BY error_type, file_path, line_number LIMIT 2000');
            $parts = null;
            foreach ($rows as $row) {
                $key = md5(strtolower(
                    (string)$row['error_type'] . '|' . (string)$row['file_path']
                    . '|' . (string)$row['line_number']
                ));
                if ($key === (string)$issue['issue_key']) {
                    $parts = $row;
                    break;
                }
            }
            if ($parts === null) return [];
            return self::all($conn,
                "SELECT DATE(created_at) AS day, COUNT(*) AS c
                 FROM arkeon_error_log
                 WHERE error_type = ? AND file_path = ? AND line_number = ?
                   AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)
                 GROUP BY DATE(created_at) ORDER BY day",
                'ssi', [(string)$parts['error_type'], (string)$parts['file_path'], (int)$parts['line_number']]);
        }
        // Sync: recover parts the same way as evidence().
        $rows = self::all($conn,
            "SELECT error_category, error_code, domain, operation
             FROM api_sync_attempts
             WHERE status IN ('failed','rejected')
               AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
             GROUP BY error_category, error_code, domain, operation LIMIT 2000");
        $parts = null;
        foreach ($rows as $row) {
            $key = md5(strtolower(
                (string)$row['error_category'] . '|' . (string)$row['error_code']
                . '|' . (string)$row['domain'] . '|' . (string)$row['operation']
            ));
            if ($key === (string)$issue['issue_key']) {
                $parts = $row;
                break;
            }
        }
        if ($parts === null) return [];
        return self::all($conn,
            "SELECT DATE(started_at) AS day, COUNT(*) AS c
             FROM api_sync_attempts
             WHERE status IN ('failed','rejected')
               AND error_category = ? AND error_code <=> ? AND domain = ? AND operation = ?
               AND started_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$days} DAY)
             GROUP BY DATE(started_at) ORDER BY day",
            'ssss', [
                (string)$parts['error_category'], (string)$parts['error_code'],
                (string)$parts['domain'], (string)$parts['operation'],
            ]);
    }

    private static function buildReportMarkdown(
        array $issue,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        string $severity,
        string $actor
    ): string {
        $src = [
            'sync' => 'Sync attempt failures (api_sync_attempts)',
            'server_error' => 'Server errors (arkeon_error_log)',
            'crash' => 'Mobile app crashes (telemetry crash chain)',
        ][$issue['source']] ?? $issue['source'];

        $lines = [];
        $lines[] = '# Failure Report — ' . $issue['title'];
        $lines[] = '';
        $lines[] = '- **Severity:** ' . $severity;
        $lines[] = '- **Channel:** ' . $src;
        $lines[] = '- **Category:** ' . ($issue['category'] !== '' ? $issue['category'] : 'uncategorised');
        $lines[] = '- **Issue ID:** #' . $issue['id'] . ' (key `' . substr((string)$issue['issue_key'], 0, 16) . '…`)';
        $lines[] = '- **Window:** ' . $windowStart->format('Y-m-d H:i') . ' → ' . $windowEnd->format('Y-m-d H:i')
            . ' (report covers the last ' . self::REPORT_WINDOW_DAYS . ' days; lifetime figures below)';
        $lines[] = '- **Status:** ' . $issue['status']
            . ($issue['regression_count'] > 0 ? ' (regressed ' . $issue['regression_count'] . '×)' : '');
        $lines[] = '';

        $lines[] = '## What fails';
        $lines[] = '';
        $lines[] = $issue['title'] . ' — ' . $issue['occurrences_24h'] . ' occurrences in the last 24h, '
            . $issue['occurrences_7d'] . ' in the last 7d; lifetime ' . $issue['total_occurrences']
            . ' since first seen ' . $issue['first_seen_at'] . '.';
        $lines[] = '';

        $lines[] = '## Why it fails (likely cause)';
        $lines[] = '';
        $rem = $issue['remediation'];
        $lines[] = $rem['cause'] !== null && $rem['cause'] !== ''
            ? (string)$rem['cause']
            : 'No cause is catalogued for this category yet — add one from the issue view (it will be attached to future reports).';
        $lines[] = '';

        $lines[] = '## When it fails';
        $lines[] = '';
        if (!empty($issue['timeline'])) {
            foreach ($issue['timeline'] as $day) {
                $lines[] = '- ' . $day['day'] . ': ' . $day['c'] . ' occurrences';
            }
        } else {
            $lines[] = 'No occurrences recorded in the timeline window.';
        }
        $lines[] = '';

        $lines[] = '## Where it fails';
        $lines[] = '';
        $ev = $issue['evidence'];
        if (!empty($ev['by_app_build'])) {
            $parts = [];
            foreach ($ev['by_app_build'] as $b) {
                $parts[] = ($b['app_build'] !== null ? 'build ' . $b['app_build'] : 'not observed')
                    . ': ' . $b['c'];
            }
            $lines[] = '- By app build (7d): ' . implode(', ', $parts);
        }
        if (!empty($ev['by_device_model'])) {
            $parts = [];
            foreach ($ev['by_device_model'] as $m) {
                $parts[] = ($m['device_model'] !== '' ? $m['device_model'] : 'unknown device') . ': ' . $m['c'];
            }
            $lines[] = '- By device model: ' . implode(', ', $parts);
        }
        if (!empty($ev['crash_signature'])) {
            $sig = $ev['crash_signature'];
            $lines[] = '- Crash signature: ' . $sig['signature_class']
                . ($sig['signature_frames'] !== null ? ' — ' . $sig['signature_frames'] : '');
            $lines[] = '- Crash lifetime: ' . $sig['total_events'] . ' events, first seen '
                . $sig['first_seen_at'] . ', last seen ' . $sig['last_seen_at'] . '.';
        }
        foreach (array_slice($ev['samples'], 0, 3) as $sample) {
            $lines[] = '- Sample: [' . $sample['severity'] . '] ' . $sample['message']
                . ' (' . $sample['file_path'] . ':' . $sample['line_number'] . ')';
        }
        $lines[] = '';

        if (!empty($ev['request_ids'])) {
            $lines[] = '## Correlated server evidence (request ids)';
            $lines[] = '';
            foreach ($ev['request_ids'] as $req) {
                $lines[] = '- `' . $req['request_id'] . '` @ ' . $req['started_at']
                    . ' (HTTP ' . ($req['http_status'] ?? '?') . ', ' . $req['status'] . ')';
            }
            $lines[] = '';
            $lines[] = 'These request ids can be looked up directly in the Fleet Analytics sync monitor.';
            $lines[] = '';
        }

        $lines[] = '## How to fix it';
        $lines[] = '';
        $lines[] = $rem['steps'] !== null && $rem['steps'] !== ''
            ? (string)$rem['steps']
            : 'No fix steps are catalogued yet — add them from the issue view.';
        if ($rem['link'] !== null && $rem['link'] !== '') {
            $lines[] = '';
            $lines[] = 'Documentation: ' . $rem['link'];
        }
        $lines[] = '';

        if ($issue['status'] === 'resolved' && $issue['resolved_note']) {
            $lines[] = '## Resolution';
            $lines[] = '';
            $lines[] = $issue['resolved_note'] . ' — ' . $issue['resolved_by'] . ', ' . $issue['resolved_at'];
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '_Generated ' . date('Y-m-d H:i:s') . ' by ' . $actor . '._';
        return implode("\n", $lines);
    }

    private static function severityFor(array $issue): string
    {
        if ($issue['source'] === 'crash') {
            return $issue['affected_24h'] >= 10 ? 'critical' : 'high';
        }
        if ($issue['occurrences_24h'] >= 50 || $issue['affected_24h'] >= 10) return 'high';
        if ($issue['occurrences_24h'] >= 10 || $issue['affected_24h'] >= 5) return 'medium';
        return 'low';
    }

    private static function windowDays(string $window): int
    {
        return match ($window) {
            '24h' => 1,
            '30d' => 30,
            default => 7,
        };
    }

    private static function titleSync(string $category, string $code, string $domain, string $operation): string
    {
        $suffix = ($code !== '' && $code !== $category) ? (' (' . $code . ')') : '';
        $title = trim(($category !== '' ? $category : 'Unclassified') . $suffix . ' — '
            . trim($domain . '/' . $operation, '/'));
        return mb_substr($title !== '' ? $title : 'Sync failure', 0, 255);
    }

    private static function titleServer(string $errorType, string $file, string $line): string
    {
        $base = basename($file);
        $title = ($errorType !== '' ? $errorType : 'Server error')
            . ($base !== '' ? (' at ' . $base . ':' . $line) : '');
        return mb_substr($title, 0, 255);
    }

    private static function titleCrash(string $class, array $frames): string
    {
        $title = $class . (!empty($frames) ? (' @ ' . (string)$frames[0]) : '');
        return mb_substr($title, 0, 255);
    }

    private static function norm(string $value): string
    {
        return trim(mb_substr($value, 0, 255));
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
