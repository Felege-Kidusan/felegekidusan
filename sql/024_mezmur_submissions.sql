-- ════════════════════════════════════════════════════════════
-- 024 — Mezmur submission packets (teachers/edu workflow clone)
-- ════════════════════════════════════════════════════════════
-- The mezmur attendance loop becomes identical to teachers ↔
-- education: the taker app saves/sends one packet per
-- (attendance_date, section); the mezmur department reviews it
-- (approve / reject / return-with-note) from the web inbox.
--
--   • mezmur_submissions  — packet table mirroring
--                           grade_submissions semantics
--                           (UNIQUE attendance_date + section).
--   • mezmur_attendance   — gains 'excused' status and per-member
--                           notes (teacher parity).
--   • mezmur_days         — untouched (legacy labels only).
--
-- Fully idempotent; guarded ALTERs only; safe to re-run.
-- ════════════════════════════════════════════════════════════

-- ── 1. Packet table ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mezmur_submissions` (
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
    UNIQUE KEY `uq_mezmur_submissions_date_section` (`attendance_date`, `section`),
    KEY `idx_mezmur_submissions_status` (`status`),
    KEY `idx_mezmur_submissions_date` (`attendance_date`),
    CONSTRAINT `fk_mezmur_submissions_taker`
        FOREIGN KEY (`taker_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_mezmur_submissions_reviewer`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Widen attendance statuses with 'excused' (guarded) ────
SET @mz_widen_status := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_attendance'
             AND column_name = 'status'
             AND COLUMN_TYPE LIKE '%excused%'),
    'SELECT 1',
    'ALTER TABLE `mezmur_attendance`
       MODIFY COLUMN `status`
       ENUM(''present'',''late'',''absent'',''excused'')
       NOT NULL DEFAULT ''present'''
);
PREPARE stmt FROM @mz_widen_status; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Per-member notes (teacher parity; guarded) ────────────
SET @mz_add_notes := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_attendance'
             AND column_name = 'notes'),
    'SELECT 1',
    'ALTER TABLE `mezmur_attendance`
       ADD COLUMN `notes` VARCHAR(500) DEFAULT NULL AFTER `marked_by`'
);
PREPARE stmt FROM @mz_add_notes; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. session_id becomes nullable (guarded) ─────────────────
-- DATE-based sheets have no session; 022 declared session_id
-- NOT NULL which blocks every date-based insert. Legacy session
-- rows keep their session_id untouched.
SET @mz_null_session := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_attendance'
             AND column_name = 'session_id'
             AND IS_NULLABLE = 'YES'),
    'SELECT 1',
    'ALTER TABLE `mezmur_attendance`
       MODIFY COLUMN `session_id` BIGINT UNSIGNED DEFAULT NULL'
);
PREPARE stmt FROM @mz_null_session; EXECUTE stmt; DEALLOCATE PREPARE stmt;
