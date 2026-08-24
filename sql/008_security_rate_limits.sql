-- ============================================================
-- 008_security_rate_limits.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Shared, atomic authentication throttling for multi-instance deployments.
-- Apply before deployment; the application does not perform request-time DDL.
-- ============================================================

CREATE TABLE IF NOT EXISTS `security_rate_limits` (
    `bucket_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `action_name` VARCHAR(64) NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` DATETIME NOT NULL,
    `window_ends_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bucket_hash`),
    KEY `idx_security_rate_expiry` (`window_ends_at`),
    KEY `idx_security_rate_action_updated` (`action_name`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
