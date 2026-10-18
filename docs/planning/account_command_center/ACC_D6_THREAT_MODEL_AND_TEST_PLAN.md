# ACC D6 — Threat Model, Security Analysis & Test Plans
**Status:** PLANNING v1.0 (2026‑10‑18). Inputs: D1–D4, decision log A13/A14/A16. Marks: CONFIRMED / PROPOSED / AWAITING DECISION.

## 1. Scope & trust boundaries
```
[Parent app / Members app (Android)] --TLS via Cloudflare--> [Apache/LiteSpeed PHP 8.2: /api/parent/v1, /api/member/v1]
[Staff browser] --TLS--> [admin/acc/*.php + admin/api_acc.php]            } same PHP process space,
[Staff app] --TLS--> [/api/v1 (UNTOUCHED)]                                } one MariaDB schema
                                   |
                        [MariaDB 11.4: acc_* tables, hash chain; existing tables read‑only for ACC
                         except users.role value 'accounts_office' set via existing users.php]
                                   |
                        [/home/<acct>/.fkss_env.php: ACC_* keys/pepper]  [/home/<acct>/logs/acc/]
```
Assets: PIN verifiers, device public keys + attestation, codes (hashed), refresh‑token families, `acc_events` integrity, family visibility facts, proofing records, consent records, operator rights.
Out of scope for ACC mitigation (documented, owned elsewhere): staff auth (`/api/v1`), Family Link correctness, Cloudflare account security, cPanel account security, Android OS compromise.

## 2. Attacker profiles
A‑1 Stranger with the app (enumeration, brute force). A‑2 Thief with an unlocked/locked phone. A‑3 Household member on a shared phone. A‑4 Malicious/negligent operator. A‑5 Compromised staff web session (XSS/CSRF). A‑6 Network attacker (Cloudflare ↔ origin or Wi‑Fi at office). A‑7 Insider with DB access (hosting/admin). A‑8 Malware on the phone.

## 3. Threat register (STRIDE‑tagged) with mitigations and test IDs
| ID | Threat | Attacker | Mitigation (design) | Test |
|---|---|---|---|---|
| T‑ID‑1 | Member‑ID enumeration via activation/lookup endpoints | A‑1 | Uniform responses + timing padding; code required together with ID; per‑IP + per‑ID rate limits (`security_rate_limits`); office network allow‑list for first activation (PROPOSED: office Wi‑Fi public IP + staff hotspot carrier ranges, with manual override by operator) | S‑01, S‑02 |
| T‑ID‑2 | Guessing activation / reset codes | A‑1 | 9–10 char CSPRNG (≥ 45 bits), hashed (SHA‑256 + pepper), single‑use, 30 min / 24 h, 5 attempts per code then burned; alert AL‑5 | S‑03 |
| T‑PIN‑1 | Online PIN brute force | A‑1/A‑2/A‑3 | PIN never accepted without a valid device‑key proof (DPoP‑style signature over nonce) → attacker needs the device key too; 5 fails → 15 min, 10 → hard lock; argon2id + pepper | S‑04, F‑12 |
| T‑PIN‑2 | Offline PIN cracking after DB leak | A‑7 | argon2id (m=64 MB, t=3 — PROPOSED, tune to host) + pepper in env file outside DB; 6‑digit space is small → pepper is the real defence; document residual risk | S‑05 |
| T‑PIN‑3 | Shoulder‑surfing PIN on shared phone | A‑3 | Biometric unlock of the device key as an option; switching locks previous account; PIN entry masks; no "remember PIN" | F‑20, F‑21 |
| T‑DEV‑1 | Key extraction / cloning from a **basic** (software‑key) device | A‑8 | Classified as Basic, flagged in console, lower limits (PROPOSED: Basic devices cannot perform PIN‑reset self‑service and show warning); attestation absent → no "trusted" badge; never claimed equal assurance (D2) | D‑03, D‑06 |
| T‑DEV‑2 | Fake attestation / rooted device claiming hardware class | A‑8 | Android Key Attestation chain verified server‑side against Google root (vendored cert), challenge‑bound; failure ⇒ Basic class, never rejection (availability) | D‑04 |
| T‑DEV‑3 | Replay of a signed proof | A‑6 | Nonce from server (`acc_proof_nonces`, 2 min, single use), proof covers method+path+body hash+nonce+time; TLS | S‑06 |
| **T‑DEV‑4** | **Lost/stolen phone — revoke only the reported device (A14)**: thief uses the phone *before* the report | A‑2 | PIN still required for each session unlock (15 min idle); biometric cannot be used by thief; after report: key fingerprint blacklisted, installation's refresh families revoked, open codes cancelled; any proof from the revoked key ⇒ `device.revoked_key_attempt` + AL‑2; other devices get notice | R‑01, R‑02, S‑07 |
| **T‑DEV‑5** | Revoked device **re‑registers silently** | A‑2 | Re‑enrolment requires a new office‑issued add‑device code **and** a new key pair; the revoked public‑key hash stays blacklisted for life of account; the same `installation_id` is refused; attestation cert chain serial of revoked key recorded (hardware devices) | R‑03 |
| T‑DEV‑6 | Residual risk under A14: thief who **also knows the PIN** and acts before the report can read family data and (on a Basic device) possibly keep a cloned software key | A‑2 | Cannot be fully mitigated by device‑only revoke. Mitigations: idle re‑lock, notices to other devices on every new session, PIN‑reset recommended by operator at report time (operator‑offered, not automatic). **Agent recommendation (not a change of A14): when the reported device is Basic *and* the reporter cannot exclude PIN disclosure, the operator should be *offered* "also reset PIN" as a separate explicit action.** Owner confirmation requested in D7 §10 Q3 | R‑04 |
| T‑DEV‑7 | Device replacement when the lost device was the only device | A‑2 | Recovery = office visit (proofing) → add‑device code; or registered recovery channel (D1 §8) | R‑05 |
| T‑SES‑1 | Refresh‑token theft/replay | A‑8/A‑6 | Rotation with family revocation on reuse (league + custom repo), tokens bound to device key (cnf claim), 15 min access tokens | S‑08 |
| T‑SES‑2 | Cross‑tenant data in multi‑account phone | A‑3 | Per‑account key + storage namespace in app; server scopes every query by `account_id` from token; sibling data only via `member_guardians` | F‑22, S‑09 |
| T‑OPS‑1 | Operator misuse (single‑operator model, A9) | A‑4 | Every action logged with reason; S1–S3 flags; reviewer ≠ actor; AL‑5/AL‑9; monthly pack with operator stats; operator cannot act on own/relatives' accounts? (PROPOSED: block when `users.member_id` ∈ family of target) | S‑10, F‑30 |
| T‑OPS‑2 | Operator grants themselves ACC rights | A‑4 | Rights live in `users.role` managed only by super_admin via existing users.php (A8); ACC never writes `users`; AL‑9 snapshot diff | S‑11 |
| T‑OPS‑3 | View‑as abuse | A‑4 | 15 min, reason, read‑only, every read logged, 24 h review (D1) | F‑31, S‑12 |
| T‑WEB‑1 | CSRF/XSS on console | A‑5 | Existing dashboard CSRF token pattern (`CSRF_TOKEN`), `X‑Requested‑With`, CSP for `admin/acc/` (PROPOSED strict CSP, no inline), output escaping, JSON via `json_encode` | S‑13, S‑14 |
| T‑WEB‑2 | IDOR between accounts in console/API | A‑4/A‑1 | UUID public ids, server‑side scope checks, tests per endpoint | S‑15 |
| T‑NET‑1 | IP spoofing of `CF-Connecting-IP` | A‑6 | Trust header only when `REMOTE_ADDR` ∈ Cloudflare ranges (D4 §1) | S‑16 |
| T‑NET‑2 | Office Wi‑Fi hostile client during activation | A‑6 | Activation still needs operator‑issued code + proofing; TLS; no plaintext | S‑02 |
| T‑AUD‑1 | Tampering with audit trail | A‑7/A‑4 | Append‑only triggers (`sql/074`, CONFIRMED possible on host), hash chain with head table, hourly verifier, daily digest copied to `ssms_secure_backups`; DB user is shared with the app (limitation — documented) | S‑17 |
| T‑AUD‑2 | Secrets leaking into logs | dev | Allow‑listed `details` keys; test scans events for 6‑digit PIN patterns/codes/tokens | S‑18 |
| T‑SYS‑1 | Shared DB user = ACC compromise ⇒ whole SSMS compromise (and vice versa) | A‑5/A‑7 | Hosting limitation (single DB user). Mitigation: no new attack surface in `/api/v1`; ACC endpoints fail closed; secrets in env file not DB | documented |
| T‑SYS‑2 | ACC code change breaks staff auth | dev | Hard boundary: no edits to `api/v1/core/*`, `RefreshTokenService`, `login.php`, `api_guardians.php`; regression suite gate (§7) | RG‑01…RG‑05 |
| T‑PRIV‑1 | Child data exposed to wrong guardian after custody change | HR data | Family Link remains authority; ACC `acc_link_overrides` only hides; custody removal by school_admin logged S2; app refreshes visibility on every token refresh | F‑40 |
| T‑CONS‑1 | Unapproved consent text treated as official | org | Consent versions carry `approved_by/approved_at`; app refuses to show a version without approval outside test builds; DRAFT watermark (A11) | F‑41 |

## 4. Security analysis of reuse decisions (A16 — "do not assume reuse")
| Existing mechanism | Reviewed behaviour (FACT) | Decision |
|---|---|---|
| Staff HMAC tokens (`createToken`) | base64 JSON + HMAC‑SHA256, no key id, no audience, `rol` from users | **Not reused** for parents/members (no device binding, single shared secret, no rotation keys). ACC uses ES256 JWT via league with `aud` per app |
| `RefreshTokenService` | staff sessions table, rotate/revoke per user | **Not reused**; ACC families in `acc_sessions` (device‑bound). Untouched |
| `SecurityRateLimiter` + `security_rate_limits` | generic key/window store with file fallback | **Reused as a library** (read/write own keys with `acc:` prefix) — no schema change |
| `SecurityAuditService` / `activity_logs` | staff‑oriented, not hash‑chained | **Not reused** for ACC events; ACC may *also* emit a one‑line summary into `activity_logs` for the existing monitor pages (PROPOSED, optional) |
| `access_control.php` ROLE_MAP | script → roles, deny default | **Reused**: add ACC scripts with `['accounts_office']` (+ school_admin for the reports/sign‑off pages). No change to existing entries |
| `users.php` / `user-save.php` | super_admin/school_admin create any role | **Reused** to create operators with new role `accounts_office` (A8). Note: school_admin can also create users with this form today — AWAITING DECISION whether creating `accounts_office` should be restricted to super_admin only (one‑line check in `$rolePermissions`) |
| `AccountCredentialService` | staff password reset + revoke staff sessions | **Not reused** (PIN model differs) |
| `FailureAlertService` | alerts table + delivery | **Reused** for AL‑x delivery |
| `NotificationCenterService` | staff audiences only | **Not reused** for parent/member notices (`acc_notices`) |
| Family Link `GuardianService` | HR writes | **Read‑only reuse** of queries; no write calls from ACC |

## 5. Test strategy & tooling (CONFIRMED tools already in repo: pytest suites under `tests/`, Playwright browser E2E, PHP contract tests; PHP built‑in server for API E2E)
Environments: sandbox (unit/contract/API E2E against local MariaDB) → **dedicated testing server with demo data** (owner‑operated; device tests, browser E2E) → production (smoke only, after D7 approval and deployment window).
Never on production: load tests, brute‑force tests, chain‑break tests.

## 6. Device test matrix (A13)
Devices: **HW** = modern Android with hardware‑backed keystore (StrongBox or TEE); **BASIC** = older/low‑cost Android (hardware support UNKNOWN until verified); **SHARED** = not yet confirmed (tests D‑20…D‑24 planned, blocked until a device is named).

| ID | Test | HW | BASIC | SHARED |
|---|---|---|---|---|
| D‑01 | Verify security class via `biometric_signature`/KeyInfo: `isInsideSecureHardware`, StrongBox flag, attestation security level → record actual result (never assumed) | ✔ | ✔ | ✔ |
| D‑02 | Device‑bound key registration at activation (code + ID), server stores class + attestation result | ✔ | ✔ | ✔ |
| D‑03 | Basic device shows "basic device" notice; console shows class; no trusted badge | — | ✔ | ✔ |
| D‑04 | Attestation chain validation: valid HW chain → Hardware; tampered chain → Basic (not rejected); missing → Basic | ✔ | ✔ | — |
| D‑05 | PIN verification requires proof: valid PIN + wrong key ⇒ denied; wrong PIN ×5 ⇒ 15 min; ×10 ⇒ hard lock; unlock by operator | ✔ | ✔ | ✔ |
| D‑06 | Biometric‑gated key use (HW) and fallback behaviour when biometrics change (key invalidated ⇒ re‑enrol flow) | ✔ | if supported | — |
| D‑07 | Device revocation (reported device only): revoked device proof ⇒ denied + AL‑2; second device unaffected | ✔ | ✔ | ✔ |
| D‑08 | Revoked device cannot re‑register with old key / same installation; new add‑device code + new key succeeds | ✔ | ✔ | — |
| D‑09 | Device replacement: add‑device code (24 h, single use) on new phone; old device remains until revoked; max 3 active enforced | ✔ | ✔ | — |
| D‑10 | App uninstall/reinstall: keys gone ⇒ graceful "device needs re‑activation" (no crash, no silent new trust) | ✔ | ✔ | — |
| D‑11 | Backup/restore to another phone: restored app data without keys ⇒ treated as new device | ✔ | — | — |
| D‑12 | Offline/poor network: proof nonce expiry handling, retries idempotent | ✔ | ✔ | — |
| D‑13 | Clock skew ±5 min tolerated; larger ⇒ clear error | ✔ | ✔ | — |
| D‑20 | Two parent accounts + one member account on one phone: separate keys, PINs, storage namespaces | — | — | ✔ |
| D‑21 | Switching locks previous account; no data bleed in UI caches/notifications | — | — | ✔ |
| D‑22 | Revoke one account's device entry ⇒ other accounts on the same phone unaffected | — | — | ✔ |
| D‑23 | PIN reset of one account ⇒ others unaffected; notices routed to correct account | — | — | ✔ |
| D‑24 | FCM token (parent app) per account on shared phone — correct routing | — | — | ✔ |
Exit criteria: all HW/BASIC rows pass on the two named devices; SHARED rows pass once a device is confirmed (A13) — otherwise shared‑phone switcher ships behind a feature flag (PROPOSED).

## 7. Test plans (IDs referenced in §3)
**Functional (F)** — F‑01 activation happy path; F‑02 code expiry 30 min; F‑03 code single‑use; F‑04 activation refused when Family Link relation missing/unverified; F‑05 sibling visibility via `member_guardians`; F‑06 account states transitions (PROVISIONED→…→CLOSED) with illegal transitions rejected; F‑10 PIN set/change; F‑12 lockout timers; F‑20/21/22 switcher; F‑30 operator reason mandatory; F‑31 view‑as time‑box; F‑40 custody removal hides child on next refresh; F‑41 consent version gating; F‑50 lazy jobs idempotent; F‑60 monthly pack generation; F‑61 alerts AL‑1…AL‑9 fire with synthetic events.
**Security (S)** — S‑01 enumeration uniformity (status, size, timing ± 20 ms); S‑02 activation off‑network behaviour; S‑03 code brute force burns after 5; S‑04 PIN online brute force; S‑05 verifier format argon2id + pepper present; S‑06 proof replay denied; S‑07 revoked key denied + alert; S‑08 refresh reuse ⇒ family revoked; S‑09 cross‑account IDOR in API; S‑10 operator action without reason denied; S‑11 ACC never writes `users` (static grep + runtime assertion); S‑12 view‑as writes denied; S‑13 CSRF; S‑14 CSP/XSS probes; S‑15 console IDOR; S‑16 IP header trust; S‑17 chain tamper detection (test DB only); S‑18 secret‑pattern scan of `acc_events`; S‑19 dependency audit (`composer audit` offline list) for league/lcobucci/web‑auth/monolog; S‑20 error disclosure (`test_error_disclosure` pattern: no `$e->getMessage()` in JSON).
**Recovery (R)** — R‑01 lost phone report → single device revoked, others active; R‑02 notices delivered to remaining devices; R‑03 revoked device re‑registration attempts (same key / same installation / new key without code) all denied; R‑04 Basic‑device + PIN‑disclosure scenario (documents residual risk; checks operator is offered PIN reset); R‑05 only‑device loss → office visit flow; R‑06 PIN reset revokes all sessions; R‑07 account unlock; R‑08 recovery channel (e‑mail/Telegram) flow if registered; R‑09 operator break‑glass full cycle incl. AL‑3 and 3‑day review; R‑10 restore `acc_events` archive + digest verification.
**Regression (RG)** — gate before any ACC merge: RG‑01 existing full suite (last green 2140 passed / 435 skipped) must stay green; RG‑02 API E2E 104/104; RG‑03 browser E2E 27/27; RG‑04 staff login + `/api/v1/auth` login/refresh/logout contract unchanged (snapshot test of responses); RG‑05 Family Link API contract unchanged (existing E2E `family_link_e2e.py`); RG‑06 `access_control.php` ROLE_MAP diff shows only additions; RG‑07 `git diff --stat` of protected paths is empty (`api/v1/core/`, `admin/backend/services/RefreshTokenService.php`, `admin/backend/login.php`, `admin/api_guardians.php`, `GuardianService.php`).

## 8. Open security questions → D7 §10
Q1 office network allow‑list feasibility (public IP of the office?) · Q2 argon2id cost on host (measure on test server) · Q3 Basic‑device + PIN disclosure under A14 (offer PIN reset?) · Q4 restrict `accounts_office` creation to super_admin only · Q5 Cloudflare IP restoration at server level (UNKNOWN) · Q6 recovery‑channel availability (e‑mail from host, Telegram bot ownership).
