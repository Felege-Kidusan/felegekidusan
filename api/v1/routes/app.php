<?php
/**
 * GET /app/config     — public, no student data
 * GET /app/download   — streams the APK if one has been uploaded
 */

require_once __DIR__ . '/../core/app_release.php';
require_once __DIR__ . '/../../../admin/backend/services/SyncInstanceService.php';

$action = $ROUTE['id'] ?? 'config';

if ($method === 'GET' && ($action === 'config' || $action === '' || $action === null)) {
    if (isApiRateLimited('app_config', 60)) {
        err('Too many requests. Please wait a moment.', 429);
    }
    header('Cache-Control: no-store');
    $rel = fkssLoadAppRelease();
    $rel['tiles'] = \App\Services\FeatureGate::filterMobileTiles($rel['tiles'] ?? []);
    $features = \App\Services\FeatureGate::mobileCapabilities();
    // Release-owned emergency containment. This is intentionally separate
    // from module visibility: false stops outbound outbox drains while local
    // SQLite saves, queue rows, and recovery UI stay available.
    $features['background_outbox_drain'] = $rel['background_drains_enabled'];
    $banner = null;
    if ($rel['banner_text'] !== '') {
        $banner = [
            'text' => $rel['banner_text'],
            'kind' => $rel['banner_kind'],
        ];
    }
    ok([
        // 1.6.2: dataset identity for the mobile sync engine (the
        // sync-anchor pattern). When this value changes — new dataset,
        // restore, migration — clients purge their server-derived caches
        // and re-pull the authoritative corpus, which removes rows the
        // delta stream can never tombstone. Empty when the DB is
        // unreachable: the endpoint stays useful and clients skip the
        // epoch check.
        'sync_instance_id' => \App\Services\SyncInstanceService::instanceId(
            isset($conn) && $conn instanceof \mysqli ? $conn : null
        ),
        'latest_version' => $rel['latest_version'],
        'latest_build' => $rel['latest_build'],
        'min_version' => $rel['min_version'],
        'min_build' => $rel['min_build'],
        'force_update' => $rel['force_update'],
        'release_notes' => $rel['release_notes'],
        'download_available' => $rel['download_available'],
        'download_path' => '/app/download',
        // Legacy single-artifact fields (the universal APK). Old app
        // versions only read these; keep them forever.
        'apk_size_bytes' => $rel['apk_size_bytes'],
        'apk_sha256' => $rel['apk_sha256'],
        // P65: per-ABI artifacts. New apps pick their ABI's entry (and
        // fall back to 'universal' / the legacy fields above). Absent
        // when the server only publishes the universal build.
        'apk_artifacts' => $rel['apk_artifacts'],
        'banner' => $banner,
        'features' => $features,
        'tiles' => $rel['tiles'],
    ]);
}

if ($method === 'GET' && $action === 'download') {
    if (isApiRateLimited('app_download', 8)) {
        err('Too many download attempts. Please wait a minute.', 429);
    }
    // P65: the app asks for its own architecture (?abi=arm64-v8a or
    // armeabi-v7a). STRICT whitelist — the value never touches the
    // filesystem (it only selects a pre-resolved, allowlisted file);
    // anything else is ignored and the universal APK is served, exactly
    // as older clients get.
    $abi = (string)($_GET['abi'] ?? '');
    if ($abi !== 'arm64-v8a' && $abi !== 'armeabi-v7a') {
        $abi = '';
    }
    $rel = fkssLoadAppRelease();
    $file = null;
    if ($abi !== '' && !empty($rel['_apk_files'][$abi])) {
        $file = $rel['_apk_files'][$abi];
    }
    if (!$file) {
        $abi = 'universal';
        $file = $rel['_apk_file'];
    }
    if (!$file || !is_readable($file)) {
        err('No app file has been published yet. Ask the school for the APK.', 404);
    }

    // SHA-256 of the file ACTUALLY served — the client validates against
    // this header first, so old and new servers, and per-ABI or universal
    // files, all verify correctly.
    $meta = fkssApkMeta($file);

    // Record download event for telemetry
    try {
        if (isset($conn) && $conn instanceof mysqli) {
            $ver = (string)($rel['latest_version'] ?? '1.0.0');
            $bld = (int)($rel['latest_build'] ?? 1);
            // Same keyed, UTC-day-rotating identity as the telemetry route
            // (routes/telemetry.php). An unkeyed digest of "ip::day" is
            // trivially reversible for IPv4, and a local-time day boundary
            // would rotate on a different edge than every other ip_hash the
            // subsystem writes.
            $ipHashSecret = defined('TELEMETRY_HASH_SECRET') && TELEMETRY_HASH_SECRET !== ''
                ? TELEMETRY_HASH_SECRET
                : JWT_SECRET;
            $ipHash = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . '::' . gmdate('Ymd'), $ipHashSecret);
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $stmt = $conn->prepare("INSERT INTO app_downloads (version, build, abi, ip_hash, user_agent, downloaded_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param('sisss', $ver, $bld, $abi, $ipHash, $ua);
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (Throwable) {}

    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    @set_time_limit(0);
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="FKSS-' . $rel['latest_version'] . '-' . $abi . '.apk"');
    header('Content-Length: ' . $meta['size']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('X-App-Version: ' . $rel['latest_version']);
    header('X-App-Build: ' . $rel['latest_build']);
    header('X-App-Sha256: ' . $meta['sha256']);
    readfile($file);
    exit;
}

err('Unknown app action. Use: config, download', 404);
