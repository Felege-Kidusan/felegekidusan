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

    /** Roles allowed to originate a role-impersonation session. */
    private const PRIVILEGED_ROLES = ['super_admin', 'school_admin'];

    /** Known application roles — an assumed role must be one of these. */
    private const KNOWN_ROLES = [
        'super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept',
        'finance_dept', 'material_dept', 'mezmur_dept', 'teacher', 'attendance_taker',
    ];

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

        // IMPERSONATION-AWARE RECONCILIATION.
        // A role-impersonation session keeps the SAME underlying account
        // (admin_id) while admin_role is swapped and the original role is
        // stashed in original_admin_role. The periodic reconciliation above
        // must therefore refresh the BASE account's identity without
        // silently reverting the assumed role — otherwise every
        // impersonation session was downgraded back to the real role after
        // one revalidation interval.
        $originalRole = (string)($session['original_admin_role'] ?? '');
        if ($originalRole !== '') {
            // Tamper defense: only privileged roles may originate an
            // impersonation, and the assumed role must stay within the
            // known role set. Any other combination means the session was
            // manipulated — invalidate it.
            $assumedRole = (string)($session['admin_role'] ?? '');
            if (!in_array($originalRole, self::PRIVILEGED_ROLES, true)
                || !in_array($assumedRole, self::KNOWN_ROLES, true)) {
                return ['valid' => false, 'reason' => 'impersonation_tampered'];
            }
            // Privilege revocation: if the base account is no longer
            // privileged, an ongoing impersonation must end.
            if (!in_array((string)$user['role'], self::PRIVILEGED_ROLES, true)) {
                return ['valid' => false, 'reason' => 'impersonation_base_revoked'];
            }

            $session['admin_username'] = (string)$user['username'];
            $session['admin_full_name'] = (string)($user['full_name'] ?? '');
            // Base role refreshed from the database; the assumed role is
            // intentionally preserved.
            $session['original_admin_role'] = (string)$user['role'];
            $session['AUTH_PASSWORD_VERSION'] = $passwordVersion;
            $session['AUTH_REVALIDATED_AT'] = $now;

            return ['valid' => true, 'reason' => 'database_impersonation'];
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
