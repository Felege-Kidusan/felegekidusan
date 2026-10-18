# D3 — Account Command Center: Data Model & API Contracts (planning)

**Status:** v1.0 — **planning only, no code, no schema applied.** Host‑dependent items were confirmed by the read‑only SSH check on 2026‑10‑18 (§8b); all **[HOST]** tags are resolved as stated there.
**Date:** 2026-10-18 · **Inputs:** D1 v1.2 (frozen), D2 master + D2‑A/B/C, D2 decision record & risk register, owner decisions below.
Labels: **FACT** (repo‑verified) · **PROPOSED** · **[HOST]** depends on host check · **UNKNOWN**.

## 0. Owner decisions carried into D3 (final)
| # | Decision | Effect in D3 |
|---|---|---|
| 1 | **Android first; iPhone deferred** | API/data stay platform‑neutral; `acc_devices.platform` allows `ios` but phase‑1 clients are Android; no Apple site‑association work |
| 2 | **Office activation via Wi‑Fi or staff hotspot** | No offline activation path; activation endpoints are online‑only, sized for small payloads |
| 3 | **Shared‑phone account switcher in Phase 1** | Device ↔ account is many‑to‑many from day one (`installation_id` groups accounts on one phone); switcher endpoints in both app APIs |
| 4 | **Run read‑only hosting check before finalising** | Script delivered: `/home/user/acc_host_check/acc_host_check.php` (§8). Results decide PHP‑library version, triggers, hashing algorithm |

---

## 1. Naming, conventions
- All new tables prefixed **`acc_`**, InnoDB, utf8mb4_unicode_ci, `created_at DATETIME` in **UTC** (`UTC_TIMESTAMP()`), ids `BIGINT UNSIGNED AUTO_INCREMENT` unless a UUID is the public identifier.
- Public identifiers exposed to apps are **UUIDv4 strings (CHAR(36))**, never auto‑increment ids.
- Existing tables are **read‑only** for ACC: `members` (`id`, `member_code` VARCHAR(30) UNIQUE — FACT), `guardians`, `member_guardians` (PK `member_id,guardian_id`, `relation`, `is_primary`, `verified_by/at` — FACT), `users`.
- Secrets never stored in clear: codes → `SHA‑256(code ‖ server_pepper)`; PIN → `password_hash(HMAC‑SHA256(pin, pin_pepper))` with argon2id **[HOST]** else bcrypt; refresh tokens → SHA‑256; device public keys stored as‑is (public).
- Every write path emits an `acc_events` row **in the same transaction** (D2 §4).

---

## 2. Data model

### 2.1 Core identity
```
acc_accounts
  id BIGINT PK · uuid CHAR(36) UNIQUE (OIDC sub)
  kind ENUM('member','parent')
  subject_table ENUM('members','guardians') · subject_id INT UNSIGNED       -- UNIQUE(kind, subject_id)
  state ENUM('provisioned','pending_activation','active','locked','suspended','closed')
  state_reason VARCHAR(64) NULL · state_changed_at DATETIME · state_changed_by INT UNSIGNED NULL (users.id)
  authorization_version INT UNSIGNED NOT NULL DEFAULT 1                      -- bump = instant revoke
  locale CHAR(2) DEFAULT 'am'
  activated_at, suspended_at, closed_at DATETIME NULL
  created_by INT UNSIGNED (users.id) · created_at, updated_at
  INDEX (state), INDEX (subject_table, subject_id)

acc_credentials                      -- PIN history; exactly one row with revoked_at IS NULL per account
  id · account_id FK · pin_hash VARCHAR(255) · pin_algo ENUM('argon2id','bcrypt') [HOST]
  pin_version INT · set_via ENUM('activation','reset','user_change') · set_by_user INT NULL
  failed_count SMALLINT DEFAULT 0 · locked_until DATETIME NULL
  created_at · revoked_at NULL
  INDEX (account_id, revoked_at)

acc_codes                            -- activation / reset / add-device / pairing codes
  id · account_id FK · purpose ENUM('activate','reset','add_device','pairing')
  code_hash CHAR(64) UNIQUE · ttl_seconds INT · expires_at DATETIME
  issued_by_user INT NULL (staff) · issued_by_account BIGINT NULL (self-service pairing) · issued_from_device BIGINT NULL
  proofing_id BIGINT NULL FK acc_proofing_records
  used_at NULL · used_by_device BIGINT NULL · revoked_at NULL · superseded_by BIGINT NULL   -- replaced ⇒ invalid
  attempts SMALLINT DEFAULT 0 · created_at
  INDEX (account_id, purpose, expires_at)
  Rules: activate=1800 s (30 min); reset/add_device/pairing=86400 s; single use; issuing a new code of the same
  purpose sets superseded_by on all open ones; code bytes from random_bytes, Crockford base32, ≥ 8 chars (+QR payload).

acc_proofing_records
  id · account_id FK · ceremony ENUM('activation','reset','add_device','identity_only')
  staff_user_id INT · evidence_type ENUM('national_id','kebele_id','passport','school_card','known_person','other')
  evidence_note VARCHAR(255) NULL (no document numbers)
  children_confirmed JSON NULL        -- [member_id,…] parent only
  legal_restriction_checked TINYINT(1) · legal_restriction_note VARCHAR(255) NULL
  consent_version_id BIGINT NULL · outcome ENUM('passed','refused') · refusal_reason VARCHAR(255) NULL
  created_at
  INDEX (account_id, created_at), INDEX (staff_user_id, created_at)
  Retention: life of account + 5 years (D1).
```

### 2.2 Devices, installations, sessions, proofs
```
acc_installations                    -- one row per app install on a phone (shared-phone grouping)
  id · uuid CHAR(36) UNIQUE (X-Installation-Id, generated by app, kept in secure storage)
  product ENUM('parent','member') · platform ENUM('android','ios') · app_version VARCHAR(32)
  device_model VARCHAR(80) · os_version VARCHAR(32)
  first_seen, last_seen · retired_at NULL

acc_devices                          -- one row per (account, installation): its own key pair
  id · uuid CHAR(36) UNIQUE · account_id FK · installation_id FK
  key_alias VARCHAR(64)               -- plugin alias used on the phone (per account)
  public_key_pem TEXT · key_thumbprint CHAR(43) UNIQUE (RFC 7638 jkt, base64url)
  key_security ENUM('strongbox','tee','secure_enclave','software') · attestation_status ENUM('verified','recorded','none')
  attestation_blob MEDIUMTEXT NULL    -- Android attestation chain (optional, D2-1 V4)
  display_name VARCHAR(60) · state ENUM('enrolling','active','stale','revoked')
  biometric_bound TINYINT(1) · enrolled_via ENUM('activation','add_device_code','pairing')
  first_seen, last_seen · revoked_at NULL · revoked_by ENUM('user','staff','policy','system') NULL · revoked_reason VARCHAR(64) NULL
  UNIQUE (account_id, installation_id), INDEX (installation_id), INDEX (account_id, state)
  Rule (service): max 3 active devices per account; a parent installation may add an account only if the new
  guardian shares ≥ 1 linked child with every guardian already on that installation (D2 decision record §2.3).

acc_sessions                         -- refresh-token families (mirrors api_refresh_sessions pattern — FACT)
  id · family_id CHAR(36) · account_id FK · device_id FK · client_id VARCHAR(40)
  token_hash CHAR(64) UNIQUE · replaced_by BIGINT NULL · issued_at · expires_at (sliding) · absolute_expires_at
  last_used_at · created_ip VARBINARY(16) · revoked_at NULL · revoked_reason VARCHAR(64) NULL
  scope VARCHAR(255) · acr ENUM('device','device+pin')
  INDEX (account_id, revoked_at), INDEX (device_id), INDEX (family_id)

acc_proof_nonces                     -- replay protection for device proofs (jti) and server-issued nonces
  jti CHAR(36) PK · device_id FK · seen_at DATETIME · INDEX (seen_at)      -- purge rows older than 10 min lazily
acc_oauth_clients                    -- 2 rows: fkss-parent-app, fkss-members-app (public clients, no secret)
  client_id VARCHAR(40) PK · product · allowed_scopes TEXT · allowed_grants TEXT · is_active
```

### 2.3 Parent link overrides, consents, channels, notices
```
acc_link_overrides                   -- school-admin decisions on top of Family Link (HR data untouched)
  id · guardian_id INT · member_id INT · status ENUM('removed_by_sa','reinstated') · reason VARCHAR(255)
  decided_by INT (school_admin) · approval_request_id BIGINT · effective_from · effective_to NULL
  UNIQUE (guardian_id, member_id, effective_to)
  Effective child list for a parent account = member_guardians rows for the guardian MINUS active 'removed_by_sa'.

acc_consent_versions  id · product · version VARCHAR(16) · title_am, title_en · body_am, body_en MEDIUMTEXT · effective_from · created_by
acc_consents          id · account_id · consent_version_id · accepted_at · via ENUM('office','app') · device_id NULL · withdrawn_at NULL
acc_contact_channels  id · account_id · type ENUM('email','telegram') · address_hash CHAR(64) · address_enc TEXT · verified_at NULL · added_from_device · removed_at NULL   -- phase 2 (members §8), table defined now
acc_notices           id · account_id · code VARCHAR(64) (localised by app) · params JSON · created_at · delivered_at NULL · read_at NULL · INDEX (account_id, read_at)
```

### 2.4 Console: support, view‑as, approvals, policy, reviews
```
acc_support_cases      id · account_id · opened_by INT · category ENUM('activation','lost_phone','forgotten_pin','app_issue','data_correction','closure','other') · status ENUM('open','waiting','closed') · summary VARCHAR(255) · created_at · closed_at
acc_support_notes      id · case_id · author_id · note TEXT · created_at
acc_view_as_sessions   id · account_id · staff_user_id · reason VARCHAR(255) · token_jti CHAR(36) · started_at · expires_at · ended_at NULL · reviewed_by INT NULL · reviewed_at NULL · review_note VARCHAR(255) NULL · INDEX (reviewed_at)
acc_approval_routes    id · version INT · action VARCHAR(64) · tier ENUM('T1','T2') · approver_roles VARCHAR(120) · fallback_roles VARCHAR(120) · escalate_after_hours SMALLINT · active TINYINT(1)
acc_approval_requests  id · action · product · subject_type · subject_id · payload JSON · payload_hash CHAR(64) · maker_id INT · tier · route_version INT
                       status ENUM('pending','approved','rejected','escalated','expired','executed','breakglass') · checker_id INT NULL · decided_at NULL · reason VARCHAR(255) NULL
                       delegated_from INT NULL · due_at · breakglass_review_by DATETIME NULL · breakglass_reviewed_by INT NULL · breakglass_reviewed_at NULL · executed_at NULL · created_at
                       INDEX (status, due_at), INDEX (maker_id), CHECK via service: checker_id <> maker_id
acc_delegations        id · from_user INT · to_user INT · scope VARCHAR(64) · starts_at · ends_at · created_by
acc_policies           key VARCHAR(64) PK · value VARCHAR(255) · version INT · changed_by INT · changed_at · approval_request_id BIGINT NULL
acc_policy_history     id · key · old_value · new_value · version · changed_by · changed_at · approval_request_id
acc_reviews            id · type ENUM('monthly','quarterly_access') · period VARCHAR(7) · prepared_by INT · signed_by INT NULL · signed_at NULL · comment VARCHAR(500) · snapshot JSON · created_at
```

### 2.5 Audit
```
acc_events  (APPEND-ONLY)
  seq BIGINT UNSIGNED AUTO_INCREMENT PK
  occurred_at DATETIME(3) · stream ENUM('user','admin','system','approval') · event VARCHAR(64)
  actor_type ENUM('account','staff','system') · actor_id BIGINT NULL · actor_label VARCHAR(120)
  subject_type VARCHAR(32) · subject_id BIGINT NULL · product ENUM('parent','member','console','core')
  outcome ENUM('success','failure','denied') · reason VARCHAR(64) NULL
  ip VARBINARY(16) NULL · ua_hash CHAR(64) NULL · device_id BIGINT NULL · installation_id BIGINT NULL · request_id CHAR(36)
  before_json JSON NULL · after_json JSON NULL     -- PII-minimised
  prev_hash CHAR(64) · row_hash CHAR(64)
  INDEX (occurred_at), INDEX (subject_type, subject_id, occurred_at), INDEX (actor_type, actor_id, occurred_at), INDEX (event, occurred_at), INDEX (request_id)
  Triggers [HOST]: BEFORE UPDATE / BEFORE DELETE → SIGNAL SQLSTATE '45000' 'acc_events is append-only'
  Canonical hash input: JSON of all columns except prev_hash/row_hash, keys sorted, UTC ISO-8601 ms; row_hash = SHA-256(prev_hash ‖ canonical).
  Concurrency: writer takes `SELECT … FOR UPDATE` on a single-row acc_event_head (last_seq, last_hash) to serialise chain appends.

acc_event_head      id TINYINT PK=1 · last_seq · last_hash · updated_at
acc_event_digests   id · period_date DATE UNIQUE · first_seq · last_seq · head_hash CHAR(64) · created_at · anchored_activity_log_id BIGINT NULL · exported_at NULL
acc_events_archive  same columns as acc_events (+ archived_at) — moved after 24 months by the on-demand archiver (append-only too)
```

### 2.6 Event vocabulary (final list for D3; emitted names are stable API)
`authn.unlock.success|fail` · `authn.pin.fail|fail_max` · `authn.token.issued|refreshed|revoked|reuse_detected` · `authn.proof.replay|invalid`
`account.provisioned|code.issued|code.used|code.expired|code.revoked|code.superseded|activated|locked|unlocked|suspended|reinstated|closed|closure.requested`
`account.pin.reset_by_staff|changed_by_user` · `account.proofing.recorded|refused`
`device.registered|renamed|revoked|stale|attestation.recorded` · `device.pairing_code.issued|used` · `installation.account_added|account_removed`
`link.denied_request|removed_by_sa|reinstated_by_sa` · `consent.granted|withdrawn` · `channel.added|verified|removed`
`support.case.opened|closed` · `support.view_as.start|end|reviewed` · `access.sensitive.read`
`approval.requested|approved|rejected|escalated|delegated|breakglass|executed` · `admin.role.granted|revoked` · `policy.changed`
`review.monthly.signed|quarterly_access.signed` · `audit.digest.created|verify.ok|verify.fail` · `data.export.requested|delivered`

---

## 3. API conventions (both apps)
- Base paths: `/api/parent/v1/…`, `/api/member/v1/…` (new front controllers; the staff `/api/v1` is untouched — FACT).
- JSON; `status: success|error`; errors carry a **machine `code`** (e.g. `CODE_EXPIRED`, `DEVICE_REVOKED`, `PIN_LOCKED`, `LINK_NOT_FOUND`, `AUTHZ_VERSION_CHANGED`) that the app localises (Amharic). No free‑text secrets, no stack traces (`test_error_disclosure` rule — FACT).
- Headers: `Authorization: DPoP <access_jwt>` · `DPoP: <proof_jwt>` (ES256 by device key; claims `htm, htu, iat, jti, nonce?`, `ath` = hash of access token when present) · `X-Installation-Id` · `X-App-Version` · `Idempotency-Key` on POSTs that mutate.
- Access JWT claims: `iss, sub(uuid), aud(client_id), kind, scope, cnf{jkt}, acr, auth_time, av(authorization_version), iat, exp(≤15 min), jti`. Signed ES256 with server key (PEM in env file) **[HOST: EC verified by script]**.
- Rate limits (PROPOSED, via existing `security_rate_limits` table — FACT `sql/008`): activation/resolve 10/h per IP + 5/h per child ID; code use 5 attempts per code; PIN 5 per device per 15 min; token 60/min per device.
- All responses `Cache-Control: no-store`.

## 4. Parent app API — `/api/parent/v1`
| Method & path | Auth | Request → Response (shape) | Errors |
|---|---|---|---|
| `POST /activation/resolve` | none + limits | `{child_member_code, code, installation}` → `{ceremony_id, guardian_display (masked, e.g. "W/ro A… B…"), children:[{member_id, name, grade}], consent:{version, title_am, body_am}, code_expires_in}` | `CODE_INVALID` (uniform for unknown ID / wrong code), `CODE_EXPIRED`, `RATE_LIMITED` |
| `POST /activation/complete` | ceremony_id | `{ceremony_id, device:{public_key_pem|jwk, key_security, attestation?, display_name, biometric_bound}, pin, consent_accept:true}` → tokens `{access_token, refresh_token, expires_in, token_type:"DPoP"}` + `{account:{sub, children:[…]}, device:{uuid}}` | `CODE_USED`, `PIN_WEAK`, `DEVICE_LIMIT`, `INSTALLATION_FAMILY_MISMATCH` |
| `POST /token` | DPoP proof | `grant_type=urn:fkss:params:oauth:grant-type:device-assertion` + `{pin?}` **or** `grant_type=refresh_token` + `{refresh_token}` → tokens | `DEVICE_REVOKED`, `ACCOUNT_LOCKED`, `ACCOUNT_SUSPENDED`, `PIN_REQUIRED`, `PIN_INVALID`, `PIN_LOCKED`, `REFRESH_REUSE` (family revoked) |
| `POST /revoke` | access | `{refresh_token?}` → `{}` (current device session) | — |
| `GET /me` | access | → `{sub, kind:"parent", state, guardian:{display_name, relation_by_child}, children:[{member_id, member_code, name, grade, link_status}], devices:[{uuid, display_name, key_security, this_device, last_seen}], consent:{current, accepted}, pin_required_for:[…]}` | — |
| `POST /me/pin` | access + `acr=device+pin` | `{old_pin, new_pin}` → `{}` (events, notice to other devices) | `PIN_INVALID`, `PIN_LOCKED` |
| `GET /me/devices` · `POST /me/devices/{uuid}/revoke` · `POST /me/devices/{uuid}/rename` | access (+pin for revoke) | → list / `{}` | `DEVICE_NOT_FOUND` |
| `POST /me/devices/pairing-code` | access + pin | → `{code, qr_payload, expires_in:86400}` (single use; supersedes previous) | `DEVICE_LIMIT` |
| `POST /devices/pair` | none + limits | `{code, installation, device:{…}, pin}` → tokens (new device enrolled; pairing requires the PIN) | `CODE_INVALID`, `PIN_INVALID`, `DEVICE_LIMIT` |
| `POST /reset/complete` | none + limits | `{child_member_code, code, new_pin, device?}` → tokens; all other sessions revoked; notices | `CODE_INVALID`, `CODE_EXPIRED` |
| `GET /installation/accounts` | none; `X-Installation-Id` | → `[{sub, display_name_masked, kind, key_alias}]` — the switcher list for this phone (names masked until that account's PIN is entered) | — |
| `POST /me/closure-request` | access + pin | `{reason?}` → `{}` (account → suspended:user_request) | — |
| `GET /me/notices` · `POST /me/notices/{id}/read` | access | → list | — |
| `GET /consent/current` · `POST /consent/accept` | access | → consent / `{}` | — |
| Child data (built by the Parent‑app programme) | access + `ParentScope::assertLinked` | `/children/{member_id}/…` | `LINK_NOT_FOUND` (uniform) |

Switcher semantics: the app holds, per enrolled account on this installation, its key alias + refresh token in secure storage; switching = select account → that account's PIN (or biometric if the account's session is still valid and policy allows) → `POST /token` with that device key. The server sees ordinary per‑device requests; `installation_id` only groups them for the console and for the family‑mismatch rule.

## 5. Members app API — `/api/member/v1`
Identical mechanics with `member_code` instead of `child_member_code`; `GET /me` returns the member profile summary; eligibility check at `activation/resolve` (`MEMBER_NOT_ELIGIBLE`); `channels` endpoints reserved for phase 2 (`POST /me/channels`, `POST /me/channels/{id}/verify`, `DELETE`). Member data endpoints use `MemberScope::assertSelf`.

## 6. Console API — `admin/api_acc.php?action=…` (staff session + CSRF; role gate per action; tier from D1 §4)
| Group | Actions |
|---|---|
| Directory | `search`, `account_get`, `account_timeline`, `installation_get` |
| Activation desk | `provision`, `proofing_record`, `code_issue{purpose}` (T0; blocked with `LINK_MISSING` → `hr_link_request` creates Family Link review item), `code_revoke`, `code_status` |
| Lost‑phone / reset | `device_revoke`, `devices_revoke_all`, `pin_reset_code` (revokes sessions, notice) |
| State | `account_lock`, `account_unlock`, `account_suspend`, `account_reinstate`, `account_close{requested_by_user}` (T0 if user‑requested else creates T1 request) |
| Links | `link_removal_request` (creates T2 → school_admin), `link_reinstate_request` |
| Support | `case_open`, `case_note`, `case_close`, `view_as_start{reason}`, `view_as_end`, `view_as_review` (not own) |
| Approvals | `approvals_inbox`, `approval_decide{approve|reject, reason}`, `approval_breakglass{reason}`, `breakglass_review`, `delegation_create` |
| Audit | `events_search`, `events_export` (T1), `digest_list`, `integrity_verify{from,to}` |
| Reviews | `review_monthly_prepare`, `review_monthly_sign`, `review_quarterly_prepare`, `review_quarterly_sign` |
| Policy/roles | `policy_change` (T1), `role_grant`/`role_revoke` (T1), `consent_version_create` (T1) |
| Reports | `report_accounts`, `report_pending`, `report_devices`, `report_cases`, `report_export` |
| Hygiene (lazy jobs) | `housekeeping_tick` (expire codes, stale devices, nonce purge, digest creation, archive) — idempotent, called on dashboard load; optional cPanel cron |

Every action returns `{status, data|error:{code}}`; writes require `Idempotency-Key` where retry is plausible (desk actions).

## 7. Migration plan (scripts to be written in D7, run by you in phpMyAdmin)
| File (planned) | Content | Idempotent | Rollback |
|---|---|---|---|
| `sql/070_acc_core.sql` | accounts, credentials, codes, proofing, installations, devices, sessions, proof_nonces, oauth_clients (+2 seed rows) | `CREATE TABLE IF NOT EXISTS`, seeds `INSERT IGNORE` | drop acc_* (no existing data touched) |
| `sql/071_acc_links_consents.sql` | link_overrides, consent_versions (+ v1 seeds am/en placeholders), consents, contact_channels, notices | yes | drop |
| `sql/072_acc_console.sql` | support, view_as, approval_routes (+ seed routes from D1 §4), approval_requests, delegations, policies (+ seed D1 §9), policy_history, reviews | yes | drop |
| `sql/073_acc_audit.sql` | events, event_head (seed row), event_digests, events_archive | yes | drop |
| `sql/074_acc_audit_triggers.sql` **[HOST]** | append‑only triggers (only if `priv.trigger = true`) | `DROP TRIGGER IF EXISTS` + create | drop triggers |
| `sql/075_acc_role.sql` | no DDL on `users` (role is VARCHAR — FACT); documents the new role value `accounts_office` for `admin/users.php` role list | n/a | n/a |
Zero `ALTER` on existing tables. All scripts MariaDB‑10.x‑safe, utf8mb4, no CHECK constraints relied upon.

## 8. Hosting check (owner decision 4) — deliverable
- Script: **`/home/user/acc_host_check/acc_host_check.php`** (syntax‑checked; non‑DB part executed in sandbox: EC P‑256 generate/sign/verify OK, argon2id present on PHP 8.4). It performs **only** `SELECT`/`SHOW` and in‑memory crypto; creates/writes nothing.
- Use: rename to an unguessable name → set `ACC_CHECK_KEY` to a long random string → upload next to `config.php` → open `https://<site>/<name>.php?key=…` → send me the JSON → **delete the file**.
- What the results decide:

| Result | Decision |
|---|---|
| `php.version` ≥ 8.2 | `league/oauth2-server` ^9; else 8.1 → ^8.5; < 8.1 → stop and discuss hosting |
| `openssl.ec_p256.verify = true` | ES256 proofs & tokens as designed; false → RS256 tokens + RSA device keys (plugin supports RSA on Android) |
| `password.argon2id_available` | argon2id PIN hashing; false → bcrypt + pepper |
| `db.priv.trigger` | run 074; false → chain + verification only, documented in risk register R1 |
| `db.server_version` | confirms JSON column support (MariaDB ≥ 10.2) and `DATETIME(3)`; else fall back to TEXT/DATETIME |
| `db.sql_mode`, `max_allowed_packet` | informs attestation blob size and strict‑mode handling |
| `ext.sodium`, `ext.gmp` | optional; absence changes nothing in phase 1 |
| `php.disable_functions`, `open_basedir` | confirm `openssl_*`, `random_bytes`, file access to env file |

## 8a. Hosting check — PRELIMINARY results (owner, 2026-10-18) and what they settle

| Finding (CLI, preliminary) | Status | Effect on design |
|---|---|---|
| cPanel shared hosting, Linux | FACT | Matches all shared‑hosting assumptions (no daemons, no composer on server, lazy timers; optional cPanel cron is a bonus) |
| PHP **CLI** 8.2.34 | FACT (CLI only) | If the **web** runtime is also ≥ 8.2 → `league/oauth2-server` ^9 confirmed. cPanel MultiPHP can pin a different version per domain → **must be confirmed by the web script** |
| OpenSSL 3.5.8 with prime256v1 | FACT (CLI) | ES256 device proofs and ES256 access tokens as designed; RS256 fallback not needed if web runtime matches |
| PDO MySQL, Sodium, core extensions present | FACT (CLI) | `mysqli` already used by SSMS; Sodium present ⇒ `PASSWORD_ARGON2ID` is very likely available (PHP ≥ 7.4 can provide Argon2 via libsodium) → **confirm `password.argon2id_available` in web output** |
| MariaDB **client** 11.4.13 | FACT (client) | Says nothing certain about the **server**; `SELECT VERSION()` in the web script decides JSON/`DATETIME(3)` support (needed ≥ 10.2; expected) |
| memory_limit 512 MB | FACT (CLI) | Ample for PhpSpreadsheet exports and chain verification in batches |
| Several process‑execution functions disabled (`exec`, `shell_exec`, `proc_open`, …) | FACT | **No impact**: ACC is pure PHP, never shells out. Must only confirm that `openssl_*`, `random_bytes`, `password_hash`, `hash_hmac`, `json_*` are **not** in `disable_functions` |

**Still open before D3 is final (web‑runtime script output):** `php.version` (web SAPI), `openssl.ec_p256.verify`, `password.argon2id_available`, `php.disable_functions`, `open_basedir`, `db.server_version`, `db.priv.trigger`, `db.sql_mode`, `db.max_allowed_packet`, `db.required_tables_present`, `fs.env_file_outside_webroot`.

Provisional selections (to be confirmed, not frozen): league ^9 · ES256 · argon2id · triggers **if** `priv.trigger = true` · JSON columns.

## 8b. Hosting check — CONFIRMED via read‑only SSH session (2026-10-18, agent, `felegeki@pro.etsecureserver.com:1219`)
Only `ls`, `cat`, `git status/log/diff`, `php -r` with `SELECT`/`SHOW`, `curl -I` were used. No file or database was modified. Secrets were not printed.

| Fact | Value | Design consequence |
|---|---|---|
| OS / panel | Enterprise Linux 9 (kernel 5.14 el9), cPanel, CloudLinux selector present, jailed shell | as assumed |
| **Web PHP** | EA4 default `ea-php82`; `/app/.htaccess` carries a cPanel‑generated `ea-php82` handler; no other handler overrides → **web runtime PHP 8.2** (same as CLI 8.2.34) | **`league/oauth2-server` ^9 confirmed** |
| PHP extensions (8.2) | openssl 3.5.8 (prime256v1 OK), sodium, mbstring, gmp, bcmath, mysqli, intl; `PASSWORD_ARGON2ID` **available** | **ES256 + argon2id confirmed** |
| `disable_functions` | exec, system, passthru, shell_exec, proc_open, popen, apache_child_terminate | no impact (pure PHP) |
| `open_basedir` | `{DOCROOT}:/home:/tmp:/var/tmp:…` | env file at `/home/felegeki/.fkss_env.php` readable (FACT: exists, mode 600) |
| PHP limits | memory 512M, max_execution_time 0 (CLI), upload/post 256M | ample |
| **MariaDB server** | **11.4.13‑MariaDB**, localhost | JSON, `DATETIME(3)`, InnoDB FULLTEXT, `SIGNAL` all supported |
| DB privileges | `ALL PRIVILEGES` on the single schema; **7 triggers already exist** (users/attendance/teacher_assignments) | **append‑only triggers (sql/074) confirmed possible** |
| `sql_mode` | STRICT_TRANS_TABLES, ERROR_FOR_DIVISION_BY_ZERO, NO_AUTO_CREATE_USER, NO_ENGINE_SUBSTITUTION | strict mode: all inserts must be complete (as designed) |
| Server charset | **latin1 / latin1_swedish_ci default** | every `acc_*` DDL must declare `utf8mb4` explicitly (already the rule) |
| `max_allowed_packet` | 256 MB | attestation blobs fine |
| `event_scheduler` | OFF; user crontab **empty** | lazy timers stand; cPanel cron optional later |
| Edge | **Cloudflare in front** (`server: cloudflare`), HSTS 1 year incl. subdomains | **Client IP for audit/rate‑limits must come from `CF-Connecting-IP` only when the peer is a Cloudflare address** (add to D4/D6; check how existing code derives IP) |
| Disk | 346 GB free on the volume; no user quota | fine |
| Schema size | 100 tables, 6.6 MB | tiny |
| Data | `users` 39 (34 teacher, 1 each super_admin/school_admin/hr_dept/edu_dept/mezmur_dept); **`members` = 0 rows**; `guardians` = 0; `api_refresh_sessions` 21; `app_installations` 2 | Production is live for **staff/teachers only**; member data entry has not started — the real‑user blast radius today is the staff app |
| Family Link tables | `guardians`, `member_guardians`, `guardian_aliases`, `guardian_link_suggestions` **exist** (sql/069 applied) | ACC can rely on them |

**Production code state (important):**
- `public_html` is the git checkout of `Felege-Kidusan/felegekidusan`, **HEAD = `3769a40`** (uploader). The four later commits on `main` — `95e40be` (Family Link P1–P3), `7659d7f` (P4), `00523ee` (audit), `9c6f84d` (UI overhaul) — are **not deployed**. The Family Link SQL *is* applied but the Family Link code is not; harmless today (tables unused), but ACC depends on Family Link being live.
- Local modification: `app/.htaccess` (+7 lines, cPanel‑generated `ea-php82` handler). The pending commits do **not** touch that file, so `git pull` will not conflict; keep the local change (or commit an equivalent).
- Untracked folder **`public_html/test.felegekidusan.com/`** — a plain copy of the app at `3769a40` **inside** the production working tree, **not** its own git checkout, with **no own env file** → by `config.php` search order it resolves `/home/felegeki/.fkss_env.php` and therefore **shares the production database**. The subdomain did not answer over HTTPS during the check. Treat as a risk until it gets its own DB + env file (then it becomes the staging site ACC needs).

**D3 technical selections — now FINAL:** `league/oauth2-server` ^9 · ES256 tokens/proofs · argon2id PIN hashing (+pepper) · append‑only triggers in `sql/074` · JSON columns · `DATETIME(3)` · lazy timers (cPanel cron optional) · Cloudflare‑aware client IP.

## 9. Sizing (INFERENCE, for 2,000 parent + 1,500 member accounts, 1.5 devices each)
`acc_events` ≈ 40–60 rows/account/month → ~2 M rows/year ≈ 1–1.5 GB with indexes before archive → archive at 24 months keeps the online table ≈ 2–3 GB worst case. Token endpoint peak (Sunday 8–9 am): refresh every ≤ 30 days per device, PIN/biometric unlock local → well under 1 request/s. Activation desk: 30–60 activations/hour/desk at 3–5 min each with 3 staff.

## 10. Hand‑off to D4–D7
- **D4** tracking/monitoring: alert rules, saved views, digest/anchor export format, monthly review snapshot schema.
- **D5** console wireframes (English) + app ceremony/switcher screens (Amharic).
- **D6** threat model (STRIDE on activation, pairing, reset, switcher, view‑as, approvals), test matrix (unit for SoD/chain, API contract, Playwright desks, load test for `/token`), plugin verification evidence (D2‑1 checklist).
- **D7** phased implementation plan for approval; includes the exact SQL files above and the vendoring list with licences.

**Host facts confirmed (§8b). D3 technical content is final; remaining inputs are product/organisational (see status reply 2026‑10‑18).**
