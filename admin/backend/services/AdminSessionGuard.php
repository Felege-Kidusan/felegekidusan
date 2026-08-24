<?php
/**
 * Periodic database revalidation for privileged browser sessions.
 *
 * The controller/bootstrap remains responsible for HTTP behavior. This class
 * owns only session policy and current-account reconciliation.
 */
namespace App\Services;

final class AdminSessionGuard
{
    public const REVALIDATE_INTERVAL_SECONDS = 300;
    public const ABSOLUTE_SESSION_SECONDS = 28800;

    private \PDO $database;

    public function __construct(\PDO $database)
    {
        $this->database = $database;
    }

    /**
     * @param array<string,mixed> $session
     * @return array{valid:bool,reason:string}
     */
    public function revalidate(array &$session, ?int $now = null): array
    {
        $now = $now ?? time();
        $userId = (int)($session['admin_id'] ?? 0);
        if ($userId <= 0 || empty($session['admin_logged_in'])) {
            return ['valid' => false, 'reason' => 'missing_identity'];
        }

        $startedAt = (int)($session['AUTH_STARTED_AT'] ?? 0);
        if ($startedAt <= 0) {
            $startedAt = $now;
            $session['AUTH_STARTED_AT'] = $startedAt;
        }
        if ($startedAt > $now || ($now - $startedAt) > self::ABSOLUTE_SESSION_SECONDS) {
            return ['valid' => false, 'reason' => 'absolute_timeout'];
        }

        $lastCheck = (int)($session['AUTH_REVALIDATED_AT'] ?? 0);
        if ($lastCheck > 0 && $lastCheck <= $now
            && ($now - $lastCheck) < self::REVALIDATE_INTERVAL_SECONDS) {
            return ['valid' => true, 'reason' => 'cached'];
        }

        $statement = $this->database->prepare(
            'SELECT id, username, full_name, role, password_hash, is_active
             FROM users WHERE id=? LIMIT 1'
        );
        $statement->execute([$userId]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$user || (int)$user['is_active'] !== 1) {
            return ['valid' => false, 'reason' => 'account_disabled'];
        }

        $passwordVersion = hash('sha256', (string)$user['password_hash']);
        $knownPasswordVersion = (string)($session['AUTH_PASSWORD_VERSION'] ?? '');
        if ($knownPasswordVersion !== ''
            && !hash_equals($knownPasswordVersion, $passwordVersion)) {
            return ['valid' => false, 'reason' => 'credentials_changed'];
        }

        // Reconcile authorization claims rather than trusting stale session
        // values for the remainder of the login.
        $session['admin_username'] = (string)$user['username'];
        $session['admin_full_name'] = (string)($user['full_name'] ?? '');
        $session['admin_role'] = (string)$user['role'];
        $session['AUTH_PASSWORD_VERSION'] = $passwordVersion;
        $session['AUTH_REVALIDATED_AT'] = $now;

        return ['valid' => true, 'reason' => 'database'];
    }
}
