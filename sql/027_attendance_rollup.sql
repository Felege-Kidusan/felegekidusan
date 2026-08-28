-- ════════════════════════════════════════════════════════════════
-- 027 — Attendance analytics read model (Phase C, 2026-08-28)
-- ════════════════════════════════════════════════════════════════
-- The Information department analyzes attendance from THREE
-- independent sources (Education classes, Mezmur sections, HR
-- sections). The sources are NEVER combined for recording or
-- reporting-as-one; this rollup only stores per-source, per-day,
-- per-group aggregates so the analytics hub reads in O(days*groups)
-- instead of scanning raw rows at 100k+ scale.
--
-- Rules enforced elsewhere:
--   • InfoAnalyticsService is the ONLY writer (refresh), and the
--     analytics endpoint exposes read-only actions to info_dept.
--   • Each row keeps its source label — comparisons happen in the
--     presentation layer, never by merging identities.
-- Idempotent: safe to run repeatedly.

CREATE TABLE IF NOT EXISTS `attendance_rollup` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` ENUM('edu','mezmur','hr') NOT NULL,
  `rollup_date` DATE NOT NULL,
  `group_key` VARCHAR(100) NOT NULL DEFAULT '',
  `packets` INT UNSIGNED NOT NULL DEFAULT 0,
  `members_marked` INT UNSIGNED NOT NULL DEFAULT 0,
  `present_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `late_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `absent_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `excused_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `approved_packets` INT UNSIGNED NOT NULL DEFAULT 0,
  `refreshed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rollup_source_date_group` (`source`, `rollup_date`, `group_key`),
  KEY `idx_rollup_date` (`rollup_date`),
  KEY `idx_rollup_source_date` (`source`, `rollup_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
