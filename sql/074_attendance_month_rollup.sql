-- ============================================================================
-- 074 — Attendance monthly roll-up (Attendance alerting — Phase 4)
-- ============================================================================
-- Derived, per-member, per-Ethiopian-month cache of the SHARED risk formula
-- (AttendanceRiskService: sessions = present+absent+late+excused of eligible
-- rows; present_eq = present + excused + 0.5·late; rate = present_eq/sessions;
-- bands 90/75/50; below_threshold against attendance_policies.low_rate_threshold
-- when sessions >= min_sessions). Written by AttendanceMonthRollupService:
-- refreshed for the members on a sheet after every official write, and lazily
-- for a whole (department, month) when the Monthly attendance report is opened
-- and the month is stale. Rows are NEVER deleted — recomputed in place only.
--
-- Shared-hosting safe: plain InnoDB, no triggers/events/procedures, idempotent.
-- Run once in phpMyAdmin (safe to re-run).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `attendance_month_rollup` (
  `department`      ENUM('edu','mezmur') NOT NULL,
  `member_id`       INT UNSIGNED NOT NULL,
  `ec_year`         SMALLINT NOT NULL,
  `ec_month`        TINYINT NOT NULL COMMENT '1..13 (13 = Pagume)',
  `sessions`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `present`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `absent`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `late`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `excused`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `present_eq`      DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  `absent_eq`       DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  `rate`            DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL = no eligible sessions',
  `band`            ENUM('no_data','low','fair','good','excellent') NOT NULL DEFAULT 'no_data',
  `below_threshold` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'sessions >= min_sessions AND rate < low_rate_threshold',
  `threshold_value` DECIMAL(5,2) DEFAULT NULL COMMENT 'policy threshold used at computation',
  `computed_at`     DATETIME NOT NULL,
  PRIMARY KEY (`department`, `member_id`, `ec_year`, `ec_month`),
  KEY `idx_amr_month` (`department`, `ec_year`, `ec_month`, `below_threshold`),
  KEY `idx_amr_member` (`member_id`, `department`),
  CONSTRAINT `fk_amr_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification (optional):
-- SELECT COUNT(*) FROM attendance_month_rollup;
