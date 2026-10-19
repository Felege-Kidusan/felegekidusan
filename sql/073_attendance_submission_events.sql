-- ============================================================
-- 073_attendance_submission_events.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Attendance alerting — Phase 3 (ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN
-- 2026-10-19, §5 Phase 3; requirements §5 "Audit").
--
--   attendance_submission_events : append-only history of ONE attendance
--                                  packet (Education grade_submissions row
--                                  with submission_type='attendance', or a
--                                  Mezmur mezmur_submissions row):
--                                    submitted / resubmitted (who, when)
--                                    approved / revision_needed / rejected
--                                    (who, when, WHY — reason is mandatory
--                                    for every non-approve decision)
--                                  The packet tables keep only the LAST
--                                  reviewer decision and clear it on
--                                  resubmission, so this table is the
--                                  durable per-packet trail the requirement
--                                  asks for. Rows are never updated or
--                                  deleted.
--
-- Dates are stored Gregorian (DB convention); every user-facing output is
-- Ethiopian calendar only.
--
-- Shared-hosting safe: InnoDB + utf8mb4, no JSON type, no CHECK constraints,
-- no generated columns, no events/triggers, no foreign keys across the two
-- packet tables (packet_id is scoped by `department`). Apply in phpMyAdmin
-- after sql/072. The code degrades gracefully until then (events are simply
-- not recorded and detail views show an empty history).
-- ============================================================

CREATE TABLE IF NOT EXISTS `attendance_submission_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department`  ENUM('edu','mezmur') NOT NULL,
  `packet_id`   INT UNSIGNED NOT NULL,
  `from_status` VARCHAR(20) NOT NULL DEFAULT '',
  `to_status`   VARCHAR(20) NOT NULL,
  `actor_id`    INT UNSIGNED NULL,
  `reason`      VARCHAR(500) NOT NULL DEFAULT '',
  `channel`     VARCHAR(10) NOT NULL DEFAULT 'web',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ase_packet` (`department`, `packet_id`, `id`),
  KEY `idx_ase_actor` (`actor_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
