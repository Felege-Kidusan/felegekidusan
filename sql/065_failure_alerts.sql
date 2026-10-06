-- ============================================================
-- 065: Failure alerting state (idempotent / re-runnable)
-- ============================================================
-- Alert evaluation runs on a 15-minute CLI schedule
-- (admin/backend/failure_alert_check.php). This table is the
-- dedupe/cooldown ledger AND the alert history:
--   * alert_key uniquely identifies a firing condition
--     (crash_new:<crashkey> / velocity:<build> / sync_budget:default);
--   * last_sent_at drives the cooldown window (24h for per-condition
--     alerts, 6h for the fleet error-budget alert);
--   * sent_count keeps the history honest for the dashboard list.
--
-- Payloads are bounded summaries (title, counts, remediation one-liner)
-- — never raw telemetry, request bodies, or member data.

CREATE TABLE IF NOT EXISTS `failure_alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_key` VARCHAR(120) NOT NULL,
  `kind` ENUM('crash_new', 'velocity', 'sync_budget') NOT NULL,
  `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'high',
  `title` VARCHAR(255) NOT NULL,
  `payload_json` TEXT NULL,
  `first_sent_at` DATETIME NOT NULL,
  `last_sent_at` DATETIME NOT NULL,
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_failure_alerts_key` (`alert_key`),
  INDEX `idx_failure_alerts_last_sent` (`last_sent_at`),
  INDEX `idx_failure_alerts_kind` (`kind`, `last_sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
