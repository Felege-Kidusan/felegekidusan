-- ============================================================
-- 072_attendance_warnings.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Attendance alerting — Phase 2 (ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN
-- 2026-10-19, §5 Phase 2; requirements §4, §5, §6, §8, §13).
--
--   attendance_warnings        : one row per department-scoped warning.
--                                kind=consecutive escalates IN PLACE
--                                (standard -> prolonged -> critical, T-7);
--                                kind=low_month is one row per EC month.
--                                dedupe_key is the idempotency key:
--                                  consecutive : dept|member|streak_start
--                                  low_month   : dept|member|ecYear|ecMonth
--                                Rows are never deleted; closing records the
--                                outcome (Contacted / Excused / Escalated /
--                                Deactivated / No Action).
--   attendance_warning_events  : append-only audit trail per warning
--                                (opened, escalated, reopened, closed,
--                                invalidated, auto_deactivated, notified).
--
-- Dates are stored Gregorian (DB convention); every user-facing output is
-- Ethiopian calendar only (ec_year / ec_month are stored for the monthly
-- rule so the month key never depends on conversion at read time).
--
-- Shared-hosting safe: InnoDB + utf8mb4, no JSON type, no CHECK constraints,
-- no generated columns, no events/triggers. Apply in phpMyAdmin AFTER
-- sql/071. The code degrades gracefully until then (evaluator is a no-op,
-- the API answers `available:false`, dashboards show "not available").
-- ============================================================

CREATE TABLE IF NOT EXISTS `attendance_warnings` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department`       ENUM('edu','mezmur') NOT NULL,
  `member_id`        INT UNSIGNED NOT NULL,
  `kind`             ENUM('consecutive','low_month') NOT NULL,
  `stage`            ENUM('standard','prolonged','critical') DEFAULT NULL COMMENT 'consecutive only',
  `dedupe_key`       VARCHAR(64) NOT NULL,
  `threshold_value`  DECIMAL(6,2) NOT NULL COMMENT 'threshold that opened the warning',
  `streak_length`    SMALLINT UNSIGNED DEFAULT NULL,
  `streak_start`     DATE DEFAULT NULL,
  `streak_last`      DATE DEFAULT NULL,
  `ec_year`          SMALLINT DEFAULT NULL,
  `ec_month`         TINYINT DEFAULT NULL,
  `sessions`         SMALLINT DEFAULT NULL,
  `rate`             DECIMAL(5,2) DEFAULT NULL,
  `status`           ENUM('open','closed') NOT NULL DEFAULT 'open',
  `below_threshold`  TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = no longer meets the threshold after a correction (kept open for staff)',
  `source`           ENUM('live','rollout') NOT NULL DEFAULT 'live',
  `opened_at`        DATETIME NOT NULL,
  `last_event_at`    DATETIME DEFAULT NULL,
  `closed_at`        DATETIME DEFAULT NULL,
  `closed_by`        INT UNSIGNED DEFAULT NULL,
  `outcome`          ENUM('contacted','excused','escalated','deactivated','no_action') DEFAULT NULL,
  `closure_note`     VARCHAR(500) DEFAULT NULL,
  `auto_deactivated` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aw_dedupe` (`dedupe_key`),
  KEY `idx_aw_open` (`department`, `status`, `opened_at`),
  KEY `idx_aw_member` (`member_id`, `department`, `status`),
  KEY `idx_aw_kind` (`kind`, `stage`, `status`),
  CONSTRAINT `fk_aw_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_warning_events` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `warning_id`    BIGINT UNSIGNED NOT NULL,
  `event`         ENUM('opened','escalated','reopened','closed','invalidated','auto_deactivated','notified') NOT NULL,
  `actor_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = system',
  `payload`       TEXT DEFAULT NULL COMMENT 'JSON: stage, streak, dates, rate, outcome, note',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_awe_warning` (`warning_id`, `created_at`),
  CONSTRAINT `fk_awe_warning` FOREIGN KEY (`warning_id`) REFERENCES `attendance_warnings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- grade_submissions / mezmur_submissions: NO change. From Phase 2 the web
-- attendance sheet (admin/api_attendance.php save_attendance) also writes a
-- `submitted` packet (owner decision T-2), so every official sheet has one.
