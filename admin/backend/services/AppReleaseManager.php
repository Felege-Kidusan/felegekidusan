<?php

namespace App\Services;

use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Service for managing mobile application releases, APK artifacts,
 * and version policies from the Super Admin dashboard.
 */
final class AppReleaseManager
{
    private const MAX_APK_BYTES = 209715200; // 200 MB

    /**
     * Get the target configuration file path.
     */
    public static function getConfigFile(string $projectRoot): string
    {
        // Check for server-level private file first
        $privateFile = dirname($projectRoot) . '/.fkss_app_release.php';
        if (is_file($privateFile) && is_writable($privateFile)) {
            return $privateFile;
        }

        // Local api directory release file
        return $projectRoot . '/api/v1/app_release.local.php';
    }

    /**
     * Get the releases storage directory.
     */
    public static function getReleasesDirectory(string $projectRoot): string
    {
        $privateDir = dirname($projectRoot) . '/fkss_releases';
        if (is_dir($privateDir) && is_writable($privateDir)) {
            return $privateDir;
        }

        $localDir = $projectRoot . '/releases';
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }

        $htaccess = $localDir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Protect release directory\nOptions -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phps)\$\">\n    Order allow,deny\n    Deny from all\n</FilesMatch>\n");
        }

        return $localDir;
    }

    /**
     * Read the full current app release metadata.
     */
    public static function getReleaseInfo(string $projectRoot): array
    {
        require_once $projectRoot . '/api/v1/core/app_release.php';
        $rel = fkssLoadAppRelease();

        $configFile = self::getConfigFile($projectRoot);
        $rel['config_file'] = $configFile;
        $rel['config_file_exists'] = is_file($configFile);

        $releasesDir = self::getReleasesDirectory($projectRoot);
        $rel['releases_dir'] = $releasesDir;

        // Details of active files
        $artifactsDetail = [];
        $apkFile = $rel['_apk_file'] ?? null;
        if ($apkFile && is_file($apkFile)) {
            $meta = fkssApkMeta($apkFile);
            $artifactsDetail['universal'] = [
                'path' => $apkFile,
                'filename' => basename($apkFile),
                'size_bytes' => $meta['size'],
                'size_formatted' => self::formatBytes($meta['size']),
                'sha256' => $meta['sha256'],
                'modified_at' => date('Y-m-d H:i:s', filemtime($apkFile)),
            ];
        }

        foreach (['arm64-v8a', 'armeabi-v7a'] as $abi) {
            $f = $rel['_apk_files'][$abi] ?? null;
            if ($f && is_file($f)) {
                $meta = fkssApkMeta($f);
                $artifactsDetail[$abi] = [
                    'path' => $f,
                    'filename' => basename($f),
                    'size_bytes' => $meta['size'],
                    'size_formatted' => self::formatBytes($meta['size']),
                    'sha256' => $meta['sha256'],
                    'modified_at' => date('Y-m-d H:i:s', filemtime($f)),
                ];
            }
        }

        $rel['artifacts_detail'] = $artifactsDetail;
        return $rel;
    }

    /**
     * Save updated release configuration.
     */
    public static function saveConfig(string $projectRoot, array $input): array
    {
        $current = self::getReleaseInfo($projectRoot);

        $version = preg_replace('/[^0-9.]/', '', (string)($input['latest_version'] ?? $current['latest_version']));
        if ($version === '') {
            throw new InvalidArgumentException('Latest version must be a valid version string (e.g. 1.5.0).');
        }

        $minVersion = preg_replace('/[^0-9.]/', '', (string)($input['min_version'] ?? $current['min_version']));
        if ($minVersion === '') {
            $minVersion = '1.0.0';
        }

        $build = max(1, (int)($input['latest_build'] ?? $current['latest_build']));
        $minBuild = max(1, (int)($input['min_build'] ?? $current['min_build']));
        $forceUpdate = !empty($input['force_update']);
        $drainsEnabled = isset($input['background_drains_enabled']) ? (bool)$input['background_drains_enabled'] : true;

        $notes = trim((string)($input['release_notes'] ?? $current['release_notes']));
        $bannerText = trim((string)($input['banner_text'] ?? $current['banner_text']));
        $bannerKind = in_array($input['banner_kind'] ?? '', ['info', 'warn'], true) ? $input['banner_kind'] : 'info';

        $apkPath = trim((string)($input['apk_path'] ?? ($current['apk_path'] ?? '')));
        $apkArm64Path = trim((string)($input['apk_arm64_path'] ?? ($current['apk_arm64_path'] ?? '')));
        $apkArm32Path = trim((string)($input['apk_arm32_path'] ?? ($current['apk_arm32_path'] ?? '')));

        $configData = [
            'latest_version' => $version,
            'latest_build'   => $build,
            'min_version'    => $minVersion,
            'min_build'      => $minBuild,
            'force_update'   => $forceUpdate,
            'background_drains_enabled' => $drainsEnabled,
            'release_notes'  => $notes,
            'banner_text'    => $bannerText,
            'banner_kind'    => $bannerKind,
            'apk_path'       => $apkPath,
            'apk_arm64_path' => $apkArm64Path,
            'apk_arm32_path' => $apkArm32Path,
            'tiles'          => $current['tiles'] ?? [
                'education' => ['classes', 'teachers', 'subjects', 'enrollment', 'grades', 'attendance'],
            ],
        ];

        $targetFile = self::getConfigFile($projectRoot);
        $content = "<?php\n/**\n * Auto-generated by Super Admin App Release Manager\n * Updated: " . date('Y-m-d H:i:s T') . "\n */\nreturn " . var_export($configData, true) . ";\n";

        $tempFile = $targetFile . '.tmp.' . bin2hex(random_bytes(8));
        if (@file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary release config file.');
        }

        if (!@rename($tempFile, $targetFile)) {
            @unlink($tempFile);
            throw new RuntimeException('Failed to save release config file.');
        }

        @chmod($targetFile, 0644);
        return self::getReleaseInfo($projectRoot);
    }

    /**
     * Upload an APK file directly and update release configuration.
     */
    public static function handleApkUpload(string $projectRoot, array $fileInfo, string $targetAbi = 'universal'): array
    {
        if (empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
            throw new InvalidArgumentException('No valid APK file was uploaded.');
        }

        if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with error code: ' . $fileInfo['error']);
        }

        $size = (int)$fileInfo['size'];
        if ($size <= 0 || $size > self::MAX_APK_BYTES) {
            throw new InvalidArgumentException('APK file exceeds the maximum allowed size of 200MB.');
        }

        $originalName = (string)($fileInfo['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'apk') {
            throw new InvalidArgumentException('The uploaded file must have a .apk extension.');
        }

        $releasesDir = self::getReleasesDirectory($projectRoot);
        
        $targetFilename = match ($targetAbi) {
            'arm64-v8a' => 'fkss-arm64-v8a.apk',
            'armeabi-v7a' => 'fkss-armeabi-v7a.apk',
            default => 'fkss.apk',
        };

        $destination = $releasesDir . '/' . $targetFilename;

        if (!move_uploaded_file($fileInfo['tmp_name'], $destination)) {
            throw new RuntimeException('Failed to save the APK to releases storage.');
        }

        @chmod($destination, 0644);

        // Recompute metadata and write sidecar
        require_once $projectRoot . '/api/v1/core/app_release.php';
        $meta = fkssApkMeta($destination);

        // Update release config with new path
        $configKey = match ($targetAbi) {
            'arm64-v8a' => 'apk_arm64_path',
            'armeabi-v7a' => 'apk_arm32_path',
            default => 'apk_path',
        };

        $currentInfo = self::getReleaseInfo($projectRoot);
        $updatePayload = [
            $configKey => $destination,
        ];

        return self::saveConfig($projectRoot, $updatePayload);
    }

    /**
     * Remove an APK artifact and update release configuration.
     */
    public static function deleteApk(string $projectRoot, string $targetAbi = 'universal'): array
    {
        $info = self::getReleaseInfo($projectRoot);
        $targetFile = match ($targetAbi) {
            'arm64-v8a' => $info['_apk_files']['arm64-v8a'] ?? null,
            'armeabi-v7a' => $info['_apk_files']['armeabi-v7a'] ?? null,
            default => $info['_apk_file'] ?? null,
        };

        if ($targetFile && is_file($targetFile)) {
            @unlink($targetFile);
            @unlink($targetFile . '.meta');
        }

        $configKey = match ($targetAbi) {
            'arm64-v8a' => 'apk_arm64_path',
            'armeabi-v7a' => 'apk_arm32_path',
            default => 'apk_path',
        };

        return self::saveConfig($projectRoot, [$configKey => '']);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
