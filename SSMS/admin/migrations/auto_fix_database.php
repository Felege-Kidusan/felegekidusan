<?php
/**
 * ============================================================
 * School Management DATABASE AUTO-FIXER
 * ============================================================
 * 
 * This file automatically fixes ALL database issues.
 * 
 * HOW TO USE:
 * Option 1: Visit this URL in your browser:
 *           {SITE_URL}/admin/migrations/auto_fix_database.php
 * 
 * Option 2: Include at the top of config.php (one-time):
 *           require_once __DIR__ . '/admin/migrations/auto_fix_database.php';
 * 
 * SAFE TO RUN MULTIPLE TIMES - it only adds what's missing
 * ============================================================
 */

// Prevent timeout on slow servers
set_time_limit(120);
error_reporting(E_ALL);

// Database credentials - use config if available
if (defined('DB_HOST')) {
    $DB_HOST = DB_HOST;
    $DB_NAME = DB_NAME;
    $DB_USER = DB_USER;
    $DB_PASS = DB_PASS;
} else {
    // Fallback: load from config
    require_once __DIR__ . '/../../config.php';
    $DB_HOST = DB_HOST;
    $DB_NAME = DB_NAME;
    $DB_USER = DB_USER;
    $DB_PASS = DB_PASS;
}

// Track what we fix
$fixes = [];
$errors = [];

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    
    if ($conn->connect_error) {
        throw new Exception('Database connection failed: ' . $conn->connect_error);
    }
    
    $conn->set_charset('utf8mb4');
    
    // ============================================================
    // HELPER FUNCTIONS
    // ============================================================
    
    function tableExists($conn, $tableName) {
        $result = $conn->query("SHOW TABLES LIKE '$tableName'");
        return $result && $result->num_rows > 0;
    }
    
    function columnExists($conn, $tableName, $columnName) {
        $result = @$conn->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return $result && $result->num_rows > 0;
    }
    
    function addColumnIfMissing($conn, $table, $column, $definition, &$fixes) {
        if (!columnExists($conn, $table, $column)) {
            $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
            if ($conn->query($sql)) {
                $fixes[] = "✅ Added column `$column` to `$table`";
                return true;
            } else {
                $fixes[] = "❌ Failed to add `$column` to `$table`: " . $conn->error;
                return false;
            }
        }
        return true;
    }
    
    function createTableIfMissing($conn, $tableName, $createSQL, &$fixes) {
        if (!tableExists($conn, $tableName)) {
            if ($conn->query($createSQL)) {
                $fixes[] = "✅ Created table `$tableName`";
                return true;
            } else {
                $fixes[] = "❌ Failed to create `$tableName`: " . $conn->error;
                return false;
            }
        }
        return true;
    }
    
    // ============================================================
    // FIX 1: Create notifications table
    // ============================================================
    createTableIfMissing($conn, 'notifications', "
        CREATE TABLE `notifications` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    // ============================================================
    // FIX 2: Fix users table
    // ============================================================
    if (tableExists($conn, 'users')) {
        addColumnIfMissing($conn, 'users', 'last_login', 'DATETIME DEFAULT NULL AFTER `is_active`', $fixes);
        addColumnIfMissing($conn, 'users', 'member_id', 'INT UNSIGNED DEFAULT NULL AFTER `is_active`', $fixes);
        
        // Expand role column to support 'teacher' and other new roles
        try {
            $conn->query("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'info_dept'");
            $fixes[] = "✅ Expanded users.role to VARCHAR(50) to support teacher role";
        } catch (Exception $e) {
            // May already be correct
        }
    }
    
    // ============================================================
    // FIX 2b: Fix members table
    // ============================================================
    if (tableExists($conn, 'members')) {
        addColumnIfMissing($conn, 'members', 'spiritual_education', 'VARCHAR(100) DEFAULT NULL AFTER `education_level`', $fixes);
        
        // Fix age_group from ENUM to VARCHAR to prevent data truncation errors
        try {
            $conn->query("ALTER TABLE `members` MODIFY COLUMN `age_group` VARCHAR(20) DEFAULT NULL");
            $fixes[] = "✅ Changed members.age_group to VARCHAR(20) to prevent truncation";
        } catch (Exception $e) {
            // May already be correct
        }
    }
    
    // ============================================================
    // FIX 3: Fix wbws_groups table
    // ============================================================
    if (tableExists($conn, 'wbws_groups')) {
        addColumnIfMissing($conn, 'wbws_groups', 'group_name_en', 'VARCHAR(200) DEFAULT NULL AFTER `group_name`', $fixes);
        addColumnIfMissing($conn, 'wbws_groups', 'established_year_gc', 'VARCHAR(20) DEFAULT NULL AFTER `established_year`', $fixes);
        addColumnIfMissing($conn, 'wbws_groups', 'description', 'TEXT DEFAULT NULL', $fixes);
        addColumnIfMissing($conn, 'wbws_groups', 'status', "ENUM('active', 'inactive') NOT NULL DEFAULT 'active'", $fixes);
        addColumnIfMissing($conn, 'wbws_groups', 'updated_by', 'VARCHAR(100) DEFAULT NULL', $fixes);
        addColumnIfMissing($conn, 'wbws_groups', 'updated_at', 'DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP', $fixes);
    }
    
    // ============================================================
    // FIX 3b: Fix wbws_group_leaders table
    // ============================================================
    if (tableExists($conn, 'wbws_group_leaders')) {
        addColumnIfMissing($conn, 'wbws_group_leaders', 'leader_full_name_en', 'VARCHAR(200) DEFAULT NULL AFTER `leader_full_name`', $fixes);
        addColumnIfMissing($conn, 'wbws_group_leaders', 'email', 'VARCHAR(100) DEFAULT NULL AFTER `phone`', $fixes);
        addColumnIfMissing($conn, 'wbws_group_leaders', 'start_date', 'DATE DEFAULT NULL AFTER `responsibility`', $fixes);
        addColumnIfMissing($conn, 'wbws_group_leaders', 'end_date', 'DATE DEFAULT NULL AFTER `start_date`', $fixes);
        addColumnIfMissing($conn, 'wbws_group_leaders', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1', $fixes);
        addColumnIfMissing($conn, 'wbws_group_leaders', 'updated_by', 'VARCHAR(100) DEFAULT NULL', $fixes);
    }
    
    // ============================================================
    // FIX 4: Create/Fix teacher_assignments table
    // ============================================================
    createTableIfMissing($conn, 'teacher_assignments', "
        CREATE TABLE `teacher_assignments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `teacher_id` INT UNSIGNED NOT NULL,
            `class_id` INT UNSIGNED NOT NULL,
            `subject_id` INT UNSIGNED NOT NULL,
            `academic_year_id` INT UNSIGNED DEFAULT NULL,
            `is_class_teacher` TINYINT(1) NOT NULL DEFAULT 0,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `assigned_by` INT UNSIGNED DEFAULT NULL,
            `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `notes` TEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `teacher_id` (`teacher_id`),
            KEY `class_id` (`class_id`),
            KEY `subject_id` (`subject_id`),
            KEY `academic_year_id` (`academic_year_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    if (tableExists($conn, 'teacher_assignments')) {
        addColumnIfMissing($conn, 'teacher_assignments', 'is_primary', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_class_teacher`', $fixes);
        addColumnIfMissing($conn, 'teacher_assignments', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1', $fixes);
    }
    
    // ============================================================
    // FIX 5: Create academic_years table
    // ============================================================
    createTableIfMissing($conn, 'academic_years', "
        CREATE TABLE `academic_years` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `year_name` VARCHAR(50) NOT NULL,
            `year_gc` VARCHAR(20) DEFAULT NULL,
            `start_date` DATE DEFAULT NULL,
            `end_date` DATE DEFAULT NULL,
            `is_current` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('upcoming', 'active', 'closed') NOT NULL DEFAULT 'upcoming',
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `year_name` (`year_name`),
            KEY `is_current` (`is_current`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    // Insert default academic year
    $check = $conn->query("SELECT COUNT(*) as cnt FROM academic_years");
    if ($check && $check->fetch_assoc()['cnt'] == 0) {
        $conn->query("INSERT INTO academic_years (year_name, year_gc, is_current, status) VALUES ('2017 ዓ.ም.', '2024/2025', 1, 'active')");
        $fixes[] = "✅ Added default academic year 2017 ዓ.ም.";
    }
    
    // ============================================================
    // FIX 6: Create classes table
    // ============================================================
    createTableIfMissing($conn, 'classes', "
        CREATE TABLE `classes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `class_name` VARCHAR(100) NOT NULL,
            `class_name_en` VARCHAR(100) DEFAULT NULL,
            `class_code` VARCHAR(20) NOT NULL,
            `level_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `section` VARCHAR(50) DEFAULT NULL,
            `age_group` ENUM('under6', '7_13', '14_17', '18_plus') DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `class_code` (`class_code`),
            KEY `level_order` (`level_order`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    // ============================================================
    // FIX 7: Create subjects table
    // ============================================================
    createTableIfMissing($conn, 'subjects', "
        CREATE TABLE `subjects` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `subject_name` VARCHAR(100) NOT NULL,
            `subject_name_en` VARCHAR(100) DEFAULT NULL,
            `subject_code` VARCHAR(20) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `subject_code` (`subject_code`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    // ============================================================
    // FIX 8: Create class_enrollments table
    // ============================================================
    createTableIfMissing($conn, 'class_enrollments', "
        CREATE TABLE `class_enrollments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `member_id` INT UNSIGNED NOT NULL,
            `class_id` INT UNSIGNED NOT NULL,
            `academic_year_id` INT UNSIGNED DEFAULT NULL,
            `status` ENUM('active', 'completed', 'dropped', 'transferred') NOT NULL DEFAULT 'active',
            `enrolled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `enrolled_by` INT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `member_id` (`member_id`),
            KEY `class_id` (`class_id`),
            KEY `academic_year_id` (`academic_year_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    // ============================================================
    // FIX 9: Create attendance table
    // ============================================================
    createTableIfMissing($conn, 'attendance', "
        CREATE TABLE `attendance` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `member_id` INT UNSIGNED NOT NULL,
            `class_id` INT UNSIGNED DEFAULT NULL,
            `academic_year_id` INT UNSIGNED DEFAULT NULL,
            `attendance_date` DATE NOT NULL,
            `status` ENUM('present', 'absent', 'late', 'excused', 'holiday') NOT NULL DEFAULT 'present',
            `check_in_time` TIME DEFAULT NULL,
            `check_out_time` TIME DEFAULT NULL,
            `notes` VARCHAR(255) DEFAULT NULL,
            `recorded_by` INT UNSIGNED DEFAULT NULL,
            `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_attendance` (`member_id`, `attendance_date`),
            KEY `attendance_date` (`attendance_date`),
            KEY `status` (`status`),
            KEY `class_id` (`class_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    // ============================================================
    // FIX 10: Create activity_logs table
    // ============================================================
    createTableIfMissing($conn, 'activity_logs', "
        CREATE TABLE `activity_logs` (
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
            KEY `action` (`action`),
            KEY `entity_type` (`entity_type`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    // ============================================================
    // FIX 11: Fix members table
    // ============================================================
    if (tableExists($conn, 'members')) {
        addColumnIfMissing($conn, 'members', 'spiritual_education', 'VARCHAR(100) DEFAULT NULL AFTER `education_level`', $fixes);
    }
    
    // ============================================================
    // FIX 12: Fix wbws_group_leaders table
    // ============================================================
    createTableIfMissing($conn, 'wbws_group_leaders', "
        CREATE TABLE `wbws_group_leaders` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    if (tableExists($conn, 'wbws_group_leaders')) {
        addColumnIfMissing($conn, 'wbws_group_leaders', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1', $fixes);
        addColumnIfMissing($conn, 'wbws_group_leaders', 'leader_full_name_en', 'VARCHAR(200) DEFAULT NULL AFTER `leader_full_name`', $fixes);
    }
    
    // ============================================================
    // FIX 13: Fix wbws_group_members table
    // ============================================================
    createTableIfMissing($conn, 'wbws_group_members', "
        CREATE TABLE `wbws_group_members` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    if (tableExists($conn, 'wbws_group_members')) {
        addColumnIfMissing($conn, 'wbws_group_members', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `membership_status`', $fixes);
        addColumnIfMissing($conn, 'wbws_group_members', 'membership_status', "ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active'", $fixes);
    }
    
    // ============================================================
    // FIX 14: Create cache_storage table
    // ============================================================
    createTableIfMissing($conn, 'cache_storage', "
        CREATE TABLE `cache_storage` (
            `cache_key` VARCHAR(100) NOT NULL,
            `cache_value` LONGTEXT DEFAULT NULL,
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`cache_key`),
            KEY `expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $fixes);
    
    // ============================================================
    // FIX 15: Create audit_log table
    // ============================================================
    createTableIfMissing($conn, 'wbws_audit_log', "
        CREATE TABLE `wbws_audit_log` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ", $fixes);
    
    $conn->close();
    
} catch (Exception $e) {
    $errors[] = $e->getMessage();
}

// ============================================================
// OUTPUT RESULTS
// ============================================================
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$isCLI = php_sapi_name() === 'cli';

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => empty($errors),
        'fixes' => $fixes,
        'errors' => $errors
    ]);
    exit;
}

if ($isCLI) {
    echo "\n=== Database Auto-Fixer ===\n\n";
    foreach ($fixes as $fix) {
        echo "$fix\n";
    }
    if (!empty($errors)) {
        echo "\n--- ERRORS ---\n";
        foreach ($errors as $error) {
            echo "ERROR: $error\n";
        }
    }
    echo "\n" . (empty($errors) ? "✅ All fixes completed successfully!" : "⚠️ Some errors occurred") . "\n\n";
    exit;
}

// HTML Output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Auto-Fixer</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', system-ui, sans-serif; 
            background: linear-gradient(135deg, #047857 0%, #065f46 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 24px; margin-bottom: 8px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .content { padding: 30px; }
        .fix-item {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .fix-success { background: #d1fae5; color: #065f46; }
        .fix-error { background: #fee2e2; color: #991b1b; }
        .summary {
            margin-top: 20px;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .summary-success { background: #d1fae5; border: 2px solid #059669; }
        .summary-error { background: #fee2e2; border: 2px solid #dc2626; }
        .summary h2 { font-size: 20px; margin-bottom: 8px; }
        .back-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 24px;
            background: #059669;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
        }
        .back-btn:hover { background: #047857; }
        .no-fixes {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        .no-fixes i { font-size: 48px; color: #059669; margin-bottom: 16px; display: block; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛠️ Database Auto-Fixer</h1>
            <p>Automatically fixes missing tables and columns</p>
        </div>
        <div class="content">
            <?php if (empty($fixes) && empty($errors)): ?>
                <div class="no-fixes">
                    <span style="font-size:48px">✅</span>
                    <h2>Database is already up to date!</h2>
                    <p>No fixes were needed - everything looks good.</p>
                </div>
            <?php else: ?>
                <?php foreach ($fixes as $fix): ?>
                    <div class="fix-item <?= strpos($fix, '✅') !== false ? 'fix-success' : 'fix-error' ?>">
                        <?= htmlspecialchars($fix) ?>
                    </div>
                <?php endforeach; ?>
                
                <?php foreach ($errors as $error): ?>
                    <div class="fix-item fix-error">❌ <?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
                
                <div class="summary <?= empty($errors) ? 'summary-success' : 'summary-error' ?>">
                    <h2><?= empty($errors) ? '✅ All Fixes Applied Successfully!' : '⚠️ Some Errors Occurred' ?></h2>
                    <p><?= count(array_filter($fixes, fn($f) => strpos($f, '✅') !== false)) ?> fixes applied</p>
                </div>
            <?php endif; ?>
            
            <div style="text-align: center;">
                <a href="../dashboard.php" class="back-btn">← Back to Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>
