# Members App Development — Progress & Starting Point

Status: auth design ON HOLD (2026-10-17). **Family Link (guardian/sibling linkage) SHIPPED 2026-10-18 — commit `95e40be` on both remotes** (prerequisite for the parent app). No member-app auth code exists yet.
Companion doc: `MEMBER_APP_SIGNIN_PROPOSAL.md` (§1–8, full auth research & design).

---

## 1. Where we stand (starting point)

### Decided (design frozen pending build-plan approval)
- **App shape**: separate "FKSS Members" APK (D3).
- **Activation**: agent-guided vault ceremony — HR opens vault via member ID no/QR → system issues one-time OTP handed **in person** → member sets **ID no + password**; same session silently registers the phone's **hardware key** (Keystore/Secure Enclave, non-exportable, biometric/PIN-gated) and binds the device (§7 hybrid).
- **Daily login**: profile tap + biometric/PIN; tokens sender-constrained to the device key (DPoP, RFC 9449) on top of the existing rotating-refresh + replay-revocation engine (`api/v1/core/auth.php`, `sql/010`).
- **New device / forgotten password**: registered **optional** verified channel (e-mail or Telegram username, set in Settings after first login) with OTP + 24 h cooling-off + loud notifications; families without any channel use the in-person agent ceremony (universal fallback) (§8 recovery ladder).
- **No e-mail/phone/SMS/Telegram anywhere in the mandatory auth path** (many families have none).
- **Member-scoped data**: own attendance, own published grades, hymns/lyrics catalog; server derives member_id only from verified tokens.
- **Parent app**: confirmed as an incoming idea → drove the sibling/guardian analysis below (§3), which is now a **prerequisite design input** for both apps.

### Next step (awaiting user start signal)
Phase-1 build plan (plan-only first): migrations (member_accounts, activation vault tickets, device keys, recovery contacts, guardian linkage §3.4), `/api/v1/member/*` endpoints, admin vault/approval UI, optional Telegram bot, Members APK skeleton.

---

## 2. End-to-end analysis: how siblings ↔ parents/guardians are linked TODAY

Method: repo-only evidence (schema SQL, PHP services, admin UI, Flutter forms). Production DB not queried (sandbox DB offline); production *data quality* therefore UNKNOWN.

### FACTS
1. **Guardian data is denormalized per child.** `members` carries the columns `guardian_name`, `guardian_phone1`, `guardian_phone2`, `phone_guardian`, `guardian_city/sub_city/woreda/mender/block_number/house` (database_schema.sql:42–140; populated by `admin/hr_register_member.php` and Flutter `register_member_screen.dart`).
2. **There is NO guardian/parent entity and NO family/household id.** Zero occurrences of `family_id`, `household_id`, `guardian_id` in the members schema or anywhere in sql/ (grep = 0). No `guardians` table exists.
3. **Names are patronymic parts, not links.** `father_name` / `grandfather_name` are split from the Amharic full name at registration (hr_register_member.php:204–208) — naming convention, not a relational key.
4. **No code path enumerates siblings.** The only family-ish query is a *data-quality duplicate check*: `GROUP BY student_name, father_name HAVING c > 1` (super-admin.php:485). Guardian phones are used as a *duplicate-detection hint* via suffix matching (`MemberDuplicateService.php:230`), not as identity.
5. **Consumers of guardian fields are per-member displays/exports**: ID card, print_member, member_file, reports, directory, Excel import map — all read the child's own copy of the guardian columns.
6. **Two independent entry points write these fields** (web HR form + Flutter register screen), each storing a fresh copy per child.

### INFERENCES
- Siblings are linked **only by human data-entry consistency**: the same guardian name/phone typed again for each child. A typo, a changed phone updated on one child only, or two guardians (mother on one child, father on another) **splits one family into unrelated rows** with no way to detect it.
- "All children of guardian X" is currently answerable only by fuzzy text/phone matching — unreliable for anything access-control-related (a parent app must never authorize by string match).
- Finance/fees are per-member (no family billing concept found), so nothing today depends on family grouping — the parent app will be the first real consumer.

### UNKNOWN
- Production fill-rate/consistency of guardian_* columns (can't query prod from here).
- Whether HR currently re-types or copies guardian data between siblings in practice.
- Real-world ratio of multi-guardian families (divorced/extended-family guardianship patterns).

### ANALYSIS INCOMPLETE (explicit)
- Production data profiling (duplicate-guardian clustering quality) not performed — needs a read-only query pack run by the user on prod, or a sanitized export.
- Finance module reviewed only shallowly for family concepts (no family grouping found in api_finance.php).

---

## 3. Consequences for the parent app + recommended linkage model

### 3.1 Why today's shape cannot authorize a parent app
Authorization needs a **stable, editable-once, many-to-many** relation: one guardian → N children; one child → 1–2 guardians (mother + father, or non-parent guardian). Per-child free-text copies provide none of: stability, dedupe, revocation, audit.

### 3.2 Recommended model (to be planned, not built yet)
- New table `guardians`: id, display_name, phone_primary, phone_alt, address columns (moved semantics), photo_path, status, created_by/at.
- New link table `member_guardians`: member_id, guardian_id, relation ENUM('father','mother','other'), is_primary, verified_at/by, created_by/at — unique(member_id, guardian_id), unique(guardian_id, member_id) natural PK.
- **One-time backfill**: cluster existing members.guardian_* by normalized phone suffix + name similarity → propose guardian rows + links → **HR confirms/merges in an admin review queue** (no silent auto-merge).
- `members.guardian_*` columns remain as legacy display copies during migration, then deprecated (reads switch to the join).
- **Parent app account = guardian row**; scope = linked member_ids; the member-app auth design (§1) applies unchanged with actor_type='guardian' and a child-switcher in the UI.
- Sibling features later (family fee view, sibling timetable) fall out of the same join for free.

### 3.4 How HR links one parent to siblings easily (professional pattern, researched 2026-10-17)
Not a typing filter — three layers (MDM golden-record + school-SIS pattern):
1. **Prevention (live picker)**: at registration (web + Flutter), guardian fields drive a live
   candidate search ("link existing guardian"): name/phone typeahead showing existing guardian
   with their already-linked children; pick + relation = done. "Create new" only after an
   explicit no-match confirmation. Sibling enrollment can also start FROM an existing family
   (pick sibling -> guardian/address prefilled). Skyward/PowerSchool UX model.
2. **Detection (Fellegi-Sunter style scoring)**: normalized keys (phone digits, last-9 suffix;
   case/punct-stripped names) + weighted similarity (phone-suffix exact = strong; name
   Jaro-Winkler/trigram >= ~0.92 = strong, typo-tolerant; address tokens = supporting).
   Three-way outcome: auto-link (high) / review queue (middle band) / new guardian (low).
   Multiple blocking passes (phone suffix, name phonetic/trigram, address) so one bad field
   cannot hide a true sibling.
3. **Resolution + monitoring (stewardship)**: HR review queue with side-by-side cards and
   one-click merge / keep-separate, fully audited; nightly re-match sweep proposes re-links
   after drift (e.g. phone changed on one child only); guardian row is the SINGLE SOURCE OF
   TRUTH — editing a phone once updates every linked child (Skyward property), removing the
   main historical split cause.
Amharic-specific: orthographic variants of the same name are an open research item (trigram
similarity covers small edits; an optional alias/aka field on guardians covers known variants).
Thresholds to be tuned from the production profiling pack (Q1 in §4).

### 3.3 Rejected alternatives
- Match by `father_name` string: patronymic collisions + typos → wrong-family authorization risk.
- Match by `guardian_phone1`: phones change, are shared/reused, and are missing for some families; also leaks authorization to whoever inherits a SIM.
- Add `family_id` column on members only: cannot express two guardians per child or a guardian's second family without another link table anyway.

---

## 4. Open questions for the user (before phase-1 planning)
- Q1: Can HR run a read-only profiling query pack on production (guardian fill-rate, phone-format spread) so backfill clustering thresholds are chosen from real data?
- Q2: Should the parent app see **grades before publication** (parent-early-access) or same publication gate as members?
- Q3: Multi-guardian disputes (divorced parents both registering) — policy: both get access by default, or HR flags per family?
- Q4: Finance: keep per-member fees, or introduce family-level fee views in the parent app later?

---

## 5. Family Link — DELIVERED (2026-10-18, `95e40be`)
Implements §3.2 + §3.4. Deployment steps for production (shared hosting):
1. `git pull` on the server (vendor/edgaras/strsim is committed; no composer needed; PHP floor stays 8.2 = ea-php82).
2. phpMyAdmin: run `sql/069_guardian_linkage.sql` (idempotent; 4 new tables; no generated columns/JSON/CHECK).
3. Hard-refresh the HR dashboard (new "Family Link" nav + picker in registration).
4. Backfill (cPanel Terminal or SSH, one-time, repeat until "deferred 0"):
   `php admin/tools/guardian_backfill.php --dry-run` → review → `--execute`.
   Conservative: clusters only on identical phone (last 9 digits) or identical name when no phone; variants become aliases; near-duplicates are left for the review queue.
5. HR then works the Review queue (Family Link page) — merge / keep separate.
P4 delivered — see §6. Still open: threshold tuning from prod profiling numbers; parent-app accounts on `guardians` (actor_type='guardian') — ON HOLD with members-app planning.
Verified in sandbox: backfill idempotent; phone edit on one child propagates to all siblings and vice-versa; typo+same-phone auto-links; identical name/different phone → review suggestion → one-click merge; info_dept read-only (403 on write); CSRF enforced; merge validated & reversible; full suite 2115 passed.

## 6. Family Link P4 — DELIVERED (2026-10-18)

Scope correction (user mandate): members are NEVER registered via the Flutter app. All mobile registration code was removed instead of adding a picker.

- **P4.0 Mobile registration removed**: Flutter `register_member_screen.dart` deleted (was already unreachable), `ApiService.createMember` removed, server `POST /api/v1/members` handler removed → 404 "No handler". Contract tests pin the removal.
- **P4.1 Mobile guardian edits reverse-sync**: `PUT /api/v1/members/{id}` with `guardian_name/phone1/phone2` updates the member's primary guardian via `GuardianService::update`, which propagates to every linked sibling. Only fields present in the payload are pushed (name-only edit keeps the guardian's phone); name falls back to the guardian row when the legacy column is empty. Best-effort: a sync failure never fails the member update.
- **P4.2 Bounded re-match sweep**: `GuardianMatchService::rematchBatch` (≤300 guardians / ≤20 s per step, cursor-resumable, each pair once, skips pending/rejected pairs, files review+auto bands as *pending suggestions*, never merges). Triggers: HR dashboard → Family Link → **Run re-match now** (JS steps until done, with progress), and CLI `admin/tools/guardian_rematch.php` for optional cPanel cron. Review-queue badge added to the Family Link nav. Audit action `Guardian Rematch`.
- **P4.3 Profiling pack**: `sql/preflight/guardian_profiling_pack.sql` — 8 read-only queries (fill rates, phone digit lengths, script mix, phone-cluster histogram, same-phone/different-name, same-name/different-phone, suggestion band distribution, source). Thresholds unchanged until the user shares results.

Verified in sandbox: CLI sweep files 2 suggestions (auto 1.00, review 0.55) and 0 on re-run; rejected pair is never re-filed; HR button + badge render; `POST /api/v1/members` → 404; mobile phone edit propagates to guardian + legacy columns; name-only edit preserves phone. Full suite 2119 passed / 435 skipped.

Deployment (no new migration): `git pull`; hard-refresh HR dashboard; click **Run re-match now** once (safe on shared hosting — short bounded requests); optional cron `30 2 * * * /usr/local/bin/php /home/USER/public_html/admin/tools/guardian_rematch.php`; run the profiling pack in phpMyAdmin and share the numbers. Flutter staff app needs a rebuild to drop the dead registration code (no behaviour change for users).

## 7. Pre-deployment deep audit — DELIVERED (2026-10-18)

Full code read of the Family Link surface + HTTP end-to-end of every workflow (`tests/e2e/family_link_e2e.py`, 67 checks) against a fresh DB. Gaps found and patched:

| # | Gap | Fix |
|---|-----|-----|
| G1 | HR UI looked guardians up by id through the *search* action → Edit / +alias / add-child never refreshed | New read action `get`; UI uses it |
| G2 | `merge_suggestion` unguarded; pending suggestions about a merged guardian went stale | try/catch → 409 + auto-expire; merge re-points other pending pairs to the survivor; queue self-heals |
| G3 | Empty guardian name accepted; links to unknown/merged guardians or unknown members accepted | Validation with user-facing messages (422) |
| G4 | Legacy `members.guardian_*` sync hit **all** linked children; member could end up with no primary | `syncLegacy` = primary links only; `ensurePrimary` after link/unlink/merge; first link auto-primary |
| G5 | Picker-registered members kept blank typed fields | Legacy columns refreshed from the guardian row |
| G6 | Guardian entered for the first time via web edit / mobile PUT never linked | Shared `GuardianService::attachFromLegacy` used by registration, web edit, mobile PUT (upgrade path = edit, not duplicate) |
| G7 | Merge dropped loser's phones/address/photo and spelling; children's legacy columns not refreshed | Survivorship (fill blanks, loser phone → alt phone, loser name → alias), legacy re-sync |
| G8 | No unlink control; API errors swallowed in UI | × per child, ★ primary marker, alerts on error |
| G9 | Re-match ignored alt phone; 20 s step vs 30 s host cap | Alt-phone blocking; 15 s budget + `set_time_limit` |
| **G10** | **JS syntax error in the Family Link script block (nested quotes) — entire HR Family Link UI dead in the browser** | Fixed (`famClearPick`) + node parse test over all inline scripts |
| Bonus | Pre-existing production JS syntax errors: `groups.php` (PHP `.` concatenation inside JS, 2×) and `school_admin.php` (template literal missing backticks, semester reopen row) — whole page scripts dead | Fixed; rendered-page parse sweep over all 85 admin pages now clean |
| UX | Member profile had no family view | Read-only **Family** card (guardians ★, aliases, sibling links) |

Behaviour note for production backfill: clustered members' `guardian_name/phone` columns are rewritten to the canonical guardian spelling (variant kept as alias).

## 8. Family Link UI/UX overhaul — DELIVERED (2026-10-18)

User-reported: HR Family Link section looked empty / headings white-on-white; "a run re-match button is not enough"; sibling link on a member profile lost the sidebar with no way back.

| Area | What changed |
|---|---|
| **A. Family Link page** (`admin/js/family_link.js` + `#section-family` in hr-dept.php, light theme) | KPI strip (Guardians · Families 2+ · Members linked · Not linked yet · Needs review) · tabs **Families** (search by name/alias/phone incl. Amharic, filters, paged table → guardian **drawer**: inline edit form, alias chips, children table with relation dropdown / ★ Primary badge or "Make primary" / open profile / unlink, add-child typeahead, merge-into picker) · **Needs review** (side-by-side compare with evidence chips, Keep A / Keep B / keep both, Run re-match with progress + last-run stamp) · **Not linked yet** (legacy members with guardian text but no record; per-row Link + bounded in-browser **Link all** = replaces the CLI backfill) · **Activity** (guardian audit log). `?section=family&guardian=ID` opens the drawer. |
| **B. Member profile Family card** (`info_manage_member.php`, `admin/js/member_nav.js`) | Sibling clicks stay inside the dashboard sheet with "← Back to previous member"; "Manage family" deep-links into the drawer; a direct profile URL bounces to `dashboard.php?member=ID` (sheet opens, sidebar kept). Works for hr_dept, info_dept, school_admin, super_admin. |
| **C. API** (`api_guardians.php`, `GuardianService.php`) | Read: `stats`, `list_guardians`, `unlinked_members`, `activity`. Write (role+CSRF): `attach_member`, `attach_batch` (40 rows / 15 s per step), `set_relation`, `remove_alias`; `merge_suggestion` accepts `keep=a|b`. No schema change. |
| Also | Registration guardian picker restyled for the light form; pre-existing `loadManageMembers is not defined` error in `navigateToSection('manage')` guarded. |

Verification: pytest `TestFamilyLinkUI` (10 pins) + repointed `TestP4Audit`; suite 2140 passed; API E2E `tests/e2e/family_link_e2e.py` 104/104; NEW browser walk-through `tests/e2e/family_link_browser.py` (Playwright/Chromium, 27 UI journeys incl. mobile viewport, 0 console errors).
Deployment: `git pull` + hard refresh (assets are versioned). No SQL. The "Not linked yet → Link all" button replaces running `admin/tools/guardian_backfill.php`.

## 9. Parent App — feature baseline LOCKED (2026-10-18)

Owner-supplied feature list recorded verbatim in `/home/user/PARENT_APP_FEATURE_BASELINE.md` (baseline v1.0, 19 sections, 3 release phases). Nothing built. Appendix A maps each area to existing SSMS pieces; Appendix B lists 6 owner decisions required before Phase 1 planning (one app vs. two, parent = guardian vs. member, teacher role, lesson-confirmation workflow, push provider, retention). Detailed planning starts only on owner instruction.
