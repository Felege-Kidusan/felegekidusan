-- ============================================================
-- 046_message_client_tag.sql   (IDEMPOTENT / RE-RUNNABLE)
-- O4 (offline-first) — exactly-once message sends.
--
-- The mobile app (1.4.0+) queues sends in a local outbox and drains
-- them with retries (O3). A drain that crashes mid-POST, times out
-- after commit, or races itself would re-send — every send therefore
-- carries a client-generated UUID, client_tag, and THIS unique index
-- is the exactly-once arbiter: the duplicate INSERT fails, the API
-- turns that into a replay success, and the user never sees two
-- bubbles.
--
--   messages.client_tag  VARCHAR(64) NULL
--   UNIQUE KEY uk_client_tag (client_tag)
--
-- NULL is allowed and NOT unique-participating (MySQL unique indexes
-- accept any number of NULLs): every existing message, web send and
-- pre-1.4.0 app send has no tag and is untouched. The API probes for
-- the column at runtime (SHOW COLUMNS), so deploying this file can
-- land before or after the PHP update — either order keeps sending
-- working (the app already sends client_tag since O3; the server
-- ignores it until both this file AND the PHP route are live).
--
-- Guarded on information_schema exactly like 040/043/044/045, so
-- re-runs are no-ops. No data changes.
-- ============================================================

SET @mz46_has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = 'client_tag');
SET @mz46_stmt := IF(@mz46_has_col = 0, "
  ALTER TABLE `messages`
    ADD COLUMN `client_tag` VARCHAR(64) DEFAULT NULL AFTER `body`
  ", 'SELECT 1');
PREPARE mz46_stmt FROM @mz46_stmt; EXECUTE mz46_stmt; DEALLOCATE PREPARE mz46_stmt;

SET @mz46_has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'messages'
    AND INDEX_NAME = 'uk_client_tag');
SET @mz46_stmt := IF(@mz46_has_idx = 0, "
  ALTER TABLE `messages`
    ADD UNIQUE INDEX `uk_client_tag` (`client_tag`)
  ", 'SELECT 1');
PREPARE mz46_stmt FROM @mz46_stmt; EXECUTE mz46_stmt; DEALLOCATE PREPARE mz46_stmt;
