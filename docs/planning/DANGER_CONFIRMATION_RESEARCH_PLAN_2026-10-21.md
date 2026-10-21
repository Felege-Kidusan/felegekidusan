# Danger Confirmation System — Research, Full Risk Inventory & Plan

Date: 2026-10-21 (Tikimt 11, 2019 EC) · Repo state: `a64bc93` (main) · Status: **APPROVED (owner decisions §8) — implementation in progress.**

Trigger: Timetable → "Remove day" warns *"Removing a day permanently clears any lessons already scheduled on it"* but only asks a browser `confirm()` when lessons exist, and nothing at all otherwise (FACT `admin/js/edu_timetable.js:231,369`).

Owner request: type-to-confirm + 3-click confirmation, a proper modal, a clear message and a clear danger UI, for every high/medium/low-risk action in every department and section. List everything first. No production deploy until the owner finishes the dev-server test.

---

## 1. Research — how big software protects risky actions

| Source | What they do | What we take |
|---|---|---|
| **GitHub — delete repository** (docs.github.com) | Danger Zone → "Delete this repository" → **"I want to delete this repository"** → warnings list → **"I have read and understand these effects"** → **type `owner/repo`** (button disabled until exact) → red "Delete this repository". Used ONLY for repo/org/account deletion. | The 3-step + typing flow for our HIGH tier only. |
| **AWS S3 / Cloudscape** | Two patterns: *simple confirmation* (modal, bold resource name, "You can't undo this action") for resources whose loss breaks nothing else; *additional confirmation* (type bucket name or "permanently delete") **only for high-severity deletes** — "adding friction to low severity deletions slows users down and creates frustration". | Proportional friction; bold object name; "can't be undone" sentence. |
| **GitLab Pajamas — destructive actions** | **High:** modal with danger button + type the object name when nested data is removed; list **counts** of what will be destroyed ("40 issues, 16 MRs"); buttons "Yes, delete project" / "Cancel, keep project". **Medium:** at least 2 clicks. **Low:** easily undone, little or no friction. | Our 3 tiers; counts in the modal; verb+object button labels. |
| **NN/G — Confirmation dialogs** | Confirm only serious/irreversible actions; never routine ones (habituation → people click OK blindly); be specific, never "Are you sure?"; buttons say the outcome; no destructive default; for very dangerous actions require a non-standard action such as typing. | Ban "Are you sure?"; Cancel is default; typing for HIGH. |
| **Primer (GitHub design system) ConfirmationDialog** | Title is a short specific question ("Delete repository?"); danger button; **initial focus on Cancel for danger dialogs**; Esc = cancel; focus returns to the trigger. | Accessibility & focus rules. |
| **Smashing Magazine 2024** | Title names the entity; warning icon (colour-blind safe); CTA = verb + entity; type-to-confirm keeps button disabled until match; undo toast for reversible actions; Danger Zone styling. | Icon + colour + text (never colour alone). |
| **OWASP ASVS 3.7.3 / Auth cheat sheet** | Highly sensitive operations need re-authentication/secondary verification **enforced server-side**, not only in the browser. | HIGH actions also checked on the server (confirm phrase), like the existing `SWITCH`/`ROLLOVER` checks. |

**Key conclusion:** making *every* click type-and-triple-confirm causes habituation and makes the truly dangerous dialogs less safe. All 6 sources scale friction to the blast radius. The plan therefore uses three tiers (owner decision D1 below).

---

## 2. What the system does today (FACT)

- **67 native browser `confirm()` popups + 6 `prompt()`** across 27 files. Native popups can't show formatting, counts or icons; Enter = OK; the browser offers "prevent this page from creating more dialogs" which **silently auto-cancels or blocks** them.
- **4 different in-house dialogs**: `sysConfirm` (mezmur.js, 10 uses), `modal()` (identity codes, 2), `confirmModal` (groups.php, 5), `showConfirm` (account.js, 2). Each looks and behaves differently.
- Messages are often generic: "Delete this class?" (`edu_dept.php:2110`), "Delete this item?" (`material_department.php:247`), "Are you sure you want to delete or deactivate this assessment type?" (`edu_dept.php:3014`).
- **Typed confirmation already exists in 2 places**, done with a browser `prompt()`: switch/reopen year (`school_admin.php:1330`, server checks `SWITCH`/`REOPEN` at `api_education.php` set_current_year), and Year Rollover (server checks `ROLLOVER`, `tools/year_rollover.php:112-115`). **Password re-entry** exists for Delete User (`super-admin.php:749-752`, `backend/user-delete.php`).
- **About 56 write actions have no confirmation at all** (first-pass scan, `rc_test/risk_scan.py`). Most of them are fine as they are (save forms, mark read), but some are not (see §4 ⚠ rows).
- Mobile app (`Mobile/wbws_flutter_app`) already uses specific `AlertDialog`s ("Delete for everyone?", "Remove all downloads?"). It only calls `/api/v1`, never `admin/api_*.php` (FACT: grep returns 0), so server-side checks on admin endpoints cannot break the APK.

### Findings found during the analysis (not fixed — need approval)

| # | Finding | Evidence |
|---|---|---|
| F1 | **Delete semester has no grade check.** The server hard-deletes a semester of an open year even when assessments/grades reference it; the UI only says "Grades linked to it may be affected." | `api_education.php` case `delete_term` (only blocks closed years) |
| F2 | **Copy bell schedule wipes all lessons** of the target class (DELETE timetable_entries + periods) behind a modal with a plain "Copy" button. | `TimetableService.php:563-568`, `edu_timetable.js:400-408` |
| F3 | **Remove working day** also stops attendance on that weekday (class-day rule), which the warning does not mention; there is no dialog when the day has no lessons. | `edu_timetable.js:369`, `SchoolCalendarService` class-day rule |
| F4 | **Info → Change status** asks the user to *type the status word* in a `prompt()`; a typo is rejected only afterwards. | `info-dept.php:4174-4176` |
| F5 | Dead code: Education `setCurrent()` (plain confirm, no SWITCH phrase) has no caller. | `edu_dept.php:3356` |
| F6 | "Delete teacher" says "This cannot be undone", but the server only deactivates the login and suspends assignments (reversible). The message is wrong. | `edu_dept.php:2102`, `api_teachers.php` delete_teacher |
| F7 | "Remove practice members" (deletes all TEST-FKSS members) sits on the production super-admin page behind a plain confirm. | `super-admin.php:718` |

---

## 3. Tier rules (proposed)

| Tier | When | What the user sees |
|---|---|---|
| **HIGH** (3 clicks + typing) | Irreversible **and** wide: cascades to other data, bulk/many records, other people's work, or whole-system settings. | **Step 1 — Intent:** red title "Delete class 3ኛ ክፍል?", one-line summary, button **"I want to delete this class"**. **Step 2 — Consequences:** bullet list **with live counts** ("12 scheduled lessons will be deleted", "Teachers can no longer take attendance on Monday"), "This cannot be undone." Button **"I have read and understand these effects"**. **Step 3 — Type to confirm:** "Type **DELETE CLASS** to confirm"; red final button **"Delete this class"** stays disabled until the text matches. The server also checks the phrase. |
| **MEDIUM** (owner D1: same 3 clicks + typing as HIGH; server check stays HIGH-only) | Single-record irreversible delete, or a reversible but impactful change (deactivate, archive, status change, approve/reject, settings that affect others). | Same 3-step modal as HIGH, amber accent. Originally proposed as one modal: amber/red icon, specific title naming the object, what will happen and what will *not* happen, reason field where the server already wants one. Buttons: **"Cancel"** (focused) and verb+object, e.g. **"Archive member"**. |
| **LOW** | Easily undone, personal, or recomputable (clear own chat, remove own photo, clear cache). | Small modal with specific text and verb button, or no dialog plus an undo/toast where the action is routine. |

**Rules for all tiers:** one shared component; `role="alertdialog"`, `aria-labelledby/-describedby`; Cancel gets initial focus; Esc/✕/backdrop = cancel (disabled while working); Enter never triggers the destructive button; double-submit guard (button shows "Deleting…"); focus returns to the trigger; icon + colour + text; no "Are you sure?"; never all-caps sentences; works on phones (full-width sheet ≤ 480px). The typed phrase is always **plain English capitals** (e.g. `DELETE CLASS`, `REMOVE DAY`) because Amharic names are hard to type on many keyboards; the object's Amharic name is shown in bold. Matching is trimmed and case-insensitive (Cloudscape style).

---

## 4. Full inventory — every risky action by department & section

Legend: **Now** = current protection. `confirm` = browser popup, `prompt` = browser text box, `dialog` = in-house modal, `—` = nothing. **Tier** = proposed. ⚠ = current protection is clearly too weak for the risk.

### 4.1 Education Department (`admin/dashboards/edu_dept.php`, timetable, calendar, assignments)

| # | Section → Action | Trigger | Server effect (FACT) | Now | Tier |
|---|---|---|---|---|---|
| E1 | Timetable → **Remove working day(s)** | `edu_timetable.js:369` | DELETE timetable_entries on those days; class-day rule stops attendance that weekday | confirm only if lessons>0 ⚠ | **HIGH** |
| E2 | Timetable → **Copy bell schedule from another class** | `edu_timetable.js:400-408` | DELETE all entries + periods of this class, copy days/periods | dialog "Copy" ⚠ | **HIGH** |
| E3 | Timetable → Delete period | `edu_timetable.js:394` | DELETE period (+ its lessons) | confirm | **HIGH** if lessons>0, else MEDIUM |
| E4 | Timetable → Edit period time / save period | `edu_timetable.js` save_period | updates bell times for all lessons in that slot | confirm | MEDIUM |
| E5 | Timetable → Delete makeup class | `edu_timetable.js:467` | DELETE makeup row (dated attendance day disappears) | confirm | MEDIUM |
| E6 | Timetable → Clear one lesson | `edu_timetable.js` clear_entry | DELETE one entry | dialog | LOW |
| E7 | Classes → **Delete class** | `edu_dept.php:2110` | Refuses if active enrolments; deactivates if grade history; else DELETE class + subjects, assignments, timetable, submissions, assessments | confirm "Delete this class?" ⚠ | **HIGH** |
| E8 | Classes → Deactivate / restore class | `edu_dept.php:2142` | classes.is_active toggle | confirm | MEDIUM |
| E9 | Subjects → Delete subject (permanent, no grades) | `edu_dept.php:2119` | DELETE subject + class/teacher links | confirm | **HIGH** |
| E10 | Subjects → Deactivate subject (has grades) | `edu_dept.php:2119` | is_active=0 | confirm | MEDIUM |
| E11 | Subjects → Restore subject | `edu_dept.php:2130` | is_active=1 | confirm | LOW |
| E12 | Subjects → Assign subject to classes (replace set) | `edu_dept.php:2183` | DELETE+INSERT class_subjects for the chosen classes | — ⚠ | MEDIUM |
| E13 | Assessments → **Apply assessment scheme to many classes** | `edu_dept.php:3275` | DELETE un-graded assessments + recreate, across N classes | confirm | **HIGH** |
| E14 | Assessments → Delete assessment | `edu_dept.php:3095` | Refuses if grades exist; else DELETE | confirm | MEDIUM |
| E15 | Assessment types → Delete / deactivate type | `edu_dept.php:3014` | delete or deactivate | confirm (generic) ⚠ | MEDIUM |
| E16 | Assessment types → Enable/disable type | `edu_dept.php:2998` | toggle | dialog | LOW |
| E17 | Teachers → Delete teacher | `edu_dept.php:2102` | users.is_active=0 + suspend assignments (reversible) | confirm (wrong text, F6) | MEDIUM |
| E18 | Teachers → Activate/deactivate teacher | `edu_dept.php:2101` | toggle login + assignments | confirm | MEDIUM |
| E19 | Teachers/Assignments → Remove class assignment | `education_teachers.js:177`, `education_assignments.js:238` | DELETE/deactivate assignment | confirm | MEDIUM |
| E20 | Assignments → Set primary / homeroom / assign / bulk assign | `education_assignments.js:219-316` | insert/update | — | LOW (no dialog) |
| E21 | Enrolment → **Bulk enrol N students** | `edu_dept.php:2277, 2524` | INSERT enrolments | confirm | MEDIUM (counts shown) |
| E22 | Enrolment → Remove student from class | `edu_dept.php:2423` | enrolment status change | confirm | MEDIUM |
| E23 | Enrolment → Transfer student | `edu_dept.php:2434` | close + new enrolment | confirm | MEDIUM |
| E24 | Members → **Sync all member types** | `edu_dept.php:2310` | bulk updates member types system-wide | confirm ⚠ | **HIGH** |
| E25 | Years → Delete semester | `edu_dept.php:3426` | DELETE academic_terms (no grade check, F1) | confirm ⚠ | **HIGH** |
| E26 | Mark lists → Approve / reject submission | `edu_dept.php:3698` | review status (approve locks marks) | confirm | MEDIUM |
| E27 | Calendar → Mark Day Off | `school_calendar.js:281` | closes the day (refused if attendance saved) | — ⚠ | MEDIUM |
| E28 | Calendar → Reopen Day Off | `school_calendar.js:232` | reopens day | confirm | LOW |
| E29 | Calendar → Save holiday (date range) | `school_calendar.js:333` | closes many days | — | MEDIUM (shows day count) |
| E30 | Calendar → Delete holiday | `school_calendar.js:209` | DELETE holiday | confirm | MEDIUM |

### 4.2 School Admin (`admin/dashboards/school_admin.php`)

| # | Action | Trigger | Server effect | Now | Tier |
|---|---|---|---|---|---|
| S1 | **Switch active year / reopen closed year** | `:1330` | closes current year; new records go to new year (server needs SWITCH/REOPEN) | prompt (typed) | **HIGH** (modal; keep server phrase) |
| S2 | **Year rollover / bulk promotion** | `:1349-1388`, `tools/year_rollover.php` | closes enrolments, promotes members (server needs ROLLOVER) | dialog + typed | **HIGH** (move to shared component) |
| S3 | Delete empty upcoming year | `:1341` | server refuses if any records | confirm | MEDIUM |
| S4 | Delete semester | `:1425` | as E25 | confirm ⚠ | **HIGH** |
| S5 | Close / reopen semester | `:1423` | is_reopened toggle | — ⚠ | MEDIUM |
| S6 | Set current semester | `:1424` | changes where new grades go | — ⚠ | MEDIUM |
| S7 | Activate / deactivate user | `:729` | users.is_active | confirm | MEDIUM |
| S8 | Switch to another role's dashboard (impersonate) | `:657` | session role switch | confirm | LOW |
| S9 | Save attendance alert policy | `:1481` | thresholds for every alert | — | MEDIUM |

### 4.3 Super Admin (`admin/dashboards/super-admin.php`, system health, identity codes, app release)

| # | Action | Trigger | Server effect | Now | Tier |
|---|---|---|---|---|---|
| A1 | **Delete user** | `:749` | DELETE user, notifications, assignments; reassigns tasks | password form | **HIGH** (keep password + type username) |
| A2 | Create/edit user — **role or active change** | `backend/user-save.php` | access level change | form | MEDIUM (only when role/active changes) |
| A3 | **Remove practice (TEST-FKSS) members** | `:718` | bulk DELETE test members | confirm ⚠ (F7) | **HIGH** |
| A4 | **Delete APK artifact** | `app_release.js:858` | removes downloadable app | confirm | **HIGH** |
| A5 | **App release config with Force update / min version** | `app_release.js:826` | older apps are forced to update | — ⚠ | **HIGH** when force/min changes, else LOW |
| A6 | Cancel APK upload | `app_release.js:658` | discards chunks | — | LOW |
| A7 | Branding → delete asset (logo etc.) | `:1618` | unlink file + clear slot | confirm | MEDIUM |
| A8 | Branding → save settings | `:1684` | site look | — | LOW |
| A9 | Identity codes → deactivate department | `identity_codes_section.php:324` | departments.is_active=0 | dialog | MEDIUM |
| A10 | Identity codes → save member type / position | `:399, :420` | code tables | — | LOW |
| A11 | System health → clear error log | `system_health.php:461`, `super-admin.php:1060` | unlink log | confirm | LOW |
| A12 | System health → clear rate limits / session cache | `system_health.php:532` | resets counters | confirm | LOW |
| A13 | Backup now | `super-admin.php:1197` | creates backup | — | none (safe) |
| A14 | AI → save API key / provider | `ai_assistant.php:319` | replaces key | — | LOW |
| A15 | Mezmur → schema reconcile (admin only) | `mezmur.js:139` | guarded DDL | dialog | MEDIUM |

### 4.4 HR Department (`hr-dept.php`, data sync, Family Link, new members)

| # | Action | Trigger | Server effect | Now | Tier |
|---|---|---|---|---|---|
| H1 | **Import members from Excel** | `hr_data_sync.js:112` | creates/updates many members (all-or-nothing) | confirm | **HIGH** (shows dry-run counts) |
| H2 | **Family Link → Link all unlinked members** | `family_link.js:412` | bulk attach (≈301) | confirm | **HIGH** |
| H3 | Family Link → Merge two guardians | `family_link.js:302, 359` | children move, alias kept (HR can reverse) | confirm | MEDIUM |
| H4 | Family Link → Unlink child from guardian | `family_link.js:261` | DELETE member_guardians row | confirm | MEDIUM |
| H5 | Family Link → Remove alias / set relation / reject suggestion / rescan | `:251, :258, :356, :365` | small, reversible | confirm/— | LOW |
| H6 | Archive member | `hr-dept.php:3765` (confirmArchive) | status=archived + reason | dialog | MEDIUM |
| H7 | Restore archived member | `hr-dept.php:3803` | status=active | confirm | LOW |
| H8 | Promote temporary → permanent (early) | `new-members.js:101`, `hr-dept.php:3261` | tier change + document | confirm + prompt | MEDIUM (reason field in modal) |
| H9 | Clear cache | `hr-dept.php:4620` | unlink cache files | confirm | LOW |

### 4.5 Information Department (`info-dept.php`, manage member)

| # | Action | Trigger | Server effect | Now | Tier |
|---|---|---|---|---|---|
| I1 | Change member status (active/warning/inactive) | `info-dept.php:4174-4176` | members.status (+ reason) | 2 prompts (F4) ⚠ | MEDIUM (select + reason in modal) |
| I2 | Archive member | `info-dept.php:3268` | as H6 | dialog | MEDIUM |
| I3 | Restore archived member | `info-dept.php:3306` | as H7 | confirm | LOW |
| I4 | Rebuild analytics rollup | `attendance_analytics_hub.js:283` | DELETE+rebuild rollup (recomputable) | confirm | LOW |
| I5 | Clear cache | `info-dept.php:4311` | unlink | confirm | LOW |

### 4.6 Teacher & Attendance takers (`teacher.php`, `attendance_taker.php`, `lesson_attendance.php`)

| # | Action | Trigger | Server effect | Now | Tier |
|---|---|---|---|---|---|
| T1 | Save attendance sheet | `teacher.php:1149`, `attendance_taker.php` | replaces the day's sheet | — | LOW. Plus MEDIUM "Replace saved attendance?" **only** when re-saving changes already-saved records |
| T2 | Submit mark list for review | `teacher.php:1330` | locks marks for review | — ⚠ | MEDIUM |
| T3 | Save grades | `teacher.php:963` | upsert grades | — | none (routine) |
| T4 | Cancel a lesson (reason) | `lesson_attendance.php:162` | cancels lesson; day result recalculated | prompt | MEDIUM (reason field in modal) |
| T5 | QR scan member | `attendance_taker.php:593` | marks present | — | none (routine) |

### 4.7 Mezmur Department (`frontend/js/mezmur.js`)

| # | Action | Trigger | Server effect | Now | Tier |
|---|---|---|---|---|---|
| M1 | Archive hymn / restore | `mezmur.js:2350` | set_status (soft) | sysConfirm | MEDIUM / LOW |
| M2 | Remove hymn audio | `mezmur.js:3334` | file deleted from CDN (irreversible) | sysConfirm | MEDIUM |
| M3 | Hide/show category or singer | `mezmur.js:1804` | hidden from app | — ⚠ | MEDIUM when hiding |
| M4 | Remove cover art / category / singer image | `mezmur.js:1833, 1855, 2050` | image removed | sysConfirm/dialog | LOW |
| M5 | Approve / reject attendance submission | `mezmur.js:2680, 2826` | review status | dialog | MEDIUM |
| M6 | Enable/disable attendance taker | `mezmur.js:3089` | taker access | dialog | MEDIUM |
| M7 | Save attendance sheet | `api_mezmur.php` save_sheet | replaces sheet | — | as T1 |

### 4.8 Finance & Material (`finance_department.php`, `frontend/js/finance.js`, `material_department.php`)

| # | Action | Trigger | Server effect | Now | Tier |
|---|---|---|---|---|---|
| FN1 | **Delete transaction** | `finance_department.php:245`, `finance.js:274` | hard DELETE money record | confirm ⚠ | **HIGH** (money record, no undo) |
| FN2 | Edit transaction / fee / category | `:244-254` | update | form | none |
| MT1 | Delete material item | `material_department.php:247` | DELETE item | confirm ⚠ | MEDIUM |
| MT2 | Approve/reject material request | `:254` | request status | form | MEDIUM |

### 4.9 Website content (CMS — `admin/js/content_editor.js`)

| # | Action | Trigger | Effect | Now | Tier |
|---|---|---|---|---|---|
| C1 | Delete registration submission | `:155` | DELETE | confirm | MEDIUM |
| C2 | Delete album (photos become uncategorized) | `:261` | DELETE category | confirm | MEDIUM |
| C3 | Delete photo / teacher / schedule / program / social link | `:232, 310, 354, 395, 441` | DELETE | confirm | MEDIUM |
| C4 | Change submission status | `:521` | status | — | LOW |

### 4.10 Groups / WBWS (`admin/dashboards/groups.php`, `admin/groups.php`)

| # | Action | Trigger | Effect | Now | Tier |
|---|---|---|---|---|---|
| G1 | **Delete group** (+ all leaders & members) | `groups.php:533`, `admin/groups.php:343` | cascade DELETE | confirm / confirmModal | **HIGH** |
| G2 | Remove leader / member | `groups.php:647, 679`, `admin/groups.php:395, 404` | DELETE | confirm | MEDIUM |

### 4.11 Shared by all users

| # | Action | Trigger | Effect | Now | Tier |
|---|---|---|---|---|---|
| U1 | Delete a sent message ("for everyone") | `comm.js:1174` | message deleted | — ⚠ | MEDIUM |
| U2 | Remove own profile photo | `profile_tab_section.php:584`, `account.js:894` | photo removed | confirm/showConfirm | LOW |
| U3 | Change own password | `profile_tab_section.php:679` | needs current password | form | none |
| U4 | Clear AI chat / start new chat | `ai_assistant.php:508`, `ai_chatbot_widget.php:202` | DELETE own history | confirm | LOW |
| U5 | Reports → delete saved view (shared = others lose it) | `reports_v2.js:526` | DELETE definition | confirm | MEDIUM if shared, LOW if private |
| U6 | ID card → renew / reset issue date | `id-card-directory.js:34`, `view_id_card.php:287` | issue date reset | confirm | MEDIUM |
| U7 | Attendance alerts → close case; department status → set; follow-ups → decide | `attendance_warnings.js:92`, `department_status.js:202`, `assessment_followups.js:200` | status changes | dialog/— | MEDIUM |
| U8 | Failure intelligence → update issue | `failure_intelligence.js:234` | status | — | LOW |

**Totals (98 inventory rows):** HIGH **19** (2 of them conditional: E3 only when lessons exist, A5 only when force-update/min-version changes) · MEDIUM **49** · LOW **24** · routine/no dialog **6**. ⚠ rows (protection clearly too weak today): **19**.

### 4.12 Mobile app (separate APK; not in this phase unless approved)

Already uses specific `AlertDialog`s: delete message for everyone (`messages_screen.dart:1091`), archive hymn (`mezmur_hymn_detail.dart:318`), remove cover art (`:250`), clear lyric timings (`mezmur_lyrics_sync_screen.dart:194`), remove downloads (`mezmur_download_settings.dart:285`), discard saved operation (`sync_center_screen.dart:158`), discard private data (`session_recovery_screen.dart:176`), remove profile image (`profile_screen.dart:675`). The mobile app has **no HIGH-tier actions** (FACT: deletes of classes, years, members, users and finance are web-only).

---

## 5. Design

1. **One component:** `admin/js/danger_confirm.js` + `admin/css/danger_confirm.css` (no library; works with the existing pages and the CSP; included once in the shared dashboard layout and the mezmur/frontend shells).
   ```js
   const ok = await DangerConfirm.ask({
     tier: 'high',                       // 'high' | 'medium' | 'low'
     title: 'Remove Monday from 3ኛ ክፍል?',
     intent: 'I want to remove this day',
     consequences: ['12 scheduled lessons on Monday will be deleted.',
                    'Teachers can no longer take attendance on Monday for this class.'],
     irreversible: true,
     phrase: 'REMOVE DAY',              // high only
     confirmLabel: 'Remove Monday',
     impact: () => fetchImpact(...),    // optional live counts
     reason: {required:false, label:'Reason'} // medium, where the server wants one
   });
   if (!ok) return;            // ok = {phrase, reason}
   ```
2. **Impact counts** (read-only, no schema change): small GET `action=impact_*` helpers next to the existing endpoints (e.g. lessons on a day, rows a class delete would remove, members a Link-all would touch), so step 2 shows real numbers.
3. **Server-side check for HIGH actions:** each HIGH endpoint requires `confirm_phrase` (same pattern as `SWITCH`/`ROLLOVER`). Old cached pages fail safely with a clear message ("Reload the page and confirm again"). Admin endpoints only, so the mobile app is unaffected (FACT §2).
4. **Replace all 67 `confirm()` + 6 `prompt()` + 4 in-house dialogs** with the component (mezmur `sysConfirm` becomes a thin wrapper, so it keeps working).
5. **Copy pattern:** title = verb + object + "?"; body = what happens (with counts), what does NOT happen ("The member record itself is untouched"), "This cannot be undone" when true; buttons "Cancel" / verb + object; never "Are you sure?"; English UI with Amharic object names shown as stored.
6. **No behaviour change** to the server rules themselves (e.g. delete_class still refuses with active enrolments). F1/F6/F7 fixes are listed separately for approval.

## 6. Tests

- Python static tests (no server needed): no `confirm(`/`prompt(` left in admin/frontend JS/PHP (allow-list empty); every HIGH action calls `DangerConfirm.ask({tier:'high'` with a phrase; the component file includes `role="alertdialog"`, initial focus on Cancel and the disabled-until-match logic.
- PHP tests: each HIGH endpoint refuses without `confirm_phrase` and accepts with it; `php -l` clean.
- Playwright harness (`rc_test/`): open each HIGH flow and check steps 1→2→3; button disabled until the phrase matches; Esc cancels; no request is sent on cancel; screenshots on desktop and phone width.
- Full regression suite plus the timetable, calendar, family link and import tests.

## 7. Rollout

| Phase | Scope |
|---|---|
| DC-1 | Component + CSS + tests; Timetable (E1–E6) and Calendar (E27–E30) first — the reported problem. |
| DC-2 | Education rest (E7–E26) + School Admin (S1–S9). |
| DC-3 | Super Admin, HR, Information (A, H, I). |
| DC-4 | Teacher/takers, Mezmur, Finance/Material, CMS, Groups, shared (T, M, FN, MT, C, G, U). |
| Gate | Push to `main` + merge to suraman21 `dev` → **owner tests on the development server** → owner says "test finished" → only then production deploy (SFTP, same backup/rollback kit method). |

## 8. Owner decisions (2026-10-21) — BINDING

| # | Decision |
|---|---|
| D1 | **HIGH and MEDIUM both get the full flow: 3 clicks + typed phrase** (Step 1 intent → Step 2 consequences "I have read and understand" → Step 3 type phrase + final button). **LOW** gets the simple specific modal. Routine actions keep no dialog. |
| D2 | Typed phrase = **short English phrase** (e.g. `DELETE CLASS`, `REMOVE DAY`); the Amharic object name is shown in bold. |
| D3 | **Server checks the phrase for HIGH actions** (`confirm_phrase`), like SWITCH/ROLLOVER. |
| D4 | **Fix F1, F6, F7** in the same work. |
| D5 | **Mobile app unchanged.** |

Status: plan APPROVED by the owner's decisions → implementation DC-1…DC-4. Production deploy only after the owner says the dev-server test is finished.
