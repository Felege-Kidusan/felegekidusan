-- ============================================================================
-- Migration 052 — Scale indexes for roster dedupe + unassigned-member listing
-- Audit patch 11 (H2/H5).
--
-- RENUMBERED 2026-10-02 (audit finding F): this file shipped as
-- `030_roster_scale_indexes.sql`, colliding with `030_mezmur_taxonomy.sql`
-- — two different migrations with the same number, so "apply in numeric
-- order" was ambiguous and an operator could silently skip one.
-- 030_mezmur_taxonomy keeps 030: it heads the mezmur chain that 031, 032
-- and 033 build on, and application code names it by path
-- (admin/api_mezmur.php schema hints). This file moved instead because it
-- is a single ADD INDEX with no dependants and no code references; it only
-- requires `class_enrollments` (created in 013), so it is order-independent
-- and safe at the end of the sequence. 047 was left as an existing gap
-- rather than reused, so numbering stays append-only and unambiguous.
-- Already applied it as 030? The ADD INDEX below is the same statement —
-- re-running reports "Duplicate key name", which is harmless (see below).
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
