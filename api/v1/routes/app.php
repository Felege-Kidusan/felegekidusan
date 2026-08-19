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
        'apk_size_bytes' => $rel['apk_size_bytes'],
        'apk_sha256' => $rel['apk_sha256'],
        'banner' => $banner,
        'tiles' => $rel['tiles'],
    ]);
}

if ($method === 'GET' && $action === 'download') {
    if (isApiRateLimited('app_download', 8)) {
        err('Too many download attempts. Please wait a minute.', 429);
    }
    $rel = fkssLoadAppRelease();
    $file = $rel['_apk_file'];
    if (!$file || !is_readable($file)) {
        err('No app file has been published yet. Ask the school for the APK.', 404);
    }

    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    @set_time_limit(0);
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="FKSS.apk"');
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('X-App-Version: ' . $rel['latest_version']);
    header('X-App-Build: ' . $rel['latest_build']);
    header('X-App-Sha256: ' . $rel['apk_sha256']);
    readfile($file);
    exit;
}

err('Unknown app action. Use: config, download', 404);
