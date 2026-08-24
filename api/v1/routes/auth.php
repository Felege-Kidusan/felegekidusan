<?php
/**
 * School API v1 — Auth Routes
 * POST /auth/login          — Login with username + password, get tokens
 * POST /auth/refresh-token  — Rotate refresh token and get a new token pair
 * POST /auth/logout         — Revoke the presented refresh-token family
 * GET  /auth/verify         — Verify current token is valid
 */

require_once __DIR__ . '/../../../admin/backend/services/RefreshTokenService.php';

$action = $ROUTE['id'] ?? '';
$refreshService = new \App\Services\RefreshTokenService(
    $conn,
    static function ($userId, $username, $role, $fullName, $sessionId, $familyId, $expiresAt) {
        return createRefreshToken(
            $userId, $username, $role, $fullName, $sessionId, $familyId, $expiresAt
        );
    },
    API_REFRESH_EXPIRY
);
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$accessTokenExpiry = apiAccessTokenExpiryForClient();

// ============================================================
// POST /auth/login
// ============================================================
if ($action === 'login' && $method === 'POST') {
    // Rate limit: 10 login attempts per minute per IP
    if (isApiRateLimited('auth_login', 10)) {
        err('Too many login attempts. Please wait a minute.', 429);
    }
    
    $input = getBody();
    $username = trim($input['username'] ?? '');
    $password = (string)($input['password'] ?? '');
    
    if ($username === '' || $password === '') {
        err('Username and password are required.');
    }
    if (strlen($password) > 4096) {
        err('Invalid username or password.', 401);
    }
    
    $stmt = $conn->prepare("SELECT id, username, email, full_name, role, password_hash, is_active FROM users WHERE (username = ? OR email = ?) LIMIT 1");
    if (!$stmt) err('Database error', 500);
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        err('Invalid username or password.', 401);
    }
    
    if ((int)$user['is_active'] !== 1) {
        err('Your account is inactive. Contact an administrator.', 403);
    }
    
    try {
        $refreshToken = $refreshService->issue($user, $clientIp, $userAgent);
    } catch (Throwable $error) {
        error_log('API refresh session creation failed. Apply migration 010.');
        err('Authentication service is temporarily unavailable.', 503);
    }

    $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . (int)$user['id']);
    logApiAction($user['id'], $user['username'], 'API Login', 'REST API v1');
    
    ok([
        'token' => createToken($user['id'], $user['username'], $user['role'], $user['full_name'], $accessTokenExpiry),
        'refresh_token' => $refreshToken,
        'expires_in' => $accessTokenExpiry,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => $user['email'] ?? '',
            'role' => $user['role']
        ]
    ]);
}

// ============================================================
// POST /auth/refresh-token — atomically rotate a one-time refresh session
// ============================================================
if ($action === 'refresh-token' && $method === 'POST') {
    if (isApiRateLimited('auth_refresh', 30)) {
        err('Too many refresh attempts. Please wait a minute.', 429);
    }

    $input = getBody();
    $refreshToken = (string)($input['refresh_token'] ?? '');
    if ($refreshToken === '') {
        err('Refresh token is required.');
    }

    $payload = verifyToken($refreshToken);
    if (!$payload || ($payload['typ'] ?? '') !== 'refresh') {
        err('Invalid or expired refresh token. Please login again.', 401);
    }

    $rotation = $refreshService->rotate($refreshToken, $payload, $clientIp, $userAgent);
    if (($rotation['state'] ?? '') === 'reused') {
        logApiAction((int)($payload['uid'] ?? 0), (string)($payload['usr'] ?? ''),
            'Refresh token reuse blocked', 'Refresh-token family revoked');
        err('Refresh token reuse detected. Please login again.', 401);
    }
    if (($rotation['state'] ?? '') === 'unavailable') {
        error_log('API refresh rotation failed. Apply migration 010.');
        err('Authentication service is temporarily unavailable.', 503);
    }
    if (($rotation['state'] ?? '') !== 'rotated' || empty($rotation['token']) || empty($rotation['user'])) {
        err('Invalid or expired refresh token. Please login again.', 401);
    }

    $user = $rotation['user'];
    ok([
        'token' => createToken($user['id'], $user['username'], $user['role'], $user['full_name'], $accessTokenExpiry),
        'refresh_token' => $rotation['token'],
        'expires_in' => $accessTokenExpiry,
    ]);
}

// ============================================================
// POST /auth/logout — revoke the presented refresh-token family
// ============================================================
if ($action === 'logout' && $method === 'POST') {
    if (isApiRateLimited('auth_logout', 30)) {
        err('Too many logout attempts. Please wait a minute.', 429);
    }
    $input = getBody();
    $refreshToken = (string)($input['refresh_token'] ?? '');
    if ($refreshToken !== '') {
        $payload = verifyToken($refreshToken);
        if ($payload && ($payload['typ'] ?? '') === 'refresh') {
            $refreshService->revokePresented($refreshToken, $payload);
        }
    }
    ok(['message' => 'Signed out.']);
}

// ============================================================
// GET /auth/verify
// ============================================================
if ($action === 'verify' && $method === 'GET') {
    $auth = apiRequireAuth();
    ok([
        'valid' => true,
        'user' => $auth,
        'expires_at' => date('c', $auth['exp']),
    ]);
}

err("Unknown auth action: {$action}. Use: login, refresh-token, logout, verify", 404);
