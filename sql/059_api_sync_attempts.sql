-- 059_api_sync_attempts.sql
-- Server-observed, read-only sync monitoring foundation.
--
-- This is intentionally separate from api_idempotency_records: the latter is
-- the correctness/replay store and contains response bodies, while this table
-- is an allow-listed operational ledger. No request/response body, token,
-- password, header, note, lyric, or secret is stored here.

CREATE TABLE IF NOT EXISTS `api_sync_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_op_id` VARCHAR(80) NULL,
    `attempt_uid` VARCHAR(64) NULL,
    `attempt_number` INT UNSIGNED NULL,
    `execution_source` VARCHAR(16) NULL,
    `request_id` VARCHAR(64) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `domain` VARCHAR(48) NOT NULL,
    `operation` VARCHAR(160) NOT NULL,
    `entity_ref` VARCHAR(255) NULL,
    `status` VARCHAR(16) NOT NULL,
    `idempotency_state` VARCHAR(20) NOT NULL,
    `retry_decision` VARCHAR(24) NOT NULL,
    `error_category` VARCHAR(32) NULL,
    `error_code` VARCHAR(64) NULL,
    `http_status` SMALLINT UNSIGNED NULL,
    `started_at` DATETIME NOT NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sync_attempts_status_started` (`status`, `started_at`, `id`),
    KEY `idx_sync_attempts_domain_started` (`domain`, `started_at`, `id`),
    KEY `idx_sync_attempts_source_started` (`execution_source`, `started_at`, `id`),
    KEY `idx_sync_attempts_client_op` (`client_op_id`, `started_at`),
    KEY `idx_sync_attempts_attempt_uid` (`attempt_uid`, `started_at`),
    KEY `idx_sync_attempts_request` (`request_id`),
    KEY `idx_sync_attempts_user_started` (`user_id`, `started_at`),
    KEY `idx_sync_attempts_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retention is bounded and best-effort from the API service. In-flight rows are
-- explicitly excluded from cleanup so an active or ambiguous operation is not
-- silently deleted. The 90-day policy is documented in
-- docs/WEB_ADMIN_SYNC_MONITORING.md.
