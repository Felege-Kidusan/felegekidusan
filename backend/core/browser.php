<?php
/**
 * Database-independent browser/session helpers.
 * Shared by config.php and logout, which must still work during a DB outage.
 */

/** Return a same-origin application URL, including a subdirectory install. */
function ssms_app_url(string $path): string
{
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $scriptFile = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = '';

    // Derive only from the server's script mapping, never Host/Referer input.
    // An included /admin/ handler still sees the requested /backend/ shim here.
    if (str_starts_with($scriptFile, $root . '/')) {
        $relative = substr($scriptFile, strlen($root));
        if ($relative !== '' && str_ends_with($scriptName, $relative)) {
            $base = substr($scriptName, 0, -strlen($relative));
        }
    }
    if ($base !== '' && (!preg_match('#^/(?:[A-Za-z0-9_~.%@+-]+/)*[A-Za-z0-9_~.%@+-]+$#D', $base)
        || str_contains($base, '..'))) {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}

function ssms_request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function ssms_start_browser_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    // PHP 8.4 deprecates these settings. Its secure defaults replace them.
    if (PHP_VERSION_ID < 80400) {
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
    }
    if (ssms_request_is_https()) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

function ssms_destroy_browser_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => ($params['samesite'] ?? '') ?: 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
        session_id('');
    }
}
