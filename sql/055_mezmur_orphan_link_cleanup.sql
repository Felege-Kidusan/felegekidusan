-- ============================================================================
-- 055 — mezmur link-table orphan cleanup  (audit finding R-2, 2026-10-02)
-- MariaDB 10.6+.  DATA ONLY: this migration creates no column, drops no
-- column, and alters no existing table definition.
-- ----------------------------------------------------------------------------
-- WHY THIS EXISTS
-- The production schema declares 42 foreign keys. Three of them cannot be
-- created because data violates them, so a restore of the production export
-- lands 39 of 42 and the live database runs without three constraints its own
-- schema claims to enforce:
--
--     fk_mhc_hymn      mezmur_hymn_categories.hymn_id     -> mezmur_hymns.id
--     fk_mhc_category  mezmur_hymn_categories.category_id -> mezmur_categories.id
--     fk_mhz_hymn      mezmur_hymn_zemarians.hymn_id      -> mezmur_hymns.id
--
-- The cause is a taxonomy re-seed (sql/030, sql/034) in which old hymns and
-- old categories were replaced while the junction rows pointing at them were
-- never cleaned up. The declared constraints are ON DELETE CASCADE; had they
-- been enforced at the time, those rows would have been removed automatically.
-- Their survival is itself evidence the constraints were never active.
--
-- 102 junction rows are affected. They fall into two groups, handled
-- differently and deliberately:
--
--   2 rows   belong to hymns that are STILL ACTIVE (68, 73) whose only
--            category assignment points at a category that no longer exists.
--            Deleting these would silently remove two live hymns from
--            category browsing, so they are REASSIGNED, not removed.
--
--   100 rows reference a hymn that no longer exists anywhere in the database
--            (68 distinct ids: 2, 3, 6-67, 70, 71, 72, 76). These tables are
--            pure junction tables — the primary key IS the foreign-key pair,
--            with no timestamp, author, note or ordering column — so such a
--            row carries exactly one assertion ("hymn X is in category Y")
--            about a hymn that is gone without trace. mezmur_hymn_words,
--            mezmur_play_stats and mezmur_user_favorites are all empty, so
--            nothing else references them either. They are REMOVED.
--
-- EVIDENCE FOR THE TWO REASSIGNMENTS
-- mezmur_hymns carries a legacy denormalised `category` TEXT column holding
-- the category NAME on the hymn row itself. Because the dangling categories
-- (30, 32) are gone and no archive of their names exists, that column is the
-- only surviving record of the intended categorisation.
--
--   hymn 68  የራማው ልዑል        legacy category = 'የገብርኤል መዝሙራት'
--            Exactly one valid category carries that name: 85. The lyrics
--            name ገብርኤል in the opening line, call him 'ቅዱስ ገብርኤል ጠባቂያችን',
--            reference the Annunciation and the Daniel 3 deliverance.
--            Precedent: hymn 78 (ገብርኤል ኃያል) has the identical legacy string
--            and is already assigned to 85.   ->  32 becomes 85
--
--   hymn 73  የሚጠብቀኝ አይተኛም     legacy category = 'አጠቃላይ'
--            Twelve valid categories share that name, one per parent, so the
--            name alone does not decide it. The lyrics are Psalm 121
--            ('neither slumbers nor sleeps', 'the sun shall not strike by
--            day') and address ጌታዬ directly, with no Marian, angelic or
--            saintly reference. Category 116 is the 'አጠቃላይ' bucket under
--            የጌታ ዝማሬዎች and is the only one of the twelve in active use —
--            six of the nine surviving valid assignments point there, all
--            Lord/Christ-themed.           ->  30 becomes 116
--
-- REVERSIBILITY
-- Every affected row is copied into migration_055_mezmur_link_quarantine
-- BEFORE it is changed, recording what was done and why. Nothing is
-- discarded. The rollback statements are at the foot of this file.
--
-- This migration is idempotent: re-running it changes nothing and re-reports
-- the same quarantine contents.
--
-- WHAT THIS MIGRATION DOES NOT DO
-- It does not add the three foreign keys. Adding constraints is a schema
-- change and is kept as a separate, explicit decision; this file only removes
-- the reason they cannot be added. After it runs, all 42 constraints validate
-- (verified against a disposable restore of the production export).
-- ============================================================================

-- ── 0. Preconditions ────────────────────────────────────────────────────────
-- Fail closed if the taxonomy is not the one this migration was written
-- against. Reassigning to a category that does not exist, or that has been
-- retired, would be worse than doing nothing.
SET @cat_85  := (SELECT COUNT(*) FROM `mezmur_categories` WHERE `id` = 85  AND `is_active` = 1);
SET @cat_116 := (SELECT COUNT(*) FROM `mezmur_categories` WHERE `id` = 116 AND `is_active` = 1);

SET @ssms_guard := IF(@cat_85 = 1 AND @cat_116 = 1,
    "SELECT 'preconditions OK: categories 85 and 116 exist and are active' AS precheck",
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 055 ABORTED: category 85 and/or 116 is missing or inactive. This migration reassigns two live hymns into those categories; it will not guess a substitute. Re-check the taxonomy before running.'"
);
PREPARE s FROM @ssms_guard; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 1. Quarantine table (the reversible backup) ─────────────────────────────
-- Follows the convention established by migration 013, which moved displaced
-- duplicates into migration_013_*_conflicts rather than discarding them.
CREATE TABLE IF NOT EXISTS `migration_055_mezmur_link_quarantine` (
    `source_table`   VARCHAR(40)      NOT NULL,
    `hymn_id`        BIGINT UNSIGNED  NOT NULL,
    `ref_id`         INT UNSIGNED     NOT NULL COMMENT 'category_id or zemarian_id as held before the change',
    `action`         VARCHAR(12)      NOT NULL COMMENT 'reassigned | removed',
    `new_ref_id`     INT UNSIGNED     NULL     COMMENT 'populated for reassigned rows only',
    `reason`         VARCHAR(255)     NOT NULL,
    `quarantined_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`source_table`, `hymn_id`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Quarantine the two reassignments (before changing them) ──────────────
INSERT IGNORE INTO `migration_055_mezmur_link_quarantine`
    (`source_table`, `hymn_id`, `ref_id`, `action`, `new_ref_id`, `reason`)
SELECT 'mezmur_hymn_categories', 68, 32, 'reassigned', 85,
       'live hymn; only assignment pointed at absent category 32; legacy category column reads the name of category 85'
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `mezmur_hymn_categories` WHERE `hymn_id` = 68 AND `category_id` = 32);

INSERT IGNORE INTO `migration_055_mezmur_link_quarantine`
    (`source_table`, `hymn_id`, `ref_id`, `action`, `new_ref_id`, `reason`)
SELECT 'mezmur_hymn_categories', 73, 30, 'reassigned', 116,
       'live hymn; only assignment pointed at absent category 30; lyrics address the Lord and 116 is the active general bucket under the Lord'
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `mezmur_hymn_categories` WHERE `hymn_id` = 73 AND `category_id` = 30);

-- ── 3. Quarantine the rows that will be removed ─────────────────────────────
INSERT IGNORE INTO `migration_055_mezmur_link_quarantine`
    (`source_table`, `hymn_id`, `ref_id`, `action`, `new_ref_id`, `reason`)
SELECT 'mezmur_hymn_categories', c.`hymn_id`, c.`category_id`, 'removed', NULL,
       'referenced hymn no longer exists in mezmur_hymns'
  FROM `mezmur_hymn_categories` c
  LEFT JOIN `mezmur_hymns` h ON c.`hymn_id` = h.`id`
 WHERE h.`id` IS NULL;

INSERT IGNORE INTO `migration_055_mezmur_link_quarantine`
    (`source_table`, `hymn_id`, `ref_id`, `action`, `new_ref_id`, `reason`)
SELECT 'mezmur_hymn_zemarians', z.`hymn_id`, z.`zemarian_id`, 'removed', NULL,
       'referenced hymn no longer exists in mezmur_hymns'
  FROM `mezmur_hymn_zemarians` z
  LEFT JOIN `mezmur_hymns` h ON z.`hymn_id` = h.`id`
 WHERE h.`id` IS NULL;

-- ── 4. Apply the two reassignments ──────────────────────────────────────────
-- Narrowly keyed on the exact (hymn, dangling category) pair so neither
-- statement can touch any other row. Re-running matches nothing.
UPDATE `mezmur_hymn_categories`
   SET `category_id` = 85
 WHERE `hymn_id` = 68 AND `category_id` = 32;

UPDATE `mezmur_hymn_categories`
   SET `category_id` = 116
 WHERE `hymn_id` = 73 AND `category_id` = 30;

-- ── 5. Remove the orphans whose hymn no longer exists ───────────────────────
-- Deliberately keyed on the MISSING HYMN only. Rows whose hymn still exists
-- are never deleted here, so if step 4 were ever skipped the two live-hymn
-- rows would survive rather than be silently discarded.
DELETE c
  FROM `mezmur_hymn_categories` c
  LEFT JOIN `mezmur_hymns` h ON c.`hymn_id` = h.`id`
 WHERE h.`id` IS NULL;

DELETE z
  FROM `mezmur_hymn_zemarians` z
  LEFT JOIN `mezmur_hymns` h ON z.`hymn_id` = h.`id`
 WHERE h.`id` IS NULL;

-- ── 6. Report ───────────────────────────────────────────────────────────────
SELECT `action`, `source_table`, COUNT(*) AS rows_quarantined
  FROM `migration_055_mezmur_link_quarantine`
 GROUP BY `action`, `source_table`
 ORDER BY `action`, `source_table`;

SELECT
    (SELECT COUNT(*) FROM `mezmur_hymn_categories` c
       LEFT JOIN `mezmur_hymns` h ON c.`hymn_id` = h.`id` WHERE h.`id` IS NULL)      AS mhc_orphans_by_hymn,
    (SELECT COUNT(*) FROM `mezmur_hymn_categories` c
       LEFT JOIN `mezmur_categories` p ON c.`category_id` = p.`id` WHERE p.`id` IS NULL) AS mhc_orphans_by_category,
    (SELECT COUNT(*) FROM `mezmur_hymn_zemarians` z
       LEFT JOIN `mezmur_hymns` h ON z.`hymn_id` = h.`id` WHERE h.`id` IS NULL)      AS mhz_orphans_by_hymn;

-- ── 7. Assertion ────────────────────────────────────────────────────────────
-- All three constraints must now be creatable. Exit non-zero if not.
SET @remaining := (
    (SELECT COUNT(*) FROM `mezmur_hymn_categories` c
       LEFT JOIN `mezmur_hymns` h ON c.`hymn_id` = h.`id` WHERE h.`id` IS NULL)
  + (SELECT COUNT(*) FROM `mezmur_hymn_categories` c
       LEFT JOIN `mezmur_categories` p ON c.`category_id` = p.`id` WHERE p.`id` IS NULL)
  + (SELECT COUNT(*) FROM `mezmur_hymn_zemarians` z
       LEFT JOIN `mezmur_hymns` h ON z.`hymn_id` = h.`id` WHERE h.`id` IS NULL)
);
SET @ssms_assert := IF(@remaining = 0,
    "SELECT 'PASS: no orphaned mezmur link rows remain; fk_mhc_hymn, fk_mhc_category and fk_mhz_hymn can now be created.' AS result",
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 055 did not clear every orphan; the three foreign keys still cannot be created. Inspect the counts reported above.'"
);
PREPARE s FROM @ssms_assert; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
-- ROLLBACK
-- Restores every affected row exactly as it was. Run as a single batch.
--
--   UPDATE `mezmur_hymn_categories` c
--     JOIN `migration_055_mezmur_link_quarantine` q
--       ON q.`source_table` = 'mezmur_hymn_categories'
--      AND q.`action`       = 'reassigned'
--      AND c.`hymn_id`      = q.`hymn_id`
--      AND c.`category_id`  = q.`new_ref_id`
--      SET c.`category_id`  = q.`ref_id`;
--
--   INSERT IGNORE INTO `mezmur_hymn_categories` (`hymn_id`, `category_id`)
--   SELECT `hymn_id`, `ref_id` FROM `migration_055_mezmur_link_quarantine`
--    WHERE `source_table` = 'mezmur_hymn_categories' AND `action` = 'removed';
--
--   INSERT IGNORE INTO `mezmur_hymn_zemarians` (`hymn_id`, `zemarian_id`)
--   SELECT `hymn_id`, `ref_id` FROM `migration_055_mezmur_link_quarantine`
--    WHERE `source_table` = 'mezmur_hymn_zemarians' AND `action` = 'removed';
--
-- Note: if the three foreign keys have been created in the meantime, drop
-- them first — the restored rows violate them by definition.
-- ============================================================================
