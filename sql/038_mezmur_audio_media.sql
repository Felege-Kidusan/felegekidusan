-- ════════════════════════════════════════════════════════════
-- 038 — Mezmur audio media + synced lyrics (DRAFT — for review)
-- ════════════════════════════════════════════════════════════
-- Companion to mezmur-audio-upgrade/DEEP_ANALYSIS_AUDIO_UPGRADE.md
--
-- Design intent (read first):
--   • Audio BYTES never live in this database nor on the shared
--     host. mezmur_hymns carries ONLY an object key that points to
--     Cloudflare R2 (mz/audio/{hymn_id}/{uuid}.{ext}). The URL is
--     built by MezmurMediaService at read time.
--   • sql/021's comment said audio "will add storage OUTSIDE the
--     web root" — R2 is outside the server entirely.
--   • lyrics_synced stores timed LRC TEXT (≈2.4 KB/hymn) so synced
--     lyrics ride the existing revision/delta-sync system. The
--     existing `lyrics` column (pretty markup) is untouched and
--     remains the fallback + the source text for re-timing.
--   • audio_status gates half-finished uploads: two-phase
--     presign → direct-to-R2 PUT → confirm.
--   • Every row change here bumps revision/updated_at VIA THE
--     SERVICE so mobile delta pulls converge correctly.
--
-- Safety: idempotent, guarded ALTERs only (repo sql/0XX style);
-- probes information_schema; safe to re-run. Apply AFTER code that
-- probes columns (MezmurHymnService already degrades gracefully on
-- a pre-038 schema).
-- ════════════════════════════════════════════════════════════

-- ── 1. Audio metadata on the hymn row ─────────────────────────
SET @mz38_audio_key := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'mezmur_hymns'
             AND column_name = 'audio_key'),
    'SELECT 1',
    "ALTER TABLE `mezmur_hymns`
       ADD COLUMN `audio_key`        VARCHAR(255)  DEFAULT NULL AFTER `language`,
       ADD COLUMN `audio_duration_s` INT UNSIGNED  DEFAULT NULL AFTER `audio_key`,
       ADD COLUMN `audio_size`       INT UNSIGNED  DEFAULT NULL AFTER `audio_duration_s`,
       ADD COLUMN `audio_format`     VARCHAR(10)   DEFAULT NULL AFTER `audio_size`,
       ADD COLUMN `audio_status`     ENUM('none','pending','ready','rejected')
                                    NOT NULL DEFAULT 'none' AFTER `audio_format`,
       ADD COLUMN `audio_uploaded_by` INT UNSIGNED DEFAULT NULL AFTER `audio_status`,
       ADD COLUMN `audio_updated_at` DATETIME      DEFAULT NULL AFTER `audio_uploaded_by`"
);
PREPARE mz38_stmt FROM @mz38_audio_key; EXECUTE mz38_stmt; DEALLOCATE PREPARE mz38_stmt;

SET @mz38_audio_idx := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'mezmur_hymns'
             AND index_name = 'idx_mz38_audio_status'),
    'SELECT 1',
    'ALTER TABLE `mezmur_hymns`
       ADD INDEX `idx_mz38_audio_status` (`audio_status`)'
);
PREPARE mz38_stmt FROM @mz38_audio_idx; EXECUTE mz38_stmt; DEALLOCATE PREPARE mz38_stmt;

-- ── 2. Synced (timed) lyrics — LRC text, separate from `lyrics` ─
-- LRC lines: [mm:ss.xx]text  (+ optional word-level <mm:ss.xx> tags).
SET @mz38_lrc := IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'mezmur_hymns'
             AND column_name = 'lyrics_synced'),
    'SELECT 1',
    "ALTER TABLE `mezmur_hymns`
       ADD COLUMN `lyrics_synced`    LONGTEXT   DEFAULT NULL AFTER `lyrics`,
       ADD COLUMN `lyrics_synced_at` DATETIME   DEFAULT NULL AFTER `lyrics_synced`,
       ADD COLUMN `lyrics_synced_by` INT UNSIGNED DEFAULT NULL AFTER `lyrics_synced_at`"
);
PREPARE mz38_stmt FROM @mz38_lrc; EXECUTE mz38_stmt; DEALLOCATE PREPARE mz38_stmt;

-- ── 3. Optional: daily play counter (Spotify-style "Top hymns"). ─
-- Mobile/Web coalesce play events client-side and flush a tiny
-- JSON batch through the existing rate-limited endpoints; never
-- one row per stream start. Skipping this table is fine — the
-- service must probe before use (repo pattern).
CREATE TABLE IF NOT EXISTS `mezmur_play_stats` (
    `hymn_id` BIGINT UNSIGNED NOT NULL,
    `day`     DATE NOT NULL,
    `plays`   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`hymn_id`, `day`),
    CONSTRAINT `fk_mps_hymn`
        FOREIGN KEY (`hymn_id`) REFERENCES `mezmur_hymns` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Optional: per-user favorites ("Your Library" heart). ────
CREATE TABLE IF NOT EXISTS `mezmur_user_favorites` (
    `user_id`    INT UNSIGNED NOT NULL,
    `hymn_id`    BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `hymn_id`),
    KEY `idx_muf_hymn` (`hymn_id`),
    CONSTRAINT `fk_muf_hymn`
        FOREIGN KEY (`hymn_id`) REFERENCES `mezmur_hymns` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════
-- NOTES FOR THE REVIEWER
--   • audio_uploaded_by / lyrics_synced_by mirror created_by/updated_by
--     FK-to-users discipline elsewhere (add FKs if you want them —
--     repo convention is ON DELETE SET NULL).
--   • add the same audio_* columns to the word-index rebuild? NO:
--     search already reads `lyrics`; LRC adds no search value.
--   • indexes: list/search already filter status/category; add
--     (audio_status) only if you build a "missing audio" curation
--     view (recommended — this index is for that).
--   • version bump: every code path that writes audio/LRC must call
--     the service's revision bump so /mezmur/hymns/changes converges.
-- ════════════════════════════════════════════════════════════
