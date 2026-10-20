-- ============================================================
-- 078 — School calendar: planned holidays + "Day Off" days
--       Class-day-only attendance (owner decisions 2026-10-20,
--       docs/planning/ATTENDANCE_CLASS_DAY_ENFORCEMENT_PLAN_2026-10-20.md)
-- ============================================================
-- Run ONCE in phpMyAdmin on the production database (after 077).
-- MariaDB-safe, idempotent (CREATE TABLE IF NOT EXISTS), ADDITIVE ONLY.
--
-- school_holidays   planned off days prepared for the whole year
--                   (Education dept only writes; ranges cover vacations)
-- school_day_offs   append-only "Day Off" log — the LATEST row per date
--                   decides (1 = day off, 0 = reopen). The form asks the
--                   department whether makeup classes are also cancelled
--                   (cancel_makeup). Rows may only be written for dates
--                   that have not passed (day_date >= today, Addis time),
--                   so a date that has passed is immutable history.
--
-- Applying this file activates the class-day rule (together with the
-- P0 code); before that the code behaves exactly as before.
-- ============================================================

CREATE TABLE IF NOT EXISTS `school_holidays` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`      VARCHAR(120) NOT NULL,
    `title_am`   VARCHAR(120) DEFAULT NULL,
    `date_from`  DATE NOT NULL,
    `date_to`    DATE NOT NULL,
    `type`       ENUM('holiday','vacation','closure','other') NOT NULL DEFAULT 'holiday',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_school_holidays_range` (`date_from`, `date_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_day_offs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `day_date`      DATE NOT NULL,
    `is_off`        TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = mark Day Off, 0 = reopen (undo)',
    `cancel_makeup` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'also cancel makeup classes on this day?',
    `reason`        VARCHAR(255) DEFAULT NULL,
    `created_by`    INT UNSIGNED DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_school_day_offs_date` (`day_date`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
