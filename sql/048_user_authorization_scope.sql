-- ============================================================
-- 048_user_authorization_scope.sql  (IDEMPOTENT / RE-RUNNABLE)
-- Monotonic authorization-scope versions for mobile live revalidation.
-- MariaDB 10.6.
--
-- This migration is additive. It does not delete application data.
-- Apply after assignment hardening (006): the trigger contract requires
-- teacher_assignments and its authorization-bearing columns.
-- ============================================================

-- Fail with an actionable message before changing schema instead of silently
-- deploying only part of the authorization boundary on a database that missed
-- migration 006.
DELIMITER $$

DROP PROCEDURE IF EXISTS `ssms_require_authorization_scope_prereqs` $$
CREATE PROCEDURE `ssms_require_authorization_scope_prereqs`()
BEGIN
    DECLARE v_users INT DEFAULT 0;
    DECLARE v_assignments INT DEFAULT 0;
    DECLARE v_assignment_columns INT DEFAULT 0;

    SELECT COUNT(*) INTO v_users
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users';

    SELECT COUNT(*) INTO v_assignments
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_assignments';

    SELECT COUNT(*) INTO v_assignment_columns
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'teacher_assignments'
       AND COLUMN_NAME IN (
           'teacher_id',
           'class_id',
           'subject_id',
           'academic_year_id',
           'is_active',
           'is_primary',
           'is_class_teacher',
           'assignment_role'
       );

    IF v_users <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 048 requires the users table.';
    END IF;
    IF v_assignments <> 1 OR v_assignment_columns <> 8 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 048 requires assignment hardening migration 006.';
    END IF;
END $$

CALL `ssms_require_authorization_scope_prereqs`() $$
DROP PROCEDURE IF EXISTS `ssms_require_authorization_scope_prereqs` $$

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `authorization_version`
        BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `is_active` $$

UPDATE `users`
   SET `authorization_version` = 1
 WHERE `authorization_version` < 1 $$

-- Re-create deterministically so a re-run leaves exactly one trigger with the
-- reviewed body. Assignment-trigger updates touch only authorization_version;
-- the users trigger does not add a second increment unless role/status changes.
DROP TRIGGER IF EXISTS `trg_users_authorization_bu` $$
CREATE TRIGGER `trg_users_authorization_bu`
BEFORE UPDATE ON `users`
FOR EACH ROW
BEGIN
    IF NOT (OLD.`role` <=> NEW.`role`)
       OR NOT (OLD.`is_active` <=> NEW.`is_active`) THEN
        SET NEW.`authorization_version` = GREATEST(
            OLD.`authorization_version` + 1,
            NEW.`authorization_version`
        );
    ELSE
        SET NEW.`authorization_version` = GREATEST(
            OLD.`authorization_version`,
            NEW.`authorization_version`
        );
    END IF;
END $$

DROP TRIGGER IF EXISTS `trg_teacher_assignments_authorization_ai` $$
CREATE TRIGGER `trg_teacher_assignments_authorization_ai`
AFTER INSERT ON `teacher_assignments`
FOR EACH ROW
BEGIN
    UPDATE `users`
       SET `authorization_version` = `authorization_version` + 1
     WHERE `id` = NEW.`teacher_id`;
END $$

DROP TRIGGER IF EXISTS `trg_teacher_assignments_authorization_au` $$
CREATE TRIGGER `trg_teacher_assignments_authorization_au`
AFTER UPDATE ON `teacher_assignments`
FOR EACH ROW
BEGIN
    IF NOT (OLD.`teacher_id` <=> NEW.`teacher_id`)
       OR NOT (OLD.`class_id` <=> NEW.`class_id`)
       OR NOT (OLD.`subject_id` <=> NEW.`subject_id`)
       OR NOT (OLD.`academic_year_id` <=> NEW.`academic_year_id`)
       OR NOT (OLD.`is_active` <=> NEW.`is_active`)
       OR NOT (OLD.`is_primary` <=> NEW.`is_primary`)
       OR NOT (OLD.`is_class_teacher` <=> NEW.`is_class_teacher`)
       OR NOT (OLD.`assignment_role` <=> NEW.`assignment_role`) THEN
        UPDATE `users`
           SET `authorization_version` = `authorization_version` + 1
         WHERE `id` IN (OLD.`teacher_id`, NEW.`teacher_id`);
    END IF;
END $$

DROP TRIGGER IF EXISTS `trg_teacher_assignments_authorization_ad` $$
CREATE TRIGGER `trg_teacher_assignments_authorization_ad`
AFTER DELETE ON `teacher_assignments`
FOR EACH ROW
BEGIN
    UPDATE `users`
       SET `authorization_version` = `authorization_version` + 1
     WHERE `id` = OLD.`teacher_id`;
END $$

DELIMITER ;
