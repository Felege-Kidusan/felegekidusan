<?php
/**
 * ============================================================
 * School Management System - UNIFIED Configuration
 * ============================================================
 * 
 * This is the MAIN config file. All other files should use this.
 * Database configuration
 * 
 * Last Updated: December 2025
 * ============================================================
 */

// ============================================================
// PREVENT DIRECT ACCESS (Security)
// ============================================================
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    die('Direct access not allowed');
}

// ============================================================
// LOAD SECRETS FROM ENV FILE (outside web root)
// ============================================================
// Secrets file lives ABOVE public_html so it's never web-accessible.
// NOTE: school_config.php isn't loaded yet, so we list candidate env filenames here.
// Checks each name in each location — works for any school deployment.
$_envFileNames = ['.fkss_env.php', '.wbws_env.php'];
$_envBaseDirs = [
    dirname(__DIR__),       // /home/user/ (production)
    dirname(__DIR__, 2),    // two levels up (some hosting layouts)
    __DIR__,                // fallback: same dir (dev only — NOT recommended)
];
$_envPaths = [];
foreach ($_envBaseDirs as $_d) {
    foreach ($_envFileNames as $_n) {
        $_envPaths[] = $_d . '/' . $_n;
    }
}
$_envLoaded = false;
foreach ($_envPaths as $_envPath) {
    if (file_exists($_envPath)) {
        require_once $_envPath;
        $_envLoaded = true;
        break;
    }
}
if (!$_envLoaded) {
    // FALLBACK: hardcoded defaults so the system doesn't crash
    // ⚠️ If you see this in error logs, the env file is missing!
    error_log('CRITICAL: env file not found! Using hardcoded fallback. Fix ASAP.');
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'school_db');
    if (!defined('DB_USER')) define('DB_USER', 'school_db');
   if (!defined('DB_PASS')) define('DB_PASS', 'ENV_FILE_MISSING');
if (!defined('JWT_SECRET')) define('JWT_SECRET', 'FALLBACK_ONLY_env_file_missing');}

// ============================================================
// SCHOOL BRANDING (loaded from school_config.php)
// ============================================================
require_once __DIR__ . '/school_config.php';

// ============================================================
// SITE CONFIGURATION (legacy aliases — school_config.php defines the real ones)
// ============================================================
if (!defined('SITE_URL'))       define('SITE_URL', 'https://localhost');
if (!defined('ADMIN_URL'))      define('ADMIN_URL', SITE_URL . '/admin');
if (!defined('SITE_NAME'))      define('SITE_NAME', defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School');
if (!defined('SITE_NAME_AMHARIC')) define('SITE_NAME_AMHARIC', defined('SCHOOL_NAME_AMHARIC') ? SCHOOL_NAME_AMHARIC : '');

// ============================================================
// FILE PATHS
// ============================================================
define('ROOT_PATH', __DIR__);
define('ADMIN_PATH', __DIR__ . '/admin');
define('UPLOADS_PATH', __DIR__ . '/admin/uploads');

// ============================================================
// JWT / API TOKEN SECRET
// ============================================================
// Loaded from env file (outside web root)
// If JWT_SECRET is not defined, the env file failed to load
if (!defined('JWT_SECRET')) {
    error_log('CRITICAL: JWT_SECRET not defined! Check env file');
    define('JWT_SECRET', bin2hex(random_bytes(32))); // emergency random — tokens won't persist across requests
}

// ============================================================
// SESSION CONFIGURATION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    // Security settings for sessions
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    // Auto-detect HTTPS - ONLY enable cookie_secure when HTTPS is available
    // Setting cookie_secure=1 on HTTP hosting KILLS sessions completely!
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
               || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
               || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($isHttps) {
        ini_set('session.cookie_secure', 1);
    }
    ini_set('session.cookie_lifetime', 0); // Expire on browser close
    ini_set('session.cookie_samesite', 'Lax'); // Lax is safer than Strict for form submissions
    session_start();
}

// Session Timeout (30 minutes = 1800 seconds)
define('SESSION_TIMEOUT', 1800);

/**
 * Detect if this request is an AJAX/fetch() call.
 * When AJAX requests hit session timeout, we MUST return JSON — not a 302 redirect.
 */
function _isAjaxRequest() {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    if (!empty($_SERVER['HTTP_ACCEPT']) && 
        strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
    if (!empty($_SERVER['CONTENT_TYPE']) && 
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) return true;
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) return true;
    $script = basename($_SERVER['PHP_SELF'] ?? '');
    if (strpos($script, 'api_') === 0 || strpos($script, 'info_register') === 0 ||
        strpos($script, 'info_manage') === 0 || strpos($script, 'info_archive') === 0 ||
        strpos($script, 'info_restore') === 0 || strpos($script, 'info_get_') === 0 ||
        strpos($script, 'api_check') === 0) return true;
    return false;
}

// Check for session timeout (only if user is logged in)
// Skip for API requests — they use JWT tokens, not sessions
if (isset($_SESSION['LAST_ACTIVITY']) && isset($_SESSION['admin_logged_in']) && !defined('WBWS_API_REQUEST')) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > SESSION_TIMEOUT) {
        // Session expired
        session_unset();
        session_destroy();
        
        // Only redirect if not on login-related pages
        $currentPage = basename($_SERVER['PHP_SELF']);
        $loginPages = ['index.php', 'login.php']; // Pages that don't need redirect
        
        if (!in_array($currentPage, $loginPages)) {
            // CRITICAL FIX: Return JSON for AJAX requests instead of redirecting
            if (_isAjaxRequest()) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(401);
                }
                echo json_encode([
                    'status'  => 'session_expired',
                    'message' => 'Your session has expired. Please log in again.',
                    'action'  => 'reload'
                ]);
                exit();
            }
            
            // Normal page request → redirect to login
            $adminBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            // Walk up to find /admin/ level
            while ($adminBase !== '' && basename($adminBase) !== 'admin') {
                $adminBase = dirname($adminBase);
            }
            if ($adminBase === '' || $adminBase === '/' || $adminBase === '.') {
                $adminBase = '/admin';
            }
            header("Location: {$adminBase}/index.php?timeout=1");
            exit();
        }
    }
}

// Update last activity time (only if logged in)
if (isset($_SESSION['admin_logged_in'])) {
    $_SESSION['LAST_ACTIVITY'] = time();
}

// ============================================================
// TIMEZONE
// ============================================================
date_default_timezone_set('Africa/Addis_Ababa');

// ============================================================
// ERROR REPORTING
// ============================================================
// For Development (shows errors):
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// For Production (hides errors, logs them):
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/error.log');

// ============================================================
// SECURITY HEADERS
// ============================================================
if (!headers_sent()) {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    // XSS Protection
    header('X-XSS-Protection: 1; mode=block');
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ============================================================
// DATABASE CONNECTIONS
// ============================================================

// --- MySQLi Connection (used by most pages) ---
$conn = null;
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        throw new Exception('MySQLi connection failed: ' . $conn->connect_error);
    }
    
    // Use UTF-8 for Amharic support
    $conn->set_charset('utf8mb4');
    
} catch (Exception $e) {
    error_log($e->getMessage());
    // Don't die here - let individual pages handle errors
}

// --- PDO Connection (used by some pages like users.php) ---
$pdo = null;
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
} catch (PDOException $e) {
    error_log("PDO connection failed: " . $e->getMessage());
    // Don't die here - let individual pages handle errors
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Sanitize user input to prevent XSS attacks
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data ?? '')), ENT_QUOTES, 'UTF-8');
}

/**
 * Safe escape function for output (handles NULL values - PHP 8+ compatible)
 * Use this instead of htmlspecialchars() directly
 * 
 * @param mixed $value The value to escape
 * @param string $default Default value if null/empty
 * @return string Escaped string safe for HTML output
 */
function e($value, $default = '') {
    if ($value === null || $value === '') {
        return $default;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Safe escape with fallback display text
 * e.g., esc($member['phone'], 'Not provided')
 */
function esc($value, $fallback = '---') {
    if ($value === null || trim((string)$value) === '') {
        return $fallback;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// CSRF PROTECTION
// ============================================================

/**
 * Generate CSRF token and store in session
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get hidden input field with CSRF token
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}

/**
 * Validate CSRF token from POST request
 * @param string|null $token Optional token to validate (if null, reads from $_POST['csrf_token'])
 */
function validateCsrf($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF token (dies on failure)
 */
function requireCsrf() {
    if (!validateCsrf()) {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh and try again.']));
    }
}

// ============================================================
// INPUT VALIDATION HELPERS
// ============================================================

/**
 * Validate date format (YYYY-MM-DD)
 * Returns the date string if valid, or $default if invalid/empty
 */
function validateDate($input, $default = null) {
    if ($input === null || trim((string)$input) === '') return $default;
    $d = trim((string)$input);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $default;
    $parts = explode('-', $d);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) return $default;
    return $d;
}

/**
 * Validate month format (YYYY-MM)
 */
function validateMonth($input, $default = null) {
    if ($input === null || trim((string)$input) === '') return $default;
    $d = trim((string)$input);
    if (!preg_match('/^\d{4}-\d{2}$/', $d)) return $default;
    $parts = explode('-', $d);
    if ((int)$parts[1] < 1 || (int)$parts[1] > 13) return $default; // 13 for Ethiopian calendar
    return $d;
}

/**
 * Validate and sanitize a positive monetary amount
 * Returns float if valid, null if invalid
 */
function validateAmount($input) {
    if ($input === null || trim((string)$input) === '') return null;
    $val = filter_var($input, FILTER_VALIDATE_FLOAT);
    if ($val === false || $val < 0 || $val > 99999999.99) return null;
    return round($val, 2);
}

/**
 * Validate password strength
 * Returns array of error messages (empty = valid)
 */
function validatePassword($password) {
    $errors = [];
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if (strlen($password) > 128) {
        $errors[] = 'Password is too long.';
    }
    return $errors;
}

/**
 * Validate username format
 * Only letters, numbers, underscores, dots. 3-50 chars.
 */
function validateUsername($username) {
    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 50) {
        return 'Username must be 3-50 characters.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
        return 'Username can only contain letters, numbers, dots and underscores.';
    }
    return null; // valid
}

/**
 * Validate Ethiopian phone number (09xxxxxxxx or +2519xxxxxxxx)
 * Returns cleaned number or null if invalid
 */
function validatePhone($input) {
    if ($input === null || trim((string)$input) === '') return null;
    $phone = preg_replace('/[\s\-\(\)]+/', '', trim((string)$input));
    // Accept: 09xxxxxxxx, +2519xxxxxxxx, 2519xxxxxxxx
    if (preg_match('/^(?:\+?251|0)(9\d{8})$/', $phone, $m)) {
        return '0' . $m[1]; // Normalize to 09xxxxxxxx
    }
    // Also accept non-Ethiopian formats loosely (7+ digits)
    if (preg_match('/^\+?\d{7,15}$/', $phone)) {
        return $phone;
    }
    return null;
}

/**
 * Validate email (loose — just basic format check)
 */
function validateEmail($input) {
    if ($input === null || trim((string)$input) === '') return null;
    $email = trim((string)$input);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/**
 * Validate enum/whitelist value
 * Returns the value if in the allowed list, or $default
 */
function validateEnum($input, array $allowed, $default = null) {
    $val = trim((string)($input ?? ''));
    return in_array($val, $allowed, true) ? $val : $default;
}

/**
 * Safe integer from user input (returns $default if not numeric)
 */
function safeInt($input, $default = 0) {
    if ($input === null || $input === '') return $default;
    $val = filter_var($input, FILTER_VALIDATE_INT);
    return ($val !== false) ? $val : $default;
}

/**
 * Validate and enforce CSRF for API POST requests
 * Call at the top of any API file that handles POST data
 * Checks both form field and X-CSRF-TOKEN header
 */
function requireCsrfForPost() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($token)) {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh the page.']));
    }
}

// ============================================================
// RATE LIMITING (Simple file-based for login attempts)
// ============================================================

/**
 * Check if IP is rate limited
 * @param string $action Action name (e.g., 'login')
 * @param int $maxAttempts Max attempts allowed
 * @param int $windowSeconds Time window in seconds
 * @return bool True if rate limited (blocked), false if allowed
 */
function isRateLimited($action = 'login', $maxAttempts = 5, $windowSeconds = 300) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = ROOT_PATH . '/admin/uploads/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    
    $file = $cacheDir . '/rate_' . md5($action . '_' . $ip) . '.json';
    $data = ['attempts' => 0, 'first_attempt' => time()];
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
        
        // Reset if window has passed
        if (time() - $data['first_attempt'] > $windowSeconds) {
            $data = ['attempts' => 0, 'first_attempt' => time()];
        }
    }
    
    return $data['attempts'] >= $maxAttempts;
}

/**
 * Record an attempt for rate limiting
 */
function recordAttempt($action = 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = ROOT_PATH . '/admin/uploads/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    
    $file = $cacheDir . '/rate_' . md5($action . '_' . $ip) . '.json';
    $data = ['attempts' => 0, 'first_attempt' => time()];
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
        if (time() - $data['first_attempt'] > 300) {
            $data = ['attempts' => 0, 'first_attempt' => time()];
        }
    }
    
    $data['attempts']++;
    file_put_contents($file, json_encode($data));
}

/**
 * Clear rate limit for IP (call on successful login)
 */
function clearRateLimit($action = 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = ROOT_PATH . '/admin/uploads/cache';
    $file = $cacheDir . '/rate_' . md5($action . '_' . $ip) . '.json';
    if (file_exists($file)) @unlink($file);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Get current user's role
 */
function getUserRole() {
    return $_SESSION['admin_role'] ?? null;
}

/**
 * Check if user has specific role(s)
 */
function hasRole($allowedRoles) {
    if (!isLoggedIn()) {
        return false;
    }
    $allowedRoles = (array)$allowedRoles;
    return in_array($_SESSION['admin_role'], $allowedRoles);
}

/**
 * Require user to be logged in (redirect if not, JSON if AJAX)
 */
function requireAuth() {
    if (!isLoggedIn()) {
        if (function_exists('_isAjaxRequest') && _isAjaxRequest()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(401);
            }
            echo json_encode(['status' => 'session_expired', 'message' => 'Not authenticated.', 'action' => 'reload']);
            exit;
        }
        header('Location: ' . ADMIN_URL . '/index.php');
        exit;
    }
}

/**
 * Require specific role(s)
 */
function requireRole($allowedRoles) {
    requireAuth();
    if (!hasRole($allowedRoles)) {
        header('Location: ' . ADMIN_URL . '/index.php?error=access_denied');
        exit;
    }
}

/**
 * Redirect helper
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * JSON response helper for AJAX requests
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get user-friendly error message
 */
function getErrorMessage($code) {
    $messages = [
        'access_denied' => 'You do not have permission to access this page.',
        'timeout' => 'Your session has expired. Please login again.',
        'invalid_credentials' => 'Invalid username or password.',
        'account_inactive' => 'Your account is inactive. Contact administrator.',
    ];
    return $messages[$code] ?? 'An error occurred.';
}

// ============================================================
// ROLE DEFINITIONS (for reference)
// ============================================================
/*
Available roles:
- super_admin    : Full system access
- school_admin   : School-level admin
- info_dept      : Information department (member management)
- edu_dept       : Education department
- finance_dept   : Finance department
- material_dept  : Material department
*/

// ============================================================
// AUTO-FIX CRITICAL DATABASE TABLES (runs once per session)
// ============================================================
if (isset($conn) && $conn && !$conn->connect_error) {
    if (!isset($_SESSION['_db_checked'])) {
        
        // Safe column check helper (works even in MySQLi exception mode)
        $_safeColCheck = function($conn, $table, $column) {
            try {
                $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                return $r && $r->num_rows > 0;
            } catch (Exception $e) { return false; }
        };
        
        // Safe query helper (never throws)
        $_safeQuery = function($conn, $sql) {
            try { $conn->query($sql); } catch (Exception $e) { /* skip */ }
        };
        
        // Check for critical tables and create if missing
        $criticalTables = [
            'notifications' => "
                CREATE TABLE IF NOT EXISTS `notifications` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `type` VARCHAR(50) NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `message` TEXT NOT NULL,
                    `data` JSON DEFAULT NULL,
                    `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
                    `source_dept` VARCHAR(50) DEFAULT NULL,
                    `source_user_id` INT UNSIGNED DEFAULT NULL,
                    `target_roles` VARCHAR(255) DEFAULT NULL,
                    `target_user_id` INT UNSIGNED DEFAULT NULL,
                    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `read_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `type` (`type`),
                    KEY `target_roles` (`target_roles`),
                    KEY `target_user_id` (`target_user_id`),
                    KEY `is_read` (`is_read`),
                    KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'activity_logs' => "
                CREATE TABLE IF NOT EXISTS `activity_logs` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED DEFAULT NULL,
                    `username` VARCHAR(100) DEFAULT NULL,
                    `action` VARCHAR(100) NOT NULL,
                    `details` TEXT DEFAULT NULL,
                    `entity_type` VARCHAR(50) DEFAULT NULL,
                    `entity_id` INT UNSIGNED DEFAULT NULL,
                    `ip_address` VARCHAR(45) DEFAULT NULL,
                    `user_agent` VARCHAR(255) DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `user_id` (`user_id`),
                    KEY `action` (`action`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'system_branding' => "
                CREATE TABLE IF NOT EXISTS `system_branding` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `asset_key` VARCHAR(50) NOT NULL,
                    `asset_label` VARCHAR(100) NOT NULL DEFAULT '',
                    `file_path` VARCHAR(500) DEFAULT NULL,
                    `original_name` VARCHAR(255) DEFAULT NULL,
                    `mime_type` VARCHAR(100) DEFAULT NULL,
                    `file_size` INT UNSIGNED DEFAULT 0,
                    `uploaded_by` INT UNSIGNED DEFAULT NULL,
                    `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_asset_key` (`asset_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'academic_years' => "
                CREATE TABLE IF NOT EXISTS `academic_years` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `year_name` VARCHAR(100) NOT NULL,
                    `year_gc` VARCHAR(20) DEFAULT NULL,
                    `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
                    `start_date` DATE DEFAULT NULL,
                    `end_date` DATE DEFAULT NULL,
                    `is_current` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'classes' => "
                CREATE TABLE IF NOT EXISTS `classes` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `class_name` VARCHAR(150) NOT NULL,
                    `class_name_en` VARCHAR(150) DEFAULT NULL,
                    `class_code` VARCHAR(30) NOT NULL,
                    `level_order` INT NOT NULL DEFAULT 0,
                    `section` VARCHAR(50) DEFAULT NULL,
                    `age_group` VARCHAR(20) DEFAULT NULL,
                    `description` TEXT DEFAULT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        
        foreach ($criticalTables as $tableName => $createSQL) {
            try {
                $check = $conn->query("SHOW TABLES LIKE '$tableName'");
                if (!$check || $check->num_rows === 0) {
                    $_safeQuery($conn, $createSQL);
                }
            } catch (Exception $e) { /* skip */ }
        }
        
        // --- Fix users table: add all missing columns ---
        if (!$_safeColCheck($conn, 'users', 'last_login')) {
            $_safeQuery($conn, "ALTER TABLE `users` ADD COLUMN `last_login` DATETIME DEFAULT NULL AFTER `is_active`");
        }
        if (!$_safeColCheck($conn, 'users', 'member_id')) {
            $_safeQuery($conn, "ALTER TABLE `users` ADD COLUMN `member_id` INT UNSIGNED DEFAULT NULL AFTER `is_active`");
        }
        
        // Expand users.role ENUM to include 'teacher' and other roles
        $_safeQuery($conn, "ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'info_dept'");
        
        // --- Fix members table: add all missing columns ---
        if (!$_safeColCheck($conn, 'members', 'spiritual_education')) {
            $_safeQuery($conn, "ALTER TABLE `members` ADD COLUMN `spiritual_education` VARCHAR(100) DEFAULT NULL AFTER `education_level`");
        }
        
        // Fix age_group ENUM to accept all possible values
        $_safeQuery($conn, "ALTER TABLE `members` MODIFY COLUMN `age_group` VARCHAR(20) DEFAULT NULL");
        
        // Fix classes.age_group ENUM → VARCHAR (prevents data truncation)
        try {
            $check = $conn->query("SHOW TABLES LIKE 'classes'");
            if ($check && $check->num_rows > 0) {
                $_safeQuery($conn, "ALTER TABLE `classes` MODIFY COLUMN `age_group` VARCHAR(20) DEFAULT NULL");
            }
        } catch (Exception $e) { /* skip */ }
        
        // --- Fix members table: add columns used by education/workflow system ---
        if (!$_safeColCheck($conn, 'members', 'current_class_id')) {
            $_safeQuery($conn, "ALTER TABLE `members` ADD COLUMN `current_class_id` INT UNSIGNED DEFAULT NULL");
        }
        if (!$_safeColCheck($conn, 'members', 'promoted_at')) {
            $_safeQuery($conn, "ALTER TABLE `members` ADD COLUMN `promoted_at` DATE DEFAULT NULL");
        }
        if (!$_safeColCheck($conn, 'members', 'academic_status')) {
            $_safeQuery($conn, "ALTER TABLE `members` ADD COLUMN `academic_status` VARCHAR(20) DEFAULT 'active'");
        }
        
        // --- Fix wbws_groups table ---
        try {
            $check = $conn->query("SHOW TABLES LIKE 'wbws_groups'");
            if ($check && $check->num_rows > 0) {
                if (!$_safeColCheck($conn, 'wbws_groups', 'group_name_en'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_groups` ADD COLUMN `group_name_en` VARCHAR(200) DEFAULT NULL AFTER `group_name`");
                if (!$_safeColCheck($conn, 'wbws_groups', 'established_year_gc'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_groups` ADD COLUMN `established_year_gc` VARCHAR(20) DEFAULT NULL AFTER `established_year`");
                if (!$_safeColCheck($conn, 'wbws_groups', 'description'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_groups` ADD COLUMN `description` TEXT DEFAULT NULL");
                if (!$_safeColCheck($conn, 'wbws_groups', 'status'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_groups` ADD COLUMN `status` ENUM('active','inactive') NOT NULL DEFAULT 'active'");
                if (!$_safeColCheck($conn, 'wbws_groups', 'updated_by'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_groups` ADD COLUMN `updated_by` VARCHAR(100) DEFAULT NULL");
                if (!$_safeColCheck($conn, 'wbws_groups', 'updated_at'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_groups` ADD COLUMN `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
            }
        } catch (Exception $e) { /* skip */ }
        
        // --- Fix wbws_group_leaders table ---
        try {
            $check = $conn->query("SHOW TABLES LIKE 'wbws_group_leaders'");
            if ($check && $check->num_rows > 0) {
                if (!$_safeColCheck($conn, 'wbws_group_leaders', 'leader_full_name_en'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_group_leaders` ADD COLUMN `leader_full_name_en` VARCHAR(200) DEFAULT NULL AFTER `leader_full_name`");
                if (!$_safeColCheck($conn, 'wbws_group_leaders', 'email'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_group_leaders` ADD COLUMN `email` VARCHAR(100) DEFAULT NULL AFTER `phone`");
                if (!$_safeColCheck($conn, 'wbws_group_leaders', 'start_date'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_group_leaders` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `responsibility`");
                if (!$_safeColCheck($conn, 'wbws_group_leaders', 'end_date'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_group_leaders` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`");
                if (!$_safeColCheck($conn, 'wbws_group_leaders', 'is_active'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_group_leaders` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
                if (!$_safeColCheck($conn, 'wbws_group_leaders', 'updated_by'))
                    $_safeQuery($conn, "ALTER TABLE `wbws_group_leaders` ADD COLUMN `updated_by` VARCHAR(100) DEFAULT NULL");
            }
        } catch (Exception $e) { /* skip */ }
        
        $_SESSION['_db_checked'] = true;
        
        // Seed system_branding defaults if table exists but is empty
        try {
            $check = $conn->query("SELECT COUNT(*) as cnt FROM system_branding");
            if ($check && (int)$check->fetch_assoc()['cnt'] === 0) {
                $brandDefaults = [
                    ['logo',      'School Logo',               '/admin/id_cards/assets/logos/school_logo.png'],
                    ['seal',      'School Seal / Stamp',        '/admin/id_cards/assets/seals/school_seal.png'],
                    ['sig_head',  'Head Teacher Signature',     '/admin/id_cards/assets/signatures/head_signature.png'],
                    ['sig_admin', 'Director / Admin Signature', '/admin/id_cards/assets/signatures/director_signature.png'],
                ];
                $seedStmt = $conn->prepare("INSERT IGNORE INTO system_branding (asset_key, asset_label, file_path) VALUES (?, ?, ?)");
                if ($seedStmt) {
                    foreach ($brandDefaults as $d) {
                        $seedStmt->bind_param("sss", $d[0], $d[1], $d[2]);
                        $seedStmt->execute();
                    }
                    $seedStmt->close();
                }
            }
        } catch (Exception $e) { /* ignore if table doesn't exist yet */ }
    }
}

// ============================================================
// OUTPUT COMPRESSION (for slow internet connections)
// ============================================================
// Skip gzip for API endpoints (they handle their own output)
// Detection: check script path, not headers (fetch() sends Accept:*/* not application/json)
$_currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
$_isApiRequest = (
    strpos($_currentScript, '/backend/') !== false ||
    strpos($_currentScript, '_api') !== false ||
    strpos($_currentScript, 'api_') !== false ||
    strpos($_currentScript, '/api/') !== false ||
    defined('WBWS_API_REQUEST')
);

if (!$_isApiRequest) {
    if (!ob_start("ob_gzhandler")) {
        ob_start();
    }
}

// ============================================================
// CONFIGURATION COMPLETE
// ============================================================

// ============================================================
// ERROR MONITORING SYSTEM (Arkeon Monitor)
// ============================================================
// Catches all PHP errors, logs to DB, sends Telegram alerts
// Dashboard: {SITE_URL}/monitor/
$_monitorPath = ROOT_PATH . '/monitor/error_monitor.php';
if (file_exists($_monitorPath)) {
    require_once $_monitorPath;
}

// ============================================================
// CENTRALIZED ACCESS CONTROL (must run AFTER auth helpers above)
// ============================================================
// This single guard enforces "who may open which admin page".
// It only acts on /admin/ and /backend/ scripts; the public site,
// the mobile API, and cron jobs are exempt inside the guard itself.
$_accessControlPath = ROOT_PATH . '/admin/access_control.php';
if (file_exists($_accessControlPath)) {
    require_once $_accessControlPath;
}

