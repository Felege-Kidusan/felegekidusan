<?php
/**
 * Authorization and step-up authentication policy for the error monitor.
 * HTTP redirects and HTML remain in monitor/index.php; this service owns the
 * account checks, short-lived authorization decision, and throttling policy.
 */
namespace App\Services;

require_once __DIR__ . '/SecurityRateLimiter.php';

final class MonitorAccessService
{
    public const STEP_UP_TTL_SECONDS = 900;
    private const MAX_ATTEMPTS = 5;
    private const ATTEMPT_WINDOW_SECONDS = 300;
    private const RATE_ACTION_IP = 'monitor-step-up-ip';
    private const RATE_ACTION_ACCOUNT = 'monitor-step-up-account';

    private \PDO $database;
    private SecurityRateLimiter $rateLimiter;

    public function __construct(\PDO $database, SecurityRateLimiter $rateLimiter)
    {
        $this->database = $database;
        $this->rateLimiter = $rateLimiter;
    }

    /** @return array{id:int,username:string,full_name:string,password_hash:string}|null */
    public function findActiveSuperAdmin(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $statement = $this->database->prepare(
            "SELECT id, username, full_name, password_hash
             FROM users
             WHERE id = ? AND role = 'super_admin' AND is_active = 1
             LIMIT 1"
        );
        $statement->execute([$userId]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        return [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'full_name' => (string)($user['full_name'] ?? ''),
            'password_hash' => (string)$user['password_hash'],
        ];
    }

    public function hasValidStepUp(array $session, int $userId, ?int $now = null): bool
    {
        $now = $now ?? time();
        $authenticatedAt = (int)($session['monitor_authenticated_at'] ?? 0);
        return (int)($session['monitor_admin_id'] ?? 0) === $userId
            && $authenticatedAt > 0
            && $authenticatedAt <= $now
            && ($now - $authenticatedAt) <= self::STEP_UP_TTL_SECONDS;
    }

    /**
     * @param array{id:int,password_hash:string} $user
     * @return array{success:bool,limited:bool,retry_after:int}
     */
    public function verifyStepUp(array $user, string $password, string $clientAddress): array
    {
        $accountSubject = (string)$user['id'];
        $ipLimit = $this->rateLimiter->consume(
            self::RATE_ACTION_IP,
            $clientAddress,
            self::MAX_ATTEMPTS,
            self::ATTEMPT_WINDOW_SECONDS
        );
        $accountLimit = $this->rateLimiter->consume(
            self::RATE_ACTION_ACCOUNT,
            $accountSubject,
            self::MAX_ATTEMPTS,
            self::ATTEMPT_WINDOW_SECONDS
        );

        if (!$ipLimit['allowed'] || !$accountLimit['allowed']) {
            return [
                'success' => false,
                'limited' => true,
                'retry_after' => max($ipLimit['retry_after'], $accountLimit['retry_after']),
            ];
        }

        $success = $password !== '' && strlen($password) <= 4096
            && password_verify($password, $user['password_hash']);
        if ($success) {
            $this->rateLimiter->clear(self::RATE_ACTION_IP, $clientAddress);
            $this->rateLimiter->clear(self::RATE_ACTION_ACCOUNT, $accountSubject);
        }

        return ['success' => $success, 'limited' => false, 'retry_after' => 0];
    }
}
