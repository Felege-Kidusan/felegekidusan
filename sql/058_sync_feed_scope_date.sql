-- ============================================================
-- 058 — Carry the attendance date on feed entries
-- ============================================================
-- Compatibility defect found while building the Flutter consumer
-- (PHASE B), not a redesign of the PHASE A feed.
--
-- The mobile cache (`cached_attendance`) is not one row per attendance
-- record. It stores a whole class/day sheet blob keyed by
-- (class_id, date), and the sheet's student entries are identified by
-- member_id. The attendance row's own `id` appears nowhere on the
-- device, because GET /attendance returns roster entries
-- (apiRosterStudentRow) which carry member_id, not attendance.id.
--
-- So a device receiving a tombstone that says only "attendance 2660 was
-- deleted" cannot act: it has never seen that id and cannot tell which
-- cached sheet to correct. INSERT and UPDATE are fine, because the
-- canonical record in the response carries class_id and attendance_date
-- — but a deleted row has no canonical record left to read them from.
--
-- `scope_class_id` and `scope_member_id` are already denormalised onto
-- the feed for exactly this reason. The date was the missing third key.
-- Adding it keeps tombstones self-describing, which is the whole point
-- of denormalising scope at write time.
--
-- Additive and idempotent. Older clients ignore the new field, so no
-- app version is forced. No new foreign keys.
-- ============================================================

-- ── 1. The column ───────────────────────────────────────────
-- Guarded so a re-run is a no-op (MariaDB has no portable
-- ADD COLUMN IF NOT EXISTS across the versions in play).
SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'sync_changes'
       AND COLUMN_NAME = 'scope_date'
);

SET @ddl := IF(
    @column_exists = 0,
    'ALTER TABLE `sync_changes` ADD COLUMN `scope_date` DATE DEFAULT NULL AFTER `scope_member_id`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 2. Triggers recreated to populate it ────────────────────
-- Same bodies as 057 plus the date. Dropped and recreated so a re-run
-- leaves exactly one trigger with the reviewed body, per the convention
-- established in 048.

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_attendance_sync_ai` $$
CREATE TRIGGER `trg_attendance_sync_ai`
AFTER INSERT ON `attendance`
FOR EACH ROW
BEGIN
    INSERT INTO `sync_changes`
        (`entity_type`, `entity_id`, `op`, `scope_class_id`, `scope_year_id`,
         `scope_member_id`, `scope_date`)
    VALUES
        ('attendance', NEW.`id`, 'INSERT', NEW.`class_id`, NEW.`academic_year_id`,
         NEW.`member_id`, NEW.`attendance_date`);
END $$

DROP TRIGGER IF EXISTS `trg_attendance_sync_au` $$
CREATE TRIGGER `trg_attendance_sync_au`
AFTER UPDATE ON `attendance`
FOR EACH ROW
BEGIN
    -- A no-op UPDATE must still burn no revision.
    IF NOT (OLD.`status`              <=> NEW.`status`)
       OR NOT (OLD.`member_id`        <=> NEW.`member_id`)
       OR NOT (OLD.`class_id`         <=> NEW.`class_id`)
       OR NOT (OLD.`academic_year_id` <=> NEW.`academic_year_id`)
       OR NOT (OLD.`attendance_date`  <=> NEW.`attendance_date`)
       OR NOT (OLD.`check_in_time`    <=> NEW.`check_in_time`)
       OR NOT (OLD.`check_out_time`   <=> NEW.`check_out_time`)
       OR NOT (OLD.`notes`            <=> NEW.`notes`) THEN
        -- A move between classes or dates leaves a stale entry on the
        -- old sheet, so the device is told about the old location too.
        IF NOT (OLD.`class_id` <=> NEW.`class_id`)
           OR NOT (OLD.`attendance_date` <=> NEW.`attendance_date`)
           OR NOT (OLD.`member_id` <=> NEW.`member_id`) THEN
            INSERT INTO `sync_changes`
                (`entity_type`, `entity_id`, `op`, `scope_class_id`, `scope_year_id`,
                 `scope_member_id`, `scope_date`)
            VALUES
                ('attendance', OLD.`id`, 'DELETE', OLD.`class_id`, OLD.`academic_year_id`,
                 OLD.`member_id`, OLD.`attendance_date`);
        END IF;
        INSERT INTO `sync_changes`
            (`entity_type`, `entity_id`, `op`, `scope_class_id`, `scope_year_id`,
             `scope_member_id`, `scope_date`)
        VALUES
            ('attendance', NEW.`id`, 'UPDATE', NEW.`class_id`, NEW.`academic_year_id`,
             NEW.`member_id`, NEW.`attendance_date`);
    END IF;
END $$

DROP TRIGGER IF EXISTS `trg_attendance_sync_ad` $$
CREATE TRIGGER `trg_attendance_sync_ad`
AFTER DELETE ON `attendance`
FOR EACH ROW
BEGIN
    INSERT INTO `sync_changes`
        (`entity_type`, `entity_id`, `op`, `scope_class_id`, `scope_year_id`,
         `scope_member_id`, `scope_date`)
    VALUES
        ('attendance', OLD.`id`, 'DELETE', OLD.`class_id`, OLD.`academic_year_id`,
         OLD.`member_id`, OLD.`attendance_date`);
END $$

DELIMITER ;

-- ── 3. Verification ─────────────────────────────────────────
SELECT CASE
    WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'sync_changes'
             AND COLUMN_NAME = 'scope_date') = 1
     AND (SELECT COUNT(*) FROM information_schema.TRIGGERS
           WHERE TRIGGER_SCHEMA = DATABASE()
             AND TRIGGER_NAME IN ('trg_attendance_sync_ai',
                                  'trg_attendance_sync_au',
                                  'trg_attendance_sync_ad')) = 3
    THEN 'PASS: feed entries carry the attendance date.'
    ELSE 'FAIL: scope_date rollout incomplete.'
END AS result;
