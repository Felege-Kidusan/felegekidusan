-- ============================================================================
-- Migration 047: Account Self-Service ("One Identity, Every Surface")
-- ============================================================================
-- Adds the optional profile fields used by the shared account component
-- (admin/components/account_settings.php). Safe to rerun; every DDL is
-- guarded with the same information_schema pattern as migration 012.
--
-- No existing column changes, no destructive statements. The account
-- component and api_settings.php feature-detect `phone` so they keep
-- working even before this migration is applied.
-- ============================================================================

-- users.phone — validated by the platform's validatePhone() helper
-- (config.php), normalized to 09xxxxxxxx / +<international> form.
SET @migration_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='phone'),
        'ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL AFTER `email`', 'SELECT 1'
    )
);
PREPARE wbws_schema_stmt FROM @migration_sql;
EXECUTE wbws_schema_stmt;
DEALLOCATE PREPARE wbws_schema_stmt;
