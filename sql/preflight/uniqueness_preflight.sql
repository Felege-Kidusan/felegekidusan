-- ============================================================================
-- Conditional-UNIQUE preflight / verification.  (audit finding H, 2026-10-02)
-- MariaDB 10.6+.  READ-ONLY: creates a TEMPORARY table only. It never
-- inserts, updates, merges or deletes application data.
--
-- WHY THIS EXISTS
-- Several migrations add a UNIQUE constraint only when the existing data is
-- already clean, and skip it otherwise so that a deployment is never blocked
-- by — and never silently destroys — duplicate rows a human must adjudicate.
-- That safety behaviour is correct and is deliberately preserved. The gap was
-- VISIBILITY: a skip looked exactly like a success, so a database could go
-- live missing a uniqueness guarantee the application assumes. Specifically:
--
--   • 018 skipped `uq_academic_years_year_name` SILENTLY. Without that index
--     the errno-1062 branch in the academic-year save endpoint can never
--     fire, so duplicate year names stay reachable under concurrency.
--   • 031 reported "skipped (duplicates present or index already exists)" —
--     one message for a BLOCKER and for a harmless no-op.
--
-- Both files now report their own outcome distinctly. This script is the
-- independent, deterministic check: run it BEFORE deploying (to find the work
-- to do) and AFTER any duplicate cleanup (to prove the work is done). A BLOCK
-- row raises SQLSTATE 45000, so the result cannot be read as "probably fine".
--
-- Resolving a BLOCK is a human decision — these are user-owned records and
-- choosing which copy survives is a business call, not a migration's call.
-- Each BLOCK is accompanied by a listing of the exact offending rows.
-- ============================================================================

SELECT DATABASE() AS selected_database,
       VERSION()  AS database_version,
       NOW()      AS checked_at;

DROP TEMPORARY TABLE IF EXISTS ssms_uniqueness_preflight;
CREATE TEMPORARY TABLE ssms_uniqueness_preflight (
    source_migration VARCHAR(8)   NOT NULL,
    constraint_name  VARCHAR(80)  NOT NULL,
    target_table     VARCHAR(64)  NOT NULL,
    detail           VARCHAR(255) NOT NULL,
    -- PENDING is distinct from BLOCK on purpose: a database whose data is
    -- clean and simply has not had the owning migration applied yet needs a
    -- mechanical step, not a business decision about which duplicate row
    -- survives. Reporting that case as BLOCK sent operators hunting for
    -- duplicates that did not exist.
    result           ENUM('PASS', 'PENDING', 'BLOCK', 'SKIP') NOT NULL
);

-- ── 018 — academic_years.year_name ──────────────────────────────────────────
SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_years');
SET @i_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_years'
                    AND INDEX_NAME = 'uq_academic_years_year_name');
SET @dups := 0;
SET @sql := IF(@t_exists = 1,
    'SELECT COUNT(*) INTO @dups FROM (SELECT 1 FROM `academic_years` GROUP BY `year_name` HAVING COUNT(*) > 1) d',
    'SELECT 0 INTO @dups');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO ssms_uniqueness_preflight VALUES (
    '018', 'uq_academic_years_year_name', 'academic_years',
    CASE WHEN @t_exists = 0 THEN 'table not present on this deployment'
         WHEN @i_exists > 0 THEN 'constraint present'
         WHEN @dups = 0 THEN 'constraint MISSING; data is clean -- apply migration 018'
         ELSE CONCAT('constraint MISSING; ', @dups, ' duplicate year_name value(s) block it')
    END,
    CASE WHEN @t_exists = 0 THEN 'SKIP'
         WHEN @i_exists > 0 THEN 'PASS'
         WHEN @dups = 0 THEN 'PENDING'
         ELSE 'BLOCK' END
);

-- ── 031 — mezmur_hymns.title (case-insensitive) ─────────────────────────────
SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mezmur_hymns');
SET @i_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mezmur_hymns'
                    AND INDEX_NAME = 'uq_mezmur_hymns_title');
SET @dups := 0;
SET @sql := IF(@t_exists = 1,
    'SELECT COUNT(*) INTO @dups FROM (SELECT 1 FROM `mezmur_hymns` GROUP BY LOWER(`title`) HAVING COUNT(*) > 1) d',
    'SELECT 0 INTO @dups');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO ssms_uniqueness_preflight VALUES (
    '031', 'uq_mezmur_hymns_title', 'mezmur_hymns',
    CASE WHEN @t_exists = 0 THEN 'table not present on this deployment'
         WHEN @i_exists > 0 THEN 'constraint present'
         WHEN @dups = 0 THEN 'constraint MISSING; data is clean -- apply migration 031'
         ELSE CONCAT('constraint MISSING; ', @dups, ' case-insensitive duplicate title(s) block it')
    END,
    CASE WHEN @t_exists = 0 THEN 'SKIP'
         WHEN @i_exists > 0 THEN 'PASS'
         WHEN @dups = 0 THEN 'PENDING'
         ELSE 'BLOCK' END
);

-- ── 013 — academic_records (assessment_id, member_id) ───────────────────────
SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_records');
SET @i_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_records'
                    AND INDEX_NAME = 'uq_ar_assessment_member');
INSERT INTO ssms_uniqueness_preflight VALUES (
    '013', 'uq_ar_assessment_member', 'academic_records',
    CASE WHEN @t_exists = 0 THEN 'table not present on this deployment'
         WHEN @i_exists > 0 THEN 'constraint present'
         ELSE 'constraint MISSING — 013 did not complete; re-run it' END,
    CASE WHEN @t_exists = 0 THEN 'SKIP'
         WHEN @i_exists > 0 THEN 'PASS'
         ELSE 'BLOCK' END
);

-- ── 013 — attendance (member_id, class_id, attendance_date) ─────────────────
SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance');
SET @i_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
                    AND INDEX_NAME = 'uq_att_member_class_date');
INSERT INTO ssms_uniqueness_preflight VALUES (
    '013', 'uq_att_member_class_date', 'attendance',
    CASE WHEN @t_exists = 0 THEN 'table not present on this deployment'
         WHEN @i_exists > 0 THEN 'constraint present'
         ELSE 'constraint MISSING — 013 did not complete; re-run it' END,
    CASE WHEN @t_exists = 0 THEN 'SKIP'
         WHEN @i_exists > 0 THEN 'PASS'
         ELSE 'BLOCK' END
);

-- ── 053 — grade_submissions attendance slot ────────────────────────────────
SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_submissions');
SET @i_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_submissions'
                    AND INDEX_NAME = 'uq_gs_attendance_slot');
SET @dups := 0;
SET @sql := IF(@t_exists = 1,
    'SELECT COUNT(*) INTO @dups FROM (SELECT 1 FROM `grade_submissions`
       WHERE `submission_type` = ''attendance'' AND `attendance_date` IS NOT NULL
       GROUP BY `class_id`, `attendance_date` HAVING COUNT(*) > 1) d',
    'SELECT 0 INTO @dups');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO ssms_uniqueness_preflight VALUES (
    '053', 'uq_gs_attendance_slot', 'grade_submissions',
    CASE WHEN @t_exists = 0 THEN 'table not present on this deployment'
         WHEN @i_exists > 0 THEN 'constraint present'
         WHEN @dups = 0 THEN 'constraint MISSING; data is clean -- apply migration 053'
         ELSE CONCAT('constraint MISSING; ', @dups,
                     ' duplicated (class, date) attendance slot(s) block it — review lock bypassable')
    END,
    CASE WHEN @t_exists = 0 THEN 'SKIP'
         WHEN @i_exists > 0 THEN 'PASS'
         WHEN @dups = 0 THEN 'PENDING'
         ELSE 'BLOCK' END
);

-- ── 053 — grade_submissions mark-list slot ─────────────────────────────────
SET @i_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_submissions'
                    AND INDEX_NAME = 'uq_gs_marklist_slot');
SET @dups := 0;
SET @sql := IF(@t_exists = 1,
    'SELECT COUNT(*) INTO @dups FROM (SELECT 1 FROM `grade_submissions`
       WHERE `submission_type` = ''marklist'' AND `assessment_id` IS NOT NULL
       GROUP BY `assessment_id` HAVING COUNT(*) > 1) d',
    'SELECT 0 INTO @dups');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO ssms_uniqueness_preflight VALUES (
    '053', 'uq_gs_marklist_slot', 'grade_submissions',
    CASE WHEN @t_exists = 0 THEN 'table not present on this deployment'
         WHEN @i_exists > 0 THEN 'constraint present'
         WHEN @dups = 0 THEN 'constraint MISSING; data is clean -- apply migration 053'
         ELSE CONCAT('constraint MISSING; ', @dups,
                     ' duplicated assessment mark-list slot(s) block it')
    END,
    CASE WHEN @t_exists = 0 THEN 'SKIP'
         WHEN @i_exists > 0 THEN 'PASS'
         WHEN @dups = 0 THEN 'PENDING'
         ELSE 'BLOCK' END
);

-- ── Verdict table ───────────────────────────────────────────────────────────
SELECT source_migration, constraint_name, target_table, detail, result
  FROM ssms_uniqueness_preflight
 ORDER BY result = 'PASS', source_migration, constraint_name;

-- ── Offending rows, printed only when something is actually blocked ─────────
-- These listings name the rows a human must adjudicate. Nothing is changed.
SET @blocked_018 := (SELECT COUNT(*) FROM ssms_uniqueness_preflight
                     WHERE constraint_name = 'uq_academic_years_year_name' AND result = 'BLOCK');
SET @sql := IF(@blocked_018 > 0,
    'SELECT `year_name` AS duplicate_year_name, COUNT(*) AS copies, GROUP_CONCAT(`id` ORDER BY `id`) AS academic_year_ids
       FROM `academic_years` GROUP BY `year_name` HAVING COUNT(*) > 1',
    'SELECT ''none'' AS academic_year_duplicates');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @blocked_031 := (SELECT COUNT(*) FROM ssms_uniqueness_preflight
                     WHERE constraint_name = 'uq_mezmur_hymns_title' AND result = 'BLOCK');
SET @sql := IF(@blocked_031 > 0,
    'SELECT LOWER(`title`) AS duplicate_title_ci, COUNT(*) AS copies, GROUP_CONCAT(`id` ORDER BY `id`) AS hymn_ids
       FROM `mezmur_hymns` GROUP BY LOWER(`title`) HAVING COUNT(*) > 1',
    'SELECT ''none'' AS mezmur_title_duplicates');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Informational: 013 quarantined rows awaiting administrator review ───────
-- 013 moved displaced duplicates into these tables instead of discarding
-- them. Rows here are NOT a deployment blocker, but they are unreviewed user
-- data and should not be forgotten.
SET @q1 := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'migration_013_academic_record_conflicts');
SET @sql := IF(@q1 = 1,
    'SELECT ''migration_013_academic_record_conflicts'' AS quarantine_table, COUNT(*) AS rows_awaiting_review
       FROM `migration_013_academic_record_conflicts`',
    'SELECT ''migration_013_academic_record_conflicts'' AS quarantine_table, ''absent'' AS rows_awaiting_review');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @q2 := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'migration_013_attendance_conflicts');
SET @sql := IF(@q2 = 1,
    'SELECT ''migration_013_attendance_conflicts'' AS quarantine_table, COUNT(*) AS rows_awaiting_review
       FROM `migration_013_attendance_conflicts`',
    'SELECT ''migration_013_attendance_conflicts'' AS quarantine_table, ''absent'' AS rows_awaiting_review');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Deterministic assertion ─────────────────────────────────────────────────
SELECT COUNT(*) INTO @ssms_blocked
  FROM ssms_uniqueness_preflight WHERE result = 'BLOCK';
SELECT COUNT(*) INTO @ssms_pending
  FROM ssms_uniqueness_preflight WHERE result = 'PENDING';
-- Both tiers exit non-zero: a constraint the application depends on is
-- absent either way. They are reported separately because the remedies
-- differ completely -- PENDING is "run the migration", BLOCK is "a human
-- must decide which duplicate row is authoritative".
SET @ssms_assert_sql := CASE
    WHEN @ssms_blocked > 0 THEN
        "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Uniqueness preflight BLOCKED: duplicate data prevents a UNIQUE constraint the application depends on. Inspect the BLOCK rows and the duplicate listings above, decide which record is authoritative, re-point dependent rows, re-run the owning migration, then re-run this script. Do NOT delete rows merely to make this pass.'"
    WHEN @ssms_pending > 0 THEN
        "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Uniqueness preflight PENDING: the data is clean but one or more owning migrations have not been applied to this database. Apply the migrations named in the PENDING rows above, then re-run this script. No data decision is required.'"
    ELSE
        "SELECT 'PASS: every conditional UNIQUE constraint is present.' AS uniqueness_preflight"
END;
PREPARE ssms_assert_stmt FROM @ssms_assert_sql;
EXECUTE ssms_assert_stmt;
DEALLOCATE PREPARE ssms_assert_stmt;
