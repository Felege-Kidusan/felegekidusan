-- ============================================================================
-- Migration 029 — Subject codes that survive Amharic names + safe field limits
-- Audit patch 8 (Education Department): "creating a subject with a longer
-- Amharic name fails with 'Server error'".
--
-- ROOT CAUSE (was in admin/api_subjects.php create_subject):
--   the auto-generated subject_code stripped every non-ASCII letter, so an
--   Amharic name became only underscores ("____________________", 20 chars =
--   the full VARCHAR(20)). The next long Amharic name collided, the code was
--   de-duplicated by appending "_<unix time>", and the result (31 chars) no
--   longer fit VARCHAR(20) -> "Data too long for column 'subject_code'".
--
-- This migration widens the columns and repairs legacy underscore-only codes.
-- Code generation itself is fixed in PHP (CodeGenService).
--
-- Compatible with MariaDB 10.2+ / MySQL 5.7+ (utf8mb4 index limit 3072 bytes;
-- VARCHAR(50) unique key = 200 bytes). Idempotent: MODIFY is declarative and
-- the UPDATE only touches underscore-only codes.
-- ============================================================================

ALTER TABLE `subjects`
  MODIFY `subject_code`   VARCHAR(50)  NOT NULL,
  MODIFY `subject_name`   VARCHAR(150) NOT NULL,
  MODIFY `subject_name_en` VARCHAR(150) DEFAULT NULL;

-- Repair codes produced by the old ASCII-only generator (garbage like
-- "____________________"). New deterministic, readable, guaranteed-unique
-- codes are derived from the row id.
UPDATE `subjects`
   SET `subject_code` = CONCAT('subj_', LPAD(`id`, 6, '0'))
 WHERE `subject_code` REGEXP '^_+$';
