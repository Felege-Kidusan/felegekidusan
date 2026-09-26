<?php
/**
 * School API v1 — JWT Authentication
 * Token-based auth for mobile app and API clients
 */

require_once __DIR__ . '/../../../admin/backend/services/ProfileService.php';

define('API_TOKEN_SECRET', defined('JWT_SECRET') ? JWT_SECRET : EXPORT_PREFIX . '_api_v1_' . DB_NAME . '_' . md5(DB_PASS));
define('API_TOKEN_EXPIRY', 900);              // 15-minute access token
define('API_REFRESH_EXPIRY', 86400 * 90);     // 90-day rotating refresh session
define('API_LEGACY_TOKEN_EXPIRY', 86400 * 30);
define('API_ROTATION_CLIENT_BUILD', 16);
define('API_LEGACY_CLIENT_COMPAT_UNTIL', strtotime('2026-09-24 23:59:59'));

// Authorization-scope revalidation is additive for build 24+, while installed
// build 23 remains on its former token-window behavior during the controlled
// rollout. Deployment overrides the timestamp in the private env file and sets
// it to a past value only after build 24 is the enforced minimum. The build
// header is therefore temporary compatibility metadata, never a permanent
// security boundary.
if (!defined('API_AUTHZ_SCOPE_CLIENT_BUILD')) {
    define('API_AUTHZ_SCOPE_CLIENT_BUILD', 24);
}
if (!defined('API_AUTHZ_LEGACY_COMPAT_UNTIL')) {
    define('API_AUTHZ_LEGACY_COMPAT_UNTIL', PHP_INT_MAX);
}

/**
 * Temporary compatibility adapter for already-installed app builds that can
 * race refresh requests. Build 16+ is single-flight and receives short access
 * tokens immediately; older builds get their former lifetime only until the
 * explicit migration deadline.
 */
function apiAccessTokenExpiryForClient(): int {
    $build = (int)($_SERVER['HTTP_X_APP_BUILD'] ?? 0);
    if ($build >= API_ROTATION_CLIENT_BUILD || time() > API_LEGACY_CLIENT_COMPAT_UNTIL) {
        return API_TOKEN_EXPIRY;
    }
    return API_LEGACY_TOKEN_EXPIRY;
}

/**
 * Create a JWT token
 */
function createToken(
    $userId,
    $username,
    $role,
    $fullName,
    $expiry = null,
    $authorizationVersion = 1
) {
    $exp = $expiry ?? API_TOKEN_EXPIRY;
    $payload = [
        'uid' => (int)$userId,
        'usr' => $username,
        'rol' => $role,
        'nam' => $fullName,
        'av' => max(1, (int)$authorizationVersion),
        'iat' => time(),
        'exp' => time() + $exp,
        'typ' => 'access',
        'jti' => bin2hex(random_bytes(16))
    ];
    $b64 = base64_encode(json_encode($payload));
    return $b64 . '.' . hash_hmac('sha256', $b64, API_TOKEN_SECRET);
}

/**
 * Create a refresh token bound to a persistent one-time session.
 */
function createRefreshToken(
    $userId,
    $username,
    $role,
    $fullName,
    $authorizationVersion,
    $sessionId,
    $familyId,
    $expiresAt
) {
    $payload = [
        'uid' => (int)$userId,
        'usr' => $username,
        'rol' => $role,
        'nam' => $fullName,
        'av' => max(1, (int)$authorizationVersion),
        'iat' => time(),
        'exp' => (int)$expiresAt,
        'typ' => 'refresh',
        'jti' => (string)$sessionId,
        'fid' => (string)$familyId,
    ];
    $b64 = base64_encode(json_encode($payload));
    return $b64 . '.' . hash_hmac('sha256', $b64, API_TOKEN_SECRET);
}

/**
 * Verify signature and required claims while preserving an explicit expiry
 * state for refresh-token responses. Expired signed payloads are never exposed
 * to callers; only the machine-readable state is retained.
 *
 * @return array{state:string,payload?:array<string,mixed>,token_type?:string}
 */
function verifyTokenState($token): array {
    if (!is_string($token) || $token === '' || strlen($token) > 8192 || strpos($token, '.') === false) {
        return ['state' => 'invalid'];
    }
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
        return ['state' => 'invalid'];
    }
    if (!hash_equals(hash_hmac('sha256', $parts[0], API_TOKEN_SECRET), $parts[1])) {
        return ['state' => 'invalid'];
    }
    $decoded = base64_decode($parts[0], true);
    if ($decoded === false) {
        return ['state' => 'invalid'];
    }
    $payload = json_decode($decoded, true);
    $now = time();
    if (!is_array($payload)
        || !isset($payload['uid'], $payload['exp'], $payload['iat'], $payload['typ'])
        || (int)$payload['uid'] <= 0
        || (int)$payload['iat'] > ($now + 60)) {
        return ['state' => 'invalid'];
    }
    if ((int)$payload['exp'] < $now) {
        return ['state' => 'expired', 'token_type' => (string)$payload['typ']];
    }
    return ['state' => 'valid', 'payload' => $payload];
}

/**
 * Verify a token and return payload, or null if invalid/expired.
 */
function verifyToken($token) {
    $result = verifyTokenState($token);
    return ($result['state'] ?? '') === 'valid' ? $result['payload'] : null;
}

/**
 * Extract token from request (Authorization header or query param)
 */
function getTokenFromRequest() {
    $header = '';
    
    // Try multiple ways (Apache strips Authorization header)
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $header = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    
    // Bearer header only — never accept ?token= (it leaks into access logs).
    if (strpos($header, 'Bearer ') === 0) {
        return substr($header, 7);
    }

    return '';
}

/**
 * Whether this request is in the live authorization-scope cohort.
 *
 * Build 24+ is always checked. Older/unknown clients are checked after the
 * deployment-controlled compatibility deadline. Once the minimum build is 24,
 * operations set the deadline to the past so a spoofed old build cannot bypass
 * revalidation.
 */
function apiAuthorizationScopeEnforcedForClient(): bool {
    $build = max(0, (int)($_SERVER['HTTP_X_APP_BUILD'] ?? 0));
    return $build >= API_AUTHZ_SCOPE_CLIENT_BUILD
        || time() > (int)API_AUTHZ_LEGACY_COMPAT_UNTIL;
}

/**
 * Revalidate the signed scope against the authoritative current user row.
 *
 * Do not overwrite token claims and continue the original request when the
 * scope changed. A capable client must refresh/reconcile first; a missing or
 * unavailable authority fails closed with a typed response.
 *
 * @param array<string,mixed> $payload verified access-token payload
 * @return array<string,mixed>
 */
function apiRevalidateAuthorizationScope(array $payload): array {
    if (!apiAuthorizationScopeEnforcedForClient()) {
        return $payload;
    }

    global $conn;
    $statement = null;
    try {
        if (!isset($conn) || !($conn instanceof mysqli)) {
            throw new RuntimeException('API database connection is unavailable.');
        }
        $authVersionAvailable = \App\Services\MysqliProfileRepository::authorizationVersionColumnAvailable($conn);
        $sql = $authVersionAvailable
            ? 'SELECT role, is_active, authorization_version, username, full_name
               FROM users WHERE id=? LIMIT 1'
            : 'SELECT role, is_active, 1 AS authorization_version, username, full_name
               FROM users WHERE id=? LIMIT 1';
        $statement = $conn->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Could not prepare authorization revalidation.');
        }
        $userId = (int)($payload['uid'] ?? 0);
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute authorization revalidation.');
        }
        $result = $statement->get_result();
        $current = $result ? $result->fetch_assoc() : null;
        $statement->close();
        $statement = null;
    } catch (Throwable $error) {
        if ($statement instanceof mysqli_stmt) {
            $statement->close();
        }
        error_log('API authorization scope revalidation unavailable.');
        err(
            'Authorization could not be revalidated. Please try again.',
            503,
            ['code' => 'AUTH_REVALIDATION_UNAVAILABLE']
        );
    }

    if (!$current) {
        err('This account no longer exists. Please sign in again.', 401,
            ['code' => 'ACCOUNT_REMOVED']);
    }
    if ((int)$current['is_active'] !== 1) {
        err('This account is disabled. Contact an administrator.', 401,
            ['code' => 'ACCOUNT_DISABLED']);
    }
    if (!array_key_exists('av', $payload) || (int)$payload['av'] <= 0) {
        err('Authorization scope must be refreshed. Please try again.', 401,
            ['code' => 'AUTH_SCOPE_REFRESH_REQUIRED']);
    }

    $currentRole = (string)$current['role'];
    $currentVersion = max(1, (int)$current['authorization_version']);
    if (!hash_equals($currentRole, (string)($payload['rol'] ?? ''))
        || $currentVersion !== (int)$payload['av']) {
        err('Your access changed. Refresh your session before continuing.', 401,
            ['code' => 'AUTH_SCOPE_CHANGED']);
    }

    // Profile identity claims are display metadata, not authorization. Capable
    // clients receive a non-authorization conflict and rotate through the
    // existing refresh-session mechanism. Legacy clients outside the rollout
    // cohort retain their established token-window behavior above.
    $currentUsername = (string)($current['username'] ?? '');
    $currentFullName = (string)($current['full_name'] ?? '');
    if (!hash_equals($currentUsername, (string)($payload['usr'] ?? ''))
        || !hash_equals($currentFullName, (string)($payload['nam'] ?? ''))) {
        err('Your profile changed. Refresh your session before continuing.', 409, [
            'code' => 'PROFILE_CLAIMS_CHANGED',
            'claims_refresh_required' => true,
        ]);
    }

    return $payload;
}

/**
 * Authenticate the current request — returns user payload or calls err()
 */
function apiRequireAuth() {
    $token = getTokenFromRequest();
    if (!$token) err('Authentication required. Provide Bearer token.', 401);
    
    $payload = verifyToken($token);
    if (!$payload) err('Invalid or expired token. Please login again.', 401);
    
    if (($payload['typ'] ?? 'access') !== 'access') {
        err('Invalid token type. Use access token, not refresh token.', 401);
    }
    if (time() > API_LEGACY_CLIENT_COMPAT_UNTIL
        && ((int)$payload['exp'] - (int)$payload['iat']) > (API_TOKEN_EXPIRY + 60)) {
        err('Access token must be refreshed. Please try again.', 401);
    }
    return apiRevalidateAuthorizationScope($payload);
}

/**
 * Require specific role(s) — call after requireAuth()
 */
function apiRequireRole($auth, $allowedRoles) {
    $allowedRoles = (array)$allowedRoles;
    if (!in_array($auth['rol'], $allowedRoles)) {
        err('Access denied. Required role: ' . implode(' or ', $allowedRoles), 403);
    }
}
