-- Build 24 authorization/outbox rollout: post-migration verification.
-- MariaDB 10.6+. Run AFTER 009/010/044/045/046/048 and BEFORE PHP/mobile
-- rollout. This creates TEMPORARY objects only and reads aggregate metadata.
-- Any BLOCK raises SQLSTATE 45000. Never add row payloads to this output.

DROP TEMPORARY TABLE IF EXISTS ssms_rollout_verify;
CREATE TEMPORARY TABLE ssms_rollout_verify (
    migration_no VARCHAR(8) NOT NULL,
    requirement VARCHAR(120) NOT NULL,
    actual_value VARCHAR(255) NOT NULL,
    expected_value VARCHAR(255) NOT NULL,
    result ENUM('PASS', 'BLOCK') NOT NULL
);

-- Required table/column contracts for 009 and 010.
INSERT INTO ssms_rollout_verify
SELECT wanted.migration_no, CONCAT('required columns ', wanted.table_name),
       CAST(COUNT(c.COLUMN_NAME) AS CHAR), CAST(wanted.expected_count AS CHAR),
       IF(COUNT(c.COLUMN_NAME) = wanted.expected_count, 'PASS', 'BLOCK')
  FROM (
       SELECT '009' migration_no, 'api_idempotency' table_name, 5 expected_count,
              'idem_key,user_id,status_code,body,created_at' names
       UNION ALL SELECT '009', 'api_idempotency_records', 13,
              'record_hash,user_id,idem_key,request_scope,request_hash,owner_token,record_state,status_code,response_body,lease_expires_at,expires_at,created_at,updated_at'
       UNION ALL SELECT '010', 'api_refresh_sessions', 12,
              'session_id,family_id,user_id,token_hash,replaced_by,expires_at,consumed_at,revoked_at,created_ip,user_agent_hash,created_at,last_used_at'
       UNION ALL SELECT '010', 'api_refresh_legacy_exchanges', 4,
              'token_hash,user_id,family_id,exchanged_at'
  ) wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = wanted.table_name
   AND FIND_IN_SET(c.COLUMN_NAME, wanted.names) > 0
 GROUP BY wanted.migration_no, wanted.table_name, wanted.expected_count;

-- Every reviewed index, including order and uniqueness.
INSERT INTO ssms_rollout_verify
SELECT wanted.migration_no, CONCAT('index ', wanted.table_name, '.', wanted.index_name),
       COALESCE(idx.actual, 'absent'), wanted.expected,
       IF(idx.actual = wanted.expected, 'PASS', 'BLOCK')
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
       UNION ALL SELECT '045', 'messages', 'idx_thread_id',
              'NONUNIQUE:thread_id,id'
       UNION ALL SELECT '045', 'message_threads', 'idx_lm_id',
              'NONUNIQUE:last_message_at,id'
       UNION ALL SELECT '046', 'messages', 'uk_client_tag',
              'UNIQUE:client_tag'
  ) wanted
  LEFT JOIN (
       SELECT TABLE_NAME, INDEX_NAME,
              CONCAT(IF(MIN(NON_UNIQUE) = 0, 'UNIQUE:', 'NONUNIQUE:'),
                     GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')) actual
         FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        GROUP BY TABLE_NAME, INDEX_NAME
  ) idx ON idx.TABLE_NAME = wanted.table_name AND idx.INDEX_NAME = wanted.index_name;

-- Message and authorization columns added by 044/046/048.
INSERT INTO ssms_rollout_verify
SELECT '044', CONCAT('column messages.', wanted.name),
       COALESCE(CONCAT(c.DATA_TYPE, '/', c.IS_NULLABLE), 'absent'),
       'datetime/YES',
       IF(c.DATA_TYPE = 'datetime' AND c.IS_NULLABLE = 'YES', 'PASS', 'BLOCK')
  FROM (SELECT 'edited_at' name UNION ALL SELECT 'deleted_at') wanted
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'messages'
   AND c.COLUMN_NAME = wanted.name;

INSERT INTO ssms_rollout_verify
SELECT '046', 'column messages.client_tag',
       COALESCE(CONCAT(c.DATA_TYPE, '/', c.CHARACTER_MAXIMUM_LENGTH, '/', c.IS_NULLABLE), 'absent'),
       'varchar/64/YES',
       IF(c.DATA_TYPE = 'varchar' AND c.CHARACTER_MAXIMUM_LENGTH = 64
          AND c.IS_NULLABLE = 'YES', 'PASS', 'BLOCK')
  FROM (SELECT 1) seed
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'messages'
   AND c.COLUMN_NAME = 'client_tag';

INSERT INTO ssms_rollout_verify
SELECT '048', 'column users.authorization_version',
       IF(c.COLUMN_NAME IS NULL, 'absent',
          CONCAT(c.COLUMN_TYPE, '/', c.IS_NULLABLE, '/',
                 COALESCE(CAST(c.COLUMN_DEFAULT AS CHAR), 'NULL'))),
       'bigint unsigned/NO/1',
       IF(c.DATA_TYPE = 'bigint' AND LOCATE('unsigned', c.COLUMN_TYPE) > 0
          AND c.IS_NULLABLE = 'NO' AND CAST(c.COLUMN_DEFAULT AS CHAR) = '1',
          'PASS', 'BLOCK')
  FROM (SELECT 1) seed
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'users'
   AND c.COLUMN_NAME = 'authorization_version';

-- Use dynamic SQL so a missing migration-created column is reported as BLOCK
-- rather than aborting before the complete verification table is printed.
SET @ssms_has_authz_version := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'authorization_version'
);
SET @ssms_authz_sql := IF(@ssms_has_authz_version = 1,
    "INSERT INTO ssms_rollout_verify
       SELECT '048', 'authorization_version values below one', CAST(COUNT(*) AS CHAR), '0',
              IF(COUNT(*) = 0, 'PASS', 'BLOCK')
         FROM users WHERE authorization_version < 1 OR authorization_version IS NULL",
    "INSERT INTO ssms_rollout_verify VALUES
       ('048', 'authorization_version values below one', 'column absent', '0', 'BLOCK')"
);
PREPARE ssms_authz_stmt FROM @ssms_authz_sql;
EXECUTE ssms_authz_stmt;
DEALLOCATE PREPARE ssms_authz_stmt;

SET @ssms_has_client_tag := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
       AND COLUMN_NAME = 'client_tag'
);
SET @ssms_dup_sql := IF(@ssms_has_client_tag = 1,
    "INSERT INTO ssms_rollout_verify
       SELECT '046', 'duplicate non-null message client tags', CAST(COUNT(*) AS CHAR), '0',
              IF(COUNT(*) = 0, 'PASS', 'BLOCK')
         FROM (SELECT client_tag FROM messages WHERE client_tag IS NOT NULL
               GROUP BY client_tag HAVING COUNT(*) > 1) duplicate_tags",
    "INSERT INTO ssms_rollout_verify VALUES
       ('046', 'duplicate non-null message client tags', 'column absent', '0', 'BLOCK')"
);
PREPARE ssms_dup_stmt FROM @ssms_dup_sql;
EXECUTE ssms_dup_stmt;
DEALLOCATE PREPARE ssms_dup_stmt;

-- Migration 048 deterministically recreates these four triggers.
INSERT INTO ssms_rollout_verify
SELECT wanted.migration_no, CONCAT('trigger ', wanted.trigger_name),
       COALESCE(CONCAT(t.ACTION_TIMING, ':', t.EVENT_MANIPULATION, ':', t.EVENT_OBJECT_TABLE), 'absent'),
       wanted.expected,
       IF(CONCAT(t.ACTION_TIMING, ':', t.EVENT_MANIPULATION, ':', t.EVENT_OBJECT_TABLE) = wanted.expected,
          'PASS', 'BLOCK')
  FROM (
       SELECT '048' migration_no, 'trg_users_authorization_bu' trigger_name,
              'BEFORE:UPDATE:users' expected
       UNION ALL SELECT '048', 'trg_teacher_assignments_authorization_ai',
              'AFTER:INSERT:teacher_assignments'
       UNION ALL SELECT '048', 'trg_teacher_assignments_authorization_au',
              'AFTER:UPDATE:teacher_assignments'
       UNION ALL SELECT '048', 'trg_teacher_assignments_authorization_ad',
              'AFTER:DELETE:teacher_assignments'
  ) wanted
  LEFT JOIN information_schema.TRIGGERS t
    ON t.TRIGGER_SCHEMA = DATABASE() AND t.TRIGGER_NAME = wanted.trigger_name;

-- All new/relevant tables must remain transactional.
INSERT INTO ssms_rollout_verify
SELECT 'all', CONCAT('InnoDB table ', wanted.table_name),
       COALESCE(t.ENGINE, 'absent'), 'InnoDB',
       IF(t.ENGINE = 'InnoDB', 'PASS', 'BLOCK')
  FROM (
       SELECT 'users' table_name
       UNION ALL SELECT 'teacher_assignments'
       UNION ALL SELECT 'messages'
       UNION ALL SELECT 'message_threads'
       UNION ALL SELECT 'api_idempotency'
       UNION ALL SELECT 'api_idempotency_records'
       UNION ALL SELECT 'api_refresh_sessions'
       UNION ALL SELECT 'api_refresh_legacy_exchanges'
  ) wanted
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = wanted.table_name;

SELECT migration_no, requirement, actual_value, expected_value, result
  FROM ssms_rollout_verify
 ORDER BY migration_no, requirement;

SELECT COUNT(*) INTO @ssms_blocked
  FROM ssms_rollout_verify WHERE result = 'BLOCK';
SET @ssms_assert_sql := IF(
    @ssms_blocked > 0,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Build 24 verification blocked: stop rollout and inspect BLOCK rows.'",
    "SELECT 'PASS: build 24 post-migration verification' AS rollout_verification"
);
PREPARE ssms_assert_stmt FROM @ssms_assert_sql;
EXECUTE ssms_assert_stmt;
DEALLOCATE PREPARE ssms_assert_stmt;
