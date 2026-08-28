-- ============================================================
-- 028_analytics_scale.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Phase E — scale hardening for the analytics hub (100k+ rows).
--
-- The hub's read model (attendance_rollup, migration 027) keeps
-- dashboard reads at O(days × groups). The remaining hot paths
-- that still touch raw attendance rows are the MEMBER-BASED
-- reports/lookups (one member's history per source). These two
-- indexes give those queries an exact (member, date-range) seek
-- instead of scanning by session/class composites.
--
-- Each index is added only if missing. Safe to run more than once.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `ssms_add_index_if_missing_028` $$
CREATE PROCEDURE `ssms_add_index_if_missing_028`(
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

-- Mezmur member-history reads (member-based reports / lookups):
-- the existing idx_mezmur_attendance_member is (member_id, session_id),
-- which cannot bound a date range. This one seeks member + date window.
CALL ssms_add_index_if_missing_028('mezmur_attendance', 'idx_mezmur_att_member_date', '`member_id`, `attendance_date`');

-- Education member-history reads: the unique key is
-- (member_id, class_id, attendance_date); analytics filters by
-- member + date only, so a dedicated compact index avoids scanning
-- every class row for the member.
CALL ssms_add_index_if_missing_028('attendance', 'idx_att_member_date', '`member_id`, `attendance_date`');

-- HR member-history reads already ship idx_hr_attendance_member
-- (member_id, attendance_date) from migration 026 — nothing to add.

DROP PROCEDURE IF EXISTS `ssms_add_index_if_missing_028`;
