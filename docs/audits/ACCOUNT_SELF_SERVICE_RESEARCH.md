# Account Self-Service ("Profile Management") — Deep System Research

**Repository:** suraman21/SSMS (WBWS / FKSS Church School Management System)
**Investigation date:** 2026-09-22 · **Branch:** `main` · **HEAD:** `71095fc`
**Scope:** Why some departments lack profile management (username / password / personal
data self-service), what exists today, whether what exists actually works, and the
full risk picture. Evidence is cited as `file:line`.

---

## 1. Executive summary

| # | Finding | Severity |
|---|---------|----------|
| F1 | **12 of 14 account roles have no profile-management UI at all.** Only `hr_dept` and `info_dept` dashboards ship the "My Profile / Change Password" settings module. | **Critical (UX/parity)** |
| F2 | **The two dashboards that have the feature are broken.** Their `sApiPost()` client sends JSON bodies without a CSRF token, but `admin/api_settings.php:31` runs `requireCsrfForPost()` on every POST. Every save returns **403 "Security token expired"**. In effect, **no web role can currently change its own password or profile.** | **Critical (functional/security)** |
| F3 | **Root cause is architectural:** each role dashboard is a monolithic copy-paste PHP file (216–280 KB). The settings module was written once inside `info-dept`, inherited by `hr-dept` when HR was created *as a copy of Info* (commit `cbbac08`), and never distributed to the other 10+ surfaces because **no shared component existed**. | Root cause |
| F4 | Newer roles (`finance_dept`, `mezmur_dept`) were migrated to a *second* frontend stack (`/frontend/` shell) whose layout ships **no account UI whatsoever** — the gap is being reproduced in the new stack. | High (trajectory) |
| F5 | The **backend API was built for everyone but wired for nobody**: `profile_get / profile_update / password_change` in `admin/api_settings.php` are role-agnostic and correctly secured (session + CSRF + policy + audit + refresh-token revocation). The mobile app already uses the equivalent `/users/change-password` and works. | — (good news) |
| F6 | No self-service **username** change (immutable by design, admin-only via `admin/users.php`), no **forgot-password** flow (no mail infrastructure), no **MFA**, no **email/phone verification**, no **session/device manager**, no **breach-password check**. | High (roadmap) |
| F7 | `profile_update` writes `full_name`/`email` **without an audit-log entry** and **without re-authentication** for email changes — an attacker with a hijacked session can silently redirect the future recovery channel. `password_change` has **no rate limiting** (unlike login). | Medium (hardening) |

**Bottom line:** the system does not have a *feature* problem, it has a *distribution*
problem — plus one fatal wiring bug. The correct fix is not to copy the settings HTML
into 10 more dashboards (that is how the gap happened); it is to extract **one shared,
self-contained "My Account" component** (the same pattern the project already
standardized for notifications in P72/P73) and wire every surface to it, then harden
the API and lay the roadmap for MFA-grade identity.

---

## 2. System map — who signs in, and where they land

`admin/dashboard.php:55-118` routes every session role to one dashboard file:

| Role | Surface | Monolith size | Profile UI | Working? |
|------|---------|--------------:|:----------:|:--------:|
| `super_admin` | `admin/dashboards/super-admin.php` | 127 KB | ❌ (Settings = calendar/system info only, `super-admin.php:1070`) | — |
| `school_admin` | `admin/dashboards/school_admin.php` | 148 KB | ❌ (has *user management for others*, `school_admin.php:537`) | — |
| `hr_dept` | `admin/dashboards/hr-dept.php` | 238 KB | ✅ Settings → My Profile + Change Password (`hr-dept.php:1676-1743`) | ❌ **403 CSRF** |
| `info_dept` | `admin/dashboards/info-dept.php` | 279 KB | ✅ (clone of HR's, `info-dept.php:2291-2357`) | ❌ **403 CSRF** |
| `edu_dept` | `admin/dashboards/edu_dept.php` | 216 KB | ❌ (Settings = Academic Year only, `edu_dept.php:485,689`) | — |
| `finance_dept` | → redirect `/frontend/pages/finance_dept.php` (`dashboard.php:82-85`) | new shell | ❌ (user card + logout only, `finance_dept.php:75-84`) | — |
| `material_dept` | `admin/dashboards/material_department.php` | 36 KB | ❌ | — |
| `mezmur_dept` | → redirect `/frontend/pages/mezmur_dept.php` (`dashboard.php:91-94`) | new shell | ❌ | — |
| `teacher` | `admin/dashboards/teacher.php` | 81 KB | ❌ (sidebar ends at Logout, `teacher.php:205`) | — |
| `attendance_taker` | `admin/dashboards/attendance_taker.php` | 39 KB | ❌ | — |
| `mezmur_attendance_taker`, `hr_attendance_taker` | `admin/dashboards/dept_taker.php` | 4.8 KB | ❌ (read-only landing; account shown as a static badge, `dept_taker.php:88`) | — |
| `content_editor` | `admin/dashboards/content_editor.php` | 17 KB | ❌ | — |
| **Mobile app users** (all roles) | `Mobile/…/screens/profile/profile_screen.dart` | — | ✅ view info + change password | ✅ works (token auth, no cookie/CSRF) |

Supporting evidence for the "working?" column: `admin/api_settings.php:31`
(`requireCsrfForPost()`), `config.php:544-551` (reads `$_POST['csrf_token']` **or the
`X-CSRF-TOKEN` header**), and `hr-dept.php:3662-3668` / `info-dept.php` equivalent —
`sApiPost()` sends `Content-Type: application/json` with **neither** the form field
**nor** the header. PHP never populates `$_POST` from JSON bodies, so the token is
always empty → `validateCsrf('')` fails → HTTP 403 on **every** `profile_update`,
`password_change`, `dept_save`, preference save, etc. from these two dashboards.
(The newer shared `admin/js/comm.js:123-132` got this right by posting
`application/x-www-form-urlencoded` with `csrf_token` — proof this is a fixable,
already-solved-in-house pattern.)

---

## 3. What already exists and is worth keeping (the good news)

The backend half of a world-class account service is **already built and mostly
excellent**:

| Capability | Where | Quality |
|---|---|---|
| Session auth, CSRF, IP+account **rate limiting** at login, session regeneration, impersonation guard | `admin/backend/login.php:60-110` | ✅ strong |
| Shared **password policy** (≥12 chars, ≤72 bytes bcrypt limit, UTF-8, common-password blocklist) | `admin/backend/services/PasswordPolicy.php` | ✅ strong (NIST-800-63B aligned: length-first, no composition rules) |
| `profile_get` / `profile_update` / `password_change` — **role-agnostic**, works for every role | `admin/api_settings.php:44-200` | ✅ logic / ❌ unreachable from UI (F2) |
| Password change verifies current password, re-hashes with `PASSWORD_DEFAULT`, rotates the session ID, updates `AUTH_PASSWORD_VERSION`, **revokes every mobile refresh-token family**, writes an audit row | `admin/api_settings.php:158-200` | ✅ strong |
| Mobile identity API: `/users/me`, `/users/change-password` with the same policy + refresh revocation | `api/v1/routes/users.php:49-118` | ✅ strong |
| Refresh-token rotation & revocation infra | `sql/010_refresh_token_rotation.sql` | ✅ strong |
| Audit trail (`activity_logs`) + login counting | `sql/012_runtime_schema_baseline.sql` | ✅ present |
| Shared-component architecture with a written contract (self-bootstrap, error boundary, single CSS/JS runtime, CSRF carried on the component) | `admin/components/notification_center.php:1-60`, `docs/COMMUNICATION_UX_OVERHAUL.md` | ✅ the pattern to reuse |

The **only** missing pieces are: (a) a shared *account* UI component, (b) wiring in
every surface, (c) the CSRF fix on the legacy settings client, (d) a hardening pass on
`profile_update`/`password_change`, and (e) the roadmap features (MFA, recovery,
devices, verification).

---

## 4. Root-cause analysis — *why* departments miss the feature

### 4.1 Timeline reconstruction (git evidence)

1. **Pre-repo snapshot** — the original monorepo already contained an
   `info-dept` dashboard with a hand-written Settings section (My Profile,
   Department, Preferences, System). `git log -S "My Profile"` first touches
   `info-dept.php` at `f929140` (*"Move files from SSMS to root directory"*) — i.e.
   the module predates this repository.
2. **HR department onboarding** — commit `cbbac08` (*"feat: Add HR Department and
   move Registration from Info Dept"*) created `hr-dept.php` **by copying
   `info-dept.php`**. HR inherited the Settings module verbatim — including its
   Amharic labels. This is why *exactly two* dashboards have the feature: they are
   clones of each other, not two independent implementations.
3. **Same commit era: CSRF arrives at the API** — `requireCsrfForPost()` appears in
   `api_settings.php` at the repo-move snapshot `f929140`. The copied `sApiPost()`
   JSON client was never updated to carry the token → **the module has been
   returning 403 since the day this repo was created.** Nobody noticed because the
   module fails *silently* (generic toast, no redirect, no crash log).
4. **Every later dashboard was written feature-first, separately** — education
   (`edu_dept.php`, its "Settings" is the academic-year console), school admin
   (user management for *others*), teacher, attendance takers, content editor,
   material. None included self-service, because there was no shared component to
   include and no "account parity" definition-of-done for new roles.
5. **The second frontend stack repeats the mistake** — commits migrated `finance`
   and `mezmur` to the `/frontend/` shell (`frontend/layouts/base.php`). The shell
   standardizes theming, CSRF bootstrap and role guards — but ships **no account
   region at all** (`base.php` has no logout/profile markup; pages add only a
   logout link). Two more roles born without the feature.
6. **Role-model drift** — `users.role` started as a 6-value ENUM
   (`database_schema.sql`), grew to 8 via `admin/migrations/003_*:127`, then was
   widened to `VARCHAR(50)` (`sql/012:309`) — plus later roles
   (`mezmur_attendance_taker`, `hr_attendance_taker`, `content_editor`) that exist
   only in code. Each new role increased surface count without a checklist item
   for account self-service.

### 4.2 The five structural causes

| # | Cause | Consequence |
|---|---|---|
| C1 | **Copy-paste dashboard architecture** (single-file monoliths up to 280 KB) | A feature exists in exactly the files someone remembered to paste it into |
| C2 | **No shared account component** (unlike P72/P73 comm/notification, which solved this class of problem for its own domain) | Wiring cost per dashboard is high → skipped |
| C3 | **No parity definition-of-done** when onboarding a role/department | New roles ship without self-service, invisibly |
| C4 | **Two frontend stacks** (legacy `/admin/dashboards/*` + new `/frontend/pages/*`) | Even a perfect fix in one stack misses the other |
| C5 | **Silent failure culture at the UI boundary** — 403s render as generic toasts; no client-side assertion that the settings API contract matches the server | A shipped, working-looking feature was dead for its entire life (F2) |

### 4.3 Why it went unnoticed

- The API fails closed but *quietly*: `requireCsrfForPost()` dies with a JSON 403 that
  the UI surfaces as an amber toast — no exception, no crash-log entry, no monitor ping.
- Manual QA focused on each department's *domain* features (attendance, hymns,
  finance); settings was assumed "done because it renders".
- No automated end-to-end test covers `profile_update`/`password_change` from the
  browser side (the `tests/` tree covers policy/middleware/unit levels, and the
  `docs/audits/production-2026-09-07` browser contract suite does not include the
  settings actions).

---

## 5. Gap analysis vs. industry-standard account self-service

Benchmarks: **Microsoft Entra ID / account.microsoft.com**, **Google Account**,
OWASP ASVS v4.0.3 (V2 Authentication, V3 Session), NIST SP 800-63B.

| Capability | Microsoft / Google | SSMS today | Gap |
|---|---|---|---|
| View own profile (all roles, all surfaces) | ✅ | mobile only | **12 web roles missing** |
| Edit display name | ✅ | hr/info (broken) | F1+F2 |
| Edit email with **re-authentication** + notification | ✅ step-up | ❌ no re-auth, no audit | F7 |
| Change password w/ current-password proof, strength policy, breach check, session revocation | ✅ | backend ✅, UI broken/missing | F1+F2 |
| Self-service **username** change (with guardrails/audit) | ✅ (Google allows; MS via admin) | ❌ immutable | P3 |
| **Forgot password** (verified recovery channel) | ✅ | ❌ none (no mail infra; login page link is dead: `admin/index.php:341`) | P2/P3 |
| **MFA** (TOTP / passkeys) | ✅ | ❌ | P3 |
| **Device/session manager** + sign-out-everywhere | ✅ | ❌ (mobile refresh revocation only, triggered by password change) | P2 |
| Security-activity timeline ("recent activity") | ✅ | ❌ (data exists in `activity_logs`, never surfaced) | P1 (cheap win) |
| Rate-limited credential APIs | ✅ | login ✅, password_change ❌ | F7 |
| Consistent UX across every entry surface | ✅ | ❌ two stacks, one component | C1-C5 |

Risk framing: the organization runs attendance, safeguarding-adjacent member data,
finance and HR on these accounts. Accounts here are **shared institutional keys** —
today, a department head who suspects credential exposure must call the super admin
to reset their password manually (`admin/users.php:467`), which is exactly the
operational pattern that produces password sharing.

---

## 6. Answer to the research question, in one paragraph

Some departments miss profile management because the feature was never a *system*
capability — it was a **block of HTML that existed inside one dashboard** (Info)
and travelled only to the one dashboard later created as its copy (HR). Every other
dashboard was authored independently across five years of feature-first commits, on
two different frontend stacks, with no shared account component, no role-parity
checklist, and a settings API that — after CSRF enforcement landed — silently
rejects the writes of the very two dashboards that *do* render the feature. The
distribution failure (12 of 14 surfaces) and the wiring bug (100% write-failure
where it exists) share the same root cause: **account self-service has no owner, no
component, and no contract.**

---

## 7. Recommended remediation (summary — full design in
`docs/ACCOUNT_SELF_SERVICE_SOLUTION.md`)

- **P0 — Unbreak (same-day):** carry `X-CSRF-TOKEN` in the legacy `sApiPost` client;
  add rate limiting + audit + email-change re-auth to the settings API.
- **P1 — One component, every surface (this change):**
  `admin/components/account_settings.php` following the P73 component contract
  (self-bootstrapping, error-bounded, self-contained CSRF, single CSS/JS runtime,
  zero host-page dependencies) rendered as a modal from every top bar — legacy
  dashboards, `dept_taker`, and both `/frontend/` shells — replacing nothing,
  breaking nothing.
- **P2 — Trust features:** security-activity timeline, sign-out-all-mobile,
  recovery-channel groundwork (mail), device registry.
- **P3 — Identity-grade:** TOTP MFA, breach-password k-anonymity check,
  username-change guardrails, passkeys (WebAuthn), verified email/phone.

---

### Appendix A — Exact integration/wiring points found (for the fix)

| Surface | Anchor point used |
|---|---|
| `super-admin.php` | top bar next to `renderNotificationCenter()` (`:668`) |
| `school_admin.php` | header bell row (`:300`) |
| `edu_dept.php` | header bell row (`:472`) |
| `material_department.php` | brand bar bell (`:72`) |
| `teacher.php` | top bar bell (`:226`) |
| `attendance_taker.php` | top bar bell (`:169`) |
| `content_editor.php` | top bar bell (`:188`) |
| `dept_taker.php` | header action cluster (`:66-74`) |
| `frontend/pages/finance_dept.php` | user-card action row (`:75-84`) |
| `frontend/pages/mezmur_dept.php` | user-card action row (`:75-84`) |
| `hr-dept.php`, `info-dept.php` | keep native module; fix `sApiPost`/`sApiGet` transport |
| Mobile | none needed (already working via `/users/*`) |

### Appendix B — Commands used to verify

```
git log -S "My Profile" -- admin/dashboards/hr-dept.php admin/dashboards/info-dept.php
  → cbbac08 (HR copy of Info), f929140 (snapshot import)
grep -n "requireCsrfForPost" admin/api_settings.php        → line 31
grep -n "function sApiPost" -A6 admin/dashboards/hr-dept.php → JSON body, no CSRF
config.php:544 requireCsrfForPost → $_POST or X-CSRF-TOKEN header only
```

---

## Appendix C — Sidebar/nav router map (Phase 2: sidebar parity, v1.2.0)

User-facing requirement after v1.1.x feedback: profile management must live **in the
sidebar as a native section** (HR/Info style) on **every** department — not only as a
top-bar modal. Audit of every dashboard's navigation system:

| Surface | Nav item mechanism | Section container | Router | Wiring needed |
|---|---|---|---|---|
| `edu_dept.php` | `<button class="nl" data-sec="N">` (delegated binder) | `<div id="sec-N" class="sec">` | generic `nav(n)` | markup only + mobile array entry |
| `school_admin.php` | `<button class="np" data-section="N">` (delegated) | `<section id="section-N" class="cs">` | generic `nav(n)` | markup only + mobile array entry |
| `material_department.php` | `<button class="np" data-section="N">` (delegated) | `<section id="section-N" class="cs">` | generic `nav(n)` | markup only |
| `super-admin.php` | `<li><button class="nav-link" data-section="N">` (delegated in super_admin.js) | `<section id="section-N" class="section" hidden>` | `switchSection` with **ALLOWED map** | markup + `account:1` in ALLOWED + cache-bust `?v=` |
| `teacher.php` | `<div class="nav-link" onclick="showSection('N')">` | `<section id="sec-N" class="section">` | generic | markup only |
| `attendance_taker.php` | same as teacher | `<section id="sec-N" class="section">` | generic | markup only |
| `content_editor.php` | card page, no sidebar | — | none | topbar button + self-routed section (shared JS toggles) |
| `dept_taker.php` | single-card landing | — | none | always-visible account card |
| `finance_dept.php` (shell) | `.school-nav-link[data-section]` (core.js generic) | `<div id="section-N" class="school-section">` | generic + mobile btns | markup only + mobile entry |
| `mezmur_dept.php` (shell) | same (core.js) | `<section id="section-N" class="school-section">` | generic `loadTab` | markup only + mobile entry |
| `hr-dept.php`, `info-dept.php` | native Settings section (reference implementation) | — | own | none — already the pattern being replicated |

Design decision (the anti-root-cause rule): the section UI is **one shared
server-side renderer** (`render_account_section()` in the same component), the JS is
**one runtime** that now binds any number of roots (modal + sections) and lazy-loads
data via a MutationObserver on section visibility — so no per-dashboard JavaScript,
no copy-paste, and future dashboards get parity with one `include` + one wrapper
element following their own router conventions.

### Appendix C.1 — v1.2.0 post-release audit finding (fixed in v1.2.1)

**Bug:** the runtime's MutationObserver watched only the `<section data-wba-section>`
element itself — but every host router toggles the section's WRAPPER
(`#sec-account` gains `.act`, `#section-account` loses `[hidden]`, tab parents gain
`.active`). The section's own attributes never change → the observer never fired →
on surfaces relying purely on their native router (super-admin, content_editor,
mobile bottom-nav entries) the section appeared but stayed EMPTY.

**Why tests missed it:** the explicit `data-wba-nav` click-hook masked the broken
observer on most surfaces, and E2E asserted data-loading only on education.

**Fix (v1.2.1):** observe the whole ancestor chain (≤10 hops) + add the explicit
`data-wba-nav` hook to the 5 missing entry points (super-admin sidebar + mobile,
content_editor tab, edu + school mobile). E2E re-run: 5/5 including the strict
"data auto-loads" criterion on the previously broken surfaces.
