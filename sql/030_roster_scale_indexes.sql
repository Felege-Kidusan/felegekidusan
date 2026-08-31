-- ============================================================================
-- Migration 030 — Scale indexes for roster dedupe + unassigned-member listing
-- Audit patch 11 (H2/H5).
--
-- The roster's new one-row-per-member join groups class_enrollments by
-- (academic_year_id, status, member_id); the unassigned-members query runs a
-- covering NOT IN subquery on the same shape. This composite index serves
-- both without table scans at hundreds of thousands of enrollment rows.
--
-- Idempotent: check-then-add is not available in plain SQL dumps, so guard
-- with information_schema logic in the deploy script, or run once — MariaDB
-- will report a duplicate-key-name error that is safe to ignore.
-- ============================================================================

ALTER TABLE `class_enrollments`
  ADD INDEX `idx_enroll_year_status_member` (`academic_year_id`, `status`, `member_id`);
