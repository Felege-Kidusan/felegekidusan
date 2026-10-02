-- ============================================================================
-- 056 — Subject duration (SEMESTER_ONLY / FULL_YEAR) + per-year semester weights
-- ============================================================================
-- WHY
-- The Education module had no concept of how long a subject runs. `subjects`
-- is a flat catalogue and `class_subjects` is a bare (class_id, subject_id)
-- pair with no term and no duration, so every subject was implicitly treated
-- as running for the whole academic year.
--
-- That assumption is wrong for this school. Two kinds of subject exist:
--
--   SEMESTER_ONLY  completed inside one semester; its final score IS that
--                  semester's score, and it does not continue.
--   FULL_YEAR      runs across both semesters; its final annual score is a
--                  weighted combination of the two semester scores.
--
-- CONSEQUENCE THIS FIXES
-- ReportCardService::buildClassBundle() passes term_id = 0 straight through
-- when no term is requested (the default for the report endpoints), and
-- fetchScores() only filters by term when term_id > 0. Every semester's
-- academic_records therefore landed in a single aggregateSubject() call and
-- were blended into one percentage — raw Semester 1 and Semester 2 marks
-- averaged together. A FULL_YEAR subject's annual score must instead be
-- computed per semester first, then combined using the configured weights.
--
-- WHAT THIS MIGRATION ADDS
--   class_subjects.duration_type  SEMESTER_ONLY | FULL_YEAR, NULL = unclassified
--   class_subjects.term_id        which semester a SEMESTER_ONLY offering runs in
--   academic_years.s1_weight_pct  Semester 1 weight for this year's annual score
--   academic_years.s2_weight_pct  Semester 2 weight for this year's annual score
--
-- DELIBERATE DECISIONS
-- 1. duration_type is NULLABLE with NO default. The 2,627 existing
--    class_subjects rows carry no evidence of which semester they belong to,
--    so this migration does NOT guess. NULL means "unclassified" and the
--    application preserves the previous behaviour for those rows until a
--    human classifies them. Nothing silently changes meaning.
-- 2. Weights live on `academic_years`, not in system_settings, so that a
--    closed year keeps the distribution it was actually graded under and
--    historical annual results stay reproducible. Defaults are 50.00/50.00 as
--    DATA, not as a constant in business logic.
-- 3. A CHECK constraint enforces s1 + s2 = 100 at the storage layer, so an
--    invalid distribution cannot be persisted even by direct SQL.
-- 4. term_id gets a plain INDEX, deliberately NOT a FOREIGN KEY. The audit's
--    restore tooling (scripts/restore_production_dump.sh) asserts an exact
--    foreign-key inventory of 39 (pre-055) or 42 (post-055); adding a 43rd FK
--    here would make that script report MISMATCH on the next production dump
--    and tell operators a good restore had failed. Referential validity of
--    term_id is enforced in the service layer instead, and the trade-off is
--    recorded here rather than hidden.
--
-- SAFETY
-- Purely additive and idempotent. No row is read, modified or deleted. Every
-- column is added only when absent, so re-running is a no-op.
-- ============================================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `ssms_056_add_column` $$
CREATE PROCEDURE `ssms_056_add_column`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition VARCHAR(800))
BEGIN
    IF EXISTS(SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
                        AND COLUMN_NAME = p_column) THEN
        SET @ssms_056_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE ssms_056_stmt FROM @ssms_056_sql;
        EXECUTE ssms_056_stmt;
        DEALLOCATE PREPARE ssms_056_stmt;
    END IF;
END $$

DROP PROCEDURE IF EXISTS `ssms_056_add_index` $$
CREATE PROCEDURE `ssms_056_add_index`(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
    IF EXISTS(SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
                        AND INDEX_NAME = p_index) THEN
        SET @ssms_056_isql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
        PREPARE ssms_056_istmt FROM @ssms_056_isql;
        EXECUTE ssms_056_istmt;
        DEALLOCATE PREPARE ssms_056_istmt;
    END IF;
END $$
DELIMITER ;

-- ── class_subjects — the subject offering ────────────────────────────────────
CALL ssms_056_add_column('class_subjects', 'duration_type',
    'ENUM(''SEMESTER_ONLY'',''FULL_YEAR'') DEFAULT NULL COMMENT ''NULL = unclassified; see migration 056'' AFTER `subject_id`');
CALL ssms_056_add_column('class_subjects', 'term_id',
    'INT UNSIGNED DEFAULT NULL COMMENT ''Semester a SEMESTER_ONLY offering runs in; NULL for FULL_YEAR'' AFTER `duration_type`');
CALL ssms_056_add_index('class_subjects', 'idx_class_subjects_term', '`term_id`');
CALL ssms_056_add_index('class_subjects', 'idx_class_subjects_duration', '`class_id`, `duration_type`');

-- ── academic_years — per-year semester weighting ─────────────────────────────
CALL ssms_056_add_column('academic_years', 's1_weight_pct',
    'DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT ''Semester 1 share of the annual subject score''');
CALL ssms_056_add_column('academic_years', 's2_weight_pct',
    'DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT ''Semester 2 share of the annual subject score''');

DROP PROCEDURE IF EXISTS `ssms_056_add_column`;
DROP PROCEDURE IF EXISTS `ssms_056_add_index`;

-- ── Weights must total 100 ───────────────────────────────────────────────────
-- Added after the columns so existing rows already hold the 50/50 default and
-- cannot fail validation. Wrapped so a re-run does not error on an existing
-- constraint.
DELIMITER $$
DROP PROCEDURE IF EXISTS `ssms_056_add_weight_check` $$
CREATE PROCEDURE `ssms_056_add_weight_check`()
BEGIN
    IF NOT EXISTS(SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE()
                    AND CONSTRAINT_NAME = 'chk_academic_year_semester_weights') THEN
        ALTER TABLE `academic_years`
            ADD CONSTRAINT `chk_academic_year_semester_weights`
            CHECK (`s1_weight_pct` + `s2_weight_pct` = 100.00);
    END IF;
END $$
DELIMITER ;
CALL ssms_056_add_weight_check();
DROP PROCEDURE IF EXISTS `ssms_056_add_weight_check`;

-- ── Deterministic verification ───────────────────────────────────────────────
SELECT
    CASE WHEN (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND (
            (TABLE_NAME = 'class_subjects'  AND COLUMN_NAME IN ('duration_type','term_id')) OR
            (TABLE_NAME = 'academic_years'  AND COLUMN_NAME IN ('s1_weight_pct','s2_weight_pct')))
    ) = 4
    THEN 'PASS: subject duration + per-year semester weights present.'
    ELSE 'FAIL: expected 4 columns from migration 056.'
    END AS migration_056_status;
