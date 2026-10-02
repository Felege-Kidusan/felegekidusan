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
SET @mz31_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'mezmur_hymns'
      AND index_name = 'uq_mezmur_hymns_title'
);

-- Finding H (2026-10-02): the old single message read "skipped (duplicates
-- present or index already exists)" — it conflated a DEPLOYMENT BLOCKER
-- (index absent, race still open) with a perfectly fine no-op (index
-- already there). An operator could not tell which had happened. The three
-- outcomes are now reported distinctly. Data is still never destroyed or
-- merged here: duplicates remain for a human to resolve.
SET @mz31_stmt := IF(
    @mz31_exists > 0,
    'SELECT ''OK: uq_mezmur_hymns_title already present — nothing to do.'' AS status',
    IF(
        @mz31_dup = 0,
        'ALTER TABLE `mezmur_hymns` ADD UNIQUE KEY `uq_mezmur_hymns_title` (`title`)',
        'SELECT ''BLOCKER: uq_mezmur_hymns_title NOT created — case-insensitive duplicate titles exist (listed above). Merge them, then re-run this file. Until then duplicate hymn titles can still be created by concurrent writers.'' AS status'
    )
);
PREPARE mz31_stmt FROM @mz31_stmt; EXECUTE mz31_stmt; DEALLOCATE PREPARE mz31_stmt;

-- Deterministic verdict: re-reads the catalogue AFTER the attempt, so the
-- final row reflects reality rather than what we intended to do.
SELECT CASE
    WHEN EXISTS(
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'mezmur_hymns'
          AND index_name = 'uq_mezmur_hymns_title'
    ) THEN 'PASS: uq_mezmur_hymns_title exists.'
    ELSE 'BLOCKER: uq_mezmur_hymns_title is MISSING — do not sign off this deployment.'
END AS mz31_verification;
