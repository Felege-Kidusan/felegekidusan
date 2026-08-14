-- ============================================================
-- WBSS/FKSS Production Patch — Database Migration
-- ============================================================
-- Run this in phpMyAdmin BEFORE deploying the code patches.
-- All statements are safe to run multiple times (idempotent).
-- ============================================================

-- 1. Add missing 'teacher' and 'attendance_taker' roles to users table
-- Without this, no user can be assigned these roles and those dashboards never load.
ALTER TABLE `users` 
MODIFY COLUMN `role` VARCHAR(30) NOT NULL DEFAULT 'info_dept'
COMMENT 'Using varchar instead of enum for flexibility. Valid: super_admin, school_admin, info_dept, edu_dept, finance_dept, material_dept, teacher, attendance_taker';

-- 2. Add last_login column if missing (referenced by api_mobile.php and school_admin dashboard)
-- This column is used to track when users last logged in.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login'
);
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `users` ADD COLUMN `last_login` DATETIME DEFAULT NULL AFTER `created_at`',
    'SELECT "last_login column already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Ensure spiritual_education column exists in members table
-- (It exists in live DB but not in schema doc — this is a safety net)
SET @col_exists2 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'spiritual_education'
);
SET @sql2 = IF(@col_exists2 = 0, 
    'ALTER TABLE `members` ADD COLUMN `spiritual_education` VARCHAR(100) DEFAULT NULL AFTER `education_level`',
    'SELECT "spiritual_education column already exists"'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 4. Ensure created_by column exists in members table
SET @col_exists3 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'created_by'
);
SET @sql3 = IF(@col_exists3 = 0, 
    'ALTER TABLE `members` ADD COLUMN `created_by` INT(10) UNSIGNED DEFAULT NULL AFTER `registered_at`',
    'SELECT "created_by column already exists"'
);
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- 5. Create activity_logs table if it doesn't exist (used by login logging)
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `username` VARCHAR(50) DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Done. Verify with: SHOW COLUMNS FROM users; SHOW COLUMNS FROM members;
