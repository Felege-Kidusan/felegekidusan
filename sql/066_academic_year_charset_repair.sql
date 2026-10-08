-- ============================================================
-- 066_academic_year_charset_repair.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Fix: academic_years is latin1 on the production database
-- ============================================================
-- WBSS/FKSS — MariaDB 11.4 (cPanel). Run in phpMyAdmin. Safe to run MORE
-- THAN ONCE — every step checks the current state first, so re-running never
-- errors and never half-applies.
--
-- WHY (2026-10-08, live incident):
--   The production database was restored from the old server's dump with its
--   schema verbatim. On that server `academic_years` had been created (by a
--   pre-charset-aware version of the setup) with the server default
--   CHARSET=latin1 — every other education table is utf8mb4. The table is
--   EMPTY (the school never successfully created a year), and the admin app
--   connects with utf8mb4, so inserting the DEFAULT year name
--   "2019 ዓ.ም." (Amharic) fails with:
--       Incorrect string value (errno 1366, strict mode)
--   which PHP 8.1+ mysqli raises as an exception → the admin saw only the
--   generic "Unable to save the academic year."
--
--   (Corroborating prior art: tools/find_stored_mojibake.php already lists
--   academic_years.year_name / academic_terms.term_name as columns that
--   historically received double-encoded values on the old server.)
--
-- WHAT THIS DOES:
--   * academic_years  → CONVERT TO utf8mb4_unicode_ci   (table is empty in
--     this deployment; conversion is instant and cannot lose data. If you
--     run this on a database that DOES hold rows, take a backup first —
--     CONVERT TO re-encodes existing text.)
--   * sync_feed_state → same conversion, pure hygiene (its three columns
--     are numeric/timestamp; latin1 there is harmless but inconsistent).
--
-- WHAT THIS DELIBERATELY DOES NOT DO:
--   * mezmur_hymn_words is latin1 on purpose and MUST NOT be converted:
--     it stores Amharic words as raw hex/binary payloads that round-trip
--     through the sync system; a charset conversion is unnecessary there
--     and would risk re-encoding stored bytes.
--   * No data is rewritten anywhere.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `wbss_repair_latin1_tables` $$

CREATE PROCEDURE `wbss_repair_latin1_tables`()
BEGIN
    DECLARE v_cs VARCHAR(32) DEFAULT '';

    -- ── 1. academic_years ──────────────────────────────────────────────
    SELECT IFNULL(MAX(CHARACTER_SET_NAME), '') INTO v_cs
      FROM information_schema.TABLES t
      JOIN information_schema.COLLATIONS c ON c.COLLATION_NAME = t.TABLE_COLLATION
     WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = 'academic_years';

    IF v_cs = '' THEN
        SELECT 'academic_years not found — nothing to do (the application migrations create it utf8mb4).' AS note;
    ELSEIF v_cs = 'utf8mb4' THEN
        SELECT 'OK: academic_years is already utf8mb4 — nothing to do.' AS status;
    ELSE
        ALTER TABLE `academic_years`
          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        SELECT 'OK: academic_years converted to utf8mb4_unicode_ci.' AS status;
    END IF;

    -- ── 2. sync_feed_state (hygiene only; numeric columns) ────────────
    SELECT IFNULL(MAX(CHARACTER_SET_NAME), '') INTO v_cs
      FROM information_schema.TABLES t
      JOIN information_schema.COLLATIONS c ON c.COLLATION_NAME = t.TABLE_COLLATION
     WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = 'sync_feed_state';

    IF v_cs = '' THEN
        SELECT 'sync_feed_state not found — nothing to do.' AS note;
    ELSEIF v_cs = 'utf8mb4' THEN
        SELECT 'OK: sync_feed_state is already utf8mb4 — nothing to do.' AS status;
    ELSE
        ALTER TABLE `sync_feed_state`
          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        SELECT 'OK: sync_feed_state converted to utf8mb4_unicode_ci.' AS status;
    END IF;
END $$

DELIMITER ;

CALL `wbss_repair_latin1_tables`();
DROP PROCEDURE IF EXISTS `wbss_repair_latin1_tables`;

-- ============================================================
-- VERIFY after running:
--   SELECT TABLE_NAME, CHARACTER_SET_NAME, TABLE_COLLATION
--     FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_COLLATION NOT LIKE 'utf8mb4%';
--   → must return only mezmur_hymn_words (binary word storage, by design).
--
-- Then create the year in the admin UI with the default Amharic name —
-- it must save, appear in the list, and the semesters must be created.
-- ============================================================
