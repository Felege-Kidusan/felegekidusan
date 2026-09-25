<?php
/**
 * Shared password-change and administrator-reset transaction primitive.
 *
 * Password mutation and revocation of every refresh session for the target
 * account are one transaction. Access-token lifetime is not changed here.
 */
namespace App\Services;

require_once __DIR__ . '/ProfileService.php';
require_once __DIR__ . '/PasswordPolicy.php';

final class CredentialDomainException extends \DomainException
{
    private string $reason;
    /** @var array<int,string> */
    private array $policyErrors;

    /** @param array<int,string> $policyErrors */
    public function __construct(string $reason, string $message, array $policyErrors = [])
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->policyErrors = array_values($policyErrors);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<int,string> */
    public function policyErrors(): array
    {
        return $this->policyErrors;
    }
}

final class CredentialPersistenceException extends \RuntimeException
{
}

final class CredentialMutationResult
{
    /** @var array<string,mixed> */
    private array $auditContext;
    private string $passwordVersion;

    /** @param array<string,mixed> $auditContext */
    public function __construct(array $auditContext, string $passwordVersion)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $passwordVersion)) {
            throw new \InvalidArgumentException('Invalid password session version.');
        }
        $this->auditContext = $auditContext;
        $this->passwordVersion = $passwordVersion;
    }

    /** Safe audit metadata; never contains a password, hash, token, or session id. */
    public function auditContext(): array
    {
        return $this->auditContext;
    }

    /** Internal web-session marker. Controllers must never return or log it. */
    public function passwordVersion(): string
    {
        return $this->passwordVersion;
    }
}

interface CredentialRepository
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;

    /** @return array{id:int,password_hash:string}|null */
    public function lockCredential(int $userId): ?array;
    public function updatePasswordHash(int $userId, string $passwordHash): void;
    public function revokeAllRefreshSessions(int $userId): void;
}

final class MysqliCredentialRepository implements CredentialRepository
{
    private \mysqli $database;

    public function __construct(\mysqli $database)
    {
        $this->database = $database;
    }

    public function begin(): void
    {
        if (!$this->database->begin_transaction()) {
            throw new CredentialPersistenceException('Could not begin credential transaction.');
        }
    }

    public function commit(): void
    {
        if (!$this->database->commit()) {
            throw new CredentialPersistenceException('Could not commit credential transaction.');
        }
    }

    public function rollback(): void
    {
        try {
            $this->database->rollback();
        } catch (\Throwable $ignored) {
        }
    }

    public function lockCredential(int $userId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, password_hash FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$statement) {
            throw new CredentialPersistenceException('Could not prepare credential lookup.');
        }
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            throw new CredentialPersistenceException('Could not load credential.');
        }
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$row) {
            return null;
        }
        return ['id' => (int)$row['id'], 'password_hash' => (string)$row['password_hash']];
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->database->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        if (!$statement) {
            throw new CredentialPersistenceException('Could not prepare credential update.');
        }
        $statement->bind_param('si', $passwordHash, $userId);
        $ok = $statement->execute();
        $affected = (int)$statement->affected_rows;
        $statement->close();
        if (!$ok || $affected !== 1) {
            throw new CredentialPersistenceException('Could not update credential.');
        }
    }

    public function revokeAllRefreshSessions(int $userId): void
    {
        $statement = $this->database->prepare(
            'UPDATE api_refresh_sessions
             SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE user_id = ?'
        );
        if (!$statement) {
            throw new CredentialPersistenceException('Could not prepare refresh-session revocation.');
        }
        $statement->bind_param('i', $userId);
        $ok = $statement->execute();
        $statement->close();
        if (!$ok) {
            throw new CredentialPersistenceException('Could not revoke refresh sessions.');
        }
    }
}

final class AccountCredentialService
{
    private CredentialRepository $credentials;

    public function __construct(CredentialRepository $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * Self-service password change. Only result->auditContext() is passed to
     * audit; the separate passwordVersion() marker is used solely to preserve
     * the caller's current hardened PHP session.
     *
     * @return CredentialMutationResult
     */
    public function changeOwnPassword(
        AuthenticatedProfileIdentity $identity,
        string $currentPassword,
        string $newPassword,
        string $confirmation
    ): CredentialMutationResult {
        self::validateSubmittedPassword($newPassword, $confirmation);
        if ($currentPassword === '' || strlen($currentPassword) > 4096) {
            throw new CredentialDomainException(
                'CURRENT_PASSWORD_INCORRECT',
                'Current password is incorrect.'
            );
        }

        $targetUserId = $identity->userId();
        return $this->mutate($targetUserId, function (array $locked) use ($currentPassword, $newPassword): void {
            if (!password_verify($currentPassword, $locked['password_hash'])) {
                throw new CredentialDomainException(
                    'CURRENT_PASSWORD_INCORRECT',
                    'Current password is incorrect.'
                );
            }
            if (hash_equals($currentPassword, $newPassword)) {
                throw new CredentialDomainException(
                    'NEW_PASSWORD_MUST_DIFFER',
                    'New password must be different from current password.'
                );
            }
        }, [
            'audit_action' => 'Profile Password Changed',
            'actor_user_id' => $targetUserId,
            'target_user_id' => $targetUserId,
        ], $newPassword);
    }

    /**
     * Administrator reset is intentionally a separate entry point and never
     * asks for, receives, or verifies the target account's current password.
     * Authorization must already have produced the trusted administrator value.
     *
     * @return CredentialMutationResult
     */
    public function resetPasswordByAdministrator(
        AuthorizedAdministratorIdentity $administrator,
        int $targetUserId,
        string $newPassword,
        string $confirmation
    ): CredentialMutationResult {
        return $this->resetPasswordByAdministratorWithAccountMutation(
            $administrator,
            $targetUserId,
            $newPassword,
            $confirmation,
            static function (): void {
            }
        );
    }

    /**
     * Administrator reset plus a caller-supplied account mutation under one
     * repository transaction. The callback must use the same database
     * connection as this service's repository and must throw on any failure.
     * It runs only after the target row is locked and before the password hash
     * and refresh-session revocations are written. No nested transaction is
     * started by the callback.
     *
     * @param callable():void $accountMutation
     */
    public function resetPasswordByAdministratorWithAccountMutation(
        AuthorizedAdministratorIdentity $administrator,
        int $targetUserId,
        string $newPassword,
        string $confirmation,
        callable $accountMutation
    ): CredentialMutationResult {
        if ($targetUserId <= 0) {
            throw new CredentialDomainException('USER_NOT_FOUND', 'User not found.');
        }
        self::validateSubmittedPassword($newPassword, $confirmation);
        return $this->mutate($targetUserId, static function (array $locked): void {
            // Deliberately no current-password verification for an authorized reset.
        }, [
            'audit_action' => 'Profile Password Reset By Administrator',
            'actor_user_id' => $administrator->actorUserId(),
            'target_user_id' => $targetUserId,
        ], $newPassword, $accountMutation);
    }

    /**
     * @param callable(array{id:int,password_hash:string}):void $authorize
     * @param array<string,mixed> $safeResult
     * @param callable():void|null $accountMutation
     * @return CredentialMutationResult
     */
    private function mutate(
        int $targetUserId,
        callable $authorize,
        array $safeResult,
        string $newPassword,
        ?callable $accountMutation = null
    ): CredentialMutationResult {
        $this->credentials->begin();
        try {
            $locked = $this->credentials->lockCredential($targetUserId);
            if ($locked === null) {
                throw new CredentialDomainException('USER_NOT_FOUND', 'User not found.');
            }
            $authorize($locked);
            if ($accountMutation !== null) {
                $accountMutation();
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new CredentialPersistenceException('Could not hash credential.');
            }
            $this->credentials->updatePasswordHash($targetUserId, $passwordHash);
            // This must succeed before commit. Any failure rolls the hash and
            // the caller's account mutation back together.
            $this->credentials->revokeAllRefreshSessions($targetUserId);
            $passwordVersion = hash('sha256', $passwordHash);
            $result = new CredentialMutationResult($safeResult, $passwordVersion);
            $this->credentials->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->credentials->rollback();
            throw $error;
        }
    }

    private static function validateSubmittedPassword(string $password, string $confirmation): void
    {
        if (!hash_equals($password, $confirmation)) {
            throw new CredentialDomainException(
                'PASSWORD_CONFIRMATION_MISMATCH',
                'New password and confirmation do not match.'
            );
        }
        $errors = PasswordPolicy::errors($password);
        if ($errors !== []) {
            throw new CredentialDomainException(
                'PASSWORD_POLICY_FAILED',
                'New password does not meet the password policy.',
                $errors
            );
        }
    }
}
