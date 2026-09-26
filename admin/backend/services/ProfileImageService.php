<?php
/**
 * Secure private profile-image preparation, storage, replacement, and removal.
 *
 * This Phase 1 foundation does not create a production directory and does not
 * require the deferred profile_image_path migration. Runtime behavior is
 * injectable and can be tested with a temporary storage root/repository.
 */
namespace App\Services;

require_once __DIR__ . '/ProfileService.php';

final class ProfileImageDomainException extends \DomainException
{
    private string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}

final class ProfileImagePersistenceException extends \RuntimeException
{
}

final class ProfileImageArtifact
{
    private string $jpegBytes;

    public function __construct(string $jpegBytes)
    {
        if ($jpegBytes === '') {
            throw new \InvalidArgumentException('Encoded profile image is empty.');
        }
        $this->jpegBytes = $jpegBytes;
    }

    /** Internal storage boundary; never include this value in API responses. */
    public function jpegBytes(): string
    {
        return $this->jpegBytes;
    }
}

final class StoredProfileImage
{
    private string $logicalReference;

    public function __construct(string $logicalReference)
    {
        $this->logicalReference = $logicalReference;
    }

    /** Internal persistence value; controllers must not return it to clients. */
    public function logicalReference(): string
    {
        return $this->logicalReference;
    }
}

final class ProfileImageRead
{
    private string $jpegBytes;
    private string $opaqueVersion;

    public function __construct(string $jpegBytes, string $opaqueVersion)
    {
        if ($jpegBytes === '' || !preg_match('/^[a-f0-9]{64}$/D', $opaqueVersion)) {
            throw new \InvalidArgumentException('Invalid profile-image read result.');
        }
        $this->jpegBytes = $jpegBytes;
        $this->opaqueVersion = $opaqueVersion;
    }

    public function jpegBytes(): string
    {
        return $this->jpegBytes;
    }

    public function opaqueVersion(): string
    {
        return $this->opaqueVersion;
    }
}

interface ProfileImageRepository
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;

    /** @return array<string,mixed>|null */
    public function findProfileState(int $userId): ?array;

    /** @return array<string,mixed>|null */
    public function lockProfileState(int $userId): ?array;
    public function updateImageReference(int $userId, ?string $logicalReference): void;
}

/** Available only after the deferred profile_image_path migration is present. */
final class MysqliProfileImageRepository implements ProfileImageRepository
{
    private \mysqli $database;

    public function __construct(\mysqli $database)
    {
        $this->database = $database;
    }

    public function begin(): void
    {
        if (!$this->database->begin_transaction()) {
            throw new ProfileImagePersistenceException('Could not begin profile-image transaction.');
        }
    }

    public function commit(): void
    {
        if (!$this->database->commit()) {
            throw new ProfileImagePersistenceException('Could not commit profile-image transaction.');
        }
    }

    public function rollback(): void
    {
        try {
            $this->database->rollback();
        } catch (\Throwable $ignored) {
        }
    }

    public function findProfileState(int $userId): ?array
    {
        return $this->findState($userId, false);
    }

    public function lockProfileState(int $userId): ?array
    {
        return $this->findState($userId, true);
    }

    /** @return array<string,mixed>|null */
    private function findState(int $userId, bool $forUpdate): ?array
    {
        $sql = 'SELECT id, username, email, full_name, profile_image_path
                  FROM users WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $this->database->prepare($sql);
        if (!$statement) {
            throw new ProfileImagePersistenceException('Could not prepare profile-image lookup.');
        }
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            throw new ProfileImagePersistenceException('Could not load profile-image state.');
        }
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        return $row ?: null;
    }

    /** @return array<int,string> */
    public function referencedImageReferences(): array
    {
        $result = $this->database->query(
            "SELECT profile_image_path FROM users
              WHERE profile_image_path IS NOT NULL AND profile_image_path <> ''"
        );
        if (!$result) {
            throw new ProfileImagePersistenceException('Could not enumerate profile-image references.');
        }
        $references = [];
        while ($row = $result->fetch_assoc()) {
            if (is_string($row['profile_image_path'] ?? null)) {
                $references[] = $row['profile_image_path'];
            }
        }
        $result->free();
        return $references;
    }

    public function updateImageReference(int $userId, ?string $logicalReference): void
    {
        $statement = $this->database->prepare(
            'UPDATE users SET profile_image_path = ? WHERE id = ?'
        );
        if (!$statement) {
            throw new ProfileImagePersistenceException('Could not prepare profile-image update.');
        }
        $statement->bind_param('si', $logicalReference, $userId);
        $ok = $statement->execute();
        $affected = (int)$statement->affected_rows;
        $statement->close();
        if (!$ok || $affected !== 1) {
            throw new ProfileImagePersistenceException('Could not update profile image.');
        }
    }
}

final class PrivateProfileImageStorage
{
    public const PRIVATE_PREFIX = 'private://profiles/';
    public const REAP_MINIMUM_AGE_SECONDS = 86400;
    private const FINAL_NAME_PATTERN = '/^[a-f0-9]{64}\.jpg$/D';
    private const TEMPORARY_NAME_PATTERN = '/^\.profile-[a-f0-9]{32}\.tmp$/D';
    private const REAPER_LOCK_NAME = '.profile-cleanup.lock';

    private string $root;

    public function __construct(string $root)
    {
        $root = rtrim($root, '/\\');
        if ($root === '') {
            throw new \InvalidArgumentException('A private profile-image root is required.');
        }
        $this->root = $root;
    }

    /**
     * Resolve configuration without creating directories. Deployment and
     * permissions remain a later, explicit concern.
     */
    public static function configured(): self
    {
        if (defined('PROFILE_PRIVATE_STORAGE_PATH') && PROFILE_PRIVATE_STORAGE_PATH !== '') {
            return new self((string)PROFILE_PRIVATE_STORAGE_PATH);
        }
        if (defined('MEMBER_PRIVATE_STORAGE_PATH') && MEMBER_PRIVATE_STORAGE_PATH !== '') {
            return new self(rtrim((string)MEMBER_PRIVATE_STORAGE_PATH, '/\\') . '/profiles');
        }
        $projectRoot = defined('ROOT_PATH') ? (string)ROOT_PATH : dirname(__DIR__, 3);
        $primary = dirname($projectRoot) . '/ssms_private/profiles';
        if (is_dir($primary)) {
            return new self($primary);
        }
        return new self($projectRoot . '/admin/uploads/profiles');
    }

    public function stage(ProfileImageArtifact $artifact): StoredProfileImage
    {
        if (!is_dir($this->root) || !is_writable($this->root)) {
            throw new ProfileImagePersistenceException('Private profile-image storage is unavailable.');
        }

        $cleanupLock = $this->acquireCleanupLock(LOCK_SH);
        try {
            $name = bin2hex(random_bytes(32)) . '.jpg';
            $temporaryName = '.profile-' . bin2hex(random_bytes(16)) . '.tmp';
            $temporaryPath = $this->root . DIRECTORY_SEPARATOR . $temporaryName;
            $finalPath = $this->root . DIRECTORY_SEPARATOR . $name;
            $handle = @fopen($temporaryPath, 'xb');
            if ($handle === false) {
                throw new ProfileImagePersistenceException('Could not stage profile image.');
            }

            try {
                $bytes = $artifact->jpegBytes();
                $offset = 0;
                $length = strlen($bytes);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new ProfileImagePersistenceException('Could not write staged profile image.');
                    }
                    $offset += $written;
                }
                if (!fflush($handle)) {
                    throw new ProfileImagePersistenceException('Could not flush staged profile image.');
                }
                fclose($handle);
                $handle = null;
                @chmod($temporaryPath, 0600);
                if (!@rename($temporaryPath, $finalPath)) {
                    throw new ProfileImagePersistenceException('Could not finalize staged profile image.');
                }
                @chmod($finalPath, 0600);
            } catch (\Throwable $error) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                @unlink($temporaryPath);
                @unlink($finalPath);
                throw $error;
            }

            return new StoredProfileImage(self::PRIVATE_PREFIX . $name);
        } finally {
            $this->releaseCleanupLock($cleanupLock);
        }
    }

    /** Idempotent, confined deletion. */
    public function discard(?string $logicalReference): bool
    {
        if ($logicalReference === null || $logicalReference === '') {
            return true;
        }
        try {
            $cleanupLock = $this->acquireCleanupLock(LOCK_SH);
        } catch (ProfileImagePersistenceException $error) {
            return false;
        }
        try {
            $path = $this->resolve($logicalReference);
            if ($path === null || !is_file($path)) {
                return true;
            }
            return @unlink($path);
        } finally {
            $this->releaseCleanupLock($cleanupLock);
        }
    }

    /**
     * Remove only aged, unreferenced final files and aged staging files.
     *
     * The reference set is read from the database by trusted server code. The
     * fixed one-day grace period protects in-flight and newly committed files;
     * the exclusive lock makes concurrent cleanup attempts harmless.
     *
     * @param array<int,string> $referencedLogicalReferences
     * @return array{lock_acquired:bool,deleted_orphans:int,deleted_temporaries:int,skipped_referenced:int,skipped_fresh:int}
     */
    public function reapUnreferenced(
        array $referencedLogicalReferences,
        ?int $now = null
    ): array {
        $counts = [
            'lock_acquired' => false,
            'deleted_orphans' => 0,
            'deleted_temporaries' => 0,
            'skipped_referenced' => 0,
            'skipped_fresh' => 0,
        ];
        $root = realpath($this->root);
        if ($root === false || !is_dir($root) || !is_writable($root)) {
            throw new ProfileImagePersistenceException('Private profile-image storage is unavailable.');
        }

        $lockPath = $root . DIRECTORY_SEPARATOR . self::REAPER_LOCK_NAME;
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new ProfileImagePersistenceException('Could not open the profile-image cleanup lock.');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return $counts;
        }
        $counts['lock_acquired'] = true;

        try {
            $referencedNames = [];
            $prefix = preg_quote(self::PRIVATE_PREFIX, '#');
            foreach ($referencedLogicalReferences as $reference) {
                if (is_string($reference)
                    && preg_match('#^' . $prefix . '([a-f0-9]{64}\.jpg)$#D', $reference, $match) === 1) {
                    $referencedNames[$match[1]] = true;
                }
            }
            $cutoff = ($now ?? time()) - self::REAP_MINIMUM_AGE_SECONDS;
            $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $entry) {
                $name = $entry->getFilename();
                $isFinal = preg_match(self::FINAL_NAME_PATTERN, $name) === 1;
                $isTemporary = preg_match(self::TEMPORARY_NAME_PATTERN, $name) === 1;
                if ((!$isFinal && !$isTemporary) || $entry->isLink() || !$entry->isFile()) {
                    continue;
                }
                $modified = $entry->getMTime();
                if ($modified >= $cutoff) {
                    $counts['skipped_fresh']++;
                    continue;
                }
                if ($isFinal && isset($referencedNames[$name])) {
                    $counts['skipped_referenced']++;
                    continue;
                }
                $candidate = $root . DIRECTORY_SEPARATOR . $name;
                $real = realpath($candidate);
                if ($real === false || dirname($real) !== $root || !is_file($real)) {
                    continue;
                }
                if (@unlink($real)) {
                    if ($isTemporary) {
                        $counts['deleted_temporaries']++;
                    } else {
                        $counts['deleted_orphans']++;
                    }
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $counts;
    }

    /** @return resource */
    private function acquireCleanupLock(int $operation)
    {
        $root = realpath($this->root);
        if ($root === false || !is_dir($root) || !is_writable($root)) {
            throw new ProfileImagePersistenceException('Private profile-image storage is unavailable.');
        }
        $lockPath = $root . DIRECTORY_SEPARATOR . self::REAPER_LOCK_NAME;
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new ProfileImagePersistenceException('Could not open the profile-image cleanup lock.');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, $operation)) {
            fclose($lock);
            throw new ProfileImagePersistenceException('Could not acquire the profile-image cleanup lock.');
        }
        return $lock;
    }

    /** @param resource $lock */
    private function releaseCleanupLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** Internal serving/cleanup boundary; never expose the returned path. */
    public function resolve(string $logicalReference): ?string
    {
        $prefix = preg_quote(self::PRIVATE_PREFIX, '#');
        if (!preg_match('#^' . $prefix . '([a-f0-9]{64}\.jpg)$#D', $logicalReference, $match)) {
            return null;
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir($root)) {
            return null;
        }
        $candidate = $root . DIRECTORY_SEPARATOR . $match[1];
        if (!is_file($candidate)) {
            return null;
        }
        $real = realpath($candidate);
        if ($real === false || dirname($real) !== $root) {
            return null;
        }
        return $real;
    }
}

final class ProfileImageService
{
    public const OUTPUT_SIZE = 512;
    public const JPEG_QUALITY = 85;
    public const MAX_UPLOAD_BYTES = 4 * 1024 * 1024;
    public const MIN_DIMENSION = 64;
    public const MAX_DIMENSION = 4096;
    public const MAX_PIXELS = 12000000;

    private ProfileImageRepository $profiles;
    private PrivateProfileImageStorage $storage;

    public function __construct(
        ProfileImageRepository $profiles,
        PrivateProfileImageStorage $storage
    ) {
        $this->profiles = $profiles;
        $this->storage = $storage;
    }

    /** @param array<string,mixed> $file */
    public static function prepareRequestUpload(array $file): ProfileImageArtifact
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ProfileImageDomainException('IMAGE_SIZE_INVALID', 'Image must be no larger than 4 MB.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ProfileImageDomainException('IMAGE_UPLOAD_FAILED', 'Image upload failed.');
        }
        $temporary = (string)($file['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw new ProfileImageDomainException('IMAGE_TRANSFER_INVALID', 'Image transfer is invalid.');
        }
        $actualSize = @filesize($temporary);
        if ($actualSize === false || $actualSize <= 0 || $actualSize > self::MAX_UPLOAD_BYTES) {
            throw new ProfileImageDomainException('IMAGE_SIZE_INVALID', 'Image must be no larger than 4 MB.');
        }
        $raw = @file_get_contents($temporary);
        if ($raw === false || strlen($raw) !== $actualSize) {
            throw new ProfileImageDomainException('IMAGE_UNREADABLE', 'Image upload could not be read.');
        }
        return self::prepareBytes($raw);
    }

    /**
     * Validate authoritative bytes, safely decode, center-crop, and newly
     * encode a metadata-free 512×512 JPEG. File names/extensions are ignored.
     */
    public static function prepareBytes(string $raw): ProfileImageArtifact
    {
        $length = strlen($raw);
        if ($length <= 0 || $length > self::MAX_UPLOAD_BYTES) {
            throw new ProfileImageDomainException('IMAGE_SIZE_INVALID', 'Image must be no larger than 4 MB.');
        }
        if (!class_exists('finfo')) {
            throw new ProfileImagePersistenceException('MIME inspection is unavailable.');
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new ProfileImagePersistenceException('Secure image processing is unavailable.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->buffer($raw));
        $accepted = [
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
        ];
        if (defined('IMAGETYPE_WEBP')
            && function_exists('imagewebp')
            && (imagetypes() & IMG_WEBP) === IMG_WEBP) {
            $accepted['image/webp'] = IMAGETYPE_WEBP;
        }
        if (!isset($accepted[$mime])) {
            throw new ProfileImageDomainException(
                'IMAGE_TYPE_INVALID',
                'Only verified JPEG, PNG, or WebP images are allowed.'
            );
        }

        $information = @getimagesizefromstring($raw);
        if ($information === false || (int)($information[2] ?? 0) !== $accepted[$mime]) {
            throw new ProfileImageDomainException('IMAGE_DECODE_INVALID', 'The file is not a valid image.');
        }
        $width = (int)$information[0];
        $height = (int)$information[1];
        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width > intdiv(self::MAX_PIXELS, max(1, $height))) {
            throw new ProfileImageDomainException(
                'IMAGE_DIMENSIONS_INVALID',
                'Image dimensions or pixel count are outside the allowed limits.'
            );
        }

        $source = @imagecreatefromstring($raw);
        if ($source === false) {
            throw new ProfileImageDomainException('IMAGE_DECODE_INVALID', 'The file is not a valid image.');
        }
        $target = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
        if ($target === false) {
            imagedestroy($source);
            throw new ProfileImagePersistenceException('Could not allocate profile-image output.');
        }

        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        $side = min($width, $height);
        $sourceX = intdiv($width - $side, 2);
        $sourceY = intdiv($height - $side, 2);
        $copied = imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            self::OUTPUT_SIZE,
            self::OUTPUT_SIZE,
            $side,
            $side
        );
        imagedestroy($source);
        if (!$copied) {
            imagedestroy($target);
            throw new ProfileImagePersistenceException('Could not resize profile image.');
        }

        ob_start();
        $encoded = imagejpeg($target, null, self::JPEG_QUALITY);
        $jpegBytes = (string)ob_get_clean();
        imagedestroy($target);
        if (!$encoded || $jpegBytes === '') {
            throw new ProfileImagePersistenceException('Could not encode profile image.');
        }
        $outputInfo = @getimagesizefromstring($jpegBytes);
        if ($outputInfo === false
            || (int)$outputInfo[0] !== self::OUTPUT_SIZE
            || (int)$outputInfo[1] !== self::OUTPUT_SIZE
            || (int)$outputInfo[2] !== IMAGETYPE_JPEG) {
            throw new ProfileImagePersistenceException('Encoded profile image failed verification.');
        }
        return new ProfileImageArtifact($jpegBytes);
    }

    /**
     * Read only the authenticated owner's private, normalized JPEG. Physical
     * paths remain inside this service and are never part of the result.
     */
    public function readOwnImage(AuthenticatedProfileIdentity $identity): ProfileImageRead
    {
        $row = $this->profiles->findProfileState($identity->userId());
        if ($row === null) {
            throw new ProfileImageDomainException('USER_NOT_FOUND', 'User not found.');
        }
        $reference = self::nullableReference($row['profile_image_path'] ?? null);
        if ($reference === null) {
            throw new ProfileImageDomainException('PROFILE_IMAGE_NOT_SET', 'Profile image is not set.');
        }
        $physicalPath = $this->storage->resolve($reference);
        if ($physicalPath === null) {
            throw new ProfileImagePersistenceException('Stored profile image is unavailable.');
        }
        $bytes = @file_get_contents($physicalPath);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new ProfileImagePersistenceException('Stored profile image could not be read.');
        }
        $information = @getimagesizefromstring($bytes);
        if ($information === false
            || (int)$information[0] !== self::OUTPUT_SIZE
            || (int)$information[1] !== self::OUTPUT_SIZE
            || (int)$information[2] !== IMAGETYPE_JPEG) {
            throw new ProfileImagePersistenceException('Stored profile image failed verification.');
        }
        return new ProfileImageRead($bytes, hash('sha256', $reference));
    }

    /**
     * Stage first, switch the owner's reference transactionally, then clean the
     * previous object after commit. A DB failure removes only the staged object.
     *
     * @return array<string,mixed>
     */
    public function replaceOwnImage(
        AuthenticatedProfileIdentity $identity,
        ProfileImageArtifact $artifact,
        string $expectedVersion
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedVersion) !== 1) {
            throw new ProfileImageDomainException(
                'PROFILE_VERSION_REQUIRED',
                'A valid profile version is required.'
            );
        }
        $staged = $this->storage->stage($artifact);
        $newReference = $staged->logicalReference();
        $inTransaction = false;
        try {
            $this->profiles->begin();
            $inTransaction = true;
            $row = $this->profiles->lockProfileState($identity->userId());
            if ($row === null) {
                throw new ProfileImageDomainException('USER_NOT_FOUND', 'User not found.');
            }
            if (!hash_equals(ProfileService::profileVersion($row), $expectedVersion)) {
                throw new ProfileImageDomainException(
                    'PROFILE_CONFLICT',
                    'The profile changed. Reload it and review your changes.'
                );
            }
            $oldReference = self::nullableReference($row['profile_image_path'] ?? null);
            $this->profiles->updateImageReference($identity->userId(), $newReference);
            $updated = $row;
            $updated['profile_image_path'] = $newReference;
            $this->profiles->commit();
            $inTransaction = false;
        } catch (\Throwable $error) {
            if ($inTransaction) {
                $this->profiles->rollback();
            }
            $this->storage->discard($newReference);
            throw $error;
        }

        $cleanupPending = $oldReference !== null
            && $oldReference !== $newReference
            && !$this->storage->discard($oldReference);
        return [
            'profile_image' => [
                'present' => true,
                'version' => hash('sha256', $newReference),
                'url' => null,
            ],
            'profile_version' => ProfileService::profileVersion($updated),
            'cleanup_pending' => $cleanupPending,
            'audit_action' => $oldReference === null
                ? 'Profile Image Uploaded'
                : 'Profile Image Replaced',
            'actor_user_id' => $identity->userId(),
            'target_user_id' => $identity->userId(),
        ];
    }

    /** Idempotent own-image removal with post-commit file cleanup. */
    public function removeOwnImage(
        AuthenticatedProfileIdentity $identity,
        string $expectedVersion
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedVersion) !== 1) {
            throw new ProfileImageDomainException(
                'PROFILE_VERSION_REQUIRED',
                'A valid profile version is required.'
            );
        }
        $inTransaction = false;
        try {
            $this->profiles->begin();
            $inTransaction = true;
            $row = $this->profiles->lockProfileState($identity->userId());
            if ($row === null) {
                throw new ProfileImageDomainException('USER_NOT_FOUND', 'User not found.');
            }
            $oldReference = self::nullableReference($row['profile_image_path'] ?? null);
            // Already absent is success even when a retry carries the version
            // from immediately before the first successful removal.
            if ($oldReference === null) {
                $this->profiles->commit();
                $inTransaction = false;
                return self::removedResult($identity->userId(), $row, false);
            }
            if (!hash_equals(ProfileService::profileVersion($row), $expectedVersion)) {
                throw new ProfileImageDomainException(
                    'PROFILE_CONFLICT',
                    'The profile changed. Reload it and review your changes.'
                );
            }
            $this->profiles->updateImageReference($identity->userId(), null);
            $updated = $row;
            $updated['profile_image_path'] = null;
            $this->profiles->commit();
            $inTransaction = false;
        } catch (\Throwable $error) {
            if ($inTransaction) {
                $this->profiles->rollback();
            }
            throw $error;
        }

        $cleanupPending = !$this->storage->discard($oldReference);
        return self::removedResult($identity->userId(), $updated, $cleanupPending);
    }

    /** @param mixed $value */
    private static function nullableReference($value): ?string
    {
        $reference = trim((string)$value);
        return $reference === '' ? null : $reference;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function removedResult(int $userId, array $row, bool $cleanupPending): array
    {
        $row['profile_image_path'] = null;
        return [
            'profile_image' => ['present' => false, 'version' => null, 'url' => null],
            'profile_version' => ProfileService::profileVersion($row),
            'cleanup_pending' => $cleanupPending,
            'audit_action' => 'Profile Image Removed',
            'actor_user_id' => $userId,
            'target_user_id' => $userId,
        ];
    }
}
