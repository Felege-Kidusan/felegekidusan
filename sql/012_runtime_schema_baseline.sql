-- Migration 012: move shared bootstrap schema repair out of request handling
-- Apply before deploying the matching config.php. Safe to rerun.

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `data` JSON DEFAULT NULL,
    `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    KEY `action` (`action`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_branding` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `asset_key` VARCHAR(50) NOT NULL,
    `asset_label` VARCHAR(100) NOT NULL DEFAULT '',
    `file_path` VARCHAR(500) DEFAULT NULL,
    `original_name` MEDIUMTEXT DEFAULT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT 0,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_asset_key` (`asset_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The following repeat-safe prepared statements upgrade databases created by
-- earlier releases. They avoid MySQL-version-specific ADD COLUMN IF NOT EXISTS.
SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='last_login'),
        'ALTER TABLE `users` ADD COLUMN `last_login` DATETIME DEFAULT NULL AFTER `is_active`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='member_id'),
        'ALTER TABLE `users` ADD COLUMN `member_id` INT UNSIGNED DEFAULT NULL AFTER `is_active`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='members')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='members' AND column_name='spiritual_education'),
        'ALTER TABLE `members` ADD COLUMN `spiritual_education` VARCHAR(100) DEFAULT NULL AFTER `education_level`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='members')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='members' AND column_name='current_class_id'),
        'ALTER TABLE `members` ADD COLUMN `current_class_id` INT UNSIGNED DEFAULT NULL', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='members')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='members' AND column_name='promoted_at'),
        'ALTER TABLE `members` ADD COLUMN `promoted_at` DATE DEFAULT NULL', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='members')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='members' AND column_name='academic_status'),
        'ALTER TABLE `members` ADD COLUMN `academic_status` VARCHAR(20) DEFAULT ''active''', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='activity_logs')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='activity_logs' AND column_name='entity_type'),
        'ALTER TABLE `activity_logs` ADD COLUMN `entity_type` VARCHAR(50) DEFAULT NULL AFTER `details`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='activity_logs')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='activity_logs' AND column_name='entity_id'),
        'ALTER TABLE `activity_logs` ADD COLUMN `entity_id` INT UNSIGNED DEFAULT NULL AFTER `entity_type`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_groups')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_groups' AND column_name='group_name_en'),
        'ALTER TABLE `wbws_groups` ADD COLUMN `group_name_en` VARCHAR(200) DEFAULT NULL AFTER `group_name`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_groups')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_groups' AND column_name='established_year_gc'),
        'ALTER TABLE `wbws_groups` ADD COLUMN `established_year_gc` VARCHAR(20) DEFAULT NULL AFTER `established_year`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_groups')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_groups' AND column_name='description'),
        'ALTER TABLE `wbws_groups` ADD COLUMN `description` TEXT DEFAULT NULL', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_groups')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_groups' AND column_name='status'),
        'ALTER TABLE `wbws_groups` ADD COLUMN `status` ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active''', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_groups')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_groups' AND column_name='updated_by'),
        'ALTER TABLE `wbws_groups` ADD COLUMN `updated_by` VARCHAR(100) DEFAULT NULL', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_groups')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_groups' AND column_name='updated_at'),
        'ALTER TABLE `wbws_groups` ADD COLUMN `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders' AND column_name='leader_full_name_en'),
        'ALTER TABLE `wbws_group_leaders` ADD COLUMN `leader_full_name_en` VARCHAR(200) DEFAULT NULL AFTER `leader_full_name`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders' AND column_name='email'),
        'ALTER TABLE `wbws_group_leaders` ADD COLUMN `email` VARCHAR(100) DEFAULT NULL AFTER `phone`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders' AND column_name='start_date'),
        'ALTER TABLE `wbws_group_leaders` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `responsibility`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders' AND column_name='end_date'),
        'ALTER TABLE `wbws_group_leaders` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders' AND column_name='is_active'),
        'ALTER TABLE `wbws_group_leaders` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wbws_group_leaders' AND column_name='updated_by'),
        'ALTER TABLE `wbws_group_leaders` ADD COLUMN `updated_by` VARCHAR(100) DEFAULT NULL', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'),
        'ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT ''info_dept''', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='members'),
        'ALTER TABLE `members` MODIFY COLUMN `age_group` VARCHAR(20) DEFAULT NULL', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='classes'),
        'ALTER TABLE `classes` MODIFY COLUMN `age_group` VARCHAR(20) DEFAULT NULL', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='system_branding'),
        'ALTER TABLE `system_branding` MODIFY COLUMN `original_name` MEDIUMTEXT NULL', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;

INSERT INTO `system_branding` (`asset_key`, `asset_label`, `file_path`)
SELECT 'logo', 'School Logo', '/admin/id_cards/assets/logos/school_logo.png'
WHERE NOT EXISTS (SELECT 1 FROM `system_branding` WHERE `asset_key`='logo');
INSERT INTO `system_branding` (`asset_key`, `asset_label`, `file_path`)
SELECT 'seal', 'School Seal / Stamp', '/admin/id_cards/assets/seals/school_seal.png'
WHERE NOT EXISTS (SELECT 1 FROM `system_branding` WHERE `asset_key`='seal');
INSERT INTO `system_branding` (`asset_key`, `asset_label`, `file_path`)
SELECT 'sig_head', 'Head Teacher Signature', '/admin/id_cards/assets/signatures/head_signature.png'
WHERE NOT EXISTS (SELECT 1 FROM `system_branding` WHERE `asset_key`='sig_head');
INSERT INTO `system_branding` (`asset_key`, `asset_label`, `file_path`)
SELECT 'sig_admin', 'Director / Admin Signature', '/admin/id_cards/assets/signatures/director_signature.png'
WHERE NOT EXISTS (SELECT 1 FROM `system_branding` WHERE `asset_key`='sig_admin');
