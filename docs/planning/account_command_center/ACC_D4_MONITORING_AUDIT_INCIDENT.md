# ACC D4 — Tracking, Monitoring, Audit Review & Incident Response
**Status:** PLANNING v1.0 (2026‑10‑18). No code. Builds on D3 (`acc_events` vocabulary §2.6) and the owner decision log of 2026‑10‑18 (A9, A10, A14).
Marks: **CONFIRMED** / **PROPOSED** / **AWAITING DECISION**.

## 0. Principles (CONFIRMED by A9/A10)
1. Every account‑management action is written to the append‑only `acc_events` chain **before** the response is returned (write‑then‑ack). No action exists without an event.
2. Single‑operator execution is allowed; "sensitive" actions are **flagged** (`review_required=1`) and surface in the review queue — they are not blocked.
3. Two clocks: **continuous alerting** (minutes) for incidents, **monthly review** (by the 7th) for the audit pack. An alert never waits for the review.
4. Zero external infrastructure: logs in MariaDB + rotated files under `/home/<acct>/logs/acc/` (outside web root); dashboards are console pages; alerts go through the existing `FailureAlertService` channel plus in‑console banner. (Shared hosting constraint — CONFIRMED D0.)

## 1. What is tracked (event sources)
| Source | Mechanism | Stored where |
|---|---|---|
| Console actions (`admin/api_acc.php`) | `AccEventWriter::record()` inside the same DB transaction as the change | `acc_events` (hash‑chained) |
| Parent/Member API (`/api/parent/v1`, `/api/member/v1`) | middleware emits `auth.*`, `device.*`, `session.*`, `code.*` events | `acc_events` |
| Token service (`league/oauth2-server`) | custom grant + repositories call the writer on issue/rotate/revoke/replay | `acc_events` |
| Rate limiter hits | `security_rate_limits` (existing table) + `security.ratelimit` event when a limit trips (not per request) | both |
| PHP errors / exceptions in ACC code | monolog `RotatingFileHandler` (14 files) + `reportInternalError()` correlation id in response | file + `acc_events(type=system.error, ref=correlation id)` |
| Jobs (lazy timers: stale devices, code expiry, archive, digest) | `job.*` events with duration & counts | `acc_events` |
| Mobile app diagnostics | reuse existing `app_telemetry_events` pattern? — **PROPOSED: no.** Parent/member apps send only `client.error` (no identifiers) to their own API; privacy by default | `acc_events` minimal |

Field set per event (D3 §2.5): `id, occurred_at(3), actor_type(staff/parent/member/system), actor_id, on_behalf_of (view‑as), account_id, device_id, installation_id, type, outcome(ok/denied/error), reason_code, ip, ip_source(remote/cf), ua_hash, details JSON (allow‑listed keys only, no secrets, no PII beyond ids), review_required, prev_hash, hash`.

**Client IP rule (PROPOSED, needed because production is behind Cloudflare):** `ip = CF-Connecting-IP` only when `REMOTE_ADDR` is inside the published Cloudflare IPv4/IPv6 ranges (list vendored as a PHP array with a dated comment, refreshed on release); otherwise `REMOTE_ADDR`. Record `ip_source`. Existing staff code is **not** touched (A16); the UNKNOWN about server‑level IP restoration is listed in D7 §10.

## 2. Sensitivity classes → review flags (A9)
| Class | Examples | review_required | Extra |
|---|---|---|---|
| S0 routine | lookups, open support case, send notice, activation issue when Family Link exists, device list view | 0 | sampled 2 % into monthly pack |
| S1 sensitive | PIN reset code issue, add‑device code issue, device revoke (lost/stolen), lock/unlock, suspend, close on request, consent re‑send | **1** | daily digest to accounts office lead |
| S2 high | view‑as start, override of Family Link visibility (`acc_link_overrides`), bulk ops (≥10 accounts / ≥20 devices), exports, policy change, closure without request, custody removal | **1** + **immediate notice** to the review role (not approval) | shown on review page within minutes; 24 h review SLA retained from D1 for view‑as |
| S3 exceptional | break‑glass by super_admin, chain verification failure, revoked‑key use, operator ACC‑rights change | **1** + **urgent alert** (§4) | 3‑day review SLA retained |

Which actions still need a **second person before execution** is proposed in D7 §3 (minimal set: granting/removing ACC operator rights — naturally super_admin ≠ operator; bulk S2; exports). **AWAITING DECISION.**

## 3. Dashboards (console pages, no external tools)
1. **Overview** — accounts by state, activations last 7/30 d, active devices, failed PIN rate, open codes, open support cases, chain status (last verified at / OK), job health.
2. **Activity stream** — filterable `acc_events` (type, actor, account, outcome, date), export disabled except via S2 export action.
3. **Review queue** — all `review_required=1` events not yet reviewed, grouped by class; one‑click "reviewed" with optional note → `review.ack` event (reviewer ≠ actor enforced server‑side).
4. **Devices & risk** — devices by security class (hardware‑backed / software‑backed), stale devices, revoked‑key attempts, multi‑account phones.
5. **Alerts** — open/acknowledged alerts with runbook link (§5).
6. **Monthly pack** — generated artefacts (§6) with sign‑off state.

## 4. Urgent alert rules (continuous; evaluated on write + by lazy job ≤5 min) — PROPOSED thresholds
| ID | Condition | Severity | Notify |
|---|---|---|---|
| AL‑1 | ≥ 20 failed PIN attempts across ≥ 5 accounts from one `ip` in 10 min (credential‑stuffing/brute force) | HIGH | accounts office + super_admin |
| AL‑2 | Any `device.revoked_key_attempt` (proof signed by a revoked device key) | HIGH | same |
| AL‑3 | `break_glass.start` by super_admin | HIGH (informational but mandatory) | school_admin + other super_admin |
| AL‑4 | `audit.chain_mismatch` (hourly verifier finds broken link) | CRITICAL | super_admin + accounts office lead; freeze exports until resolved |
| AL‑5 | ≥ 10 activation/add‑device codes issued by one operator in 60 min, or any code issued outside office hours (configurable 07:00–19:00 Africa/Addis_Ababa) | MEDIUM | accounts office lead |
| AL‑6 | Refresh‑token replay detected (family revoked by rotation check) | MEDIUM | account owner notice in app + review flag |
| AL‑7 | `view_as.start` not reviewed within 24 h; S3 not reviewed within 3 d | MEDIUM | review role, escalate to school_admin at 48 h |
| AL‑8 | ACC API 5xx rate > 2 % over 15 min, or job not run in 24 h | MEDIUM | super_admin (technical) |
| AL‑9 | Operator rights change (`users.role` → / from `accounts_office`) — detected by comparing a daily snapshot of ACC‑capable users (no trigger on `users`, A16) | HIGH | school_admin + super_admin |

Delivery: in‑console banner + `failure_alerts` row via existing `FailureAlertService` (reuse — CONFIRMED pattern) → whatever channels that service already supports on production (email). Telegram/SMS **not** assumed. Alerts are deduplicated per rule/key for 60 min.

## 5. Incident response runbook (A10) — PROPOSED
Roles: **Incident lead** = any accounts office member on duty; **Technical** = super_admin; **Decision** = school_admin for custody/legal; **Recorder** = whoever opens the incident (`incident.open` event).

| Step | Action | Tool |
|---|---|---|
| 1 Triage (≤30 min) | Acknowledge alert, classify (account compromise / device theft / operator misuse / system integrity / data exposure) | Alerts page |
| 2 Contain | Account compromise → lock account(s) (T0), revoke affected device(s) **only** (A14 — do not revoke all unless owner approves), invalidate open codes; operator misuse → super_admin disables the staff user via existing `users.php` toggle (existing mechanism, A16); integrity → freeze exports, run chain verify, snapshot `acc_events` to `ssms_secure_backups` | Console + existing admin |
| 3 Notify | Affected parent/member via in‑app notice (and registered recovery channel if any); school_admin for anything touching a child's data | Notices |
| 4 Recover | Office‑visit re‑activation with new single‑use code; PIN reset; device replacement per D3 flows | Console |
| 5 Record | `incident.close` with summary, root cause, actions, follow‑ups; appears in next monthly pack | Review page |
| 6 Lessons | Threshold/policy change → `policy.change` (S2) | Policies page |

Severity → response targets: CRITICAL act within 1 h, HIGH 4 h, MEDIUM next business day. **AWAITING DECISION** on targets and on who is "on duty" outside office hours.

## 6. Monthly audit review (A10) — by the 7th, PROPOSED content
Generated on demand (lazy job, idempotent) as a stored HTML/PDF in `acc_reviews` with hash:
1. Volume: actions by type/operator/outcome; S1–S3 counts; review‑SLA compliance (AL‑7).
2. Sample: all S2/S3 + random 2 % of S0/S1 with full context for the reviewer.
3. Access: list of ACC‑capable users and changes during the month (AL‑9 snapshots); operators with zero activity (candidates for removal).
4. Devices: new enrolments by security class; revocations and reasons; devices stale > 60 d; auto‑revoked.
5. Codes: issued/consumed/expired; codes issued but never consumed > 30 d.
6. Incidents opened/closed; alerts by rule; false‑positive notes.
7. Integrity: chain verification results; archive digests; backup presence.
8. Sign‑off: school_admin marks reviewed (`review.monthly_signoff`); quarterly month adds the admin‑rights review (D1).
Retention: packs kept 5 years; `acc_events` online 24 months then archived with digest (D1 §9).

## 7. Metrics worth watching (health, not security)
Activation success rate (code issued → ACTIVE within 30 min), first‑login failure reasons, median time‑to‑reset, device class mix, app version spread (from `installations.app_version`), API p95 latency (sampled), job durations.

## 8. Privacy limits in logs (CONFIRMED by D1 data‑minimisation)
No PINs, codes, tokens, public keys or names in `details`; only ids and reason codes. `ua_hash` not raw user agent. IP kept 24 months then nulled in archive. View‑as reads are logged as `view_as.read` with resource ids only.

## 9. Hand‑off
D5 renders pages §3; D6 tests AL‑1…AL‑9 and the runbook; D7 schedules the alert evaluator as part of the lazy‑job runner (optional cPanel cron later — AWAITING DECISION whether to add a cron entry at all).
