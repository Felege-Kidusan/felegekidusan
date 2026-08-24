-- ============================================================
-- 010_refresh_token_rotation.sql   (IDEMPOTENT / RE-RUNNABLE)
-- One-time refresh-token rotation, replay detection, and family revocation.
-- Apply before deploying the matching API authentication code.
-- ============================================================

CREATE TABLE IF NOT EXISTS `api_refresh_sessions` (
    `session_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `family_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `replaced_by` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `consumed_at` DATETIME DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_ip` VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`session_id`),
    UNIQUE KEY `uniq_api_refresh_token_hash` (`token_hash`),
    KEY `idx_api_refresh_family` (`family_id`),
    KEY `idx_api_refresh_user_active` (`user_id`, `revoked_at`, `expires_at`),
    KEY `idx_api_refresh_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stateless refresh tokens issued by older releases may be exchanged once.
-- Replaying one revokes the tracked family created by its first exchange.
CREATE TABLE IF NOT EXISTS `api_refresh_legacy_exchanges` (
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `family_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `exchanged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token_hash`),
    KEY `idx_api_refresh_legacy_family` (`family_id`),
    KEY `idx_api_refresh_legacy_expiry` (`exchanged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
