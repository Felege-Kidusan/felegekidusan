-- ============================================================
-- 064: Failure Intelligence (idempotent / re-runnable)
-- ============================================================
-- One issue registry for all three failure channels (sync attempts,
-- server errors, crashes), a seeded remediation catalog ("how to fix
-- it" attached to every known failure kind), and durable recorded
-- failure reports.
--
-- Design rules (consistent with 060/062/063):
--   * failure_issues stores only what an upsert maintains correctly:
--     identity, title, first/last seen, lifetime occurrences, status
--     workflow, regression count, admin remediation override. Window
--     counts, affected users/installs and distributions are computed
--     at read time from the raw tables, which stay the source of truth.
--   * the catalog is seed-owned: (source, category) rows refreshed by
--     this migration; admin edits live per-issue (override columns).
--   * failure_reports is deliberately exempt from raw-event retention:
--     it is the durable record of what happened, sized in kilobytes.

CREATE TABLE IF NOT EXISTS `failure_issues` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_key` VARCHAR(64) NOT NULL,
  `source` ENUM('sync', 'server_error', 'crash') NOT NULL,
  `category` VARCHAR(120) NOT NULL DEFAULT '',
  `title` VARCHAR(255) NOT NULL,
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `total_occurrences` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('open', 'acknowledged', 'resolved') NOT NULL DEFAULT 'open',
  `resolved_at` DATETIME NULL,
  `resolved_note` VARCHAR(500) NULL,
  `resolved_by` VARCHAR(100) NULL,
  `regression_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `remediation_cause` TEXT NULL,
  `remediation_steps` TEXT NULL,
  `remediation_link` VARCHAR(255) NULL,
  `last_auto_report_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_failure_issues_key` (`issue_key`),
  INDEX `idx_failure_issues_source_status` (`source`, `status`, `last_seen_at`),
  INDEX `idx_failure_issues_last_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failure_remediation_catalog` (
  `source` ENUM('sync', 'server_error', 'crash') NOT NULL,
  `category` VARCHAR(120) NOT NULL,
  `cause` VARCHAR(500) NOT NULL,
  `fix_steps` TEXT NOT NULL,
  `doc_link` VARCHAR(255) NULL,
  PRIMARY KEY (`source`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failure_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_id` BIGINT UNSIGNED NOT NULL,
  `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
  `window_start` DATETIME NOT NULL,
  `window_end` DATETIME NOT NULL,
  `trigger` ENUM('threshold', 'manual', 'reconcile') NOT NULL,
  `report_markdown` MEDIUMTEXT NOT NULL,
  `generated_by` VARCHAR(100) NOT NULL DEFAULT 'system',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_failure_reports_issue` (`issue_id`, `created_at`),
  INDEX `idx_failure_reports_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────────
-- Remediation catalog seeds — the client's SyncErrorCategory taxonomy
-- (Mobile/.../sync_attempt_models.dart, 22 failure shapes) and the server
-- error families (monitor/error_monitor.php). Refreshed on re-run; admin
-- customisations belong on failure_issues (override columns), not here.
-- ────────────────────────────────────────────────────────────────────────────

INSERT INTO `failure_remediation_catalog` (`source`, `category`, `cause`, `fix_steps`, `doc_link`) VALUES
('sync', 'NETWORK_UNAVAILABLE', 'The device had no usable network route when the attempt was scheduled.', '1. Confirm the device shows connectivity in the Diagnostics screen.\n2. If mobile data is off by policy, enable it or wait for Wi-Fi.\n3. The outbox retries automatically with backoff — no data is lost.', NULL),
('sync', 'TIMEOUT', 'The server accepted the connection but did not answer within the client timeout budget.', '1. Check Site Health uptime and response time for the same window.\n2. A slow query or a busy shared host is the usual cause — check /monitor/ for slow PHP execution times.\n3. If it persists, raise the attempt timeout after reviewing the sync docs.', NULL),
('sync', 'DNS_FAILURE', 'The device could not resolve the server hostname.', '1. Verify the server domain resolves publicly (dns lookup from another network).\n2. A local ISP DNS hiccup usually self-heals; the retry ladder will confirm.\n3. If fleet-wide, check the domain/DNS provider.', NULL),
('sync', 'TLS_FAILURE', 'The TLS handshake failed (expired certificate, broken chain, or interception).', '1. Check the site certificate expiry and chain (any browser padlock).\n2. Renew the certificate if expired.\n3. Devices on intercepted networks (proxies) may need the network fixed instead.', NULL),
('sync', 'AUTH_EXPIRED', 'The login token expired and the refresh flow did not produce a working credential.', '1. Ask the user to log out and back in.\n2. If fleet-wide, check the JWT secret and token lifetime settings.\n3. A single device usually just needs the re-login.', NULL),
('sync', 'AUTH_SCOPE_CHANGED', 'The account''s roles changed, so pending work is no longer authorized.', '1. Confirm the member''s current roles with the school admin.\n2. Either restore the needed role or reassign the pending work.\n3. The device stops sending until the credential is renewed.', NULL),
('sync', 'HTTP_403', 'The server refused the operation for this account (permission denied).', '1. Check the account''s role and the route''s access rules.\n2. If the role is correct, check access_control.php for a recent change.\n3. Pending items must be reassigned or the role corrected.', NULL),
('sync', 'VALIDATION_ERROR', 'The payload failed server-side validation.', '1. Open the attempt detail and read the safe error code.\n2. Usually a stale local row (an old revision of the record) — pull fresh data and re-enter.\n3. If reproducible, capture the request id and check server logs.', NULL),
('sync', 'HTTP_404', 'The endpoint or entity was not found on the server.', '1. Confirm the server version supports this endpoint (app newer than server).\n2. If the entity was deleted server-side, discard the local pending change.\n3. Align app and server versions.', NULL),
('sync', 'PAYLOAD_REJECTED', 'The server rejected the payload structure itself (size or shape).', '1. Check whether the batch exceeds the server size cap.\n2. Update the app — newer builds split oversized batches.\n3. Inspect the safe error code in the attempt detail.', NULL),
('sync', 'IDEMPOTENCY_CONFLICT', 'The same idempotency key was sent with a different payload — the operation is unrepairable as transmitted.', '1. This needs a replacement operation: the client creates a new one from current local state.\n2. Update the app if this repeats — it indicates duplicate outbox rows.\n3. No server action is required.', NULL),
('sync', 'IDEMPOTENCY_IN_PROGRESS', 'An earlier attempt of the same operation still holds the server lease.', '1. No action — this resolves itself when the earlier attempt finishes.\n2. If it persists for hours, check for stuck in-flight rows in the sync monitor.', NULL),
('sync', 'WORKFLOW_REJECTED', 'Domain workflow refused the change (for example, already submitted).', '1. Compare with the server''s current state — the record usually moved on.\n2. Refresh local data; discard the stale pending change if it duplicates what the server already has.', NULL),
('sync', 'REVISION_CONFLICT', 'The server holds a newer revision of the record.', '1. The device must reconcile to the server''s canonical copy (attached to the response).\n2. The recovery center handles this automatically in current builds — update the app if it repeats.', NULL),
('sync', 'HTTP_429', 'The account or route hit a server rate limit.', '1. Backoff is automatic; persistent 429s mean the fleet is exceeding limits.\n2. Check rate-limit settings for the route family.\n3. Stagger background sync schedules if many devices share an account.', NULL),
('sync', 'SERVER_ERROR', 'The server returned an unexpected 5xx.', '1. Open /monitor/ for the same window — a PHP error with a matching request id is the cause.\n2. Fix the underlying server error; the client retries automatically while it persists.', NULL),
('sync', 'SERVER_ERROR_REPLAYED', 'A pinned 5xx answer: the stored failure is replayed to every retry of this operation id.', '1. The operation cannot progress under this id — it needs a replacement operation.\n2. Fix the underlying server error first, then update the app so the replacement is created.', NULL),
('sync', 'SERVICE_UNAVAILABLE', 'The server reported itself unavailable (maintenance or overload).', '1. Check uptime in Site Health for the same window.\n2. If it recurs daily at the same time, a host backup/maintenance window is the cause.', NULL),
('sync', 'LOCAL_DB_ERROR', 'The device''s local SQLite store failed while preparing or applying the operation.', '1. Open the in-app Diagnostics screen and copy the report.\n2. Free device storage if it is full.\n3. If corruption is reported, follow the recovery flow before more syncing.', NULL),
('sync', 'SERIALIZATION_ERROR', 'The operation failed to encode/decode locally.', '1. Update the app — this indicates a version-specific codec bug.\n2. The pending row stays in the outbox; it will send once the build is fixed.', NULL),
('sync', 'PROTOCOL_ERROR', 'The client and server disagreed on the protocol.', '1. Confirm the app build is compatible with the server version.\n2. Update whichever side is stale.', NULL),
('sync', 'UNKNOWN', 'The failure could not be classified.', '1. Open the attempt detail for the request id and HTTP status.\n2. Cross-check /monitor/ and server logs for the same timestamp.\n3. Report the request id if it recurs.', NULL),
('server_error', 'Fatal Error', 'A PHP fatal error stopped the request.', '1. Open /monitor/, find the entry, and read the stack trace (file and line are recorded).\n2. Fix the code at that location.\n3. Check whether the same file:line is a recurring issue in the Failure Intelligence list.', NULL),
('server_error', 'Parse Error', 'PHP could not parse a source file — usually a syntax error in a recent edit.', '1. Run php -l on the file shown in /monitor/.\n2. Restore or fix the broken edit immediately; every request touching the file fails.', NULL),
('server_error', 'Warning', 'A PHP warning (deprecated call, undefined index, …) — not fatal, but a defect signal.', '1. Open /monitor/ and read the message and location.\n2. Fix the underlying notice; warnings pollute logs and can hide real errors.', NULL),
('server_error', 'Uncaught Exception', 'An exception escaped without a handler.', '1. Open /monitor/ and read the exception class, message, and trace.\n2. Add handling or fix the throwing code path.', NULL),
('server_error', 'FATAL', 'The shutdown handler caught a fatal condition.', '1. Open /monitor/ — the entry records the error type, file, and line.\n2. Fix the cause; the same signature will group here until it stops.', NULL),
('server_error', 'Custom Log', 'An explicit application log entry (ArkeonErrorMonitor::log).', '1. Read the message in /monitor/ — these are deliberate diagnostic entries.\n2. Address the condition the code was reporting.', NULL)
ON DUPLICATE KEY UPDATE
  `cause` = VALUES(`cause`),
  `fix_steps` = VALUES(`fix_steps`),
  `doc_link` = VALUES(`doc_link`);
