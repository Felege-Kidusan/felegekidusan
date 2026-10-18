# ACC D7 — Implementation Plan, Dependencies & Rollout (APPROVAL GATE)
**Status:** PRESENTED FOR OWNER REVIEW — 2026‑10‑18. **Nothing is implemented. No schema, migration, deployment or production change is authorised by this document.**
Every line is marked **CONFIRMED** (owner decision on record), **PROPOSED** (agent recommendation — needs yes/no), or **AWAITING DECISION** (owner must choose).
Companion documents: D1 (requirements, frozen), D2 (+A/B/C, decision record), D3 (data & API, host facts §8b), D4 (monitoring/audit/incident), D5 (wireframes + `acc_wireframes/acc_wireframes.html`), D6 (threat model & tests), decision log 2026‑10‑18, `PARENT_APP_PHASE1_INPUTS.md`, `MEMBERS_APP_FEATURE_PROPOSAL.md`.

---
## 1. Architecture & integration plan
### 1.1 Boundaries (CONFIRMED by A16; facts from repo `9c6f84d`)
| Existing component | ACC relationship | Protected? |
|---|---|---|
| Staff web login (`admin/index.php`, `admin/backend/login.php`, sessions, `AdminSessionGuard`) | ACC console pages are ordinary admin pages behind the **same** staff session; ACC adds pages, changes nothing in login | **Yes — no edits** |
| `/api/v1` staff API (`api/v1/core/*`, `RefreshTokenService`, HMAC tokens) | **Not used, not modified.** Parent/member apps use new namespaces `/api/parent/v1`, `/api/member/v1` with their own router file and token stack | **Yes — no edits** |
| `access_control.php` ROLE_MAP | **Additive only**: new entries for `admin/acc/*.php` and `admin/api_acc.php` | Additions only (RG‑06) |
| `admin/users.php` + `backend/user-save.php` | **Reused to create operators** (A8): add role value `accounts_office` to `$validRoles`, label in `users.php` dropdown, and `$rolePermissions` | Minimal additive edit (2 arrays + 1 label) — PROPOSED; see Q4 |
| Family Link (`api_guardians.php`, `GuardianService`, tables) | **Read‑only** queries on `guardians`, `member_guardians`, `guardian_aliases` | **Yes — no write calls, no edits** |
| `members`, `users`, `class_enrollments`, `attendance`, … | Read‑only for ACC and for the parent/member data APIs | No edits |
| `SecurityRateLimiter` / `security_rate_limits` | Reused as a library with `acc:` keys | No edits |
| `FailureAlertService` / `failure_alerts` | Reused for alert delivery | No edits |
| `FeatureGate` | New constants `FEATURE_ACC`, `FEATURE_PARENT_API`, `FEATURE_MEMBER_API` (false by default) — additive | Additive |
| `.fkss_env.php` | New constants `ACC_TOKEN_PRIVATE_KEY_PATH`, `ACC_PIN_PEPPER`, `ACC_CODE_PEPPER`, `ACC_AUDIT_KEY` — **added by owner**, never committed | Owner action |

### 1.2 New code (all new files; PROPOSED layout)
```
admin/acc/                      console pages (W1–W12)            admin/api_acc.php   console JSON actions (D3 §6)
admin/backend/services/acc/     AccountService, ActivationService, CodeService, DeviceService, PinService,
                                ProofVerifier (nonce+ES256), AttestationVerifier, SessionService (league repos),
                                EventWriter (hash chain), ReviewService, AlertEvaluator, JobRunner, MonthlyPackBuilder,
                                ClientIp (Cloudflare‑aware), Policies
api/parent/v1/index.php + routes/   api/member/v1/index.php + routes/   api/acc_common/ (shared middleware)
sql/070_acc_core.sql … 075_acc_seed_policies.sql (D3 §7)        tests/acc/ (pytest + PHP contract)
Mobile/fkss_parent_app/ , Mobile/fkss_members_app/ (new Flutter projects; shared package Mobile/fkss_account_core/)
```
### 1.3 Libraries (vendored via composer locally, committed with `git add -f` as today; pure PHP, PHP 8.2 — CONFIRMED by host check)
| Package | Version | Licence | Purpose | Mark |
|---|---|---|---|---|
| league/oauth2-server | ^9.4 | MIT | token issuing, refresh rotation, custom device‑assertion grant | CONFIRMED (D0/D3) |
| lcobucci/jwt | ^5 | BSD‑3 | ES256 JWT (used by league) | CONFIRMED |
| nyholm/psr7 + nyholm/psr7-server | ^1.8 | MIT | PSR‑7 bridge for league | CONFIRMED |
| phpseclib/phpseclib | ^3 | MIT | X.509 parsing of Android key‑attestation chains, ECDSA verify helper | PROPOSED |
| monolog/monolog | ^3 | MIT | file logging | CONFIRMED |
| (no) symfony/rate-limiter | — | — | replaced by existing `SecurityRateLimiter` | PROPOSED |
| (no) otphp / web‑auth | — | — | no OTP factor (owner), no WebAuthn in Phase 1 (device‑key model chosen in D2) | CONFIRMED |
Flutter (both apps): `biometric_signature` ^13 (verified publisher; Android SDK ≥ 23, compileSdk 35), `flutter_secure_storage`, `mobile_scanner` (QR), `dio`, `firebase_messaging` (parent app only, A4), `intl` + Ethiopic font (existing). **Verification condition from D2‑1 (plugin maintenance, compatibility, hardware support, limits) is a Phase 0 task, not yet done** — CONFIRMED condition.

---
## 2. Parent / member account lifecycle & recovery flows (CONFIRMED unless marked)
| Flow | Steps | Mark |
|---|---|---|
| Provision | HR Family Link verified relation (parent) or active member record (member) ⇒ ACC creates `acc_accounts` PROVISIONED on first office contact (not in bulk) | CONFIRMED (D1) |
| Office activation | Identify by child's/own member ID → proofing record → consent (DRAFT until approved, A11) → single‑use 30‑min code → phone on office Wi‑Fi/hotspot → device key + attestation → PIN set → ACTIVE | CONFIRMED (D2 final, A11) |
| Daily login | PIN + device‑key proof; 15 min access token; refresh family 30 d sliding / 180 d absolute | CONFIRMED (D1 §9) |
| Add device | office or registered recovery channel ⇒ add‑device code 24 h single‑use; max 3 active devices | CONFIRMED |
| **Lost/stolen phone** | Report (in person / phone call with knowledge check / other device) ⇒ **revoke only that device** (key blacklisted, its sessions revoked, codes cancelled), notices to other devices, audit S1; replacement via add‑device code; revoked key use ⇒ AL‑2 | **CONFIRMED (A14)**; residual risk T‑DEV‑6 → Q3 |
| PIN reset | proofing ⇒ 24 h single‑use code ⇒ new PIN on device ⇒ all sessions revoked ⇒ notices | CONFIRMED |
| Lock / unlock | auto after 10 fails or operator; unlock by operator after proofing | CONFIRMED |
| Suspend / close | suspend by operator with reason; close on request (T0) or custody/legal by school_admin (S2); CLOSED never deleted; minimisation after 12 months | CONFIRMED (D1) |
| Custody change | HR changes Family Link ⇒ visibility recalculated on next refresh; ACC override only hides | CONFIRMED |
| Shared phone | switcher in Phase 1; one account per key; switching locks previous | CONFIRMED (owner final answers) |
| Recovery channels | optional verified e‑mail/Telegram registered inside a trusted session; used for add‑device/PIN‑reset codes | CONFIRMED (D1 §8); availability Q6 |

---
## 3. Role & permission matrix (A8, A9)
| Capability | accounts_office | school_admin | super_admin | hr_dept | others |
|---|---|---|---|---|---|
| Open ACC console | ✔ | reports / review / sign‑off pages only | **break‑glass only** (W10) | status chip in Family Link | ✖ |
| Lookup, timeline, support cases | ✔ (S0) | read | break‑glass | read status | ✖ |
| Activation, codes, lock/unlock, suspend, close‑on‑request, device revoke, notices | ✔ single operator, logged, S1 flag | ✖ | break‑glass | ✖ | ✖ |
| View‑as (15 min, reason) | ✔ S2 | ✖ | break‑glass | ✖ | ✖ |
| Visibility override / custody removal | override: ✔ S2 · custody removal: ✖ | custody removal ✔ S2 | break‑glass | ✖ | ✖ |
| Review queue ack | ✔ (not own actions) | ✔ | ✔ | ✖ | ✖ |
| Monthly sign‑off, quarterly rights review | ✖ | ✔ | ✖ | ✖ | ✖ |
| Policies edit | ✖ | ✖ | ✔ S2 | ✖ | ✖ |
| Grant/remove `accounts_office` role | ✖ (ACC never writes `users`) | existing users.php allows — see Q4 | ✔ via existing users.php | ✖ | ✖ |
| Family relation writes | ✖ | ✖ | ✖ | ✔ Family Link (existing) | ✖ |
Marks: structure CONFIRMED (A8/A9/D1); **residual two‑person set PROPOSED**: (1) bulk ≥10 accounts/≥20 devices, (2) data exports, (3) policy changes — second operator confirms before execution; everything else single‑operator + flag. → **AWAITING DECISION Q2**. Operator count ≥4 CONFIRMED; names pending (A8).

---
## 4. Device trust & PIN security design (summary; detail D2/D3/D6)
| Element | Design | Mark |
|---|---|---|
| Device key | P‑256 ECDSA generated on device (`biometric_signature`), non‑exportable; public key + Android attestation sent at enrolment; server classifies **Hardware‑backed** (StrongBox/TEE with valid chain) or **Basic** (software or unverifiable) — never equal assurance, never rejected | CONFIRMED (D2) |
| Proof | ES256 signature over `nonce|method|path|sha256(body)|ts`; nonce 2 min single‑use; required for login, refresh, sensitive reads | CONFIRMED |
| Tokens | league ES256 JWT, `aud` = `fkss-parent-app` / `fkss-members-app`, `sub` = account UUID, `cnf` = device key thumbprint, `av` version | CONFIRMED |
| PIN | 6 digits, argon2id + server pepper, verified only with valid proof; 5/15 min, 10/hard lock; biometric only unlocks the key, never replaces server PIN check | CONFIRMED |
| Codes | CSPRNG ≥45 bits, hashed+peppered, single‑use, 30 min (activation) / 24 h (others), 5 tries then burned | CONFIRMED |
| Basic device limits | no self‑service PIN reset via app; console warning; counts in monthly pack | PROPOSED |
| Revocation | per device (A14); key‑hash blacklist for account life; installation id refused; AL‑2 on reuse | CONFIRMED |
| Min Android | API 23 (plugin requirement); older test phone must be ≥ Android 6 — verify (D‑01) | CONFIRMED constraint |

---
## 5. Threat model & mitigations
Full register D6 §3 (T‑ID, T‑PIN, T‑DEV, T‑SES, T‑OPS, T‑WEB, T‑NET, T‑AUD, T‑SYS, T‑PRIV, T‑CONS) with test IDs. Accepted residual risks to acknowledge at approval: **R‑a** shared DB user (hosting); **R‑b** Basic devices (software keys); **R‑c** A14 window before report (T‑DEV‑6); **R‑d** 6‑digit PIN space defended by pepper; **R‑e** Cloudflare IP UNKNOWN. Mark: register PROPOSED; residual risks AWAITING acknowledgement.

---
## 6. Audit logging, monthly review, urgent incident response
D4 in full. Summary: write‑then‑ack hash‑chained `acc_events`; S0–S3 classes with flags (A9); alerts AL‑1…AL‑9 continuous via `FailureAlertService` (A10); incident runbook with CRITICAL 1 h / HIGH 4 h / MEDIUM next business day (PROPOSED); monthly pack by the 7th signed by school_admin (CONFIRMED), quarterly rights review (CONFIRMED). Alert thresholds and duty roster → AWAITING DECISION Q7.

## 7. Wireframes
D5 + `acc_wireframes/acc_wireframes.html` (W1–W12, M1–M4). Mark: PROPOSED.

## 8. Test plans
D6 §5–§7: functional F, security S, recovery R, regression RG, device matrix D‑01…D‑24 (A13). Shared‑phone rows wait for a confirmed device. Mark: PROPOSED; exit criteria PROPOSED.

---
## 9. Deployment, rollback & operations (shared hosting, A3)
### 9.1 Phases (PROPOSED; durations are working‑day estimates for one developer, sequential)
| Phase | Content | Depends on | Est. | Exit gate |
|---|---|---|---|---|
| **P0 Verification** | D2‑1 plugin verification on the two test devices (D‑01…D‑04 spike), argon2id cost measurement on the **test server**, office network facts (Q1), composer vendoring of libraries locally, `.fkss_env.php` key list for owner | D7 approval | 4 d | written verification note; owner confirms plugin acceptable |
| **P1 Core + console (staff only)** | sql/070–075 on test server; services; `admin/acc/*` W1–W3, W7–W9, W10; ROLE_MAP additions; `accounts_office` role option; EventWriter + chain verifier; alerts; regression gate RG‑01…07 | P0 | 12 d | full suite green; console usable with demo data on test server |
| **P2 Parent API + app core** | `/api/parent/v1` (activate, pin, device, session, family, notices); Flutter `fkss_account_core` + Parent app shell (M1–M4); FCM registration (data only) | P1 | 15 d | D‑ and F‑ tests on HW+BASIC devices pass on test server |
| **P3 Member API + app core** | `/api/member/v1`; Members app shell; (features per `MEMBERS_APP_FEATURE_PROPOSAL.md` are a **separate** approval) | P2 | 6 d | same |
| **P4 Pilot** | Production deploy **behind feature flags OFF**; owner runs migrations in phpMyAdmin (scripts supplied, idempotent, utf8mb4); flags ON for console only; create 4+ operators via users.php; activate **≤10 pilot families + ≤10 members** in office; 2‑week observation; first monthly pack | P2/P3 + owner go | 2 d + 2 wk | pilot report; owner go for general use |
| **P5 General rollout** | flags ON for apps; APK published via existing release channel (A7) with controlled update; operators trained; incident roster live | P4 | 2 d | — |
Parent **features** beyond account/login (baseline §1–§19) and Member features are planned/approved separately after P2/P3 (see companion documents).

### 9.2 Deployment mechanics (CONFIRMED practice + PROPOSED details)
- Code: agent pushes to both remotes → **owner runs `git pull`** on production (CONFIRMED). Deploy window: outside office hours; staff app unaffected (no `/api/v1` changes).
- DB: owner runs `sql/070…075` in phpMyAdmin in order; each script idempotent (`CREATE TABLE IF NOT EXISTS`, `ssms_add_column_if_missing`, `DROP TRIGGER IF EXISTS` + `CREATE TRIGGER`); all DDL `utf8mb4` (host default is latin1 — CONFIRMED); no change to existing tables.
- Secrets: owner adds `ACC_*` constants and an ES256 private key file under `/home/<acct>/` (outside web root, mode 600); agent supplies generation commands; keys never leave the server.
- Flags: `FEATURE_ACC`, `FEATURE_PARENT_API`, `FEATURE_MEMBER_API` in env file; default false ⇒ ACC routes return 403 and console pages are hidden.
- Backups: before each migration, owner exports the schema via existing `BackupService`/phpMyAdmin to `ssms_secure_backups` (CONFIRMED location exists).
- Jobs: lazy on‑request runner (no cron required); optional cPanel cron every 5 min for alert evaluation — **AWAITING DECISION Q8**.
- Monitoring after deploy: D4 overview + `logs/acc/*.log`; smoke tests only on production (login page loads, `/api/parent/v1/ping` with flag off ⇒ 403).

### 9.3 Rollback (PROPOSED)
| Level | Action | Data |
|---|---|---|
| L1 flag | set `FEATURE_*` false ⇒ ACC inert within one request; staff system untouched | kept |
| L2 code | `git checkout <previous tag>` on server (owner) | kept |
| L3 schema | `sql/rollback/07x_down.sql` drop `acc_*` tables/triggers only (supplied with each migration); never touches existing tables | ACC data lost — only acceptable before pilot |
| Apps | APK channel: previous APK re‑published; apps tolerate 403 with a friendly "service paused" screen | — |

### 9.4 Operations (PROPOSED)
Operator onboarding checklist; monthly pack calendar (by the 7th); quarterly rights review; key rotation yearly (ES256 key with `kid`, old key kept for verification 30 d); dependency review quarterly (`composer audit` offline); test‑server refresh of demo data before each phase.

---
## 10. Unresolved decisions, assumptions, risks, required inputs
### 10.1 AWAITING DECISION (owner)
| # | Question | Options / recommendation |
|---|---|---|
| Q1 | Office network allow‑list for first activation — is the office public IP static? | (a) static IP allow‑list + operator override (recommended if static); (b) no network check, rely on code + proofing |
| Q2 | Residual two‑person set under A9 | recommended: bulk, exports, policy changes only |
| Q3 | Under A14: when the reported device is **Basic** and PIN disclosure cannot be excluded, may the operator be **offered** "also reset PIN" as a separate explicit action? (not automatic, not revoke‑all) | recommended: yes |
| Q4 | Restrict creation of `accounts_office` users to super_admin only (today school_admin can create any role via users.php) | recommended: yes (one‑line additive check) |
| Q5 | Lesson‑delivery confirmation workflow for the parent app | see `PARENT_APP_PHASE1_INPUTS.md` §3 (recommend Option A) |
| Q6 | Recovery channels: is outbound e‑mail from the host usable? Does the school own a Telegram bot? | determines whether R‑08 exists in Phase 1 |
| Q7 | Alert thresholds AL‑1…AL‑9, severity targets, who is on duty outside office hours | accept proposed defaults or edit |
| Q8 | Add a cPanel cron (every 5 min) for alert evaluation? | recommended: yes, read‑only PHP CLI script; otherwise alerts evaluate on next request |
| Q9 | Member app feature proposal — which of the 6 areas enter Phase 1? | `MEMBERS_APP_FEATURE_PROPOSAL.md` |
| Q10 | Shared‑phone test device — which phone, when? | shared‑phone switcher ships behind a flag until D‑20…D‑24 pass |
| Q11 | SSH key: de‑authorise now? | recommended: yes (A15 assessment) |
### 10.2 Assumptions (to verify in P0)
A‑i `biometric_signature` behaves as documented on both test devices; A‑ii office Wi‑Fi can reach the production host over HTTPS; A‑iii the test server mirrors production PHP 8.2 / MariaDB 11.4 (UNKNOWN — please confirm versions); A‑iv argon2id at m=64 MB fits within 512 MB and typical web timeouts; A‑v production already at `9c6f84d` (owner statement).
### 10.3 Risks (top)
R‑1 plugin or device fails verification ⇒ fallback = software keys everywhere (lower assurance) or delay; R‑2 operator availability (≥4 named people, training); R‑3 Amharic consent approval timing blocks pilot (A11); R‑4 shared DB user; R‑5 Cloudflare IP handling UNKNOWN affects rate‑limit quality; R‑6 scope creep from parent/member features into ACC phases.
### 10.4 Required organisational inputs
Names of ≥4 accounts office staff (after approval, created by super_admin); approved Amharic consent text; office public IP (Q1); test‑server access details for the developer (versions, demo data refresh method); shared‑phone device; decision on e‑mail/Telegram (Q6); pilot family/member list (≤10 each).

---
## STOP — awaiting explicit owner approval of D7
No implementation, database change, migration or deployment will start until the owner approves this plan (with answers to Q1–Q11 or explicit deferral of any of them).
