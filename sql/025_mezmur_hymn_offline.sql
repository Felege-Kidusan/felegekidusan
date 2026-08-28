-- ════════════════════════════════════════════════════════════
-- 025 — Mezmur hymns go offline-first on mobile
-- ════════════════════════════════════════════════════════════
-- Local-first sync (Telegram/Drive pattern): the app keeps a
-- full local copy of the library, pushes queued mutations with
-- idempotency keys, and pulls a DELTA of rows changed since its
-- last cursor. This migration gives the server what that needs:
--
--   • mezmur_categories — canonical category list (managed from
--     the app), seeded with the three official sections.
--   • mezmur_hymns.revision — monotonic per-row version for
--     optimistic-conflict detection on offline edits.
--   • mezmur_hymns delta index — (updated_at, id) cursor scans
--     stay index-only at hundreds of thousands of rows.
--
-- Fully idempotent; guarded ALTERs only; safe to re-run.
-- ════════════════════════════════════════════════════════════

-- ── 1. Canonical categories ──────────────────────────────────
CREATE TABLE IF NOT EXISTS `mezmur_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(50) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mezmur_categories_name` (`name`),
    KEY `idx_mezmur_categories_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canonical seed (idempotent via the unique name key).
INSERT IGNORE INTO `mezmur_categories` (`name`, `sort_order`) VALUES
    ('ህናት', 1),
    ('ማዕከዊያን', 2),
    ('ጣቶች', 3);

-- ── 2. Revision counter on hymns (guarded) ───────────────────
SET @mz_add_revision := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_hymns'
             AND column_name = 'revision'),
    'SELECT 1',
    'ALTER TABLE `mezmur_hymns`
       ADD COLUMN `revision` INT UNSIGNED NOT NULL DEFAULT 1'
);
PREPARE mz_stmt FROM @mz_add_revision;
EXECUTE mz_stmt;
DEALLOCATE PREPARE mz_stmt;

-- Backfill revisions for pre-existing rows (all already at 1).
UPDATE `mezmur_hymns` SET `revision` = 1 WHERE `revision` IS NULL OR `revision` < 1;

-- ── 3. Delta-sync cursor index (guarded) ─────────────────────
SET @mz_add_delta_idx := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_hymns'
             AND index_name = 'idx_mezmur_hymns_updated'),
    'SELECT 1',
    'ALTER TABLE `mezmur_hymns`
       ADD KEY `idx_mezmur_hymns_updated` (`updated_at`, `id`)'
);
PREPARE mz_stmt FROM @mz_add_delta_idx;
EXECUTE mz_stmt;
DEALLOCATE PREPARE mz_stmt;
