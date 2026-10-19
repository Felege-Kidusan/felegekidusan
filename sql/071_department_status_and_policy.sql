-- ============================================================
-- 071_department_status_and_policy.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Attendance alerting — Phase 1 (ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN
-- 2026-10-19, §5 Phase 1; requirements §1, §3, §11).
--
--   member_department_periods : per-department (Education / Mezmur) active /
--                               inactive periods for a member. Independent of
--                               members.status (which stays HR/Info-manual).
--                               Rule: NO row  =>  active (no back-fill needed).
--                               Reactivation ALWAYS opens a new period, so a
--                               streak can never bridge an inactive gap.
--   attendance_policies       : per-department thresholds edited by the School
--                               Admin (5 / 8 / 10 consecutive, auto-deactivate
--                               OFF, 75 % low-rate, 3 minimum sessions).
--
-- Shared-hosting safe: InnoDB + utf8mb4, no JSON type, no CHECK constraints,
-- no generated columns, no events/triggers. Apply in phpMyAdmin BEFORE
-- enabling FEATURE_ATTENDANCE_V2 — the code degrades gracefully (the new
-- endpoints answer `available:false`, rosters are unchanged) until then.
-- ============================================================

CREATE TABLE IF NOT EXISTS `member_department_periods` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`   INT UNSIGNED NOT NULL,
  `department`  ENUM('edu','mezmur') NOT NULL,
  `status`      ENUM('active','inactive') NOT NULL,
  `started_at`  DATETIME NOT NULL COMMENT 'effective from',
  `ended_at`    DATETIME DEFAULT NULL COMMENT 'NULL = current period',
  `source`      ENUM('manual','auto','rollout') NOT NULL DEFAULT 'manual',
  `changed_by`  INT UNSIGNED DEFAULT NULL,
  `reason`      VARCHAR(500) DEFAULT NULL,
  `warning_id`  BIGINT UNSIGNED DEFAULT NULL COMMENT 'set when source=auto (Phase 2)',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mdp_member_dept` (`member_id`, `department`, `ended_at`),
  KEY `idx_mdp_dept_status` (`department`, `status`, `ended_at`),
  CONSTRAINT `fk_mdp_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_policies` (
  `department`            ENUM('edu','mezmur') NOT NULL,
  `consecutive_threshold` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `prolonged_threshold`   TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `critical_threshold`    TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `auto_deactivate`       TINYINT(1) NOT NULL DEFAULT 0,
  `low_rate_threshold`    DECIMAL(5,2) NOT NULL DEFAULT 75.00,
  `min_sessions`          TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `updated_by`            INT UNSIGNED DEFAULT NULL,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `attendance_policies` (`department`) VALUES ('edu'), ('mezmur');

-- Verification (optional):
-- SELECT table_name FROM information_schema.tables
--  WHERE table_schema = DATABASE() AND table_name IN ('member_department_periods','attendance_policies');
-- SELECT * FROM attendance_policies;
