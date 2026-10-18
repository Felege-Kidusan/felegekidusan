# D2 Decision Record (D2‑1, D2‑3) + Shared‑Phone Assessment + Remaining Risk Register

**Date:** 2026-10-18 · **Status:** decisions recorded as given by the owner; risk register for review before D3. **No code.**
Labels: **FACT** · **INFERENCE** · **UNKNOWN**.

---

## 1. Decisions recorded (owner, verbatim intent)

| ID | Decision | Conditions attached by owner | Where it lands |
|---|---|---|---|
| **D2‑1** Trusted device security | **ACCEPT** — device‑bound keys in Android Keystore / iOS Secure Enclave, biometric‑gated, signed per‑request proofs, 6‑digit PIN second factor | Developer must verify, **before implementation**: plugin maintenance health, Android/iOS compatibility, hardware‑backed key support, security limitations (checklist §4) | D2 master §3; D7 pre‑implementation gate |
| **D2‑3a** First activation | **ACCEPT** — at the registration office only | Code **single‑use**, **expires after 30 minutes**, **consumed during in‑person identity verification** | D1 §9 (TTL 72 h → 30 min, change log v1.2); D2‑A/B flow A |
| **D2‑3b** Add‑device & PIN‑reset codes | **ACCEPT WITH CONDITIONS** — 24 h expiry allowed | Each code: single‑use; CSPRNG‑generated; stored only as a hash; rate‑limited on entry; invalidated when used **or replaced** by a newer code of the same purpose; PIN reset **revokes all existing sessions** and **notifies the user in‑app when possible** | D1 §7 codes row; D2 master §2 ActivationService; D3 table `acc_codes` |
| **D2‑3c** One guardian account per phone install | **NOT FINALISED** | Assess shared family phones, multiple guardians, several children; do not impose if it creates unnecessary problems | §2 below → carried to D3/D5 |
| **D2‑3d** Phones without hardware security chip | **ACCEPT WITH CONDITIONS** | Supported via a clearly documented fallback; **never claimed equal** to hardware‑backed assurance; appropriate risk controls; limitations explained | §3 below; D2 master §3 |
| Standing | **No code changes until the implementation plan (D7) is approved** | — | all |

Document updates made now: D1 → v1.2 (code TTL + code conditions + session‑revoke/notify wording); D2 master §11 → D2‑1/D2‑3 marked decided with conditions.

---

## 2. D2‑3c assessment — shared family phones, multiple guardians, several children

### 2.1 Scenarios (INFERENCE on local reality; numbers UNKNOWN)

| Scenario | What families need | Does "one account per install" hurt? |
|---|---|---|
| **S1** Two guardians, each with a phone, several children | Each guardian has own account; each sees all linked children (Family Link) | No |
| **S2** One guardian with a phone, the other without | One account; the other guardian is simply not enrolled | No |
| **S3** **One shared phone, two guardians** (common where only one smartphone exists) | Both want to be "the parent" in the app: feedback to the teacher signed by the right person, notices addressed correctly, consent per person | **Yes, if restricted:** the second guardian is invisible to the school's records even though they use the app daily; teacher feedback and audit are attributed to the wrong person |
| **S4** Several children, one guardian | One account shows all children | No (already solved by Family Link) |
| **S5** Grandparent/aunt as additional guardian with own phone | Own account after HR links them | No |
| **S6** Phone passed between relatives who are *not* guardians | Should not work | Restriction irrelevant; protection comes from PIN + biometric |

**Conclusion:** the restriction only matters in **S3**, and there it causes a real problem (mis‑attribution, exclusion of one parent). It should **not** be imposed at the server/data level.

### 2.2 What makes multi‑account on one phone technically clean (FACT)
- The chosen plugin supports **named key aliases** — multiple independent hardware key pairs per app install [FACT: `biometric_signature` "Named Key Aliases"] → one key per account on the same phone.
- Therefore the data model is simply **device ↔ account = many‑to‑many** (`acc_devices` row per *account+phone* pair, each with its own public key). No special "shared phone" concept is needed on the server.
- The phone's biometric cannot tell mother from father on a shared phone (both may have enrolled fingerprints). On a shared phone the **PIN is the identity factor between family members**; the biometric still proves *possession of that phone*. This is acceptable within a household and must be stated in the documentation.

### 2.3 Recommended rule (for D3/D5, not frozen)
1. **Server:** no limit of one account per phone. Limit: a phone may hold accounts only for guardians who **share at least one linked child** (prevents a phone becoming a bundle of unrelated families); max 3 devices per account remains.
2. **Each guardian is still activated separately at the office** with their own proofing record and own PIN — a shared phone does not merge identities.
3. **App:** account switcher ("Who is using the app?") requiring that account's PIN; notices and feedback are attributed to the active account; sensitive screens re‑prompt PIN. Phase‑1 UI may ship single‑account first and add the switcher in phase 2 — the server supports both from day one, so no migration later.
4. **Custody caveat (outside technology):** if the school admin removes one guardian's access while both still live in the same household and share the phone, the removed guardian can physically use the other's account — exactly as with a paper report card. Documented as a residual, non‑technical risk; the console shows the shared‑device situation so staff can advise.

---

## 3. D2‑3d — software‑backed key fallback (documented limitations and controls)

| Topic | Hardware‑backed key (TEE/StrongBox/Secure Enclave) | Software‑backed fallback (old/cheap Androids) |
|---|---|---|
| Key extractable by malware with root | No (used, not extracted) | **Possible** on a rooted/compromised phone |
| Assurance claim | "Trusted device" | **"Registered device (basic)"** — shown in console and in the app's device list |
| Detection | Android key attestation (plugin option) proves TEE/StrongBox origin; iOS Secure Enclave always | Attestation reports software → stored as `key_security = software` |
| Extra controls | — | PIN required on **every** app open (not only step‑up); shorter refresh absolute lifetime (PROPOSED 30 d); sensitive screens (report‑card photo, documents) require PIN each time; flagged in monthly review counts |
| User explanation (Amharic copy in app) | "This phone is a trusted device" | "This phone does not have a security chip. For your safety the app will ask for your PIN each time." |
| Staff explanation (console) | badge **HW** | badge **SW** with tooltip |
| Phones with **no screen lock / no biometric at all** | — | **Enrolment refused** until a screen lock exists (device‑credential gating is the minimum); staff help set one up |

---

## 4. Developer verification checklist for D2‑1 (gate before any implementation)

| # | Verify | Pass criterion |
|---|---|---|
| V1 | `biometric_signature` maintenance health | Releases within the last 6 months, open‑issue response time, verified publisher, licence; **bus‑factor** noted (single maintainer?) |
| V2 | Android compatibility | Works on the oldest device class parents realistically own (API 23/24, Android 6/7); compileSdk 35 OK for new apps; behaviour on devices without StrongBox |
| V3 | iOS compatibility | iOS 13+; Secure Enclave on all supported iPhones; `shouldMigrate`/Keychain semantics after reinstall |
| V4 | Hardware‑backed support & attestation | Attestation chain returned on supporting Androids; parsed/verified server‑side (or at least recorded) — decide depth in D3 |
| V5 | Security limitations | Key invalidation on biometric change (choose: invalidate → re‑enrol with PIN); device‑credential fallback policy; behaviour under root/jailbreak; no key material in logs |
| V6 | Abstraction | App code talks to a thin `DeviceKey` interface so the plugin can be replaced by a platform‑channel implementation without touching flows |
| V7 | Restore‑from‑backup behaviour | App detects "metadata present, key missing" after restore/transfer and routes to **Add this device** gracefully (known failure mode [FACT: Nevis docs]) |
| V8 | Server verification | `openssl_verify` ES256 + `lcobucci/jwt` on the production PHP build; nonce/`jti` replay store on MariaDB |

---

## 5. Remaining risk register (security + compatibility)

Severity: H / M / L (INFERENCE). Owner column = who closes it.

| # | Risk | Sev | Mitigation already designed | Residual / action | Owner |
|---|---|---|---|---|---|
| **Security** | | | | | |
| R1 | Shared‑hosting DB user has full rights → audit rows could be altered/deleted by anyone with DB credentials | H | Append‑only triggers, hash chain, daily digest heads anchored in `activity_logs` + exported; verification button | Tampering is **detectable, not preventable**; keep DB credentials in env file; monthly export of digest heads off‑host (e‑mail/Drive by super_admin) | IT |
| R2 | 6‑digit PIN brute force if DB **and** pepper leak | H→M | PIN verified only with device proof; per‑device/account limiter; pepper in `.fkss_env.php` outside web root; argon2id/bcrypt | Define pepper rotation procedure; host check for argon2id | IT / D3 |
| R3 | Software‑backed keys extractable on rooted phones | M | §3 controls; attestation recorded; PIN every open | Accept; optional root/jailbreak detection library evaluated in D6 | D6 |
| R4 | Shared phone: biometric cannot distinguish guardians | M | PIN per account; switcher re‑prompts PIN; documented | Accept within household | D5 copy |
| R5 | 24 h add‑device/reset slip taken home and misused | M | Needs existing PIN (add‑device) / follows office check (reset); single‑use; hashed; revocable; notice to other devices | Accept (owner condition met) | — |
| R6 | Insider at desk activates wrong person | M | HR link prerequisite; proofing record; SoD‑2; monthly review; codes bound to guardian | Random desk‑sample audit in quarterly review (PROPOSED) | SA |
| R7 | Custody removal vs physical household access | M | Non‑technical; console warns on shared device | Accept; school process | School |
| R8 | Replay / clock skew of device proofs | L | `jti` single‑use store, server nonce challenge, ±60 s tolerance | Define nonce TTL and cleanup in D3 | D3 |
| R9 | TLS weaknesses on shared host | M | HTTPS only, HSTS, modern ciphers as host permits | **UNKNOWN:** host TLS grade — run SSL Labs once; no certificate pinning (cert rotation on shared hosting would brick apps) | IT |
| R10 | Child‑ID enumeration | L | Uniform responses, limiter per IP/ID, code required | Covered | — |
| R11 | Token endpoint load on registration Sundays | M | 15‑min access tokens; refresh only on expiry; cached data offline | **UNKNOWN:** expected concurrent parents; estimate in D3 and measure in E2E load test | D3/D6 |
| R12 | Plugin abandonment / breaking change | M | V1 + V6 abstraction | Pin version; fork‑ready | Dev |
| R13 | View‑as misuse | L | Read‑only token, 15 min, banner, user notice, 24 h second‑person review | Covered | — |
| **Compatibility** | | | | | |
| C1 | Production PHP < 8.2 (league v9 needs ≥ 8.2) | H | — | **UNKNOWN** → host check script (D3); fallback: league v8.5 on PHP 8.1 | IT |
| C2 | Triggers not permitted on shared MariaDB | M | Chain + verification work without triggers | **UNKNOWN** → host check | IT |
| C3 | `openssl` without EC P‑256 / no `sodium` | M | Only `openssl` EC is required | **UNKNOWN** → host check | IT |
| C4 | Share of very old Android phones among parents | M | Fallback §3; API 23/24 floor | **UNKNOWN** → quick survey at registration (ask phone model) | Office |
| C5 | iPhone distribution: Apple Developer account / App Store review needed; staff app today appears Android‑only (FACT: repo ships APK assets) | M | Design is iOS‑ready | **UNKNOWN:** does FKSS want iOS in phase 1? | Owner |
| C6 | Restore/transfer leaves metadata without key | M | V7 graceful path | Test in D6 | Dev |
| C7 | Phones without any screen lock | L | Enrolment refused until lock set | Desk script | Office |
| C8 | Biometric re‑enrolment invalidates key | L | Re‑enrol via PIN | Decide final UX in D5 | D5 |
| C9 | No cron on host | L | Lazy timers; optional cPanel cron | Covered | — |
| C10 | Wi‑Fi/data at the office for activation | M | Office Wi‑Fi or staff hotspot | **UNKNOWN:** availability at the registration office | Office |

---

## 6. Questions that remain open before D3 (UNKNOWN)
1. iOS in phase 1 or Android‑first? (C5)
2. Is there Wi‑Fi at the registration office for the activation minute? (C10)
3. Shared‑phone multi‑account: ship the switcher in phase 1 or phase 2? (server supports both either way)
4. Host facts (PHP version, triggers, openssl EC, argon2id) — I will hand you the read‑only check script with D3.

---

## 7. Owner decisions on §6 (final, 2026-10-18)
| # | Decision | Closes |
|---|---|---|
| 1 | **Android first; iPhone deferred** | C5 |
| 2 | **Office activation uses Wi‑Fi or a staff mobile hotspot** | C10 |
| 3 | **Shared‑phone account switcher ships in Phase 1** (server many‑to‑many device↔account; family‑mismatch rule) | D2‑3c (restriction NOT imposed) |
| 4 | **Read‑only production hosting check before finalising technical design** — script at `/home/user/acc_host_check/acc_host_check.php` | C1, C2, C3, R2 (argon2id) pending results |

---

## 8. Risk register update after the production SSH verification (2026-10-18)
| # | Change |
|---|---|
| C1 | **CLOSED** — web and CLI PHP are 8.2 (`league` ^9) |
| C2 | **CLOSED** — ALL PRIVILEGES on schema, 7 triggers already exist → append‑only triggers possible |
| C3 | **CLOSED** — OpenSSL 3.5.8 with prime256v1; argon2id available |
| R2 | pepper/key files: `/home/felegeki/.fkss_env.php` exists (mode 600, outside web root) — ACC keys go there |
| **NEW P1** | **Production code is 4 commits behind `main`** (`3769a40` vs `9c6f84d`); Family Link code not deployed although its tables exist. ACC depends on Family Link → owner `git pull` needed before ACC deployment (safe: no conflict with the local `app/.htaccess` change) |
| **NEW P2** | **`public_html/test.felegekidusan.com/` shares the production database** (no own env file) and sits untracked inside the production git tree. Must get its own DB + `.fkss_env.php` (or be removed) before being used as staging; never run ACC migrations against it while it points at production |
| **NEW P3** | **Cloudflare proxy**: audit `ip` and rate limits must use `CF-Connecting-IP` only for requests arriving from Cloudflare ranges; otherwise all users look like one IP (rate‑limit lockouts) or IPs can be spoofed. To be specified in D4/D6 and checked against existing `login.php`/`SecurityRateLimiter` behaviour |
| **NEW P4** | Server default charset latin1 → all ACC DDL explicit utf8mb4 (already a rule; now mandatory) |
| Context | Production currently serves **staff only** (39 users, 0 members) — the live‑user blast radius for the coming deployments is the staff web/app, not families |
