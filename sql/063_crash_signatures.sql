-- ============================================================
-- 063: Crash signatures (idempotent / re-runnable)
-- ============================================================
-- The crash telemetry chain is exact-once but intentionally opaque: the
-- crash key is a SHA-256 of the log entry, so the fleet dashboard could
-- count a crash identity but never say WHERE it happened.
--
-- This migration adds the readable companion: one row per crash key
-- holding a bounded, allow-listed signature (exception class + up to 3
-- first-party frame names — file and function only; never messages,
-- argument values, or PII) plus durable lifetime aggregates the 90-day
-- event retention would otherwise erase.
--
-- The signature itself travels as a `crash_signature` telemetry event
-- through the existing exact-once chain (unique
-- installation_id + event_type + dedupe_key), so delivery is deduplicated
-- by the same mechanism as crash counting.

CREATE TABLE IF NOT EXISTS `app_crash_signatures` (
  `crash_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `signature_class` VARCHAR(120) NOT NULL DEFAULT '',
  `signature_frames` TEXT NULL,
  `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_events` INT UNSIGNED NOT NULL DEFAULT 0,
  `app_version_last` VARCHAR(32) NOT NULL DEFAULT '',
  `app_build_last` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`crash_key`),
  INDEX `idx_crash_sig_last_seen` (`last_seen_at`),
  INDEX `idx_crash_sig_total` (`total_events`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Covering index for fleet-wide crash aggregation over the event log
-- (per-key occurrence and affected-installation counts stay cheap and
-- exact; these are computed at read time rather than stored, because a
-- distinct-count cannot be maintained correctly by upsert).
ALTER TABLE `app_telemetry_events`
  ADD INDEX IF NOT EXISTS `idx_app_events_type_dedupe`
    (`event_type`, `dedupe_key`, `installation_id`);

-- Backfill lifetime aggregates from crash events already recorded.
-- INSERT IGNORE keeps this repeat-safe and never overwrites counters a
-- live upsert has already started maintaining. Apply this migration with
-- (or before) the application code that maintains the table.
INSERT IGNORE INTO `app_crash_signatures`
  (crash_key, first_seen_at, last_seen_at, total_events)
SELECT dedupe_key, MIN(created_at), MAX(created_at), COUNT(*)
  FROM `app_telemetry_events`
 WHERE event_type IN ('crash', 'crash_recorded')
   AND dedupe_key IS NOT NULL
 GROUP BY dedupe_key;
