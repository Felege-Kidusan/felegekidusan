-- ════════════════════════════════════════════════════════════
-- 026 — HR department attendance domain (section-based)
-- ════════════════════════════════════════════════════════════
-- Product rule (2026-08-28): three independent attendance sources —
-- HR, Education, Mezmur. Each owns its takers and its data; they
-- share the WORKFLOW, never the records. Education keeps the
-- class-based `attendance` + grade_submissions tables; mezmur keeps
-- mezmur_attendance + mezmur_submissions; HR gets this domain, a
-- section-based clone of the mezmur mechanics.
--
--   • hr_attendance    — marks (one per member per date), section
--                        carried from members.current_section at
--                        record time (snapshotted on the row).
--   • hr_submissions   — packet table mirroring mezmur_submissions
--                        semantics (UNIQUE attendance_date + section,
--                        counts, review fields, client_op_id).
--
-- Fully idempotent; CREATE TABLE IF NOT EXISTS only; safe to re-run.
-- ════════════════════════════════════════════════════════════

-- ── 1. Marks ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `hr_attendance` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attendance_date` DATE NOT NULL,
    `member_id`       INT UNSIGNED NOT NULL,
    `section`         VARCHAR(80) NOT NULL DEFAULT '',
    `status`          ENUM('present','late','absent','excused')
                      NOT NULL DEFAULT 'present',
    `notes`           VARCHAR(255) DEFAULT NULL,
    `marked_by`       INT UNSIGNED DEFAULT NULL,
    `marked_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hr_attendance_date_member` (`attendance_date`, `member_id`),
    KEY `idx_hr_attendance_date` (`attendance_date`),
    KEY `idx_hr_attendance_section_date` (`section`, `attendance_date`),
    KEY `idx_hr_attendance_member` (`member_id`, `attendance_date`),
    KEY `idx_hr_attendance_status` (`status`),
    CONSTRAINT `fk_hr_attendance_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_hr_attendance_marked_by`
        FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Submission packets (edu/mezmur workflow parity) ───────
CREATE TABLE IF NOT EXISTS `hr_submissions` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attendance_date` DATE NOT NULL,
    `section`         VARCHAR(80) NOT NULL,
    `taker_id`        INT UNSIGNED DEFAULT NULL,
    `status`          VARCHAR(20) NOT NULL DEFAULT 'draft',
    `member_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `present_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `late_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `absent_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `excused_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `submitted_at`    DATETIME DEFAULT NULL,
    `reviewed_by`     INT UNSIGNED DEFAULT NULL,
    `reviewed_at`     DATETIME DEFAULT NULL,
    `review_notes`    VARCHAR(500) DEFAULT NULL,
    `client_op_id`    VARCHAR(64) DEFAULT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hr_submissions_date_section` (`attendance_date`, `section`),
    KEY `idx_hr_submissions_status` (`status`),
    KEY `idx_hr_submissions_date` (`attendance_date`),
    CONSTRAINT `fk_hr_submissions_taker`
        FOREIGN KEY (`taker_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_hr_submissions_reviewer`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Audit trail (who changed what) ────────────────────────
CREATE TABLE IF NOT EXISTS `hr_attendance_audit` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attendance_date` DATE DEFAULT NULL,
    `section`         VARCHAR(80) DEFAULT NULL,
    `actor_id`        INT UNSIGNED DEFAULT NULL,
    `action`          VARCHAR(40) NOT NULL,
    `details`         VARCHAR(500) DEFAULT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hr_audit_date` (`attendance_date`),
    KEY `idx_hr_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
