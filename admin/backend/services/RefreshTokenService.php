<?php
/**
 * Persistent one-time refresh-token sessions.
 *
 * The signed token format remains in API auth.php. This service owns session
 * persistence, atomic rotation, replay detection, account revalidation, legacy
 * one-time exchange, and family revocation.
 */
namespace App\Services;

final class RefreshTokenService
{
    private \mysqli $database;
    /** @var callable */
    private $tokenIssuer;
    private int $refreshTtl;

    public function __construct(\mysqli $database, callable $tokenIssuer, int $refreshTtl)
    {
        $this->database = $database;
        $this->tokenIssuer = $tokenIssuer;
        $this->refreshTtl = max(300, min($refreshTtl, 31536000));
    }

    /** @param array{id:mixed,username:mixed,role:mixed,full_name:mixed} $user */
    public function issue(array $user, string $clientIp, string $userAgent): string
    {
        $familyId = bin2hex(random_bytes(32));
        $issued = $this->issueWithinTransaction($user, $familyId, $clientIp, $userAgent);
        return $issued['token'];
    }

    /**
     * @param array<string,mixed> $payload Verified signed refresh payload
     * @return array{state:string,token?:string,user?:array<string,mixed>}
     */
    public function rotate(string $presentedToken, array $payload, string $clientIp, string $userAgent): array
    {
        $sessionId = (string)($payload['jti'] ?? '');
        $familyId = (string)($payload['fid'] ?? '');
        if ($this->isIdentifier($sessionId) && $this->isIdentifier($familyId)) {
            return $this->rotateTracked($presentedToken, $payload, $clientIp, $userAgent);
        }

        // Compatibility adapter: pre-rotation tokens had no jti/fid. They may
        // enter a tracked family exactly once.
        return $this->exchangeLegacy($presentedToken, $payload, $clientIp, $userAgent);
    }

    /** @param array<string,mixed> $payload */
    public function revokePresented(string $presentedToken, array $payload): void
    {
        $tokenHash = hash('sha256', $presentedToken);
        $sessionId = (string)($payload['jti'] ?? '');
        try {
            $this->database->begin_transaction();
            if ($this->isIdentifier($sessionId)) {
                $statement = $this->database->prepare(
                    'SELECT family_id FROM api_refresh_sessions WHERE session_id=? AND token_hash=? LIMIT 1 FOR UPDATE'
                );
                $statement->bind_param('ss', $sessionId, $tokenHash);
                $statement->execute();
                $row = $statement->get_result()->fetch_assoc();
                $statement->close();
                if ($row) {
                    $this->revokeFamily((string)$row['family_id']);
                }
            } else {
                $statement = $this->database->prepare(
                    'SELECT family_id FROM api_refresh_legacy_exchanges WHERE token_hash=? LIMIT 1 FOR UPDATE'
                );
                $statement->bind_param('s', $tokenHash);
                $statement->execute();
                $row = $statement->get_result()->fetch_assoc();
                $statement->close();
                if ($row) {
                    $this->revokeFamily((string)$row['family_id']);
                }
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            try {
                $this->database->rollback();
            } catch (\Throwable $ignored) {
            }
        }
    }

    /** @return array{state:string,token?:string,user?:array<string,mixed>} */
    private function rotateTracked(
        string $presentedToken,
        array $payload,
        string $clientIp,
        string $userAgent
    ): array {
        $sessionId = (string)$payload['jti'];
        $claimedFamily = (string)$payload['fid'];
        $tokenHash = hash('sha256', $presentedToken);

        try {
            $this->database->begin_transaction();
            $statement = $this->database->prepare(
                'SELECT session_id, family_id, user_id, token_hash, consumed_at, revoked_at,
                        expires_at
                 FROM api_refresh_sessions WHERE session_id=? LIMIT 1 FOR UPDATE'
            );
            $statement->bind_param('s', $sessionId);
            $statement->execute();
            $session = $statement->get_result()->fetch_assoc();
            $statement->close();

            if (!$session
                || !hash_equals((string)$session['token_hash'], $tokenHash)
                || !hash_equals((string)$session['family_id'], $claimedFamily)
                || (int)$session['user_id'] !== (int)($payload['uid'] ?? 0)) {
                $this->database->rollback();
                return ['state' => 'invalid'];
            }

            if ($session['consumed_at'] !== null || $session['revoked_at'] !== null) {
                $this->revokeFamily((string)$session['family_id']);
                $this->database->commit();
                return ['state' => 'reused'];
            }
            if (strtotime((string)$session['expires_at']) <= time()) {
                $this->revokeFamily((string)$session['family_id']);
                $this->database->commit();
                return ['state' => 'invalid'];
            }

            $user = $this->findActiveUserForUpdate((int)$session['user_id']);
            if (!$user) {
                $this->revokeFamily((string)$session['family_id']);
                $this->database->commit();
                return ['state' => 'invalid'];
            }

            $issued = $this->issueWithinTransaction(
                $user,
                (string)$session['family_id'],
                $clientIp,
                $userAgent
            );
            $statement = $this->database->prepare(
                'UPDATE api_refresh_sessions
                 SET consumed_at=CURRENT_TIMESTAMP, last_used_at=CURRENT_TIMESTAMP, replaced_by=?
                 WHERE session_id=? AND consumed_at IS NULL AND revoked_at IS NULL'
            );
            $statement->bind_param('ss', $issued['session_id'], $sessionId);
            $statement->execute();
            if ($statement->affected_rows !== 1) {
                $statement->close();
                $this->database->rollback();
                return ['state' => 'invalid'];
            }
            $statement->close();
            $this->database->commit();
            $this->cleanupExpiredSessions();

            return ['state' => 'rotated', 'token' => $issued['token'], 'user' => $user];
        } catch (\Throwable $error) {
            try {
                $this->database->rollback();
            } catch (\Throwable $ignored) {
            }
            return ['state' => 'unavailable'];
        }
    }

    /** @return array{state:string,token?:string,user?:array<string,mixed>} */
    private function exchangeLegacy(
        string $presentedToken,
        array $payload,
        string $clientIp,
        string $userAgent
    ): array {
        $userId = (int)($payload['uid'] ?? 0);
        if ($userId <= 0) {
            return ['state' => 'invalid'];
        }
        $tokenHash = hash('sha256', $presentedToken);
        $familyId = bin2hex(random_bytes(32));

        try {
            $this->database->begin_transaction();
            $statement = $this->database->prepare(
                'INSERT IGNORE INTO api_refresh_legacy_exchanges (token_hash, user_id, family_id)
                 VALUES (?, ?, ?)'
            );
            $statement->bind_param('sis', $tokenHash, $userId, $familyId);
            $statement->execute();
            $inserted = $statement->affected_rows === 1;
            $statement->close();

            if (!$inserted) {
                $statement = $this->database->prepare(
                    'SELECT family_id FROM api_refresh_legacy_exchanges WHERE token_hash=? LIMIT 1 FOR UPDATE'
                );
                $statement->bind_param('s', $tokenHash);
                $statement->execute();
                $exchange = $statement->get_result()->fetch_assoc();
                $statement->close();
                if ($exchange) {
                    $this->revokeFamily((string)$exchange['family_id']);
                }
                $this->database->commit();
                return ['state' => 'reused'];
            }

            $user = $this->findActiveUserForUpdate($userId);
            if (!$user) {
                $this->database->rollback();
                return ['state' => 'invalid'];
            }
            $issued = $this->issueWithinTransaction($user, $familyId, $clientIp, $userAgent);
            $this->database->commit();
            return ['state' => 'rotated', 'token' => $issued['token'], 'user' => $user];
        } catch (\Throwable $error) {
            try {
                $this->database->rollback();
            } catch (\Throwable $ignored) {
            }
            return ['state' => 'unavailable'];
        }
    }

    /**
     * Caller owns transaction boundaries when applicable.
     *
     * @param array{id:mixed,username:mixed,role:mixed,full_name:mixed} $user
     * @return array{token:string,session_id:string}
     */
    private function issueWithinTransaction(
        array $user,
        string $familyId,
        string $clientIp,
        string $userAgent
    ): array {
        $sessionId = bin2hex(random_bytes(32));
        $expiresAt = time() + $this->refreshTtl;
        $token = ($this->tokenIssuer)(
            (int)$user['id'],
            (string)$user['username'],
            (string)$user['role'],
            (string)($user['full_name'] ?? ''),
            $sessionId,
            $familyId,
            $expiresAt
        );
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Refresh token issuer failed.');
        }

        $tokenHash = hash('sha256', $token);
        $safeIp = substr($clientIp, 0, 45);
        $agentHash = hash('sha256', $userAgent);
        $statement = $this->database->prepare(
            'INSERT INTO api_refresh_sessions
                (session_id, family_id, user_id, token_hash, expires_at, created_ip, user_agent_hash)
             VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?)'
        );
        $userId = (int)$user['id'];
        $statement->bind_param(
            'ssisiss',
            $sessionId,
            $familyId,
            $userId,
            $tokenHash,
            $expiresAt,
            $safeIp,
            $agentHash
        );
        $statement->execute();
        $statement->close();

        return ['token' => $token, 'session_id' => $sessionId];
    }

    /** @return array{id:int,username:string,full_name:string,role:string}|null */
    private function findActiveUserForUpdate(int $userId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, username, full_name, role FROM users
             WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE'
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$user) {
            return null;
        }
        return [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'full_name' => (string)($user['full_name'] ?? ''),
            'role' => (string)$user['role'],
        ];
    }

    private function revokeFamily(string $familyId): void
    {
        $statement = $this->database->prepare(
            'UPDATE api_refresh_sessions SET revoked_at=COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE family_id=? AND revoked_at IS NULL'
        );
        $statement->bind_param('s', $familyId);
        $statement->execute();
        $statement->close();
    }

    private function cleanupExpiredSessions(): void
    {
        if (random_int(1, 1000) !== 1) {
            return;
        }
        try {
            $this->database->query(
                'DELETE FROM api_refresh_sessions
                 WHERE expires_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY) LIMIT 1000'
            );
            $this->database->query(
                'DELETE FROM api_refresh_legacy_exchanges
                 WHERE exchanged_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 120 DAY) LIMIT 1000'
            );
        } catch (\Throwable $error) {
            // Cleanup is bounded and never changes the current auth decision.
        }
    }

    private function isIdentifier(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }
}
