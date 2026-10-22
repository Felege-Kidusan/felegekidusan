# Plan: Score Limit · "Did not take" · View‑only Marks · List Paging

| | |
|---|---|
| **Date** | 2026‑10‑22 |
| **Base** | `7cb1ed8` (production still `a64bc93`) |
| **Status** | APPROVED 2026‑10‑22 (D3): all 3 phases, in order 1 → 2 → 3. No production deploy until the owner says "deploy". |

---

## Owner request (verbatim summary)
1. Add **load more, per page, and next / previous** navigation to lists that get cut off, especially the **member listing**, everywhere it is needed.
2. A teacher can type **more than the assessment's max**. After Submit, that student **disappears** from the submission and the phone list shows them **empty**.
   - Limit the input **live**: max 10 means nobody can type more than 10.
   - A **blank** box means **the student did not take the test**, and the report and submission must say so.
3. Add a **preview‑only (view‑only) lock** to assessments:
   - the teacher can cross‑check without accidentally changing or erasing marks;
   - the teacher can easily **show students their result** on that screen.

---

## A. Root cause: the student who "disappears" (FACT, from code)

| Step | Where | What happens |
|---|---|---|
| 1 | Mobile `teacher_grades.dart:1228` | The box only filters characters (`[\d.]`), so `15` on a `/10` test is accepted. `15..` and `1.2.3` are accepted too. |
| 2 | Mobile `_recountGraded()` `:885` | Counts **any non‑empty** box as graded. The teacher sees "50 of 50 graded" and gets no warning. |
| 3 | Mobile `_collectGrades()` `:950` | **Silently drops** the over‑max score: `if (score > maxScore) continue;`. The student is never sent. |
| 4 | Server `api/v1/routes/grades.php:1196` (`/grades/submit`) | Also skips over‑max silently, then **locks the packet as SUBMITTED** anyway. |
| 5 | `SubmissionService::marklistRows()` `:1752` | Education's review screen lists **only students who have a saved row**, so the student is gone. |
| 6 | Re‑open on phone | `/grades/students` returns that student with `score = null`, so the box shows "—". |

**The web has the same gaps.** The boxes carry `max="10"`, but nothing enforces it:
- **Web save, 3 paths** (teacher Grade entry, teacher Submit marks, Education Grade Entry): skip the student and still answer "success" (`api_subjects.php:1879`, `api_communication.php:137`).
- **Non‑numbers** become 0: PHP's `(float)"abc"` = 0, and `"1o"` → 1.
- **Lowering the max** after marks exist is **not blocked** (`api_subjects.php:1574`). Education can change 20 → 10 and leave 15/10 rows. Test DB: 0 such rows; production: UNKNOWN (read‑only SQL in §G).

**What a blank means today:** every save path skips a blank box, so "didn't take" and "not entered yet" look the same (no row).
- **Report card** (`ReportCardService::aggregateSubject`): a missing test is **not listed and not counted**. The student is averaged over the tests they took.
- **Review screen:** the student is not listed at all.

## B. Cut‑off lists: inventory (FACT)

**Server side.** The member endpoints already support paging (page / limit / cursor / total):
- web `api_list_members.php` → `MemberDirectoryService` (default 50, max 200);
- mobile `GET /members` (default 50).

So most of the work is in the **screens**.

| # | Screen | Today | Problem |
|---|---|---|---|
| W1 | Web member lists ×5 (All / Manage / New / Archive / ID cards) | Shared `admin/js/paginated-list.js`: "Showing X of Y", Load More, page numbers | **No per‑page choice**; no Prev/Next on some |
| W2 | School Admin member list | Own Prev/Next | Different look; no per‑page or Load more |
| W3 | **Education / School review inbox (web)** | `SubmissionService::list` **LIMIT 200**, no paging | 14 classes × daily attendance + mark lists fills 200 in weeks; **older packets become unreachable** |
| W4 | **Finance "Add fee" + "Transaction" member dropdowns** | `api_list_members.php?view=picker&limit=50` | **Only the first 50 of 440 members can be picked. BUG.** A searchable picker already exists (`frontend/js/member-picker.js`). |
| W5 | Education teachers list | `limit=100` / `limit=80`, no pager | Cut off silently above the limit |
| W6 | Finance member fees | `LIMIT 500`, no pager | Cut off silently |
| W7 | Attendance hub drill‑down / Mezmur submissions / Report views / Material requests / HR + Mezmur takers / Mezmur hymn list | 100 / 100 / 300 / 100 / 500 / 500 | Cut off silently |
| M1 | **Mobile members list** | Invisible infinite scroll, 20 per page | No "Showing X of Y", no Load more button, no per‑page, no Prev/Next. If scrolling doesn't trigger, there is **no way to load more**. |
| M2 | **Mobile review inbox** | `per_page=50`, page 1 only | Packets after the 50th are unreachable |
| M3 | **Mobile attendance warnings** | `limit=100`, header shows the full total | Only the first 100 are shown |
| M4 | Mobile enrollment search | `limit=20` | Only 20 results |
| M5 | Mobile teachers / notifications | Have paging / "older" cursor | Unify the look only |

Lists that are already fine: the Education roster (25 per page with pages) and the mark‑entry rosters (a whole class, ≤ ~60, by design not paged).

---

## C. Proposed solution

### Phase 1: Score limit + "Did not take" (server + web; no APK, no SQL)

**1a. Server refuses bad marks, nothing half‑saved.** Applies to all 4 write paths: v1 `save` / `submit`, web `save_grades`, web `submit_marklist`, plus the make‑up score.
- Any score that is < 0, > max or not a number → **422** listing the rows ("Row 12 · Abebe: 15 is over the max 10"). **Nothing is saved and the packet is NOT locked.**
- A 422 puts a queued mobile item in "needs attention", not an endless retry (FACT, `outbox_policy.dart:195`).

**1b. The max cannot be lowered below the highest saved score.** Message: "3 students already have more than 10. Fix their marks first."

**1c. Live limit on every web entry screen.** Covers teacher Grade entry, teacher Submit marks, Education Grade Entry and the follow‑up make‑up score.
- A keystroke that would go over the max is **refused** and a hint shows ("Max is 10").
- At most 2 decimals; one dot.
- The "graded" counter counts **valid** scores only.
- Submit is disabled while any box is invalid (covers old or pasted values).

**1d. "Did not take" everywhere.** No schema change: once a mark list is **submitted**, a blank = **Did not take**.
- **Before submit:** the dialog lists the blank students by name ("These 3 students will be marked **Did not take**").
- **Education review:** shows **all enrolled students**. Blanks show **"Did not take"** (grey badge), plus a count in the header.
- **Report card + class report:** the test is listed for that student as **"Did not take"**. How it counts toward the total is **owner decision D1**.
- **While still a draft:** a blank shows "Not entered yet". This keeps "not entered" apart from "didn't take".

### Phase 2: Mobile marks (APK 1.7.3)
- **2a. Live limit:** a custom input formatter refuses any keystroke that would exceed the max (and more than 2 decimals or a second dot). A small hint shows "Max 10", and the counter counts valid scores only.
- **2b. Submit summary:** before the 5‑second undo window, a short sheet shows: "47 scored · 3 **Did not take**: names…" → **Submit** / **Go back**.
- **2c. Server rows:** the review / submitted view shows "Did not take" from the server rows.
- **2d. View‑only lock** (eye / lock button in the top bar):
  - all boxes become read‑only and the keyboard can't open;
  - a banner reads "View only. Tap 🔒 to edit";
  - the lock is remembered per assessment on the phone until the teacher unlocks it (one tap, no typing; it is a LOW‑risk action);
  - a search box finds a student by name or code;
  - blanks show "Did not take".
- **2e. Show a student their result:** owner decision **D2**.
- **2f. Web teacher screens:** get the same View‑only toggle.

### Phase 3: Paging everywhere (web first, then mobile in the same APK)

**One pager design, used everywhere:**
```
Showing 51–100 of 440    [Per page: 25 | 50 | 100 | 200]
[‹ Previous]  Page 2 of 9  [Next ›]          [Load more (340 left)]
```
- Prev/Next replaces the list; Load more appends to it.
- The per‑page choice is remembered per screen.

| Step | Work |
|---|---|
| 3a | **Web shared component** (`paginated-list.js`): add the per‑page selector, Prev/Next and the "51–100" range. This fixes all 5 member screens at once. W2 moves to the same component. |
| 3b | **Review inbox W3:** server `page` / `per_page` / `total` on `SubmissionService::list` (default 50), plus the pager. |
| 3c | **Finance W4:** both dropdowns become the existing **searchable member picker** (type a name or code; all 440 reachable). |
| 3d | **W5–W7:** add `page` / `per_page` / `total` to each endpoint (keeping the old default when no page is sent, so nothing else breaks) and attach the pager. |
| 3e | **Mobile:** one shared `PagedListFooter` widget (same layout), applied to M1–M5. On M1, a visible **Load more** button backs up the auto‑scroll. Server endpoints already page (`/members`, `/attendance/warnings` offset, `/grades/submissions` page). |

---

## D. Files likely to change

| Phase | Files |
|---|---|
| **1** | `api/v1/routes/grades.php`; `admin/api_subjects.php`; `admin/api_communication.php`; `admin/backend/services/SubmissionService.php` (`marklistRows` + roster); `admin/backend/services/ReportCardService.php`; `admin/backend/services/AssessmentFollowupService.php` (validation only); `admin/dashboards/teacher.php`; `admin/dashboards/edu_dept.php`; `admin/js/assessment_followups.js`; `admin/js/report_card.js` |
| **2** | `Mobile/.../screens/teacher/teacher_grades.dart`, review detail screen, a new `widgets/score_input_formatter.dart` |
| **3** | `admin/js/paginated-list.js` (+ CSS); `admin/dashboards/school_admin.php`; `finance_department.php`; `frontend/js/finance.js`; `edu_dept.php`; `frontend/js/education_teachers.js`; the endpoints in W3–W7; Mobile `member_list_screen.dart`, `review_inbox_screen.dart`, `attendance_warnings_screen.dart`, `edu_enrollment_screen.dart`, a new `widgets/paged_list_footer.dart` |

## E. Database / API

- **Database:** no SQL or migrations in any phase.
- **API:** additive only. New optional `page` / `per_page`; new `not_taken` rows and counts in packet detail; 422 on invalid scores.
- **Mobile `/api/v1`:** paths unchanged. Older APKs keep working (they already drop over‑max scores on the phone; they just won't show the new hints).

## F. Risks

| Risk | Mitigation |
|---|---|
| Report‑card totals change if D1 = "counts as 0" | The owner decides; this is shown in the report |
| A mobile item queued before the update with a now‑invalid score → "needs attention" | Correct and safe. The teacher fixes it and resubmits. |
| Paging hides rows a user relied on seeing all at once | Per‑page up to 200, plus Load more |
| Older APK users don't get the live limit until they update | The server refusal (1a) protects the data from the first deploy |

## G. Read‑only SQL for the owner (phpMyAdmin, optional)
Finds existing marks above their max in production:
```sql
SELECT ar.id, ar.member_id, ar.score, a.max_score, a.assessment_name
FROM academic_records ar JOIN assessments a ON a.id = ar.assessment_id
WHERE ar.score > a.max_score;
```

## H. Tests

| Phase | Tests |
|---|---|
| **1** | API: over‑max / negative / text refused with 422 and nothing saved, on all 4 paths; max‑lowering guard. Browser: the keystroke is refused on 4 screens; review shows "Did not take"; report card shows it. |
| **2** | `flutter analyze` + widget tests for the formatter, view‑only lock and submit sheet. Lock file unchanged. |
| **3** | Browser: per‑page, Prev/Next, Load more and range on every listed screen; Finance can pick member #440. API: page / total correct, and the old default unchanged without `page`. |
| **All** | Full security suite, the journeys (68) and the danger suites. |

## I. Owner decisions (2026‑10‑22, via ask_user)
- **D1 = excluded:** "Did not take" is **shown** on the report card and class report but **not counted** in the total (current maths kept).
- **D2 = both + other results:** View‑only shows the big read‑only list **and** tapping a student opens a private full‑screen result card. The card **also shows that student's other assessment results** (same class and subject, this semester).
- **D3 = all, in order:** Phases 1 → 2 → 3; implement, test and push each; no production deploy until "deploy".

---

## Phase 1 status — 2026-10-22: DONE (server + web; no SQL, no APK)

- Server refuses invalid marks on every write path (v1 save/submit, web save_grades / submit_marklist, make-up): HTTP 422 `INVALID_SCORES`, nothing saved, message names each student.
- Lowering a max below saved marks → 422 `MAX_BELOW_SAVED_SCORES`. Make-up "Out of" above the test max → refused (not rewritten).
- Live web limit on 4 screens (teacher Grade entry, teacher Submit marks, Education Grade Entry, make-up form) via `admin/js/score_limit.js`.
- "Did not take": review modal (all enrolled students, amber badge, counts), submit dialog names blank students, report card note (not counted — D1), profile tab.
- Side fixes: Submit-marks boxes stayed editable after a locked redraw; `update_assessment` with an empty due date returned 500.
- Tests: `tests/e2e/score_limit_api.py` (41), `tests/e2e/score_limit_browser.py` (34), `tests/security/test_score_limit.py`.

**Added to Phase 2 (found during Phase 1, FACT):** the mobile review detail
(`review_inbox_screen.dart`) is attendance-only — for a mark list it shows
P/A/L/E = 0 and no scores. Phase 2 shows scores and "Did not take" there.
