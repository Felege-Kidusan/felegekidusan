-- ============================================================
-- 057 — Shared synchronization change feed (PHASE A foundation)
-- ============================================================
-- Devices cache data locally so the app works offline. When another
-- device or a web user changes the same row, the cached copy goes stale
-- and the app has no reliable way to notice. This migration adds the
-- server side of the fix: one append-only feed of "what changed", keyed
-- by a monotonic revision the client can resume from.
--
-- WHY A CHANGE LOG AND TRIGGERS, not updated_at scanning:
--   * `attendance` has no updated_at column (only recorded_at, set once
--     on insert), so a timestamp keyset cannot see edits at all.
--   * A timestamp keyset can never see a DELETE. Deletions must reach
--     devices as tombstones, not as rows that silently stop appearing.
--   * Rows are written by many existing PHP endpoints and by direct SQL.
--     A service-layer recorder would only catch callers that remember to
--     call it; triggers capture every path into the table.
-- The hymn delta (MezmurHymnService::listChangedSince) keeps its own
-- updated_at cursor: it works there because mezmur_hymns has updated_at
-- and deletes are soft (status='archived'). That endpoint is untouched.
--
-- SCOPE OF THIS MIGRATION: the feed itself plus `attendance`, the first
-- representative domain. Other domains are added one migration at a
-- time, each with its own triggers, as they are migrated and verified.
--
-- NO new foreign keys: scripts/restore_production_dump.sh asserts an
-- exact FK count (39 pre-055 / 42 post-055). The feed deliberately keeps
-- no FK to the rows it describes, because a tombstone must outlive the
-- row it refers to.
--
-- Idempotent: safe to re-run. Does NOT modify production by itself.
-- ============================================================

-- ── 1. The change feed ──────────────────────────────────────
-- `revision` is the cursor. AUTO_INCREMENT gives a server-generated,
-- monotonic ordering that never depends on a device clock.
CREATE TABLE IF NOT EXISTS `sync_changes` (
    `revision`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type`  VARCHAR(40)     NOT NULL,
    `entity_id`    BIGINT UNSIGNED NOT NULL,
    `op`           ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    -- Permission scope, denormalised at write time. A tombstone has to
    -- stay filterable after its row is gone, so the scope cannot be
    -- resolved by joining back to the deleted record.
    `scope_class_id` INT UNSIGNED  DEFAULT NULL,
    `scope_year_id`  INT UNSIGNED  DEFAULT NULL,
    `scope_member_id` INT UNSIGNED DEFAULT NULL,
    `changed_at`   TIMESTAMP       NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`revision`),
    -- Incremental pulls read WHERE revision > ? ORDER BY revision.
    KEY `idx_sync_changes_entity` (`entity_type`, `revision`),
    -- Class-scoped feeds (a teacher pulling their own classes).
    KEY `idx_sync_changes_scope_class` (`scope_class_id`, `revision`),
    -- Retention pruning deletes by age.
    KEY `idx_sync_changes_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Retention floor ──────────────────────────────────────
-- The feed is synchronization history, not a business archive, so it is
-- pruned. Once pruned, a client holding an older cursor can no longer be
-- served a complete delta and must bootstrap instead of silently
-- receiving a partial one. `min_valid_revision` records the oldest
-- revision still fully represented; the API compares cursors against it.
CREATE TABLE IF NOT EXISTS `sync_feed_state` (
    `id`                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `min_valid_revision` BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    `pruned_at`          TIMESTAMP        NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_sync_feed_state_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `sync_feed_state` (`id`, `min_valid_revision`) VALUES (1, 0);

-- ── 3. Attendance triggers (first representative domain) ────
-- Re-created deterministically so a re-run leaves exactly one trigger
-- with the reviewed body, matching the convention in 048.
-- These only append to the feed; they never alter attendance itself, so
-- they cannot change the outcome of an attendance write or interfere
-- with the existing transaction and roster guards.

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_attendance_sync_ai` $$
CREATE TRIGGER `trg_attendance_sync_ai`
AFTER INSERT ON `attendance`
FOR EACH ROW
BEGIN
    INSERT INTO `sync_changes`
        (`entity_type`, `entity_id`, `op`, `scope_class_id`, `scope_year_id`, `scope_member_id`)
    VALUES
        ('attendance', NEW.`id`, 'INSERT', NEW.`class_id`, NEW.`academic_year_id`, NEW.`member_id`);
END $$

DROP TRIGGER IF EXISTS `trg_attendance_sync_au` $$
CREATE TRIGGER `trg_attendance_sync_au`
AFTER UPDATE ON `attendance`
FOR EACH ROW
BEGIN
    -- Only record a change when something a device mirrors actually
    -- moved. A no-op UPDATE must not burn a revision, or every save of
    -- an unchanged sheet would hand every device pointless work.
    IF NOT (OLD.`status`          <=> NEW.`status`)
       OR NOT (OLD.`member_id`        <=> NEW.`member_id`)
       OR NOT (OLD.`class_id`         <=> NEW.`class_id`)
       OR NOT (OLD.`academic_year_id` <=> NEW.`academic_year_id`)
       OR NOT (OLD.`attendance_date`  <=> NEW.`attendance_date`)
       OR NOT (OLD.`check_in_time`    <=> NEW.`check_in_time`)
       OR NOT (OLD.`check_out_time`   <=> NEW.`check_out_time`)
       OR NOT (OLD.`notes`            <=> NEW.`notes`) THEN
        INSERT INTO `sync_changes`
            (`entity_type`, `entity_id`, `op`, `scope_class_id`, `scope_year_id`, `scope_member_id`)
        VALUES
            ('attendance', NEW.`id`, 'UPDATE', NEW.`class_id`, NEW.`academic_year_id`, NEW.`member_id`);
    END IF;
END $$

DROP TRIGGER IF EXISTS `trg_attendance_sync_ad` $$
CREATE TRIGGER `trg_attendance_sync_ad`
AFTER DELETE ON `attendance`
FOR EACH ROW
BEGIN
    -- The tombstone. Scope is copied from the row being removed because
    -- after this statement there is nothing left to join to.
    INSERT INTO `sync_changes`
        (`entity_type`, `entity_id`, `op`, `scope_class_id`, `scope_year_id`, `scope_member_id`)
    VALUES
        ('attendance', OLD.`id`, 'DELETE', OLD.`class_id`, OLD.`academic_year_id`, OLD.`member_id`);
END $$

DELIMITER ;

-- ── 4. Verification ─────────────────────────────────────────
SELECT CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME IN ('sync_changes','sync_feed_state')) = 2
     AND (SELECT COUNT(*) FROM information_schema.TRIGGERS
           WHERE TRIGGER_SCHEMA = DATABASE()
             AND TRIGGER_NAME IN ('trg_attendance_sync_ai',
                                  'trg_attendance_sync_au',
                                  'trg_attendance_sync_ad')) = 3
    THEN 'PASS: sync change feed + attendance triggers present.'
    ELSE 'FAIL: sync change feed incomplete.'
END AS result;
