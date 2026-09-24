-- Build 24 authorization/outbox rollout: read-only production preflight.
-- MariaDB 10.6+. Run BEFORE migrations 009/010/044/045/046/048.
-- This script creates TEMPORARY objects only; it does not change app data.
-- A BLOCK result raises SQLSTATE 45000 at the end. Preserve the output as a
-- release artifact, but do not publish it: grants and schema sizes are private.

SELECT DATABASE() AS selected_database,
       VERSION() AS database_version,
       CURRENT_USER() AS database_principal;
SHOW GRANTS FOR CURRENT_USER;

DROP TEMPORARY TABLE IF EXISTS ssms_rollout_preflight;
CREATE TEMPORARY TABLE ssms_rollout_preflight (
    migration_no VARCHAR(8) NOT NULL,
    requirement VARCHAR(120) NOT NULL,
    actual_value VARCHAR(255) NOT NULL,
    expected_value VARCHAR(255) NOT NULL,
    result ENUM('PASS', 'BLOCK') NOT NULL
);

SET @ssms_db_version := VERSION();
SET @ssms_db_major := CAST(SUBSTRING_INDEX(@ssms_db_version, '.', 1) AS UNSIGNED);
SET @ssms_db_minor := CAST(
    SUBSTRING_INDEX(SUBSTRING_INDEX(@ssms_db_version, '.', 2), '.', -1)
    AS UNSIGNED
);
INSERT INTO ssms_rollout_preflight VALUES (
    'all', 'database family and version', @ssms_db_version, 'MariaDB 10.6+',
    IF(LOCATE('MariaDB', @ssms_db_version) > 0
       AND (@ssms_db_major > 10 OR (@ssms_db_major = 10 AND @ssms_db_minor >= 6)),
       'PASS', 'BLOCK')
);

-- Core source tables. Migrations 044-048 cannot be safely applied without
-- these exact predecessors.
INSERT INTO ssms_rollout_preflight
SELECT '044-048', 'required source tables',
       CAST(COUNT(*) AS CHAR), '4', IF(COUNT(*) = 4, 'PASS', 'BLOCK')
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('users', 'teacher_assignments', 'messages', 'message_threads');

INSERT INTO ssms_rollout_preflight
SELECT '048', 'users prerequisite columns',
       CAST(COUNT(*) AS CHAR), '3', IF(COUNT(*) = 3, 'PASS', 'BLOCK')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND COLUMN_NAME IN ('id', 'role', 'is_active');

INSERT INTO ssms_rollout_preflight
SELECT '048', 'migration 006 teacher assignment columns',
       CAST(COUNT(*) AS CHAR), '8', IF(COUNT(*) = 8, 'PASS', 'BLOCK')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_assignments'
   AND COLUMN_NAME IN (
       'teacher_id', 'class_id', 'subject_id', 'academic_year_id',
       'is_active', 'is_primary', 'is_class_teacher', 'assignment_role'
   );

INSERT INTO ssms_rollout_preflight
SELECT '044-046', 'messages prerequisite columns',
       CAST(COUNT(*) AS CHAR), '3', IF(COUNT(*) = 3, 'PASS', 'BLOCK')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
   AND COLUMN_NAME IN ('id', 'thread_id', 'body');

INSERT INTO ssms_rollout_preflight
SELECT '045', 'message_threads prerequisite columns',
       CAST(COUNT(*) AS CHAR), '2', IF(COUNT(*) = 2, 'PASS', 'BLOCK')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_threads'
   AND COLUMN_NAME IN ('id', 'last_message_at');

-- CREATE TABLE IF NOT EXISTS does not repair a partially-created table. An
-- existing 009/010 table must therefore contain every column the code uses.
INSERT INTO ssms_rollout_preflight
SELECT '009', 'api_idempotency absent or complete',
       CONCAT(IF(t.TABLE_NAME IS NULL, 'absent', 'present'), '/', COUNT(c.COLUMN_NAME)),
       'absent or 5 required columns',
       IF(t.TABLE_NAME IS NULL OR COUNT(c.COLUMN_NAME) = 5, 'PASS', 'BLOCK')
  FROM (SELECT 'api_idempotency' AS wanted) x
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = x.wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = x.wanted
   AND c.COLUMN_NAME IN ('idem_key', 'user_id', 'status_code', 'body', 'created_at')
 GROUP BY t.TABLE_NAME;

INSERT INTO ssms_rollout_preflight
SELECT '009', 'api_idempotency_records absent or complete',
       CONCAT(IF(t.TABLE_NAME IS NULL, 'absent', 'present'), '/', COUNT(c.COLUMN_NAME)),
       'absent or 13 required columns',
       IF(t.TABLE_NAME IS NULL OR COUNT(c.COLUMN_NAME) = 13, 'PASS', 'BLOCK')
  FROM (SELECT 'api_idempotency_records' AS wanted) x
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = x.wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = x.wanted
   AND c.COLUMN_NAME IN (
       'record_hash', 'user_id', 'idem_key', 'request_scope', 'request_hash',
       'owner_token', 'record_state', 'status_code', 'response_body',
       'lease_expires_at', 'expires_at', 'created_at', 'updated_at'
   )
 GROUP BY t.TABLE_NAME;

INSERT INTO ssms_rollout_preflight
SELECT '010', 'api_refresh_sessions absent or complete',
       CONCAT(IF(t.TABLE_NAME IS NULL, 'absent', 'present'), '/', COUNT(c.COLUMN_NAME)),
       'absent or 12 required columns',
       IF(t.TABLE_NAME IS NULL OR COUNT(c.COLUMN_NAME) = 12, 'PASS', 'BLOCK')
  FROM (SELECT 'api_refresh_sessions' AS wanted) x
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = x.wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = x.wanted
   AND c.COLUMN_NAME IN (
       'session_id', 'family_id', 'user_id', 'token_hash', 'replaced_by',
       'expires_at', 'consumed_at', 'revoked_at', 'created_ip',
       'user_agent_hash', 'created_at', 'last_used_at'
   )
 GROUP BY t.TABLE_NAME;

INSERT INTO ssms_rollout_preflight
SELECT '010', 'api_refresh_legacy_exchanges absent or complete',
       CONCAT(IF(t.TABLE_NAME IS NULL, 'absent', 'present'), '/', COUNT(c.COLUMN_NAME)),
       'absent or 4 required columns',
       IF(t.TABLE_NAME IS NULL OR COUNT(c.COLUMN_NAME) = 4, 'PASS', 'BLOCK')
  FROM (SELECT 'api_refresh_legacy_exchanges' AS wanted) x
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = x.wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = x.wanted
   AND c.COLUMN_NAME IN ('token_hash', 'user_id', 'family_id', 'exchanged_at')
 GROUP BY t.TABLE_NAME;

-- Transactional storage is required for request reservations, rotation, exact
-- queue settlement, and authorization trigger updates.
INSERT INTO ssms_rollout_preflight
SELECT 'all', CONCAT('transactional table ', wanted.table_name),
       COALESCE(t.ENGINE, 'absent'),
       IF(wanted.required_now = 1, 'InnoDB', 'absent or InnoDB'),
       IF((wanted.required_now = 0 AND t.TABLE_NAME IS NULL) OR t.ENGINE = 'InnoDB',
          'PASS', 'BLOCK')
  FROM (
       SELECT 'users' table_name, 1 required_now
       UNION ALL SELECT 'teacher_assignments', 1
       UNION ALL SELECT 'messages', 1
       UNION ALL SELECT 'message_threads', 1
       UNION ALL SELECT 'api_idempotency', 0
       UNION ALL SELECT 'api_idempotency_records', 0
       UNION ALL SELECT 'api_refresh_sessions', 0
       UNION ALL SELECT 'api_refresh_legacy_exchanges', 0
  ) wanted
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = wanted.table_name;

-- CREATE TABLE IF NOT EXISTS also cannot repair missing/malformed indexes on a
-- partial 009/010 table. If the table exists, every reviewed index must match.
INSERT INTO ssms_rollout_preflight
SELECT wanted.migration_no, CONCAT('existing index ', wanted.table_name, '.', wanted.index_name),
       IF(t.TABLE_NAME IS NULL, 'table absent', COALESCE(idx.actual, 'index absent')),
       CONCAT('table absent or ', wanted.expected),
       IF(t.TABLE_NAME IS NULL OR idx.actual = wanted.expected, 'PASS', 'BLOCK')
  FROM (
       SELECT '009' migration_no, 'api_idempotency' table_name, 'PRIMARY' index_name,
              'UNIQUE:idem_key,user_id' expected
       UNION ALL SELECT '009', 'api_idempotency', 'idx_api_idempotency_legacy_created',
              'NONUNIQUE:created_at'
       UNION ALL SELECT '009', 'api_idempotency_records', 'PRIMARY',
              'UNIQUE:record_hash'
       UNION ALL SELECT '009', 'api_idempotency_records', 'idx_api_idempotency_expiry',
              'NONUNIQUE:expires_at'
       UNION ALL SELECT '009', 'api_idempotency_records', 'idx_api_idempotency_user_created',
              'NONUNIQUE:user_id,created_at'
       UNION ALL SELECT '010', 'api_refresh_sessions', 'PRIMARY',
              'UNIQUE:session_id'
       UNION ALL SELECT '010', 'api_refresh_sessions', 'uniq_api_refresh_token_hash',
              'UNIQUE:token_hash'
       UNION ALL SELECT '010', 'api_refresh_sessions', 'idx_api_refresh_family',
              'NONUNIQUE:family_id'
       UNION ALL SELECT '010', 'api_refresh_sessions', 'idx_api_refresh_user_active',
              'NONUNIQUE:user_id,revoked_at,expires_at'
       UNION ALL SELECT '010', 'api_refresh_sessions', 'idx_api_refresh_expiry',
              'NONUNIQUE:expires_at'
       UNION ALL SELECT '010', 'api_refresh_legacy_exchanges', 'PRIMARY',
              'UNIQUE:token_hash'
       UNION ALL SELECT '010', 'api_refresh_legacy_exchanges', 'idx_api_refresh_legacy_family',
              'NONUNIQUE:family_id'
       UNION ALL SELECT '010', 'api_refresh_legacy_exchanges', 'idx_api_refresh_legacy_expiry',
              'NONUNIQUE:exchanged_at'
  ) wanted
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = wanted.table_name
  LEFT JOIN (
       SELECT TABLE_NAME, INDEX_NAME,
              CONCAT(IF(MIN(NON_UNIQUE) = 0, 'UNIQUE:', 'NONUNIQUE:'),
                     GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')) actual
         FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        GROUP BY TABLE_NAME, INDEX_NAME
  ) idx ON idx.TABLE_NAME = wanted.table_name AND idx.INDEX_NAME = wanted.index_name;

-- Guarded ALTER scripts skip a column/index when its name already exists. If
-- such an object exists with another shape, applying the migration would leave
-- an incompatible schema, so block rather than guessing.
INSERT INTO ssms_rollout_preflight
SELECT '044', CONCAT('optional column shape messages.', wanted.name),
       COALESCE(CONCAT(c.DATA_TYPE, '/', c.IS_NULLABLE), 'absent'),
       'absent or datetime/YES',
       IF(c.COLUMN_NAME IS NULL OR (c.DATA_TYPE = 'datetime' AND c.IS_NULLABLE = 'YES'),
          'PASS', 'BLOCK')
  FROM (SELECT 'edited_at' AS name UNION ALL SELECT 'deleted_at') wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'messages'
   AND c.COLUMN_NAME = wanted.name;

INSERT INTO ssms_rollout_preflight
SELECT '046', 'optional column shape messages.client_tag',
       COALESCE(CONCAT(c.DATA_TYPE, '/', c.CHARACTER_MAXIMUM_LENGTH, '/', c.IS_NULLABLE), 'absent'),
       'absent or varchar/64/YES',
       IF(c.COLUMN_NAME IS NULL OR
          (c.DATA_TYPE = 'varchar' AND c.CHARACTER_MAXIMUM_LENGTH = 64 AND c.IS_NULLABLE = 'YES'),
          'PASS', 'BLOCK')
  FROM (SELECT 1) seed
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'messages'
   AND c.COLUMN_NAME = 'client_tag';

INSERT INTO ssms_rollout_preflight
SELECT '048', 'optional column shape users.authorization_version',
       IF(c.COLUMN_NAME IS NULL, 'absent',
          CONCAT(c.COLUMN_TYPE, '/', c.IS_NULLABLE, '/',
                 COALESCE(CAST(c.COLUMN_DEFAULT AS CHAR), 'NULL'))),
       'absent or bigint unsigned/NO/1',
       IF(c.COLUMN_NAME IS NULL OR
          (c.DATA_TYPE = 'bigint' AND LOCATE('unsigned', c.COLUMN_TYPE) > 0
           AND c.IS_NULLABLE = 'NO' AND CAST(c.COLUMN_DEFAULT AS CHAR) = '1'),
          'PASS', 'BLOCK')
  FROM (SELECT 1) seed
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'users'
   AND c.COLUMN_NAME = 'authorization_version';

-- Named indexes may be absent (the migration will create them), but an index
-- already using the target name must have the reviewed order and uniqueness.
INSERT INTO ssms_rollout_preflight
SELECT wanted.migration_no, CONCAT('optional index shape ', wanted.table_name, '.', wanted.index_name),
       COALESCE(idx.actual, 'absent'), CONCAT('absent or ', wanted.expected),
       IF(idx.actual IS NULL OR idx.actual = wanted.expected, 'PASS', 'BLOCK')
  FROM (
       SELECT '045' migration_no, 'messages' table_name, 'idx_thread_id' index_name,
              'NONUNIQUE:thread_id,id' expected
       UNION ALL SELECT '045', 'message_threads', 'idx_lm_id',
              'NONUNIQUE:last_message_at,id'
       UNION ALL SELECT '046', 'messages', 'uk_client_tag', 'UNIQUE:client_tag'
  ) wanted
  LEFT JOIN (
       SELECT TABLE_NAME, INDEX_NAME,
              CONCAT(IF(MIN(NON_UNIQUE) = 0, 'UNIQUE:', 'NONUNIQUE:'),
                     GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')) actual
         FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        GROUP BY TABLE_NAME, INDEX_NAME
  ) idx ON idx.TABLE_NAME = wanted.table_name AND idx.INDEX_NAME = wanted.index_name;

-- If client_tag is already present, prove migration 046's unique index can be
-- created. Dynamic SQL avoids referencing the column when it is absent.
SET @ssms_has_client_tag := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
       AND COLUMN_NAME = 'client_tag'
);
SET @ssms_dup_sql := IF(@ssms_has_client_tag = 1,
    "INSERT INTO ssms_rollout_preflight
       SELECT '046', 'duplicate non-null message client tags', CAST(COUNT(*) AS CHAR), '0',
              IF(COUNT(*) = 0, 'PASS', 'BLOCK')
         FROM (SELECT client_tag FROM messages WHERE client_tag IS NOT NULL
               GROUP BY client_tag HAVING COUNT(*) > 1) duplicate_tags",
    "INSERT INTO ssms_rollout_preflight VALUES
       ('046', 'duplicate non-null message client tags', 'column absent', '0 or column absent', 'PASS')"
);
PREPARE ssms_dup_stmt FROM @ssms_dup_sql;
EXECUTE ssms_dup_stmt;
DEALLOCATE PREPARE ssms_dup_stmt;

-- DDL sizing evidence for the change window. This is aggregate schema data;
-- never add payload/member samples to the release artifact.
SELECT TABLE_NAME, ENGINE, TABLE_ROWS,
       ROUND(DATA_LENGTH / 1024 / 1024, 2) AS data_mib,
       ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS index_mib
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN (
       'users', 'teacher_assignments', 'messages', 'message_threads',
       'api_idempotency', 'api_idempotency_records',
       'api_refresh_sessions', 'api_refresh_legacy_exchanges'
   )
 ORDER BY TABLE_NAME;

SELECT migration_no, requirement, actual_value, expected_value, result
  FROM ssms_rollout_preflight
 ORDER BY migration_no, requirement;

SELECT COUNT(*) INTO @ssms_blocked
  FROM ssms_rollout_preflight WHERE result = 'BLOCK';
SET @ssms_assert_sql := IF(
    @ssms_blocked > 0,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Build 24 preflight blocked: inspect BLOCK rows; do not migrate or deploy.'",
    "SELECT 'PASS: build 24 migration preflight' AS rollout_preflight"
);
PREPARE ssms_assert_stmt FROM @ssms_assert_sql;
EXECUTE ssms_assert_stmt;
DEALLOCATE PREPARE ssms_assert_stmt;
