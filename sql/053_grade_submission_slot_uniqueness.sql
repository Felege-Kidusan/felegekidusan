-- ============================================================================
-- 053 — one Education submission packet per slot (audit finding J, 2026-10-02)
-- ============================================================================
-- WHY
-- `SubmissionService::upsertAttendance()` and `upsertMarklist()` are
-- SELECT-then-INSERT: they look for an existing packet and insert when they
-- do not find one. Nothing stopped two concurrent writers from both reading
-- "not found" and both inserting, because migration 013 gave
-- `grade_submissions` only NON-UNIQUE lookup keys:
--
--     KEY `sub_att_lookup`  (teacher_id, class_id, submission_type, attendance_date)
--     KEY `sub_mark_lookup` (teacher_id, assessment_id, submission_type)
--
-- The runtime DDL that once compensated was removed (commit d865ee9) and
-- `SubmissionService::hardenUniques()` became an empty stub whose comment said
-- "unique keys are deployment-managed by migration 013" — but 013 never
-- declared them. The responsibility was handed over and then dropped.
--
-- CONSEQUENCE (this is an integrity bug, not just untidy schema)
-- Lock checks resolve a slot with `ORDER BY id DESC LIMIT 1`
-- (`attendancePacketStatus`). With two packets for one (class, date):
--   • Education approves the packet it was shown, while the newer duplicate
--     still reads draft/incomplete — so the teacher keeps editing attendance
--     that is already approved and the review lock is bypassed; or
--   • the older packet is stranded in the inbox as a permanent "submitted"
--     ghost that can never be cleared.
--
-- THE KEYS
-- These are the natural keys the service itself already queries by, and the
-- same concept both sibling modules already enforce
-- (`uq_hr_submissions_date_section` in 026, `uq_mezmur_submissions_date_section`
-- in 024). Nothing new is being invented here.
--
-- NULL-exemption is deliberate and verified against the INSERT statements:
-- the attendance INSERT never sets `assessment_id`, and the marklist INSERT
-- never sets `attendance_date`. MySQL/MariaDB permit unlimited NULLs in a
-- UNIQUE index, so each key constrains only its own submission type and the
-- two cannot collide. `report`-type rows set neither and stay exempt.
--
-- SAFETY
-- Duplicate packets are USER DATA. Deciding which copy survives — and what
-- happens to the marks or attendance rows that reference it — is a human
-- decision, not a migration's. This file therefore NEVER deletes or merges
-- anything: it adds each constraint only when the data is already clean,
-- reports the exact blocking rows otherwise, and ends with a deterministic
-- verdict. Idempotent and safe to re-run.
--
-- Verify with: sql/preflight/uniqueness_preflight.sql
-- ============================================================================

-- ── Report blockers first (empty result sets = clean) ───────────────────────
SELECT `class_id`, `attendance_date`, COUNT(*) AS copies,
       GROUP_CONCAT(`id` ORDER BY `id`) AS submission_ids
FROM `grade_submissions`
WHERE `submission_type` = 'attendance' AND `attendance_date` IS NOT NULL
GROUP BY `class_id`, `attendance_date`
HAVING COUNT(*) > 1;

SELECT `assessment_id`, COUNT(*) AS copies,
       GROUP_CONCAT(`id` ORDER BY `id`) AS submission_ids
FROM `grade_submissions`
WHERE `submission_type` = 'marklist' AND `assessment_id` IS NOT NULL
GROUP BY `assessment_id`
HAVING COUNT(*) > 1;

-- ── 1. Attendance slot: (class_id, attendance_date, submission_type) ────────
SET @gs53_att_dup := (
    SELECT COUNT(*) FROM (
        SELECT 1 FROM `grade_submissions`
        WHERE `submission_type` = 'attendance' AND `attendance_date` IS NOT NULL
        GROUP BY `class_id`, `attendance_date`
        HAVING COUNT(*) > 1
    ) AS d
);
SET @gs53_att_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'grade_submissions'
      AND INDEX_NAME = 'uq_gs_attendance_slot'
);
SET @gs53_sql := IF(
    @gs53_att_exists > 0,
    'SELECT ''OK: uq_gs_attendance_slot already present — nothing to do.'' AS status',
    IF(
        @gs53_att_dup = 0,
        'ALTER TABLE `grade_submissions` ADD UNIQUE KEY `uq_gs_attendance_slot` (`class_id`, `attendance_date`, `submission_type`)',
        'SELECT ''BLOCKER: uq_gs_attendance_slot NOT created — duplicate attendance packets exist for the same class and date (listed above). Decide which packet survives, re-point any dependent rows, then re-run this file. Until then the approval lock on Education attendance can be bypassed.'' AS status'
    )
);
PREPARE gs53_stmt FROM @gs53_sql; EXECUTE gs53_stmt; DEALLOCATE PREPARE gs53_stmt;

-- ── 2. Mark-list slot: (assessment_id, submission_type) ─────────────────────
SET @gs53_mark_dup := (
    SELECT COUNT(*) FROM (
        SELECT 1 FROM `grade_submissions`
        WHERE `submission_type` = 'marklist' AND `assessment_id` IS NOT NULL
        GROUP BY `assessment_id`
        HAVING COUNT(*) > 1
    ) AS d
);
SET @gs53_mark_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'grade_submissions'
      AND INDEX_NAME = 'uq_gs_marklist_slot'
);
SET @gs53_sql := IF(
    @gs53_mark_exists > 0,
    'SELECT ''OK: uq_gs_marklist_slot already present — nothing to do.'' AS status',
    IF(
        @gs53_mark_dup = 0,
        'ALTER TABLE `grade_submissions` ADD UNIQUE KEY `uq_gs_marklist_slot` (`assessment_id`, `submission_type`)',
        'SELECT ''BLOCKER: uq_gs_marklist_slot NOT created — duplicate mark-list packets exist for the same assessment (listed above). Decide which packet survives, re-point any dependent rows, then re-run this file.'' AS status'
    )
);
PREPARE gs53_stmt FROM @gs53_sql; EXECUTE gs53_stmt; DEALLOCATE PREPARE gs53_stmt;

-- ── Deterministic verdict: re-read the catalogue AFTER the attempt ──────────
SELECT
    CASE WHEN EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_submissions'
          AND INDEX_NAME = 'uq_gs_attendance_slot')
    THEN 'PASS: uq_gs_attendance_slot exists.'
    ELSE 'BLOCKER: uq_gs_attendance_slot is MISSING — do not sign off this deployment.'
    END AS attendance_slot_verification,
    CASE WHEN EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_submissions'
          AND INDEX_NAME = 'uq_gs_marklist_slot')
    THEN 'PASS: uq_gs_marklist_slot exists.'
    ELSE 'BLOCKER: uq_gs_marklist_slot is MISSING — do not sign off this deployment.'
    END AS marklist_slot_verification;

-- ── Fail loudly if either constraint is still missing ───────────────────────
-- Added 2026-10-02 after the first real execution: the BLOCKER text above is
-- printed, but without this the script still exits 0, so an automated runner
-- would treat a blocked deployment as a success. The preflight already
-- SIGNALs; this keeps the migration consistent with it. Nothing is deleted
-- or merged either way — a blocker is always resolved by a human.
SET @gs53_missing := (
    SELECT 2 - COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_submissions'
      AND INDEX_NAME IN ('uq_gs_attendance_slot', 'uq_gs_marklist_slot')
);
SET @gs53_sql := IF(
    @gs53_missing > 0,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 053 did not complete: a slot uniqueness constraint is still missing because duplicate packets exist. The duplicates are listed above. Decide which packet is authoritative, re-point dependent rows, then re-run. Do NOT delete rows merely to make this pass.'",
    "SELECT 'Migration 053 complete: both slot constraints present.' AS status"
);
PREPARE gs53_stmt FROM @gs53_sql; EXECUTE gs53_stmt; DEALLOCATE PREPARE gs53_stmt;
