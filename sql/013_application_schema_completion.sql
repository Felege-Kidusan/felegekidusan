-- Migration 013: application-owned tables and compatibility columns
-- Replaces request-time CREATE/ALTER/SHOW schema repair in normal web/API paths.
-- Apply during deployment after migrations 001-012.

INSERT INTO `system_branding` (`asset_key`, `asset_label`, `file_path`)
SELECT 'card_bg', 'ID Card Background', '/admin/id_cards/assets/backgrounds/id_card_bg.jpg'
WHERE NOT EXISTS (SELECT 1 FROM `system_branding` WHERE `asset_key`='card_bg');

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_chat_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `role` ENUM('user','assistant') NOT NULL,
    `message` TEXT NOT NULL,
    `data_context` TEXT DEFAULT NULL COMMENT 'JSON snapshot of data sent to AI',
    `tokens_used` INT UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_provider_configs` (
    `provider` VARCHAR(40) NOT NULL,
    `api_key_enc` TEXT DEFAULT NULL,
    `model` VARCHAR(160) DEFAULT NULL,
    `base_url` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`provider`),
    KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dept_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `dept_settings` (`setting_key`,`setting_value`)
SELECT 'calendar_mode','ethiopian'
WHERE NOT EXISTS (SELECT 1 FROM `dept_settings` WHERE `setting_key`='calendar_mode');

CREATE TABLE IF NOT EXISTS `academic_terms` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `term_name` VARCHAR(50) NOT NULL,
    `term_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `academic_year_id` (`academic_year_id`),
    KEY `is_current` (`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `class_enrollments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `class_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `enrolled_at` DATE DEFAULT NULL,
    `status` ENUM('active','withdrawn','completed','transferred') NOT NULL DEFAULT 'active',
    `notes` TEXT DEFAULT NULL,
    `promoted_from` INT UNSIGNED DEFAULT NULL,
    `enrolled_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_enrollment` (`member_id`,`class_id`,`academic_year_id`),
    KEY `class_id` (`class_id`),
    KEY `academic_year_id` (`academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wbws_groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_by` VARCHAR(100) DEFAULT NULL,
    `updated_by` VARCHAR(100) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_category` (`is_under_sunday_school`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wbws_group_leaders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    PRIMARY KEY (`id`),
    KEY `idx_group` (`group_id`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wbws_group_members` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    `membership_status` ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT DEFAULT NULL,
    `photo_path` VARCHAR(300) DEFAULT NULL,
    `created_by` VARCHAR(100) DEFAULT NULL,
    `updated_by` VARCHAR(100) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_group` (`group_id`),
    KEY `idx_status` (`membership_status`),
    KEY `idx_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wbws_audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT DEFAULT NULL,
    `username` VARCHAR(100) DEFAULT NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_entity` (`entity_type`,`entity_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `academic_records` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `term_id` INT UNSIGNED DEFAULT NULL,
    `assessment_id` INT UNSIGNED DEFAULT NULL,
    `submission_id` INT UNSIGNED DEFAULT NULL,
    `assessment_type` ENUM('test','midterm','final','assignment','participation','project') NOT NULL DEFAULT 'test',
    `score` DECIMAL(5,2) DEFAULT NULL,
    `max_score` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    `grade_letter` VARCHAR(5) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `member_id` (`member_id`),
    KEY `class_id` (`class_id`),
    KEY `subject_id` (`subject_id`),
    KEY `academic_year_id` (`academic_year_id`),
    KEY `term_id` (`term_id`),
    KEY `assessment_id` (`assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `class_id` INT UNSIGNED DEFAULT NULL,
    `academic_year_id` INT UNSIGNED DEFAULT NULL,
    `attendance_date` DATE NOT NULL,
    `status` ENUM('present','absent','late','excused','holiday') NOT NULL DEFAULT 'present',
    `check_in_time` TIME DEFAULT NULL,
    `check_out_time` TIME DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `attendance_date` (`attendance_date`),
    KEY `status` (`status`),
    KEY `class_id` (`class_id`),
    KEY `academic_year_id` (`academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_summary` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED DEFAULT NULL,
    `month` TINYINT UNSIGNED DEFAULT NULL,
    `year` SMALLINT UNSIGNED DEFAULT NULL,
    `total_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `present_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `absent_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `late_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `excused_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `attendance_rate` DECIMAL(5,2) DEFAULT NULL,
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_summary` (`member_id`,`academic_year_id`,`month`,`year`),
    KEY `member_id` (`member_id`),
    KEY `academic_year_id` (`academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `grade_submissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id` INT UNSIGNED NOT NULL,
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED DEFAULT 0,
    `academic_year_id` INT UNSIGNED DEFAULT NULL,
    `term_id` INT UNSIGNED DEFAULT NULL,
    `assessment_id` INT UNSIGNED DEFAULT NULL,
    `attendance_date` DATE DEFAULT NULL,
    `submission_type` ENUM('marklist','attendance','report') NOT NULL DEFAULT 'marklist',
    `status` ENUM('draft','incomplete','submitted','approved','rejected','revision_needed') NOT NULL DEFAULT 'incomplete',
    `student_count` INT UNSIGNED DEFAULT 0,
    `average_score` DECIMAL(5,2) DEFAULT NULL,
    `present_count` INT UNSIGNED DEFAULT 0,
    `absent_count` INT UNSIGNED DEFAULT 0,
    `late_count` INT UNSIGNED DEFAULT 0,
    `excused_count` INT UNSIGNED DEFAULT 0,
    `submitted_at` TIMESTAMP NULL DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    `review_notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `teacher_id` (`teacher_id`),
    KEY `class_id` (`class_id`),
    KEY `status` (`status`),
    KEY `sub_att_lookup` (`teacher_id`,`class_id`,`submission_type`,`attendance_date`),
    KEY `sub_mark_lookup` (`teacher_id`,`assessment_id`,`submission_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS `ssms_013_add_column` $$
CREATE PROCEDURE `ssms_013_add_column`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition VARCHAR(800))
BEGIN
    IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=p_table)
       AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=p_table AND column_name=p_column) THEN
        SET @ssms_013_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE ssms_013_stmt FROM @ssms_013_sql;
        EXECUTE ssms_013_stmt;
        DEALLOCATE PREPARE ssms_013_stmt;
    END IF;
END $$
DELIMITER ;

CALL ssms_013_add_column('academic_years','year_gc','VARCHAR(20) DEFAULT NULL AFTER `year_name`');
CALL ssms_013_add_column('academic_years','ec_year','SMALLINT UNSIGNED DEFAULT NULL AFTER `year_gc`');
CALL ssms_013_add_column('academic_years','status','ENUM(''upcoming'',''active'',''closed'') NOT NULL DEFAULT ''upcoming'' AFTER `is_current`');
CALL ssms_013_add_column('class_enrollments','promoted_from','INT UNSIGNED DEFAULT NULL AFTER `notes`');
CALL ssms_013_add_column('members','archived_at','DATETIME NULL DEFAULT NULL AFTER `status`');
CALL ssms_013_add_column('members','archived_by','VARCHAR(100) NULL DEFAULT NULL AFTER `archived_at`');
CALL ssms_013_add_column('members','archive_reason','VARCHAR(50) NULL DEFAULT NULL AFTER `archived_by`');
CALL ssms_013_add_column('members','archive_notes','TEXT NULL AFTER `archive_reason`');
CALL ssms_013_add_column('members','archive_type','ENUM(''permanent_archive'',''failed_observation'') NULL DEFAULT ''permanent_archive'' AFTER `archive_reason`');
CALL ssms_013_add_column('members','restored_at','DATETIME NULL DEFAULT NULL AFTER `archived_at`');
CALL ssms_013_add_column('members','restored_by','VARCHAR(100) NULL DEFAULT NULL AFTER `restored_at`');
CALL ssms_013_add_column('cms_gallery_photos','thumb_path','VARCHAR(255) DEFAULT NULL AFTER `image_path`');
CALL ssms_013_add_column('academic_records','assessment_id','INT UNSIGNED DEFAULT NULL AFTER `term_id`');
CALL ssms_013_add_column('academic_records','submission_id','INT UNSIGNED DEFAULT NULL AFTER `assessment_id`');
CALL ssms_013_add_column('grade_submissions','attendance_date','DATE DEFAULT NULL AFTER `assessment_id`');
CALL ssms_013_add_column('grade_submissions','present_count','INT UNSIGNED DEFAULT 0 AFTER `average_score`');
CALL ssms_013_add_column('grade_submissions','absent_count','INT UNSIGNED DEFAULT 0 AFTER `present_count`');
CALL ssms_013_add_column('grade_submissions','late_count','INT UNSIGNED DEFAULT 0 AFTER `absent_count`');
CALL ssms_013_add_column('grade_submissions','excused_count','INT UNSIGNED DEFAULT 0 AFTER `late_count`');
CALL ssms_013_add_column('wbws_groups','group_name_en','VARCHAR(200) DEFAULT NULL AFTER `group_name`');
CALL ssms_013_add_column('wbws_groups','established_year_gc','VARCHAR(20) DEFAULT NULL AFTER `established_year`');
CALL ssms_013_add_column('wbws_groups','description','TEXT DEFAULT NULL AFTER `current_female`');
CALL ssms_013_add_column('wbws_groups','status','ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active'' AFTER `notes`');
CALL ssms_013_add_column('wbws_groups','updated_by','VARCHAR(100) DEFAULT NULL AFTER `created_by`');
CALL ssms_013_add_column('wbws_group_leaders','leader_full_name_en','VARCHAR(200) DEFAULT NULL AFTER `leader_full_name`');
CALL ssms_013_add_column('wbws_group_leaders','email','VARCHAR(100) DEFAULT NULL AFTER `phone`');
CALL ssms_013_add_column('wbws_group_leaders','start_date','DATE DEFAULT NULL AFTER `responsibility`');
CALL ssms_013_add_column('wbws_group_leaders','end_date','DATE DEFAULT NULL AFTER `start_date`');
CALL ssms_013_add_column('wbws_group_leaders','is_active','TINYINT(1) NOT NULL DEFAULT 1 AFTER `end_date`');
CALL ssms_013_add_column('wbws_group_leaders','updated_by','VARCHAR(100) DEFAULT NULL AFTER `created_by`');
CALL ssms_013_add_column('wbws_group_members','full_name_en','VARCHAR(200) DEFAULT NULL AFTER `full_name`');
CALL ssms_013_add_column('wbws_group_members','email','VARCHAR(100) DEFAULT NULL AFTER `phone`');
CALL ssms_013_add_column('wbws_group_members','date_of_birth','DATE DEFAULT NULL AFTER `email`');
CALL ssms_013_add_column('wbws_group_members','occupation','VARCHAR(100) DEFAULT NULL AFTER `education_level`');
CALL ssms_013_add_column('wbws_group_members','joined_date','DATE DEFAULT NULL AFTER `occupation`');
CALL ssms_013_add_column('wbws_group_members','membership_status','ENUM(''active'',''inactive'',''suspended'') NOT NULL DEFAULT ''active'' AFTER `joined_date`');
CALL ssms_013_add_column('wbws_group_members','photo_path','VARCHAR(300) DEFAULT NULL AFTER `notes`');
CALL ssms_013_add_column('wbws_group_members','updated_by','VARCHAR(100) DEFAULT NULL AFTER `created_by`');

ALTER TABLE `wbws_group_members` MODIFY COLUMN `membership_status` ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active';
ALTER TABLE `wbws_group_members` MODIFY COLUMN `gender` ENUM('M','F') NOT NULL DEFAULT 'M';
ALTER TABLE `wbws_group_leaders` MODIFY COLUMN `sex` ENUM('M','F') NOT NULL DEFAULT 'M';

DROP PROCEDURE IF EXISTS `ssms_013_add_column`;

-- Reconcile the legacy current flag only where the new status still has its
-- default. Prefer the newest active row, fall back to the newest year, and
-- close any conflicting active rows so lifecycle resolution is deterministic.
UPDATE `academic_years` SET `status`='active' WHERE `is_current`=1 AND `status`='upcoming';
SET @ssms_013_chosen_year := COALESCE(
    (SELECT `id` FROM `academic_years` WHERE `status`='active' ORDER BY COALESCE(`ec_year`,0) DESC, `id` DESC LIMIT 1),
    (SELECT `id` FROM `academic_years` ORDER BY COALESCE(`ec_year`,0) DESC, `id` DESC LIMIT 1)
);
UPDATE `academic_years`
SET `status`=CASE WHEN `id`=@ssms_013_chosen_year THEN 'active' ELSE 'closed' END
WHERE @ssms_013_chosen_year IS NOT NULL
  AND (`id`=@ssms_013_chosen_year OR `status`='active');
UPDATE `academic_years` SET `is_current`=IF(`status`='active',1,0);

-- Preserve displaced legacy rows before enforcing natural uniqueness. These
-- conflict tables are intentionally retained for administrator review/restore.
CREATE TABLE IF NOT EXISTS `migration_013_academic_record_conflicts` LIKE `academic_records`;
INSERT IGNORE INTO `migration_013_academic_record_conflicts`
SELECT ar.* FROM `academic_records` ar
INNER JOIN `academic_records` keep
    ON keep.assessment_id=ar.assessment_id AND keep.member_id=ar.member_id AND keep.id>ar.id
WHERE ar.assessment_id IS NOT NULL;
DELETE ar FROM `academic_records` ar
INNER JOIN `academic_records` keep
    ON keep.assessment_id=ar.assessment_id AND keep.member_id=ar.member_id AND keep.id>ar.id
WHERE ar.assessment_id IS NOT NULL;
SET @ssms_013_sql := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='academic_records' AND index_name='uq_ar_assessment_member'),
    'SELECT 1',
    'ALTER TABLE `academic_records` ADD UNIQUE KEY `uq_ar_assessment_member` (`assessment_id`,`member_id`)'
);
PREPARE ssms_013_stmt FROM @ssms_013_sql;
EXECUTE ssms_013_stmt;
DEALLOCATE PREPARE ssms_013_stmt;

CREATE TABLE IF NOT EXISTS `migration_013_attendance_conflicts` LIKE `attendance`;
INSERT IGNORE INTO `migration_013_attendance_conflicts`
SELECT attendance_duplicate.* FROM `attendance` attendance_duplicate
INNER JOIN `attendance` keep
    ON keep.member_id=attendance_duplicate.member_id
   AND keep.class_id <=> attendance_duplicate.class_id
   AND keep.attendance_date=attendance_duplicate.attendance_date
   AND keep.id>attendance_duplicate.id;
DELETE attendance_duplicate FROM `attendance` attendance_duplicate
INNER JOIN `attendance` keep
    ON keep.member_id=attendance_duplicate.member_id
   AND keep.class_id <=> attendance_duplicate.class_id
   AND keep.attendance_date=attendance_duplicate.attendance_date
   AND keep.id>attendance_duplicate.id;
SET @ssms_013_sql := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='attendance' AND index_name='uq_att_member_class_date'),
    'SELECT 1',
    'ALTER TABLE `attendance` ADD UNIQUE KEY `uq_att_member_class_date` (`member_id`,`class_id`,`attendance_date`)'
);
PREPARE ssms_013_stmt FROM @ssms_013_sql;
EXECUTE ssms_013_stmt;
DEALLOCATE PREPARE ssms_013_stmt;
