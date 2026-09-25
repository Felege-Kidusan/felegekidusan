<?php
/**
 * Central writer for security-relevant administrative audit events.
 */

namespace App\Services;

use mysqli;
use PDO;
use Throwable;

final class SecurityAuditActor
{
    private int $userId;
    private string $username;
    private string $surface;
    private string $ipAddress;
    private string $userAgent;
    private string $requestMethod;
    private string $requestRoute;

    private function __construct(
        int $userId,
        string $username,
        string $surface,
        string $ipAddress,
        string $userAgent,
        string $requestMethod,
        string $requestRoute
    ) {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Trusted audit actor id is required.');
        }
        if (!preg_match('/^[a-z0-9._-]{2,32}$/D', $surface)) {
            throw new \InvalidArgumentException('Trusted audit surface is invalid.');
        }
        $this->userId = $userId;
        $this->username = substr($username !== '' ? $username : ('user-' . $userId), 0, 100);
        $this->surface = $surface;
        $this->ipAddress = substr($ipAddress, 0, 45);
        $this->userAgent = substr($userAgent, 0, 255);
        $method = strtoupper($requestMethod);
        $this->requestMethod = preg_match('/^[A-Z]{3,10}$/D', $method)
            ? $method
            : 'UNKNOWN';
        $route = (string)(parse_url($requestRoute, PHP_URL_PATH) ?: '');
        $this->requestRoute = substr($route !== '' ? $route : '/', 0, 255);
    }

    /**
     * Controllers call this only after JWT/session authentication. Request JSON
     * is never an actor source; network metadata comes from the server context.
     *
     * @param array<string,mixed> $server
     */
    public static function fromAuthenticatedContext(
        int $userId,
        string $username,
        string $surface,
        array $server
    ): self {
        return new self(
            $userId,
            $username,
            strtolower($surface),
            (string)($server['REMOTE_ADDR'] ?? ''),
            (string)($server['HTTP_USER_AGENT'] ?? ''),
            (string)($server['REQUEST_METHOD'] ?? ''),
            (string)($server['REQUEST_URI'] ?? '')
        );
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function surface(): string
    {
        return $this->surface;
    }

    public function ipAddress(): string
    {
        return $this->ipAddress;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function requestMethod(): string
    {
        return $this->requestMethod;
    }

    public function requestRoute(): string
    {
        return $this->requestRoute;
    }
}

final class SecurityAuditService
{
    /**
     * Existing PHP-session audit entry point retained for compatibility.
     */
    public static function record(
        PDO|mysqli $connection,
        string $action,
        array $details = [],
        ?string $entityType = null,
        ?int $entityId = null
    ): bool {
        if (!self::detailsAreSafe($details)) {
            error_log('Security audit event rejected unsafe detail keys.');
            return false;
        }
        $userId = (int)($_SESSION['admin_id'] ?? 0);
        return self::write(
            $connection,
            $userId > 0 ? $userId : null,
            substr((string)($_SESSION['admin_username'] ?? 'unknown'), 0, 100),
            $action,
            $details,
            $entityType,
            $entityId,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
        );
    }

    /**
     * Explicit trusted-actor entry point for JWT and session controllers.
     *
     * @param array<string,mixed> $details
     */
    public static function recordTrusted(
        PDO|mysqli $connection,
        SecurityAuditActor $actor,
        string $action,
        array $details = [],
        ?string $entityType = null,
        ?int $targetUserId = null
    ): bool {
        if (!self::detailsAreSafe($details)) {
            error_log('Security audit event rejected unsafe detail keys.');
            return false;
        }
        $safeDetails = array_merge($details, [
            'actor_user_id' => $actor->userId(),
            'target_user_id' => $targetUserId !== null && $targetUserId > 0
                ? $targetUserId
                : null,
            'surface' => $actor->surface(),
            'request_method' => $actor->requestMethod(),
            'request_route' => $actor->requestRoute(),
        ]);
        return self::write(
            $connection,
            $actor->userId(),
            $actor->username(),
            $action,
            $safeDetails,
            $entityType,
            $targetUserId,
            $actor->ipAddress(),
            $actor->userAgent()
        );
    }

    /** @param array<string,mixed> $details */
    private static function detailsAreSafe(array $details): bool
    {
        $forbidden = [
            'password', 'password_hash', 'current_password', 'new_password',
            'confirm_password', 'confirmation', 'token', 'access_token',
            'refresh_token', 'session_token', 'session_id', 'image_bytes',
            'physical_path', 'filesystem_path', 'profile_image_path',
            'logical_reference', 'private_storage_key',
        ];
        foreach ($details as $key => $value) {
            $normalized = strtolower((string)$key);
            if (in_array($normalized, $forbidden, true)
                || preg_match(
                    '/(?:password|token|(?:^|_)hash(?:_|$)|(?:^|_)bytes(?:_|$)|session_id|(?:^|_)(?:path|reference|storage_key)(?:_|$))/',
                    $normalized
                )) {
                return false;
            }
            if (is_array($value) && !self::detailsAreSafe($value)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $details */
    private static function write(
        PDO|mysqli $connection,
        ?int $userId,
        string $username,
        string $action,
        array $details,
        ?string $entityType,
        ?int $entityId,
        string $ip,
        string $userAgent
    ): bool {
        try {
            $action = substr($action, 0, 100);
            $entityType = $entityType === null ? null : substr($entityType, 0, 50);
            $entityId = $entityId !== null && $entityId > 0 ? $entityId : null;
            $detailsJson = json_encode(
                $details,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($detailsJson === false) {
                return false;
            }

            if ($connection instanceof PDO) {
                $statement = $connection->prepare(
                    'INSERT INTO activity_logs
                     (user_id, username, action, details, entity_type, entity_id, ip_address, user_agent, created_at)
                     VALUES (:user_id, :username, :action, :details, :entity_type,
                             :entity_id, :ip_address, :user_agent, CURRENT_TIMESTAMP)'
                );
                return $statement->execute([
                    ':user_id' => $userId,
                    ':username' => substr($username, 0, 100),
                    ':action' => $action,
                    ':details' => $detailsJson,
                    ':entity_type' => $entityType,
                    ':entity_id' => $entityId,
                    ':ip_address' => substr($ip, 0, 45),
                    ':user_agent' => substr($userAgent, 0, 255),
                ]);
            }

            $username = substr($username, 0, 100);
            $ip = substr($ip, 0, 45);
            $userAgent = substr($userAgent, 0, 255);
            $statement = $connection->prepare(
                'INSERT INTO activity_logs
                 (user_id, username, action, details, entity_type, entity_id, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            if (!$statement) {
                return false;
            }
            $statement->bind_param(
                'issssiss',
                $userId,
                $username,
                $action,
                $detailsJson,
                $entityType,
                $entityId,
                $ip,
                $userAgent
            );
            $ok = $statement->execute();
            $statement->close();
            return $ok;
        } catch (Throwable $error) {
            error_log('Security audit event could not be recorded.');
            return false;
        }
    }
}
