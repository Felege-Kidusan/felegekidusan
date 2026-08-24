<?php
/**
 * School API v1 — JWT Authentication
 * Token-based auth for mobile app and API clients
 */

define('API_TOKEN_SECRET', defined('JWT_SECRET') ? JWT_SECRET : EXPORT_PREFIX . '_api_v1_' . DB_NAME . '_' . md5(DB_PASS));
define('API_TOKEN_EXPIRY', 900);              // 15-minute access token
define('API_REFRESH_EXPIRY', 86400 * 90);     // 90-day rotating refresh session
define('API_LEGACY_TOKEN_EXPIRY', 86400 * 30);
define('API_ROTATION_CLIENT_BUILD', 16);
define('API_LEGACY_CLIENT_COMPAT_UNTIL', strtotime('2026-09-24 23:59:59'));

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
function createToken($userId, $username, $role, $fullName, $expiry = null) {
    $exp = $expiry ?? API_TOKEN_EXPIRY;
    $payload = [
        'uid' => (int)$userId,
        'usr' => $username,
        'rol' => $role,
        'nam' => $fullName,
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
    $sessionId,
    $familyId,
    $expiresAt
) {
    $payload = [
        'uid' => (int)$userId,
        'usr' => $username,
        'rol' => $role,
        'nam' => $fullName,
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
 * Verify a token and return payload, or null if invalid
 */
function verifyToken($token) {
    if (!is_string($token) || $token === '' || strlen($token) > 8192 || strpos($token, '.') === false) return null;
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) return null;
    if (!hash_equals(hash_hmac('sha256', $parts[0], API_TOKEN_SECRET), $parts[1])) return null;
    $decoded = base64_decode($parts[0], true);
    if ($decoded === false) return null;
    $payload = json_decode($decoded, true);
    $now = time();
    if (!is_array($payload)
        || !isset($payload['uid'], $payload['exp'], $payload['iat'], $payload['typ'])
        || (int)$payload['uid'] <= 0
        || (int)$payload['exp'] < $now
        || (int)$payload['iat'] > ($now + 60)) return null;
    return $payload;
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
    return $payload;
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
