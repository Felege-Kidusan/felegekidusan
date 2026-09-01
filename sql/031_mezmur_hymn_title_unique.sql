-- ════════════════════════════════════════════════════════════
-- 031 — case-insensitive UNIQUE hymn titles (MZ-6)
-- ════════════════════════════════════════════════════════════
-- The application has always refused duplicate titles with a
-- SELECT-then-INSERT pair — which loses under concurrency: two
-- writers pass the check together and both insert (check-then-act
-- race). This closes the race at the storage layer with a real
-- UNIQUE index. The table collation is utf8mb4_unicode_ci, so the
-- index is case-insensitive exactly like the LOWER() checks in
-- MezmurHymnService, which also maps error 1062 to the same friendly
-- message ("A hymn with this title already exists.") — behaviour is
-- unchanged for users, minus the race window.
--
-- Existing case-insensitive duplicates block the index; they are
-- reported below and the ADD is skipped (merge them, then re-run).
-- Fully idempotent; guarded; safe to re-run.
-- ══════════════════════════════════════════════════════════════

-- Report any blockers (empty result set = clean).
SELECT LOWER(title) AS duplicate_title_ci, COUNT(*) AS copies, GROUP_CONCAT(id) AS hymn_ids
FROM mezmur_hymns
GROUP BY LOWER(title)
HAVING COUNT(*) > 1;

SET @mz31_dup := (
    SELECT COUNT(*) FROM (
        SELECT 1 FROM mezmur_hymns GROUP BY LOWER(title) HAVING COUNT(*) > 1
    ) AS mz31_d
);
SET @mz31_stmt := IF(
    @mz31_dup = 0
    AND NOT EXISTS(
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'mezmur_hymns'
          AND index_name = 'uq_mezmur_hymns_title'
    ),
    'ALTER TABLE `mezmur_hymns` ADD UNIQUE KEY `uq_mezmur_hymns_title` (`title`)',
    'SELECT ''uq_mezmur_hymns_title: skipped (duplicates present or index already exists)'' AS info'
);
PREPARE mz31_stmt FROM @mz31_stmt; EXECUTE mz31_stmt; DEALLOCATE PREPARE mz31_stmt;
