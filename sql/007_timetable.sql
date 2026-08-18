-- ============================================================
-- 007_timetable.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Bell schedule (periods) + weekly class grid (entries).
-- Single-tenant SSMS — no school_id. Used later by the mobile app.
-- Safe to run more than once. Does not delete existing rows.
-- ============================================================

CREATE TABLE IF NOT EXISTS `timetable_periods` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label` VARCHAR(50) NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `is_break` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_period_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timetable_entries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id` INT UNSIGNED NOT NULL,
    `period_id` INT UNSIGNED NOT NULL,
    `day_of_week` TINYINT UNSIGNED NOT NULL COMMENT 'ISO 1=Mon … 7=Sun',
    `subject_id` INT UNSIGNED DEFAULT NULL,
    `teacher_id` INT UNSIGNED DEFAULT NULL,
    `room` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_class_period_day` (`class_id`, `period_id`, `day_of_week`),
    KEY `idx_tt_teacher_day` (`teacher_id`, `day_of_week`),
    KEY `idx_tt_class` (`class_id`),
    KEY `idx_tt_period` (`period_id`),
    KEY `idx_tt_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
