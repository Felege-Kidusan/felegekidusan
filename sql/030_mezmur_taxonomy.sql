-- ════════════════════════════════════════════════════════════
-- 030 — Mezmur hymn taxonomy (multi-category + singers + type)
-- ════════════════════════════════════════════════════════════
-- Builds on 021/025. Adds the relational model that replaces the
-- old single-string `mezmur_hymns.category`:
--
--   • mezmur_hymns.length      ENUM('long','short')     (default long)
--   • mezmur_hymns.language    ENUM('geez','amharic')   (default amharic)
--   • mezmur_hymn_categories   — many-to-many hymn ↔ canonical category
--   • mezmur_zemarians          — singer/artist catalogue
--   • mezmur_hymn_zemarians    — many-to-many hymn ↔ singer
--
-- The legacy `category` string is retained for read-backward
-- compatibility; the join tables are the new source of truth.
-- Existing rows are backfilled so nothing already saved is lost.
--
-- Fully idempotent; guarded ALTERs only; safe to re-run.
-- ════════════════════════════════════════════════════════════

-- ── 1. length / language flags (guarded) ─────────────────────
SET @mz_len := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'mezmur_hymns'
             AND column_name = 'length'),
    'SELECT 1',
    "ALTER TABLE `mezmur_hymns` ADD COLUMN `length` ENUM('long','short') NOT NULL DEFAULT 'long' AFTER `reference`"
);
PREPARE mz_stmt FROM @mz_len; EXECUTE mz_stmt; DEALLOCATE PREPARE mz_stmt;

SET @mz_lang := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'mezmur_hymns'
             AND column_name = 'language'),
    'SELECT 1',
    "ALTER TABLE `mezmur_hymns` ADD COLUMN `language` ENUM('geez','amharic') NOT NULL DEFAULT 'amharic' AFTER `length`"
);
PREPARE mz_stmt FROM @mz_lang; EXECUTE mz_stmt; DEALLOCATE PREPARE mz_stmt;

-- ── 2. hymn ↔ category join ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `mezmur_hymn_categories` (
    `hymn_id`     BIGINT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`hymn_id`, `category_id`),
    KEY `idx_mhc_category` (`category_id`),
    CONSTRAINT `fk_mhc_hymn`     FOREIGN KEY (`hymn_id`)
        REFERENCES `mezmur_hymns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_mhc_category` FOREIGN KEY (`category_id`)
        REFERENCES `mezmur_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. singer / zemarian catalogue ────────────────────────────
CREATE TABLE IF NOT EXISTS `mezmur_zemarians` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100) NOT NULL,
    `name_am`    VARCHAR(100) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mezmur_zemarians_name` (`name`),
    KEY `idx_mezmur_zemarians_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. hymn ↔ singer join ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mezmur_hymn_zemarians` (
    `hymn_id`     BIGINT UNSIGNED NOT NULL,
    `zemarian_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`hymn_id`, `zemarian_id`),
    KEY `idx_mhz_zemarian` (`zemarian_id`),
    CONSTRAINT `fk_mhz_hymn`     FOREIGN KEY (`hymn_id`)
        REFERENCES `mezmur_hymns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_mhz_zemarian` FOREIGN KEY (`zemarian_id`)
        REFERENCES `mezmur_zemarians` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. backfill legacy single-string categories ───────────────
-- Ensure every distinct legacy category (except the 'general'
-- placeholder and empty) exists in the canonical category list.
INSERT IGNORE INTO `mezmur_categories` (`name`, `sort_order`)
SELECT DISTINCT `category`, 100
FROM `mezmur_hymns`
WHERE `category` <> '' AND `category` IS NOT NULL AND `category` <> 'general';

-- Link each hymn to its legacy category row.
INSERT IGNORE INTO `mezmur_hymn_categories` (`hymn_id`, `category_id`)
SELECT h.`id`, c.`id`
FROM `mezmur_hymns` h
JOIN `mezmur_categories` c ON c.`name` = h.`category`
WHERE h.`category` <> '' AND h.`category` IS NOT NULL AND h.`category` <> 'general';