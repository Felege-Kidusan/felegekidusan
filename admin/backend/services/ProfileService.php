<?php
/**
 * Shared profile domain rules with deliberately separate self-service and
 * administrator mutation entry points.
 *
 * Controllers must construct identity values exclusively from verified
 * JWT/session context. Client payloads are never an identity source; only the
 * explicitly administrator-authorized entry point accepts a target user id.
 */
namespace App\Services;

final class AuthenticatedProfileIdentity
{
    private int $userId;

    private function __construct(int $userId)
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Authenticated user id is required.');
        }
        $this->userId = $userId;
    }

    /** Construct only after the JWT/session adapter has authenticated the id. */
    public static function fromTrustedUserId(int $userId): self
    {
        return new self($userId);
    }

    public function userId(): int
    {
        return $this->userId;
    }
}

final class AuthorizedAdministratorIdentity
{
    private int $actorUserId;

    private function __construct(int $actorUserId)
    {
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Administrator actor id is required.');
        }
        $this->actorUserId = $actorUserId;
    }

    /** Construct only after a controller has enforced an administrator role. */
    public static function fromTrustedAuthorization(int $actorUserId): self
    {
        return new self($actorUserId);
    }

    public function actorUserId(): int
    {
        return $this->actorUserId;
    }
}

class ProfileDomainException extends \DomainException
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

final class ProfilePersistenceException extends \RuntimeException
{
}

interface ProfileRepository
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;

    /** @return array<string,mixed>|null */
    public function findById(int $userId, bool $forUpdate = false): ?array;

    public function usernameExists(string $username, int $excludeUserId): bool;
    public function emailExists(string $email, int $excludeUserId): bool;

    /** @param array<string,string|null> $fields */
    public function updateProfileFields(int $userId, array $fields): void;
}

/**
 * mysqli adapter for the current users table.
 *
 * Phase 1 defaults profile-image selection to NULL because migration 049 has
 * intentionally not been applied. Phase 2 may opt in only after that migration
 * is verified on the target database.
 */
final class MysqliProfileRepository implements ProfileRepository
{
    private \mysqli $database;
    private bool $profileImageColumnAvailable;

    public function __construct(\mysqli $database, bool $profileImageColumnAvailable = false)
    {
        $this->database = $database;
        $this->profileImageColumnAvailable = $profileImageColumnAvailable;
    }

    /** Read-only rolling-deployment probe; never creates or alters schema. */
    public static function profileImageColumnAvailable(\mysqli $database): bool
    {
        try {
            $statement = $database->prepare(
                "SELECT COUNT(*) AS column_count
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'users'
                    AND COLUMN_NAME = 'profile_image_path'
                    AND DATA_TYPE = 'varchar'
                    AND CHARACTER_MAXIMUM_LENGTH = 255
                    AND IS_NULLABLE = 'YES'"
            );
            if (!$statement || !$statement->execute()) {
                if ($statement) {
                    $statement->close();
                }
                return false;
            }
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();
            return (int)($row['column_count'] ?? 0) === 1;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function begin(): void
    {
        if (!$this->database->begin_transaction()) {
            throw new ProfilePersistenceException('Could not begin profile transaction.');
        }
    }

    public function commit(): void
    {
        if (!$this->database->commit()) {
            throw new ProfilePersistenceException('Could not commit profile transaction.');
        }
    }

    public function rollback(): void
    {
        try {
            $this->database->rollback();
        } catch (\Throwable $ignored) {
        }
    }

    public function findById(int $userId, bool $forUpdate = false): ?array
    {
        $image = $this->profileImageColumnAvailable
            ? 'profile_image_path'
            : 'NULL AS profile_image_path';
        $sql = "SELECT id, username, email, full_name, role, password_hash,
                       is_active, authorization_version, member_id, created_at,
                       last_login, {$image}
                  FROM users WHERE id = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $this->database->prepare($sql);
        if (!$statement) {
            throw new ProfilePersistenceException('Could not prepare profile lookup.');
        }
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            throw new ProfilePersistenceException('Could not load profile.');
        }
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        return $row ?: null;
    }

    public function usernameExists(string $username, int $excludeUserId): bool
    {
        return $this->uniqueValueExists('username', $username, $excludeUserId);
    }

    public function emailExists(string $email, int $excludeUserId): bool
    {
        return $this->uniqueValueExists('email', $email, $excludeUserId);
    }

    public function updateProfileFields(int $userId, array $fields): void
    {
        $allowed = ['username', 'email', 'full_name'];
        $sets = [];
        $values = [];
        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                throw new ProfileDomainException(
                    'PROFILE_FIELD_NOT_ALLOWED',
                    'This profile field cannot be changed.'
                );
            }
            $sets[] = "{$field} = ?";
            $values[] = $value;
        }
        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $statement = $this->database->prepare($sql);
        if (!$statement) {
            throw new ProfilePersistenceException('Could not prepare profile update.');
        }
        $types = str_repeat('s', count($values)) . 'i';
        $values[] = $userId;
        $statement->bind_param($types, ...$values);
        try {
            $ok = $statement->execute();
            $errno = (int)$statement->errno;
            $error = (string)$statement->error;
        } catch (\Throwable $failure) {
            $ok = false;
            $errno = (int)$failure->getCode();
            $error = $failure->getMessage();
        }
        $statement->close();
        if ($ok) {
            return;
        }
        self::throwUpdateFailure($errno, $error);
    }

    private static function throwUpdateFailure(int $errno, string $error): void
    {
        if ($errno === 1062) {
            $reason = stripos($error, 'email') !== false
                ? 'EMAIL_TAKEN'
                : (stripos($error, 'username') !== false ? 'USERNAME_TAKEN' : 'PROFILE_DUPLICATE');
            throw new ProfileDomainException($reason, 'That account value is already in use.');
        }
        throw new ProfilePersistenceException('Could not update profile.');
    }

    private function uniqueValueExists(string $column, string $value, int $excludeUserId): bool
    {
        if (!in_array($column, ['username', 'email'], true)) {
            throw new \InvalidArgumentException('Unsupported unique profile field.');
        }
        $statement = $this->database->prepare(
            "SELECT id FROM users WHERE {$column} = ? AND id <> ? LIMIT 1"
        );
        if (!$statement) {
            throw new ProfilePersistenceException('Could not prepare profile uniqueness check.');
        }
        $statement->bind_param('si', $value, $excludeUserId);
        if (!$statement->execute()) {
            $statement->close();
            throw new ProfilePersistenceException('Could not check profile uniqueness.');
        }
        $exists = (bool)$statement->get_result()->fetch_assoc();
        $statement->close();
        return $exists;
    }
}

final class ProfileService
{
    private const ALLOWED_INPUT_FIELDS = [
        'username', 'email', 'full_name', 'current_password', 'profile_version',
    ];

    private const ADMIN_ALLOWED_INPUT_FIELDS = [
        'username', 'email', 'full_name', 'profile_version',
    ];

    private const RESERVED_USERNAMES = [
        'admin', 'administrator', 'root', 'system', 'support', 'api', 'null',
    ];

    private ProfileRepository $profiles;

    public function __construct(ProfileRepository $profiles)
    {
        $this->profiles = $profiles;
    }

    /**
     * Validate the opaque optimistic-lock token before any profile mutation.
     * Versions emitted by shapeProfile() are lowercase SHA-256 digests.
     *
     * @param mixed $value
     */
    public static function requireProfileVersion($value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ProfileDomainException(
                'PROFILE_VERSION_REQUIRED',
                'A valid profile version is required.'
            );
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public function getOwnProfile(AuthenticatedProfileIdentity $identity): array
    {
        $row = $this->profiles->findById($identity->userId());
        if ($row === null) {
            throw new ProfileDomainException('USER_NOT_FOUND', 'User not found.');
        }
        return self::shapeProfile($row);
    }

    /**
     * Transactional, optimistic self-service update.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateOwnProfile(
        AuthenticatedProfileIdentity $identity,
        array $input,
        string $expectedVersion
    ): array {
        self::assertAllowedInput($input, self::ALLOWED_INPUT_FIELDS);
        return $this->mutateProfile(
            $identity->userId(),
            $input,
            $expectedVersion,
            true
        );
    }

    /**
     * Administrator profile mutation is explicit and separate from self-service.
     * The trusted authorization value must be produced by a controller's role
     * check; it is not accepted from the request body.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateProfileByAdministrator(
        AuthorizedAdministratorIdentity $administrator,
        int $targetUserId,
        array $input,
        string $expectedVersion
    ): array {
        if ($targetUserId <= 0) {
            throw new ProfileDomainException('USER_NOT_FOUND', 'User not found.');
        }
        // Reading the trusted actor prevents this boundary from degrading into
        // an unmarked target-user update during later controller integration.
        $administrator->actorUserId();
        self::assertAllowedInput($input, self::ADMIN_ALLOWED_INPUT_FIELDS);
        return $this->mutateProfile($targetUserId, $input, $expectedVersion, false);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function mutateProfile(
        int $targetUserId,
        array $input,
        string $expectedVersion,
        bool $requireCurrentPasswordForIdentityChange
    ): array {
        $expectedVersion = self::requireProfileVersion($expectedVersion);
        if (array_key_exists('profile_version', $input)
            && (!is_string($input['profile_version'])
                || !hash_equals($expectedVersion, $input['profile_version']))) {
            throw new ProfileDomainException('PROFILE_CONFLICT', 'Conflicting profile versions were supplied.');
        }

        $this->profiles->begin();
        try {
            $row = $this->profiles->findById($targetUserId, true);
            if ($row === null) {
                throw new ProfileDomainException('USER_NOT_FOUND', 'User not found.');
            }
            if (!hash_equals(self::profileVersion($row), $expectedVersion)) {
                throw new ProfileDomainException(
                    'PROFILE_CONFLICT',
                    'The profile changed. Reload it and review your changes.'
                );
            }

            $updates = [];
            if (array_key_exists('username', $input)) {
                $username = self::normalizeUsername($input['username']);
                if (!hash_equals((string)$row['username'], $username)) {
                    $updates['username'] = $username;
                }
            }
            if (array_key_exists('email', $input)) {
                $email = self::normalizeEmail($input['email']);
                if (($row['email'] ?? null) !== $email) {
                    $updates['email'] = $email;
                }
            }
            if (array_key_exists('full_name', $input)) {
                $name = self::normalizeFullName($input['full_name']);
                if (!hash_equals((string)$row['full_name'], $name)) {
                    $updates['full_name'] = $name;
                }
            }

            if ($requireCurrentPasswordForIdentityChange
                && (isset($updates['username']) || array_key_exists('email', $updates))) {
                $currentPassword = $input['current_password'] ?? null;
                if (!is_string($currentPassword)
                    || $currentPassword === ''
                    || strlen($currentPassword) > 4096
                    || !password_verify($currentPassword, (string)($row['password_hash'] ?? ''))) {
                    throw new ProfileDomainException(
                        'CURRENT_PASSWORD_INCORRECT',
                        'Current password is incorrect.'
                    );
                }
            }

            if (isset($updates['username'])
                && $this->profiles->usernameExists($updates['username'], $targetUserId)) {
                throw new ProfileDomainException('USERNAME_TAKEN', 'That username is already in use.');
            }
            if (array_key_exists('email', $updates)
                && $updates['email'] !== null
                && $this->profiles->emailExists($updates['email'], $targetUserId)) {
                throw new ProfileDomainException('EMAIL_TAKEN', 'That email is already in use.');
            }

            $this->profiles->updateProfileFields($targetUserId, $updates);
            $updated = array_merge($row, $updates);
            $this->profiles->commit();
            return self::shapeProfile($updated);
        } catch (\Throwable $error) {
            $this->profiles->rollback();
            throw $error;
        }
    }

    /** @param mixed $value */
    public static function normalizeUsername($value): string
    {
        if (!is_string($value)) {
            throw new ProfileDomainException('USERNAME_INVALID', 'Username must be text.');
        }
        $username = strtolower(trim($value));
        $length = strlen($username);
        if ($length < 3 || $length > 50
            || !preg_match('/^[a-z0-9][a-z0-9_.]*[a-z0-9]$/D', $username)
            || preg_match('/[._]{2}/', $username)) {
            throw new ProfileDomainException(
                'USERNAME_INVALID',
                'Username must be 3–50 lowercase letters, numbers, dots or underscores and must begin and end with a letter or number.'
            );
        }
        if (in_array($username, self::RESERVED_USERNAMES, true)) {
            throw new ProfileDomainException('USERNAME_RESERVED', 'That username is reserved.');
        }
        return $username;
    }

    /** @param mixed $value */
    public static function normalizeFullName($value): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new ProfileDomainException('FULL_NAME_INVALID', 'Full name must be valid text.');
        }
        $name = trim($value);
        if ($name === '' || self::unicodeLength($name) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new ProfileDomainException(
                'FULL_NAME_INVALID',
                'Full name must be 1–100 characters and contain no control characters.'
            );
        }
        return $name;
    }

    /** @param mixed $value */
    public static function normalizeEmail($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ProfileDomainException('EMAIL_INVALID', 'Email must be text or null.');
        }
        $email = strtolower(trim($value));
        if ($email === '') {
            return null;
        }
        if (strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ProfileDomainException('EMAIL_INVALID', 'Enter a valid email address.');
        }
        return $email;
    }

    /** @param array<string,mixed> $row */
    public static function profileVersion(array $row): string
    {
        $email = array_key_exists('email', $row) && $row['email'] !== null
            ? (string)$row['email']
            : null;
        $image = array_key_exists('profile_image_path', $row) && $row['profile_image_path'] !== null
            ? (string)$row['profile_image_path']
            : null;
        $canonical = json_encode([
            'username' => (string)($row['username'] ?? ''),
            'email' => $email,
            'full_name' => (string)($row['full_name'] ?? ''),
            'profile_image_path' => $image,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($canonical === false) {
            throw new ProfilePersistenceException('Could not version profile.');
        }
        return hash('sha256', $canonical);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function shapeProfile(array $row): array
    {
        $imagePath = trim((string)($row['profile_image_path'] ?? ''));
        $assignments = isset($row['assignments']) && is_array($row['assignments'])
            ? array_values($row['assignments'])
            : [];
        return [
            'id' => (int)($row['id'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'email' => isset($row['email']) ? (string)$row['email'] : null,
            'full_name' => (string)($row['full_name'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0) === 1,
            'member_id' => isset($row['member_id']) ? (int)$row['member_id'] : null,
            'assignments' => $assignments,
            'profile_image' => [
                'present' => $imagePath !== '',
                'version' => $imagePath === '' ? null : hash('sha256', $imagePath),
                'url' => null,
            ],
            'profile_version' => self::profileVersion($row),
            'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : null,
            'last_login' => isset($row['last_login']) ? (string)$row['last_login'] : null,
        ];
    }

    /** @param array<string,mixed> $input @param array<int,string> $allowed */
    private static function assertAllowedInput(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new ProfileDomainException(
                    'PROFILE_FIELD_NOT_ALLOWED',
                    'This profile field cannot be changed.'
                );
            }
        }
    }

    private static function unicodeLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? PHP_INT_MAX : $count;
    }
}
