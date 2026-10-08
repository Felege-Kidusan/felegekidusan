-- ============================================================================
-- 067 — Semester reopen-for-corrections flag (term-close model, release 1)
-- ============================================================================
-- WHY
-- The school's reporting model is semester-based: when the Education
-- Department flips the current semester (set_current_term), the previous
-- semester's data is CLOSED — teachers may view it for analysis but not
-- edit it; corrections belong to the Education Department (the standard
-- SIS pattern: PowerSchool locks reporting terms, Skyward gates changes
-- behind administrator-approved Grade Change Requests, Canvas closes
-- grading periods).
--
-- Teachers still sometimes must apply a correction to a closed semester
-- (a wrong mark discovered after the flip). Rather than the department
-- re-entering every row, the department can REOPEN a closed semester for
-- teacher corrections. While a semester is reopened, teachers may edit
-- its assessments exactly as in the current semester. Setting a new
-- current semester re-closes every reopened window automatically, so an
-- old semester can never stay silently writable.
--
-- CONSEQUENCE THIS FIXES
-- SubmissionService::teacherWriteRefusal() (release 1.6.5) needs a
-- persistent, inspectable signal that a closed semester is temporarily
-- writable. Without this column the only options were to let teachers
-- edit all closed semesters (defeats the close) or none (the department
-- must hand-apply every correction).
--
-- WHAT CHANGES
--   academic_terms.is_reopened  TINYINT(1) NOT NULL DEFAULT 0
--     0 = normal closed semester (the default; every existing row).
--     1 = the Education Department has reopened this semester for teacher
--         corrections (ui: "Reopened for corrections").
--
-- Invariants enforced in code (api_education.php), not here:
--   * Only the ACTIVE year's semesters can be reopened.
--   * The current semester is never "reopened" (it is already writable;
--     reopening it would be a no-op that confuses the UI).
--   * set_current_term clears is_reopened for the whole active year.
--
-- MIGRATION STYLE
-- Manual phpMyAdmin run (same as 056/066). MariaDB 11.4 supports
-- ADD COLUMN IF NOT EXISTS, which keeps the script safely re-runnable
-- on a host that already has the column.
-- ============================================================================

ALTER TABLE `academic_terms`
    ADD COLUMN IF NOT EXISTS `is_reopened` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `is_current`;

-- Hygiene: the flag starts clean for every semester of every year. This is
-- a no-op by construction (the column was just created with DEFAULT 0) and
-- exists only so a re-run after a partial state can never leave a stale 1.
UPDATE `academic_terms` SET `is_reopened` = 0 WHERE `is_reopened` <> 0;
