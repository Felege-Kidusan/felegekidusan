-- ============================================================
-- 049_user_profile_image.sql  (IDEMPOTENT / RE-RUNNABLE)
-- Private profile-image reference for authenticated user profiles.
-- MariaDB 10.6.
--
-- Additive rollout contract:
--   * existing rows remain valid because the column is nullable;
--   * existing application versions ignore the new column;
--   * no user identity, role, status, membership, or authorization data changes;
--   * private image bytes remain outside the database.
--
-- Deliberate rollback: after application rollback and only after confirming no
-- references must be retained, an operator may run
--   ALTER TABLE `users` DROP COLUMN `profile_image_path`;
-- This migration never performs that destructive operation automatically.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `ssms_require_profile_image_prereqs` $$
CREATE PROCEDURE `ssms_require_profile_image_prereqs`()
BEGIN
    DECLARE v_users INT DEFAULT 0;
    DECLARE v_existing INT DEFAULT 0;
    DECLARE v_compatible INT DEFAULT 0;

    SELECT COUNT(*) INTO v_users
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users';

    IF v_users <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 049 requires the users table.';
    END IF;

    SELECT COUNT(*) INTO v_existing
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'profile_image_path';

    SELECT COUNT(*) INTO v_compatible
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'profile_image_path'
       AND DATA_TYPE = 'varchar'
       AND CHARACTER_MAXIMUM_LENGTH = 255
       AND IS_NULLABLE = 'YES';

    IF v_existing > 0 AND v_compatible <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Existing users.profile_image_path has an incompatible definition.';
    END IF;
END $$

CALL `ssms_require_profile_image_prereqs`() $$
DROP PROCEDURE IF EXISTS `ssms_require_profile_image_prereqs` $$

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `profile_image_path`
        VARCHAR(255) NULL DEFAULT NULL AFTER `full_name` $$

DELIMITER ;
