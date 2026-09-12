-- ============================================================
-- 044_message_edit_delete.sql   (IDEMPOTENT / RE-RUNNABLE)
-- P73 — Telegram-grade message management: edit + delete.
--
--   messages.edited_at  — set when the sender edits a message
--                         (the UI shows an "edited" marker).
--   messages.deleted_at — soft delete: participants see a
--                         "This message was deleted" tombstone and
--                         the body is NEVER returned again.
--
-- Guarded on information_schema exactly like 040/043, so re-runs
-- are no-ops. No data changes — NULL means "never edited/deleted".
-- ============================================================

SET @mz44_has_edited := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = 'edited_at');
SET @mz44_stmt := IF(@mz44_has_edited = 0, "
  ALTER TABLE `messages`
    ADD COLUMN `edited_at` DATETIME DEFAULT NULL
      COMMENT 'set when the sender edited this message (NULL = never)'
  ", 'SELECT 1');
PREPARE mz44_stmt FROM @mz44_stmt; EXECUTE mz44_stmt; DEALLOCATE PREPARE mz44_stmt;

SET @mz44_has_deleted := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = 'deleted_at');
SET @mz44_stmt := IF(@mz44_has_deleted = 0, "
  ALTER TABLE `messages`
    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL
      COMMENT 'set when the sender deleted this message (NULL = live)'
  ", 'SELECT 1');
PREPARE mz44_stmt FROM @mz44_stmt; EXECUTE mz44_stmt; DEALLOCATE PREPARE mz44_stmt;
