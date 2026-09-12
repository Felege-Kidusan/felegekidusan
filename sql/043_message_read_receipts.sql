-- ============================================================
-- 043_message_read_receipts.sql   (IDEMPOTENT / RE-RUNNABLE)
-- P73 Phase 3 — Telegram-style read receipts for messaging.
--
-- Adds a per-participant read watermark to message_thread_participants:
--   last_read_message_id = the newest message id that participant has
--   seen. Updated when a participant opens/reads a thread
--   (NotificationCenterService::markThreadRead).
--
-- "Seen" for one of MY messages = every OTHER participant's watermark
-- has reached at least that message id. One column, no data changes —
-- NULL (never read) is treated as 0 by the service. Guarded on
-- information_schema exactly like 040, so re-runs are no-ops.
-- ============================================================

SET @mz43_has_wm := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'message_thread_participants'
    AND COLUMN_NAME = 'last_read_message_id');
SET @mz43_stmt := IF(@mz43_has_wm = 0, "
  ALTER TABLE `message_thread_participants`
    ADD COLUMN `last_read_message_id` INT UNSIGNED DEFAULT NULL
      COMMENT 'newest message id this participant has read (NULL = none)'
  ", 'SELECT 1');
PREPARE mz43_stmt FROM @mz43_stmt; EXECUTE mz43_stmt; DEALLOCATE PREPARE mz43_stmt;
