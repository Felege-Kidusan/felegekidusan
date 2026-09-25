<?php
/**
 * CLI target for scheduled cleanup of private profile-image artifacts.
 *
 * Example cron entry (deployment-owned schedule):
 *   17 3 * * * php /path/to/SSMS/admin/backend/profile_image_cleanup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/services/ProfileImageService.php';

try {
    if (!isset($conn) || !($conn instanceof \mysqli)) {
        throw new \RuntimeException('Database connection is unavailable.');
    }
    if (!\App\Services\MysqliProfileRepository::profileImageColumnAvailable($conn)) {
        throw new \RuntimeException('The profile-image schema is unavailable.');
    }

    $repository = new \App\Services\MysqliProfileImageRepository($conn);
    $result = \App\Services\PrivateProfileImageStorage::configured()->reapUnreferenced(
        $repository->referencedImageReferences()
    );
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (\Throwable $error) {
    error_log('Profile-image cleanup failed: ' . $error->getMessage());
    fwrite(STDERR, "Profile-image cleanup failed.\n");
    exit(1);
}
