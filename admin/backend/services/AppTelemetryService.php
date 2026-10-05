<?php

namespace App\Services;

use mysqli;

/**
 * Service for aggregating and querying mobile fleet telemetry,
 * installation activity, version adoption, and device hardware classes.
 *
 * Installation-based metrics use one explicit cohort scope: app version,
 * optional device brand, and the selected last_seen_at window. Lifetime
 * counters are reported as lifetime counters for that cohort; they are not
 * presented as event counts for the selected window.
 */
final class AppTelemetryService
{
    /**
     * Get aggregate fleet metrics and distributions with optional filters.
     */
    public static function getFleetMetrics(mysqli $conn, array $filters = []): array
    {
        $baseWhere = [];
        $baseParams = [];
        $baseTypes = '';
        self::appendInstallationFilters($baseWhere, $baseParams, $baseTypes, $filters, false);

        $cohortWhere = $baseWhere;
        $cohortParams = $baseParams;
        $cohortTypes = $baseTypes;
        self::appendRangeFilter($cohortWhere, $cohortParams, $cohortTypes, 'last_seen_at', $filters['range'] ?? null);

        $cohortWhereClause = self::whereClause($cohortWhere);

        // Core device counts. The selected range is an installation cohort
        // based on last_seen_at; the standard DAU/WAU/MAU values remain useful
        // comparisons inside the selected version/brand cohort.
        $allTimeInstalls = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_installations");
        $filteredCount = (int)self::fetchValue(
            $conn,
            "SELECT COUNT(*) FROM app_installations" . $cohortWhereClause,
            $cohortTypes,
            $cohortParams
        );

        $activeToday = self::countActiveInstallations($conn, $baseWhere, $baseParams, $baseTypes, 1);
        $active7d = self::countActiveInstallations($conn, $baseWhere, $baseParams, $baseTypes, 7);
        $active30d = self::countActiveInstallations($conn, $baseWhere, $baseParams, $baseTypes, 30);

        // Downloads have no installation/brand foreign key. Apply only the
        // filters the table can support: app version and downloaded_at window.
        $downloadBaseWhere = [];
        $downloadBaseParams = [];
        $downloadBaseTypes = '';
        self::appendDownloadFilters($downloadBaseWhere, $downloadBaseParams, $downloadBaseTypes, $filters, false);
        $downloadCohortWhere = $downloadBaseWhere;
        $downloadCohortParams = $downloadBaseParams;
        $downloadCohortTypes = $downloadBaseTypes;
        self::appendRangeFilter($downloadCohortWhere, $downloadCohortParams, $downloadCohortTypes, 'downloaded_at', $filters['range'] ?? null);

        $totalDownloads = (int)self::fetchValue(
            $conn,
            "SELECT COUNT(*) FROM app_downloads" . self::whereClause($downloadCohortWhere),
            $downloadCohortTypes,
            $downloadCohortParams
        );
        $downloadsToday = self::countDownloadsSince($conn, $downloadBaseWhere, $downloadBaseParams, $downloadBaseTypes, 1);
        $downloads7d = self::countDownloadsSince($conn, $downloadBaseWhere, $downloadBaseParams, $downloadBaseTypes, 7);

        // These are lifetime installation counters for the selected cohort.
        // They are intentionally not mislabeled as events occurring inside the
        // selected range; app_telemetry_events is the event-time source.
        $totalsRow = self::fetchRow(
            $conn,
            "SELECT
                SUM(launch_count) as total_launches,
                SUM(sync_success_count) as total_sync_success,
                SUM(sync_fail_count) as total_sync_fail,
                SUM(crash_count) as total_crashes
                FROM app_installations" . $cohortWhereClause,
            $cohortTypes,
            $cohortParams
        );

        $totalLaunches = (int)($totalsRow['total_launches'] ?? 0);
        $syncSuccess = (int)($totalsRow['total_sync_success'] ?? 0);
        $syncFail = (int)($totalsRow['total_sync_fail'] ?? 0);
        $totalSyncs = $syncSuccess + $syncFail;
        $syncHealthPct = $totalSyncs > 0 ? round(($syncSuccess / $totalSyncs) * 100, 1) : null;
        $totalCrashes = (int)($totalsRow['total_crashes'] ?? 0);

        // Version distribution
        $versionRows = self::fetchAll($conn, "SELECT
            app_version,
            app_build,
            COUNT(*) as device_count,
            SUM(CASE WHEN last_seen_at >= NOW() - INTERVAL 7 DAY THEN 1 ELSE 0 END) as active_7d_count,
            MAX(last_seen_at) as last_seen
            FROM app_installations" . $cohortWhereClause . "
            GROUP BY app_version, app_build
            ORDER BY device_count DESC, app_build DESC", $cohortTypes, $cohortParams);

        $versionDist = [];
        $latestVersion = '';
        $latestBuild = 0;
        $latestCount = 0;

        foreach ($versionRows as $row) {
            $count = (int)$row['device_count'];
            $pct = $filteredCount > 0 ? round(($count / $filteredCount) * 100, 1) : 0;
            $buildNum = (int)$row['app_build'];
            if ($buildNum > $latestBuild) {
                $latestBuild = $buildNum;
                $latestVersion = (string)$row['app_version'];
                $latestCount = $count;
            }
            $versionDist[] = [
                'version' => (string)$row['app_version'],
                'build' => $buildNum,
                'count' => $count,
                'active_7d' => (int)$row['active_7d_count'],
                'percentage' => $pct,
                'last_seen' => (string)$row['last_seen'],
            ];
        }

        $adoptionPct = $filteredCount > 0 ? round(($latestCount / $filteredCount) * 100, 1) : 0;

        // OS distribution
        $osRows = self::fetchAll($conn, "SELECT
            IF(os_version != '', os_version, 'Unknown') as os_name,
            sdk_int,
            COUNT(*) as device_count
            FROM app_installations" . $cohortWhereClause . "
            GROUP BY os_name, sdk_int
            ORDER BY device_count DESC
            LIMIT 10", $cohortTypes, $cohortParams);

        $osDist = [];
        foreach ($osRows as $row) {
            $c = (int)$row['device_count'];
            $osDist[] = [
                'os_version' => (string)$row['os_name'],
                'sdk_int' => (int)$row['sdk_int'],
                'count' => $c,
                'percentage' => $filteredCount > 0 ? round(($c / $filteredCount) * 100, 1) : 0,
            ];
        }

        // Device brands and models
        $brandRows = self::fetchAll($conn, "SELECT
            IF(device_brand != '', device_brand, 'Generic') as brand,
            COUNT(*) as device_count
            FROM app_installations" . $cohortWhereClause . "
            GROUP BY brand
            ORDER BY device_count DESC
            LIMIT 8", $cohortTypes, $cohortParams);

        $modelRows = self::fetchAll($conn, "SELECT
            CONCAT(IF(device_brand != '', CONCAT(device_brand, ' '), ''), IF(device_model != '', device_model, 'Device')) as full_model,
            device_brand,
            device_model,
            COUNT(*) as device_count,
            AVG(ram_mb) as avg_ram,
            SUM(is_low_ram) as low_ram_count
            FROM app_installations" . $cohortWhereClause . "
            GROUP BY full_model, device_brand, device_model
            ORDER BY device_count DESC
            LIMIT 10", $cohortTypes, $cohortParams);

        // Hardware / RAM class
        $ramStats = self::fetchRow($conn, "SELECT
            SUM(CASE WHEN ram_mb <= 2048 OR is_low_ram = 1 THEN 1 ELSE 0 END) as low_tier,
            SUM(CASE WHEN ram_mb > 2048 AND ram_mb <= 4096 AND is_low_ram = 0 THEN 1 ELSE 0 END) as mid_tier,
            SUM(CASE WHEN ram_mb > 4096 AND is_low_ram = 0 THEN 1 ELSE 0 END) as high_tier
            FROM app_installations" . $cohortWhereClause, $cohortTypes, $cohortParams);

        $range = self::normalizedRange($filters['range'] ?? null);
        return [
            'summary' => [
                'total_installations' => $filteredCount,
                'all_time_installations' => $allTimeInstalls,
                'active_today' => $activeToday,
                'active_7d' => $active7d,
                'active_30d' => $active30d,
                'filtered_count' => $filteredCount,
                'total_downloads' => $totalDownloads,
                'downloads_today' => $downloadsToday,
                'downloads_7d' => $downloads7d,
                'total_launches' => $totalLaunches,
                'sync_success' => $syncSuccess,
                'sync_fail' => $syncFail,
                'sync_health_percentage' => $syncHealthPct,
                'total_crashes' => $totalCrashes,
                'latest_version' => $latestVersion,
                'latest_build' => $latestBuild,
                'adoption_percentage' => $adoptionPct,
                'scope' => [
                    'range' => $range,
                    'version' => !empty($filters['version']) ? (string)$filters['version'] : null,
                    'brand' => !empty($filters['brand']) ? (string)$filters['brand'] : null,
                    'installation_basis' => 'last_seen_at cohort',
                    'counter_basis' => 'lifetime installation counters for the selected cohort',
                    'download_basis' => 'downloaded_at and app version; downloads have no device-brand link',
                ],
            ],
            'versions' => $versionDist,
            'os_versions' => $osDist,
            'brands' => $brandRows,
            'models' => $modelRows,
            'hardware' => [
                'low_tier' => (int)($ramStats['low_tier'] ?? 0),
                'mid_tier' => (int)($ramStats['mid_tier'] ?? 0),
                'high_tier' => (int)($ramStats['high_tier'] ?? 0),
            ],
        ];
    }

    /**
     * Get paginated installation devices list using the same cohort scope as
     * the overview metrics.
     */
    public static function getInstallationsList(mysqli $conn, array $filters = [], int $page = 1, int $limit = 25): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        $types = '';
        self::appendInstallationFilters($where, $params, $types, $filters, true);

        if (!empty($filters['search'])) {
            $search = '%' . substr((string)$filters['search'], 0, 80) . '%';
            $where[] = "(installation_id LIKE ? OR device_model LIKE ? OR device_brand LIKE ? OR app_version LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= 'ssss';
        }

        $whereClause = self::whereClause($where);
        $total = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_installations" . $whereClause, $types, $params);

        $sql = "SELECT
            installation_id,
            app_version,
            app_build,
            os_version,
            sdk_int,
            device_brand,
            device_model,
            abi,
            ram_mb,
            is_low_ram,
            launch_count,
            sync_success_count,
            sync_fail_count,
            crash_count,
            last_role_hint,
            first_seen_at,
            last_seen_at,
            TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) as minutes_ago
            FROM app_installations" . $whereClause . "
            ORDER BY last_seen_at DESC
            LIMIT ? OFFSET ?";

        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;

        $rows = self::fetchAll($conn, $sql, $types, $params);

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $total > 0 ? (int)ceil($total / $limit) : 1,
            'items' => $rows,
            'scope' => [
                'range' => self::normalizedRange($filters['range'] ?? null),
                'version' => !empty($filters['version']) ? (string)$filters['version'] : null,
                'brand' => !empty($filters['brand']) ? (string)$filters['brand'] : null,
                'installation_basis' => 'last_seen_at cohort',
            ],
        ];
    }

    /**
     * Get recent telemetry events stream.
     */
    public static function getRecentEvents(mysqli $conn, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));

        return self::fetchAll($conn, "SELECT
            e.id,
            e.installation_id,
            e.event_type,
            e.event_data,
            e.app_version,
            e.app_build,
            e.created_at,
            i.device_brand,
            i.device_model,
            i.os_version
            FROM app_telemetry_events e
            LEFT JOIN app_installations i ON e.installation_id = i.installation_id
            ORDER BY e.id DESC
            LIMIT ?", 'i', [$limit]);
    }

    private static function appendInstallationFilters(
        array &$where,
        array &$params,
        string &$types,
        array $filters,
        bool $includeRange
    ): void {
        if (!empty($filters['version'])) {
            $where[] = 'app_version = ?';
            $params[] = (string)$filters['version'];
            $types .= 's';
        }

        if (!empty($filters['brand'])) {
            $where[] = 'device_brand = ?';
            $params[] = (string)$filters['brand'];
            $types .= 's';
        }

        if ($includeRange) {
            self::appendRangeFilter($where, $params, $types, 'last_seen_at', $filters['range'] ?? null);
        }
    }

    private static function appendDownloadFilters(
        array &$where,
        array &$params,
        string &$types,
        array $filters,
        bool $includeRange
    ): void {
        if (!empty($filters['version'])) {
            $where[] = 'version = ?';
            $params[] = (string)$filters['version'];
            $types .= 's';
        }

        if ($includeRange) {
            self::appendRangeFilter($where, $params, $types, 'downloaded_at', $filters['range'] ?? null);
        }
    }

    private static function appendRangeFilter(
        array &$where,
        array &$params,
        string &$types,
        string $column,
        ?string $range
    ): void {
        $interval = match ((string)$range) {
            '1d', 'today' => '1 DAY',
            '7d' => '7 DAY',
            '30d' => '30 DAY',
            default => null,
        };

        if ($interval !== null) {
            $where[] = $column . ' >= NOW() - INTERVAL ' . $interval;
        }
    }

    private static function normalizedRange(?string $range): string
    {
        return match ((string)$range) {
            '1d', 'today' => 'today',
            '7d' => '7d',
            '30d' => '30d',
            default => 'all',
        };
    }

    private static function whereClause(array $where): string
    {
        return !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
    }

    private static function countActiveInstallations(
        mysqli $conn,
        array $where,
        array $params,
        string $types,
        int $days
    ): int {
        $where[] = 'last_seen_at >= NOW() - INTERVAL ' . max(1, $days) . ' DAY';
        return (int)self::fetchValue(
            $conn,
            'SELECT COUNT(*) FROM app_installations' . self::whereClause($where),
            $types,
            $params
        );
    }

    private static function countDownloadsSince(
        mysqli $conn,
        array $where,
        array $params,
        string $types,
        int $days
    ): int {
        $where[] = 'downloaded_at >= NOW() - INTERVAL ' . max(1, $days) . ' DAY';
        return (int)self::fetchValue(
            $conn,
            'SELECT COUNT(*) FROM app_downloads' . self::whereClause($where),
            $types,
            $params
        );
    }

    private static function fetchValue(mysqli $conn, string $sql, string $types = '', array $params = []): mixed
    {
        $row = self::fetchRow($conn, $sql, $types, $params);
        return $row ? reset($row) : null;
    }

    private static function fetchRow(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
    {
        $rows = self::fetchAll($conn, $sql, $types, $params);
        return !empty($rows) ? $rows[0] : null;
    }

    private static function fetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
    {
        if (empty($params)) {
            $res = $conn->query($sql);
            if (!$res) {
                throw new \RuntimeException('Telemetry query failed.');
            }
            $out = [];
            while ($r = $res->fetch_assoc()) {
                $out[] = $r;
            }
            $res->free();
            return $out;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Could not prepare telemetry query.');
        }
        try {
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Telemetry query failed.');
            }
            $res = $stmt->get_result();
            if (!$res) {
                throw new \RuntimeException('Could not read telemetry query results.');
            }
            $out = [];
            while ($r = $res->fetch_assoc()) {
                $out[] = $r;
            }
            return $out;
        } finally {
            $stmt->close();
        }
    }
}
