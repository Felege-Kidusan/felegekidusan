-- SSMS migration 016: bounded member duplicate lookup
-- Apply once during deployment. This file is idempotent and never runs from a request.
-- Prefixes keep the index compatible with older MariaDB/InnoDB key-size limits;
-- SQL equality predicates still recheck complete column values.

DROP PROCEDURE IF EXISTS `ssms_016_add_index`;
DELIMITER $$
CREATE PROCEDURE `ssms_016_add_index`(
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
        SET @ssms_016_sql = CONCAT(
            'ALTER TABLE `', REPLACE(p_table, '`', '``'),
            '` ADD INDEX `', REPLACE(p_index, '`', '``'),
            '` (', p_columns, ')'
        );
        PREPARE ssms_016_stmt FROM @ssms_016_sql;
        EXECUTE ssms_016_stmt;
        DEALLOCATE PREPARE ssms_016_stmt;
    END IF;
END$$
DELIMITER ;

CALL `ssms_016_add_index`(
    'members',
    'idx_members_duplicate_name',
    '`student_name`(63), `father_name`(63), `grandfather_name`(63)'
);
CALL `ssms_016_add_index`(
    'members',
    'idx_members_duplicate_advisory',
    '`student_name`(63), `father_name`(63), `status`, `id`'
);

DROP PROCEDURE IF EXISTS `ssms_016_add_index`;
