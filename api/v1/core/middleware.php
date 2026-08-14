<?php
/**
 * School API v1 — Middleware
 * Runs before every request: CORS, headers, method validation
 */

/**
 * Handle CORS preflight and set response headers
 */
function handleCors() {
    header('Content-Type: application/json; charset=utf-8');
    
    // ── CORS: Allow specific origins only ──
    // Mobile apps (Flutter) don't send Origin headers, so they pass through.
    // Web browsers WILL send Origin — we only allow our own domains.
    $allowedOrigins = [
        SITE_URL,
        'http://' . SITE_DOMAIN,
        'https://www.' . SITE_DOMAIN,
        // Add more if needed, e.g. a staging domain
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    } elseif ($origin === '') {
        // No Origin header = not a browser CORS request (mobile app, curl, etc.)
        // Allow these through — they're authenticated via JWT anyway
        header('Access-Control-Allow-Origin: *');
    }
    // If Origin is set but NOT in our list → no CORS header = browser blocks it
    
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('X-Content-Type-Options: nosniff');
    header('X-API-Version: 1.0');
    
    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Require specific HTTP method(s)
 */
function requireMethod($allowed) {
    $allowed = (array)$allowed;
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, $allowed)) {
        err("Method $method not allowed. Use: " . implode(', ', $allowed), 405);
    }
}

/**
 * Get the HTTP method (supports method override for clients that can't send PUT/DELETE)
 */
function getMethod() {
    $method = $_SERVER['REQUEST_METHOD'];
    // Allow method override via header or POST field
    if ($method === 'POST') {
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_POST['_method'] ?? '';
        if (in_array(strtoupper($override), ['PUT', 'DELETE', 'PATCH'])) {
            $method = strtoupper($override);
        }
    }
    return $method;
}

/**
 * Simple API rate limiting (per IP + endpoint)
 * Returns true if rate limited (blocked)
 */
function isApiRateLimited($endpoint, $maxPerMinute = 60) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = ROOT_PATH . '/admin/uploads/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    
    $key = md5("api_{$endpoint}_{$ip}");
    $file = $cacheDir . "/api_rate_{$key}.json";
    $data = ['count' => 0, 'window_start' => time()];
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
        if (time() - $data['window_start'] > 60) {
            $data = ['count' => 0, 'window_start' => time()];
        }
    }
    
    if ($data['count'] >= $maxPerMinute) {
        return true;
    }
    
    $data['count']++;
    @file_put_contents($file, json_encode($data));
    return false;
}
