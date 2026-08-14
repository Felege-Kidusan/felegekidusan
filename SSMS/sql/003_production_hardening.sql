-- ============================================================
-- 003_production_hardening.sql
-- WBSS / FKSS — Database hardening for go-live (5,000+ students)
-- ============================================================
--
-- HOW TO RUN (do this ONCE, from phpMyAdmin or the MySQL client):
--   1. TAKE A FULL BACKUP FIRST (Super Admin → Backup, or mysqldump).
--   2. Open phpMyAdmin → select the school database → "SQL" tab.
--   3. Run SECTION A (indexes) first. It is always safe.
--   4. Run SECTION B (foreign keys) next. It first CLEANS UP orphaned
--      rows, then adds the keys. If a key errors, read the message —
--      it means there is still orphaned data to clean.
--   5. SECTION C (archiving) is optional; read the comments.
--
-- These statements are written to be safe to run more than once where
-- possible. MariaDB does not support "ADD INDEX IF NOT EXISTS" on all
-- versions, so if an index already exists you will see a harmless
-- "Duplicate key name" error — that is fine, move on.
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- SECTION A — MISSING INDEXES (SAFE, RUN FIRST)
-- ============================================================
-- Why: the members table in production has ONLY the primary key and a
-- unique member_code. Every "list members", "filter by status", and
-- attendance lookup is a full table scan today. At 5,000 students the
-- member screen and attendance reports slow to a crawl. These indexes
-- are the single most important performance fix for go-live.

-- ---- members: the hot filters ----
ALTER TABLE `members` ADD INDEX `idx_members_status`            (`status`);
ALTER TABLE `members` ADD INDEX `idx_members_gender`            (`gender`);
ALTER TABLE `members` ADD INDEX `idx_members_age_group`         (`age_group`);
ALTER TABLE `members` ADD INDEX `idx_members_current_section`   (`current_section`);
ALTER TABLE `members` ADD INDEX `idx_members_registration_type` (`registration_type`);
ALTER TABLE `members` ADD INDEX `idx_members_current_class`     (`current_class_id`);
ALTER TABLE `members` ADD INDEX `idx_members_created_at`        (`created_at`);
ALTER TABLE `members` ADD INDEX `idx_members_is_teacher`        (`is_teacher`);
-- Composite for the most common combined filter (list active by gender):
ALTER TABLE `members` ADD INDEX `idx_members_status_gender`     (`status`, `gender`);

-- ---- attendance: the biggest-growing table (250k+ rows/year) ----
-- (member_id + date already backed by unique key; add the common lookups)
ALTER TABLE `attendance` ADD INDEX `idx_att_class_date` (`class_id`, `attendance_date`);
ALTER TABLE `attendance` ADD INDEX `idx_att_year`       (`academic_year_id`);
ALTER TABLE `attendance` ADD INDEX `idx_att_recorded_by` (`recorded_by`);

-- ---- class_enrollments: every roster + promotion query ----
ALTER TABLE `class_enrollments` ADD INDEX `idx_enr_class_year_status` (`class_id`, `academic_year_id`, `status`);
ALTER TABLE `class_enrollments` ADD INDEX `idx_enr_member`            (`member_id`);

-- ---- academic_records (grades) ----
ALTER TABLE `academic_records` ADD INDEX `idx_ar_member_year` (`member_id`, `academic_year_id`);
ALTER TABLE `academic_records` ADD INDEX `idx_ar_recorded_by` (`recorded_by`);

-- ---- users: role lookups ----
ALTER TABLE `users` ADD INDEX `idx_users_role` (`role`(20));

-- ============================================================
-- SECTION B — FOREIGN KEYS (RUN AFTER SECTION A)
-- ============================================================
-- Why: several tables point at members / classes / years / users but
-- have NO foreign keys, so deleting a member or class silently orphans
-- money records, grades, and assignments. We add the keys so the
-- database itself protects integrity from now on.
--
-- IMPORTANT: a foreign key CANNOT be added while orphaned rows exist.
-- Each block below first removes/neutralises orphans, THEN adds the key.
-- Run the whole section. If an ADD CONSTRAINT still errors, there is
-- more orphaned data — the SELECT in the comment helps you find it.

-- ---- teacher_assignments → users / classes ----
-- (api_teachers.php intentionally dropped these before; re-add safely)
DELETE FROM `teacher_assignments`
  WHERE `teacher_id` IS NOT NULL
    AND `teacher_id` NOT IN (SELECT `id` FROM `users`);
DELETE FROM `teacher_assignments`
  WHERE `class_id` IS NOT NULL
    AND `class_id` NOT IN (SELECT `id` FROM `classes`);
ALTER TABLE `teacher_assignments`
  ADD CONSTRAINT `fk_ta_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`   (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ta_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes` (`id`) ON DELETE CASCADE;

-- ---- finance_transactions → members (keep the money, null the link) ----
UPDATE `finance_transactions`
  SET `member_id` = NULL
  WHERE `member_id` IS NOT NULL
    AND `member_id` NOT IN (SELECT `id` FROM `members`);
ALTER TABLE `finance_transactions`
  ADD CONSTRAINT `fk_fin_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;

-- ---- finance_member_fees → members ----
DELETE FROM `finance_member_fees`
  WHERE `member_id` IS NOT NULL
    AND `member_id` NOT IN (SELECT `id` FROM `members`);
ALTER TABLE `finance_member_fees`
  ADD CONSTRAINT `fk_fee_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;

-- ---- material_items → material_categories ----
UPDATE `material_items`
  SET `category_id` = NULL
  WHERE `category_id` IS NOT NULL
    AND `category_id` NOT IN (SELECT `id` FROM `material_categories`);
ALTER TABLE `material_items`
  ADD CONSTRAINT `fk_mi_category` FOREIGN KEY (`category_id`) REFERENCES `material_categories` (`id`) ON DELETE SET NULL;

-- ---- material_transactions → material_items ----
DELETE FROM `material_transactions`
  WHERE `item_id` IS NOT NULL
    AND `item_id` NOT IN (SELECT `id` FROM `material_items`);
ALTER TABLE `material_transactions`
  ADD CONSTRAINT `fk_mt_item` FOREIGN KEY (`item_id`) REFERENCES `material_items` (`id`) ON DELETE CASCADE;

-- ---- academic_records → members / classes ----
DELETE FROM `academic_records`
  WHERE `member_id` NOT IN (SELECT `id` FROM `members`);
ALTER TABLE `academic_records`
  ADD CONSTRAINT `fk_ar_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;

-- NOTE: activity_logs / notifications / department_tasks keep their user
-- links as plain (nullable) columns on purpose — application code now sets
-- them to NULL when a user is deleted (see admin/backend/user-delete.php),
-- which preserves the audit history. Adding ON DELETE SET NULL keys there
-- is optional and can be done later.

-- ============================================================
-- SECTION C — COLLATION CONSISTENCY (OPTIONAL, RECOMMENDED)
-- ============================================================
-- Why: core tables use utf8mb4_unicode_ci but groups/finance/materials
-- use utf8mb4_general_ci. JOINs across the two can throw "illegal mix of
-- collations". Standardise on utf8mb4_unicode_ci. Run per table; each is
-- safe but can take a moment on large tables.
--
-- ALTER TABLE `finance_transactions` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ALTER TABLE `finance_categories`   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ALTER TABLE `finance_member_fees`  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ALTER TABLE `material_items`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ALTER TABLE `wbws_groups`          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ALTER TABLE `activity_logs`        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
-- SECTION D — ARCHIVING STRATEGY (READ BEFORE USING)
-- ============================================================
-- The tables below grow forever and have no cleanup today. Rather than
-- delete history (a school needs it), the plan is: keep everything in
-- the live tables but let the academic_year_id scope old data out of
-- day-to-day screens, and once per year copy closed-year rows into an
-- "_archive" table so the live tables stay lean.
--
-- 1) Create archive tables that mirror the live ones (run once):

CREATE TABLE IF NOT EXISTS `attendance_archive` LIKE `attendance`;
CREATE TABLE IF NOT EXISTS `academic_records_archive` LIKE `academic_records`;
CREATE TABLE IF NOT EXISTS `activity_logs_archive` LIKE `activity_logs`;

-- 2) At year-end, AFTER the year rollover, move a CLOSED year's rows into
--    the archive. Replace @old_year_id with the finished year's id.
--    (Do this inside a low-traffic window; it is safe to run in batches.)
--
--    SET @old_year_id = 1;   -- <-- the year you are closing
--
--    INSERT INTO `attendance_archive`
--      SELECT * FROM `attendance` WHERE `academic_year_id` = @old_year_id;
--    DELETE FROM `attendance` WHERE `academic_year_id` = @old_year_id;
--
--    INSERT INTO `academic_records_archive`
--      SELECT * FROM `academic_records` WHERE `academic_year_id` = @old_year_id;
--    DELETE FROM `academic_records` WHERE `academic_year_id` = @old_year_id;
--
-- 3) activity_logs has no year column — prune by age instead (keep 1 year):
--
--    INSERT INTO `activity_logs_archive`
--      SELECT * FROM `activity_logs` WHERE `created_at` < (NOW() - INTERVAL 12 MONTH);
--    DELETE FROM `activity_logs` WHERE `created_at` < (NOW() - INTERVAL 12 MONTH);
--
-- Keep the archive tables for reporting; they are never written to by the app.

-- ============================================================
-- END
-- ============================================================
