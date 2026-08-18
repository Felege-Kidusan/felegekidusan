-- ============================================================
-- 006_assignment_hardening.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Smart teacher ↔ subject ↔ class assignments.
-- Safe to run more than once. Does not delete existing rows.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `ssms_add_column_if_missing` $$
CREATE PROCEDURE `ssms_add_column_if_missing`(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_def    VARCHAR(800)
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    DECLARE v_table_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;

    IF v_table_exists > 0 THEN
        SELECT COUNT(*) INTO v_exists
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = p_table
           AND COLUMN_NAME = p_column;

        IF v_exists = 0 THEN
            SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
END $$

DROP PROCEDURE IF EXISTS `ssms_add_index_if_missing` $$
CREATE PROCEDURE `ssms_add_index_if_missing`(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_cols  VARCHAR(255)
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    DECLARE v_table_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;

    IF v_table_exists > 0 THEN
        SELECT COUNT(*) INTO v_exists
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = p_table
           AND INDEX_NAME = p_index;

        IF v_exists = 0 THEN
            SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
END $$

DELIMITER ;

-- Create table if a very old install never ran migration 002
CREATE TABLE IF NOT EXISTS `teacher_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id` INT UNSIGNED NOT NULL COMMENT 'users.id of the teacher',
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED DEFAULT NULL,
    `academic_year_id` INT UNSIGNED DEFAULT NULL,
    `is_class_teacher` TINYINT(1) NOT NULL DEFAULT 0,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `assignment_role` ENUM('primary','assistant','homeroom') NOT NULL DEFAULT 'primary',
    `assigned_by` INT UNSIGNED DEFAULT NULL,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `teacher_id` (`teacher_id`),
    KEY `class_id` (`class_id`),
    KEY `subject_id` (`subject_id`),
    KEY `academic_year_id` (`academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `class_subjects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_class_subject` (`class_id`, `subject_id`),
    KEY `class_id` (`class_id`),
    KEY `subject_id` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Homeroom does not need a subject
ALTER TABLE `teacher_assignments`
    MODIFY `subject_id` INT UNSIGNED DEFAULT NULL;

CALL ssms_add_column_if_missing('teacher_assignments', 'is_active',
    'TINYINT(1) NOT NULL DEFAULT 1');
CALL ssms_add_column_if_missing('teacher_assignments', 'is_primary',
    'TINYINT(1) NOT NULL DEFAULT 0');
CALL ssms_add_column_if_missing('teacher_assignments', 'is_class_teacher',
    'TINYINT(1) NOT NULL DEFAULT 0');
CALL ssms_add_column_if_missing('teacher_assignments', 'assignment_role',
    "ENUM('primary','assistant','homeroom') NOT NULL DEFAULT 'primary'");
CALL ssms_add_column_if_missing('teacher_assignments', 'assigned_by',
    'INT UNSIGNED DEFAULT NULL');

-- Sentinel so NULL subject (homeroom) can be unique
CALL ssms_add_column_if_missing('teacher_assignments', 'subject_key',
    'INT UNSIGNED GENERATED ALWAYS AS (IFNULL(`subject_id`, 0)) STORED');

-- Collapse exact duplicates (keep oldest row) so the unique index can be added
DELETE ta FROM `teacher_assignments` ta
INNER JOIN `teacher_assignments` keep
    ON ta.teacher_id = keep.teacher_id
   AND ta.class_id = keep.class_id
   AND IFNULL(ta.subject_id, 0) = IFNULL(keep.subject_id, 0)
   AND IFNULL(ta.academic_year_id, 0) = IFNULL(keep.academic_year_id, 0)
   AND ta.id > keep.id;

-- Backfill role from existing flags
UPDATE `teacher_assignments`
   SET `assignment_role` = 'homeroom'
 WHERE (`subject_id` IS NULL OR `subject_id` = 0)
   AND (`is_class_teacher` = 1 OR `assignment_role` = 'homeroom');

UPDATE `teacher_assignments`
   SET `assignment_role` = 'assistant'
 WHERE `subject_id` IS NOT NULL
   AND `subject_id` > 0
   AND `is_primary` = 0
   AND `is_class_teacher` = 0
   AND `assignment_role` = 'primary'
   AND EXISTS (
        SELECT 1 FROM (
            SELECT teacher_id, class_id, subject_id, academic_year_id
              FROM teacher_assignments x
             WHERE x.is_active = 1 AND x.is_primary = 1
        ) p
        WHERE p.class_id = teacher_assignments.class_id
          AND p.subject_id = teacher_assignments.subject_id
          AND IFNULL(p.academic_year_id,0) = IFNULL(teacher_assignments.academic_year_id,0)
          AND p.teacher_id <> teacher_assignments.teacher_id
   );

UPDATE `teacher_assignments`
   SET `is_primary` = 1
 WHERE `subject_id` IS NOT NULL
   AND `subject_id` > 0
   AND `assignment_role` = 'primary'
   AND `is_active` = 1;

-- Unique: one row per teacher + class + subject (or homeroom) + year
-- Added only if missing
SET @uniq_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'teacher_assignments'
       AND INDEX_NAME = 'uniq_ta_teacher_class_subject_year'
);
SET @sql := IF(@uniq_exists = 0,
    'ALTER TABLE `teacher_assignments` ADD UNIQUE KEY `uniq_ta_teacher_class_subject_year` (`teacher_id`, `class_id`, `subject_key`, `academic_year_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CALL ssms_add_index_if_missing('teacher_assignments', 'idx_ta_year_class_active',
    '`academic_year_id`, `class_id`, `is_active`');
CALL ssms_add_index_if_missing('teacher_assignments', 'idx_ta_year_teacher_active',
    '`academic_year_id`, `teacher_id`, `is_active`');
CALL ssms_add_index_if_missing('teacher_assignments', 'idx_ta_year_subject_active',
    '`academic_year_id`, `subject_id`, `is_active`');
CALL ssms_add_index_if_missing('teacher_assignments', 'idx_ta_class_homeroom',
    '`class_id`, `academic_year_id`, `is_class_teacher`, `is_active`');

CALL ssms_add_index_if_missing('class_subjects', 'idx_cs_subject', '`subject_id`');
CALL ssms_add_index_if_missing('users', 'idx_users_role_active', '`role`, `is_active`');
CALL ssms_add_index_if_missing('users', 'idx_users_full_name', '`full_name`');

DROP PROCEDURE IF EXISTS `ssms_add_column_if_missing`;
DROP PROCEDURE IF EXISTS `ssms_add_index_if_missing`;
