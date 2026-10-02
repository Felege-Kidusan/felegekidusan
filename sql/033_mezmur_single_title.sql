-- 033: single Amharic title (Patch 28, item 9).
--
-- ORDERING REQUIREMENT (audit finding G, 2026-10-02) — apply AFTER:
--   • 031_mezmur_hymn_title_unique.sql — the UNIQUE title key whose
--     collision rules the fold below is written to respect.
--   • 032_mezmur_hymn_words.sql — CREATES `mezmur_hymn_words`, the table
--     step 3 clears. Run 033 first and that DELETE fails with error 1146
--     ("table doesn't exist") AFTER step 2 has already dropped title_am
--     and reference — a half-applied, destructive migration. Step 3 is
--     therefore guarded below so a wrong order reports the cause instead
--     of aborting mid-way.
-- Numeric order (031 → 032 → 033) satisfies this; see
-- docs/audits/DEPLOYMENT_RUNBOOK.md stage 4.7.
-- Why: hymns carried THREE text fields (title, title_am, reference).
-- The product decision is ONE title field — the Amharic name IS the
-- hymn's real name — and the reference field is retired.
--
-- Collision safety (the UNIQUE title key from 031 must never fire
-- mid-migration): a candidate folds ONLY when
--   a) no OTHER row already owns the target as its title, and
--   b) it is the ONLY candidate aiming at that target.
-- Everything else is left for the curator to resolve by hand.
--
-- Idempotent: the fold is guarded on the column still existing, and
-- both ALTERs use DROP COLUMN IF EXISTS — re-runs are no-ops.

-- 1. Fold the Amharic title into the canonical title (one time).
SET @has_title_am := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mezmur_hymns' AND COLUMN_NAME = 'title_am');
SET @fold_sql := IF(@has_title_am = 1, "
  UPDATE mezmur_hymns h
  JOIN (
    SELECT x.id, x.title_am
    FROM mezmur_hymns x
    WHERE x.title_am IS NOT NULL AND x.title_am <> '' AND x.title <> x.title_am
      AND NOT EXISTS (
        SELECT 1 FROM (SELECT id, title FROM mezmur_hymns) o
        WHERE o.title = x.title_am AND o.id <> x.id)
      AND (
        SELECT COUNT(*) FROM (
          SELECT title_am FROM mezmur_hymns
          WHERE title_am IS NOT NULL AND title_am <> '' AND title <> title_am) d
        WHERE d.title_am = x.title_am) = 1
  ) c ON c.id = h.id
  SET h.title = c.title_am", "SELECT 'single-title fold already applied' AS info");
PREPARE fold_stmt FROM @fold_sql;
EXECUTE fold_stmt;
DEALLOCATE PREPARE fold_stmt;

-- 2. Drop the retired columns (MariaDB: IF EXISTS guards re-runs).
ALTER TABLE mezmur_hymns DROP COLUMN IF EXISTS title_am;
ALTER TABLE mezmur_hymns DROP COLUMN IF EXISTS reference;

-- 3. Clear the word index: rows built from the retired fields would
-- keep matching queries those hymns can no longer satisfy. The schema
-- reconciler (admin action 'migrate') backfills every hymn that has no
-- word rows — from title + lyrics only, per the P28 service change.
--
-- Guarded (finding G): `mezmur_hymn_words` is created by 032. If 032 has
-- not run, say so plainly rather than failing with a bare error 1146 —
-- the DROP COLUMNs above have already committed by this point, so a raw
-- abort here leaves the database half-migrated and the cause unobvious.
SET @mz33_words := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mezmur_hymn_words');
SET @mz33_sql := IF(@mz33_words = 1,
  'DELETE FROM mezmur_hymn_words',
  'SELECT ''BLOCKER: mezmur_hymn_words is missing — run sql/032_mezmur_hymn_words.sql, then re-run this file (steps 1-2 are idempotent) to clear the stale word index.'' AS error');
PREPARE mz33_stmt FROM @mz33_sql;
EXECUTE mz33_stmt;
DEALLOCATE PREPARE mz33_stmt;
