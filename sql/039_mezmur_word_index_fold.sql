-- ════════════════════════════════════════════════════════════════
-- 039 — P42: rebuild the Mezmur word index after homophone folding
-- ════════════════════════════════════════════════════════════════
-- WHY THIS IS REQUIRED
--
-- P42 added Amharic homophone folding to MezmurHymnService::tokenizeWords()
-- so that ጸ/ፀ, ሀ/ሐ/ኀ, ሠ/ሰ and አ/ዐ all match one another. The QUERY side
-- now folds too — which means the keys already sitting in
-- mezmur_hymn_words were written by the OLD, unfolded tokenizer and no
-- longer match what the query side looks for.
--
-- Concretely: a hymn titled ፀሐይ is indexed under the literal word "ፀሐይ",
-- but a search for ጸሀይ (or for ፀሐይ itself) now folds to "ጸሀይ" and finds
-- nothing. Without this rebuild, folding makes search WORSE, not better,
-- for every hymn already in the table.
--
-- Note that backfillHymnWords() will NOT fix this: it only touches hymns
-- that have no word rows at all, and these hymns have rows — just stale
-- ones. The index must be emptied so every hymn is re-tokenised.
--
-- WHAT THIS DOES
--
-- Empties mezmur_hymn_words. The application refills it automatically:
--   · every hymn save calls reindexHymnWords()
--   · backfillHymnWords() re-indexes hymns that have no rows, in
--     batches, and after this truncate that is ALL of them
--
-- Search degrades gracefully while the backfill runs: searchWordCandidates()
-- returns [] for an empty table, and listHymns() falls back to the
-- title/lyrics LIKE path. Users see results throughout — slower and
-- without homophone folding, but never an empty screen.
--
-- SAFETY
--
-- mezmur_hymn_words is a pure derived index. It holds no authored data:
-- every row can be recomputed from mezmur_hymns.title and .lyrics.
-- Deleting it cannot lose anything a user typed. It is safe to re-run.
--
-- AFTER RUNNING
--
-- Trigger the backfill (admin migrate action, or any hymn save), then
-- verify with the check at the bottom of this file.
-- ════════════════════════════════════════════════════════════════

-- Guarded: only acts when the table actually exists.
SET @has_words := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mezmur_hymn_words'
);

-- Report the pre-state so the operator can see what changed.
SET @before := 0;
SET @sql := IF(@has_words > 0,
    'SELECT COUNT(*) INTO @before FROM mezmur_hymn_words',
    'SELECT 0 INTO @before');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- DELETE rather than TRUNCATE: TRUNCATE is DDL and implicitly commits,
-- which would break a wrapping transaction, and it needs DROP privilege
-- that a constrained deploy user may not hold.
SET @sql := IF(@has_words > 0,
    'DELETE FROM mezmur_hymn_words',
    'SELECT "mezmur_hymn_words absent — nothing to rebuild" AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT
    @before                                   AS rows_cleared,
    (SELECT COUNT(*) FROM mezmur_hymns)       AS hymns_awaiting_reindex,
    'Run the admin backfill (or save any hymn) to repopulate' AS next_step;

-- ── Verification, run AFTER the backfill completes ──────────────
-- Expect zero rows. Any hymn listed here still has no index entries.
--
--   SELECT h.id, h.title
--   FROM mezmur_hymns h
--   WHERE NOT EXISTS (
--       SELECT 1 FROM mezmur_hymn_words w WHERE w.hymn_id = h.id
--   );
--
-- And confirm folding actually landed — this should return rows for a
-- hymn whose title contains ፀ, indexed under the folded ጸ form:
--
--   SELECT word, hymn_id FROM mezmur_hymn_words
--   WHERE word LIKE '%ጸ%' LIMIT 20;
