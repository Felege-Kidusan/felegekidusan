<?php
/**
 * App release metadata — how the phone knows a new APK exists.
 *
 * Edit /home/USER/.fkss_app_release.php on the server (see
 * api/v1/app_release.example.php). Never put the APK in git.
 */

function fkssCompareVersion(string $a, string $b): int
{
    $pa = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $a) . ''));
    $pb = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $b) . ''));
    for ($i = 0; $i < 3; $i++) {
        $x = $pa[$i] ?? 0;
        $y = $pb[$i] ?? 0;
        if ($x < $y) return -1;
        if ($x > $y) return 1;
    }
    return 0;
}

function fkssLoadAppRelease(): array
{
    $defaults = [
        'latest_version' => '1.1.0',
        'latest_build'   => 2,
        'min_version'    => '1.0.0',
        'min_build'      => 1,
        'force_update'   => false,
        'release_notes'  => '',
        'banner_text'    => '',
        'banner_kind'    => 'info',
        'apk_path'       => '',
        'tiles'          => [
            'education' => ['classes', 'teachers', 'subjects', 'enrollment', 'grades', 'attendance'],
        ],
    ];

    $candidates = [];
    if (defined('ROOT_PATH')) {
        $candidates[] = dirname(ROOT_PATH) . '/.fkss_app_release.php';
        $candidates[] = ROOT_PATH . '/api/v1/app_release.local.php';
    }
    $candidates[] = dirname(__DIR__, 2) . '/../.fkss_app_release.php';
    $candidates[] = dirname(__DIR__) . '/app_release.local.php';

    $file = null;
    foreach ($candidates as $path) {
        if (is_string($path) && is_file($path)) {
            $file = $path;
            break;
        }
    }

    $loaded = [];
    if ($file) {
        $raw = include $file;
        if (is_array($raw)) {
            $loaded = $raw;
        }
    }

    $out = array_merge($defaults, $loaded);
    $out['latest_version'] = preg_replace('/[^0-9.]/', '', (string)$out['latest_version']) ?: '1.1.0';
    $out['min_version'] = preg_replace('/[^0-9.]/', '', (string)$out['min_version']) ?: '1.0.0';
    $out['latest_build'] = max(1, (int)$out['latest_build']);
    $out['min_build'] = max(1, (int)$out['min_build']);
    $out['force_update'] = !empty($out['force_update']);
    $out['release_notes'] = trim((string)$out['release_notes']);
    $out['banner_text'] = trim((string)$out['banner_text']);
    $out['banner_kind'] = in_array($out['banner_kind'] ?? '', ['info', 'warn'], true) ? $out['banner_kind'] : 'info';
    $out['apk_path'] = trim((string)$out['apk_path']);
    if (!is_array($out['tiles'])) {
        $out['tiles'] = $defaults['tiles'];
    }

    $resolved = fkssResolveApkPath($out['apk_path']);
    $out['_apk_file'] = $resolved;
    $out['download_available'] = $resolved !== null;
    $out['apk_size_bytes'] = $resolved ? (int)filesize($resolved) : 0;
    $out['apk_sha256'] = $resolved ? hash_file('sha256', $resolved) : '';

    return $out;
}

/** Only files under an allowed folder, ending in .apk, may be served. */
function fkssResolveApkPath(string $configured): ?string
{
    $allowedRoots = [];
    if (defined('ROOT_PATH')) {
        $allowedRoots[] = dirname(ROOT_PATH) . '/fkss_releases';
        $allowedRoots[] = ROOT_PATH . '/releases';
    }
    $allowedRoots[] = dirname(__DIR__, 2) . '/../fkss_releases';
    $allowedRoots[] = dirname(__DIR__, 2) . '/releases';

    $try = [];
    if ($configured !== '') {
        $try[] = $configured;
    }
    foreach ($allowedRoots as $root) {
        $try[] = rtrim($root, '/') . '/fkss.apk';
    }

    foreach ($try as $path) {
        $real = realpath($path);
        if (!$real || !is_file($real)) {
            continue;
        }
        if (strtolower(substr($real, -4)) !== '.apk') {
            continue;
        }
        foreach ($allowedRoots as $root) {
            $rootReal = realpath($root);
            if ($rootReal && strncmp($real, $rootReal, strlen($rootReal)) === 0) {
                return $real;
            }
            // folder may not exist yet — allow exact configured file only if parent is allowed name
            if (!$rootReal && $configured !== '' && basename(dirname($real)) === 'fkss_releases') {
                return $real;
            }
        }
    }
    return null;
}
