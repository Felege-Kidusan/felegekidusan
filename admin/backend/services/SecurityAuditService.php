<?php
/**
 * Central writer for security-relevant administrative audit events.
 */

namespace App\Services;

use mysqli;
use PDO;
use Throwable;

final class SecurityAuditService
{
    public static function record(
        PDO|mysqli $connection,
        string $action,
        array $details = [],
        ?string $entityType = null,
        ?int $entityId = null
    ): bool {
        try {
            $userId = (int)($_SESSION['admin_id'] ?? 0);
            $auditUserId = $userId > 0 ? $userId : null;
            $username = substr((string)($_SESSION['admin_username'] ?? 'unknown'), 0, 100);
            $action = substr($action, 0, 100);
            $entityType = $entityType === null ? null : substr($entityType, 0, 50);
            $entityId = $entityId !== null && $entityId > 0 ? $entityId : null;
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
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
                    ':user_id' => $auditUserId,
                    ':username' => $username,
                    ':action' => $action,
                    ':details' => $detailsJson,
                    ':entity_type' => $entityType,
                    ':entity_id' => $entityId,
                    ':ip_address' => $ip,
                    ':user_agent' => $userAgent,
                ]);
            }

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
                $auditUserId,
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
