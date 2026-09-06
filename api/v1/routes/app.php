<?php
/**
 * GET /app/config     — public, no student data
 * GET /app/download   — streams the APK if one has been uploaded
 */

require_once __DIR__ . '/../core/app_release.php';

$action = $ROUTE['id'] ?? 'config';

if ($method === 'GET' && ($action === 'config' || $action === '' || $action === null)) {
    if (isApiRateLimited('app_config', 60)) {
        err('Too many requests. Please wait a moment.', 429);
    }
    header('Cache-Control: no-store');
    $rel = fkssLoadAppRelease();
    $rel['tiles'] = \App\Services\FeatureGate::filterMobileTiles($rel['tiles'] ?? []);
    $banner = null;
    if ($rel['banner_text'] !== '') {
        $banner = [
            'text' => $rel['banner_text'],
            'kind' => $rel['banner_kind'],
        ];
    }
    ok([
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
        'features' => \App\Services\FeatureGate::mobileCapabilities(),
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
