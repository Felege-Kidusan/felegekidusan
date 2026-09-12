-- ============================================================
-- 042_notification_center.sql   (IDEMPOTENT / RE-RUNNABLE)
-- P72 — WBWS Communication Center: per-user notification read
-- state, announcements, and two-way messaging.
--
-- Design (docs/NOTIFICATION_SYSTEM_OVERHAUL.md):
--   notifications            — event stream (unchanged, sql/012)
--   notification_reads       — per-USER read state, ONE pivot for
--                              notifications, announcements and
--                              message threads (Novu-style inbox
--                              semantics: unseen until read)
--   announcements            — dept → audience broadcasts
--   message_threads/_participants/messages — two-way messaging
--   department_tasks         — unchanged (existing Tasks tab)
--
-- `notifications.is_read` stays and is still written by the API for
-- backward compatibility; it means "at least one recipient read it".
-- All statements guarded / value-free DDL only — no data changes.
-- ============================================================

-- ── 1. Per-user read state (all subjects) ─────────────────────
CREATE TABLE IF NOT EXISTS `notification_reads` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `subject_type` ENUM('notification','announcement','message_thread') NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_subject` (`user_id`, `subject_type`, `subject_id`),
    KEY `idx_user_type_read` (`user_id`, `subject_type`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Announcements ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(200) NOT NULL,
    `body` TEXT NOT NULL,
    `priority` ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
    `audience_type` ENUM('roles','users') NOT NULL DEFAULT 'roles',
    `target_roles` VARCHAR(255) DEFAULT NULL,
    `target_user_ids` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `source_dept` VARCHAR(30) NOT NULL,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_source` (`source_dept`),
    KEY `idx_pinned` (`is_pinned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Messaging: threads, participants, messages ─────────────
CREATE TABLE IF NOT EXISTS `message_threads` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject` VARCHAR(200) NOT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_message_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_last_message` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_thread_participants` (
    `thread_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `added_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`thread_id`, `user_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `thread_id` INT UNSIGNED NOT NULL,
    `sender_id` INT UNSIGNED NOT NULL,
    `body` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_thread_created` (`thread_id`, `created_at`),
    KEY `idx_sender` (`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
