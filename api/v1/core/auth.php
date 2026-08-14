<?php
/**
 * School API v1 — JWT Authentication
 * Token-based auth for mobile app and API clients
 */

define('API_TOKEN_SECRET', defined('JWT_SECRET') ? JWT_SECRET : EXPORT_PREFIX . '_api_v1_' . DB_NAME . '_' . md5(DB_PASS));
define('API_TOKEN_EXPIRY', 86400 * 30);       // 30 days
define('API_REFRESH_EXPIRY', 86400 * 90);     // 90 days for refresh

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
        'typ' => 'access'
    ];
    $b64 = base64_encode(json_encode($payload));
    return $b64 . '.' . hash_hmac('sha256', $b64, API_TOKEN_SECRET);
}

/**
 * Create a refresh token (longer expiry, type=refresh)
 */
function createRefreshToken($userId, $username, $role, $fullName) {
    $payload = [
        'uid' => (int)$userId,
        'usr' => $username,
        'rol' => $role,
        'nam' => $fullName,
        'iat' => time(),
        'exp' => time() + API_REFRESH_EXPIRY,
        'typ' => 'refresh'
    ];
    $b64 = base64_encode(json_encode($payload));
    return $b64 . '.' . hash_hmac('sha256', $b64, API_TOKEN_SECRET);
}

/**
 * Verify a token and return payload, or null if invalid
 */
function verifyToken($token) {
    if (!$token || strpos($token, '.') === false) return null;
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    if (!hash_equals(hash_hmac('sha256', $parts[0], API_TOKEN_SECRET), $parts[1])) return null;
    $payload = json_decode(base64_decode($parts[0]), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return null;
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
    
    // Extract Bearer token
    if (strpos($header, 'Bearer ') === 0) {
        return substr($header, 7);
    }
    
    // Fallback: query param or POST field
    return $_GET['token'] ?? $_POST['token'] ?? '';
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
