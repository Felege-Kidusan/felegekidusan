# Parent App — Phase‑1 Inputs (owner decisions of 2026‑10‑18 + repository verification)
Planning only. Baseline remains `PARENT_APP_FEATURE_BASELINE.md` (locked). This file closes Appendix‑B items.

## 1. Push notifications — CONFIRMED: Firebase Cloud Messaging
- Parent app registers an FCM token **per account** (shared phone ⇒ several tokens per device, each bound to an `acc_installation` + account).
- Server side: pure‑PHP HTTP v1 send (OAuth2 service‑account JWT signed with ES/RS256 via existing openssl — no Firebase SDK, no composer C‑ext). Service‑account JSON stored outside web root (owner adds path to `.fkss_env.php`).
- Payload policy: **data‑only, no child names or grades in the push**; the app fetches details after unlocking. Tokens revoked with the device (A14).
- Dependency: Firebase project owned by the school (owner creates; agent supplies steps). Google Play **not** required for FCM on an APK‑distributed app, but Google Play services must exist on the phone (older/low‑cost phones usually have it — verify on the BASIC test device; D‑24).

## 2. "Assigned teacher" — CONFIRMED definition, verified against SSMS (FACT)
Definition: a teacher is "assigned" to a student when `teacher_assignments` links the teacher to the student's **class** (any subject, or `subject_id IS NULL` = whole class) for the **current academic year**, with `is_active = 1`; the student's class comes from `class_enrollments(member_id, class_id, academic_year_id)`.
- Homeroom/class teacher = `is_class_teacher = 1` or `assignment_role = 'homeroom'` (both exist; INFERENCE: homeroom is the newer field — implementation must treat either as homeroom and the parent app shows "class teacher" first).
- Teachers are `users.role = 'teacher'` (34 on production); `users.member_id` may link a teacher to a member record (used for display name/photo only).
- Parent‑facing permission rule (PROPOSED): parents see teacher **name + subject + role** only; no phone/e‑mail unless a separate "contact visible to parents" flag is added to `teacher_assignments` or `users` (schema addition — AWAITING DECISION later, not Phase 1 core).
- No other role (edu_dept, attendance_taker, dept takers) is ever shown as a teacher.

## 3. Lesson‑delivery confirmation — AWAITING DECISION (options + recommendation)
**What exists (FACT):** there is no "lesson" entity. Attendance is a **daily class sheet** (`attendance` per member/date/status, replaced as a whole via the staff app/API, `recorded_by` teacher). `timetable_entries` is a **weekly template** (class × period × weekday → subject/teacher/room), not a log of delivered lessons. Announcements/notifications target staff only.

| Option | Mechanism | Teacher effort | Data quality | Changes needed |
|---|---|---|---|---|
| **A — Derive from the attendance sheet (recommended for Phase 1)** | A class‑day is "held" when its attendance sheet exists for that date (`attendance` rows for class+date). Parent sees: "Class held on <date> — attendance recorded by <teacher>"; child's status shown separately (baseline item) | none (already done today) | good at day level; no per‑subject granularity | read‑only query in `/api/parent/v1`; no schema change |
| B — Teacher confirms each timetable slot | New table `lesson_logs(timetable_entry_id, date, teacher_id, status held/cancelled/substituted, note)`; staff app gets a "today's lessons" confirm screen | one tap per lesson | per‑subject, accurate | new staff‑app feature + API + table; edu_dept review page; adoption risk |
| C — Scheduled only (no confirmation) | Show timetable as "planned"; no claim that it happened | none | lowest (planned ≠ delivered) | read‑only |
| D — A now, B later | Ship A; add B when the staff app roadmap allows, keeping the parent UI wording honest ("class held" vs "lesson confirmed") | staged | improves over time | as A then B |

**Recommendation: D (A now, B later).** It needs no new teacher workflow, no schema change, and never over‑claims. Wording in the app must say "attendance recorded" rather than "lesson delivered" until B exists. **Owner decision required (Q5 in D7).**

## 4. Android distribution — CONFIRMED: existing APK release channel with controlled updates
Reuse `AppReleaseManager` / `releases/` channel (separate artefact names `fkss_parent.apk`, `fkss_members.apk`, separate signing keys from the staff app); in‑app "update required" gate by minimum version from the server; Google Play out of scope.

## 5. Still open for the parent feature plan (not blocking ACC)
Retention of parent‑visible records (baseline Appendix B) — D1 defaults apply to ACC data only; academic data retention remains the school's existing policy. Teacher contact visibility flag (§2). Push content policy (§1) to be confirmed with the consent text (A11).
