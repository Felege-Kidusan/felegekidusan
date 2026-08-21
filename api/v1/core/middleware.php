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
    
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Idempotency-Key');
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
 * Stripe-style idempotency. Same teacher + same key = same JSON.
 */
function apiIdempotencyKey(): string {
    $key = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($key === '' || strlen($key) > 80 || !preg_match('/^[A-Za-z0-9._-]+$/', $key)) {
        return '';
    }
    return $key;
}

function apiIdempotencyEnsureTable(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    global $conn;
    if (!isset($conn) || !$conn) {
        return;
    }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `api_idempotency` (
            `idem_key` VARCHAR(80) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `status_code` SMALLINT NOT NULL DEFAULT 200,
            `body` MEDIUMTEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`idem_key`, `user_id`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ok */ }
}

function apiIdempotencyBegin(int $userId, ?string $fromBody = null): void {
    if ($fromBody && $fromBody !== '' && empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $fromBody;
    }
    $key = apiIdempotencyKey();
    if ($key === '' || $userId <= 0) {
        return;
    }
    global $conn;
    apiIdempotencyEnsureTable();
    if (!isset($conn) || !$conn) {
        return;
    }
    try {
        $stmt = $conn->prepare('SELECT status_code, body FROM api_idempotency WHERE idem_key = ? AND user_id = ? LIMIT 1');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $key, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && isset($row['body'])) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) {
                http_response_code((int)$row['status_code']);
                header('Content-Type: application/json; charset=utf-8');
                header('Idempotency-Replayed: true');
            }
            echo $row['body'];
            exit;
        }
    } catch (Throwable $e) { /* do the work */ }
    $GLOBALS['_fkss_idem'] = ['key' => $key, 'uid' => $userId];
}

function apiIdempotencyStore(string $json, int $code): void {
    $pack = $GLOBALS['_fkss_idem'] ?? null;
    if (!is_array($pack) || empty($pack['key'])) {
        return;
    }
    global $conn;
    if (!isset($conn) || !$conn) {
        return;
    }
    apiIdempotencyEnsureTable();
    try {
        $stmt = $conn->prepare('INSERT INTO api_idempotency (idem_key, user_id, status_code, body) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status_code = VALUES(status_code), body = VALUES(body)');
        if (!$stmt) {
            return;
        }
        $uid = (int)$pack['uid'];
        $stmt->bind_param('siis', $pack['key'], $uid, $code, $json);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) { /* non-fatal */ }
}

/**
 * Simple API rate limiting (per IP + endpoint)
 * Returns true if rate limited (blocked)
 */
function isApiRateLimited($endpoint, $maxPerMinute = 60) {
    if (function_exists('apiIdempotencyKey') && apiIdempotencyKey() !== '') {
        return false;
    }
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
