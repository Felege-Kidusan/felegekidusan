# P71 — Section / Age Group: Single Source of Truth

**Task type:** bug patch (data-model consistency)
**Protocol:** Universal AI Software Change Protocol + Complete Codebase Understanding Protocol
**Phase:** impact analysis (pre-implementation) — every conclusion below is labeled FACT (verified from source), INFERENCE, or UNKNOWN.

---

## 1. REQUEST (extracted contract)

| Item | Content |
|---|---|
| Objective | One system-wide source of truth for "section / age group" (the user states they are the same concept: sections are created based on age group) |
| Current problem | Section and age group are modeled as two unrelated things; some UIs show one, some the other, some both; the education class-creation modal shows BOTH as separate selects and its section list is wrong ("ሰበከላ / Parish" instead of "ወጣቶች / Youth") |
| Desired behavior | Every department, dashboard, mobile app and future feature derives section + age range from ONE definition; changing an age range happens in one place |
| Constraints | Do not break any working functionality, current data usages, workflows; avoid unrelated files/logic |
| Scope | Entire system including database: remove confusions and hardcoded section/age lists |
| Acceptance | Fully traced report of how section/age group works everywhere; system updated to the single source of truth without breaking anything |
| Non-goals | (derived) no restructuring of unrelated domains; no schema redesign where values already fit |

## 2. HOW SECTION / AGE GROUP ACTUALLY WORKS TODAY (traced)

### 2.1 The two dimensions [FACT]

**`age_group`** — canonical string codes `'7_13' | '14_17' | '18_plus'`:

- `members.age_group` — `ENUM('7_13','14_17','18_plus')` (database_schema.sql:69; the historical 4th value `'under6'` was retired by sql/017 + sql/019).
- `classes.age_group` — ENUM in migration 002, VARCHAR(20) in the 012 runtime baseline (deployments may hold either).
- **A PHP source of truth already exists**: `App\Services\MemberCategory` (admin/backend/services/MemberCategory.php) — self-described "the ONLY source of truth": letters A/B/C, stored codes, Amharic labels **ህጻናት / ማዕከላዊያን / ወጣቶች**, English labels Children / Intermediate / Youth. Identity codes are generated from it (`EnrollmentService::generateMemberCode` → `MemberCategory::letterFor`; unknown group ⇒ PENDING, never guessed).
- Legacy stray value `'18+'` is still accommodated defensively in `api_teachers.php:770` and `api_ai.php:233`.

**`section`** — free text, **no canonical list anywhere**:

- `classes.section VARCHAR(50)` — written only by the education class modal via `api_education.php save_class` (no validation); seeded by migration 002 with **ህጻናት / ማዕከላዊያን / ወጣቶች** — i.e. the seed already treats section as the age-group's name.
- `members.current_section VARCHAR(60)` (indexed) — written by the Excel import (header literally "Age Section"), preserved (not edited) by `info_manage_member.php`; read everywhere as the attendance-grouping unit.

### 2.2 The system's own seed defines the intended mapping [FACT]

Migration `002_add_academic_attendance_workflow.php` seeds classes:

| Section (seed) | age_group | Grades |
|---|---|---|
| ህጻናት | 7_13 | 1–4 |
| ማዕከላዊያን | 14_17 | 5–7 |
| ወጣቶች | 18_plus | 8–10, …, degree |

**The user's statement ("sections are created based on age group — same idea") matches the system's own seed data.** The contradiction is in later UI code, not in the data model's intent.

### 2.3 Where the confusion lives [FACT — complete inventory]

**A. Education dashboard (`admin/dashboards/edu_dept.php`) — the reported defect:**
- Class modal has TWO selects: `Section` (hardcoded **ልጆች / ማእከላዊ / ሰበከላ**) and `Age Group` (7-13/14-17/18+).
- **`ሰበከላ` appears nowhere else in the entire repository** — it is an isolated, incorrect hardcode that contradicts migration 002's seed (ወጣቶች). Answering the user's "where did this come from": someone hand-typed that list for the modal; it was never derived from the system's data.
- `save_class` accepts both fields with no validation and no linkage.
- Age filters (roster, unassigned, bulk) use canonical codes but are hardcoded.
- Class list table shows Section AND Age Group as separate columns (redundant once unified).

**B. Hardcoded A/B/C lists duplicated outside the source of truth [FACT]:**

| File | Spot(s) | Content |
|---|---|---|
| `admin/dashboards/school_admin.php:386` | member filter `fAgeGroup` | ህጻናት (A) / ማዕከላዊያን (B) / ወጣቶች (C) |
| `admin/dashboards/hr-dept.php:1137-1139` | filter `filterAgeGroup` — labeled **"Section (All)"** (!) | same |
| `admin/dashboards/hr-dept.php:1232-1234` | `manageFilterAgeGroup` | ህጻናት (7 - 13) … |
| `admin/dashboards/hr-dept.php:1815-1819` | settings `defAgeGroup` | same |
| `admin/dashboards/info-dept.php:932-934` (+settings select) | filter + `defAgeGroup` | same |
| `admin/reports.php:139,270,316-318,323` | filter, charts, summary | same labels ×4 |
| `admin/js/all-members.js:24` | `sectionLabel()` map | same — note the function is *named* sectionLabel but maps **age_group** |
| `admin/backend/services/MemberReportRenderer.php:12-16` | private `AGE_LABELS` const | same (a service duplicating MemberCategory) |
| `admin/dashboards/edu_dept.php` | modal + 3 filters | wrong/duplicated lists |

- The same dashboards ALSO render section labels correctly server-side via `MemberCategory::labelAm` (hr-dept 209-210, info-dept 155-156) — the system is half-migrated.

**C. The two fields shown side-by-side [FACT]:** edu class modal; school_admin member table ("Age" col = age_group, "Section" col = current_section); Flutter member detail ("Age Group" + "Section" fields); `info_manage_member.php:674` badge `current_section ?: age_group` (fallback proves the display layer treats them as interchangeable); `archive-members.js:39` same fallback.

**D. Section-driven features that must keep working [FACT]:**
- HR + Mezmur attendance (web + Flutter): sections = `SELECT DISTINCT current_section FROM members` (`HrAttendanceService`/`MezmurAttendanceService::sectionListWithCounts`); rosters, packets (`hr_submissions`/`mezmur_submissions` UNIQUE date+section), QR roster (`api_qr_roster` WHERE current_section=?).
- school_admin `fSection`/`attSection`/`qmSection` dropdowns populated from the same DISTINCT list (`api_list_members` → `MemberDirectoryService::sections()`).
- Flutter app contains **zero hardcoded section/age strings** (verified) — it displays raw values, so normalizing the DATA fixes the app with no app release.
- `api/v1/routes/members.php` filters by `current_section`/`age_group` (free params — contract unchanged).

**E. Related but separate dimensions (documented, not conflated):**
- `mezmur_categories` (hymn taxonomy, 025) is seeded with **corrupted section names** — 'ህናት', 'ማዕከዊያን', 'ጣቶች' (truncated spellings of the section names) [FACT]. It is a managed hymn category list, but its seed names claim to be "the three official sections".
- mezmur/HR "submission packet" sections, `members.current_section` — one concept, covered above.
- Zero usage in: mezmur web frontend, teacher, attendance_taker, material, finance, super-admin dashboards [FACT — verified by absence].

### 2.4 Answers to the user's specific questions [FACT]

1. **Difference between section and age group:** none by design — migration 002's seed and the identity-code system define section as the name of the age group. The split exists only because UI code hardcoded two independent vocabularies.
2. **Why does the class modal show both?** The modal was written with two unlinked selects; the section list was hand-typed (wrong) instead of derived.
3. **Where did ሰበከላ/Parish come from?** Only from `edu_dept.php`'s modal markup. Nowhere else in the system — it contradicts the seeded ወጣቶች.
4. **Why is section "required" there?** It isn't — both selects default to "—"; but the modal lets you save a class with a section that contradicts its age group (or neither). After this patch there is exactly one Section/Age select and the pair is always written consistently.

## 3. ROOT CAUSE

No canonical definition of "section" (name ⇄ age range) exists; `age_group` has one (`MemberCategory`) but most UIs bypass it with copy-pasted lists, and the education modal additionally invented a wrong section vocabulary. `classes.section` / `members.current_section` are free-text columns whose values therefore vary by whichever UI or spreadsheet wrote them.

## 4. DESIGN — the single source of truth

**Extend `App\Services\MemberCategory`** (already the declared source of truth for the age-group half) into the complete definition of the section/age-group concept — one code-level definition, consistent with the repo's existing architecture (017 explicitly calls A/B/C "configuration-free constants of the ministry structure"):

```
code 7_13   →  A · ህጻናት · Children   · age range "7–13"
code 14_17  →  B · ማዕከላዊያን · Intermediate · "14–17"
code 18_plus→  C · ወጣቶች · Youth        · "18+"
```

New on the class (all additive, no signature changes): `sections()` (full list for UIs), `sectionAm($ageGroup)`, `ageGroupForSectionAm($name)`, `ageRangeLabel($ageGroup)`, `normalizeSectionAm($value)` (maps legacy variants ልጆች/ማእከላዊ/ሰበከላ/… → canonical; unknown ⇒ null, never guessed — same doctrine as codes).

**Stored values do not change**: `age_group` keeps `7_13/14_17/18_plus`, `section`/`current_section` become the canonical Amharic names. No column type changes — every existing reader keeps working.

### Change plan (DIRECT files)

| # | File | Change | Why |
|---|---|---|---|
| 1 | `admin/backend/services/MemberCategory.php` | add section metadata + accessors (additive) | THE source of truth |
| 2 | `sql/041_section_age_source_of_truth.sql` (new) | idempotent data normalization: classes.section variants → canonical; align classes.section ⇄ classes.age_group both directions; members.current_section variants → canonical; members.age_group '18+' stragglers → 18_plus; fix corrupted mezmur_categories seed names (ids stable, hymn assignments preserved) | the "database also" part of the scope — makes stored data match the definition |
| 3 | `admin/api_education.php` | `save_class`: validate age_group against `MemberCategory::groups()`, derive `section` from it (accept legacy section-only posts by deriving age_group); the two inline `['7_13','14_17','18_plus']` whitelists replaced by `MemberCategory::groups()` | single writer guarantees the pair is always consistent |
| 4 | `admin/dashboards/edu_dept.php` | class modal: ONE select "Section / Age Group" (canonical options, value = age_group code); remove the separate Age Group select; filters (roster/unassigned/bulk) render from MemberCategory; class table merges Section+Age Group columns into one; JS: saveClass posts age_group only, editClass fills from age_group (mapped from section for legacy rows), unassigned/roster rows show canonical labels via injected map | the named defect |
| 5 | `admin/dashboards/school_admin.php` | `fAgeGroup` options rendered from MemberCategory (PHP) | kill hardcode |
| 6 | `admin/dashboards/hr-dept.php` + `info-dept.php` | `filterAgeGroup`, `manageFilterAgeGroup`, `defAgeGroup` (+info's equivalents) rendered from MemberCategory; inject `window.WBWS_SECTIONS` JSON for their JS | kill hardcodes + feed all-members.js from the source |
| 7 | `admin/reports.php` | inject labels via PHP (filter options, chart labels, summary rows) | kill hardcode ×4 |
| 8 | `admin/js/all-members.js` | `sectionLabel()` reads `window.WBWS_SECTIONS` (set by its including pages hr-dept/info-dept) | kill hardcode |
| 9 | `admin/backend/services/MemberReportRenderer.php` | private `AGE_LABELS` → delegate to `MemberCategory` | service duplication |
| 10 | `admin/api_import_members.php` | normalize the imported "Age Section" value through `MemberCategory::normalizeSectionAm()` (known variants only; unknown values pass through unchanged) | stop new bad data at the gate |
| 11 | `tests/security/test_section_source_of_truth.py` (new) + update `test_edu_uiux.py` class-table pins | pin the definition, the modal, the derivation, the migration, and the elimination of ሰበከላ/ልጆች from UI markup | regression gates |

### Impact map (protocol §11)

- **DIRECT:** files above.
- **INDIRECT (benefit, no code change):** Flutter app displays (raw values become canonical after migration); HR/mezmur section pickers (DISTINCT lists collapse to the 3 canonical sections); school_admin dynamic section dropdowns; QR roster.
- **POTENTIAL (verified safe):** `test_edu_uiux.py` pins the 9-column class table → updated deliberately with the new 8-column contract; `test_identity_*` pin `MemberCategory` behavior → additive changes only, existing methods untouched; reports/exports show the same labels they hardcode today.
- **UNRELATED (untouched):** mezmur/HR attendance mechanics, identity code allocation, finance/material/teacher/super-admin, api/v1 contracts, Flutter code, Excel column names.

### Explicit non-behavior-changes (constraints honored)

- No auto-assignment of member age_group/section from age (identity codes depend on age_group; auto-changing them would renumber codes — forbidden. `MemberCategory`'s doctrine "categories are never guessed" is preserved).
- No new tables/columns (values fit existing columns; the definition is code-level like today's MemberCategory, per the repo's own architecture).
- API params/responses keep their shape; only the values become consistent.
- Unknown/legacy section strings that don't match any known variant are left untouched by the migration (documented for manual review) — nothing is destroyed.

## 5. VERIFICATION PLAN

1. New static contract suite `test_section_source_of_truth.py` (definition completeness, variant map, save_class derivation, modal single-select, no wrong vocabularies in markup, migration idempotency + coverage, all former hardcode sites now sourced).
2. Updated `test_edu_uiux.py` (new class-table contract).
3. Full matrix must stay at the pre-existing 42-failure environmental baseline; mezmur 30/30; edu suite green.
4. PHP-level behavior of MemberCategory verified by the existing identity tests (executable where PHP exists; static contracts here).
5. Migration is pure-value UPDATEs — idempotent by construction, re-runnable.

---

## 16. Result Log (implementation complete)

Executed change plan §9 items 1–11, verified against the full matrix.

| # | Item | Result |
|---|------|--------|
| 1 | `sql/041_section_age_source_of_truth.sql` | 19 value-filtered UPDATEs: classes.section variants→canonical + pair alignment both directions; members.current_section variants; members.age_group '18+' stragglers; mezmur_categories corrupted names (guarded prepared statements, ids stable). No DELETE/DROP/ALTER — re-runnable by construction. |
| 2 | `api_education.php` save_class | Validates age_group via `MemberCategory::groups()`; **derives section server-side** (`sectionAm`); legacy section-only posts resolve via `ageGroupForSectionAm`; both inline whitelists (roster + unassigned) now call `groups()`. |
| 3 | `edu_dept.php` | Modal: ONE select `Section / Age Group` (id `classAge`, value = code, label `ህጻናት · 7–13 (Children)`); `classSection` removed. Filters (roster/unassigned/bulk) PHP-rendered from `sections()`. Class table merged Section+Age→`Section / Age` (9→8 cols, colspan + P70 card labels updated). JS: `EDU_SECTIONS` injected; `saveClass` posts age_group only; `editClass` falls back section→code; unassigned rows use `eduSecLabel`. |
| 4 | `school_admin.php` | `fAgeGroup` options + `fmtAge` JS map both derive from MemberCategory. |
| 5 | `hr-dept.php` / `info-dept.php` | All 6 hardcoded selects (filter/manage/def ×2 files) PHP-rendered; both inject `window.WBWS_SECTIONS` for all-members.js. info-dept's require path untouched (test pin). |
| 6 | `reports.php` | Filter options + chart labels + summary chips + export `agL` map → `RP_SECTIONS` injected from MemberCategory. |
| 7 | `all-members.js` | `sectionLabel()` reads `window.WBWS_SECTIONS`. |
| 8 | `MemberReportRenderer.php` | `AGE_LABELS` const removed → `sectionAm` + `letterFor` (identical CSV output). |
| 9 | `api_import_members.php` | Import gate normalizes `current_section` via `normalizeSectionAm` (known variants only; unknown pass through). |
| 10 | Tests | NEW `tests/security/test_section_source_of_truth.py` (14 tests); `test_edu_uiux.py` class-table pins updated 9→8. |
| 11 | Matrix | **698 tests / 42 failing = exact pre-existing baseline (33F+9E, all environmental). Zero new failures.** edu 25/25, mezmur 30/30, section 14/14. |

Design OS §16/§17 compliance: merged select minimizes cognitive effort; reuses existing `.lbl/.inp` components (Level 2); error message is actionable.
Scope discipline: 11 files + 3 new files only; zero Flutter changes required (app displays raw values — migration fixes its display); identity-code doctrine untouched.
