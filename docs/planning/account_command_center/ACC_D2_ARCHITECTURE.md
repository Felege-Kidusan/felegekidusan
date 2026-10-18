# D2 — Account Command Center: Architecture (master)

**Status:** DRAFT v0.1 for owner review · **Date:** 2026-10-18 · **Programme step:** D2 of D0–D7 · **No code in this step.**
**Inputs:** D0 memo (Level B, shared hosting), D1 v1.1 (frozen), Parent baseline (locked), Members sign-in proposal (accepted design), Family Link (delivered).
**Structure requested by owner — three separated parts on one shared foundation:**

| Document | Covers |
|---|---|
| **This file (D2)** | Shared foundation: ACC Core identity engine, audit store, approval engine, libraries, hosting, trust boundaries, migration safety |
| `ACC_D2A_PARENT_APP_ARCHITECTURE.md` | Parent app ↔ ACC: activation, child-ID lookup, devices, tokens, data scopes |
| `ACC_D2B_MEMBERS_APP_ARCHITECTURE.md` | Members app ↔ ACC: activation, devices, tokens, data scopes |
| `ACC_D2C_COMMAND_CENTER_ARCHITECTURE.md` | Staff console: modules, desks, approvals inbox, audit explorer, reviews |

Labels: **FACT** (repo-verified / decided) · **PROPOSED** · **DECISION NEEDED (D2-n)**.

---

## 1. Separation principle

Three products, **one** identity engine, **one** console, **one** audit trail:

```
┌──────────────────┐   ┌──────────────────┐   ┌─────────────────────────────┐
│   PARENT APP     │   │   MEMBERS APP    │   │  COMMAND CENTER (staff web) │
│  client_id:      │   │  client_id:      │   │  SSMS admin session + CSRF  │
│  fkss-parent-app │   │  fkss-members-app│   │  roles: accounts_office,    │
│  /api/parent/v1  │   │  /api/member/v1  │   │  super_admin, school_admin, │
│  account kind:   │   │  account kind:   │   │  hr_dept (read)             │
│  parent          │   │  member          │   │  /admin/acc/…               │
└────────┬─────────┘   └────────┬─────────┘   └──────────────┬──────────────┘
         │ OAuth2 tokens (PKCE, device-assertion grant, rotated refresh)      │ server-side calls
         ▼                      ▼                                              ▼
┌──────────────────────────────────────────────────────────────────────────────────────┐
│                         ACC CORE  (PHP library, admin/backend/acc/)                   │
│  Accounts · Activation codes · Proofing · Devices · Sessions · PIN · Consents         │
│  Token engine (league/oauth2-server) · Device-proof verifier · Rate limiter           │
│  Approval engine (T1/T2, maker≠checker, break-glass) · Policy registry                │
│  Audit writer (append-only, hash chain) · Notifications (in-app)                      │
└───────────────┬───────────────────────────────┬───────────────────────────────────────┘
                │ reads only                    │ owns
                ▼                               ▼
   ┌─────────────────────────┐      ┌───────────────────────────┐
   │ EXISTING SSMS DATA      │      │ NEW acc_* TABLES          │
   │ members, guardians,     │      │ acc_accounts, acc_codes,  │
   │ member_guardians, users │      │ acc_devices, acc_sessions,│
   │ (never written by ACC)  │      │ acc_events (audit) …      │
   └─────────────────────────┘      └───────────────────────────┘
```

What is **separate per product**: OAuth client id, API namespace, account kind, scopes, consent text, rate-limit buckets, app release channel, data endpoints, UI language/content.
What is **shared**: account/device/session model, token engine, activation ceremony mechanics, audit store, approval engine, policy registry, console.
What is **never touched**: staff web login, staff mobile API `/api/v1/*` and its HMAC tokens + `RefreshTokenService` (FACT: `api/v1/core/auth.php`, `admin/backend/services/RefreshTokenService.php`), Family Link write paths (HR only).

---

## 2. ACC Core — components

| Component | Responsibility | Built on |
|---|---|---|
| **AccountService** | Create/provision account bound to `members.id` or `guardians.id`; state machine (D1 §6); `authorization_version` per account | own code |
| **ActivationService** | Issue/verify one-time codes (activate, reset, add-device); TTL, single-use, hashed; binds code → account (→ guardian for parents) | `random_bytes`, `hash_hmac`; own code |
| **ProofingService** | Proofing record per ceremony (staff, evidence type, children confirmed, consent version, legal-restriction check) | own code |
| **DeviceService** | Device registry: public key, platform, name, first/last seen, state; max 3; STALE/auto-revoke timers evaluated on request | own code; ECDSA P-256 keys |
| **DeviceProofVerifier** | Verifies per-request device proof (DPoP-style JWT signed by device key, RFC 9449 shape: `htm`, `htu`, `iat`, `jti`, nonce) | **`lcobucci/jwt`** (ES256 verification), `openssl` |
| **PinService** | 6-digit PIN: peppered (server secret outside web root) + `password_hash` (argon2id if available, else bcrypt); verified **only** together with a valid device proof; attempt counters per account+device | PHP native |
| **TokenEngine** | OAuth2 authorization server: access tokens (JWT, 15 min), refresh tokens (rotated, family tracked), custom grant `urn:fkss:params:oauth:grant-type:device-assertion`, PKCE for any browser-based flow later, token revocation | **`league/oauth2-server`** + PSR-7 impl (**`nyholm/psr7`**) |
| **RateLimiter** | Per-IP, per-device, per-account, per-ID buckets for code entry, PIN, token endpoints | D2-2: `symfony/rate-limiter` (PDO storage) **or** extend existing `SecurityRateLimiter.php` (FACT: exists in repo) |
| **PolicyRegistry** | D1 §9 parameters in `acc_policies` with version; change = T1 approval | own code |
| **ApprovalEngine** | Requests, routing table (versioned), maker≠checker, fallback, escalation timers, delegation, break-glass, binding to payload hash | own code |
| **AuditWriter / AuditVerifier** | Append-only `acc_events` with `prev_hash`/`row_hash`, daily digest rows, anchor export, verification walk | own code (no mature PHP lib exists; ~150 lines, test-covered) |
| **NotificationService** | In-app notices to user devices (next sync / push via the app's channel) for resets, device revocations, view-as, approvals | own code; push provider per parent-app plan |
| **ConsentService** | Consent versions per product; acceptance records; re-consent flag | own code |

---

## 3. Identity & token design (OIDC-shaped)

### 3.1 Principals and identifiers
- `acc_accounts.id` = UUIDv4 → OIDC `sub`. Never the member ID, never the guardian id.
- `kind ∈ {member, parent}`; `subject_table/subject_id` → `members.id` or `guardians.id` (D1 §7a: parent accounts are owned by the guardian record).
- A parent's typed **child member ID** is resolved **server-side at ceremony time only**: `member_code → members.id → member_guardians → guardian candidates`; the activation code then selects exactly one guardian (ID-4). Nothing of the typed ID is persisted on the account.

### 3.2 Authentication = device proof + PIN (+ biometric locally)
1. **Enrolment** (activation or add-device, always with a desk code): app generates a P-256 key pair in the OS keystore (Android Keystore / iOS Secure Enclave) with biometric/device-credential gating; sends public key + code (+ child ID for parents). Server verifies code, binds device → account, stores public key, returns first tokens.
2. **Every token request** carries a device proof JWT (ES256, `jti` single-use, `iat` ±60 s, `htm/htu` bound, server nonce when challenged). Access tokens are **sender-constrained** to the device key (`cnf.jkt` thumbprint, RFC 9449 shape) → a stolen access/refresh token is useless without the phone's key.
3. **Session unlock:** PIN is required at first use after install, after `refresh absolute` expiry, after STALE, or after a staff reset; daily use is biometric-only on the device (local), with the device proof doing the cryptographic work. The PIN is verified only when accompanied by a valid device proof → online brute force limited by rate limiter; offline brute force of a 6-digit PIN is prevented by the server pepper kept outside the web root (`.fkss_env.php` pattern, FACT exists).
4. **Why not platform passkeys (WebAuthn) for the apps:** synced passkeys (Google/Apple password managers) restore onto *other* devices — exactly what "trusted device" semantics forbid. Keystore keys are device-bound and non-exportable. **D2-1 DECISION NEEDED:** confirm keystore keys + DPoP-style proofs (recommended) over WebAuthn passkeys; `web-auth/webauthn-lib` is then reserved for a later staff-console step-up and is **not** vendored now.

### 3.3 Tokens
| Token | Format | Lifetime (D1 §9) | Bound to |
|---|---|---|---|
| Access | JWT (RS256 or ES256, server key pair in env file) | 15 min | `sub`, `kind`, `client_id`, `scope`, `cnf.jkt`, `auth_time`, `acr` (`pin+device`) |
| Refresh | opaque, hashed at rest (`acc_sessions`) | sliding 30 d / absolute 180 d | device id, family id; reuse → revoke family (pattern FACT in `api_refresh_sessions`) |
| Device proof | JWT ES256 by device key | 60 s | request method+URL, nonce |
| Activation/reset/add-device code | 8–10 char Crockford base32 (+ QR), CSPRNG, hashed at rest | **30 min** / 24 h / 24 h | account (+ guardian), purpose, single use, invalidated when replaced |
| View-as token (staff) | JWT, `act` claim = staff user, `scope=read-only`, `sid` | 15 min | staff SSMS session; refused by every write handler centrally |

Revocation: `authorization_version` on `acc_accounts` is embedded in access tokens and checked on every protected request (same mechanism staff tokens use — FACT `users.authorization_version`), so a lock/suspend/device-revoke takes effect within one request, not after 15 min.

### 3.4 Grants (league/oauth2-server)
- `device-assertion` (custom grant class): input = device proof + (PIN **or** valid refresh) → access + refresh. Used by both apps.
- `refresh_token` (built-in): rotated; our repository implementation adds family tracking and reuse detection.
- `authorization_code + PKCE` (built-in): **not enabled now**; kept available for a future web/SSO use. This is the "OIDC-shaped, engine swappable later" guarantee from D0.

---

## 4. Audit architecture (shared)

- `acc_events` (append-only): `seq, occurred_at, stream (user|admin|system|approval), event, actor_type/id/label, subject_type/id, product (parent|member|console), outcome, reason, ip, ua_hash, device_id, request_id, before_json, after_json, prev_hash, row_hash`.
- **Append-only enforcement on MariaDB:** `BEFORE UPDATE` / `BEFORE DELETE` triggers that `SIGNAL SQLSTATE '45000'`; ACC code has no update/delete path; the DB user is shared on shared hosting (FACT constraint) → triggers + chain are the control, not grants.
- **Hash chain:** `row_hash = SHA-256(prev_hash ‖ canonical JSON of row)`; genesis = 64×'0'. Written inside the same transaction as the business change.
- **Digests:** `acc_event_digests` (period, first/last seq, head hash, created_by=system) created on first console load after midnight (no cron) and on demand; head hash also written to legacy `activity_logs` (separate table = cheap external anchor) and included in the monthly review PDF/CSV.
- **Verification:** `AuditVerifier::walk(from,to)` recomputes and compares; exposed as a one-button page for `school_admin` (D1 SoD-6).
- **No secrets in events** (codes, PINs, tokens, keys); personal data minimised in `before/after` (ids + changed field names + hashed values where needed).

---

## 5. Approval engine (shared)

```
acc_approval_requests: id, action, product, subject, payload_json, payload_hash, maker_id, tier, route_version,
                       status (pending|approved|rejected|escalated|expired|breakglass), checker_id, decided_at, reason,
                       delegated_from, due_at, breakglass_review_by, breakglass_reviewed_by/at
```
- Server rule: `checker_id ≠ maker_id` (compared on `users.id`); SoD-2 self-relation check uses `users.member_id` ↔ account subject.
- Execution re-validates payload hash and current state before applying (SoD-7); idempotent via existing `ApiIdempotencyService` pattern (FACT exists).
- Routing table (`acc_approval_routes`, versioned): action → approver roles → fallback → escalation hours.
- Timers evaluated lazily (on console page load / API hit) — no cron.

---

## 6. Library stack (vendored, pinned) — D2-2 decisions

| Library | Purpose | Licence | PHP / ext needs | Vendoring note |
|---|---|---|---|---|
| `league/oauth2-server` ^9 | token engine | MIT | PHP ≥ 8.2, `openssl`, `json`; deps `lcobucci/jwt`, `league/event`, `league/uri`, `defuse/php-encryption` | medium footprint; required |
| `nyholm/psr7` + `nyholm/psr7-server` | PSR-7/17 messages for the league server in plain PHP | MIT | none | tiny |
| `lcobucci/jwt` (comes with league) | sign/verify access tokens; verify device proofs (ES256) | BSD-3 | `openssl`, `sodium` optional | shared |
| `symfony/rate-limiter` + `symfony/cache` (PdoAdapter) **or** in-house `SecurityRateLimiter` extension | buckets | MIT | PDO | **D2-2:** recommend **in-house extension** — the Symfony pair pulls ~6 packages for a problem the repo already solves; revisit if limits get complex |
| `spomky-labs/otphp` | optional TOTP/recovery codes (members proposal §8, optional channel) | MIT | none | **defer** until recovery channels are built |
| `monolog/monolog` | operational logs (not audit) | MIT | none | optional; repo uses `error_log` today — **defer** |
| `web-auth/webauthn-lib` | passkeys | MIT | PHP ≥ 8.2, `mbstring`, `sodium`/`gmp` | **not now** (see D2-1) |
| `phpoffice/phpspreadsheet` | reports/exports | MIT | already vendored | reuse |

**Host extension check (you run once on production, read-only):** PHP version ≥ 8.2?, `openssl` (with EC P-256 support), `json`, `mbstring`, `sodium` (optional), `gmp`/`bcmath` (optional), `PASSWORD_ARGON2ID` defined?, `random_bytes` OK, `zlib`. I will supply a 20-line script in D3 that prints only these booleans.

---

## 7. Trust boundaries & first-pass threat table

| Boundary / threat | Control (where) |
|---|---|
| Desk ceremony — wrong person activated | Proofing record mandatory; HR link must pre-exist (T2 otherwise); code bound to one guardian; slip shown once; anti-enumeration |
| Stolen code slip | Useless without a phone the attacker controls **and** presence at setup within TTL? No — code alone *does* allow enrolment → mitigations: short TTL, single use, PIN set by user at setup, **in-app + desk notice when a code is used**, parent sees new device in settings; **D2-3 DECISION NEEDED:** require the code to be used at the desk in front of staff (zero remote risk, longer desk time) or allow use at home within 72 h (baseline-friendly) |
| Stolen/lost phone | Device key gated by biometric/device credential; PIN required for sensitive views; staff/user revoke device → `authorization_version` bump kills sessions instantly |
| Token theft (network, backup, malware) | Sender-constrained tokens (`cnf.jkt`); refresh reuse detection revokes family; TLS only |
| PIN brute force | Online: per-device/account limiter + lock; offline: pepper outside web root; PIN never verifiable without device proof |
| Insider admin | T1 maker≠checker; SoD-2 self-relation refusal; append-only hash-chained audit; school-admin monthly review; view-as read-only + 24 h review |
| Replayed / altered approval | Payload hash binding, single-use, state re-check |
| Log tampering by DB-level access | Hash chain + digest anchor in `activity_logs` + exported heads; verification button |
| Enumeration via child ID | Uniform responses, limiter per IP/ID, codes required for any identity-revealing step |
| Shared-hosting DB user has all rights | Accepted residual risk; compensated by triggers, chain, anchors, external exports (documented in risk register) |

---

## 8. Hosting & operational constraints (FACT-driven)

- PHP + MariaDB only; no daemons; **no cron** assumed → all timers (STALE, expiry, digests, escalations) are evaluated lazily and idempotently on request; an optional cPanel cron may call the same endpoints if available (FACT: repo already has `cron_backup.php`, `mobile_telemetry_retention.php` as callable scripts).
- MariaDB-safe DDL (InnoDB, utf8mb4, no CHECK dependence, triggers allowed? **verify on host** — if triggers are not permitted, fall back to chain + verification only; add to host check).
- Vendor committed (`git add -f`), composer run locally; licence file list maintained in `docs/ACC_THIRD_PARTY.md`.
- Server key pair and PIN pepper live in `.fkss_env.php` (outside web root, never committed — FACT pattern).
- Static `/.well-known/assetlinks.json` and `apple-app-site-association` **not required** for keystore keys (only for passkeys) — another reason for D2-1.

---

## 9. Degraded modes

| Situation | Apps | Console |
|---|---|---|
| SSMS slow/down | cached data readable (baseline §15); no activation/reset/token refresh; tokens valid until expiry (≤ 15 min access; app keeps refresh for retry) | down |
| Audit write fails | business write **also fails** (same transaction) — never silent | error shown |
| Digest/anchor job missed | chain still valid; next load catches up; verification shows gap as warning | warning banner |
| Approver unavailable | — | fallback / escalation / break-glass (D1 §8) |

---

## 10. Migration-safety analysis

| Existing asset | ACC impact | Evidence to produce in tests |
|---|---|---|
| `users`, staff web login, `AdminSessionGuard` | none (ACC console uses the existing staff session + role gates; adds role value `accounts_office` to the role VARCHAR — FACT role is VARCHAR since migration 012) | full existing suite green; new role appears in `admin/users.php` role list only |
| `/api/v1/*`, HMAC tokens, `RefreshTokenService`, `api_refresh_sessions` | none; ACC uses `/api/parent/v1`, `/api/member/v1`, `acc_sessions` | staff API E2E 104/104 unchanged |
| Family Link tables/services | read-only joins; HR UI unchanged; new *read* of `member_guardians` for link status | Family Link E2E 27/27 unchanged |
| `activity_logs` | ACC additionally writes digest heads (append) | row count grows; schema unchanged |
| Deployment | new folders `admin/backend/acc/`, `admin/acc/`, `api/parent/`, `api/member/`; new `sql/07x_acc_*.sql` run by you in phpMyAdmin | migration scripts idempotent |

---

## 11. Decisions needed in D2

| ID | Question | Recommendation |
|---|---|---|
| D2-1 | Device binding: OS keystore keys + DPoP-style proofs (no passkeys now) | **ACCEPTED** (owner, 2026‑10‑18) — conditional on developer verification checklist (`ACC_D2_DECISION_RECORD_AND_RISKS.md` §4); software‑key fallback accepted with documented limitations (§3 there) |
| D2-2 | Rate limiting: extend in-house `SecurityRateLimiter` vs vendor `symfony/rate-limiter` stack | In-house extension |
| D2-3 | Activation code used **at the desk** in front of staff, or **at home** within 72 h | **ACCEPTED** (owner): first activation at the office, code single‑use, 30 min, consumed during in‑person verification; add‑device/reset codes 24 h with conditions; **one‑account‑per‑phone NOT finalised** (assessment in decision record §2) |
| D2-4 | Access-token signature: RS256 (one key pair, simple) vs ES256 (smaller tokens) | ES256 |
| D2-5 | Triggers permitted on the production MariaDB? (host check) | If not, chain + verification only |

---

## 12. Exit to D3
D3 (Data & API) will specify every `acc_*` table, the two API contracts (`/api/parent/v1`, `/api/member/v1`), the console API, the host-check script, and the migration SQL plan — after D2-1…D2-5 are answered.
