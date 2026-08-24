-- SSMS migration 014: member directory and export indexes
-- Apply once during deployment. This file is idempotent and never runs from a request.

DROP PROCEDURE IF EXISTS `ssms_014_add_index`;
DELIMITER $$
CREATE PROCEDURE `ssms_014_add_index`(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns VARCHAR(512)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table
          AND index_name = p_index
    ) THEN
        SET @ssms_014_sql = CONCAT(
            'ALTER TABLE `', REPLACE(p_table, '`', '``'),
            '` ADD INDEX `', REPLACE(p_index, '`', '``'),
            '` (', p_columns, ')'
        );
        PREPARE ssms_014_stmt FROM @ssms_014_sql;
        EXECUTE ssms_014_stmt;
        DEALLOCATE PREPARE ssms_014_stmt;
    END IF;
END$$
DELIMITER ;

-- Supports status-filtered, descending keyset pages.
CALL `ssms_014_add_index`('members', 'idx_members_status_id', '`status`, `id`');
-- Supports exact filters exposed by the management directory.
CALL `ssms_014_add_index`('members', 'idx_members_city', '`city`');
CALL `ssms_014_add_index`('members', 'idx_members_education_level', '`education_level`');
-- Supports bounded XLSX and streaming CSV exports by membership tier.
CALL `ssms_014_add_index`('members', 'idx_members_tier_id', '`membership_tier`, `id`');
-- Supports bounded permanent/failed-observation archive pages.
CALL `ssms_014_add_index`('members', 'idx_members_archive_type_id', '`status`, `archive_type`, `id`');
CALL `ssms_014_add_index`(
    'class_enrollments',
    'idx_ce_member_year_status_id',
    '`member_id`, `academic_year_id`, `status`, `id`'
);

-- Avoid eight leading-wildcard LIKE scans for normal name/code/phone searches.
-- MariaDB/InnoDB uses this index for MATCH ... AGAINST in boolean mode.
SET @ssms_014_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'members'
        ) AND NOT EXISTS(
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'members'
              AND index_name = 'ft_members_directory'
        ),
        'ALTER TABLE `members` ADD FULLTEXT INDEX `ft_members_directory` (`student_name`, `father_name`, `grandfather_name`, `member_code`, `baptismal_name`, `phone_number`, `work_profession`, `city`)',
        'SELECT 1'
    )
);
PREPARE ssms_014_fulltext_stmt FROM @ssms_014_sql;
EXECUTE ssms_014_fulltext_stmt;
DEALLOCATE PREPARE ssms_014_fulltext_stmt;

DROP PROCEDURE IF EXISTS `ssms_014_add_index`;
