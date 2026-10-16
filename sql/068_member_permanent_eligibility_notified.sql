-- ============================================================================
-- 068 — Permanent-eligibility notification state for temporary members
-- ============================================================================
-- Tracks whether a temporary member's 6-month eligibility has already been
-- notified to HR, so the cron detector never spams the same member twice.
-- ============================================================================

ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `permanent_eligibility_notified_at` DATETIME NULL DEFAULT NULL
    AFTER `membership_tier`;

CREATE INDEX IF NOT EXISTS `idx_members_tier_status_registered`
    ON `members` (`membership_tier`, `status`, `registered_at`);
