-- ============================================================
-- 009_api_idempotency.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Atomic request reservations and bounded response replay for API writes.
-- Apply before deployment; the API does not perform request-time DDL.
-- ============================================================

-- Keep the legacy replay table readable during the seven-day transition. Older
-- releases created this table in a request; new releases never do so.
CREATE TABLE IF NOT EXISTS `api_idempotency` (
    `idem_key` VARCHAR(80) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `status_code` SMALLINT NOT NULL DEFAULT 200,
    `body` MEDIUMTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`idem_key`, `user_id`),
    KEY `idx_api_idempotency_legacy_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_idempotency_records` (
    `record_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `idem_key` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `request_scope` VARCHAR(255) NOT NULL,
    `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `owner_token` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `record_state` ENUM('processing','completed') NOT NULL DEFAULT 'processing',
    `status_code` SMALLINT UNSIGNED DEFAULT NULL,
    `response_body` MEDIUMTEXT DEFAULT NULL,
    `lease_expires_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`record_hash`),
    KEY `idx_api_idempotency_expiry` (`expires_at`),
    KEY `idx_api_idempotency_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
