-- ============================================================
-- 017_identity_codes.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Ministry three-category identity + configurable department /
-- staff-position coding system.
--
-- Categories (letters are configuration-free constants of the ministry
-- structure, stored as data here so dashboards can stay dynamic):
--   A = ህጻናት      (age_group '7_13')
--   B = ማዕከላዊያን   (age_group '14_17')
--   C = ወጣቶች      (age_group '18_plus')
-- The fourth group 'under6' (አጸደ ህጻናት) is retired: its rows merge into
-- ህጻናት below. Section assignment is manual-only everywhere.
--
-- Apply once during deployment with an authorized DB account. Renumbering
-- of member codes and QR regeneration is performed by the reviewed CLI
-- tool admin/tools/migrate_identity_codes.php AFTER this file succeeds.
-- ============================================================

-- ── Departments ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `name_am` VARCHAR(150) NOT NULL,
    `name_en` VARCHAR(150) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_departments_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Staff positions ─────────────────────────────────────────
-- role_code examples: H=head-of-department marker, T=teacher,
-- N is implicit for ordinary members (never stored), S=secretary,
-- D=director … fully managed by Super Admin.
-- department_id NULL => school-wide post (Director, Secretary, …).
CREATE TABLE IF NOT EXISTS `staff_positions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `role_code` VARCHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `title_am` VARCHAR(150) NOT NULL,
    `title_en` VARCHAR(150) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_position_dept_role` (`department_id`, `role_code`),
    KEY `idx_positions_active` (`is_active`, `sort_order`),
    CONSTRAINT `fk_positions_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Member ↔ position assignments (source of truth for codes) ──
CREATE TABLE IF NOT EXISTS `member_staff_positions` (
    `member_id` INT UNSIGNED NOT NULL,
    `position_id` INT UNSIGNED NOT NULL,
    `assigned_by` INT UNSIGNED DEFAULT NULL,
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`member_id`, `position_id`),
    KEY `idx_msp_position` (`position_id`),
    CONSTRAINT `fk_msp_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_msp_position` FOREIGN KEY (`position_id`) REFERENCES `staff_positions`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Retire the fourth category (አጸደ ህጻናት / under6) ──────────
UPDATE `members` SET `age_group` = '7_13' WHERE `age_group` = 'under6';
UPDATE `classes`  SET `age_group` = '7_13' WHERE `age_group` = 'under6';

-- ── Code-change traceability ────────────────────────────────
SET @legacy_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'members'
                 AND column_name = 'legacy_member_code'),
        'SELECT 1',
        'ALTER TABLE `members` ADD COLUMN `legacy_member_code` VARCHAR(32) NULL '
            || 'AFTER `member_code`'
    )
);
PREPARE legacy_stmt FROM @legacy_sql;
EXECUTE legacy_stmt;
DEALLOCATE PREPARE legacy_stmt;

CREATE TABLE IF NOT EXISTS `member_code_migrations` (
    `member_id` INT UNSIGNED NOT NULL,
    `old_code` VARCHAR(32) NOT NULL,
    `new_code` VARCHAR(32) NOT NULL,
    `reason` VARCHAR(64) NOT NULL DEFAULT 'ministry_renumber',
    `migrated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`member_id`),
    KEY `idx_code_migration_old` (`old_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed departments confirmed by leadership (fully editable later) ──
INSERT INTO `departments` (`code`, `name_am`, `name_en`, `sort_order`)
SELECT 'ED', 'የትምህርት ክፍል', 'Education Department', 1
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `code` = 'ED');
INSERT INTO `departments` (`code`, `name_am`, `name_en`, `sort_order`)
SELECT 'ID', 'የመረጃ ክፍል', 'Information Department', 2
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `code` = 'ID');
INSERT INTO `departments` (`code`, `name_am`, `name_en`, `sort_order`)
SELECT 'HRD', 'የሰው ሀይል ክፍል', 'Human Resource Department', 3
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `code` = 'HRD');

-- ── Scale indexes for code lookups at six-figure rosters ────
SET @cat_idx_sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'members'
                 AND index_name = 'idx_members_age_group'),
        'SELECT 1',
        'ALTER TABLE `members` ADD INDEX `idx_members_age_group` (`age_group`)'
    )
);
PREPARE cat_idx_stmt FROM @cat_idx_sql;
EXECUTE cat_idx_stmt;
DEALLOCATE PREPARE cat_idx_stmt;
