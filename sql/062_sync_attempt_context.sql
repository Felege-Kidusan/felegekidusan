-- ============================================================
-- 062: Sync attempt fleet context (idempotent / re-runnable)
-- ============================================================
-- The mobile client already sends X-App-Version / X-App-Build on every
-- request and begins sending X-Installation-Id on authenticated sync
-- writes. Recording them per attempt makes failures sliceable by build
-- (per-build regression detection) and joinable to the device directory
-- the telemetry channel already maintains.
--
-- All three columns are NULLable: old clients and non-mobile callers
-- remain valid, and no API contract changes — the headers are read and
-- validated, never required. Absent or invalid header = NULL = "not
-- observed", the same semantics the ledger already uses for
-- attempt_number / execution_source.

ALTER TABLE `api_sync_attempts`
  ADD COLUMN IF NOT EXISTS `app_version` VARCHAR(32) NULL
    AFTER `http_status`;

ALTER TABLE `api_sync_attempts`
  ADD COLUMN IF NOT EXISTS `app_build` INT UNSIGNED NULL
    AFTER `app_version`;

ALTER TABLE `api_sync_attempts`
  ADD COLUMN IF NOT EXISTS `installation_id` VARCHAR(64) NULL
    AFTER `app_build`;

ALTER TABLE `api_sync_attempts`
  ADD INDEX IF NOT EXISTS `idx_sync_attempts_build_started`
    (`app_build`, `started_at`, `id`);

ALTER TABLE `api_sync_attempts`
  ADD INDEX IF NOT EXISTS `idx_sync_attempts_install_started`
    (`installation_id`, `started_at`, `id`);
