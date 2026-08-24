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
 * - Logs privacy-minimized diagnostic context and stack traces
 * - Sends Telegram alerts for critical/error level
 * - Never mutates application files, permissions, or schema while handling errors
 * - Dashboard at /monitor/ behind Super Admin password step-up
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

// Monitor access is enforced by the account-bound admin step-up flow.
// Telegram secrets are deployment configuration, never source values.
if (!defined('MONITOR_TELEGRAM_ENABLED')) define('MONITOR_TELEGRAM_ENABLED', false);
if (!defined('MONITOR_TELEGRAM_BOT_TOKEN')) define('MONITOR_TELEGRAM_BOT_TOKEN', '');
if (!defined('MONITOR_TELEGRAM_CHAT_ID')) define('MONITOR_TELEGRAM_CHAT_ID', '');

// Error handling is observational only; remediation belongs in reviewed deployments.
if (!defined('MONITOR_AUTO_FIX_ENABLED')) define('MONITOR_AUTO_FIX_ENABLED', false);
if (!defined('MONITOR_LOG_FILE')) define('MONITOR_LOG_FILE', __DIR__ . '/error_monitor.log');

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
    private $dbConnectionAttempted = false;
    private $telegramSentThisRequest = 0;
    private $maxTelegramPerRequest = 3;
    private $startTime;
    private $startMemory;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage();
        // Only override error handling if NOT already set by main config
        // This prevents conflicts with the system's own error settings
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    private function connectDB() {
        if ($this->dbConnectionAttempted) return;
        $this->dbConnectionAttempted = true;
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
        } catch (Exception $e) {
            $this->logToFile("Monitor DB exception: " . $e->getMessage());
            $this->dbConnected = false;
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
            'message' => $this->redactText($errstr),
            'file' => $errfile,
            'line' => $errline,
            'severity' => $severity,
            'url' => $this->getCurrentURL(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'ip_address' => $this->getClientIP(),
            'request_data' => $this->getRequestMetadata(),
            'session_data' => $this->getSessionMetadata(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => round(microtime(true) - $this->startTime, 4),
            'stack_trace' => $this->getCleanStackTrace(),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
        ];
        
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
            'message' => $this->redactText($exception->getMessage()),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'severity' => 'critical',
            'url' => $this->getCurrentURL(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'ip_address' => $this->getClientIP(),
            'request_data' => $this->getRequestMetadata(),
            'session_data' => $this->getSessionMetadata(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => round(microtime(true) - $this->startTime, 4),
            'stack_trace' => $exception->getTraceAsString(),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
        ];
        
        $errorId = $this->saveError($context);
        $this->sendTelegramAlert($context, $errorId);
        
        // For API endpoints, return JSON error instead of HTML page.
        // /api/v1/index.php does NOT contain "api_" (slash), so the phone
        // was getting the Amharic HTML crash page and never marking synced.
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isApi = defined('WBWS_API_REQUEST')
            || strpos($scriptName, 'api_') !== false
            || strpos($scriptName, '/api/') !== false
            || strpos($uri, '/api/') !== false
            || strpos($scriptName, '/backend/') !== false;
        
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
                'message' => $this->redactText($error['message']),
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

            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $isApi = defined('WBWS_API_REQUEST')
                || strpos($scriptName, '/api/') !== false
                || strpos($uri, '/api/') !== false
                || strpos($scriptName, 'api_') !== false;
            if ($isApi && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Server error. Please try again.',
                    'ref' => $errorId ? "#$errorId" : null,
                ], JSON_UNESCAPED_UNICODE);
            }
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
            'message' => $instance->redactText($message),
            'file' => $caller['file'] ?? 'unknown',
            'line' => $caller['line'] ?? 0,
            'severity' => $severity,
            'url' => $instance->getCurrentURL(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'ip_address' => $instance->getClientIP(),
            'extra_data' => $instance->sanitizeData($extraData),
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
    // DATABASE LOGGING
    // ============================================================
    
    private function saveError($context) {
        $projectName = mb_substr((string)MONITOR_PROJECT_NAME, 0, 100);
        $errorType = mb_substr($this->redactText($context['error_type'] ?? 'Unknown'), 0, 100);
        $errorCode = (int)($context['error_code'] ?? 0);
        $severity = in_array(($context['severity'] ?? ''), ['info', 'warning', 'error', 'critical'], true)
            ? $context['severity'] : 'error';
        $message = mb_substr($this->redactText($context['message'] ?? ''), 0, 2000);
        $filePath = mb_substr($this->redactFilePath($context['file'] ?? ''), 0, 500);
        $lineNumber = max(0, (int)($context['line'] ?? 0));
        $url = mb_substr($this->redactText($context['url'] ?? ''), 0, 2000);
        $httpMethod = preg_match('/^[A-Z]{1,10}$/', (string)($context['method'] ?? ''))
            ? (string)$context['method'] : 'UNKNOWN';
        $ipAddress = mb_substr((string)($context['ip_address'] ?? ''), 0, 45);
        $userAgent = mb_substr($this->redactText($context['user_agent'] ?? ''), 0, 500);
        $requestData = json_encode(
            $this->sanitizeData($context['request_data'] ?? []),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';
        $sessionData = json_encode(
            $this->sanitizeData($context['session_data'] ?? []),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';
        $extraData = json_encode([
            'privacy_version' => 2,
            'data' => $this->sanitizeData($context['extra_data'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $stackTrace = mb_substr($this->redactText($context['stack_trace'] ?? ''), 0, 5000);
        $memoryUsage = max(0, (int)($context['memory_usage'] ?? 0));
        $peakMemory = max(0, (int)($context['peak_memory'] ?? 0));
        $executionTime = max(0.0, (float)($context['execution_time'] ?? 0));
        $autoFix = null; // Retained only for compatibility with existing rows/UI.
        $phpVer = mb_substr((string)($context['php_version'] ?? PHP_VERSION), 0, 20);
        $serverSw = mb_substr($this->redactText($context['server_software'] ?? ''), 0, 200);

        // The fallback file contains only a bounded, redacted summary. Request,
        // session, and custom context are never copied to filesystem logs.
        $this->logToFile(json_encode([
            'type' => $errorType,
            'severity' => $severity,
            'message' => $message,
            'file' => $filePath,
            'line' => $lineNumber,
            'url' => $url,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        // Open the separate monitor connection only when an error is actually
        // persisted; ordinary application requests incur no monitor DB work.
        if (!$this->dbConnected) $this->connectDB();
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
                $this->logToFile('Monitor schema unavailable. Apply migration 011.');
                return null;
            }

            $stmt->bind_param(
                'ssisssissssssssiidsss',
                $projectName, $errorType, $errorCode, $severity, $message,
                $filePath, $lineNumber, $url, $httpMethod, $ipAddress,
                $userAgent, $requestData, $sessionData, $extraData,
                $stackTrace, $memoryUsage, $peakMemory, $executionTime,
                $autoFix, $phpVer, $serverSw
            );
            if (!$stmt->execute()) {
                $stmt->close();
                $this->logToFile('Monitor insert failed. Verify migration 011.');
                return null;
            }
            $errorId = $this->db->insert_id;
            $stmt->close();
            return $errorId;
        } catch (Throwable $error) {
            $this->logToFile('Monitor persistence failed. Verify migration 011.');
            return null;
        }
    }
    
    // ============================================================
    // TELEGRAM
    // ============================================================
    
    private function sendTelegramAlert($context, $errorId = null) {
        if (!MONITOR_TELEGRAM_ENABLED) return;
        if (MONITOR_TELEGRAM_BOT_TOKEN === '' || MONITOR_TELEGRAM_CHAT_ID === '') return;
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
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        // Numeric/UUID-like resource identifiers are not needed to identify a route.
        $path = preg_replace('#/(?:[0-9]+|[a-f0-9-]{24,})(?=/|$)#i', '/{id}', $path);
        $fields = $this->fieldNames($_GET);
        return mb_substr($path . ($fields ? '?fields=' . implode(',', $fields) : ''), 0, 2000);
    }

    private function getClientIP() {
        $address = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }
        return 'h:' . substr($this->pseudonymize($address), 0, 24);
    }

    private function getRequestMetadata() {
        $contentType = mb_substr((string)($_SERVER['CONTENT_TYPE'] ?? ''), 0, 100);
        if (strpos($contentType, ';') !== false) {
            $contentType = trim(explode(';', $contentType, 2)[0]);
        }
        $contentLength = max(0, min((int)($_SERVER['CONTENT_LENGTH'] ?? 0), 1073741824));
        return [
            'privacy_version' => 2,
            'query_fields' => $this->fieldNames($_GET),
            'body_fields' => $this->fieldNames($_POST),
            'file_fields' => $this->fieldNames($_FILES),
            'content_type' => $contentType,
            'content_length' => $contentLength,
        ];
    }

    private function getSessionMetadata() {
        if (!isset($_SESSION) || !is_array($_SESSION)) return [];
        $metadata = [
            'privacy_version' => 2,
            'authenticated' => !empty($_SESSION['admin_logged_in']),
        ];
        if (!empty($_SESSION['admin_logged_in'])) {
            $metadata['role'] = mb_substr((string)($_SESSION['admin_role'] ?? 'unknown'), 0, 50);
            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            if ($adminId > 0) {
                $metadata['actor_ref'] = substr($this->pseudonymize((string)$adminId), 0, 20);
            }
        }
        return $metadata;
    }

    private function fieldNames($data) {
        if (!is_array($data)) return [];
        $fields = [];
        foreach (array_slice(array_keys($data), 0, 50) as $key) {
            $field = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$key);
            if ($field !== '') $fields[] = mb_substr($field, 0, 80);
        }
        return $fields;
    }

    private function pseudonymize($value) {
        $secret = defined('API_TOKEN_SECRET')
            ? (string)API_TOKEN_SECRET
            : (string)MONITOR_DB_PASS . '|' . (string)MONITOR_DB_NAME;
        return hash_hmac('sha256', (string)$value, $secret);
    }

    private function sanitizeData($data, $depth = 0) {
        if (!is_array($data) || $depth >= 4) return [];
        $out = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if ($count++ >= 50) break;
            $safeKey = mb_substr((string)$key, 0, 80);
            if (preg_match('/(?:^|_)(?:pass(?:word)?|secret|token|api_?key|csrf|cookie|session|email|phone|mobile|address|name|user_?name|full_?name|first_?name|last_?name|birth|photo|document|medical|(?:member|user|student|guardian)_?id)(?:$|_)/i', $safeKey)) {
                $out[$safeKey] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$safeKey] = $this->sanitizeData($value, $depth + 1);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $out[$safeKey] = $value;
            } else {
                $out[$safeKey] = mb_substr($this->redactText((string)$value), 0, 200);
            }
        }
        return $out;
    }

    private function redactText($text) {
        $text = (string)$text;
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer [redacted]',
            '/\b(?:password|passwd|secret|token|api[_-]?key|authorization|csrf)["\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)/i' => '[credential redacted]',
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '[email redacted]',
            '/\bDuplicate entry\s+\'[^\']*\'/i' => "Duplicate entry '[redacted]'",
            '/\b(?:phone|mobile)(?:\s+number)?\s*[:=]?\s*\+?[0-9() .-]{7,}/i' => '[phone redacted]',
            '/\b(?:eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}|[a-f0-9]{48,})\b/i' => '[token redacted]',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? '[unavailable]';
    }

    private function redactFilePath($path) {
        $path = str_replace('\\', '/', (string)$path);
        $root = defined('ROOT_PATH') ? str_replace('\\', '/', (string)ROOT_PATH) : '';
        if ($root !== '' && strpos($path, $root) === 0) {
            return '[root]' . substr($path, strlen($root));
        }
        return $this->redactText($path);
    }

    private function getCleanStackTrace() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $out = [];
        foreach ($trace as $i => $f) {
            if (isset($f['file']) && strpos($f['file'], 'error_monitor.php') !== false) continue;
            $file = $this->redactFilePath($f['file'] ?? 'unknown');
            $line = $f['line'] ?? '?';
            $func = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '?');
            $out[] = "#{$i} {$file}({$line}): {$func}()";
        }
        return implode("\n", $out);
    }

    private function truncateSQL($sql) {
        $sql = preg_replace('/\s+/', ' ', trim((string)$sql));
        return mb_substr($this->redactText($sql), 0, 500);
    }

    private function logToFile($message) {
        $handle = @fopen(MONITOR_LOG_FILE, 'c+');
        if (!$handle) return;
        try {
            if (!flock($handle, LOCK_EX)) return;
            $stats = fstat($handle);
            if (($stats['size'] ?? 0) >= 5242880) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, '[' . date('Y-m-d H:i:s') . "] log truncated at 5 MiB\n");
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, '[' . date('Y-m-d H:i:s') . '] ' . mb_substr($this->redactText($message), 0, 8000) . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

}

// ============================================================
// AUTO-START — Monitor activates the moment this file is loaded
// ============================================================
ArkeonErrorMonitor::getInstance();
