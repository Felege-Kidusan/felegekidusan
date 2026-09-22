# Account Self-Service Solution — "One Identity, Every Surface"

**Project:** WBWS/FKSS Account & Profile Management Parity
**Design authority:** follows the patterns the platform already trusts — the P72/P73
shared-component contract (`admin/components/notification_center.php`) and the
identity primitives already in production (`PasswordPolicy`, `SecurityRateLimiter`,
refresh-token rotation, `activity_logs`).
**Benchmark target:** Microsoft Entra ID / Google Account behavior, OWASP ASVS v4.0.3
(V2, V3), NIST SP 800-63B.
**Companion research:** `docs/audits/ACCOUNT_SELF_SERVICE_RESEARCH.md`

---

## 1. Design principles (the Microsoft/Google rules, made concrete here)

| # | Principle | How it shows up in this system |
|---|---|---|
| 1 | **One identity service, many surfaces.** Google never ships a second "account page" per product; surfaces call one service. | All account reads/writes stay in `admin/api_settings.php` + `api/v1/routes/users.php`. UI is one shared component, never per-dashboard copies. |
| 2 | **A component is a contract, not a snippet.** (The P73 rule.) | `account_settings.php` self-bootstraps config, error-bounds every render (fails to *nothing*, never blanks a dashboard), carries its own CSRF token, loads its CSS/JS once. |
| 3 | **Proof for sensitive changes (step-up).** | Changing email requires the current password (re-auth); changing password already requires it; both are rate-limited and audited. |
| 4 | **Fail loudly at the contract boundary.** | The shared JS client sends the CSRF header on *every* call and surfaces non-JSON/403 responses as explicit errors — the exact failure mode that hid F2 becomes impossible. |
| 5 | **Length-first passwords, no composition theater.** | Keep `PasswordPolicy` (12+ chars, 72-byte bcrypt cap, blocklist). UI adds a live strength meter + requirements checklist mirroring the server policy — never a second policy. |
| 6 | **Every state change is observable.** | `profile_update`, `password_change`, sign-out-all-devices all append to `activity_logs`; the component renders a "Recent security activity" timeline from the same table (Google's "Your activity"). |
| 7 | **Progressive trust.** Ship the trust you can verify today (password, activity, mobile-revocation); stage the rest (mail → recovery → MFA → passkeys) behind feature gates, like `FeatureGate` already does for modules. |

---

## 2. Target architecture

```
┌────────────────────────── SURFACES (13) ──────────────────────────┐
│ super-admin │ school_admin │ edu │ material │ teacher │ attn-taker │
│ content_editor │ dept_taker │ hr* │ info* │ finance shell │        │
│ mezmur shell │ Mobile app (already OK)                             │
└──────────────┬─────────────────────────────────────────────────────┘
               │  include + <?= render_account_settings() ?>   (1 line each)
┌──────────────▼─────────────────────────────────────────────────────┐
│  admin/components/account_settings.php   ← ONE component           │
│   • modal UI (profile card, edit form, password form, activity)    │
│   • self-contained CSRF (data-csrf), error-bounded                 │
│  admin/css/account-settings.css  admin/js/account-settings.js      │
│   • single runtime; fetch wrapper ALWAYS sends X-CSRF-TOKEN        │
└──────────────┬─────────────────────────────────────────────────────┘
               │  ?action=… (session cookie, same-origin)
┌──────────────▼─────────────────────────────────────────────────────┐
│  admin/api_settings.php  ← ONE identity API (role-agnostic)        │
│   profile_get │ profile_update (phone, email re-auth, audit)       │
│   password_change (rate-limited, audit, refresh revocation)        │
│   account_activity (NEW) │ signout_devices (NEW)                   │
│   + PasswordPolicy + SecurityRateLimiter + activity_logs           │
└──────────────┬─────────────────────────────────────────────────────┘
               │
     users · activity_logs · api_refresh_sessions   (MySQL)
```

`*` HR/Info keep their richer native Settings module (dept info, preferences,
system) — after the transport fix they simply work again. They are also the first
candidates to adopt the shared component in a later pass; this change deliberately
does **not** rip working (once fixed) UI out of two production dashboards.

---

## 3. What ships now (P0+P1, implemented in this change set)

### 3.1 New shared component — `admin/components/account_settings.php`
- `render_account_settings(): string` — emits, once per page: the stylesheet link,
  an avatar trigger button (❶ next to the notification bell), the modal panel, and
  the deferred script tag. Cache-busted with `?v=`.
- Contract compliance (mirrors `notification_center.php` header docs):
  self-bootstraps config via `ROOT_PATH` walk-up; every render path wrapped in
  try/catch → `error_log` + empty string; zero dependency on host load order.
- Panel sections: **Profile card** (avatar initial, name, @username, role badge,
  email/phone/member-since/last-login/logins), **Edit profile** (full name, email,
  phone; email change reveals a "confirm current password" field), **Change
  password** (current / new / confirm, live checklist + strength meter, caps at the
  server's 72-byte rule), **Security activity** (last events from
  `activity_logs`), **Sign out mobile devices**.
- Bilingual labels (English + Amharic) consistent with the platform's UI language.

### 3.2 One runtime — `admin/js/account-settings.js` (+ `admin/css/account-settings.css`)
- `WBAccount` IIFE; no framework, no host-page JS dependencies, namespaced CSS
  (`.wba-`) with `prefers-reduced-motion` and dark-host tolerance (panel paints its
  own opaque surface).
- **Hardened fetch wrapper:** always `credentials: 'same-origin'` + `X-CSRF-TOKEN`
  header; treats HTML/403 responses as contract errors with an explicit message —
  the anti-F2 guard.
- Client-side pre-validation mirrors (never replaces) the server: 12-char minimum,
  ≤72 bytes, not in the known blocklist, new ≠ current, confirm match.
- Strength heuristic: length classes + character variety + repetition/sequence
  penalties (zxcvbn-lite; zero dependencies).
- Renders activity timeline, handles `signout_devices`, resets state on open.

### 3.3 API hardening — `admin/api_settings.php`
| Action | Change |
|---|---|
| `profile_get` | also returns `phone` when the column exists (feature-detected; migration 047) |
| `profile_update` | accepts `phone` (validated by the existing `validatePhone()`); **requires `current_password` when the email address changes** (step-up); writes an `activity_logs` audit row ("Profile Updated", details of which fields changed) — parity with password auditing |
| `password_change` | **rate-limited** via `SecurityRateLimiter` (5 attempts / 5 min per account and per IP, mirroring login), identical responses for wrong-password and throttled cases, all existing behavior preserved (re-hash, session regeneration, `AUTH_PASSWORD_VERSION`, refresh-family revocation, audit) |
| `account_activity` *(new)* | last 15 account events + total login count, read-only |
| `signout_devices` *(new)* | revokes every `api_refresh_sessions` family for the user (mobile sign-out-everywhere), audited |

### 3.4 Transport fix (F2) — `hr-dept.php`, `info-dept.php`
`sApiPost()` now sends the `X-CSRF-TOKEN` header (token already rendered in-page at
`hr-dept.php:270`). This single line un-breaks the **entire** legacy Settings module
in both dashboards: profile save, password change, department info, preferences.

### 3.5 Universal wiring — one line per surface
Anchors documented in Research Appendix A. Each surface gains the trigger button +
modal with **zero** changes to its layout system (the modal is fixed-positioned and
self-contained, exactly like the notification panel).

### 3.6 Schema — `sql/047_account_self_service.sql`
Guarded (rerunnable) migration adding `users.phone VARCHAR(20) NULL` in the
exact `information_schema` + prepared-statement pattern of migration 012.
No other schema change — by design; P2+ features get their own migrations.

### 3.7 Tests
- `tests/smoke/account_settings_component_test.php` — renders the component with a
  stubbed session/config (no DB) and asserts: contract markers present, exactly one
  CSRF data-attribute, Amharic labels, no PHP warnings/notices.
- `php -l` on every touched PHP file; `node --check` on the JS runtime.
- **Full E2E against a real MariaDB + PHP 8.4 built-in server** — see §7.

---

## 7. Verification evidence (E2E, MariaDB 11.8 + PHP 8.4, 2026-09-22)

Environment: repo schema + migrations 001–047, seeded users for `hr_dept`,
`super_admin`, `teacher`, `mezmur_attendance_taker`. Real browser-flow logins
(CSRF + cookies) against `php -S`:

| # | Check | Result |
|---|---|---|
| 1 | Login (`hr_dept`) → 302 dashboard | ✅ |
| 2 | `profile_get` via shared role-agnostic API | ✅ |
| 3 | POST **without** CSRF → 403 (F2 bug class reproduced against the guard) | ✅ |
| 4 | `profile_update` **with** `X-CSRF-TOKEN` → success | ✅ |
| 5 | Email change w/o current password → rejected | ✅ |
| 6 | Email change w/ wrong current password → rejected | ✅ |
| 7 | Email change w/ correct step-up + phone → success | ✅ |
| 8 | Phone persisted and returned by `profile_get` | ✅ |
| 9 | `password_change` wrong current password → rejected | ✅ |
| 10 | Server policy enforced (min 12 chars) | ✅ |
| 11 | `password_change` success | ✅ |
| 12 | Old password rejected at login after change | ✅ |
| 13 | New password logs in | ✅ |
| 14 | `account_activity` shows Login + Profile Updated + Password Change | ✅ |
| 15 | `signout_devices` → success | ✅ |
| 16 | 6th password attempt → 429 (rate limited) | ✅ |
| 17 | Component renders on super-admin, edu (×2), material (×2), teacher, attendance taker, content editor, dept taker, finance shell, mezmur shell; HR/Info keep their native (now fixed) module | ✅ |

Component render smoke test: `php tests/smoke/account_settings_component_test.php`
→ 6/6 ok. `php -l` clean on all 15 touched PHP files; `node --check` clean.

---

## 4. Roadmap (P2 → P3) — the path to Entra/Google parity

### P2 — Trust & recovery (next 1–2 phases)
1. **Device/session registry** — new `user_sessions` table (session id hash, UA,
   IP, created/last-seen, revoked_at) written at login; "Your devices" page;
   true sign-out-everywhere for browsers (revoke row → middleware forces re-auth).
2. **Security timeline v2** — surface `activity_logs` with geo/IP context and
   "wasn't me" reporting.
3. **Mail groundwork** — PHPMailer + queue table + `system_settings` SMTP keys;
   first consumers: email-change notifications and the **verified recovery email**;
   then the real **forgot-password** flow (single-use, 15-minute, hashed tokens —
   ASVS V2.5) replacing the dead `Forgot password?` link (`admin/index.php:341`).
4. **Onboarding checklist for new roles** — a documented definition-of-done:
   "new role ⇒ dashboard includes notification center, comm section, bottom nav,
   **account component**, and passes the browser-contract suite."

### P3 — Identity-grade
1. **TOTP MFA** (`users.totp_secret`, provisioning QR, backup codes, "remember this
   device" 30-day cookie) — behind `FeatureGate::mfa`.
2. **Breach-password check** — k-anonymity HIBP range API (only the first 5 chars
   of the SHA-1 hash ever leave the server; fails open on network error so
   availability never depends on the third party).
3. **Self-service username change** — once per 180 days, super-admin notification,
   audit + member-facing username propagation checks.
4. **Passkeys (WebAuthn)** as a phishing-resistant second factor.
5. **Verified phone (SMS)** once a gateway is contracted.

### Explicit non-goals
- SSO/IdP federation — no directory service exists to federate with yet; revisit if
  the school network grows beyond this deployment.
- Replacing session auth with the mobile JWT flow for browsers — cookies + CSRF is
  the correct browser model; keep the two stacks purpose-built.

---

## 5. Security review notes for this change set

- CSRF: component carries its token in `data-csrf` at render time (per-request),
  JS reads it per call; header-based (never query-string) → not logged, not referer-leaked.
- XSS: all dynamic values rendered via `textContent` assignments or server-side
  `e()`; no `innerHTML` with user data anywhere in the runtime.
- Step-up: `current_password` is verified with `password_verify` only when email
  changes; wrong password = generic error, no user enumeration.
- Rate limiting: per-account **and** per-IP buckets (prevents both credential
  stuffing and single-account spraying); limiter failure fails **open** for
  availability (same trade-off as the platform's login limiter) while the
  current-password proof still gates the action itself.
- Audit: profile updates log *which fields changed* (names only — never values for
  password; email logged as changed, not displayed in full in the timeline UI).
- Availability: component errors are contained (render → empty string + log);
  API keeps all existing response contracts for legacy callers.
- Privacy: activity timeline shows the acting user only their own rows.

## 6. Acceptance criteria

1. Every one of the 13 surfaces can open "My Account" and see real profile data.
2. A password change from **any** surface succeeds (200), revokes mobile sessions,
   and appears in Security activity — verified on hr/info legacy module too.
3. Changing email without the current password fails with a clear message; with it,
   succeeds and is audited.
4. 6th wrong-password attempt in 5 minutes is throttled (429/`Retry-After`).
5. No dashboard's layout, JS, or section routing changes in any way when the
   component is present but unopened; a simulated component exception leaves the
   host page fully functional.
6. `php -l` clean on all touched files; component smoke test green.
