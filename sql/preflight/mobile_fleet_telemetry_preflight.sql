-- Mobile Fleet Telemetry & Analytics — read-only staging preflight.
--
-- Run this AFTER deploying the application code and after applying migrations
-- 051 and 059, against the intended staging database. It creates only one
-- TEMPORARY table, reads information_schema metadata, and never changes
-- application data. Do not run this as a migration runner.
--
-- Example:
--   mariadb --defaults-extra-file=/secure/staging.cnf staging_db \
--     < sql/preflight/mobile_fleet_telemetry_preflight.sql
--
-- The output is release evidence. It must not contain request bodies,
-- event_data, tokens, member data, full operation identifiers, or credentials.

SELECT DATABASE() AS selected_database,
       VERSION() AS database_version,
       CURRENT_TIMESTAMP AS checked_at;

DROP TEMPORARY TABLE IF EXISTS ssms_mobile_fleet_preflight;
CREATE TEMPORARY TABLE ssms_mobile_fleet_preflight (
    migration_no VARCHAR(8) NOT NULL,
    requirement VARCHAR(160) NOT NULL,
    actual_value VARCHAR(255) NOT NULL,
    expected_value VARCHAR(255) NOT NULL,
    result ENUM('PASS', 'BLOCK') NOT NULL
);

-- ── Required tables ─────────────────────────────────────────────────────────
INSERT INTO ssms_mobile_fleet_preflight
SELECT '051/059',
       'required telemetry and sync-monitor tables',
       CAST(COUNT(*) AS CHAR),
       '4',
       IF(COUNT(*) = 4, 'PASS', 'BLOCK')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN (
       'app_installations',
       'app_telemetry_events',
       'app_downloads',
       'api_sync_attempts'
   );

-- All four tables must remain transactional. The application deliberately
-- treats monitor bookkeeping as advisory, but a non-transactional table would
-- invalidate the reviewed persistence and cleanup assumptions.
INSERT INTO ssms_mobile_fleet_preflight
SELECT '051/059',
       CONCAT('InnoDB table ', wanted.table_name),
       COALESCE(t.ENGINE, 'absent'),
       'InnoDB',
       IF(t.ENGINE = 'InnoDB', 'PASS', 'BLOCK')
  FROM (
       SELECT 'app_installations' AS table_name
       UNION ALL SELECT 'app_telemetry_events'
       UNION ALL SELECT 'app_downloads'
       UNION ALL SELECT 'api_sync_attempts'
  ) wanted
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE()
   AND t.TABLE_NAME = wanted.table_name;

-- ── Required columns ────────────────────────────────────────────────────────
-- CREATE TABLE IF NOT EXISTS does not repair a partially-created table. Check
-- every column consumed by the route, services, admin API, and monitor.
INSERT INTO ssms_mobile_fleet_preflight
SELECT wanted.migration_no,
       CONCAT('required columns ', wanted.table_name),
       CAST(COUNT(c.COLUMN_NAME) AS CHAR),
       CAST(wanted.expected_count AS CHAR),
       IF(COUNT(c.COLUMN_NAME) = wanted.expected_count, 'PASS', 'BLOCK')
  FROM (
       SELECT '051' AS migration_no,
              'app_installations' AS table_name,
              18 AS expected_count,
              'installation_id,app_version,app_build,os_version,sdk_int,device_brand,device_model,abi,ram_mb,is_low_ram,launch_count,sync_success_count,sync_fail_count,crash_count,last_role_hint,ip_hash,first_seen_at,last_seen_at' AS required_columns
       UNION ALL
       SELECT '051', 'app_telemetry_events', 7,
              'id,installation_id,event_type,event_data,app_version,app_build,created_at'
       UNION ALL
       SELECT '051', 'app_downloads', 7,
              'id,version,build,abi,ip_hash,user_agent,downloaded_at'
       UNION ALL
       SELECT '059', 'api_sync_attempts', 20,
              'id,client_op_id,attempt_uid,attempt_number,execution_source,request_id,user_id,domain,operation,entity_ref,status,idempotency_state,retry_decision,error_category,error_code,http_status,started_at,completed_at,created_at,updated_at'
  ) wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = wanted.table_name
   AND FIND_IN_SET(c.COLUMN_NAME, wanted.required_columns) > 0
 GROUP BY wanted.migration_no,
          wanted.table_name,
          wanted.expected_count;

-- ── Required index shapes ───────────────────────────────────────────────────
-- Check both name and ordered columns. A differently-shaped index can exist
-- while the query plan and uniqueness contract are still wrong.
INSERT INTO ssms_mobile_fleet_preflight
SELECT wanted.migration_no,
       CONCAT('index ', wanted.table_name, '.', wanted.index_name),
       COALESCE(idx.actual, 'absent'),
       wanted.expected,
       IF(idx.actual = wanted.expected, 'PASS', 'BLOCK')
  FROM (
       SELECT '051' migration_no, 'app_installations' table_name, 'PRIMARY' index_name,
              'UNIQUE:installation_id' expected
       UNION ALL SELECT '051', 'app_installations', 'idx_app_install_ver',
              'NONUNIQUE:app_version,app_build'
       UNION ALL SELECT '051', 'app_installations', 'idx_app_install_last_seen',
              'NONUNIQUE:last_seen_at'
       UNION ALL SELECT '051', 'app_installations', 'idx_app_install_brand',
              'NONUNIQUE:device_brand'
       UNION ALL SELECT '051', 'app_installations', 'idx_app_install_os',
              'NONUNIQUE:os_version'

       UNION ALL SELECT '051', 'app_telemetry_events', 'PRIMARY',
              'UNIQUE:id'
       UNION ALL SELECT '051', 'app_telemetry_events', 'idx_app_events_type_created',
              'NONUNIQUE:event_type,created_at'
       UNION ALL SELECT '051', 'app_telemetry_events', 'idx_app_events_install_created',
              'NONUNIQUE:installation_id,created_at'
       UNION ALL SELECT '051', 'app_telemetry_events', 'idx_app_events_created',
              'NONUNIQUE:created_at'

       UNION ALL SELECT '051', 'app_downloads', 'PRIMARY',
              'UNIQUE:id'
       UNION ALL SELECT '051', 'app_downloads', 'idx_app_downloads_ver',
              'NONUNIQUE:version,build'
       UNION ALL SELECT '051', 'app_downloads', 'idx_app_downloads_at',
              'NONUNIQUE:downloaded_at'

       UNION ALL SELECT '059', 'api_sync_attempts', 'PRIMARY',
              'UNIQUE:id'
       UNION ALL SELECT '059', 'api_sync_attempts', 'idx_sync_attempts_status_started',
              'NONUNIQUE:status,started_at,id'
       UNION ALL SELECT '059', 'api_sync_attempts', 'idx_sync_attempts_domain_started',
              'NONUNIQUE:domain,started_at,id'
       UNION ALL SELECT '059', 'api_sync_attempts', 'idx_sync_attempts_source_started',
              'NONUNIQUE:execution_source,started_at,id'
       UNION ALL SELECT '059', 'api_sync_attempts', 'idx_sync_attempts_client_op',
              'NONUNIQUE:client_op_id,started_at'
       UNION ALL SELECT '059', 'api_sync_attempts', 'idx_sync_attempts_attempt_uid',
              'NONUNIQUE:attempt_uid,started_at'
       UNION ALL SELECT '059', 'api_sync_attempts', 'idx_sync_attempts_request',
              'NONUNIQUE:request_id'
       UNION ALL SELECT '059', 'api_sync_attempts', 'idx_sync_attempts_user_started',
              'NONUNIQUE:user_id,started_at'
       UNION ALL SELECT '059', 'api_sync_attempts', 'idx_sync_attempts_created',
              'NONUNIQUE:created_at'
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

-- ── Aggregate sizing and lifecycle evidence ─────────────────────────────────
-- These are informational only. They deliberately expose counts and metadata,
-- never row contents or identifiers.
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
       'app_installations',
       'app_telemetry_events',
       'app_downloads',
       'api_sync_attempts'
   )
 ORDER BY TABLE_NAME;

SELECT 'informational' AS result_type,
       'legacy telemetry retention' AS check_name,
       'No repository cleanup job is defined for app_telemetry_events or app_downloads' AS observed,
       'Define and verify a retention policy before production sign-off' AS action;

SELECT 'informational' AS result_type,
       'sync-monitor retention' AS check_name,
       'Service policy is 90 days, best effort, excluding in_flight rows' AS observed,
       'Confirm cleanup invocation in staging/production operations' AS action;

-- ── Deterministic verdict ───────────────────────────────────────────────────
SELECT migration_no, requirement, actual_value, expected_value, result
  FROM ssms_mobile_fleet_preflight
 ORDER BY result ASC, migration_no, requirement;

SELECT COUNT(*) INTO @ssms_mobile_fleet_blocked
  FROM ssms_mobile_fleet_preflight
 WHERE result = 'BLOCK';

SET @ssms_mobile_fleet_assert_sql := IF(
    @ssms_mobile_fleet_blocked > 0,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mobile fleet telemetry preflight blocked: inspect BLOCK rows; do not sign off deployment.'",
    "SELECT 'PASS: mobile fleet telemetry schema preflight' AS release_gate"
);
PREPARE ssms_mobile_fleet_assert_stmt FROM @ssms_mobile_fleet_assert_sql;
EXECUTE ssms_mobile_fleet_assert_stmt;
DEALLOCATE PREPARE ssms_mobile_fleet_assert_stmt;

DROP TEMPORARY TABLE IF EXISTS ssms_mobile_fleet_preflight;
