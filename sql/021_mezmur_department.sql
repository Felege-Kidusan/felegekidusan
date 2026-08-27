-- ════════════════════════════════════════════════════════════
-- 021 — Mezmur Department (መዝሙር ክፍል)
-- Hymn library schema + Mezmur entry in the staff-position
-- department catalogue (identity v2).
--
-- Safety: creates NEW objects only (CREATE TABLE IF NOT EXISTS,
-- guarded INSERT). Nothing existing is altered. Idempotent.
-- ════════════════════════════════════════════════════════════

-- ── Hymn library ─────────────────────────────────────────────
-- Text-only v1 (lyrics/reference/category). Audio support is a
-- future phase and will add storage OUTSIDE the web root.
CREATE TABLE IF NOT EXISTS `mezmur_hymns` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255) NOT NULL,
    `title_am`    VARCHAR(255) DEFAULT NULL,
    `category`    VARCHAR(50)  NOT NULL DEFAULT 'general',
    `reference`   VARCHAR(255) DEFAULT NULL,
    `lyrics`      LONGTEXT     DEFAULT NULL,
    `status`      ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_by`  INT UNSIGNED DEFAULT NULL,
    `updated_by`  INT UNSIGNED DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mezmur_hymns_status_category` (`status`, `category`),
    KEY `idx_mezmur_hymns_title` (`title`),
    KEY `idx_mezmur_hymns_created_at` (`created_at`),
    FULLTEXT KEY `ft_mezmur_hymns_titles` (`title`, `title_am`),
    CONSTRAINT `fk_mezmur_hymns_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_mezmur_hymns_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Mezmur in the staff-position department catalogue ────────
-- Code 'MZ' → position codes look like MZH-48213 once leadership
-- adds positions for the department in Identity & Codes.
INSERT INTO `departments` (`code`, `name_am`, `name_en`, `sort_order`)
SELECT 'MZ', 'መዝሙር ክፍል', 'Mezmur Department', 4
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `code` = 'MZ');
