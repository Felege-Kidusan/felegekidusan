-- ============================================================
-- 041_section_age_source_of_truth.sql   (IDEMPOTENT / RE-RUNNABLE)
-- P71 — Section / Age Group single source of truth.
--
-- The definition lives in App\Services\MemberCategory (PHP):
--     7_13    = A = ህጻናት     (7–13)
--     14_17   = B = ማዕከላዊያን  (14–17)
--     18_plus = C = ወጣቶች     (18+)
-- Migration 002's class seed already used exactly these section
-- names; this migration makes the STORED DATA match that definition:
--
--   1. classes.section / members.current_section: legacy variant
--      spellings (ልጆች, ማእከላዊ, ሰበከላ — the education modal's old
--      hardcoded list, ሰበከላ being a mistranslation of ወጣቶች — and
--      English names) normalize onto the canonical Amharic names.
--   2. classes: section and age_group are made a consistent pair in
--      both directions (age_group is authoritative when both exist).
--   3. members.age_group: '18+' stragglers → '18_plus' (no-op on
--      ENUM deployments where '18+' could never be stored).
--   4. mezmur_categories: the 025 seed inserted corrupted section
--      names (ህናት / ማዕከዊያን / ጣቶች) — fixed to the canonical spellings.
--      Renames happen by unique name; category ids and every hymn
--      assignment are preserved.
--
-- Values that match NO known variant are intentionally LEFT
-- UNTOUCHED (review them manually — nothing is destroyed here).
-- Every statement is a plain value-filtered UPDATE: re-running this
-- file is always safe.
-- ============================================================

-- ── 1. classes.section → canonical names ─────────────────────
UPDATE `classes` SET `section` = 'ህጻናት'
 WHERE `section` IN ('ልጆች', 'ልጆች (Children)', 'Children', 'children');
UPDATE `classes` SET `section` = 'ማዕከላዊያን'
 WHERE `section` IN ('ማእከላዊ', 'ማእከላዊ (Middle)', 'Middle', 'middle');
UPDATE `classes` SET `section` = 'ወጣቶች'
 WHERE `section` IN ('ሰበከላ', 'ሰበከላ (Parish)', 'Parish', 'parish', 'ወጣቶ', 'Youth', 'youth');
UPDATE `classes` SET `section` = TRIM(`section`) WHERE `section` IS NOT NULL;

-- age_group is authoritative (ENUM-validated, identity-linked):
-- derive the section name from it wherever an age_group exists.
UPDATE `classes` SET `section` = 'ህጻናት'     WHERE `age_group` = '7_13';
UPDATE `classes` SET `section` = 'ማዕከላዊያን'  WHERE `age_group` = '14_17';
UPDATE `classes` SET `section` = 'ወጣቶች'     WHERE `age_group` = '18_plus';

-- Fill a missing age_group from the (now canonical) section name.
UPDATE `classes` SET `age_group` = '7_13'
 WHERE (`age_group` IS NULL OR `age_group` = '') AND `section` = 'ህጻናት';
UPDATE `classes` SET `age_group` = '14_17'
 WHERE (`age_group` IS NULL OR `age_group` = '') AND `section` = 'ማዕከላዊያን';
UPDATE `classes` SET `age_group` = '18_plus'
 WHERE (`age_group` IS NULL OR `age_group` = '') AND `section` = 'ወጣቶች';

-- ── 2. members.current_section → canonical names ─────────────
UPDATE `members` SET `current_section` = 'ህጻናት'
 WHERE `current_section` IN ('ልጆች', 'ልጆች (Children)', 'Children', 'children');
UPDATE `members` SET `current_section` = 'ማዕከላዊያን'
 WHERE `current_section` IN ('ማእከላዊ', 'ማእከላዊ (Middle)', 'Middle', 'middle');
UPDATE `members` SET `current_section` = 'ወጣቶች'
 WHERE `current_section` IN ('ሰበከላ', 'ሰበከላ (Parish)', 'Parish', 'parish', 'ወጣቶ', 'Youth', 'youth');
UPDATE `members` SET `current_section` = TRIM(`current_section`)
 WHERE `current_section` IS NOT NULL;

-- ── 3. members.age_group stragglers ──────────────────────────
-- (no-op on ENUM deployments — the WHERE simply matches nothing)
UPDATE `members` SET `age_group` = '18_plus' WHERE `age_group` = '18+';

-- ── 4. mezmur_categories seed spellings (guarded) ─────────────
-- 025 seeded 'ህናት' / 'ማዕከዊያን' / 'ጣቶች' — corrupted spellings of the
-- three official section names. Renaming keeps ids (and every hymn
-- assignment) intact; guarded for deployments without the table.
SET @mz_fix1 := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'mezmur_categories'),
        'UPDATE `mezmur_categories` SET `name` = ''ህጻናት'' WHERE `name` = ''ህናት''',
        'SELECT 1'
    )
);
PREPARE mz_stmt FROM @mz_fix1; EXECUTE mz_stmt; DEALLOCATE PREPARE mz_stmt;

SET @mz_fix2 := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'mezmur_categories'),
        'UPDATE `mezmur_categories` SET `name` = ''ማዕከላዊያን'' WHERE `name` = ''ማዕከዊያን''',
        'SELECT 1'
    )
);
PREPARE mz_stmt FROM @mz_fix2; EXECUTE mz_stmt; DEALLOCATE PREPARE mz_stmt;

SET @mz_fix3 := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'mezmur_categories'),
        'UPDATE `mezmur_categories` SET `name` = ''ወጣቶች'' WHERE `name` = ''ጣቶች''',
        'SELECT 1'
    )
);
PREPARE mz_stmt FROM @mz_fix3; EXECUTE mz_stmt; DEALLOCATE PREPARE mz_stmt;
