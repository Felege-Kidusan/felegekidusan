-- ============================================================
-- 077 — Education timetable builder (per‑class working days, per‑class bell
--       schedule, weekly grid scoped to the academic year, makeup classes)
--       Research/plan: docs/planning/EDUCATION_TIMETABLE_RESEARCH_2026-10-20.md
-- ============================================================
-- Run ONCE in phpMyAdmin on the production database (after 071–076).
-- MariaDB‑safe, idempotent, ADDITIVE ONLY: the two tables created by
-- sql/007_timetable.sql keep every existing row and index; this file only
-- adds nullable columns, two new tables and secondary indexes.
--
-- timetable_class_days        working days of one class in one academic year
--                             ("6,7" = Saturday + Sunday — the default)
-- timetable_periods           + class_id / academic_year_id  (bell schedule
--                             is now PER CLASS, exactly like the approved demo)
-- timetable_entries           + academic_year_id
-- timetable_makeup_classes    one‑off dated lessons outside the weekly grid
-- ============================================================

CREATE TABLE IF NOT EXISTS `timetable_class_days` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id`         INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `working_days`     VARCHAR(20) NOT NULL DEFAULT '6,7' COMMENT 'ISO weekdays, comma separated (1=Mon … 7=Sun)',
    `updated_by`       INT UNSIGNED DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ttcd_class_year` (`class_id`, `academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timetable_makeup_classes` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id`         INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `lesson_date`      DATE NOT NULL COMMENT 'stored Gregorian; displayed Ethiopian everywhere',
    `start_time`       TIME NOT NULL,
    `end_time`         TIME NOT NULL,
    `subject_id`       INT UNSIGNED DEFAULT NULL,
    `teacher_id`       INT UNSIGNED DEFAULT NULL,
    `room`             VARCHAR(50) DEFAULT NULL,
    `reason`           VARCHAR(255) DEFAULT NULL,
    `created_by`       INT UNSIGNED DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ttmk_class_date` (`class_id`, `lesson_date`),
    KEY `idx_ttmk_teacher_date` (`teacher_id`, `lesson_date`),
    KEY `idx_ttmk_year` (`academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- timetable_entries.room (007 creates it; self-heal if this database was
-- provisioned without the full 007 shape) ---------------------------------
SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='timetable_entries')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='timetable_entries' AND column_name='room'),
        'ALTER TABLE `timetable_entries` ADD COLUMN `room` VARCHAR(50) DEFAULT NULL AFTER `teacher_id`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql; EXECUTE wbws_schema_stmt; DEALLOCATE PREPARE wbws_schema_stmt;

-- timetable_periods.class_id ------------------------------------------------
SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='timetable_periods')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='timetable_periods' AND column_name='class_id'),
        'ALTER TABLE `timetable_periods` ADD COLUMN `class_id` INT UNSIGNED DEFAULT NULL AFTER `id`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql; EXECUTE wbws_schema_stmt; DEALLOCATE PREPARE wbws_schema_stmt;

-- timetable_periods.academic_year_id ---------------------------------------
SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='timetable_periods')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='timetable_periods' AND column_name='academic_year_id'),
        'ALTER TABLE `timetable_periods` ADD COLUMN `academic_year_id` INT UNSIGNED DEFAULT NULL AFTER `class_id`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql; EXECUTE wbws_schema_stmt; DEALLOCATE PREPARE wbws_schema_stmt;

-- timetable_periods index (class, year, start) -----------------------------
SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='timetable_periods')
        AND NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='timetable_periods' AND index_name='idx_ttp_class_year'),
        'ALTER TABLE `timetable_periods` ADD KEY `idx_ttp_class_year` (`class_id`, `academic_year_id`, `start_time`)', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql; EXECUTE wbws_schema_stmt; DEALLOCATE PREPARE wbws_schema_stmt;

-- timetable_entries.academic_year_id ---------------------------------------
SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='timetable_entries')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='timetable_entries' AND column_name='academic_year_id'),
        'ALTER TABLE `timetable_entries` ADD COLUMN `academic_year_id` INT UNSIGNED DEFAULT NULL AFTER `class_id`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql; EXECUTE wbws_schema_stmt; DEALLOCATE PREPARE wbws_schema_stmt;

-- timetable_entries index (year, teacher, day) -----------------------------
SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='timetable_entries')
        AND NOT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='timetable_entries' AND index_name='idx_tte_year_teacher_day'),
        'ALTER TABLE `timetable_entries` ADD KEY `idx_tte_year_teacher_day` (`academic_year_id`, `teacher_id`, `day_of_week`)', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql; EXECUTE wbws_schema_stmt; DEALLOCATE PREPARE wbws_schema_stmt;

-- Done. The builder reads/writes only rows whose class_id and
-- academic_year_id are set; legacy global periods (class_id NULL) are
-- ignored by the UI and left in place.
