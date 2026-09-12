-- ============================================================
-- 045_comm_poll_indexes.sql   (IDEMPOTENT / RE-RUNNABLE)
-- P73 Phase 5 — Performance & scale: poll/pagination indexes.
--
-- Two composite indexes back the new hot paths:
--   messages(thread_id, id)
--     the conversation window query (WHERE thread_id = ? AND id < ?
--     ORDER BY id DESC LIMIT n) becomes a clean backward range scan
--     instead of a whole-thread read + filesort; also serves the
--     per-thread MAX(id) version probe.
--   message_threads(last_message_at, id)
--     exact sort key of the conversations list cursor — deterministic
--     total order + index-backed pagination.
--
-- Everything else the poller touches is already covered by existing
-- keys (notifications PK/type/targets/created_at, notification_reads
-- uk_user_subject + idx_user_type_read, messages idx_sender,
-- department_tasks status/to_dept/to_user_id) — verified by the
-- EXPLAIN audit at 100k+ scale (see docs §9 Phase 5).
--
-- Guarded on information_schema exactly like 040/043/044, so re-runs
-- are no-ops. No data changes.
-- ============================================================

SET @mz45_has_mid := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'messages'
    AND INDEX_NAME = 'idx_thread_id');
SET @mz45_stmt := IF(@mz45_has_mid = 0, "
  ALTER TABLE `messages`
    ADD INDEX `idx_thread_id` (`thread_id`, `id`)
  ", 'SELECT 1');
PREPARE mz45_stmt FROM @mz45_stmt; EXECUTE mz45_stmt; DEALLOCATE PREPARE mz45_stmt;

SET @mz45_has_lmi := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'message_threads'
    AND INDEX_NAME = 'idx_lm_id');
SET @mz45_stmt := IF(@mz45_has_lmi = 0, "
  ALTER TABLE `message_threads`
    ADD INDEX `idx_lm_id` (`last_message_at`, `id`)
  ", 'SELECT 1');
PREPARE mz45_stmt FROM @mz45_stmt; EXECUTE mz45_stmt; DEALLOCATE PREPARE mz45_stmt;
