-- ============================================================
-- 020_identity_v2.sql  (IDEMPOTENT / RE-RUNNABLE)
--
-- Identity code format v2 (ANALYSIS/08):
--   * positions may be FREE (no department): Director, Secretary …
--     their letters lead the prefix (DEDHT-98798, DT-98798);
--   * staff_positions.legacy_flag maps a position onto one of the
--     legacy member flag columns (is_teacher, is_staff, …) so the
--     Teacher dashboard / Education stats / workflow keep working
--     while forms migrate to the position picker (strangler pattern).
-- ============================================================

-- ── 1. Free positions: department becomes optional ──────────
DROP PROCEDURE IF EXISTS `ssms_020_identity_v2`;
DELIMITER $$
CREATE PROCEDURE `ssms_020_identity_v2`()
BEGIN
    DECLARE v_nullable VARCHAR(3);

    SELECT IS_NULLABLE INTO v_nullable
    FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'staff_positions'
      AND `COLUMN_NAME` = 'department_id';

    IF v_nullable = 'NO' THEN
        ALTER TABLE `staff_positions`
            MODIFY `department_id` INT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM `information_schema`.`COLUMNS`
        WHERE `TABLE_SCHEMA` = DATABASE()
          AND `TABLE_NAME` = 'staff_positions'
          AND `COLUMN_NAME` = 'legacy_flag'
    ) THEN
        ALTER TABLE `staff_positions`
            ADD COLUMN `legacy_flag` VARCHAR(20) NULL AFTER `department_id`,
            ADD INDEX `idx_staff_positions_legacy_flag` (`legacy_flag`);
    END IF;
END$$
DELIMITER ;

CALL `ssms_020_identity_v2`();
DROP PROCEDURE IF EXISTS `ssms_020_identity_v2`;

-- ── 2. Data-driven default: Teacher position drives is_teacher ─
UPDATE `staff_positions`
   SET `legacy_flag` = 'is_teacher'
 WHERE `role_code` = 'T'
   AND `legacy_flag` IS NULL;

-- NOTE: member_code_sequences (sql/018) is intentionally left in the
-- schema but no longer read or written by the application (format v2
-- uses random unique tails). It remains only as rollback safety.
