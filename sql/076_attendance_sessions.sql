-- ============================================================
-- 076 — Lesson‑level Education attendance (Attendance alerting — Phase 6, Option B)
--       Requirements §3 (lesson‑level detail mandatory), §5, §13; plan
--       docs/planning/ATTENDANCE_PHASE6_LESSON_LEVEL_PLAN_2026-10-20.md
-- ============================================================
-- Run ONCE in phpMyAdmin on the production database (after 071–075).
-- MariaDB‑safe, idempotent (CREATE TABLE IF NOT EXISTS), ADDITIVE ONLY:
-- the `attendance` table, its UNIQUE key, its sync triggers and every
-- existing reader are untouched. Applying this file is the switch that
-- makes lesson sheets available (together with FEATURE_ATTENDANCE_V2).
--
-- attendance_sessions           one lesson of one class on one date
--                               (subject + lesson number + the teacher who saved it)
-- attendance_session_records    one outcome per member per lesson
--
-- The class/day row in `attendance` for a day that has lesson sheets is
-- DERIVED by LessonAttendanceService (present › late › excused › absent;
-- holiday only when every lesson is holiday) and written through the
-- existing AttendanceRecordService, so warnings, roll‑ups, report cards and
-- the offline feed keep working unchanged.
-- ============================================================

CREATE TABLE IF NOT EXISTS `attendance_sessions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id`         INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED DEFAULT NULL,
    `subject_id`       INT UNSIGNED NOT NULL,
    `session_date`     DATE NOT NULL,
    `lesson_no`        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = first lesson of that subject on that day',
    `teacher_id`       INT UNSIGNED NOT NULL COMMENT 'users.id of the teacher who saved the sheet',
    `status`           ENUM('submitted','cancelled') NOT NULL DEFAULT 'submitted',
    `present_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `absent_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `late_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `excused_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `cancelled_by`     INT UNSIGNED DEFAULT NULL,
    `cancelled_at`     DATETIME DEFAULT NULL,
    `cancel_reason`    VARCHAR(255) DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_slot` (`class_id`, `session_date`, `subject_id`, `lesson_no`),
    KEY `idx_session_class_date` (`class_id`, `session_date`),
    KEY `idx_session_teacher` (`teacher_id`, `session_date`),
    KEY `idx_session_subject` (`subject_id`),
    CONSTRAINT `fk_session_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_session_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_session_records` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`  INT UNSIGNED NOT NULL,
    `member_id`   INT UNSIGNED NOT NULL,
    `status`      ENUM('present','absent','late','excused','holiday') NOT NULL,
    `note`        VARCHAR(255) DEFAULT NULL,
    `recorded_by` INT UNSIGNED NOT NULL,
    `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_member` (`session_id`, `member_id`),
    KEY `idx_session_record_member` (`member_id`, `session_id`),
    CONSTRAINT `fk_session_record_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_session_record_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
