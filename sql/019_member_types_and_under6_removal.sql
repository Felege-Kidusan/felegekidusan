-- ============================================================
-- 019_member_types_and_under6_removal.sql  (IDEMPOTENT / RE-RUNNABLE)
--
-- Part of the Identity & Membership management rollout:
--
--  1. member_type_settings
--     The three membership tiers (regular / special_regular / honorary)
--     become manageable from the Super Admin panel: Amharic + English
--     display labels live in data, not in code. The ENUM keys stay
--     stable (they are referenced by reports, mobile sync and filters);
--     only the human-facing labels are editable. Renderers read the
--     table through App\Services\MemberTypeService with hard-coded
--     fallbacks, so a deployment that has not run this migration keeps
--     working unchanged.
--
--  2. Complete removal of the retired fourth category
--     አጸደ ህጻናት ('under6'). sql/017 already merged existing rows into
--     ህጻናት ('7_13'); this migration scrubs any stragglers (imports that
--     ran before the app update) and then removes 'under6' from the
--     age_group ENUMs so the value can never be written again. The
--     ALTER is applied ONLY when no row still carries 'under6' and the
--     ENUM still contains it — re-runs are safe.
-- ============================================================

-- ── 1. Membership tier registry ─────────────────────────────
CREATE TABLE IF NOT EXISTS `member_type_settings` (
    `type_key`   VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `label_am`   VARCHAR(150) NOT NULL,
    `label_en`   VARCHAR(150) NOT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`type_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `member_type_settings` (`type_key`, `label_am`, `label_en`, `sort_order`)
SELECT 'regular', 'መደበኛ', 'Regular', 1
WHERE NOT EXISTS (SELECT 1 FROM `member_type_settings` WHERE `type_key` = 'regular');
INSERT INTO `member_type_settings` (`type_key`, `label_am`, `label_en`, `sort_order`)
SELECT 'special_regular', 'ልዩ መደበኛ', 'Special Regular', 2
WHERE NOT EXISTS (SELECT 1 FROM `member_type_settings` WHERE `type_key` = 'special_regular');
INSERT INTO `member_type_settings` (`type_key`, `label_am`, `label_en`, `sort_order`)
SELECT 'honorary', 'የክብር አባል', 'Honorary', 3
WHERE NOT EXISTS (SELECT 1 FROM `member_type_settings` WHERE `type_key` = 'honorary');

-- ── 2. Scrub + retire 'under6' ──────────────────────────────
-- Everything below is guarded: tables that do not exist on this
-- deployment (classes ships with the 012 baseline and may be absent on
-- older databases) are skipped, and the ENUM shrink only runs when the
-- column still contains 'under6' and no row still uses it.
DROP PROCEDURE IF EXISTS `ssms_019_drop_under6_enum`;
DELIMITER $$
CREATE PROCEDURE `ssms_019_drop_under6_enum`()
BEGIN
    DECLARE v_rows INT DEFAULT 0;
    DECLARE v_has_classes INT DEFAULT 0;
    DECLARE v_col_type LONGTEXT;

    -- Scrub stragglers (imports that ran before the app update).
    UPDATE `members` SET `age_group` = '7_13' WHERE `age_group` = 'under6';

    SELECT COUNT(*) INTO v_has_classes
    FROM `information_schema`.`TABLES`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'classes';
    IF v_has_classes > 0 THEN
        UPDATE `classes` SET `age_group` = '7_13' WHERE `age_group` = 'under6';
    END IF;

    SELECT COUNT(*) INTO v_rows
    FROM `members` WHERE `age_group` = 'under6';

    IF v_rows = 0 THEN
        SELECT COLUMN_TYPE INTO v_col_type
        FROM `information_schema`.`COLUMNS`
        WHERE `TABLE_SCHEMA` = DATABASE()
          AND `TABLE_NAME` = 'members'
          AND `COLUMN_NAME` = 'age_group';

        -- ENUM columns only: deployments already upgraded to VARCHAR by
        -- the 012 baseline do not need (and must not get) this shrink.
        IF v_col_type LIKE '%under6%' THEN
            ALTER TABLE `members`
                MODIFY `age_group` ENUM('7_13','14_17','18_plus') DEFAULT NULL;
        END IF;

        IF v_has_classes > 0 THEN
            SET v_col_type = NULL;
            SELECT COLUMN_TYPE INTO v_col_type
            FROM `information_schema`.`COLUMNS`
            WHERE `TABLE_SCHEMA` = DATABASE()
              AND `TABLE_NAME` = 'classes'
              AND `COLUMN_NAME` = 'age_group';

            IF v_col_type LIKE '%under6%' THEN
                ALTER TABLE `classes`
                    MODIFY `age_group` ENUM('7_13','14_17','18_plus') DEFAULT NULL;
            END IF;
        END IF;
    END IF;
END$$
DELIMITER ;

CALL `ssms_019_drop_under6_enum`();
DROP PROCEDURE IF EXISTS `ssms_019_drop_under6_enum`;
