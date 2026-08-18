-- ============================================================
-- 005_roster_indexes.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Indexes for Education roster + member search.
-- Each index is added only if missing. Safe to run more than once.
-- ============================================================

DELIMITER $$

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

CALL ssms_add_index_if_missing('members', 'idx_members_status', '`status`');
CALL ssms_add_index_if_missing('members', 'idx_members_gender', '`gender`');
CALL ssms_add_index_if_missing('members', 'idx_members_age_group', '`age_group`');
CALL ssms_add_index_if_missing('members', 'idx_members_member_type', '`member_type`');
CALL ssms_add_index_if_missing('members', 'idx_members_student_name', '`student_name`');
CALL ssms_add_index_if_missing('members', 'idx_members_father_name', '`father_name`');
CALL ssms_add_index_if_missing('members', 'idx_members_member_code', '`member_code`');
CALL ssms_add_index_if_missing('members', 'idx_members_baptismal', '`baptismal_name`');
CALL ssms_add_index_if_missing('members', 'idx_members_status_gender', '`status`, `gender`');

CALL ssms_add_index_if_missing('class_enrollments', 'idx_ce_year_status', '`academic_year_id`, `status`');
CALL ssms_add_index_if_missing('class_enrollments', 'idx_ce_class_year_status', '`class_id`, `academic_year_id`, `status`');
CALL ssms_add_index_if_missing('class_enrollments', 'idx_ce_member_year', '`member_id`, `academic_year_id`');

CALL ssms_add_index_if_missing('classes', 'idx_classes_code', '`class_code`');
CALL ssms_add_index_if_missing('classes', 'idx_classes_active_level', '`is_active`, `level_order`');

DROP PROCEDURE IF EXISTS `ssms_add_index_if_missing`;
