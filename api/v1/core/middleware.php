<?php
/**
 * School API v1 — Middleware
 * Runs before every request: CORS, headers, method validation
 */

require_once __DIR__ . '/../../../admin/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/../../../admin/backend/services/ApiIdempotencyService.php';

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
    
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Idempotency-Key, X-App-Version, X-App-Build');
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
 * Stripe-style idempotency. Same user + route + key + payload = same JSON.
 */
function apiIdempotencyKey(): string {
    $key = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($key === '' || strlen($key) > 80 || !preg_match('/^[A-Za-z0-9._-]+$/', $key)) {
        return '';
    }
    return $key;
}

function apiIdempotencyService(): \App\Services\ApiIdempotencyService {
    static $service = null;
    global $conn;
    if (!$service instanceof \App\Services\ApiIdempotencyService) {
        $database = $conn instanceof \mysqli ? $conn : null;
        $service = new \App\Services\ApiIdempotencyService(
            $database,
            ROOT_PATH . '/admin/uploads/cache'
        );
    }
    return $service;
}

/** @return mixed */
function apiCanonicalizeIdempotencyValue($value) {
    if (!is_array($value)) {
        return $value;
    }
    $keys = array_keys($value);
    $isList = $keys === range(0, count($keys) - 1);
    if (!$isList) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = apiCanonicalizeIdempotencyValue($item);
    }
    return $value;
}

function apiIdempotencyRequestHash(string $method, string $scope): string {
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $canonical = json_encode(
        apiCanonicalizeIdempotencyValue($payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($canonical === false) {
        $canonical = $raw;
    }
    return hash('sha256', $method . "\0" . $scope . "\0" . $canonical);
}

function apiIdempotencyBegin(int $userId, ?string $fromBody = null): void {
    $bodyKey = trim((string)$fromBody);
    if ($bodyKey !== '' && empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $bodyKey;
    }
    $key = apiIdempotencyKey();
    if ($key === '') {
        if ($bodyKey !== '' || !empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
            err('Invalid idempotency key.', 400);
        }
        return;
    }
    if ($userId <= 0) {
        err('Authentication is required for idempotent writes.', 401);
    }

    global $ROUTE;
    $method = function_exists('getMethod') ? getMethod() : (string)($_SERVER['REQUEST_METHOD'] ?? 'POST');
    $route = is_array($ROUTE) ? (string)($ROUTE['full_route'] ?? '') : '';
    if ($route === '') {
        $route = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/api/v1'), PHP_URL_PATH) ?: '/api/v1');
    }
    $scope = substr(strtoupper($method) . ' ' . $route, 0, 255);
    $requestHash = apiIdempotencyRequestHash(strtoupper($method), $scope);
    $service = apiIdempotencyService();
    $result = $service->begin($userId, $key, $scope, $requestHash);

    if (($result['state'] ?? '') === 'acquired') {
        $GLOBALS['_fkss_idem'] = ['service' => $service, 'reservation' => $result];
        return;
    }
    if (($result['state'] ?? '') === 'replay') {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code((int)($result['status_code'] ?? 200));
            header('Content-Type: application/json; charset=utf-8');
            header('Idempotency-Replayed: true');
        }
        echo (string)($result['body'] ?? '');
        exit;
    }
    if (($result['state'] ?? '') === 'conflict') {
        err('This idempotency key was already used with a different request.', 409);
    }
    if (($result['state'] ?? '') === 'processing') {
        if (!headers_sent()) {
            header('Retry-After: ' . max(1, (int)($result['retry_after'] ?? 1)));
        }
        err('A request with this idempotency key is still processing.', 409);
    }
    err('Idempotency service is temporarily unavailable. Please retry safely.', 503);
}

function apiIdempotencyStore(string $json, int $code): void {
    $pack = $GLOBALS['_fkss_idem'] ?? null;
    unset($GLOBALS['_fkss_idem']);
    if (!is_array($pack)
        || !(($pack['service'] ?? null) instanceof \App\Services\ApiIdempotencyService)
        || !is_array($pack['reservation'] ?? null)) {
        return;
    }

    if ($code === 429) {
        $pack['service']->abandon($pack['reservation']);
        return;
    }
    $pack['service']->complete($pack['reservation'], $json, $code);
}

/**
 * Atomic API rate limiting per client address + endpoint.
 *
 * Idempotency keys never bypass throttling: they are caller-controlled and are
 * only a replay-safety mechanism. The shared database backend supports multiple
 * API instances; SecurityRateLimiter provides a locked compatibility fallback
 * while migration 008 is rolled out.
 */
function isApiRateLimited($endpoint, $maxPerMinute = 60) {
    static $rateLimiter = null;
    global $pdo;

    if (!$rateLimiter instanceof \App\Services\SecurityRateLimiter) {
        $database = $pdo instanceof \PDO ? $pdo : null;
        $rateLimiter = new \App\Services\SecurityRateLimiter(
            $database,
            ROOT_PATH . '/admin/uploads/cache'
        );
    }

    $safeEndpoint = preg_replace('/[^A-Za-z0-9._:-]/', '_', (string)$endpoint);
    $limit = max(1, min((int)$maxPerMinute, 1000));
    $result = $rateLimiter->consume(
        'api:' . substr($safeEndpoint, 0, 59),
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        $limit,
        60
    );
    if (!$result['allowed'] && !headers_sent()) {
        header('Retry-After: ' . max(1, (int)$result['retry_after']));
        header('X-RateLimit-Limit: ' . $limit);
    }

    return !$result['allowed'];
}
