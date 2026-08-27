-- ════════════════════════════════════════════════════════════
-- 023 — Mezmur attendance goes DATE-based (section-grouped)
-- ════════════════════════════════════════════════════════════
-- Product decision: mezmur attendance is NOT session-driven.
-- One attendance sheet per DATE over the whole active roster,
-- grouped by section (the unit the department reasons about).
--
--   • mezmur_days        — one row per attendance date (program
--                          label optional), UNIQUE by date.
--   • mezmur_attendance  — gains attendance_date; one mark per
--                          member per DATE (new UNIQUE key).
--   • mezmur_sessions    — left untouched (deprecated by app
--                          code; data is preserved, nothing is
--                          dropped or destroyed).
--
-- Fully idempotent; guarded ALTERs only; safe to run whether or
-- not 022 has already been applied.
-- ════════════════════════════════════════════════════════════

-- ── 1. Attendance days ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mezmur_days` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attendance_date` DATE NOT NULL,
    `program_type`    VARCHAR(50) NOT NULL DEFAULT 'rehearsal',
    `title`           VARCHAR(255) DEFAULT NULL,
    `notes`           VARCHAR(500) DEFAULT NULL,
    `created_by`      INT UNSIGNED DEFAULT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mezmur_days_date` (`attendance_date`),
    KEY `idx_mezmur_days_program` (`program_type`),
    CONSTRAINT `fk_mezmur_days_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. mezmur_attendance gains attendance_date (guarded) ─────
SET @mz_add_date := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_attendance'
             AND column_name = 'attendance_date'),
    'SELECT 1',
    'ALTER TABLE `mezmur_attendance`
       ADD COLUMN `attendance_date` DATE DEFAULT NULL AFTER `session_id`'
);
PREPARE stmt FROM @mz_add_date; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Backfill dates from any existing session rows ─────────
UPDATE `mezmur_attendance` a
  JOIN `mezmur_sessions` s ON s.id = a.session_id
SET a.attendance_date = s.session_date
WHERE a.attendance_date IS NULL;

-- Seed mezmur_days from any pre-existing session dates so old
-- marks keep their day (and its program label).
INSERT INTO `mezmur_days` (`attendance_date`, `program_type`, `title`, `notes`, `created_by`)
SELECT DISTINCT s.session_date, s.program_type, s.title, s.notes, s.created_by
FROM `mezmur_sessions` s
WHERE NOT EXISTS (SELECT 1 FROM `mezmur_days` d WHERE d.attendance_date = s.session_date);

-- ── 4. Collapse same-date duplicates (keep the newest mark) ──
DELETE a1 FROM `mezmur_attendance` a1
JOIN `mezmur_attendance` a2
  ON a2.attendance_date = a1.attendance_date
 AND a2.member_id = a1.member_id
 AND a2.id > a1.id
WHERE a1.attendance_date IS NOT NULL;

-- ── 5. New unique key: one mark per member per date ──────────
SET @mz_uq_date := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_attendance'
             AND index_name = 'uq_mezmur_attendance_date_member'),
    'SELECT 1',
    'ALTER TABLE `mezmur_attendance`
       ADD UNIQUE KEY `uq_mezmur_attendance_date_member` (`attendance_date`, `member_id`)'
);
PREPARE stmt FROM @mz_uq_date; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 6. Scale index for date-window scans ─────────────────────
SET @mz_idx_date := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_attendance'
             AND index_name = 'idx_mezmur_attendance_date'),
    'SELECT 1',
    'ALTER TABLE `mezmur_attendance`
       ADD KEY `idx_mezmur_attendance_date` (`attendance_date`, `status`)'
);
PREPARE stmt FROM @mz_idx_date; EXECUTE stmt; DEALLOCATE PREPARE stmt;
