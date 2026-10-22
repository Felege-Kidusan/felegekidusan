# PRODUCTION READINESS AUDIT — SSMS (pre-sync gate)

> **Historical point-in-time audit (marked 2026-10-22).** This verdict applied to `main @ 4ea1e50` on 2026-10-17 and a beta/test target. It is not a statement about the current system. See [docs/ENGINEERING_HANDOVER.md](../../ENGINEERING_HANDOVER.md).


Date: 2026-10-17 · Auditor: Arena agent · Repo state: `main @ 4ea1e50`
Target: `felegekidusan.arkeonethiopia.com` (shared hosting, Apache + PHP + MariaDB)

Verdict: **READY TO SYNC** — with the migration checklist in §4 executed immediately after code deploy.

---

## 1. Scope of changes pending in production

All commits on `main` since the last production sync (production currently exhibits the
pre-fix New Members errors, so it predates at least `59efc06`):

| Commit | Content |
|---|---|
| `59efc06`…`3fac4b2`…`48c2c39` | promotion modal fixes, enterprise prefill |
| `3c849ba` | registration fixes (stored XSS, polyglot/PDF, quick_add gating, edit audit+transaction, rate limit) |
| `9ee99ec` / `cae69e6` | Phase A conditional form / Phase B manage-member preview |
| `70237d0` / `7506dc9` / `b125c1d` | ZAP Phase 1 (dir browsing, API CSP/HSTS/no-store, CORS wildcard, form POST, SRI scripts) |
| `b190974` | P2 role gating (edit + registration) |
| `cd6069b` | P4 preview UX (2-col cards) |
| `ac34f9d` | P5 font-awesome SRI |
| `18c8ab3` | P6 input length caps |
| `596702b` | Rebrand: app icon + splash assets (mobile/web) |
| `4ea1e50` | test-regression fix: age filter sourced from MemberCategory |

Deploy model: **full `main` sync** (commits are incremental patches of one codebase; cherry-picking is riskier than a whole-tree sync).

## 2. End-to-end test evidence (local sandbox, PHP 8.4 + MariaDB 11.8)

| Suite | Result |
|---|---|
| `php -l` sweep, 360 files | 0 syntax errors |
| Migration chain 001→068 on fresh DB | applies clean apart from documented drift (§4) |
| Custom e2e HTTP suite (`/home/user/e2e_run.sh`) | **35/35 PASS** |
| `tests/security` (pytest) | **2107 passed, 0 failed**, 419 skipped (env-gated: flutter/network) |
| `tests/security/test_destructive_guard.py` | 10 passed (fail-closed interlocks verified at runtime) |
| `node --check admin/js/new-members.js` | OK |

e2e coverage highlights: login/CSRF/session; HR + Info dashboards; New Members API
(`view=new_members&membership_tier=temporary`) returns only temporaries, `eligible_for_promotion=1`
returns only the 7-month member and excludes the 2-month member; manage-member HTML + `?format=json`;
member edit accepted for super_admin & info_dept, 150-char input rejected by P6 caps; registration
POST blocked for info_dept (302 page gate) and rejects bad CSRF (403); API responses carry
`Cache-Control: no-store`, CSP, X-Frame-Options and **no** wildcard CORS; `/api/` returns 404 JSON
(no directory listing); cron detector notifies once for the eligible member, creates a department
task, sets `permanent_eligibility_notified_at`, and **does not duplicate on a second run**; the
2-month member is untouched.

Regression found & fixed during audit: New Members age-group filter had hardcoded
`<option value="7_13">…` violating the MemberCategory single-source-of-truth contract
(`tests/security/test_section_source_of_truth.py`). Fixed in `4ea1e50`; suite now fully green.

## 3. Not verifiable in this sandbox (residual risk, low)

1. **Apache `.htaccess` behaviour** (`Options -Indexes`, `api/.htaccess`) — PHP built-in server ignores htaccess. Syntax reviewed manually; production is Apache so directives apply. Verify after deploy: `curl -I https://…/api/` → 404, `https://…/admin/js/` → 403/404 not a listing.
2. **Flutter build** — no SDK here. Icon/splash changes are pure asset replacements at existing dimensions; rebuild APK/IPA to ship. `pubspec.yaml` sources updated so regenerators agree.
3. **Production PHP version** — code lint-clean on 8.4; repo audit baselines include 8.2. If production runs 7.4, confirm with host (cPanel → PHP selector); nothing in the pending commits uses PHP 8-only syntax in the touched files (verified by lint on 8.4 + prior 8.2 baseline).
4. **`tests/smoke/*` harnesses** require their CI-provisioned `ssms_smoke` fixture schema; not runnable against an empty DB by design. Covered instead by the e2e suite above.
5. HSTS header only emits over HTTPS (by design); production serves HTTPS → active there.

## 4. Production DB drift checklist (run AFTER code deploy, in this order)

Production schema evolved organically; these objects exist in code expectations but are NOT
created by the versioned `sql/*.sql` track. Run the detector first, then apply only what's missing:

```sql
-- drift_detector.sql (read-only)
SELECT 'members.permanent_eligibility_notified_at' AS obj,
       IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE()
         AND table_name='members' AND column_name='permanent_eligibility_notified_at'),'OK','MISSING') AS st
UNION ALL SELECT 'members.membership_tier', IF(EXISTS(SELECT 1 FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='members' AND column_name='membership_tier'),'OK','MISSING')
UNION ALL SELECT 'departments.sort_order', IF(EXISTS(SELECT 1 FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='departments' AND column_name='sort_order'),'OK','MISSING')
UNION ALL SELECT 'table department_tasks', IF(EXISTS(SELECT 1 FROM information_schema.tables
  WHERE table_schema=DATABASE() AND table_name='department_tasks'),'OK','MISSING')
UNION ALL SELECT 'index idx_members_tier_status_registered', IF(EXISTS(SELECT 1 FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='members' AND index_name='idx_members_tier_status_registered'),'OK','MISSING');
```

Fixes when MISSING:
- `permanent_eligibility_notified_at` + index → run `sql/068_member_permanent_eligibility_notified.sql` (idempotent, `IF NOT EXISTS`).
- `department_tasks` → DDL in `backend/migrations/002_migration.php` line 373 (copy in `/home/user/department_tasks_ddl.sql`).
- `departments.sort_order` → `ALTER TABLE departments ADD COLUMN sort_order INT NOT NULL DEFAULT 0;`
- `membership_tier` → should already exist (New Members is live in production); if MISSING stop and investigate before deploying.

Code is defensive: the New Members list and manage-member page work before migrations run
(defensive SELECTs); only the **cron dedupe** needs 068. Therefore: code first, migrations
immediately after, then confirm one cron run.

## 5. Deploy runbook

1. Backup: cPanel → full DB dump + `public_html` archive (or existing `cron_backup.php` output).
2. Sync code (whole tree) — see §6 for repo/server mechanics.
3. Run `drift_detector.sql`; apply missing fixes (§4).
4. Confirm `.fkss_env.php` (above web root) untouched; permissions 0600.
5. Confirm cron entry exists: daily `php …/admin/backend/notify_permanent_eligibility.php`.
6. Post-deploy smoke (logged-in browser or curl with session): login → HR dashboard → New Members
   lists temporaries, eligible row green; open Promote modal (prefill loads); save a member edit
   (audit row appears); `curl -I /api/v1/members` shows `no-store` + CSP; `/api/` → 404 JSON.
7. Mobile: rebuild & release app (new icon/splash). No API contract change → old apps unaffected.

## 6. Rollback

- Code: revert to previous production tree (git tag/branch snapshot taken at step 1).
- DB: all pending migrations are additive (`ADD COLUMN IF NOT EXISTS`, new index, new table);
  leaving them in place after a code rollback is harmless. No data-destructive migration in scope.

## 7. Standing deferred items (accepted, not blockers)

- P3 AV scanning: infeasible on shared hosting; mitigations in place (GD re-encode, PDF-as-attachment, MIME/extension allow-list, private storage).
- CSP `unsafe-inline` removal (nonce refactor) — dedicated future task.
- Suspicious-comment cleanup (informational).
