<?php
/**
 * School API v1 — Database Connection
 * Loads the main config.php which establishes $conn (mysqli) and $pdo
 * Also provides helper functions for common DB operations
 */

// Prevent config.php from starting output buffering or session redirects
define('WBWS_API_REQUEST', true);
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/api/v1/index.php';

// Load main config (2 levels up from /api/v1/core/)
require_once __DIR__ . '/../../../config.php';

// Verify connection
if (!isset($conn) || !$conn || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

/**
 * Get current academic year (used by many endpoints)
 */
function getCurrentAcademicYear() {
    global $conn;
    // Delegates to the central resolver — the ACTIVE year (used for stamping).
    if (function_exists('ay_active_year')) return ay_active_year($conn);
    try {
        $r = $conn->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
        return $r ? $r->fetch_assoc() : null;
    } catch (Exception $e) { return null; }
}

/**
 * Log API activity
 */
function logApiAction($userId, $username, $action, $details = '') {
    global $conn;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('issss', $userId, $username, $action, $details, $ip);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) { /* don't break API for logging failure */ }
}
