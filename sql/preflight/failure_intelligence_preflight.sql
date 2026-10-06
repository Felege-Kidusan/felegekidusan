-- Failure Intelligence — read-only staging preflight for migrations 062-065.
--
-- Run this AFTER applying migrations 062, 063, 064, and 065 and deploying the
-- application code, against the intended staging database:
--
--   mariadb --defaults-extra-file=/secure/staging.cnf staging_db \
--     < sql/preflight/failure_intelligence_preflight.sql
--
-- The script creates only one TEMPORARY table, reads information_schema
-- metadata and aggregate counts, and never changes application data. Do not
-- run it as a migration runner.
--
-- The output is release evidence. It must not contain event payloads, crash
-- signatures, request ids, installation ids, report bodies, member data, or
-- credentials.

SELECT DATABASE() AS selected_database,
       VERSION() AS database_version,
       CURRENT_TIMESTAMP AS checked_at;

DROP TEMPORARY TABLE IF EXISTS ssms_failure_intel_preflight;
CREATE TEMPORARY TABLE ssms_failure_intel_preflight (
    migration_no VARCHAR(8) NOT NULL,
    requirement VARCHAR(160) NOT NULL,
    actual_value VARCHAR(255) NOT NULL,
    expected_value VARCHAR(255) NOT NULL,
    result ENUM('PASS', 'BLOCK') NOT NULL
);

-- ── Required tables ─────────────────────────────────────────────────────────
-- Six failure-intelligence tables plus the two pre-existing channel
-- dependencies the engine reads (arkeon_error_log, notifications).
INSERT INTO ssms_failure_intel_preflight
SELECT '062/065',
       CONCAT('required table ', wanted.table_name),
       COALESCE(t.ENGINE, 'absent'),
       'InnoDB',
       IF(t.ENGINE = 'InnoDB', 'PASS', 'BLOCK')
  FROM (
       SELECT 'api_sync_attempts' AS table_name
       UNION ALL SELECT 'app_telemetry_events'
       UNION ALL SELECT 'app_crash_signatures'
       UNION ALL SELECT 'failure_issues'
       UNION ALL SELECT 'failure_remediation_catalog'
       UNION ALL SELECT 'failure_reports'
       UNION ALL SELECT 'failure_alerts'
       UNION ALL SELECT 'arkeon_error_log'
       UNION ALL SELECT 'notifications'
  ) wanted
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE()
   AND t.TABLE_NAME = wanted.table_name;

-- ── Required columns ────────────────────────────────────────────────────────
-- CREATE TABLE IF NOT EXISTS does not repair a partially-created table. Check
-- every column consumed by the services, admin API, CLI jobs, and alert
-- engine. Note: the failure_reports column `trigger` is a reserved word —
-- it is always referenced backticked in application SQL.
INSERT INTO ssms_failure_intel_preflight
SELECT wanted.migration_no,
       CONCAT('required columns ', wanted.table_name),
       CAST(COUNT(c.COLUMN_NAME) AS CHAR),
       CAST(wanted.expected_count AS CHAR),
       IF(COUNT(c.COLUMN_NAME) = wanted.expected_count, 'PASS', 'BLOCK')
  FROM (
       SELECT '062' AS migration_no,
              'api_sync_attempts' AS table_name,
              3 AS expected_count,
              'app_version,app_build,installation_id' AS required_columns
       UNION ALL
       SELECT '063', 'app_crash_signatures', 9,
              'crash_key,signature_class,signature_frames,first_seen_at,last_seen_at,total_events,app_version_last,app_build_last,updated_at'
       UNION ALL
       SELECT '064', 'failure_issues', 19,
              'id,issue_key,source,category,title,first_seen_at,last_seen_at,total_occurrences,status,resolved_at,resolved_note,resolved_by,regression_count,remediation_cause,remediation_steps,remediation_link,last_auto_report_at,created_at,updated_at'
       UNION ALL
       SELECT '064', 'failure_remediation_catalog', 5,
              'source,category,cause,fix_steps,doc_link'
       UNION ALL
       SELECT '064', 'failure_reports', 9,
              'id,issue_id,severity,window_start,window_end,trigger,report_markdown,generated_by,created_at'
       UNION ALL
       SELECT '065', 'failure_alerts', 11,
              'id,alert_key,kind,severity,title,payload_json,first_sent_at,last_sent_at,sent_count,created_at,updated_at'
  ) wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = wanted.table_name
   AND FIND_IN_SET(c.COLUMN_NAME, wanted.required_columns) > 0
 GROUP BY wanted.migration_no,
          wanted.table_name,
          wanted.expected_count;

-- ── Required index shapes ───────────────────────────────────────────────────
-- Check both name and ordered columns: the grouping/uniqueness contracts
-- (issue fingerprints, alert cooldown keys, crash exact-once) are load-bearing.
INSERT INTO ssms_failure_intel_preflight
SELECT wanted.migration_no,
       CONCAT('index ', wanted.table_name, '.', wanted.index_name),
       COALESCE(idx.actual, 'absent'),
       wanted.expected,
       IF(idx.actual = wanted.expected, 'PASS', 'BLOCK')
  FROM (
       SELECT '062' migration_no, 'api_sync_attempts' table_name,
              'idx_sync_attempts_build_started' index_name,
              'NONUNIQUE:app_build,started_at,id' expected
       UNION ALL SELECT '062', 'api_sync_attempts', 'idx_sync_attempts_install_started',
              'NONUNIQUE:installation_id,started_at,id'

       UNION ALL SELECT '063', 'app_crash_signatures', 'PRIMARY',
              'UNIQUE:crash_key'
       UNION ALL SELECT '063', 'app_crash_signatures', 'idx_crash_sig_last_seen',
              'NONUNIQUE:last_seen_at'
       UNION ALL SELECT '063', 'app_crash_signatures', 'idx_crash_sig_total',
              'NONUNIQUE:total_events'
       UNION ALL SELECT '063', 'app_telemetry_events', 'idx_app_events_type_dedupe',
              'NONUNIQUE:event_type,dedupe_key,installation_id'

       UNION ALL SELECT '064', 'failure_issues', 'PRIMARY',
              'UNIQUE:id'
       UNION ALL SELECT '064', 'failure_issues', 'uq_failure_issues_key',
              'UNIQUE:issue_key'
       UNION ALL SELECT '064', 'failure_issues', 'idx_failure_issues_source_status',
              'NONUNIQUE:source,status,last_seen_at'
       UNION ALL SELECT '064', 'failure_issues', 'idx_failure_issues_last_seen',
              'NONUNIQUE:last_seen_at'
       UNION ALL SELECT '064', 'failure_remediation_catalog', 'PRIMARY',
              'UNIQUE:source,category'
       UNION ALL SELECT '064', 'failure_reports', 'PRIMARY',
              'UNIQUE:id'
       UNION ALL SELECT '064', 'failure_reports', 'idx_failure_reports_issue',
              'NONUNIQUE:issue_id,created_at'
       UNION ALL SELECT '064', 'failure_reports', 'idx_failure_reports_created',
              'NONUNIQUE:created_at'

       UNION ALL SELECT '065', 'failure_alerts', 'PRIMARY',
              'UNIQUE:id'
       UNION ALL SELECT '065', 'failure_alerts', 'uq_failure_alerts_key',
              'UNIQUE:alert_key'
       UNION ALL SELECT '065', 'failure_alerts', 'idx_failure_alerts_last_sent',
              'NONUNIQUE:last_sent_at'
       UNION ALL SELECT '065', 'failure_alerts', 'idx_failure_alerts_kind',
              'NONUNIQUE:kind,last_sent_at'
  ) wanted
  LEFT JOIN (
       SELECT TABLE_NAME,
              INDEX_NAME,
              CONCAT(
                  IF(MIN(NON_UNIQUE) = 0, 'UNIQUE:', 'NONUNIQUE:'),
                  GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
              ) AS actual
         FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        GROUP BY TABLE_NAME, INDEX_NAME
  ) idx
    ON idx.TABLE_NAME = wanted.table_name
   AND idx.INDEX_NAME = wanted.index_name;

-- ── Remediation catalog seed ────────────────────────────────────────────────
-- Migration 064 seeds 28 rows (22 sync failure categories + 6 server error
-- families). A re-run refreshes them; a count below 28 means the seed insert
-- was skipped or partially applied.
INSERT INTO ssms_failure_intel_preflight
SELECT '064',
       'remediation catalog seeded',
       CAST(COUNT(*) AS CHAR),
       '>= 28 (22 sync categories + 6 server error families)',
       IF(COUNT(*) >= 28, 'PASS', 'BLOCK')
  FROM failure_remediation_catalog;

-- ── Informational: sizing and lifecycle evidence ────────────────────────────
-- Aggregate metadata only. The failure tables are expected to stay small
-- (one row per fingerprint/condition); failure_reports grows by kilobytes.
SELECT TABLE_NAME,
       ENGINE,
       TABLE_ROWS,
       ROUND(DATA_LENGTH / 1024 / 1024, 2) AS data_mib,
       ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS index_mib,
       CREATE_TIME,
       UPDATE_TIME
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN (
       'api_sync_attempts',
       'app_crash_signatures',
       'failure_issues',
       'failure_remediation_catalog',
       'failure_reports',
       'failure_alerts'
   )
 ORDER BY TABLE_NAME;

-- Cron installation is deployment evidence, not schema state. Reminders only.
SELECT 'informational' AS result_type,
       'reconcile cron (nightly 03:47)' AS check_name,
       'admin/backend/failure_issue_reconcile.php must be installed and locked' AS observed,
       'Record the installed cron line as release evidence' AS action;
SELECT 'informational' AS result_type,
       'alert cron (every 15 min)' AS check_name,
       'admin/backend/failure_alert_check.php must be installed and locked' AS observed,
       'Record the installed cron line as release evidence' AS action;
SELECT 'informational' AS result_type,
       'failure-alert channel' AS check_name,
       'Notification center (super_admin) is always on; Telegram requires MONITOR_TELEGRAM_* configuration' AS observed,
       'Record which channels are configured as release evidence' AS action;

-- ── Deterministic verdict ───────────────────────────────────────────────────
SELECT migration_no, requirement, actual_value, expected_value, result
  FROM ssms_failure_intel_preflight
 ORDER BY result ASC, migration_no, requirement;

SELECT COUNT(*) INTO @ssms_failure_intel_blocked
  FROM ssms_failure_intel_preflight
 WHERE result = 'BLOCK';

SET @ssms_failure_intel_assert_sql := IF(
    @ssms_failure_intel_blocked > 0,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Failure intelligence preflight blocked: inspect BLOCK rows; do not sign off deployment.'",
    "SELECT 'PASS: failure intelligence schema preflight (062/063/064/065)' AS release_gate"
);
PREPARE ssms_failure_intel_assert_stmt FROM @ssms_failure_intel_assert_sql;
EXECUTE ssms_failure_intel_assert_stmt;
DEALLOCATE PREPARE ssms_failure_intel_assert_stmt;

DROP TEMPORARY TABLE IF EXISTS ssms_failure_intel_preflight;
