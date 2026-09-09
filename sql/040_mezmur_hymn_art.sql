-- ============================================================
-- 040: HYMN ART (P66) — per-hymn cover image plane
-- ============================================================
-- Every hymn can carry its own cover art, Spotify-style:
--   • stored ON THE SHARED-HOSTING SERVER (local disk), NOT on R2
--     (R2 remains the audio-only media plane by owner decision);
--   • three fixed square renditions written at upload time by
--     MezmurArtService: {art_key}_160.jpg / _320.jpg / _640.jpg
--     (list thumb / card / hero — the multi-rendition model used by
--     Spotify & Apple Music);
--   • art_key is a RELATIVE PATH PREFIX inside uploads/mezmur_art/;
--     public URLs are rebuilt from it at read time and version-tagged
--     with art_updated_at (?v=) so a new artwork always means a new
--     URL (immutable-URL caching, no disk stat per request);
--   • art_color is the server-extracted dominant color (#rrggbb) used
--     by clients for UI theming (the Spotify "color as emotional
--     infrastructure" pattern, computed ONCE at ingest);
--   • art_status mirrors the audio status machine
--     (none → pending → ready | rejected) for forward compatibility,
--     though the single-request upload path goes straight to ready.
--
-- SAFETY (repo style):
--   • additive columns only — no existing column is touched;
--   • every ALTER is guarded on information_schema so re-runs are
--     no-ops (same idempotent pattern as 038);
--   • the MezmurSchemaReconciler owns the same contract so the
--     console's "Sync DB schema" button can close drift on its own
--     (the F1 lesson from 038: never ship columns the reconciler
--     cannot add).
-- ============================================================

SET @mz40_has_art_key := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mezmur_hymns' AND COLUMN_NAME = 'art_key');
SET @mz40_stmt := IF(@mz40_has_art_key = 0, "
  ALTER TABLE `mezmur_hymns`
    ADD COLUMN `art_key`         VARCHAR(255) DEFAULT NULL
      COMMENT 'relative path prefix of the art renditions (uploads/mezmur_art/…)',
    ADD COLUMN `art_status`      ENUM('none','pending','ready','rejected')
      NOT NULL DEFAULT 'none',
    ADD COLUMN `art_color`       CHAR(7) DEFAULT NULL
      COMMENT 'server-extracted dominant color #rrggbb',
    ADD COLUMN `art_uploaded_by` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN `art_updated_at`  DATETIME DEFAULT NULL
  ", 'SELECT 1');
PREPARE mz40_stmt FROM @mz40_stmt; EXECUTE mz40_stmt; DEALLOCATE PREPARE mz40_stmt;

-- Status index (same shape as 038's audio index): the manager lists
-- "hymns with art" / "without art" and diagnostics probe this column.
SET @mz40_has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mezmur_hymns' AND INDEX_NAME = 'idx_mz40_art_status');
SET @mz40_stmt := IF(@mz40_has_idx = 0,
  "ALTER TABLE `mezmur_hymns` ADD INDEX `idx_mz40_art_status` (`art_status`)",
  'SELECT 1');
PREPARE mz40_stmt FROM @mz40_stmt; EXECUTE mz40_stmt; DEALLOCATE PREPARE mz40_stmt;
