<?php
/**
 * School API v1 — Auth Routes
 * POST /auth/login          — Login with username + password, get tokens
 * POST /auth/refresh-token  — Get new access token using refresh token
 * GET  /auth/verify         — Verify current token is valid
 */

$action = $ROUTE['id'] ?? '';

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
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        err('Username and password are required.');
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
    
    // Update last login
    $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . (int)$user['id']);
    
    // Log the login
    logApiAction($user['id'], $user['username'], 'API Login', 'REST API v1');
    
    ok([
        'token' => createToken($user['id'], $user['username'], $user['role'], $user['full_name']),
        'refresh_token' => createRefreshToken($user['id'], $user['username'], $user['role'], $user['full_name']),
        'expires_in' => API_TOKEN_EXPIRY,
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
// POST /auth/refresh-token
// ============================================================
if ($action === 'refresh-token' && $method === 'POST') {
    $input = getBody();
    $refreshToken = $input['refresh_token'] ?? '';
    
    if (empty($refreshToken)) {
        err('Refresh token is required.');
    }
    
    $payload = verifyToken($refreshToken);
    if (!$payload) {
        err('Invalid or expired refresh token. Please login again.', 401);
    }
    
    if (($payload['typ'] ?? '') !== 'refresh') {
        err('Invalid token type. Provide a refresh token.', 401);
    }
    
    // Verify user still exists and is active
    $stmt = $conn->prepare("SELECT id, username, full_name, role, is_active FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $payload['uid']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user || (int)$user['is_active'] !== 1) {
        err('Account no longer active. Please login again.', 401);
    }
    
    ok([
        'token' => createToken($user['id'], $user['username'], $user['role'], $user['full_name']),
        'refresh_token' => createRefreshToken($user['id'], $user['username'], $user['role'], $user['full_name']),
        'expires_in' => API_TOKEN_EXPIRY,
    ]);
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

err("Unknown auth action: {$action}. Use: login, refresh-token, verify", 404);
