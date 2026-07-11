-- ============================================================
-- 004_year_lifecycle.sql
-- Academic-year LIFECYCLE STATES (upcoming / active / closed)
-- ============================================================
-- WBSS/FKSS — MariaDB 10.6 (cPanel). Run ONCE, pre-launch, in phpMyAdmin.
-- TAKE A BACKUP FIRST (Super Admin → Backup or admin/tools/backup.php).
--
-- WHY NOW: the system has no real data yet (pre-launch), so adding a state
-- column and normalising the "current year" is completely safe right now —
-- there is nothing to corrupt. This is the ideal moment.
--
-- WHAT THIS DOES:
--   * Adds `status` = the single source of truth for the year lifecycle.
--   * Keeps `is_current` as a DERIVED mirror (= status='active') so any
--     legacy read that still uses is_current keeps working.
--   * Normalises the data so EXACTLY ONE year is 'active'.
--   * Adds a DB-level guarantee that two years can never both be active.
-- ============================================================

-- ── 1. Add the lifecycle column ──
ALTER TABLE `academic_years`
  ADD COLUMN `status` ENUM('upcoming','active','closed')
    NOT NULL DEFAULT 'upcoming' AFTER `is_current`;

-- ── 2. Seed status from the existing is_current flag ──
UPDATE `academic_years` SET `status` = IF(`is_current` = 1, 'active', 'upcoming');

-- ── 3. Normalise to EXACTLY ONE active year ──
-- (a) If two or more rows ended up 'active', keep only the most recent one
--     (highest Ethiopian year, then highest id) and CLOSE the older ones.
UPDATE `academic_years`
SET `status` = 'closed'
WHERE `status` = 'active'
  AND `id` <> (
    SELECT `id` FROM (
      SELECT `id` FROM `academic_years`
      WHERE `status` = 'active'
      ORDER BY COALESCE(`ec_year`, 0) DESC, `id` DESC
      LIMIT 1
    ) AS keep_row
  );

-- (b) If NO row is active (there was no current year), promote the most
--     recent year to active so the system always has exactly one.
UPDATE `academic_years`
SET `status` = 'active'
WHERE `id` = (
    SELECT `id` FROM (
      SELECT `id` FROM `academic_years`
      ORDER BY COALESCE(`ec_year`, 0) DESC, `id` DESC
      LIMIT 1
    ) AS newest_row
  )
  AND NOT EXISTS (
    SELECT 1 FROM (
      SELECT 1 FROM `academic_years` WHERE `status` = 'active' LIMIT 1
    ) AS has_active
  );

-- ── 4. Re-derive is_current from status so the two always agree ──
UPDATE `academic_years` SET `is_current` = IF(`status` = 'active', 1, 0);

-- ── 5. Index for fast status lookups ──
ALTER TABLE `academic_years` ADD INDEX `idx_status` (`status`);

-- ── 6. DB-LEVEL single-active guarantee (best-effort) ──
-- A virtual column that equals 1 only when the row is 'active', plus a UNIQUE
-- index on it. Because a UNIQUE index allows many NULLs but only one non-NULL
-- '1', the database itself now refuses to have two active years at once.
-- Supported on MariaDB 10.3+/MySQL 5.7+. If your version rejects this one
-- statement, you may SKIP it — the application still enforces single-active
-- atomically inside a transaction (see ay_switch_active in
-- admin/backend/academic_year.php). Everything above must still be run.
ALTER TABLE `academic_years`
  ADD COLUMN `active_guard` TINYINT
    GENERATED ALWAYS AS (IF(`status` = 'active', 1, NULL)) VIRTUAL,
  ADD UNIQUE KEY `uk_single_active` (`active_guard`);

-- ============================================================
-- VERIFY after running:
--   SELECT id, year_name, ec_year, is_current, status FROM academic_years;
--   -- exactly one row must show status='active' and is_current=1
-- ============================================================
