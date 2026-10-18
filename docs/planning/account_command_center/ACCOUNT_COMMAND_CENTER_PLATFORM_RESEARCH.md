# FKSS Account Command Center (Members + Parents) — Platform Research: What Mature Open Source Can Carry It?

**Type:** research + recommendation, no implementation. **Date:** 2026-10-18.
**Scope (your words, restated):** one dedicated command center that manages *every* member-app and parent-app user account end to end — activation, tracking, reporting, support assistance, password reset, device deactivation when a phone is stolen/lost/broken, and professional monitoring of every log. Starting from scratch is not acceptable; a very mature open-source foundation must carry it, and research + design come first.
Companion document: `PARENT_ACCOUNTS_MANAGEMENT_RESEARCH.md` (roles, separation of duties, event taxonomy, audit protection — all of it still applies, now to members as well).
Labels: **FACT** (repo or cited source) · **INFERENCE** · **UNKNOWN** (your decision).

---

## 0. Position in one paragraph

I agree with the direction: this is the **Identity & Access Operations layer** of the whole FKSS platform, it is a big feature in its own right, and it should be designed once for members, parents and (later, optionally) staff. The honest research result is this: **there is no mature open-source "account command center" that runs on shared PHP hosting.** Mature open source exists at exactly two levels — (A) full identity platforms that need their own always-on server (Keycloak, Zitadel, Authentik, Casdoor, Logto…), and (B) mature PHP component libraries (OAuth2/OIDC server, WebAuthn/passkeys, JWT, OTP, rate-limiting) on top of which the console itself is built. Which level you can use is decided by **one question: will FKSS run a small VPS in addition to the shared host?** Everything below is organised around that fork, and I end with a recommendation and the design process either way.

---

## 1. Where SSMS stands (FACT)

- Plain PHP 8 + MariaDB, no framework; `composer.json` carries only `phpoffice/phpspreadsheet` and `edgaras/strsim`; `vendor/` is committed because there is no composer on the server. → Any library we adopt must be vendorable pure PHP.
- Staff mobile auth already exists in-house: JWT access tokens, `api_refresh_sessions` with rotation families/revocation, `authorization_version` for live permission bumps, login throttling. Members and parents have **no** identity yet; guardians are records (Family Link), members are directory rows.
- Earlier decisions still standing: separate "FKSS Members" APK; agent-vault in-person activation; device-bound keys + biometric daily unlock; no e-mail/SMS as a mandatory factor; everything compatible with shared hosting.

---

## 2. Level A — full identity platforms (need their own server)

### 2.1 Shortlist

| Platform | Stack / licence | DB | Idle RAM (reported) | Admin console | Audit built in | Maturity | Verdict |
|---|---|---|---|---|---|---|---|
| **Keycloak** | Java/Quarkus, Apache-2.0, Red Hat, CNCF incubating, ~35.7k stars [12](https://skycloak.io/blog/keycloak-vs-authentik-comparison/) | PostgreSQL, MySQL/MariaDB, MSSQL, Oracle [8](https://skycloak.io/blog/keycloak-vs-zitadel-comparison/) | ~540–620 MB idle; docs: base 1250 MB per instance, 2 GB container limit recommended, 750 MB floor [3](https://www.keycloak.org/high-availability/multi-cluster/concepts-memory-and-cpu-sizing) [12](https://www.keycloak.org/server/containers) | Yes (powerful, complex) | **User events + admin events**, sessions per user, impersonation, required actions | Highest | **Best-in-class if a 2 GB VPS is acceptable** |
| **Zitadel** | Go; licence reported as AGPL-3.0 since 2025 by one source and Apache-2.0 core by others — verify before adopting [7](https://wz-it.com/en/blog/authentik-vs-zitadel-identity-provider-comparison/) [8](https://skycloak.io/blog/keycloak-vs-zitadel-comparison/); ~15k stars | PostgreSQL only | ~145–215 MB [3](https://devhandbook.io/blog/2026-08-08-self-hosted-auth-sso-showdown/) [6](https://computingforgeeks.com/keycloak-vs-authentik-vs-zitadel-authelia/) | Yes (modern) | **Event-sourced** — every change is stored as an event (strong for "track everything"); grows 2–4 GB/30 days at 10k users [4](https://www.pistack.xyz/posts/2026-04-21-casdoor-vs-zitadel-vs-authentik-lightweight-sso-guide-2026/) | High | **Strong runner-up on a 1 GB VPS**; licence check required |
| Authentik | Python/Django + Go, MIT core + EE, ~22–26k stars | PostgreSQL | ~350–700 MB; documented min 2 CPU / 2 GB [7](https://wz-it.com/en/blog/authentik-vs-zitadel-identity-provider-comparison/) | Yes (visual flows) | Events | High | Heavier than Zitadel, less standard than Keycloak |
| Casdoor | Go, Apache-2.0 | MySQL/MariaDB, PostgreSQL, SQLite… [5](https://casdoor.ai/docs/comparison/overview/) | ~100–150 MB [1](https://futurepicker.com/en/authentik-alternatives-sso-2026-en/) | Yes (basic) | Basic | Medium | Lightest full IdP; smaller community → riskier long-term |
| Logto | Node/TS, MPL-2.0 | PostgreSQL | ~100–400 MB | Yes (modern, CIAM-oriented) | Audit logs | Medium–large | Good CIAM UX; younger |
| Ory Kratos | Go, Apache-2.0 | PostgreSQL/MySQL | ~80 MB | **No admin UI (API only)** [3](https://devhandbook.io/blog/2026-08-08-self-hosted-auth-sso-showdown/) | Via webhooks | High (API) | Not suitable — you would still build the console |
| Hanko | Go, passkey-first CIAM [20](https://github.com/topics/passwordless) | PostgreSQL | low | Lightweight | Limited | Medium | Too narrow for a command center |

### 2.2 What a platform gives you for free — and what it does not

**Given (Keycloak as reference):** user directory with custom attributes and groups; OAuth2/OIDC/PKCE token engine for the apps; refresh-token rotation, session listing and revocation; **user events** (LOGIN, LOGIN_ERROR, LOGOUT, UPDATE_PASSWORD…) and **admin events** (every admin change with optional before/after representation); required actions (force password change); impersonation; WebAuthn/passkeys; an end-user **Account Console** where a person sees "Device activity" and can sign out a suspicious device [1](https://docs.redhat.com/en/documentation/red_hat_build_of_keycloak/22.0/html/server_administration_guide/account-service) [3](https://skycloak.io/blog/device-management-in-iam-an-introduction/).

**Not given (you still design and build it):**
1. **In-person activation ceremony with one-time codes and a proofing record** — no platform has FKSS's "registration office verifies the person and the children, then issues a code" flow. On Keycloak this becomes custom code calling the Admin REST API (create user → set required actions → issue your own code); on PHP it is native.
2. **Guardian ↔ child authority** — stays in SSMS (Family Link). The platform only knows "this user has attribute parent_of=[…]" if you sync it; double bookkeeping risk.
3. **Named devices** — Keycloak's Device Activity is only in the *user's* account console; the admin console shows sessions, not devices, and there is no admin endpoint for devices [4](https://github.com/keycloak/keycloak/discussions/21054) [2](https://github.com/keycloak/keycloak/discussions/45095). "Phone stolen → revoke that phone" still needs your own device table + session mapping.
4. **Read-only "view as"** — Keycloak impersonation is full impersonation, not read-only; you must add the read-only enforcement and banner in SSMS/apps.
5. **School-specific reporting, Amharic staff UI, support case notes, consent versions, retention/archival policy, SSMS role integration** — all yours.
6. **Persistent audit** — Keycloak events are *off by default* and need explicit enabling and expiry settings [5](https://github.com/AdrianIAna/keycloak-account-events); both Keycloak and Okta-style systems expect you to export for long retention.

**INFERENCE:** a platform covers roughly the "engine room" (tokens, sessions, credentials, passkeys, raw event store) — maybe a third of the command center — and the staff-facing command center would **still be a custom SSMS console** calling the platform's API. You would run two admin surfaces (Keycloak's and SSMS's) or hide Keycloak's completely behind yours.

### 2.3 What running a platform costs you (FACT/INFERENCE)
- A VPS: ~2 GB RAM for Keycloak + PostgreSQL (docs-based) [1](https://keycloakops.com/posts/keycloak-resource-requirements/); ~1 GB for Zitadel/Casdoor. Typically US$5–15/month abroad; foreign-currency payment is an FKSS consideration (UNKNOWN).
- Operations forever: Docker, TLS, backups of a second database, upgrades (Keycloak releases often and upgrades can break themes/extensions), monitoring. Someone must own this.
- **Availability coupling:** if the VPS is down, *no member or parent can log in* to either app, even though SSMS itself is up. The baseline's offline-first rule (§15) softens this for reading cached data but not for activation/reset.
- Identity data (names, phones, children links) would live on a second host; the two stores must never disagree (baseline 19.6 "single source of truth" — the platform would have to be declared the identity master, SSMS the profile master).

---

## 3. Level B — mature PHP components, your own console (runs on the shared host)

This is how large PHP products (CMSs, LMSs, analytics suites) handle identity: they do not embed Keycloak; they compose audited libraries and build the management UI themselves. For SSMS the candidate set is small and all pure PHP, vendorable:

| Concern | Library | Maturity signal | Note |
|---|---|---|---|
| OAuth2 authorization server (PKCE, access/refresh tokens, rotation, revocation) | **`league/oauth2-server`** | 165 M installs, 6.6k stars, v9.4.1 June 2026, PHP 8.2–8.5, MIT [15](https://packagist.org/packages/league/oauth2-server) | "Gold standard" PHP OAuth server; replaces hand-rolled token code. OIDC layer: `steverhoades/oauth2-openid-connect-server` [21](https://github.com/steverhoades/oauth2-openid-connect-server) |
| Device-bound keys / passkeys (biometric login, stolen-device revocation by key) | **`web-auth/webauthn-lib`** (26.8 M installs, v5.3.9 Sept 2026, MIT) [16](https://packagist.org/packages/web-auth/webauthn-lib) or the smaller **`lbuchs/WebAuthn`** (MIT, PHP ≥ 8.0, OpenSSL + mbstring) [3](https://github.com/lbuchs/WebAuthn) | FIDO-conformant verification is exactly the thing not to write yourself [5](https://www.corbado.com/blog/webauthn-server-implementation) | lbuchs is lighter and has a security-focused maintained fork (`passkeys-php`, Report URI) [10](https://scotthelme.co.uk/open-sourcing-passkeys-php-a-security-focused-webauthn-library-for-php/); check extension availability on the host (sodium/openssl) |
| JWT signing/validation | `lcobucci/jwt` (dependency of league server) | de-facto standard | — |
| Recovery codes / TOTP | `spomky-labs/otphp` | widely used | optional recovery factor |
| Lockout / rate limits | `symfony/rate-limiter` (+ PDO/DB storage) | Symfony component | replaces ad-hoc throttling |
| Structured logging | `monolog/monolog` (DB handler) | ubiquitous | operational logs; audit stays in DB table |
| Reports/exports | `phpoffice/phpspreadsheet` (already in repo) | — | account reports |
| Ready-made PHP "user management systems" | UserFrosting 6 (Slim+Twig+Eloquent+Vue, 1.7k stars, MIT, active 2026) [1](https://github.com/userfrosting/UserFrosting) [12](https://userfrosting.com/); UserSpice (passkeys, small) [26](http://userspice.com/); LDAP Account Manager (LDAP only) | small communities | **Not adoptable**: they are whole frameworks with their own stack; bolting one onto SSMS would create a second application, not a console |

**What Level B does not give you:** the console UI, state machine, device registry, proofing records, audit viewer, reports — but these are the *school-specific* parts you would build in Level A as well (see §2.2). What it does remove is every piece of cryptographic/protocol code: token issuance, PKCE, refresh rotation, WebAuthn verification, OTP, rate limiting.

**Security review point (INFERENCE):** Level B puts the identity store in the same MariaDB and PHP runtime as the rest of SSMS. Isolation is logical (separate tables, separate role gates, append-only audit) rather than physical. Acceptable for a school system; not what a bank would do.

---

## 4. Requirement-by-requirement fit

| Your requirement | Level A (Keycloak/Zitadel + SSMS console) | Level B (PHP libs + SSMS console) |
|---|---|---|
| In-person activation code, proofing record | Custom (Admin API) | Custom (native) |
| Members + parents in one place | Yes (one realm, groups) | Yes (one `accounts` module, `kind = member/parent`) |
| Password reset by staff, forced change | Native (required action) + SSMS wrapper | Custom, ~small |
| Phone stolen/lost/broken → kill that device | Sessions revoke native; *per-device* naming/mapping custom | Custom, on the existing `api_refresh_sessions` pattern + WebAuthn credential ids |
| Biometric/device-bound login | WebAuthn native | WebAuthn via library |
| Support "view as" (read-only) | Impersonation native but full; read-only layer custom | Custom scoped token, read-only enforced centrally |
| Track **everything** | Native event store (Zitadel strongest) + SSMS audit; two logs to correlate | One hash-chained audit table, one viewer |
| Reporting | Mostly custom | Custom |
| Amharic staff UI | Keycloak admin console not in Amharic → hide it behind SSMS | Fully yours |
| No e-mail/SMS dependency | Platforms assume e-mail for flows; disable and replace | Native to the design |
| Shared hosting only | **No** | **Yes** |
| Long-term maintenance burden | Platform upgrades + VPS + integration code | Library updates (vendored) + own code |
| Time to first working version (INFERENCE) | Longer (infrastructure + integration + custom parts) | Shorter for the school-specific scope |
| Future SSO to other systems (web SSO, third-party apps) | Excellent | Possible via league server, more work |

---

## 5. Recommendation

1. **Decide the hosting question first; it dominates everything.**
   - **If FKSS will not run and maintain a VPS: Level B is the right and legitimate choice.** Build the Account Command Center as an SSMS module on `league/oauth2-server` + a WebAuthn library + `symfony/rate-limiter` + hash-chained audit. Nothing here is "from scratch" in the dangerous sense — every cryptographic and protocol piece is a mature library; what you write is school workflow, UI and reporting, which no product sells.
   - **If FKSS will run a VPS (≥ 2 GB) with an owner for operations: Keycloak** is the most mature engine room, with Zitadel as the lean alternative *after a licence check*. Even then, build the staff-facing command center inside SSMS and treat the platform as an engine behind an API; do not hand the registration office the Keycloak console.
2. **Design so that the choice is reversible.** Whatever the path: identity ids are stable UUIDs (`sub`), tokens are standard JWT/OIDC-shaped, the apps talk OAuth2 + PKCE (RFC 8252/AppAuth), the audit event vocabulary is the same, and the guardian/child authority lives only in SSMS. Then a later move from Level B to Keycloak (or the reverse) swaps the engine, not the console or the apps.
3. **My own leaning (INFERENCE, not a decision):** Level B, because the standing constraint is shared hosting, the hard parts of your requirement list (ceremony, devices, view-as, Amharic console, no e-mail) are custom in both paths, and a single audit trail and a single source of truth are easier to defend. Level A becomes compelling only if FKSS also wants web SSO across several systems or plans to outgrow shared hosting anyway.

---

## 6. "Research and design how it has to be" — proposed design programme (no code)

| Step | Output | Content |
|---|---|---|
| D0 | Decision memo | Hosting path (VPS yes/no), scope (members + parents now; staff later?), console language, budget/owner |
| D1 | Requirements & governance | Actors and roles (`accounts_office`, super_admin, school_admin, HR, support), separation-of-duties and four-eyes matrix, lifecycle state machines for member and parent accounts, policy parameters (lockouts, lifetimes, code expiry, retention) |
| D2 | Architecture | Component diagram (apps ↔ token engine ↔ SSMS console ↔ audit), chosen libraries/platform with version pins and licence list, trust boundaries, offline/degraded modes |
| D3 | Data & API design | Tables (accounts, activation codes, proofing records, devices, sessions, consents, support sessions, audit events + digests), API contract for both apps (activation, login, refresh, device list/revoke, self-service), console API |
| D4 | Tracking & monitoring design | Final event vocabulary, correlation ids, hash-chain + anchoring on shared hosting, viewer/filters/alerts/exports, retention and archival jobs that run without cron (on-request or page-triggered) |
| D5 | Console UX | Wireframes (Amharic/English): directory, account page with timeline, activation desk, lost-phone desk, reset desk, view-as, audit explorer, reports, policy |
| D6 | Threat model & test plan | STRIDE on activation/reset/lost-phone/view-as; abuse cases (social engineering at the desk, insider misuse); e2e test matrix; migration plan from current staff JWT if staff are included later |
| D7 | Implementation plan | Phased delivery with go/no-go per phase, per the project change protocol |

Each step is a reviewable document; nothing is implemented until D7 is approved.

---

## 7. Decisions needed from you (UNKNOWN)
1. **VPS or not?** (This selects Level A vs Level B.)
2. **Scope now:** members + parents only, or staff accounts to migrate into the same center later?
3. **Console language:** Amharic-first like the apps, or English like the current admin?
4. **Budget/owner** for operations if a platform is chosen.

---

## Sources
- Platform comparisons: https://devhandbook.io/blog/2026-08-08-self-hosted-auth-sso-showdown/ · https://computingforgeeks.com/keycloak-vs-authentik-vs-zitadel-vs-authelia/ · https://skycloak.io/blog/keycloak-vs-zitadel-comparison/ · https://skycloak.io/blog/keycloak-vs-authentik-comparison/ · https://wz-it.com/en/blog/authentik-vs-zitadel-identity-provider-comparison/ · https://www.pistack.xyz/posts/2026-04-21-casdoor-vs-zitadel-vs-authentik-lightweight-sso-guide-2026/ · https://casdoor.ai/docs/comparison/overview/ · https://futurepicker.com/en/authentik-alternatives-sso-2026-en/
- Keycloak sizing: https://www.keycloak.org/high-availability/multi-cluster/concepts-memory-and-cpu-sizing · https://www.keycloak.org/server/containers · https://keycloakops.com/posts/keycloak-resource-requirements/
- Keycloak account console / device activity: https://docs.redhat.com/en/documentation/red_hat_build_of_keycloak/22.0/html/server_administration_guide/account-service · https://github.com/keycloak/keycloak/discussions/21054 · https://github.com/keycloak/keycloak/discussions/45095 · https://github.com/AdrianIAna/keycloak-account-events
- PHP libraries: https://packagist.org/packages/league/oauth2-server · https://github.com/steverhoades/oauth2-openid-connect-server · https://packagist.org/packages/web-auth/webauthn-lib · https://github.com/lbuchs/WebAuthn · https://scotthelme.co.uk/open-sourcing-passkeys-php-a-security-focused-webauthn-library-for-php/ · https://www.corbado.com/blog/webauthn-server-implementation
- PHP user-management frameworks: https://github.com/userfrosting/UserFrosting · https://userfrosting.com/ · http://userspice.com/

---

## D0 — Decision memo (recorded 2026-10-18, owner decisions)

| # | Question | Decision | Consequence |
|---|---|---|---|
| 1 | Separate VPS for an identity platform? | **No — shared hosting only** | **Level B selected**: Account Command Center built inside SSMS on mature vendored PHP libraries (`league/oauth2-server` + OIDC layer, WebAuthn library, `symfony/rate-limiter`, `otphp`, `monolog`) with a hash-chained audit store in MariaDB. Keycloak/Zitadel are out of scope; design stays OIDC-shaped so an engine swap remains possible later. |
| 2 | Accounts covered from day one | **Members + parents only** | Staff keep the existing `users` table, web login and staff-app JWT flow. The design must not break them; a later staff migration is an optional future phase. |
| 3 | Console language | **English** (like the current admin) | Parent/member *apps* remain Amharic-first per their baselines; the staff console is English. |

Next step under the project protocol: **D1 — Requirements & Governance document** (actors, roles incl. `accounts_office`, separation-of-duties / four-eyes matrix, member and parent account state machines, policy parameters, retention). No implementation until D7 is approved.
