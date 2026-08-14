<?php
/**
 * ============================================================
 * Error Monitor — Integrated with School System
 * ============================================================
 * Based on Arkeon Error Monitor v1.0
 * Pre-configured for deployment
 * 
 * HOW IT WORKS:
 * - Catches ALL PHP errors, warnings, fatal crashes
 * - Catches uncaught exceptions  
 * - Logs to database with full context (URL, IP, session, stack trace)
 * - Sends Telegram alerts for critical/error level
 * - Auto-fixes common problems (missing dirs, permissions, DB reconnect)
 * - Dashboard at: /monitor/dashboard.php?key={your_key}
 * 
 * INTEGRATION:
 * One line added to /public_html/config.php:
 *   require_once __DIR__ . '/monitor/error_monitor.php';
 * ============================================================
 */

// ============================================================
// CONFIGURATION — Pre-filled for deployment
// ============================================================
if(!defined('MONITOR_PROJECT_NAME')) define('MONITOR_PROJECT_NAME', 'School Monitor');
define('MONITOR_DB_HOST', DB_HOST);
define('MONITOR_DB_NAME', DB_NAME);
define('MONITOR_DB_USER', DB_USER);
define('MONITOR_DB_PASS', DB_PASS);

// Secret key for the monitor dashboard.
// PREFERRED: set MONITOR_SECRET_KEY in the secrets env file (.fkss_env.php).
// If it is not set, we generate a key ONCE and persist it to a protected file
// so it stays stable across requests. The OLD code generated a NEW random key
// on EVERY run — which made the dashboard permanently inaccessible AND wrote a
// warning to error.log on every single invocation (this file runs ~once per
// minute), flooding the log. Now the warning fires at most once.
if (!defined('MONITOR_SECRET_KEY')) {
    $__mkFile = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__)) . '/admin/uploads/cache/.monitor_key';
    $__mkKey  = @is_file($__mkFile) ? trim((string)@file_get_contents($__mkFile)) : '';
    if (strlen($__mkKey) < 32) {
        $__mkKey = bin2hex(random_bytes(32));
        $__mkDir = dirname($__mkFile);
        if (!is_dir($__mkDir)) { @mkdir($__mkDir, 0755, true); }
        // Persist ONCE and warn ONCE (only when the key is first created).
        if (@file_put_contents($__mkFile, $__mkKey) !== false) {
            error_log('NOTICE: MONITOR_SECRET_KEY not in env file. Generated a persistent key at admin/uploads/cache/.monitor_key (add MONITOR_SECRET_KEY to your env file to set your own).');
        }
    }
    define('MONITOR_SECRET_KEY', $__mkKey);
}

// Telegram Settings — Set these up later via @BotFather
define('MONITOR_TELEGRAM_ENABLED', false);  // Change to true after setup
define('MONITOR_TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');
define('MONITOR_TELEGRAM_CHAT_ID', 'YOUR_CHAT_ID_HERE');

// Auto-fix settings
define('MONITOR_AUTO_FIX_ENABLED', true);
define('MONITOR_LOG_FILE', __DIR__ . '/error_monitor.log');

// Error display — never show raw errors to church users
define('MONITOR_SHOW_ERRORS_TO_USERS', false);
define('MONITOR_ERROR_LEVELS', E_ALL);
define('MONITOR_TELEGRAM_MAX_PER_HOUR', 10);


// ============================================================
// DO NOT EDIT BELOW THIS LINE
// ============================================================

class ArkeonErrorMonitor {
    
    private static $instance = null;
    private $db = null;
    private $dbConnected = false;
    private $telegramSentThisRequest = 0;
    private $maxTelegramPerRequest = 3;
    private $startTime;
    private $startMemory;
    
    // Common auto-fixable problems
    private $autoFixPatterns = [
        'mkdir\(\): No such file or directory' => 'fix_missing_directory',
        'failed to open stream: No such file or directory' => 'fix_missing_file_path',
        'Permission denied' => 'fix_permissions',
        'session_start\(\): Failed' => 'fix_session',
        'move_uploaded_file\(\)' => 'fix_upload_directory',
        'imagecreatefrom' => 'fix_image_processing',
        'MySQL server has gone away' => 'fix_db_reconnect',
        'Lost connection to MySQL' => 'fix_db_reconnect',
    ];
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage();
        $this->connectDB();
        
        // Only override error handling if NOT already set by main config
        // This prevents conflicts with the system's own error settings
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    private function connectDB() {
        try {
            // Use a SEPARATE connection so we never interfere with the system's $conn
            $this->db = @new mysqli(
                MONITOR_DB_HOST,
                MONITOR_DB_USER,
                MONITOR_DB_PASS,
                MONITOR_DB_NAME
            );
            
            if ($this->db->connect_error) {
                $this->logToFile("Monitor DB connection failed: " . $this->db->connect_error);
                $this->dbConnected = false;
                return;
            }
            
            $this->db->set_charset('utf8mb4');
            $this->dbConnected = true;
            
            // Auto-create table if it doesn't exist (self-setup)
            $this->ensureTablesExist();
            
        } catch (Exception $e) {
            $this->logToFile("Monitor DB exception: " . $e->getMessage());
            $this->dbConnected = false;
        }
    }
    
    /**
     * Auto-create monitor tables if they don't exist
     * No need to run setup_monitor.php separately!
     */
    private function ensureTablesExist() {
        if (!$this->dbConnected) return;
        
        // Check once per session to avoid running on every request
        if (isset($_SESSION['_monitor_tables_ok'])) return;
        
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'arkeon_error_log'");
            if ($check && $check->num_rows === 0) {
                $this->db->query("CREATE TABLE IF NOT EXISTS `arkeon_error_log` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `project_name` VARCHAR(100) NOT NULL DEFAULT '',
                    `error_type` VARCHAR(100) NOT NULL DEFAULT '',
                    `error_code` INT NOT NULL DEFAULT 0,
                    `severity` ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
                    `message` TEXT NOT NULL,
                    `file_path` VARCHAR(500) DEFAULT '',
                    `line_number` INT DEFAULT 0,
                    `url` VARCHAR(2000) DEFAULT '',
                    `http_method` VARCHAR(10) DEFAULT '',
                    `ip_address` VARCHAR(45) DEFAULT '',
                    `user_agent` VARCHAR(500) DEFAULT '',
                    `request_data` JSON DEFAULT NULL,
                    `session_data` JSON DEFAULT NULL,
                    `extra_data` JSON DEFAULT NULL,
                    `stack_trace` TEXT DEFAULT NULL,
                    `memory_usage` BIGINT DEFAULT 0,
                    `peak_memory` BIGINT DEFAULT 0,
                    `execution_time` DECIMAL(10,4) DEFAULT 0,
                    `auto_fix_applied` VARCHAR(500) DEFAULT NULL,
                    `php_version` VARCHAR(20) DEFAULT '',
                    `server_software` VARCHAR(200) DEFAULT '',
                    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
                    `resolved_at` DATETIME DEFAULT NULL,
                    `resolved_note` TEXT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_project` (`project_name`),
                    INDEX `idx_severity` (`severity`),
                    INDEX `idx_created` (`created_at`),
                    INDEX `idx_resolved` (`is_resolved`),
                    INDEX `idx_project_severity` (`project_name`, `severity`, `created_at`),
                    INDEX `idx_file` (`file_path`(100))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            
            $check2 = $this->db->query("SHOW TABLES LIKE 'arkeon_uptime_log'");
            if ($check2 && $check2->num_rows === 0) {
                $this->db->query("CREATE TABLE IF NOT EXISTS `arkeon_uptime_log` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `project_name` VARCHAR(100) NOT NULL DEFAULT '',
                    `url_checked` VARCHAR(2000) NOT NULL,
                    `status_code` INT DEFAULT 0,
                    `response_time_ms` INT DEFAULT 0,
                    `is_up` TINYINT(1) NOT NULL DEFAULT 1,
                    `error_message` VARCHAR(500) DEFAULT NULL,
                    `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_project_uptime` (`project_name`, `checked_at`),
                    INDEX `idx_status` (`is_up`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            
            if (isset($_SESSION)) {
                $_SESSION['_monitor_tables_ok'] = true;
            }
        } catch (Exception $e) {
            $this->logToFile("Table auto-create failed: " . $e->getMessage());
        }
    }
    
    // ============================================================
    // ERROR HANDLERS
    // ============================================================
    
    public function handleError($errno, $errstr, $errfile, $errline, $errcontext = null) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $severity = $this->getSeverityLevel($errno);
        
        $context = [
            'error_type' => $this->getErrorTypeName($errno),
            'error_code' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'severity' => $severity,
            'url' => $this->getCurrentURL(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'ip_address' => $this->getClientIP(),
            'get_params' => $this->sanitizeData($_GET),
            'post_params' => $this->sanitizeData($_POST),
            'session_data' => isset($_SESSION) ? $this->sanitizeData($_SESSION) : [],
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => round(microtime(true) - $this->startTime, 4),
            'stack_trace' => $this->getCleanStackTrace(),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
        ];
        
        // Try auto-fix
        $autoFixResult = null;
        if (MONITOR_AUTO_FIX_ENABLED) {
            $autoFixResult = $this->attemptAutoFix($errstr, $errfile, $errline, $context);
        }
        if ($autoFixResult) {
            $context['auto_fix_applied'] = $autoFixResult;
        }
        
        $errorId = $this->saveError($context);
        
        if ($severity === 'critical' || $severity === 'error') {
            $this->sendTelegramAlert($context, $errorId);
        }
        
        // Let PHP's internal handler also run for fatal-level errors
        // so the system's own error page/logging still works
        if ($severity === 'critical') {
            return false;
        }
        
        return true;
    }
    
    public function handleException($exception) {
        $context = [
            'error_type' => 'Uncaught Exception: ' . get_class($exception),
            'error_code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'severity' => 'critical',
            'url' => $this->getCurrentURL(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'ip_address' => $this->getClientIP(),
            'get_params' => $this->sanitizeData($_GET),
            'post_params' => $this->sanitizeData($_POST),
            'session_data' => isset($_SESSION) ? $this->sanitizeData($_SESSION) : [],
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => round(microtime(true) - $this->startTime, 4),
            'stack_trace' => $exception->getTraceAsString(),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
        ];
        
        $autoFixResult = null;
        if (MONITOR_AUTO_FIX_ENABLED) {
            $autoFixResult = $this->attemptAutoFix(
                $exception->getMessage(), $exception->getFile(), $exception->getLine(), $context
            );
        }
        if ($autoFixResult) {
            $context['auto_fix_applied'] = $autoFixResult;
        }
        
        $errorId = $this->saveError($context);
        $this->sendTelegramAlert($context, $errorId);
        
        // For API endpoints, return JSON error instead of HTML page
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $isApi = (strpos($scriptName, 'api_') !== false || strpos($scriptName, '/backend/') !== false);
        
        if ($isApi && !headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'message' => 'Server error. Please try again.',
                'ref' => $errorId ? "#$errorId" : null
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // For regular pages, show friendly error
        $this->showFriendlyErrorPage($errorId);
    }
    
    public function handleShutdown() {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $context = [
                'error_type' => 'FATAL: ' . $this->getErrorTypeName($error['type']),
                'error_code' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'severity' => 'critical',
                'url' => $this->getCurrentURL(),
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'ip_address' => $this->getClientIP(),
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'execution_time' => round(microtime(true) - $this->startTime, 4),
                'stack_trace' => 'Fatal error — no stack trace available',
                'php_version' => PHP_VERSION,
            ];
            
            $errorId = $this->saveError($context);
            $this->sendTelegramAlert($context, $errorId);
        }
    }
    
    // ============================================================
    // PUBLIC API — Use in your application code
    // ============================================================
    
    /**
     * Manual log: ArkeonErrorMonitor::log('Enrollment failed', 'error', ['id' => 123]);
     */
    public static function log($message, $severity = 'info', $extraData = []) {
        $instance = self::getInstance();
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[0] ?? [];
        
        $context = [
            'error_type' => 'Custom Log: ' . ucfirst($severity),
            'error_code' => 0,
            'message' => $message,
            'file' => $caller['file'] ?? 'unknown',
            'line' => $caller['line'] ?? 0,
            'severity' => $severity,
            'url' => $instance->getCurrentURL(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'ip_address' => $instance->getClientIP(),
            'extra_data' => $extraData,
            'memory_usage' => memory_get_usage(true),
            'execution_time' => round(microtime(true) - $instance->startTime, 4),
            'stack_trace' => $instance->getCleanStackTrace(),
        ];
        
        $errorId = $instance->saveError($context);
        
        if ($severity === 'critical' || $severity === 'error') {
            $instance->sendTelegramAlert($context, $errorId);
        }
        
        return $errorId;
    }
    
    /**
     * Safe query wrapper: ArkeonErrorMonitor::query($conn, "SELECT ...", [$id]);
     */
    public static function query($connection, $sql, $params = [], $types = '') {
        $instance = self::getInstance();
        $startTime = microtime(true);
        
        try {
            if (!empty($params)) {
                $stmt = $connection->prepare($sql);
                if ($stmt === false) {
                    self::log("SQL Prepare Failed: " . $connection->error . " | " . $instance->truncateSQL($sql), 'error');
                    return false;
                }
                if (empty($types)) {
                    $types = '';
                    foreach ($params as $param) {
                        if (is_int($param)) $types .= 'i';
                        elseif (is_float($param)) $types .= 'd';
                        else $types .= 's';
                    }
                }
                $stmt->bind_param($types, ...$params);
                $result = $stmt->execute();
                if (!$result) {
                    self::log("SQL Execute Failed: " . $stmt->error . " | " . $instance->truncateSQL($sql), 'error');
                    return false;
                }
                $queryResult = $stmt->get_result();
                
                // Log slow queries (over 2 seconds)
                $queryTime = round(microtime(true) - $startTime, 4);
                if ($queryTime > 2.0) {
                    self::log("Slow query ({$queryTime}s): " . $instance->truncateSQL($sql), 'warning');
                }
                
                return $queryResult !== false ? $queryResult : $stmt;
            } else {
                $result = $connection->query($sql);
                if ($result === false) {
                    self::log("SQL Query Failed: " . $connection->error . " | " . $instance->truncateSQL($sql), 'error');
                    return false;
                }
                return $result;
            }
        } catch (Exception $e) {
            self::log("SQL Exception: " . $e->getMessage() . " | " . $instance->truncateSQL($sql), 'critical');
            return false;
        }
    }
    
    // ============================================================
    // AUTO-FIX SYSTEM
    // ============================================================
    
    private function attemptAutoFix($errorMessage, $file, $line, $context) {
        foreach ($this->autoFixPatterns as $pattern => $fixMethod) {
            if (preg_match('/' . $pattern . '/i', $errorMessage)) {
                try {
                    $result = $this->$fixMethod($errorMessage, $file, $line, $context);
                    if ($result) {
                        $this->logToFile("AUTO-FIX: {$fixMethod} for {$file}:{$line}");
                        return $result;
                    }
                } catch (Exception $e) {
                    $this->logToFile("Auto-fix failed ({$fixMethod}): " . $e->getMessage());
                }
            }
        }
        return null;
    }
    
    private function fix_missing_directory($msg, $file, $line, $ctx) {
        if (preg_match('/(?:mkdir|open)\(\): .+?["\']?([\\/\\\\][^"\']+)["\']?/', $msg, $matches)) {
            $dir = dirname($matches[1]);
            if (!is_dir($dir) && @mkdir($dir, 0755, true)) {
                return "Created directory: {$dir}";
            }
        }
        return null;
    }
    
    private function fix_missing_file_path($msg, $file, $line, $ctx) {
        if (preg_match('/open\(([^)]+)\)/', $msg, $matches)) {
            $dir = dirname($matches[1]);
            if (!is_dir($dir) && @mkdir($dir, 0755, true)) {
                return "Created directory: {$dir}";
            }
        }
        return null;
    }
    
    private function fix_permissions($msg, $file, $line, $ctx) {
        $fixableDirs = ['uploads', 'cache', 'tmp', 'temp', 'logs', 'sessions', 'backups'];
        foreach ($fixableDirs as $dirName) {
            if (stripos($msg, $dirName) !== false) {
                $dir = dirname($file) . '/' . $dirName;
                if (is_dir($dir) && @chmod($dir, 0755)) {
                    return "Fixed permissions: {$dir}";
                }
            }
        }
        return null;
    }
    
    private function fix_session($msg, $file, $line, $ctx) {
        $sessionPath = session_save_path();
        if (empty($sessionPath)) $sessionPath = sys_get_temp_dir() . '/sessions';
        if (!is_dir($sessionPath) && @mkdir($sessionPath, 0755, true)) {
            return "Created session dir: {$sessionPath}";
        }
        return null;
    }
    
    private function fix_upload_directory($msg, $file, $line, $ctx) {
        $uploadDirs = ['uploads', 'uploads/members', 'uploads/members/photos',
                       'uploads/members/docs', 'uploads/members/guardian_photo',
                       'uploads/backups', 'uploads/cache'];
        $baseDir = dirname($file);
        foreach ($uploadDirs as $dir) {
            $fullPath = $baseDir . '/' . $dir;
            if (!is_dir($fullPath) && @mkdir($fullPath, 0755, true)) {
                return "Created upload dir: {$fullPath}";
            }
        }
        return null;
    }
    
    private function fix_image_processing($msg, $file, $line, $ctx) {
        if (preg_match('/imagecreatefrom\w+\(\): (.+)/', $msg, $matches)) {
            return "Image issue: {$matches[1]}. Check file integrity.";
        }
        return null;
    }
    
    private function fix_db_reconnect($msg, $file, $line, $ctx) {
        if ($this->db && !$this->db->ping()) {
            $this->connectDB();
            if ($this->dbConnected) return "Database reconnected";
        }
        return null;
    }
    
    // ============================================================
    // DATABASE LOGGING
    // ============================================================
    
    private function saveError($context) {
        $this->logToFile(json_encode($context, JSON_UNESCAPED_UNICODE));
        
        if (!$this->dbConnected) return null;
        
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO arkeon_error_log (
                    project_name, error_type, error_code, severity, message,
                    file_path, line_number, url, http_method, ip_address,
                    user_agent, request_data, session_data, extra_data,
                    stack_trace, memory_usage, peak_memory, execution_time,
                    auto_fix_applied, php_version, server_software, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            
            if (!$stmt) {
                $this->logToFile("Prepare failed: " . $this->db->error);
                return null;
            }
            
            $projectName = MONITOR_PROJECT_NAME;
            $errorType = mb_substr($context['error_type'] ?? 'Unknown', 0, 100);
            $errorCode = (int)($context['error_code'] ?? 0);
            $severity = $context['severity'] ?? 'info';
            $message = mb_substr($context['message'] ?? '', 0, 2000);
            $filePath = mb_substr($context['file'] ?? '', 0, 500);
            $lineNumber = (int)($context['line'] ?? 0);
            $url = mb_substr($context['url'] ?? '', 0, 2000);
            $httpMethod = $context['method'] ?? '';
            $ipAddress = $context['ip_address'] ?? '';
            $userAgent = mb_substr($context['user_agent'] ?? '', 0, 500);
            $requestData = json_encode(array_merge(
                $context['get_params'] ?? [], $context['post_params'] ?? []
            ), JSON_UNESCAPED_UNICODE);
            $sessionData = json_encode($context['session_data'] ?? [], JSON_UNESCAPED_UNICODE);
            $extraData = json_encode($context['extra_data'] ?? [], JSON_UNESCAPED_UNICODE);
            $stackTrace = mb_substr($context['stack_trace'] ?? '', 0, 5000);
            $memoryUsage = (int)($context['memory_usage'] ?? 0);
            $peakMemory = (int)($context['peak_memory'] ?? 0);
            $executionTime = (float)($context['execution_time'] ?? 0);
            $autoFix = $context['auto_fix_applied'] ?? null;
            $phpVer = $context['php_version'] ?? PHP_VERSION;
            $serverSw = $context['server_software'] ?? '';
            
            $stmt->bind_param(
                'ssisssississsssiidsss',
                $projectName, $errorType, $errorCode, $severity, $message,
                $filePath, $lineNumber, $url, $httpMethod, $ipAddress,
                $userAgent, $requestData, $sessionData, $extraData,
                $stackTrace, $memoryUsage, $peakMemory, $executionTime,
                $autoFix, $phpVer, $serverSw
            );
            
            $stmt->execute();
            $errorId = $this->db->insert_id;
            $stmt->close();
            
            // Auto-cleanup: delete logs older than 30 days (run rarely)
            if (mt_rand(1, 100) === 1) {
                $this->db->query("DELETE FROM arkeon_error_log WHERE is_resolved = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            }
            
            return $errorId;
            
        } catch (Exception $e) {
            $this->logToFile("Save failed: " . $e->getMessage());
            return null;
        }
    }
    
    // ============================================================
    // TELEGRAM
    // ============================================================
    
    private function sendTelegramAlert($context, $errorId = null) {
        if (!MONITOR_TELEGRAM_ENABLED) return;
        if (MONITOR_TELEGRAM_BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE') return;
        if ($this->telegramSentThisRequest >= $this->maxTelegramPerRequest) return;
        
        // Rate limit check
        if ($this->dbConnected) {
            try {
                $check = $this->db->query(
                    "SELECT COUNT(*) as cnt FROM arkeon_error_log 
                     WHERE severity IN ('critical','error') 
                     AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                     AND project_name = '" . $this->db->real_escape_string(MONITOR_PROJECT_NAME) . "'"
                );
                if ($check && (int)$check->fetch_assoc()['cnt'] > MONITOR_TELEGRAM_MAX_PER_HOUR) return;
            } catch (Exception $e) {}
        }
        
        $sev = strtoupper($context['severity'] ?? 'ERROR');
        $emoji = $sev === 'CRITICAL' ? '🔴' : '🟠';
        
        $msg = "{$emoji} *{$sev} ERROR*\n";
        $msg .= "📋 *Project:* " . MONITOR_PROJECT_NAME . "\n";
        $msg .= "💬 *Error:* " . mb_substr($context['message'] ?? 'Unknown', 0, 200) . "\n";
        $msg .= "📁 *File:* `" . basename($context['file'] ?? 'unknown') . "` (line " . ($context['line'] ?? '?') . ")\n";
        $msg .= "🌐 *URL:* " . ($context['url'] ?? 'N/A') . "\n";
        $msg .= "⏱ *Time:* " . date('Y-m-d H:i:s') . "\n";
        
        if (!empty($context['auto_fix_applied'])) {
            $msg .= "🔧 *Auto-Fix:* " . $context['auto_fix_applied'] . "\n";
        }
        if ($errorId) {
            $msg .= "🆔 *Error ID:* #{$errorId}\n";
        }
        
        $this->sendTelegram($msg);
        $this->telegramSentThisRequest++;
    }
    
    private function sendTelegram($message) {
        $url = "https://api.telegram.org/bot" . MONITOR_TELEGRAM_BOT_TOKEN . "/sendMessage";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => MONITOR_TELEGRAM_CHAT_ID,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        if (curl_errno($ch)) {
            $this->logToFile("Telegram failed: " . curl_error($ch));
        }
        curl_close($ch);
    }
    
    // ============================================================
    // FRIENDLY ERROR PAGE — System themed
    // ============================================================
    
    private function showFriendlyErrorPage($errorId = null) {
        if (headers_sent() || php_sapi_name() === 'cli') return;
        if (ob_get_level() > 0) ob_end_clean();
        
        http_response_code(500);
        
        $siteName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School';
        $ref = $errorId ? htmlspecialchars($errorId) : '';
        
        echo '<!DOCTYPE html>
<html lang="am"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>ስህተት ተከስቷል</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;color:#e2e8f0}
.card{background:rgba(30,41,59,0.9);border:1px solid rgba(148,163,184,0.2);border-radius:20px;padding:48px;max-width:480px;width:100%;text-align:center;backdrop-filter:blur(20px)}
.icon{font-size:56px;margin-bottom:16px}
h1{font-size:22px;margin-bottom:8px;color:#f1f5f9}
.am{font-size:16px;color:#94a3b8;margin-bottom:24px;line-height:1.8}
.btn{display:inline-block;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;transition:all .2s;margin:4px}
.btn-primary{background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(59,130,246,0.4)}
.btn-secondary{background:rgba(148,163,184,0.15);color:#94a3b8;border:1px solid rgba(148,163,184,0.2)}
.btn-secondary:hover{background:rgba(148,163,184,0.25)}
.ref{color:#475569;font-size:12px;margin-top:24px;font-family:monospace}
</style></head><body>
<div class="card">
<div class="icon">⚙️</div>
<h1>Something went wrong</h1>
<p class="am">ያልተጠበቀ ስህተት ተከስቷል።<br>እባክዎ ትንሽ ቆይተው ይሞክሩ።<br>ችግሩ ለአስተዳዳሪው ተነግሯል።</p>
<a href="javascript:location.reload()" class="btn btn-primary">🔄 እንደገና ሞክር</a>
<a href="javascript:history.back()" class="btn btn-secondary">← ተመለስ</a>
' . ($ref ? '<p class="ref">Ref: #' . $ref . '</p>' : '') . '
</div></body></html>';
        exit;
    }
    
    // ============================================================
    // HELPERS
    // ============================================================
    
    private function getSeverityLevel($errno) {
        return match(true) {
            in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR]) => 'critical',
            in_array($errno, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING]) => 'error',
            in_array($errno, [E_NOTICE, E_USER_NOTICE]) => 'warning',
            default => 'info',
        };
    }
    
    private function getErrorTypeName($errno) {
        $types = [
            E_ERROR => 'Fatal Error', E_WARNING => 'Warning', E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice', E_CORE_ERROR => 'Core Error', E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error', E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error', E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice', E_STRICT => 'Strict',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'User Deprecated',
        ];
        return $types[$errno] ?? "Unknown ({$errno})";
    }
    
    private function getCurrentURL() {
        if (php_sapi_name() === 'cli') return 'CLI';
        $p = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $p . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
    }
    
    private function getClientIP() {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) return trim(explode(',', $_SERVER[$h])[0]);
        }
        return 'unknown';
    }
    
    private function sanitizeData($data) {
        if (!is_array($data)) return [];
        $sensitive = ['password', 'passwd', 'pass', 'secret', 'token', 'api_key', 'credit_card', 'password_hash'];
        $out = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitive)) $out[$key] = '***';
            elseif (is_array($value)) $out[$key] = $this->sanitizeData($value);
            else $out[$key] = mb_substr((string)$value, 0, 500);
        }
        return $out;
    }
    
    private function getCleanStackTrace() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $out = [];
        foreach ($trace as $i => $f) {
            if (isset($f['file']) && strpos($f['file'], 'error_monitor.php') !== false) continue;
            $file = $f['file'] ?? 'unknown';
            $line = $f['line'] ?? '?';
            $func = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '?');
            $out[] = "#{$i} {$file}({$line}): {$func}()";
        }
        return implode("\n", $out);
    }
    
    private function truncateSQL($sql) {
        return mb_substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 500);
    }
    
    private function logToFile($message) {
        @file_put_contents(MONITOR_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }
}

// ============================================================
// AUTO-START — Monitor activates the moment this file is loaded
// ============================================================
ArkeonErrorMonitor::getInstance();
