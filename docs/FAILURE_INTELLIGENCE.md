# Failure Intelligence — Architecture & Contract

## What this is

One issue registry for every failure the system can observe, a seeded
remediation catalog, and durable recorded reports — surfaced in the
Super Admin dashboard under **Failure Intelligence**. It answers, per
issue: **what** fails, **why** it fails, **when** it fails, **where** it
fails, and **how to fix it**.

Three channels, three fingerprints (all deterministic and low-cardinality):

| Channel | Raw table (source of truth) | Fingerprint |
|---|---|---|
| Sync attempt failures | `api_sync_attempts` (`failed`/`rejected`) | `md5(category \| code \| domain \| operation)` |
| Server errors | `arkeon_error_log` (monitor) | `md5(error_type \| file_path \| line_number)` |
| App crashes | `app_telemetry_events` (`crash`/`crash_recorded`) | the SHA-256 crash key itself |

## Components (migration 064)

- **`failure_issues`** — one row per fingerprint: source, category, title,
  first/last seen, lifetime occurrences, status workflow
  (`open → acknowledged → resolved`, reopen on recurrence = regression,
  Sentry semantics), admin remediation override, `last_auto_report_at`.
- **`failure_remediation_catalog`** — seed-owned `(source, category)` rows
  (the client's 22 `SyncErrorCategory` failure shapes + the server error
  families) with cause and fix steps. Refreshed by re-running migration
  064; admin customisations belong on the issue (override columns win).
- **`failure_reports`** — recorded markdown reports (what/why/when/where/
  how-to-fix + correlated request ids + resolution), surviving raw-data
  retention.

## Ingest (write path)

Every hook is **advisory and never-throw**: a failure of failure
intelligence must never alter the host write.

- Sync: `ApiSyncAttemptMonitorService` — direct failed/rejected inserts,
  and both completion paths (`complete()`, `completeWithinTransaction()`),
  guarded by the UPDATE's affected-rows so a row is never counted twice.
- Server: `monitor/error_monitor.php saveError()` — after the arkeon row
  is persisted.
- Crash: `api/v1/routes/telemetry.php` — alongside the crash-count and
  signature upserts (migration 063).

## Aggregation model (read path)

`failure_issues` stores only what an upsert maintains correctly. Window
counts (24h/7d/30d), affected users/installs, per-build and per-device
distributions, and 14-day timelines are computed at read time from the
raw tables by `FailureIssueService`. The nightly job
(`admin/backend/failure_issue_reconcile.php`, CLI-only, locked,
fail-closed, aggregate-only output) recomputes lifetime aggregates as
self-healing and generates threshold reports: **≥ 5 affected installs/users
or ≥ 50 occurrences in 24h → one recorded report per issue per 24h**
(user-approved defaults).

## Admin surface

- Endpoint: `admin/api_failure_intelligence.php` — **super_admin only**
  (fleet-wide scope), session auth, CSRF (`validateCsrf`) on every POST.
  GET: `get_failure_overview`, `get_failure_issues`, `get_failure_issue`,
  `get_failure_reports`, `get_failure_report`. POST:
  `update_failure_issue` (status transitions, resolution note, remediation
  override), `generate_failure_report` (on demand).
  The endpoint writes **only** `failure_issues`/`failure_reports`.
- Dashboard: `sections/failure_intelligence_section.php` +
  `admin/js/failure_intelligence.js` — pure client-side shell (zero
  server-side queries until the section is opened), golden-signal KPIs
  (sync failure rate, p95 duration, attempts/min, crash-free installs,
  open issues), impact-ranked issue list, issue detail with timeline /
  distributions / correlated request ids / remediation editing, and the
  reports archive with rendered reports.

## Privacy

No new data leaves devices. The issue registry groups data the server
already records; crash issues carry only the bounded signature from
migration 063 (exception class + ≤ 3 first-party frame names). Server
error samples shown in the dashboard were already redacted at capture by
the monitor. The admin API is read-mostly and double-gated (session +
super_admin role + access_control ROLE_MAP).

## Deploy order

1. Apply `sql/062`, `sql/063`, `sql/064` (all repeat-safe).
2. Deploy the server code.
3. Install the nightly cron after the existing retention job:
   `47 3 * * * php .../admin/backend/failure_issue_reconcile.php`
4. Ship the app release (carries `X-Installation-Id` + crash signatures).

Older servers + newer clients, and newer servers + older clients, remain
compatible: every hook tolerates a missing table (issue bookkeeping is
skipped, raw telemetry unaffected), and clients simply send headers the
old server ignores.
