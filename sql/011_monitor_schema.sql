-- Migration 011: deployment-managed error and uptime monitor schema
-- Apply once during deployment. Runtime requests intentionally never execute DDL.

CREATE TABLE IF NOT EXISTS `arkeon_error_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_name` VARCHAR(100) NOT NULL DEFAULT '',
    `error_type` VARCHAR(100) NOT NULL DEFAULT '',
    `error_code` INT NOT NULL DEFAULT 0,
    `severity` ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
    `message` TEXT NOT NULL,
    `file_path` VARCHAR(500) DEFAULT '',
    `line_number` INT DEFAULT 0,
    `url` VARCHAR(2000) DEFAULT '',
    `http_method` VARCHAR(10) DEFAULT '',
    `ip_address` VARCHAR(45) DEFAULT '',
    `user_agent` VARCHAR(500) DEFAULT '',
    `request_data` JSON DEFAULT NULL,
    `session_data` JSON DEFAULT NULL,
    `extra_data` JSON DEFAULT NULL,
    `stack_trace` TEXT DEFAULT NULL,
    `memory_usage` BIGINT DEFAULT 0,
    `peak_memory` BIGINT DEFAULT 0,
    `execution_time` DECIMAL(10,4) DEFAULT 0,
    `auto_fix_applied` VARCHAR(500) DEFAULT NULL,
    `php_version` VARCHAR(20) DEFAULT '',
    `server_software` VARCHAR(200) DEFAULT '',
    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
    `resolved_at` DATETIME DEFAULT NULL,
    `resolved_note` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_project` (`project_name`),
    KEY `idx_severity` (`severity`),
    KEY `idx_created` (`created_at`),
    KEY `idx_resolved` (`is_resolved`),
    KEY `idx_project_severity` (`project_name`, `severity`, `created_at`),
    KEY `idx_file` (`file_path`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arkeon_uptime_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_name` VARCHAR(100) NOT NULL DEFAULT '',
    `url_checked` VARCHAR(2000) NOT NULL,
    `status_code` INT DEFAULT 0,
    `response_time_ms` INT DEFAULT 0,
    `is_up` TINYINT(1) NOT NULL DEFAULT 1,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_project_uptime` (`project_name`, `checked_at`),
    KEY `idx_status` (`is_up`),
    KEY `idx_uptime_retention` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remove legacy payload/session snapshots that may contain member PII or
-- credentials. New rows contain field names and pseudonymous diagnostics only.
-- This is deployment-time data minimization, intentionally never request-time.
UPDATE `arkeon_error_log`
SET `request_data` = JSON_OBJECT('privacy_version', 2, 'legacy_data_removed', TRUE),
    `session_data` = JSON_OBJECT('privacy_version', 2, 'legacy_data_removed', TRUE),
    `extra_data` = JSON_OBJECT('privacy_version', 2, 'legacy_data_removed', TRUE)
WHERE JSON_EXTRACT(COALESCE(`request_data`, JSON_OBJECT()), '$.privacy_version') IS NULL
   OR JSON_EXTRACT(COALESCE(`session_data`, JSON_OBJECT()), '$.privacy_version') IS NULL
   OR JSON_EXTRACT(COALESCE(`extra_data`, JSON_OBJECT()), '$.privacy_version') IS NULL;

-- Install the retention index for databases where the monitor table predates
-- migration management. INFORMATION_SCHEMA makes this upgrade repeat-safe.
SET @monitor_retention_index_sql := (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'arkeon_uptime_log'
              AND index_name = 'idx_uptime_retention'
        ),
        'SELECT 1',
        'ALTER TABLE `arkeon_uptime_log` ADD KEY `idx_uptime_retention` (`checked_at`)'
    )
);
PREPARE monitor_retention_index_stmt FROM @monitor_retention_index_sql;
EXECUTE monitor_retention_index_stmt;
DEALLOCATE PREPARE monitor_retention_index_stmt;
