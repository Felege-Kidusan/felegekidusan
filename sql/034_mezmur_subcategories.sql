-- 034: two-level taxonomy (Patch 30) — main category → sub-categories.
-- Model (large-catalog browse hierarchy): a handful of MAIN categories on
-- top, fine-grained SUB-categories under them, hymns live at the leaves.
-- Browse drills down: main → subs → hymns. Filtering by a main category
-- rolls up (matches itself + all of its subs) in every query path.
--
-- Structure: single adjacency table (parent_id on mezmur_categories,
-- app-enforced depth of 2) — one management UI, one sync shape, counts
-- roll up with a single join. Uniqueness moves from global name to
-- (parent_id, name): "አጠቃላይ" may exist under many mains; the service
-- keeps enforcing uniqueness among mains.
--
-- Data migration (chosen by the owner, 2026-09-02): every EXISTING
-- category becomes a MAIN category; one "አጠቃላይ" (General) sub is
-- created under it and all its hymn links move there. Idempotent:
-- guarded ADDs + INSERT IGNORE + the scoped unique key make every
-- statement a no-op on re-run.

ALTER TABLE mezmur_categories ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED NULL DEFAULT NULL AFTER name;
ALTER TABLE mezmur_categories ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL DEFAULT NULL AFTER parent_id;
ALTER TABLE mezmur_categories ADD INDEX IF NOT EXISTS idx_mc_parent (parent_id);

-- Unique scope swap: global name → (parent_id, name). Guarded so a
-- re-run (or a partially applied migration) never fails.
SET @has_old_uq := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mezmur_categories' AND INDEX_NAME = 'uq_mezmur_categories_name');
SET @swap_sql := IF(@has_old_uq = 1,
  "ALTER TABLE mezmur_categories DROP INDEX uq_mezmur_categories_name,
     ADD UNIQUE KEY uq_mc_parent_name (parent_id, name)",
  "SELECT 'scoped unique already in place' AS info");
PREPARE swap_stmt FROM @swap_sql;
EXECUTE swap_stmt;
DEALLOCATE PREPARE swap_stmt;

-- Referential integrity: a sub must point at a real main (RESTRICT —
-- categories are soft-hidden, never hard-deleted, by the service).
SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mezmur_categories' AND CONSTRAINT_NAME = 'fk_mc_parent');
SET @fk_sql := IF(@has_fk = 0,
  "ALTER TABLE mezmur_categories ADD CONSTRAINT fk_mc_parent
     FOREIGN KEY (parent_id) REFERENCES mezmur_categories (id)",
  "SELECT 'parent FK already in place' AS info");
PREPARE fk_stmt FROM @fk_sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;

-- One "አጠቃላይ" (General) sub under every existing main (no-op on re-run
-- via the scoped unique key).
INSERT IGNORE INTO mezmur_categories (name, parent_id, sort_order, is_active, created_by)
SELECT 'አጠቃላይ', c.id, 0, c.is_active, c.created_by
FROM mezmur_categories c
WHERE c.parent_id IS NULL;

-- Move every hymn link from the main to its General sub, then drop the
-- main-level link (hymns live at the leaves from now on).
INSERT IGNORE INTO mezmur_hymn_categories (hymn_id, category_id)
SELECT hc.hymn_id, ns.id
FROM mezmur_hymn_categories hc
JOIN mezmur_categories c ON c.id = hc.category_id AND c.parent_id IS NULL
JOIN mezmur_categories ns ON ns.parent_id = c.id AND ns.name = 'አጠቃላይ';
DELETE hc FROM mezmur_hymn_categories hc
JOIN mezmur_categories c ON c.id = hc.category_id
WHERE c.parent_id IS NULL;
