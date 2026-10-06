-- Mobile Fleet Telemetry — post-drill reconciliation report.
--
-- READ-ONLY for application and monitoring data. It creates one temporary
-- report table, reads information_schema and aggregate counts, and never
-- inserts, updates, or deletes a business/telemetry/monitor row.
--
-- Run only after an authorized staging drill and the retention job:
--   mariadb --defaults-extra-file=/secure/staging.cnf staging_database \
--     < sql/preflight/mobile_fleet_telemetry_postdrill.sql \
--     | tee /secure/release-evidence/mobile-fleet-telemetry-postdrill.txt
--
-- Output is aggregate-only. Do not add SELECTs that expose event_data,
-- installation IDs, request IDs, member IDs, tokens, or operation IDs.

SELECT DATABASE() AS selected_database,
       VERSION() AS database_version,
       CURRENT_TIMESTAMP AS checked_at;

DROP TEMPORARY TABLE IF EXISTS ssms_mobile_fleet_postdrill;
CREATE TEMPORARY TABLE ssms_mobile_fleet_postdrill (
    check_name VARCHAR(160) NOT NULL,
    observed_value VARCHAR(255) NOT NULL,
    expected_value VARCHAR(255) NOT NULL,
    result ENUM('PASS', 'BLOCK', 'INFO') NOT NULL,
    action VARCHAR(255) NOT NULL
);

-- ── Schema contract for 051/059/060 ─────────────────────────────────────────
INSERT INTO ssms_mobile_fleet_postdrill
SELECT 'required telemetry and monitor tables',
       CAST(COUNT(*) AS CHAR),
       '4',
       IF(COUNT(*) = 4, 'PASS', 'BLOCK'),
       'Apply 051 and 059 before repeating the drill'
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN (
       'app_installations',
       'app_telemetry_events',
       'app_downloads',
       'api_sync_attempts'
   );

INSERT INTO ssms_mobile_fleet_postdrill
SELECT 'app_telemetry_events.dedupe_key column',
       CAST(COUNT(*) AS CHAR),
       '1',
       IF(COUNT(*) = 1, 'PASS', 'BLOCK'),
       'Apply 060_telemetry_integrity_hardening.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'app_telemetry_events'
   AND COLUMN_NAME = 'dedupe_key';

INSERT INTO ssms_mobile_fleet_postdrill
SELECT 'app_telemetry_events dedupe index',
       COALESCE(idx.actual, 'absent'),
       'UNIQUE:installation_id,event_type,dedupe_key',
       IF(idx.actual = 'UNIQUE:installation_id,event_type,dedupe_key', 'PASS', 'BLOCK'),
       'Apply 060 and verify the ordered unique index'
  FROM (SELECT 1 AS marker) one
  LEFT JOIN (
       SELECT INDEX_NAME,
              CONCAT(
                  IF(MIN(NON_UNIQUE) = 0, 'UNIQUE:', 'NONUNIQUE:'),
                  GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
              ) AS actual
         FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'app_telemetry_events'
          AND INDEX_NAME = 'uq_app_events_dedupe'
        GROUP BY INDEX_NAME
  ) idx ON 1 = 1;

-- ── Integrity checks from real controlled activity ──────────────────────────
-- A duplicate group here means the dedupe contract was not applied or the
-- post-hardening route is not using the unique crash key correctly.
INSERT INTO ssms_mobile_fleet_postdrill
SELECT 'duplicate crash-key groups',
       CAST(COUNT(*) AS CHAR),
       '0',
       IF(COUNT(*) = 0, 'PASS', 'BLOCK'),
       'Stop release; inspect migration 060 and crash delivery reconciliation'
  FROM (
       SELECT COUNT(*) AS event_count
         FROM app_telemetry_events
        WHERE event_type IN ('crash', 'crash_recorded')
          AND dedupe_key IS NOT NULL
        GROUP BY installation_id, event_type, dedupe_key
       HAVING COUNT(*) > 1
  ) duplicate_groups;

-- Informational only: this includes legacy history from before the current
-- client contract. The route normalizes these fields for new legacy deliveries.
SELECT 'informational' AS result_type,
       'legacy raw-payload markers' AS check_name,
       CAST(COUNT(*) AS CHAR) AS observed,
       '0 for current-client drill rows; historical rows require interpretation' AS expected,
       'Do not expose or export event_data; investigate only through aggregate evidence' AS action
  FROM app_telemetry_events
 WHERE event_data IS NOT NULL
   AND JSON_VALID(event_data)
   AND (
       JSON_CONTAINS_PATH(event_data, 'one', '$.summary')
       OR JSON_CONTAINS_PATH(event_data, 'one', '$.error')
   );

-- Retention is informational because cleanup is deliberately bounded. A
-- non-zero value means the operator should repeat the job and record whether
-- backlog_possible was true; it is not permission to run an unbounded DELETE.
SELECT 'informational' AS result_type,
       'telemetry rows older than 90 days' AS check_name,
       CAST((
           (SELECT COUNT(*) FROM app_telemetry_events
             WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY))
           +
           (SELECT COUNT(*) FROM app_downloads
             WHERE downloaded_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY))
       ) AS CHAR) AS observed,
       '0 for a clean staging fixture; production backlog must be bounded and documented' AS expected,
       'Run admin/backend/mobile_telemetry_retention.php again if backlog_possible is true' AS action;

-- The monitor count is evidence only. This report never prunes or rewrites it.
SELECT 'informational' AS result_type,
       'server-observed sync monitor rows' AS check_name,
       CAST(COUNT(*) AS CHAR) AS observed,
       'Reconcile with the controlled sync/retry drill' AS expected,
       'Do not modify api_sync_attempts from this report' AS action
  FROM api_sync_attempts;

-- app_installations is the lifetime counter basis and is intentionally never
-- deleted by the retention job. This row keeps growth visible so an operator
-- can make an explicit, documented decision; this report never prunes it.
SELECT 'informational' AS result_type,
       'installations unseen for 395+ days (retained by design)' AS check_name,
       CAST(COUNT(*) AS CHAR) AS observed,
       'Growth awareness only - lifetime counter basis' AS expected,
       'If unbounded, make an explicit retention decision and document it' AS action
  FROM app_installations
 WHERE last_seen_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 395 DAY);

-- ── Aggregate-only gate output ──────────────────────────────────────────────
SELECT check_name, observed_value, expected_value, result, action
  FROM ssms_mobile_fleet_postdrill
 ORDER BY result DESC, check_name;

SELECT COUNT(*) AS blocking_checks
  FROM ssms_mobile_fleet_postdrill
 WHERE result = 'BLOCK';

DROP TEMPORARY TABLE IF EXISTS ssms_mobile_fleet_postdrill;
