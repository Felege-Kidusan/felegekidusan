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

$__enrollService = __DIR__ . '/../../../admin/backend/services/EnrollmentService.php';
if (is_file($__enrollService)) {
    require_once $__enrollService;
}

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
/** Create grade_submissions if Education has not opened that page yet. */
function apiEnsureSubmissionsTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    global $conn;
    if (!$conn) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `grade_submissions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `teacher_id` INT UNSIGNED NOT NULL,
            `class_id` INT UNSIGNED NOT NULL,
            `subject_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `academic_year_id` INT UNSIGNED DEFAULT NULL,
            `term_id` INT UNSIGNED DEFAULT NULL,
            `assessment_id` INT UNSIGNED DEFAULT NULL,
            `submission_type` ENUM('marklist','attendance','report') NOT NULL DEFAULT 'marklist',
            `status` ENUM('draft','submitted','approved','rejected','revision_needed') NOT NULL DEFAULT 'draft',
            `student_count` INT UNSIGNED DEFAULT 0,
            `average_score` DECIMAL(5,2) DEFAULT NULL,
            `submitted_at` TIMESTAMP NULL DEFAULT NULL,
            `reviewed_by` INT UNSIGNED DEFAULT NULL,
            `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
            `review_notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `teacher_id` (`teacher_id`),
            KEY `class_id` (`class_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $r = $conn->query("SHOW COLUMNS FROM `academic_records` LIKE 'submission_id'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE `academic_records` ADD COLUMN `submission_id` INT UNSIGNED DEFAULT NULL AFTER `assessment_id`");
        }
    } catch (Throwable $e) { /* table may already exist */ }
}

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
