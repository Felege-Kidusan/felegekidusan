-- ============================================================
-- 051: First-Party Mobile App Telemetry & Fleet Analytics
-- ============================================================

CREATE TABLE IF NOT EXISTS `app_installations` (
  `installation_id` VARCHAR(64) NOT NULL,
  `app_version` VARCHAR(32) NOT NULL,
  `app_build` INT UNSIGNED NOT NULL DEFAULT 1,
  `os_version` VARCHAR(32) NOT NULL DEFAULT '',
  `sdk_int` INT UNSIGNED NOT NULL DEFAULT 0,
  `device_brand` VARCHAR(64) NOT NULL DEFAULT '',
  `device_model` VARCHAR(64) NOT NULL DEFAULT '',
  `abi` VARCHAR(32) NOT NULL DEFAULT '',
  `ram_mb` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_low_ram` TINYINT(1) NOT NULL DEFAULT 0,
  `launch_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `sync_success_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `sync_fail_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `crash_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_role_hint` VARCHAR(32) DEFAULT NULL,
  `ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`installation_id`),
  INDEX `idx_app_install_ver` (`app_version`, `app_build`),
  INDEX `idx_app_install_last_seen` (`last_seen_at`),
  INDEX `idx_app_install_brand` (`device_brand`),
  INDEX `idx_app_install_os` (`os_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_telemetry_events` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `installation_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(48) NOT NULL,
  `event_data` TEXT DEFAULT NULL,
  `app_version` VARCHAR(32) NOT NULL,
  `app_build` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_app_events_type_created` (`event_type`, `created_at`),
  INDEX `idx_app_events_install_created` (`installation_id`, `created_at`),
  INDEX `idx_app_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_downloads` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `version` VARCHAR(32) NOT NULL,
  `build` INT UNSIGNED NOT NULL DEFAULT 1,
  `abi` VARCHAR(32) NOT NULL DEFAULT 'universal',
  `ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `downloaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_app_downloads_ver` (`version`, `build`),
  INDEX `idx_app_downloads_at` (`downloaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
