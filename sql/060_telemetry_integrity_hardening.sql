-- ============================================================
-- 060: Telemetry integrity hardening (idempotent / re-runnable)
-- ============================================================
-- Crash telemetry carries a SHA-256 report identity instead of raw stack
-- text. A composite unique key makes a retried delivery of the same crash
-- a no-op, including the ambiguous "server committed, response was lost"
-- case. NULL keeps ordinary telemetry events append-only.

ALTER TABLE `app_telemetry_events`
  ADD COLUMN IF NOT EXISTS `dedupe_key` CHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `event_type`;

ALTER TABLE `app_telemetry_events`
  ADD UNIQUE KEY IF NOT EXISTS `uq_app_events_dedupe`
    (`installation_id`, `event_type`, `dedupe_key`);
