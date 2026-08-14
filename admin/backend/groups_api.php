<?php
/**
 * ============================================================
 * Groups API - School School Management System
 * ============================================================
 * Location: /admin/backend/groups_api.php
 * 
 * FULLY REBUILT - Bulletproof Version
 * 
 * Features:
 * - CSRF Protection on all POST requests
 * - Prepared statements everywhere (no SQL injection)
 * - Input validation and sanitization
 * - Pagination support
 * - Search/filter support
 * - Export capabilities (Excel/PDF data)
 * - Audit logging
 * - Proper error handling
 * 
 * Last Updated: February 2026
 * ============================================================
 */

// Prevent direct output of errors - ALWAYS return JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Global error handler - only throw for actual errors, NOT notices/warnings
set_error_handler(function($severity, $message, $file, $line) {
    // Only convert real errors to exceptions, skip notices and deprecations
    if ($severity & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    // Log warnings but don't crash
    if ($severity & (E_WARNING | E_USER_WARNING)) {
        error_log("Groups API Warning: $message in $file:$line");
    }
    return true; // Don't execute PHP's internal error handler
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Clear any output
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false, 
            'message' => 'Server error. Please try again or contact support.',
            'debug' => defined('DEBUG_MODE') && DEBUG_MODE ? $error['message'] : null
        ]);
    }
});

header('Content-Type: application/json; charset=utf-8');

// Clean any output buffers from config.php (prevents gzip corruption)
while (ob_get_level()) {
    ob_end_clean();
}

// Load configuration (includes session, DB, helpers)
require_once __DIR__ . '/../config.php';

// Clean again after config load (config may start ob_gzhandler)
while (ob_get_level()) {
    ob_end_clean();
}

// ============================================================
// AUTHENTICATION CHECK
// ============================================================
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// ============================================================
// SAFE COLUMN/TABLE CHECK HELPERS
// ============================================================
// PHP 8+ throws mysqli_sql_exception even with @, so we must use try/catch
function safeColumnExists($conn, $table, $column) {
    try {
        $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $r && $r->num_rows > 0;
    } catch (Exception $e) { return false; }
}

function safeTableExists($conn, $table) {
    try {
        $r = $conn->query("SHOW TABLES LIKE '$table'");
        return $r && $r->num_rows > 0;
    } catch (Exception $e) { return false; }
}

function safeQuery($conn, $sql) {
    try { return $conn->query($sql); } catch (Exception $e) { return false; }
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Send JSON response and exit
 */
function apiResponse($success, $data = null, $message = '', $meta = []) {
    $response = ['success' => $success];
    if ($data !== null) $response['data'] = $data;
    if ($message) $response['message'] = $message;
    if (!empty($meta)) $response['meta'] = $meta;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sanitize string input
 */
function cleanString($value, $maxLength = 255) {
    $value = trim($value ?? '');
    $value = strip_tags($value);
    return mb_substr($value, 0, $maxLength);
}

/**
 * Sanitize integer input
 */
function cleanInt($value, $min = 0, $max = PHP_INT_MAX) {
    $value = (int)($value ?? 0);
    return max($min, min($max, $value));
}

/**
 * Log audit action
 */
function logAudit($conn, $action, $entityType, $entityId, $details = '') {
    try {
        $userId = $_SESSION['admin_id'] ?? 0;
        $username = $_SESSION['admin_username'] ?? 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Create audit table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS `wbws_audit_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT DEFAULT NULL,
            `username` VARCHAR(100) DEFAULT NULL,
            `action` VARCHAR(50) NOT NULL,
            `entity_type` VARCHAR(50) NOT NULL,
            `entity_id` INT DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $stmt = $conn->prepare("INSERT INTO wbws_audit_log (user_id, username, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("issisis", $userId, $username, $action, $entityType, $entityId, $details, $ip);
            $stmt->execute();
        }
    } catch (Exception $e) {
        // Audit logging should never crash the main operation
    }
}

// ============================================================
// DATABASE CONNECTION CHECK
// ============================================================
if (!isset($conn) || $conn->connect_error) {
    apiResponse(false, null, 'Database connection failed');
}

// ============================================================
// ENSURE TABLES EXIST AND HAVE ALL REQUIRED COLUMNS
// Always run this - it's fast (SHOW COLUMNS is cheap) and prevents
// column-missing errors that cause save_member to fail silently
// ============================================================
    // Groups table
    $conn->query("CREATE TABLE IF NOT EXISTS `wbws_groups` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `group_name` VARCHAR(200) NOT NULL,
        `group_name_en` VARCHAR(200) DEFAULT NULL,
        `established_year` VARCHAR(20) DEFAULT NULL,
        `established_year_gc` VARCHAR(20) DEFAULT NULL,
        `is_under_sunday_school` TINYINT(1) NOT NULL DEFAULT 1,
        `founding_male` INT UNSIGNED NOT NULL DEFAULT 0,
        `founding_female` INT UNSIGNED NOT NULL DEFAULT 0,
        `current_male` INT UNSIGNED NOT NULL DEFAULT 0,
        `current_female` INT UNSIGNED NOT NULL DEFAULT 0,
        `description` TEXT DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        `created_by` VARCHAR(100) DEFAULT NULL,
        `updated_by` VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_status` (`status`),
        KEY `idx_category` (`is_under_sunday_school`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add missing columns to existing wbws_groups table
    $groupColumns = [
        ['group_name_en', "VARCHAR(200) DEFAULT NULL AFTER `group_name`"],
        ['established_year_gc', "VARCHAR(20) DEFAULT NULL AFTER `established_year`"],
        ['description', "TEXT DEFAULT NULL AFTER `current_female`"],
        ['status', "ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER `notes`"],
        ['updated_by', "VARCHAR(100) DEFAULT NULL AFTER `created_by`"],
    ];
    foreach ($groupColumns as $col) {
        if (!safeColumnExists($conn, 'wbws_groups', $col[0])) {
            safeQuery($conn, "ALTER TABLE wbws_groups ADD COLUMN `{$col[0]}` {$col[1]}");
        }
    }

    // Leaders table
    $conn->query("CREATE TABLE IF NOT EXISTS `wbws_group_leaders` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `group_id` INT UNSIGNED NOT NULL,
        `leader_full_name` VARCHAR(200) NOT NULL,
        `leader_full_name_en` VARCHAR(200) DEFAULT NULL,
        `sex` ENUM('M','F') NOT NULL DEFAULT 'M',
        `phone` VARCHAR(30) DEFAULT NULL,
        `email` VARCHAR(100) DEFAULT NULL,
        `education_level` VARCHAR(80) DEFAULT NULL,
        `responsibility` VARCHAR(150) DEFAULT NULL,
        `start_date` DATE DEFAULT NULL,
        `end_date` DATE DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `remark` TEXT DEFAULT NULL,
        `created_by` VARCHAR(100) DEFAULT NULL,
        `updated_by` VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_group` (`group_id`),
        KEY `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add missing columns to existing wbws_group_leaders table
    $leaderColumns = [
        ['leader_full_name_en', "VARCHAR(200) DEFAULT NULL AFTER `leader_full_name`"],
        ['email', "VARCHAR(100) DEFAULT NULL AFTER `phone`"],
        ['start_date', "DATE DEFAULT NULL AFTER `responsibility`"],
        ['end_date', "DATE DEFAULT NULL AFTER `start_date`"],
        ['is_active', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `end_date`"],
        ['updated_by', "VARCHAR(100) DEFAULT NULL AFTER `created_by`"],
    ];
    foreach ($leaderColumns as $col) {
        if (!safeColumnExists($conn, 'wbws_group_leaders', $col[0])) {
            safeQuery($conn, "ALTER TABLE wbws_group_leaders ADD COLUMN `{$col[0]}` {$col[1]}");
        }
    }

    // Members table
    $conn->query("CREATE TABLE IF NOT EXISTS `wbws_group_members` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `group_id` INT UNSIGNED NOT NULL,
        `full_name` VARCHAR(200) NOT NULL,
        `full_name_en` VARCHAR(200) DEFAULT NULL,
        `baptismal_name` VARCHAR(100) DEFAULT NULL,
        `gender` ENUM('M','F') NOT NULL DEFAULT 'M',
        `phone` VARCHAR(30) DEFAULT NULL,
        `email` VARCHAR(100) DEFAULT NULL,
        `date_of_birth` DATE DEFAULT NULL,
        `city` VARCHAR(80) DEFAULT NULL,
        `sub_city` VARCHAR(80) DEFAULT NULL,
        `woreda` VARCHAR(30) DEFAULT NULL,
        `house_number` VARCHAR(30) DEFAULT NULL,
        `education_level` VARCHAR(80) DEFAULT NULL,
        `occupation` VARCHAR(100) DEFAULT NULL,
        `joined_date` DATE DEFAULT NULL,
        `membership_status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `notes` TEXT DEFAULT NULL,
        `photo_path` VARCHAR(300) DEFAULT NULL,
        `created_by` VARCHAR(100) DEFAULT NULL,
        `updated_by` VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_group` (`group_id`),
        KEY `idx_status` (`membership_status`),
        KEY `idx_name` (`full_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add missing columns to existing wbws_group_members table
    $memberColumns = [
        ['full_name_en', "VARCHAR(200) DEFAULT NULL AFTER `full_name`"],
        ['email', "VARCHAR(100) DEFAULT NULL AFTER `phone`"],
        ['date_of_birth', "DATE DEFAULT NULL AFTER `email`"],
        ['occupation', "VARCHAR(100) DEFAULT NULL AFTER `education_level`"],
        ['joined_date', "DATE DEFAULT NULL AFTER `occupation`"],
        ['membership_status', "ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active' AFTER `joined_date`"],
        ['photo_path', "VARCHAR(300) DEFAULT NULL AFTER `notes`"],
        ['updated_by', "VARCHAR(100) DEFAULT NULL AFTER `created_by`"],
    ];
    foreach ($memberColumns as $col) {
        if (!safeColumnExists($conn, 'wbws_group_members', $col[0])) {
            safeQuery($conn, "ALTER TABLE wbws_group_members ADD COLUMN `{$col[0]}` {$col[1]}");
        }
    }
    
    // Fix existing columns that may lack DEFAULT values (causes 'cannot be null' errors)
    safeQuery($conn, "ALTER TABLE wbws_group_members MODIFY COLUMN `membership_status` ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active'");
    safeQuery($conn, "ALTER TABLE wbws_group_members MODIFY COLUMN `gender` ENUM('M','F') NOT NULL DEFAULT 'M'");
    safeQuery($conn, "ALTER TABLE wbws_group_leaders MODIFY COLUMN `sex` ENUM('M','F') NOT NULL DEFAULT 'M'");
    
    // Tables/columns verified - ready to handle requests

// ============================================================
// REQUEST HANDLING
// ============================================================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$username = $_SESSION['admin_username'] ?? 'system';

// CSRF validation for POST requests
if ($method === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrfToken)) {
        http_response_code(403);
        apiResponse(false, null, 'CSRF token missing from request. Make sure the form includes csrf_token field.');
    }
    if (empty($_SESSION['csrf_token'])) {
        http_response_code(403);
        apiResponse(false, null, 'Session expired (no server CSRF token). Please refresh the page and login again.');
    }
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        apiResponse(false, null, 'CSRF token mismatch. Session may have expired. Please refresh the page.');
    }
}

// ============================================================
// DIAGNOSTIC ENDPOINT (helps debug issues from browser)
// Visit: backend/groups_api.php?action=diagnose
// ============================================================
if ($action === 'diagnose') {
    $diag = [
        'php_version' => PHP_VERSION,
        'session_active' => session_status() === PHP_SESSION_ACTIVE,
        'session_id' => session_id() ? substr(session_id(), 0, 8) . '...' : 'NONE',
        'user_logged_in' => isLoggedIn(),
        'username' => $username,
        'csrf_token_exists' => !empty($_SESSION['csrf_token']),
        'csrf_token_preview' => !empty($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 8) . '...' : 'NONE',
        'db_connected' => isset($conn) && !$conn->connect_error,
        'ob_level' => ob_get_level(),
        'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_secure' => ini_get('session.cookie_secure'),
        'cookie_samesite' => ini_get('session.cookie_samesite'),
    ];
    
    // Check tables
    $tables = ['wbws_groups', 'wbws_group_leaders', 'wbws_group_members'];
    foreach ($tables as $t) {
        $diag['table_' . $t] = safeTableExists($conn, $t) ? 'EXISTS' : 'MISSING';
    }
    
    // Check key columns in members table
    if (safeTableExists($conn, 'wbws_group_members')) {
        $keyCols = ['group_id','full_name','full_name_en','baptismal_name','gender','phone','email',
                     'date_of_birth','city','sub_city','woreda','house_number','education_level',
                     'occupation','joined_date','membership_status','notes','created_by'];
        $missing = [];
        foreach ($keyCols as $col) {
            if (!safeColumnExists($conn, 'wbws_group_members', $col)) $missing[] = $col;
        }
        $diag['members_missing_columns'] = $missing ?: 'NONE - all columns present';
    }
    
    apiResponse(true, $diag, 'Diagnostic info');
}

// ============================================================
// GROUPS ENDPOINTS
// ============================================================

if ($action === 'list_groups') {
    try {
        $page = cleanInt($_GET['page'] ?? 1, 1);
        $limit = cleanInt($_GET['limit'] ?? 50, 1, 100);
        $offset = ($page - 1) * $limit;
        $search = cleanString($_GET['search'] ?? '', 100);
        $category = $_GET['category'] ?? 'all';
        $status = $_GET['status'] ?? 'all';
        
        // Check if columns exist (exception-safe)
        $hasStatusCol = safeColumnExists($conn, 'wbws_groups', 'status');
        $hasGroupNameEn = safeColumnExists($conn, 'wbws_groups', 'group_name_en');
        $hasCreatedAt = safeColumnExists($conn, 'wbws_groups', 'created_at');
        $hasLeaderIsActive = safeColumnExists($conn, 'wbws_group_leaders', 'is_active');
        $hasMembershipStatus = safeColumnExists($conn, 'wbws_group_members', 'membership_status');
        
        // Check if group tables exist at all
        $groupsTableExists = safeTableExists($conn, 'wbws_groups');
        if (!$groupsTableExists) {
            apiResponse(true, [], '', ['total' => 0, 'page' => 1, 'limit' => $limit, 'pages' => 0]);
        }
        
        $leadersTableExists = safeTableExists($conn, 'wbws_group_leaders');
        $membersTableExists = safeTableExists($conn, 'wbws_group_members');
        
        $where = [];
        $params = [];
        $types = '';
        
        if ($search) {
            if ($hasGroupNameEn) {
                $where[] = "(g.group_name LIKE ? OR g.group_name_en LIKE ? OR g.notes LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= 'sss';
            } else {
                $where[] = "(g.group_name LIKE ? OR g.notes LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= 'ss';
            }
        }
        
        if ($category === 'ss') {
            $where[] = "g.is_under_sunday_school = 1";
        } elseif ($category === 'parish') {
            $where[] = "g.is_under_sunday_school = 0";
        }
        
        if ($hasStatusCol && $status === 'active') {
            $where[] = "g.status = 'active'";
        } elseif ($hasStatusCol && $status === 'inactive') {
            $where[] = "g.status = 'inactive'";
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Count total
        $countSql = "SELECT COUNT(*) as total FROM wbws_groups g $whereClause";
        if (!empty($params)) {
            $stmt = $conn->prepare($countSql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
        } else {
            $result = $conn->query($countSql);
            $total = $result ? $result->fetch_assoc()['total'] : 0;
        }
    
    // Build leader/member count subqueries based on available columns and tables
    if ($leadersTableExists) {
        $leaderCountSql = $hasLeaderIsActive 
            ? "(SELECT COUNT(*) FROM wbws_group_leaders WHERE group_id = g.id AND is_active = 1)"
            : "(SELECT COUNT(*) FROM wbws_group_leaders WHERE group_id = g.id)";
    } else {
        $leaderCountSql = "0";
    }
    
    if ($membersTableExists) {
        // Check what columns exist in wbws_group_members
        $hasIsActive = safeColumnExists($conn, 'wbws_group_members', 'is_active');
        
        if ($hasMembershipStatus) {
            $memberCountSql = "(SELECT COUNT(*) FROM wbws_group_members WHERE group_id = g.id AND membership_status = 'active')";
        } elseif ($hasIsActive) {
            $memberCountSql = "(SELECT COUNT(*) FROM wbws_group_members WHERE group_id = g.id AND is_active = 1)";
        } else {
            $memberCountSql = "(SELECT COUNT(*) FROM wbws_group_members WHERE group_id = g.id)";
        }
    } else {
        $memberCountSql = "0";
    }
    
    // Determine ORDER BY clause based on available columns
    $orderBy = $hasCreatedAt ? "ORDER BY g.created_at DESC" : "ORDER BY g.id DESC";
    
    // Fetch groups
    $sql = "SELECT g.*, 
            $leaderCountSql as leader_count,
            $memberCountSql as member_count
            FROM wbws_groups g 
            $whereClause 
            $orderBy 
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        apiResponse(false, null, 'SQL Error: ' . $conn->error);
    }
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $row['founding_total'] = (int)($row['founding_male'] ?? 0) + (int)($row['founding_female'] ?? 0);
        $row['current_total'] = (int)($row['current_male'] ?? 0) + (int)($row['current_female'] ?? 0);
        $groups[] = $row;
    }
    
    apiResponse(true, $groups, '', [
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ]);
    
    } catch (Exception $e) {
        apiResponse(false, null, 'Error: ' . $e->getMessage());
    }
}

if ($action === 'get_group') {
    $id = cleanInt($_GET['id'] ?? 0, 1);
    if ($id <= 0) apiResponse(false, null, 'Invalid group ID');
    
    $stmt = $conn->prepare("SELECT * FROM wbws_groups WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    
    if (!$group) apiResponse(false, null, 'Group not found');
    apiResponse(true, $group);
}

if ($action === 'save_group' && $method === 'POST') {
    try {
    $id = cleanInt($_POST['id'] ?? 0);
    $name = cleanString($_POST['group_name'] ?? '', 200);
    $nameEn = cleanString($_POST['group_name_en'] ?? '', 200);
    $underSS = isset($_POST['is_under_sunday_school']) ? 1 : 0;
    $estYear = cleanString($_POST['established_year'] ?? '', 20);
    $estYearGc = cleanString($_POST['established_year_gc'] ?? '', 20);
    $foundingMale = cleanInt($_POST['founding_male'] ?? 0, 0, 100000);
    $foundingFemale = cleanInt($_POST['founding_female'] ?? 0, 0, 100000);
    $currentMale = cleanInt($_POST['current_male'] ?? 0, 0, 100000);
    $currentFemale = cleanInt($_POST['current_female'] ?? 0, 0, 100000);
    $description = cleanString($_POST['description'] ?? '', 2000);
    $notes = cleanString($_POST['notes'] ?? '', 2000);
    $status = isset($_POST['status']) && in_array($_POST['status'], ['active', 'inactive']) ? $_POST['status'] : 'active';
    
    if (empty($name)) {
        apiResponse(false, null, 'Group name is required');
    }
    
    // Check duplicate
    $checkSql = $id > 0 
        ? "SELECT id FROM wbws_groups WHERE group_name = ? AND id != ?" 
        : "SELECT id FROM wbws_groups WHERE group_name = ?";
    $checkStmt = $conn->prepare($checkSql);
    if ($id > 0) {
        $checkStmt->bind_param("si", $name, $id);
    } else {
        $checkStmt->bind_param("s", $name);
    }
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        apiResponse(false, null, 'A group with this name already exists');
    }
    
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE wbws_groups SET 
            group_name = ?, group_name_en = ?, is_under_sunday_school = ?, 
            established_year = ?, established_year_gc = ?,
            founding_male = ?, founding_female = ?, current_male = ?, current_female = ?,
            description = ?, notes = ?, status = ?, updated_by = ?
            WHERE id = ?");
        $stmt->bind_param("ssissiiiissssi", 
            $name, $nameEn, $underSS, $estYear, $estYearGc,
            $foundingMale, $foundingFemale, $currentMale, $currentFemale,
            $description, $notes, $status, $username, $id);
        
        if ($stmt->execute()) {
            logAudit($conn, 'update', 'group', $id, "Updated group: $name");
            apiResponse(true, ['id' => $id], 'Group updated successfully');
        } else {
            apiResponse(false, null, 'Failed to update group');
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO wbws_groups 
            (group_name, group_name_en, is_under_sunday_school, established_year, established_year_gc,
             founding_male, founding_female, current_male, current_female, description, notes, status, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssissiiiissss", 
            $name, $nameEn, $underSS, $estYear, $estYearGc,
            $foundingMale, $foundingFemale, $currentMale, $currentFemale,
            $description, $notes, $status, $username);
        
        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            logAudit($conn, 'create', 'group', $newId, "Created group: $name");
            apiResponse(true, ['id' => $newId], 'Group created successfully');
        } else {
            apiResponse(false, null, 'Failed to create group');
        }
    }
    } catch (Exception $e) {
        apiResponse(false, null, 'Error saving group: ' . $e->getMessage());
    }
}

if ($action === 'delete_group' && $method === 'POST') {
    $id = cleanInt($_POST['id'] ?? 0, 1);
    if ($id <= 0) apiResponse(false, null, 'Invalid group ID');
    
    $stmt = $conn->prepare("SELECT group_name FROM wbws_groups WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $groupName = $group['group_name'] ?? 'Unknown';
    
    // Delete leaders first (no FK constraint in older versions)
    $stmt = $conn->prepare("DELETE FROM wbws_group_leaders WHERE group_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    // Delete members
    $stmt = $conn->prepare("DELETE FROM wbws_group_members WHERE group_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    // Delete group
    $stmt = $conn->prepare("DELETE FROM wbws_groups WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        logAudit($conn, 'delete', 'group', $id, "Deleted group: $groupName");
        apiResponse(true, null, 'Group deleted successfully');
    } else {
        apiResponse(false, null, 'Failed to delete group');
    }
}

if ($action === 'get_stats') {
    $stats = [];
    
    // Use safeQuery to handle missing columns gracefully
    $hasStatus = safeColumnExists($conn, 'wbws_groups', 'status');
    
    $statusFilter = $hasStatus ? "WHERE status = 'active'" : "";
    $statusFilterAnd = $hasStatus ? "AND status = 'active'" : "";
    
    $r = safeQuery($conn, "SELECT COUNT(*) as cnt FROM wbws_groups $statusFilter");
    $stats['total_groups'] = $r && $r !== true ? (int)$r->fetch_assoc()['cnt'] : 0;
    
    $r = safeQuery($conn, "SELECT COUNT(*) as cnt FROM wbws_groups WHERE is_under_sunday_school = 1 $statusFilterAnd");
    $stats['under_ss'] = $r && $r !== true ? (int)$r->fetch_assoc()['cnt'] : 0;
    
    $stats['parish'] = $stats['total_groups'] - $stats['under_ss'];
    
    $hasLeaderActive = safeColumnExists($conn, 'wbws_group_leaders', 'is_active');
    $leaderFilter = $hasLeaderActive ? "WHERE is_active = 1" : "";
    $r = safeQuery($conn, "SELECT COUNT(*) as cnt FROM wbws_group_leaders $leaderFilter");
    $stats['total_leaders'] = $r && $r !== true ? (int)$r->fetch_assoc()['cnt'] : 0;
    
    $hasMemberStatus = safeColumnExists($conn, 'wbws_group_members', 'membership_status');
    $memberFilter = $hasMemberStatus ? "WHERE membership_status = 'active'" : "";
    $r = safeQuery($conn, "SELECT COUNT(*) as cnt FROM wbws_group_members $memberFilter");
    $stats['total_members'] = $r && $r !== true ? (int)$r->fetch_assoc()['cnt'] : 0;
    
    $r = safeQuery($conn, "SELECT SUM(current_male) as m, SUM(current_female) as f FROM wbws_groups $statusFilter");
    $row = ($r && $r !== true) ? $r->fetch_assoc() : ['m' => 0, 'f' => 0];
    $stats['total_male'] = (int)($row['m'] ?? 0);
    $stats['total_female'] = (int)($row['f'] ?? 0);
    
    apiResponse(true, $stats);
}

// ============================================================
// LEADERS ENDPOINTS
// ============================================================

if ($action === 'list_leaders') {
    $groupId = cleanInt($_GET['group_id'] ?? 0);
    $activeOnly = ($_GET['active_only'] ?? '1') === '1';
    
    $sql = "SELECT * FROM wbws_group_leaders WHERE group_id = ?";
    if ($activeOnly) $sql .= " AND is_active = 1";
    $sql .= " ORDER BY responsibility, leader_full_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    
    $leaders = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $leaders[] = $row;
    }
    
    apiResponse(true, $leaders);
}

if ($action === 'get_leader') {
    $id = cleanInt($_GET['id'] ?? 0, 1);
    if ($id <= 0) apiResponse(false, null, 'Invalid leader ID');
    
    $stmt = $conn->prepare("SELECT * FROM wbws_group_leaders WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $leader = $stmt->get_result()->fetch_assoc();
    
    if (!$leader) apiResponse(false, null, 'Leader not found');
    apiResponse(true, $leader);
}

if ($action === 'save_leader' && $method === 'POST') {
    try {
    $id = cleanInt($_POST['id'] ?? 0);
    $groupId = cleanInt($_POST['group_id'] ?? 0, 1);
    $name = cleanString($_POST['leader_full_name'] ?? '', 200);
    $nameEn = cleanString($_POST['leader_full_name_en'] ?? '', 200);
    $rawSex = $_POST['sex'] ?? 'M';
    $sex = in_array($rawSex, ['M', 'F']) ? $rawSex : 'M';
    $phone = cleanString($_POST['phone'] ?? '', 30);
    $email = cleanString($_POST['email'] ?? '', 100);
    $education = cleanString($_POST['education_level'] ?? '', 80);
    $responsibility = cleanString($_POST['responsibility'] ?? '', 150);
    $startDate = $_POST['start_date'] ?? null;
    $endDate = $_POST['end_date'] ?? null;
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $remark = cleanString($_POST['remark'] ?? '', 1000);
    
    if (empty($name)) apiResponse(false, null, 'Leader name is required');
    if ($groupId <= 0) apiResponse(false, null, 'Group is required');
    
    $startDate = (!empty($startDate) && strtotime($startDate)) ? $startDate : null;
    $endDate = (!empty($endDate) && strtotime($endDate)) ? $endDate : null;
    
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE wbws_group_leaders SET 
            leader_full_name = ?, leader_full_name_en = ?, sex = ?, phone = ?, email = ?,
            education_level = ?, responsibility = ?, start_date = ?, end_date = ?, 
            is_active = ?, remark = ?, updated_by = ?
            WHERE id = ?");
        $stmt->bind_param("sssssssssissi", 
            $name, $nameEn, $sex, $phone, $email, $education, $responsibility,
            $startDate, $endDate, $isActive, $remark, $username, $id);
        
        if ($stmt->execute()) {
            logAudit($conn, 'update', 'leader', $id, "Updated leader: $name");
            apiResponse(true, ['id' => $id], 'Leader updated successfully');
        } else {
            apiResponse(false, null, 'Failed to update leader');
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO wbws_group_leaders 
            (group_id, leader_full_name, leader_full_name_en, sex, phone, email, education_level, 
             responsibility, start_date, end_date, is_active, remark, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssssssiss", 
            $groupId, $name, $nameEn, $sex, $phone, $email, $education,
            $responsibility, $startDate, $endDate, $isActive, $remark, $username);
        
        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            logAudit($conn, 'create', 'leader', $newId, "Added leader: $name");
            apiResponse(true, ['id' => $newId], 'Leader added successfully');
        } else {
            apiResponse(false, null, 'Failed to add leader: ' . $stmt->error);
        }
    }
    } catch (Exception $e) {
        apiResponse(false, null, 'Error saving leader: ' . $e->getMessage());
    }
}

if ($action === 'delete_leader' && $method === 'POST') {
    $id = cleanInt($_POST['id'] ?? 0, 1);
    if ($id <= 0) apiResponse(false, null, 'Invalid leader ID');
    
    $stmt = $conn->prepare("SELECT leader_full_name FROM wbws_group_leaders WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $leader = $stmt->get_result()->fetch_assoc();
    $leaderName = $leader['leader_full_name'] ?? 'Unknown';
    
    $stmt = $conn->prepare("DELETE FROM wbws_group_leaders WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        logAudit($conn, 'delete', 'leader', $id, "Removed leader: $leaderName");
        apiResponse(true, null, 'Leader removed successfully');
    } else {
        apiResponse(false, null, 'Failed to remove leader');
    }
}

// ============================================================
// MEMBERS ENDPOINTS
// ============================================================

if ($action === 'list_members') {
    $groupId = cleanInt($_GET['group_id'] ?? 0);
    $page = cleanInt($_GET['page'] ?? 1, 1);
    $limit = cleanInt($_GET['limit'] ?? 100, 1, 500);
    $offset = ($page - 1) * $limit;
    $search = cleanString($_GET['search'] ?? '', 100);
    $status = $_GET['status'] ?? 'active';
    $gender = $_GET['gender'] ?? 'all';
    
    $where = ["group_id = ?"];
    $params = [$groupId];
    $types = 'i';
    
    if ($status !== 'all') {
        $where[] = "membership_status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    if ($gender !== 'all' && in_array($gender, ['M', 'F'])) {
        $where[] = "gender = ?";
        $params[] = $gender;
        $types .= 's';
    }
    
    if ($search) {
        $where[] = "(full_name LIKE ? OR baptismal_name LIKE ? OR phone LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'sss';
    }
    
    $whereClause = implode(' AND ', $where);
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM wbws_group_members WHERE $whereClause");
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    $sql = "SELECT * FROM wbws_group_members WHERE $whereClause ORDER BY full_name LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    $members = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    
    apiResponse(true, $members, '', [
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ]);
}

if ($action === 'get_member') {
    $id = cleanInt($_GET['id'] ?? 0, 1);
    if ($id <= 0) apiResponse(false, null, 'Invalid member ID');
    
    $stmt = $conn->prepare("SELECT * FROM wbws_group_members WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    
    if (!$member) apiResponse(false, null, 'Member not found');
    apiResponse(true, $member);
}

if ($action === 'save_member' && $method === 'POST') {
    try {
        $id = cleanInt($_POST['id'] ?? 0);
        $groupId = cleanInt($_POST['group_id'] ?? 0, 1);
        $name = cleanString($_POST['full_name'] ?? '', 200);
        $nameEn = cleanString($_POST['full_name_en'] ?? '', 200);
        $baptismal = cleanString($_POST['baptismal_name'] ?? '', 100);
        $rawGender = $_POST['gender'] ?? 'M';
        $gender = in_array($rawGender, ['M', 'F']) ? $rawGender : 'M';
        $phone = cleanString($_POST['phone'] ?? '', 30);
        $email = cleanString($_POST['email'] ?? '', 100);
        $dob = $_POST['date_of_birth'] ?? null;
        $city = cleanString($_POST['city'] ?? '', 80);
        $subCity = cleanString($_POST['sub_city'] ?? '', 80);
        $woreda = cleanString($_POST['woreda'] ?? '', 30);
        $house = cleanString($_POST['house_number'] ?? '', 30);
        $education = cleanString($_POST['education_level'] ?? '', 80);
        $occupation = cleanString($_POST['occupation'] ?? '', 100);
        $joinedDate = $_POST['joined_date'] ?? null;
        $rawStatus = $_POST['membership_status'] ?? 'active';
        $status = in_array($rawStatus, ['active', 'inactive', 'suspended']) ? $rawStatus : 'active';
        $notes = cleanString($_POST['notes'] ?? '', 2000);
        
        if (empty($name)) apiResponse(false, null, 'Member name is required');
        if ($groupId <= 0) apiResponse(false, null, 'Group ID is required');
        
        // Verify the group actually exists
        $checkGroup = $conn->prepare("SELECT id FROM wbws_groups WHERE id = ?");
        $checkGroup->bind_param("i", $groupId);
        $checkGroup->execute();
        if (!$checkGroup->get_result()->fetch_assoc()) {
            apiResponse(false, null, 'Group not found (ID: ' . $groupId . ')');
        }
        
        // Sanitize dates - empty strings become null
        $dob = (!empty($dob) && strtotime($dob)) ? $dob : null;
        $joinedDate = (!empty($joinedDate) && strtotime($joinedDate)) ? $joinedDate : null;
        
        if ($id > 0) {
            // UPDATE existing member
            $sql = "UPDATE wbws_group_members SET 
                full_name = ?, full_name_en = ?, baptismal_name = ?, gender = ?, phone = ?, email = ?,
                date_of_birth = ?, city = ?, sub_city = ?, woreda = ?, house_number = ?,
                education_level = ?, occupation = ?, joined_date = ?, membership_status = ?, notes = ?, updated_by = ?
                WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                apiResponse(false, null, 'Database prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("sssssssssssssssssi", 
                $name, $nameEn, $baptismal, $gender, $phone, $email, $dob,
                $city, $subCity, $woreda, $house, $education, $occupation,
                $joinedDate, $status, $notes, $username, $id);
            
            if ($stmt->execute()) {
                logAudit($conn, 'update', 'group_member', $id, "Updated member: $name");
                apiResponse(true, ['id' => $id], 'Member updated successfully');
            } else {
                apiResponse(false, null, 'Failed to update: ' . $stmt->error);
            }
        } else {
            // INSERT new member
            $sql = "INSERT INTO wbws_group_members 
                (group_id, full_name, full_name_en, baptismal_name, gender, phone, email, date_of_birth,
                 city, sub_city, woreda, house_number, education_level, occupation, joined_date, membership_status, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                // Prepare failed - probably a missing column. Try to fix and retry.
                $prepError = $conn->error;
                
                // Force re-check columns
                $requiredCols = [
                    'full_name_en' => "VARCHAR(200) DEFAULT NULL AFTER `full_name`",
                    'email' => "VARCHAR(100) DEFAULT NULL AFTER `phone`",
                    'date_of_birth' => "DATE DEFAULT NULL AFTER `email`",
                    'occupation' => "VARCHAR(100) DEFAULT NULL AFTER `education_level`",
                    'joined_date' => "DATE DEFAULT NULL AFTER `occupation`",
                    'membership_status' => "ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active' AFTER `joined_date`",
                    'photo_path' => "VARCHAR(300) DEFAULT NULL AFTER `notes`",
                    'updated_by' => "VARCHAR(100) DEFAULT NULL AFTER `created_by`",
                ];
                foreach ($requiredCols as $col => $def) {
                    if (!safeColumnExists($conn, 'wbws_group_members', $col)) {
                        safeQuery($conn, "ALTER TABLE wbws_group_members ADD COLUMN `$col` $def");
                    }
                }
                
                // Retry prepare
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    apiResponse(false, null, 'Database error: ' . $prepError . ' (auto-fix attempted but failed: ' . $conn->error . ')');
                }
            }
            
            $stmt->bind_param("isssssssssssssssss", 
                $groupId, $name, $nameEn, $baptismal, $gender, $phone, $email, $dob,
                $city, $subCity, $woreda, $house, $education, $occupation,
                $joinedDate, $status, $notes, $username);
            
            if ($stmt->execute()) {
                $newId = $conn->insert_id;
                logAudit($conn, 'create', 'group_member', $newId, "Added member: $name");
                apiResponse(true, ['id' => $newId], 'Member added successfully');
            } else {
                apiResponse(false, null, 'Failed to add member: ' . $stmt->error);
            }
        }
    } catch (Exception $e) {
        apiResponse(false, null, 'Error saving member: ' . $e->getMessage());
    }
}

if ($action === 'delete_member' && $method === 'POST') {
    $id = cleanInt($_POST['id'] ?? 0, 1);
    $permanent = ($_POST['permanent'] ?? '0') === '1';
    
    if ($id <= 0) apiResponse(false, null, 'Invalid member ID');
    
    $stmt = $conn->prepare("SELECT full_name FROM wbws_group_members WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $memberName = $member['full_name'] ?? 'Unknown';
    
    if ($permanent) {
        $stmt = $conn->prepare("DELETE FROM wbws_group_members WHERE id = ?");
        $stmt->bind_param("i", $id);
        $actionType = 'delete';
        $message = 'Member permanently deleted';
    } else {
        $stmt = $conn->prepare("UPDATE wbws_group_members SET membership_status = 'inactive', updated_by = ? WHERE id = ?");
        $stmt->bind_param("si", $username, $id);
        $actionType = 'deactivate';
        $message = 'Member deactivated';
    }
    
    if ($stmt->execute()) {
        logAudit($conn, $actionType, 'group_member', $id, "$message: $memberName");
        apiResponse(true, null, $message);
    } else {
        apiResponse(false, null, 'Failed to remove member');
    }
}

if ($action === 'bulk_add_members' && $method === 'POST') {
    $groupId = cleanInt($_POST['group_id'] ?? 0, 1);
    $membersJson = $_POST['members'] ?? '[]';
    
    if ($groupId <= 0) apiResponse(false, null, 'Group is required');
    
    $members = json_decode($membersJson, true);
    if (!is_array($members) || empty($members)) {
        apiResponse(false, null, 'No members provided');
    }
    
    $added = 0;
    $errors = [];
    
    $stmt = $conn->prepare("INSERT INTO wbws_group_members 
        (group_id, full_name, gender, phone, membership_status, created_by) 
        VALUES (?, ?, ?, ?, 'active', ?)");
    
    foreach ($members as $i => $m) {
        $name = cleanString($m['full_name'] ?? '', 200);
        $gender = in_array($m['gender'] ?? 'M', ['M', 'F']) ? $m['gender'] : 'M';
        $phone = cleanString($m['phone'] ?? '', 30);
        
        if (empty($name)) {
            $errors[] = "Row " . ($i + 1) . ": Name is required";
            continue;
        }
        
        $stmt->bind_param("issss", $groupId, $name, $gender, $phone, $username);
        if ($stmt->execute()) {
            $added++;
        } else {
            $errors[] = "Row " . ($i + 1) . ": Failed to add";
        }
    }
    
    logAudit($conn, 'bulk_add', 'group_member', $groupId, "Bulk added $added members");
    
    apiResponse(true, ['added' => $added, 'errors' => $errors], 
        "Added $added members" . (count($errors) > 0 ? " with " . count($errors) . " errors" : ""));
}

// ============================================================
// EXPORT ENDPOINTS
// ============================================================

if ($action === 'export_group') {
    $groupId = cleanInt($_GET['group_id'] ?? 0, 1);
    if ($groupId <= 0) apiResponse(false, null, 'Invalid group ID');
    
    $stmt = $conn->prepare("SELECT * FROM wbws_groups WHERE id = ?");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    
    if (!$group) apiResponse(false, null, 'Group not found');
    
    $data = ['group' => $group, 'leaders' => [], 'members' => []];
    
    $stmt = $conn->prepare("SELECT * FROM wbws_group_leaders WHERE group_id = ? AND is_active = 1 ORDER BY responsibility");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $data['leaders'][] = $row;
    }
    
    $stmt = $conn->prepare("SELECT * FROM wbws_group_members WHERE group_id = ? AND membership_status = 'active' ORDER BY full_name");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $data['members'][] = $row;
    }
    
    apiResponse(true, $data);
}

if ($action === 'export_all_groups') {
    $sql = "SELECT g.*, 
            (SELECT COUNT(*) FROM wbws_group_leaders WHERE group_id = g.id AND is_active = 1) as leader_count,
            (SELECT COUNT(*) FROM wbws_group_members WHERE group_id = g.id AND membership_status = 'active') as member_count
            FROM wbws_groups g 
            WHERE g.status = 'active'
            ORDER BY g.group_name";
    
    $result = $conn->query($sql);
    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
    
    apiResponse(true, $groups);
}

// ============================================================
// ADVANCED ANALYTICS ENDPOINT
// ============================================================
if ($action === 'get_analytics') {
    try {
        $analytics = [];
        
        // 1. Gender distribution across all groups
        $r = safeQuery($conn, "SELECT 
            SUM(current_male) as total_male, 
            SUM(current_female) as total_female,
            SUM(founding_male) as founding_male_total,
            SUM(founding_female) as founding_female_total
            FROM wbws_groups WHERE status = 'active'");
        $genderData = ($r && $r !== true) ? $r->fetch_assoc() : [];
        $analytics['gender'] = [
            'current_male' => (int)($genderData['total_male'] ?? 0),
            'current_female' => (int)($genderData['total_female'] ?? 0),
            'founding_male' => (int)($genderData['founding_male_total'] ?? 0),
            'founding_female' => (int)($genderData['founding_female_total'] ?? 0),
        ];
        $analytics['gender']['current_total'] = $analytics['gender']['current_male'] + $analytics['gender']['current_female'];
        $analytics['gender']['founding_total'] = $analytics['gender']['founding_male'] + $analytics['gender']['founding_female'];
        $analytics['gender']['growth'] = $analytics['gender']['founding_total'] > 0 
            ? round(($analytics['gender']['current_total'] - $analytics['gender']['founding_total']) / $analytics['gender']['founding_total'] * 100, 1)
            : 0;
        
        // 2. Per-group breakdown with growth rates
        $r = safeQuery($conn, "SELECT g.id, g.group_name, g.group_name_en,
            g.is_under_sunday_school, g.established_year,
            g.founding_male, g.founding_female, g.current_male, g.current_female,
            (g.founding_male + g.founding_female) as founding_total,
            (g.current_male + g.current_female) as current_total,
            (SELECT COUNT(*) FROM wbws_group_leaders WHERE group_id = g.id AND is_active = 1) as leader_count,
            (SELECT COUNT(*) FROM wbws_group_members WHERE group_id = g.id AND membership_status = 'active') as registered_members,
            (SELECT COUNT(*) FROM wbws_group_members WHERE group_id = g.id AND membership_status = 'active' AND gender = 'M') as reg_male,
            (SELECT COUNT(*) FROM wbws_group_members WHERE group_id = g.id AND membership_status = 'active' AND gender = 'F') as reg_female
            FROM wbws_groups g WHERE g.status = 'active' ORDER BY current_total DESC");
        
        $groups = [];
        if ($r && $r !== true) {
            while ($row = $r->fetch_assoc()) {
                $foundingTotal = (int)$row['founding_total'];
                $currentTotal = (int)$row['current_total'];
                $row['growth_rate'] = $foundingTotal > 0 
                    ? round(($currentTotal - $foundingTotal) / $foundingTotal * 100, 1) : 0;
                $row['male_percent'] = $currentTotal > 0 
                    ? round((int)$row['current_male'] / $currentTotal * 100, 1) : 0;
                $row['female_percent'] = $currentTotal > 0 
                    ? round((int)$row['current_female'] / $currentTotal * 100, 1) : 0;
                $groups[] = $row;
            }
        }
        $analytics['groups'] = $groups;
        
        // 3. Category distribution
        $analytics['category'] = [
            'sunday_school' => 0,
            'parish' => 0
        ];
        foreach ($groups as $g) {
            if ($g['is_under_sunday_school'] == 1) $analytics['category']['sunday_school']++;
            else $analytics['category']['parish']++;
        }
        
        // 4. Top 5 largest groups
        $analytics['top_groups'] = array_slice($groups, 0, 5);
        
        // 5. Top 5 fastest growing
        $growthSorted = $groups;
        usort($growthSorted, function($a, $b) { return $b['growth_rate'] <=> $a['growth_rate']; });
        $analytics['fastest_growing'] = array_slice($growthSorted, 0, 5);
        
        // 6. Recently added members
        $r = safeQuery($conn, "SELECT m.full_name, m.gender, m.created_at, g.group_name 
            FROM wbws_group_members m 
            JOIN wbws_groups g ON m.group_id = g.id 
            WHERE m.membership_status = 'active'
            ORDER BY m.created_at DESC LIMIT 10");
        $analytics['recent_members'] = [];
        if ($r && $r !== true) {
            while ($row = $r->fetch_assoc()) {
                $analytics['recent_members'][] = $row;
            }
        }
        
        // 7. Members per group for chart
        $analytics['member_chart_data'] = array_map(function($g) {
            return [
                'name' => mb_substr($g['group_name'], 0, 20),
                'male' => (int)$g['current_male'],
                'female' => (int)$g['current_female'],
                'registered' => (int)$g['registered_members']
            ];
        }, $groups);
        
        apiResponse(true, $analytics);
    } catch (Exception $e) {
        apiResponse(false, null, 'Analytics error: ' . $e->getMessage());
    }
}

// ============================================================
// EXPORT GROUP DETAIL FOR PDF
// ============================================================
if ($action === 'export_group_pdf') {
    $groupId = cleanInt($_GET['group_id'] ?? 0, 1);
    $includeMembers = ($_GET['include_members'] ?? '1') === '1';
    $includeLeaders = ($_GET['include_leaders'] ?? '1') === '1';
    $includeStats = ($_GET['include_stats'] ?? '1') === '1';
    
    if ($groupId <= 0) apiResponse(false, null, 'Invalid group ID');
    
    $stmt = $conn->prepare("SELECT * FROM wbws_groups WHERE id = ?");
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    
    if (!$group) apiResponse(false, null, 'Group not found');
    
    $data = ['group' => $group];
    
    if ($includeLeaders) {
        $stmt = $conn->prepare("SELECT * FROM wbws_group_leaders WHERE group_id = ? AND is_active = 1 ORDER BY responsibility, leader_full_name");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $data['leaders'] = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $data['leaders'][] = $row;
    }
    
    if ($includeMembers) {
        $stmt = $conn->prepare("SELECT * FROM wbws_group_members WHERE group_id = ? AND membership_status = 'active' ORDER BY full_name");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $data['members'] = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $data['members'][] = $row;
        
        // Member stats
        if ($includeStats) {
            $data['member_stats'] = [
                'total' => count($data['members']),
                'male' => count(array_filter($data['members'], fn($m) => $m['gender'] === 'M')),
                'female' => count(array_filter($data['members'], fn($m) => $m['gender'] === 'F')),
            ];
        }
    }
    
    logAudit($conn, 'export_pdf', 'group', $groupId, "PDF export of group: " . $group['group_name']);
    apiResponse(true, $data);
}

// Default
apiResponse(false, null, 'Unknown action: ' . htmlspecialchars($action));
