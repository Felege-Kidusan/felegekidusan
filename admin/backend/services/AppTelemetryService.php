<?php

namespace App\Services;

use mysqli;
use Throwable;

/**
 * Service for aggregating and querying mobile fleet telemetry,
 * installation activity, version adoption, and device hardware classes.
 */
final class AppTelemetryService
{
    /**
     * Get aggregate fleet metrics and distributions with optional filters.
     */
    public static function getFleetMetrics(mysqli $conn, array $filters = []): array
    {
        $where = [];
        $params = [];
        $types = '';

        if (!empty($filters['version'])) {
            $where[] = "app_version = ?";
            $params[] = $filters['version'];
            $types .= 's';
        }

        if (!empty($filters['brand'])) {
            $where[] = "device_brand = ?";
            $params[] = $filters['brand'];
            $types .= 's';
        }

        if (!empty($filters['range'])) {
            switch ($filters['range']) {
                case '1d':
                case 'today':
                    $where[] = "last_seen_at >= NOW() - INTERVAL 1 DAY";
                    break;
                case '7d':
                    $where[] = "last_seen_at >= NOW() - INTERVAL 7 DAY";
                    break;
                case '30d':
                    $where[] = "last_seen_at >= NOW() - INTERVAL 30 DAY";
                    break;
            }
        }

        $whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        // 1. Core KPIs
        $totalInstalls = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_installations");
        $activeToday = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_installations WHERE last_seen_at >= NOW() - INTERVAL 1 DAY");
        $active7d = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_installations WHERE last_seen_at >= NOW() - INTERVAL 7 DAY");
        $active30d = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_installations WHERE last_seen_at >= NOW() - INTERVAL 30 DAY");

        $totalDownloads = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_downloads");
        $downloadsToday = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_downloads WHERE downloaded_at >= NOW() - INTERVAL 1 DAY");
        $downloads7d = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_downloads WHERE downloaded_at >= NOW() - INTERVAL 7 DAY");

        // 2. Filtered count
        $filteredCount = (int)self::fetchValue($conn, "SELECT COUNT(*) FROM app_installations" . $whereClause, $types, $params);

        // 3. Totals from installations
        $totalsRow = self::fetchRow($conn, "SELECT 
            SUM(launch_count) as total_launches,
            SUM(sync_success_count) as total_sync_success,
            SUM(sync_fail_count) as total_sync_fail,
            SUM(crash_count) as total_crashes
            FROM app_installations" . $whereClause, $types, $params);

        $totalLaunches = (int)($totalsRow['total_launches'] ?? 0);
        $syncSuccess = (int)($totalsRow['total_sync_success'] ?? 0);
        $syncFail = (int)($totalsRow['total_sync_fail'] ?? 0);
        $totalSyncs = $syncSuccess + $syncFail;
        $syncHealthPct = $totalSyncs > 0 ? round(($syncSuccess / $totalSyncs) * 100, 1) : 100.0;
        $totalCrashes = (int)($totalsRow['total_crashes'] ?? 0);

        // 4. Version distribution
        $versionRows = self::fetchAll($conn, "SELECT 
            app_version, 
            app_build, 
            COUNT(*) as device_count,
            SUM(CASE WHEN last_seen_at >= NOW() - INTERVAL 7 DAY THEN 1 ELSE 0 END) as active_7d_count,
            MAX(last_seen_at) as last_seen
            FROM app_installations
            GROUP BY app_version, app_build
            ORDER BY device_count DESC, app_build DESC");

        $versionDist = [];
        $latestVersion = '';
        $latestBuild = 0;
        $latestCount = 0;

        foreach ($versionRows as $row) {
            $count = (int)$row['device_count'];
            $pct = $totalInstalls > 0 ? round(($count / $totalInstalls) * 100, 1) : 0;
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

        $adoptionPct = $totalInstalls > 0 ? round(($latestCount / $totalInstalls) * 100, 1) : 0;

        // 5. OS (Android version) distribution
        $osRows = self::fetchAll($conn, "SELECT 
            IF(os_version != '', os_version, 'Unknown') as os_name,
            sdk_int,
            COUNT(*) as device_count
            FROM app_installations
            GROUP BY os_name, sdk_int
            ORDER BY device_count DESC
            LIMIT 10");

        $osDist = [];
        foreach ($osRows as $row) {
            $c = (int)$row['device_count'];
            $osDist[] = [
                'os_version' => (string)$row['os_name'],
                'sdk_int' => (int)$row['sdk_int'],
                'count' => $c,
                'percentage' => $totalInstalls > 0 ? round(($c / $totalInstalls) * 100, 1) : 0,
            ];
        }

        // 6. Device Brands & Models
        $brandRows = self::fetchAll($conn, "SELECT 
            IF(device_brand != '', device_brand, 'Generic') as brand,
            COUNT(*) as device_count
            FROM app_installations
            GROUP BY brand
            ORDER BY device_count DESC
            LIMIT 8");

        $modelRows = self::fetchAll($conn, "SELECT 
            CONCAT(IF(device_brand != '', CONCAT(device_brand, ' '), ''), IF(device_model != '', device_model, 'Device')) as full_model,
            device_brand,
            device_model,
            COUNT(*) as device_count,
            AVG(ram_mb) as avg_ram,
            SUM(is_low_ram) as low_ram_count
            FROM app_installations
            GROUP BY full_model, device_brand, device_model
            ORDER BY device_count DESC
            LIMIT 10");

        // 7. Hardware / RAM Class
        $ramStats = self::fetchRow($conn, "SELECT 
            SUM(CASE WHEN ram_mb <= 2048 OR is_low_ram = 1 THEN 1 ELSE 0 END) as low_tier,
            SUM(CASE WHEN ram_mb > 2048 AND ram_mb <= 4096 AND is_low_ram = 0 THEN 1 ELSE 0 END) as mid_tier,
            SUM(CASE WHEN ram_mb > 4096 AND is_low_ram = 0 THEN 1 ELSE 0 END) as high_tier
            FROM app_installations");

        return [
            'summary' => [
                'total_installations' => $totalInstalls,
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
     * Get paginated installation devices list.
     */
    public static function getInstallationsList(mysqli $conn, array $filters = [], int $page = 1, int $limit = 25): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        $types = '';

        if (!empty($filters['version'])) {
            $where[] = "app_version = ?";
            $params[] = $filters['version'];
            $types .= 's';
        }

        if (!empty($filters['brand'])) {
            $where[] = "device_brand = ?";
            $params[] = $filters['brand'];
            $types .= 's';
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $where[] = "(installation_id LIKE ? OR device_model LIKE ? OR device_brand LIKE ? OR app_version LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= 'ssss';
        }

        $whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

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
        try {
            if (empty($params)) {
                $res = $conn->query($sql);
                if (!$res) return [];
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = $r;
                }
                $res->free();
                return $out;
            }

            $stmt = $conn->prepare($sql);
            if (!$stmt) return [];
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            if (!$res) {
                $stmt->close();
                return [];
            }
            $out = [];
            while ($r = $res->fetch_assoc()) {
                $out[] = $r;
            }
            $stmt->close();
            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
