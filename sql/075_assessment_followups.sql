-- ============================================================
-- 075 — Assessment follow-ups on Education reactivation
--       (Attendance alerting — Phase 5; requirements §11–§12, plan §Phase 5, T-8)
-- ============================================================
-- Run ONCE in phpMyAdmin on the production database (after 071–074).
-- MariaDB-safe, idempotent (CREATE TABLE IF NOT EXISTS), additive only:
-- nothing in `assessments` / `academic_records` is altered or deleted.
--
-- assessment_followups
--   One row per (member, inactive Education period, assessment) that the
--   member MISSED while excluded from Education — detected when HR / Info
--   (or an auto-deactivated member being reactivated) sets the Education
--   status back to `active`. Starts as `pending`; the responsible teacher
--   (teacher_assignments class + subject) or the Education department
--   records the decision. No score is ever auto-written (no automatic zero).
--
-- assessment_makeup_attempts
--   A make-up result is stored SEPARATELY from the original assessment row
--   (owner decision T-8). `original_record_id` points at the existing
--   academic_records row for the same member + assessment when one exists
--   (never overwritten); NULL when the member had no record at all.
-- ============================================================

CREATE TABLE IF NOT EXISTS `assessment_followups` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id`     INT UNSIGNED NOT NULL,
    `period_id`     BIGINT UNSIGNED NOT NULL COMMENT 'member_department_periods.id of the INACTIVE Education period that was closed',
    `assessment_id` INT UNSIGNED NOT NULL,
    `class_id`      INT UNSIGNED NOT NULL,
    `subject_id`    INT UNSIGNED NOT NULL,
    `teacher_id`    INT UNSIGNED DEFAULT NULL COMMENT 'users.id of the primary responsible teacher at detection time (informational)',
    `decision`      ENUM('pending','makeup_required','exempt','not_applicable') NOT NULL DEFAULT 'pending',
    `decided_by`    INT UNSIGNED DEFAULT NULL,
    `decided_at`    DATETIME DEFAULT NULL,
    `note`          VARCHAR(500) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_followup` (`member_id`, `period_id`, `assessment_id`),
    KEY `idx_followup_decision` (`decision`, `class_id`, `subject_id`),
    KEY `idx_followup_assessment` (`assessment_id`),
    KEY `idx_followup_member` (`member_id`),
    KEY `idx_followup_period` (`period_id`),
    CONSTRAINT `fk_followup_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_followup_period` FOREIGN KEY (`period_id`) REFERENCES `member_department_periods` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_followup_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assessment_makeup_attempts` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `followup_id`        INT UNSIGNED NOT NULL,
    `original_record_id` INT UNSIGNED DEFAULT NULL COMMENT 'academic_records.id of the original row (kept untouched), NULL when none',
    `score`              DECIMAL(6,2) NOT NULL,
    `max_score`          DECIMAL(6,2) NOT NULL,
    `recorded_by`        INT UNSIGNED NOT NULL,
    `recorded_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `remarks`            VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_makeup_followup` (`followup_id`, `recorded_at`),
    KEY `idx_makeup_original` (`original_record_id`),
    CONSTRAINT `fk_makeup_followup` FOREIGN KEY (`followup_id`) REFERENCES `assessment_followups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
