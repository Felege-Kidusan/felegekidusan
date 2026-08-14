-- ============================================================
-- 004_year_lifecycle.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Academic-year LIFECYCLE STATES (upcoming / active / closed)
-- ============================================================
-- WBSS/FKSS — MariaDB 10.6 (cPanel). Run in phpMyAdmin. Safe to run MORE THAN
-- ONCE — every statement checks state first, so re-running never errors and
-- never half-applies. TAKE A BACKUP FIRST anyway (Super Admin -> Backup).
--
-- WHY THIS IS A REWRITE:
--   The `academic_years.status` column already existed on the live database as
--   ENUM('active','completed','upcoming'). The original 004 used ADD COLUMN,
--   which fails with "duplicate column" on such a database, and it used the
--   value 'completed' where the application now uses 'closed'. This version:
--     * NORMALISES the enum to ENUM('upcoming','active','closed') via MODIFY
--       (preserving any legacy 'completed' rows by remapping them to 'closed'),
--     * ADDs the column only if it is genuinely missing,
--     * ADDs indexes / the single-active guard only if they do not exist,
--     * NORMALISES the data so EXACTLY ONE year is 'active',
--     * is fully safe to run twice.
--
-- Everything is wrapped in a stored procedure so the existence checks and the
-- conditional DDL run as one unit; the procedure is dropped again at the end,
-- leaving nothing behind.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `wbss_apply_year_lifecycle` $$

CREATE PROCEDURE `wbss_apply_year_lifecycle`()
BEGIN
    DECLARE v_table_exists  INT DEFAULT 0;
    DECLARE v_col_exists    INT DEFAULT 0;
    DECLARE v_idx_status    INT DEFAULT 0;
    DECLARE v_guard_col     INT DEFAULT 0;
    DECLARE v_uk_active     INT DEFAULT 0;

    -- If the table itself does not exist yet, do nothing: the application
    -- migrations / self-healing resolver will create it with the correct enum.
    SELECT COUNT(*) INTO v_table_exists
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_years';

    IF v_table_exists = 0 THEN
        SELECT 'academic_years table not found — nothing to do (it will be created with the correct enum).' AS note;
    ELSE

        -- ── 1. The `status` column: add if missing, otherwise normalise it ──
        SELECT COUNT(*) INTO v_col_exists
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME  = 'academic_years'
           AND COLUMN_NAME = 'status';

        IF v_col_exists = 0 THEN
            -- Genuinely missing → add it with the final enum, seed from is_current.
            ALTER TABLE `academic_years`
              ADD COLUMN `status` ENUM('upcoming','active','closed')
              NOT NULL DEFAULT 'upcoming' AFTER `is_current`;
            UPDATE `academic_years` SET `status` = IF(`is_current` = 1, 'active', 'upcoming');
        ELSE
            -- Already present (possibly the legacy enum). Widen to a SUPERSET
            -- first so any existing 'completed' rows are preserved, remap them
            -- to 'closed', then narrow to the final enum. All steps are no-ops
            -- on a database that is already correct, so this is safe to re-run.
            ALTER TABLE `academic_years`
              MODIFY COLUMN `status` ENUM('upcoming','active','closed','completed')
              NOT NULL DEFAULT 'upcoming';
            UPDATE `academic_years` SET `status` = 'closed' WHERE `status` = 'completed';
            ALTER TABLE `academic_years`
              MODIFY COLUMN `status` ENUM('upcoming','active','closed')
              NOT NULL DEFAULT 'upcoming';
        END IF;

        -- ── 2. Repair any stray / blank value (e.g. a prior forced enum change
        --       that turned an out-of-range value into '') to 'closed'. ──
        UPDATE `academic_years`
           SET `status` = 'closed'
         WHERE `status` NOT IN ('upcoming','active','closed') OR `status` = '';

        -- ── 3. Normalise to EXACTLY ONE active year ──
        -- (a) If two or more are 'active', keep the most recent (highest EC year,
        --     then highest id) and CLOSE the rest.
        UPDATE `academic_years`
           SET `status` = 'closed'
         WHERE `status` = 'active'
           AND `id` <> (
               SELECT `id` FROM (
                   SELECT `id` FROM `academic_years`
                    WHERE `status` = 'active'
                    ORDER BY COALESCE(`ec_year`,0) DESC, `id` DESC
                    LIMIT 1
               ) AS keep_row
           );
        -- (b) If NONE is active, promote the most recent year.
        UPDATE `academic_years`
           SET `status` = 'active'
         WHERE `id` = (
               SELECT `id` FROM (
                   SELECT `id` FROM `academic_years`
                    ORDER BY COALESCE(`ec_year`,0) DESC, `id` DESC
                    LIMIT 1
               ) AS newest_row
           )
           AND NOT EXISTS (
               SELECT 1 FROM (
                   SELECT 1 FROM `academic_years` WHERE `status` = 'active' LIMIT 1
               ) AS has_active
           );

        -- ── 4. Re-derive is_current from status so the two always agree ──
        UPDATE `academic_years` SET `is_current` = IF(`status` = 'active', 1, 0);

        -- ── 5. Index on status (add only if missing) ──
        SELECT COUNT(*) INTO v_idx_status
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'academic_years'
           AND INDEX_NAME   = 'idx_status';
        IF v_idx_status = 0 THEN
            ALTER TABLE `academic_years` ADD INDEX `idx_status` (`status`);
        END IF;

        -- ── 6. DB-LEVEL single-active guarantee (best-effort, optional) ──
        -- A virtual column that is 1 only when the row is 'active', plus a
        -- UNIQUE index on it: because UNIQUE allows many NULLs but only one
        -- non-NULL '1', the database itself refuses two active years at once.
        -- Wrapped in a CONTINUE handler so that if this MariaDB build rejects a
        -- unique index on a virtual column, the migration still finishes — the
        -- application enforces single-active atomically anyway (ay_switch_active
        -- in admin/backend/academic_year.php).
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;

            SELECT COUNT(*) INTO v_guard_col
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME  = 'academic_years'
               AND COLUMN_NAME = 'active_guard';
            IF v_guard_col = 0 THEN
                ALTER TABLE `academic_years`
                  ADD COLUMN `active_guard` TINYINT
                  GENERATED ALWAYS AS (IF(`status` = 'active', 1, NULL)) VIRTUAL;
            END IF;

            SELECT COUNT(*) INTO v_uk_active
              FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'academic_years'
               AND INDEX_NAME   = 'uk_single_active';
            IF v_uk_active = 0 THEN
                ALTER TABLE `academic_years`
                  ADD UNIQUE KEY `uk_single_active` (`active_guard`);
            END IF;
        END;

    END IF;
END $$

DELIMITER ;

CALL `wbss_apply_year_lifecycle`();
DROP PROCEDURE IF EXISTS `wbss_apply_year_lifecycle`;

-- ============================================================
-- VERIFY after running (should show exactly one active row):
--   SELECT id, year_name, ec_year, is_current, status FROM academic_years;
--   SHOW COLUMNS FROM academic_years LIKE 'status';
--     -- Type must read: enum('upcoming','active','closed')
-- ============================================================
