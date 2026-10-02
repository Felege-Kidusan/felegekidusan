-- ============================================================
-- 018_code_sequences_and_year_uniqueness.sql  (IDEMPOTENT / RE-RUNNABLE)
--
-- Part of the phase-1 fix batch (findings M2 + M3):
--
--  1. member_code_sequences
--     O(1) per-letter identity-code allocation. Runtime code allocation
--     previously scanned `members` with REGEXP on every registration to
--     find MAX+1 — an O(n) full scan serialized behind GET_LOCK, which
--     does not scale to large rosters. IdentityCodeService bumps this
--     table atomically and falls back to the legacy scan automatically
--     until this migration has run.
--
--  2. UNIQUE(year_name) on academic_years
--     The save endpoint already reports errno 1062 ("year name already
--     exists"), but without the index that branch could never fire and
--     duplicate year names were silently accepted. The index is added
--     ONLY when no duplicate names exist; deployments with legacy
--     duplicates keep working (the application-level pre-check enforces
--     uniqueness from now on) and can add the index after cleaning data.
--
-- Apply once during deployment with an authorized DB account.
-- ============================================================

-- ── 1. Per-letter identity code sequences ────────────────────
CREATE TABLE IF NOT EXISTS `member_code_sequences` (
    `letter`     CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `last_n`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`letter`)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Seed every letter from the highest numeric suffix currently in use.
-- INSERT IGNORE keeps re-runs and already-seeded letters untouched.
INSERT IGNORE INTO `member_code_sequences` (`letter`, `last_n`)
SELECT
    LEFT(m.`member_code`, 1) AS letter,
    MAX(CAST(SUBSTRING(m.`member_code`, 2) AS UNSIGNED)) AS last_n
FROM `members` m
WHERE m.`member_code` REGEXP '^[A-Z][0-9]+$'
GROUP BY LEFT(m.`member_code`, 1);

-- ── 2. Unique academic year names (conditional) ─────────────
DROP PROCEDURE IF EXISTS `ssms_018_unique_year_name`;
DELIMITER $$
CREATE PROCEDURE `ssms_018_unique_year_name`()
BEGIN
    DECLARE v_duplicates INT DEFAULT 0;
    DECLARE v_index_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_duplicates
    FROM (
        SELECT `year_name`
        FROM `academic_years`
        GROUP BY `year_name`
        HAVING COUNT(*) > 1
    ) AS dup;

    SELECT COUNT(*) INTO v_index_exists
    FROM `information_schema`.`STATISTICS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'academic_years'
      AND `INDEX_NAME` = 'uq_academic_years_year_name';

    IF v_index_exists > 0 THEN
        SELECT 'OK: uq_academic_years_year_name already present — nothing to do.' AS status;
    ELSEIF v_duplicates = 0 THEN
        ALTER TABLE `academic_years`
            ADD CONSTRAINT `uq_academic_years_year_name` UNIQUE (`year_name`);
        SELECT 'OK: uq_academic_years_year_name created.' AS status;
    ELSE
        -- Finding H (2026-10-02): this branch used to be SILENT. The index
        -- was skipped and the deployment looked clean, while duplicate year
        -- names stayed possible under concurrency (the errno-1062 branch in
        -- the save endpoint can never fire without the index). Duplicates
        -- are still NOT merged or deleted here — that is a human decision —
        -- but the operator is now told, and shown exactly which rows block.
        SELECT CONCAT(
            'BLOCKER: uq_academic_years_year_name NOT created — ',
            v_duplicates,
            ' duplicate year_name value(s) exist. Resolve them, then re-run this file.'
        ) AS status;
        SELECT `year_name` AS duplicate_year_name,
               COUNT(*)    AS copies,
               GROUP_CONCAT(`id` ORDER BY `id`) AS academic_year_ids
        FROM `academic_years`
        GROUP BY `year_name`
        HAVING COUNT(*) > 1;
    END IF;
END$$
DELIMITER ;

CALL `ssms_018_unique_year_name`();
DROP PROCEDURE IF EXISTS `ssms_018_unique_year_name`;

-- Deterministic verdict: re-reads the catalogue AFTER the attempt, so the
-- final row reflects reality rather than what we intended to do.
SELECT CASE
    WHEN EXISTS(
        SELECT 1 FROM `information_schema`.`STATISTICS`
        WHERE `TABLE_SCHEMA` = DATABASE()
          AND `TABLE_NAME` = 'academic_years'
          AND `INDEX_NAME` = 'uq_academic_years_year_name'
    ) THEN 'PASS: uq_academic_years_year_name exists.'
    ELSE 'BLOCKER: uq_academic_years_year_name is MISSING — do not sign off this deployment.'
END AS m018_verification;
