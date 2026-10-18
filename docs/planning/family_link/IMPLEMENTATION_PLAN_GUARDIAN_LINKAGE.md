# Implementation Plan — Guardian/Sibling Linkage ("Family Link")
Status: APPROVED & IMPLEMENTED — P1–P3 shipped as `95e40be` (both remotes) on 2026-10-18. P4 (P4.0–P4.3) approved and shipped on 2026-10-18 — see MEMBERS_APP_PROGRESS.md §6; pre-deployment audit fixes (G1–G10) in §7. Scope correction: Flutter register-screen picker DROPPED — mobile member registration removed entirely (user mandate: registration is web-only). Library change: iliaal/phonetic REJECTED (C extension, not installable on shared hosting) → PHP core metaphone + strsim Jaro-Winkler + aliases.
Companion docs: `MEMBERS_APP_PROGRESS.md` (§2 analysis, §3 model, §3.4 HR linking pattern), `MEMBER_APP_SIGNIN_PROPOSAL.md`.

## 0. Goal
Give HR a reliable, typo-tolerant way to link one parent/guardian to N siblings (and a child to
1–2 guardians), with a review queue for ambiguous cases and a one-time audited backfill — the
prerequisite for the future parent app. No member/parent auth in this phase.

## 1. Deep-research verdict: what we adopt vs what we write
| Layer | Adopt (mature open source) | Why / evidence |
|---|---|---|
| String similarity (Jaro-Winkler, Damerau-Levenshtein, cosine) | **edgaras/strsim** (composer, MIT, maintained 2025) | Packagist: full algorithm set incl. JaroWinkler::similarity; multibyte-safe |
| Phonetic encoding for spelling/transliteration variants (Double Metaphone, Beider-Morse, NYSIIS, Daitch-Mokotoff) | **iliaal/phonetic** (composer, native PHP, 2026) | "the standard tools for fuzzy name matching, record linkage, and genealogy search across spelling and transliteration variants" — exactly the Amharic-variant problem |
| Fallback if a dep misbehaves | PHP core `soundex()`, `metaphone()`, `levenshtein()`, `similar_text()` | core functions, zero risk |
| Blocking + weighted scoring + 3-band decision glue | **Written by us (~300 LOC)** in Fellegi-Sunter style | FACT: no mature PHP entity-resolution framework exists on Packagist; the glue is standard, well-documented practice (Splink/MDM literature) and must speak our schema |
| One-time backfill clustering | **Our PHP scorer in an admin batch tool (default)**; optional offline **Splink** (UK MoJ; ONS 58M-record census; NHS; 3M+ downloads) only if user prefers an export-based Python pass | Splink is production-proven but Python/PySpark — cannot run on cPanel; default keeps data on-server |
| Family/guardian schema shape | Reference **Gibbon** (PHP school platform since 2010, GPL, schools in 100+ countries): `gibbonFamily` + `gibbonFamilyAdult(contactPriority)` + `gibbonFamilyChild` | Borrow schema SHAPE only (no code copy — GPL); proves the household-adult-child link model at school scale |
| In-repo reuse | `MemberDuplicateService::normalizePhoneSuffix()` (digits, last-9), activity_logs audit pattern, CSRF+role-gate pattern, idempotent `sql/0xx` migration convention, admin card/table UI patterns, composer+committed-vendor practice (phpspreadsheet precedent) | zero new infrastructure |

## 2. Schema (migration `sql/069_guardian_linkage.sql`, idempotent; USER runs it in phpMyAdmin)
- `guardians`: id, display_name VARCHAR(150), phone_primary VARCHAR(30), phone_alt VARCHAR(30),
  phone_primary_n9 CHAR(9) GENERATED/derived column + index (last-9 blocking key),
  phone_alt_n9, city/sub_city/woreda/mender/block/house (moved semantics), photo_path NULL,
  status ENUM('active','merged','archived'), merged_into INT NULL, created_by/at, updated_at.
- `member_guardians`: member_id, guardian_id, relation ENUM('father','mother','other'),
  contact_priority TINYINT (Gibbon-style ordering), is_primary TINYINT, verified_by/at NULL,
  created_by/at; PK(member_id, guardian_id), KEY(guardian_id), UNIQUE per member is_primary=1
  enforced in service.
- `guardian_aliases`: guardian_id, alias_name, source ENUM('hr','backfill') — Amharic orthographic
  variants and known aka's feed the blocking passes.
- `guardian_link_suggestions`: id, guardian_a INT NULL, guardian_b INT NULL, member_id INT NULL,
  score DECIMAL(5,4), evidence JSON, status ENUM('pending','merged','rejected','expired'),
  resolved_by/at, created_at — the steward review queue.
- `members.guardian_*` columns remain untouched this phase (legacy display copies); reads for
  family UI come from the new join. Deprecation later.

## 3. Server components
- `admin/backend/services/GuardianMatchService.php`:
  normalizeName (mb_lower, strip punct/honorifics, collapse spaces), normalizePhone (reuse
  normalizePhoneSuffix), blocking passes: (a) phone_n9 exact, (b) Double-Metaphone of name tokens,
  (c) trigram-set overlap of name, (d) address-token Jaccard; weighted score
  (phone_n9 exact = +0.45; name JaroWinkler scaled ×0.50; metaphone-equal = +0.05;
  address Jaccard ≥0.5 = +0.15) → bands: **auto ≥0.80**, **review 0.55–0.79**, **new <0.55** (constants at
  top, tunable after the prod profiling pack Q1).
- `admin/backend/services/GuardianService.php`: create/link/unlink/setPriority/merge (merge moves
  links, marks merged_into, audits), familyOfMember(member_id), siblingsOf(member_id),
  searchForPicker(q) (name prefix + phone suffix typeahead, returns guardian + linked children).
- `admin/api_guardians.php` (CSRF + roles super_admin/school_admin/hr_dept write; info_dept read):
  actions search, get_family, create, link, unlink, set_priority, update, merge_suggestion,
  reject_suggestion, list_suggestions. All mutating actions audit to activity_logs.
- Backfill tool `admin/tools/guardian_backfill.php` (hr/admin UI page): DRY-RUN first — clusters
  members.guardian_* via the same scorer, lists proposed guardians + links + scores; APPLY is
  per-cluster confirm, cap 200 clusters/run, every created row records source='backfill';
  reversible via source tag + audit.
- Read-model switch: member file / ID-card / reports keep legacy columns this phase (no behavior
  change); new Family card reads the join.

## 4. Admin UI
1. HR register member + edit member: guardian block gains **live picker** (typeahead →
   api_guardians search): candidate cards show name, phone, existing children; pick + relation +
   priority; "create new guardian" behind an explicit no-match confirm.
2. Member file: **Family card** — guardians (priority order) + siblings list, quick actions
   (add sibling link, unlink with confirm).
3. New page **Guardian Review Queue** (HR + admins): pending suggestions side-by-side with score +
   evidence chips (phone match / name 0.96 / address), one-click Merge or Keep separate; audit trail.
4. Nav entries under HR section; role-gated like other HR pages.

## 5. Phases & deliverables
- **P1** schema migration + GuardianMatchService + GuardianService + api_guardians.php + contract pins.
- **P2** admin UI: picker in register/edit, Family card, Review Queue page.
- **P3** backfill tool (dry-run/apply) + e2e scenario tests + full suite + commit/push both remotes + handoff note (user runs sql/057 in phpMyAdmin, git pull, hard refresh).
- **P4 (later, separate approval)**: threshold tuning from prod profiling pack; nightly re-match sweep cron; Flutter register-screen picker; parent-app auth on top (actor_type='guardian').

## 6. Test plan
- Contract pins (tests/security/test_guardian_linkage.py): migration DDL names/indexes; service
  band constants; role gate list; audit writes; picker endpoint names; queue actions.
- e2e (curl, local server): seed family "Aster Bekele / 0911234567" + sibling rows with typo
  "Astr Bekele / 0911-234-567" → backfill dry-run proposes one cluster with score in auto band →
  apply → both children share guardian; picker search "ast" returns guardian with 2 children;
  link a 3rd child via API; update guardian phone once → family card shows new phone for all;
  unlink + merge paths audited; info_dept write attempt → 403.
- Full pytest suite green before push.

## 7. Risks & mitigations
- iliaal/phonetic license/maintenance verified at install (MIT expected); fallback = core metaphone/soundex.
- Sandbox resets wipe php/composer → known reinstall recipe before P1 testing.
- Amharic orthographic variants beyond phonetics → guardian_aliases + review band (never auto-merge on name alone: auto band REQUIRES phone_n9 or (name≥0.96 AND address≥0.6)).
- Backfill mistakes → dry-run default, per-cluster confirm, source tag, merge is reversible via merged_into + audit.
- Perf: blocking caps candidate pairs (phone/meta/trigram keys); school scale = thousands, trivial.

## 8. Explicitly out of scope now
Member/parent authentication, Flutter changes, nightly cron, finance family views, deprecating
members.guardian_* columns (read-model switch later).
