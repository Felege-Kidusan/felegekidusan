-- ============================================================
-- 069_guardian_linkage.sql   (IDEMPOTENT / RE-RUNNABLE)
-- Family Link: guardians entity + member<->guardian links + aliases +
-- steward review queue. Shared-hosting safe: no generated columns, no JSON
-- column type, no CHECK constraints, InnoDB + utf8mb4 like the rest of the
-- schema. Apply in phpMyAdmin before deploying the matching code.
-- ============================================================

CREATE TABLE IF NOT EXISTS `guardians` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `display_name` VARCHAR(150) NOT NULL,
  `phone_primary` VARCHAR(30) DEFAULT NULL,
  `phone_alt` VARCHAR(30) DEFAULT NULL,
  `phone_primary_n9` CHAR(9) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `phone_alt_n9` CHAR(9) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `city` VARCHAR(60) DEFAULT NULL,
  `sub_city` VARCHAR(60) DEFAULT NULL,
  `woreda` VARCHAR(10) DEFAULT NULL,
  `mender` VARCHAR(10) DEFAULT NULL,
  `block_number` VARCHAR(10) DEFAULT NULL,
  `house` VARCHAR(10) DEFAULT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','merged','archived') NOT NULL DEFAULT 'active',
  `merged_into` INT UNSIGNED DEFAULT NULL,
  `source` ENUM('hr','backfill') NOT NULL DEFAULT 'hr',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_guardians_phone_n9` (`phone_primary_n9`),
  KEY `idx_guardians_phone_alt_n9` (`phone_alt_n9`),
  KEY `idx_guardians_name` (`display_name`),
  KEY `idx_guardians_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_guardians` (
  `member_id` INT UNSIGNED NOT NULL,
  `guardian_id` INT UNSIGNED NOT NULL,
  `relation` ENUM('father','mother','other') NOT NULL DEFAULT 'other',
  `contact_priority` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `verified_by` INT UNSIGNED DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`member_id`,`guardian_id`),
  KEY `idx_mg_guardian` (`guardian_id`),
  KEY `idx_mg_member_primary` (`member_id`,`is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guardian_aliases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `guardian_id` INT UNSIGNED NOT NULL,
  `alias_name` VARCHAR(150) NOT NULL,
  `source` ENUM('hr','backfill') NOT NULL DEFAULT 'hr',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_alias_per_guardian` (`guardian_id`,`alias_name`),
  KEY `idx_alias_name` (`alias_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guardian_link_suggestions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `guardian_a` INT UNSIGNED DEFAULT NULL,
  `guardian_b` INT UNSIGNED DEFAULT NULL,
  `member_id` INT UNSIGNED DEFAULT NULL,
  `score` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
  `band` ENUM('auto','review') NOT NULL DEFAULT 'review',
  `evidence` TEXT DEFAULT NULL,
  `status` ENUM('pending','merged','rejected','expired') NOT NULL DEFAULT 'pending',
  `resolved_by` INT UNSIGNED DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gls_status` (`status`),
  KEY `idx_gls_pair` (`guardian_a`,`guardian_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
