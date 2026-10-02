-- ============================================================================
-- 054 — columns the application depends on that no sql/ migration creates
--        (audit finding O, 2026-10-02)
-- ============================================================================
-- WHY
-- Eight columns exist in the live production database but are created by NO
-- file in sql/. They were added during the legacy PHP-migration era
-- (admin/migrations/*.php), which applies them through ADD COLUMN arrays
-- rather than CREATE TABLE. Those files are now HTTP-disabled and marked
-- "Legacy compatibility migration ... apply reviewed, versioned sql/*.sql
-- migrations during deployment", so nothing in the supported deployment path
-- reproduces them.
--
-- This was found by restoring the production dump into a staging database,
-- provisioning a second database from the repository, and diffing
-- information_schema.COLUMNS. Exactly eight columns differed.
--
-- WHY IT MATTERS
-- Two of them are written by live application code:
--
--   members.total_attendance_rate   <- AttendanceSummaryService
--   members.last_attendance_date    <- AttendanceSummaryService (finding E)
--
-- On production they exist, so the code works. On any environment built from
-- this repository they do not, and AttendanceSummaryService fails with
-- "Unknown column". The audit fix for finding E is therefore correct against
-- production but was, until this migration, not reproducible from the repo.
--
-- SOURCE OF THE DEFINITIONS
-- Nothing here is inferred from application queries. Every type, nullability,
-- default, comment, ON UPDATE clause and column position below was read out
-- of information_schema in a restored copy of the authoritative production
-- dump (arkeonet_felegekidusan, MariaDB 11.4.13, dumped 2026-10-02 12:57).
--
-- SAFETY
-- Purely additive and idempotent: each column is added only when its table
-- exists and the column does not. No data is read, modified or deleted, and
-- re-running is a no-op. On production every check is already satisfied, so
-- this migration does nothing there — it exists so a fresh environment
-- matches production.
-- ============================================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS `ssms_054_add_column` $$
CREATE PROCEDURE `ssms_054_add_column`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition VARCHAR(800))
BEGIN
    IF EXISTS(SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
                        AND COLUMN_NAME = p_column) THEN
        SET @ssms_054_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE ssms_054_stmt FROM @ssms_054_sql;
        EXECUTE ssms_054_stmt;
        DEALLOCATE PREPARE ssms_054_stmt;
    END IF;
END $$
DELIMITER ;

-- ── members — attendance rollup columns written by AttendanceSummaryService ──
CALL ssms_054_add_column('members', 'spiritual_level_id',
    'INT UNSIGNED DEFAULT NULL COMMENT ''Current spiritual education level'' AFTER `promoted_at`');
CALL ssms_054_add_column('members', 'total_attendance_rate',
    'DECIMAL(5,2) DEFAULT NULL COMMENT ''Overall attendance percentage'' AFTER `spiritual_level_id`');
CALL ssms_054_add_column('members', 'last_attendance_date',
    'DATE DEFAULT NULL AFTER `total_attendance_rate`');

-- ── academic_years ───────────────────────────────────────────────────────────
CALL ssms_054_add_column('academic_years', 'created_by',
    'INT UNSIGNED DEFAULT NULL AFTER `status`');
CALL ssms_054_add_column('academic_years', 'updated_at',
    'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

-- ── bookkeeping timestamps ───────────────────────────────────────────────────
CALL ssms_054_add_column('member_code_sequences', 'updated_at',
    'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `last_n`');
CALL ssms_054_add_column('member_type_settings', 'updated_at',
    'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `sort_order`');

-- ── teacher_assignments.status ───────────────────────────────────────────────
-- Production carries BOTH is_active and this status enum. Preserved as-is;
-- reconciling the two is a separate decision, not this migration's business.
CALL ssms_054_add_column('teacher_assignments', 'status',
    'ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active'' AFTER `is_active`');

DROP PROCEDURE IF EXISTS `ssms_054_add_column`;

-- ── Deterministic verification ───────────────────────────────────────────────
SELECT
    CASE WHEN (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND (
            (TABLE_NAME = 'members' AND COLUMN_NAME IN
                ('spiritual_level_id','total_attendance_rate','last_attendance_date')) OR
            (TABLE_NAME = 'academic_years' AND COLUMN_NAME IN ('created_by','updated_at')) OR
            (TABLE_NAME = 'member_code_sequences' AND COLUMN_NAME = 'updated_at') OR
            (TABLE_NAME = 'member_type_settings' AND COLUMN_NAME = 'updated_at') OR
            (TABLE_NAME = 'teacher_assignments' AND COLUMN_NAME = 'status'))
    ) = 8
    THEN 'PASS: all 8 legacy-era columns present.'
    ELSE 'BLOCKER: a legacy-era column is still missing — AttendanceSummaryService will fail on this database.'
    END AS legacy_column_verification;
