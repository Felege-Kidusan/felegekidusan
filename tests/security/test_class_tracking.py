"""
Academic Tracking — Phase 5 Class Tracking workflow.

Phase 2 built the student workflow, Phase 3 the teacher one and Phase 4
the subject one. Phase 5 builds the last of the four root entities: pick
a class, and see who is enrolled in it, what it is taught, who teaches
it, and where its assessments stand.

These tests drive the real endpoints against the live fixture database
through tests/e2e/education_config_api.php — the existing harness for
calling an admin endpoint as a session role. No second harness is added.
The frontend half lives in tests/e2e/class_tracking.js and is executed
from here so one pytest run checks both sides.

What is being defended:

  * The class domain model is the one the schema actually has. `classes`
    carries no academic_year_id and neither does `class_subjects`, so a
    class is year-agnostic and the academic year scopes only the data
    hanging off it. A class never disappears because a year was quiet.
  * The four relationships are READ from their authoritative tables and
    never inferred:
        students  <- class_enrollments
        subjects  <- class_subjects
        teachers  <- teacher_assignments
        assessments <- assessments.class_id
    A mark, a packet or an assessment row never creates a relationship.
    The fixture contains a packet filed by a teacher who holds no
    assignment for the class it was filed against.
  * Both meaningful NULLs survive: teacher_assignments.subject_id IS NULL
    is a homeroom assignment and must never be shown as teaching a
    subject; academic_year_id IS NULL is a standing assignment and must
    be kept, flagged, and never rewritten into the selected year.
  * users.role='teacher' is required wherever a teacher is exposed.
  * Four facts stay four facts: the assessment exists, a packet was
    submitted, the packet has a status, a mark was recorded.
  * Every class-scoped call authorizes the class server-side, before any
    read, using the existing canViewClass primitive.
  * No class score, ranking or effectiveness figure exists anywhere.
  * An absent record is never a zero, and the empty states stay distinct.

Every negative assertion here is paired with a positive one proving the
thing being excluded actually exists in the fixture — a test that says
"X must not appear" is worthless if X was never there.

Requires: php CLI, .fkss_env.php and the dedicated e2e database
          $SSMS_SYNC_DB (default ssms_e2e) -- never production.
"""

import json
import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / "tests" / "e2e" / "academic_intelligence.php"
ROLE_RUNNER = ROOT / "tests" / "e2e" / "education_config_api.php"
CLASS_HARNESS = ROOT / "tests" / "e2e" / "class_tracking.js"
CONTROLLER = ROOT / "admin" / "js" / "academic_tracking.js"
SERVICE = ROOT / "admin" / "backend" / "services" / "AcademicTrackingService.php"
SUBMISSION_SERVICE = ROOT / "admin" / "backend" / "services" / "SubmissionService.php"
API_EDU = ROOT / "admin" / "api_education.php"
ENV_FILE = ROOT / ".fkss_env.php"

SYNC_DB = os.environ.get("SSMS_SYNC_DB", "ssms_e2e")

EDU_ROLES = ("super_admin", "school_admin", "edu_dept")
NON_EDU_ROLES = ("teacher", "attendance_taker", "hr_dept", "finance_dept")

# Fixture landmarks (tests/e2e/academic_intelligence.php :: seed +
# scenario_student_tracking_fixture).
C1, C2 = 1, 2
S_GEEZ, S_MUSIC, S_HIST = 1, 2, 3
T_BEKELE, T_ALMAZ, T_KEBEDE = 11, 12, 13
Y1 = 1
T1, T2 = 1, 2
C1_STUDENTS = {101, 102, 103, 104, 105}
C2_STUDENTS = {201, 202, 203}
C1_ASSESSMENTS = {1, 2, 3, 7}
C2_ASSESSMENTS = {4, 5, 6}
# Assessment 6 is C2/History and carries a revision_needed packet filed
# by BEKELE, who holds no History assignment anywhere. The trap.
A_HISTORY = 6
A_GEEZ_MID = 1          # approved packet + marks
A_MUSIC_TEST = 3        # status from marks, NO packet
A_MUSIC_PROJECT = 7     # exists, nothing else
# Scratch ids, seeded and removed by the tests that need them.
SCRATCH_USER = 4250
SCRATCH_CLASS = 91
PAGE_MEMBER_BASE = 7300


def _php_binary():
    override = os.environ.get("SSMS_E2E_PHP", "").strip()
    if override:
        return override if Path(override).is_file() else None
    return shutil.which("php")


def _probe_db(php):
    probe = (
        "require %s; "
        "$m = @new mysqli(DB_HOST, DB_USER, DB_PASS, %s); "
        "exit($m->connect_errno ? 3 : 0);" % (repr(str(ENV_FILE)), repr(SYNC_DB))
    )
    proc = subprocess.run([php, "-r", probe], capture_output=True, text=True, timeout=30)
    return proc.returncode == 0


def _strip_comments(src):
    """Remove // and /* */ comments so prose cannot satisfy a code assertion."""
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    return re.sub(r"(?m)^\s*//.*$|(?<=[;{}\)\s])//[^\n]*", "", src)


class _ClassBase(unittest.TestCase):
    """Seeds the tracking fixture once, then calls endpoints as a role."""

    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — class tracking e2e skipped")
        if not RUNNER.is_file() or not ROLE_RUNNER.is_file():
            raise unittest.SkipTest("e2e runners not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(".fkss_env.php not present — class tracking e2e skipped")
        if not _probe_db(php):
            raise unittest.SkipTest(f"e2e database '{SYNC_DB}' unreachable — skipped")
        cls.php = php

        proc = subprocess.run(
            [php, str(RUNNER), "student_tracking_fixture"],
            capture_output=True, text=True, timeout=600, cwd=str(ROOT),
            env={**os.environ, "SSMS_AUDIT_TESTING": "1", "SSMS_SYNC_DB": SYNC_DB},
        )
        if proc.returncode != 0:
            raise unittest.SkipTest(
                f"could not seed tracking fixture:\n{proc.stdout}\n{proc.stderr}"
            )

    def call(self, api, action, role="edu_dept", **params):
        payload = {"action": action}
        payload.update({k: v for k, v in params.items() if v is not None})
        proc = subprocess.run(
            [self.php, str(ROLE_RUNNER), api, role, "GET", json.dumps(payload)],
            capture_output=True, text=True, timeout=120, cwd=str(ROOT),
            env={**os.environ, "SSMS_DB_NAME": SYNC_DB, "SSMS_SYNC_DB": SYNC_DB},
        )
        lines = (proc.stdout or "").strip().splitlines()
        if not lines:
            self.fail(f"{action}: no output (exit {proc.returncode})\n{proc.stderr}")
        try:
            return json.loads(lines[-1])
        except json.JSONDecodeError:
            self.fail(f"{action}: non-JSON response: {lines[-1][:300]}")

    # ── the five endpoints ────────────────────────────────────────────
    def detail(self, class_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_class_detail", role,
                         class_id=class_id, **kw)

    def students(self, class_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_class_students", role,
                         class_id=class_id, **kw)

    def subjects(self, class_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_class_subjects", role,
                         class_id=class_id, **kw)

    def teachers(self, class_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_class_teachers", role,
                         class_id=class_id, **kw)

    def assessments(self, class_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_class_assessments", role,
                         class_id=class_id, **kw)

    # ── helpers ───────────────────────────────────────────────────────
    def ids_of(self, payload, key, field):
        return {r[field] for r in payload[key]}

    def sql(self, statements):
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$c->multi_query(%s);"
            "while ($c->more_results() && $c->next_result()) {}"
            "echo 'ok';"
            % (repr(str(ENV_FILE)), repr(SYNC_DB), repr(statements))
        )
        subprocess.run(
            [self.php, "-r", script], capture_output=True, text=True, timeout=60,
            cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )

    def scalar(self, expr):
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$r = $c->query(%s);"
            "$row = $r ? $r->fetch_row() : null;"
            "echo $row ? $row[0] : '';"
            % (repr(str(ENV_FILE)), repr(SYNC_DB), repr(expr))
        )
        proc = subprocess.run(
            [self.php, "-r", script], capture_output=True, text=True, timeout=60,
            cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )
        out = (proc.stdout or "").strip().splitlines()
        return out[-1] if out else ""


# ══════════════════════════════════════════════════════════════════════
# THE CLASS DOMAIN MODEL
# ══════════════════════════════════════════════════════════════════════

class ClassDomainModelTests(_ClassBase):
    """
    A class is year-agnostic. The academic year is reporting context for
    the data hanging off it, never part of its identity.
    """

    def test_the_classes_table_has_no_academic_year(self):
        """
        The premise of the whole phase, asserted against the live schema
        rather than assumed. If a migration ever adds the column, this
        fails and the year semantics must be re-examined before the
        tracking layer is trusted.
        """
        got = self.scalar(
            "SELECT COUNT(*) FROM information_schema.columns "
            "WHERE table_schema = DATABASE() AND table_name = 'classes' "
            "AND column_name = 'academic_year_id'"
        )
        self.assertEqual("0", got,
                         "classes gained an academic_year_id; Phase 5 assumes it has none")

    def test_class_subjects_has_no_academic_year_either(self):
        got = self.scalar(
            "SELECT COUNT(*) FROM information_schema.columns "
            "WHERE table_schema = DATABASE() AND table_name = 'class_subjects' "
            "AND column_name = 'academic_year_id'"
        )
        self.assertEqual("0", got,
                         "class_subjects gained an academic_year_id; offerings are "
                         "no longer standing arrangements")

    def test_a_class_identifies_itself_without_a_year(self):
        got = self.detail(C1)
        self.assertEqual("success", got["status"])
        self.assertEqual(C1, got["class"]["id"])
        self.assertEqual("Grade 4", got["class"]["class_name_en"])
        self.assertEqual("G4", got["class"]["class_code"])
        # The year is present, but as context.
        self.assertIn("context", got)
        self.assertIn("year_id", got["context"])
        self.assertNotIn("academic_year_id", got["class"])

    def test_the_class_survives_a_year_it_has_no_data_for(self):
        """
        A class is not created by its marks. Asked about a year in which
        nothing happened, the class must still be returned with its
        identity intact and its counts honestly zero.
        """
        # Guard the guard: the class is rich in Y1.
        rich = self.detail(C1, year_id=Y1)
        self.assertEqual(5, rich["summary"]["students"])
        self.assertEqual(4, rich["summary"]["assessments"])

        quiet = self.detail(C1, year_id=99)
        self.assertEqual("success", quiet["status"])
        self.assertEqual(C1, quiet["class"]["id"])
        self.assertEqual("Grade 4", quiet["class"]["class_name_en"])
        self.assertEqual(0, quiet["summary"]["students"])
        self.assertEqual(0, quiet["summary"]["assessments"])

    def test_the_offering_list_is_not_filtered_by_year(self):
        """
        class_subjects has no year, so the offerings must be identical in
        a year with data and a year without. Only the counts beside them
        move.
        """
        rich = self.subjects(C1, year_id=Y1)
        quiet = self.subjects(C1, year_id=99)
        self.assertEqual(
            {s["subject_id"] for s in rich["subjects"]},
            {s["subject_id"] for s in quiet["subjects"]},
        )
        self.assertEqual({S_GEEZ, S_MUSIC}, {s["subject_id"] for s in rich["subjects"]})
        # Guard the guard: the counts really did move, so the comparison
        # above is not comparing two empty sets.
        self.assertTrue(any(s["assessment_count"] > 0 for s in rich["subjects"]))
        self.assertTrue(all(s["assessment_count"] == 0 for s in quiet["subjects"]))

    def test_the_duration_type_is_reported_as_recorded(self):
        rows = {s["subject_id"]: s for s in self.subjects(C1)["subjects"]}
        self.assertEqual("FULL_YEAR", rows[S_GEEZ]["duration_type"])
        self.assertEqual("SEMESTER_ONLY", rows[S_MUSIC]["duration_type"])
        self.assertEqual(T1, rows[S_MUSIC]["term_id"])
        # NULL is migration 056's third value and must survive as NULL.
        hist = {s["subject_id"]: s for s in self.subjects(C2)["subjects"]}
        self.assertIsNone(hist[S_HIST]["duration_type"])


# ══════════════════════════════════════════════════════════════════════
# RELATIONSHIP INTEGRITY — the four relationships are read, not inferred
# ══════════════════════════════════════════════════════════════════════

class ClassRelationshipTests(_ClassBase):

    def test_students_come_from_enrollments(self):
        got = self.students(C1, per_page=100)
        self.assertEqual(C1_STUDENTS, self.ids_of(got, "students", "member_id"))
        self.assertEqual(5, got["total"])

    def test_students_of_another_class_never_leak_in(self):
        # Guard the guard: C2 really does have students.
        other = self.students(C2, per_page=100)
        self.assertEqual(C2_STUDENTS, self.ids_of(other, "students", "member_id"))
        self.assertTrue(other["students"], "C2 has no students; the test below is vacuous")

        got = self.students(C1, per_page=100)
        self.assertEqual(set(), self.ids_of(got, "students", "member_id") & C2_STUDENTS)

    def test_a_withdrawn_student_is_not_on_the_roll(self):
        """
        Every enrolment in the fixture is active, so the status
        condition is invisible unless a withdrawn row is introduced.
        Without this, removing `status = 'active'` changes nothing and
        the condition is untested.
        """
        probe = 105
        self.sql(
            "UPDATE class_enrollments SET status = 'withdrawn' "
            f"WHERE member_id = {probe} AND class_id = {C1}"
        )
        try:
            # Guard the guard: the row is still there, just withdrawn.
            self.assertEqual("withdrawn", self.scalar(
                "SELECT status FROM class_enrollments "
                f"WHERE member_id = {probe} AND class_id = {C1}"))

            got = self.students(C1, per_page=100)
            self.assertNotIn(probe, self.ids_of(got, "students", "member_id"))
            self.assertEqual(4, got["total"])
            # The summary count must agree with the list.
            self.assertEqual(4, self.detail(C1)["summary"]["students"])
        finally:
            self.sql(
                "UPDATE class_enrollments SET status = 'active' "
                f"WHERE member_id = {probe} AND class_id = {C1}"
            )
        # And restored, they are back: the only thing hiding them was
        # the status.
        self.assertIn(probe, self.ids_of(
            self.students(C1, per_page=100), "students", "member_id"))
        self.assertEqual(5, self.detail(C1)["summary"]["students"])

    def test_a_mark_does_not_enroll_a_student(self):
        """
        A student with an academic_records row for a class they are not
        enrolled in must not appear on its roll. Membership comes from
        class_enrollments alone.
        """
        probe = 201  # enrolled in C2
        self.sql(
            "INSERT INTO academic_records "
            "(member_id, class_id, subject_id, assessment_id, academic_year_id, "
            " term_id, score, max_score) "
            f"VALUES ({probe}, {C1}, {S_GEEZ}, {A_GEEZ_MID}, {Y1}, {T1}, 70, 100)"
        )
        try:
            # Guard the guard: the misleading row really is there.
            self.assertEqual(
                "1",
                self.scalar(
                    "SELECT COUNT(*) FROM academic_records "
                    f"WHERE member_id = {probe} AND class_id = {C1}"
                ),
            )
            got = self.students(C1, per_page=100)
            self.assertNotIn(probe, self.ids_of(got, "students", "member_id"))
            self.assertEqual(5, got["total"])
            self.assertEqual(5, self.detail(C1)["summary"]["students"])
        finally:
            self.sql(
                f"DELETE FROM academic_records WHERE member_id = {probe} "
                f"AND class_id = {C1}"
            )

    def test_an_assessment_does_not_create_an_offering(self):
        """
        An assessment row naming a subject the class does not offer must
        not add that subject to the offering list. class_subjects is what
        makes an offering.
        """
        self.sql(
            "INSERT INTO assessments "
            "(id, class_id, subject_id, academic_year_id, term_id, "
            " assessment_name, assessment_type, max_score, is_active) "
            f"VALUES (950, {C1}, {S_HIST}, {Y1}, {T1}, 'Stray', 'test', 100, 1)"
        )
        try:
            self.assertEqual("1", self.scalar(
                "SELECT COUNT(*) FROM assessments WHERE id = 950"))
            # Guard the guard: C1 genuinely offers two subjects, so an
            # empty list would not be what makes this pass.
            offered = {s["subject_id"] for s in self.subjects(C1)["subjects"]}
            self.assertEqual({S_GEEZ, S_MUSIC}, offered)
            self.assertNotIn(S_HIST, offered)
            self.assertEqual(2, self.detail(C1)["summary"]["subjects"])
        finally:
            self.sql("DELETE FROM assessments WHERE id = 950")

    def test_a_filed_packet_does_not_make_someone_a_teacher(self):
        """
        The fixture's built-in trap: assessment 6 is C2/History and its
        mark list was filed by BEKELE, who holds no History assignment at
        all. He must not appear as a teacher of C2/History.
        """
        # Guard the guard: the packet exists, and it is his.
        self.assertEqual(str(T_BEKELE), self.scalar(
            "SELECT teacher_id FROM grade_submissions "
            f"WHERE assessment_id = {A_HISTORY}"))
        self.assertEqual(str(S_HIST), self.scalar(
            "SELECT subject_id FROM grade_submissions "
            f"WHERE assessment_id = {A_HISTORY}"))
        # ...and he holds no History assignment anywhere.
        self.assertEqual("0", self.scalar(
            "SELECT COUNT(*) FROM teacher_assignments "
            f"WHERE teacher_id = {T_BEKELE} AND subject_id = {S_HIST}"))

        hist = [s for s in self.subjects(C2)["subjects"]
                if s["subject_id"] == S_HIST][0]
        self.assertEqual([], hist["teachers"])

        # And guard it again from the other side: the same call DOES
        # return a teacher where an assignment exists, so an empty list
        # is a finding and not a broken query.
        geez = [s for s in self.subjects(C2)["subjects"]
                if s["subject_id"] == S_GEEZ][0]
        self.assertEqual([T_BEKELE], [t["teacher_id"] for t in geez["teachers"]])

    def test_assessments_belong_to_the_class_not_to_a_matching_subject(self):
        """
        C1 and C2 both offer Geez. Joining on subject_id alone would mix
        their assessments. The class condition is what keeps them apart.
        """
        got_c1 = self.ids_of(self.assessments(C1), "assessments", "assessment_id")
        got_c2 = self.ids_of(self.assessments(C2), "assessments", "assessment_id")
        self.assertEqual(C1_ASSESSMENTS, got_c1)
        self.assertEqual(C2_ASSESSMENTS, got_c2)
        self.assertEqual(set(), got_c1 & got_c2)
        # Guard the guard: both classes really do teach the same subject,
        # so the separation above is meaningful.
        self.assertIn(S_GEEZ, {s["subject_id"] for s in self.subjects(C1)["subjects"]})
        self.assertIn(S_GEEZ, {s["subject_id"] for s in self.subjects(C2)["subjects"]})

    def test_teachers_come_from_assignments(self):
        got = self.teachers(C1)
        self.assertEqual({T_BEKELE, T_ALMAZ},
                         self.ids_of(got, "teachers", "teacher_id"))

    def test_a_teacher_of_another_class_never_leaks_in(self):
        # ALMAZ teaches C1/Music and holds nothing in C2.
        self.assertEqual("0", self.scalar(
            "SELECT COUNT(*) FROM teacher_assignments "
            f"WHERE teacher_id = {T_ALMAZ} AND class_id = {C2} AND is_active = 1"))
        self.assertNotIn(T_ALMAZ, self.ids_of(self.teachers(C2), "teachers", "teacher_id"))
        # Guard the guard: she is genuinely returned for C1.
        self.assertIn(T_ALMAZ, self.ids_of(self.teachers(C1), "teachers", "teacher_id"))


# ══════════════════════════════════════════════════════════════════════
# THE TWO MEANINGFUL NULLS
# ══════════════════════════════════════════════════════════════════════

class ClassNullSemanticsTests(_ClassBase):
    """
    teacher_assignments.subject_id IS NULL is a homeroom assignment.
    teacher_assignments.academic_year_id IS NULL is a standing one.
    Neither is a missing value and neither may be reinterpreted.
    """

    def test_both_columns_are_still_nullable(self):
        for col in ("subject_id", "academic_year_id"):
            with self.subTest(column=col):
                got = self.scalar(
                    "SELECT is_nullable FROM information_schema.columns "
                    "WHERE table_schema = DATABASE() "
                    "AND table_name = 'teacher_assignments' "
                    f"AND column_name = '{col}'"
                )
                self.assertEqual("YES", got,
                                 f"teacher_assignments.{col} is no longer nullable; "
                                 "the NULL semantics Phase 5 relies on have changed")

    def test_a_homeroom_assignment_is_homeroom_and_not_a_subject(self):
        self.sql(
            "INSERT INTO teacher_assignments "
            "(teacher_id, class_id, subject_id, academic_year_id, "
            " is_class_teacher, is_primary, is_active, assignment_role) "
            f"VALUES ({T_ALMAZ}, {C1}, NULL, {Y1}, 1, 0, 1, 'homeroom')"
        )
        try:
            detail = self.detail(C1)
            homeroom = detail["homeroom_teachers"]
            self.assertEqual([T_ALMAZ], [t["teacher_id"] for t in homeroom])

            rows = self.teachers(C1)["teachers"]
            hr = [t for t in rows if t["is_homeroom"]]
            self.assertEqual(1, len(hr))
            self.assertEqual(T_ALMAZ, hr[0]["teacher_id"])
            self.assertIsNone(hr[0]["subject_id"])
            self.assertIsNone(hr[0]["subject_name"])

            # It must appear against NO subject offering.
            for s in self.subjects(C1)["subjects"]:
                with self.subTest(subject=s["subject_id"]):
                    pairs = [(t["teacher_id"], s["subject_id"]) for t in s["teachers"]]
                    self.assertNotIn((T_ALMAZ, S_GEEZ), pairs)
                    self.assertNotIn((T_ALMAZ, S_HIST), pairs)

            # Guard the guard: subject teachers ARE attached to offerings,
            # so "homeroom appears nowhere" is a real finding.
            geez = [s for s in self.subjects(C1)["subjects"]
                    if s["subject_id"] == S_GEEZ][0]
            self.assertEqual([T_BEKELE], [t["teacher_id"] for t in geez["teachers"]])
        finally:
            self.sql(
                "DELETE FROM teacher_assignments "
                f"WHERE teacher_id = {T_ALMAZ} AND class_id = {C1} "
                "AND subject_id IS NULL"
            )

    def test_a_subject_teacher_is_never_reported_as_homeroom(self):
        """The inverse, which is the mutation that would otherwise pass."""
        rows = self.teachers(C1)["teachers"]
        self.assertTrue(rows, "no assignments at all; the assertion would be vacuous")
        for t in rows:
            with self.subTest(teacher=t["teacher_id"]):
                if t["subject_id"] is not None:
                    self.assertFalse(t["is_homeroom"])
        # And no homeroom teacher is claimed when none is recorded.
        self.assertEqual([], self.detail(C1)["homeroom_teachers"])
        self.assertEqual("no_teacher_assignments",
                         self.detail(C1)["data_state"]["homeroom"])

    def test_a_homeroom_row_held_by_a_non_teacher_is_not_exposed(self):
        """
        The homeroom query is a separate query from the teacher list and
        needs its own role check. Nothing else in the suite reaches it,
        so without this the role condition could be dropped from the
        homeroom query alone and no test would notice.
        """
        self.sql(
            f"INSERT INTO users (id, username, full_name, role, is_active) "
            f"VALUES ({SCRATCH_USER}, 'ex_homeroom_p5', 'Ex Homeroom', "
            f"'finance_dept', 1);"
            "INSERT INTO teacher_assignments "
            "(teacher_id, class_id, subject_id, academic_year_id, "
            " is_class_teacher, is_primary, is_active, assignment_role) "
            f"VALUES ({SCRATCH_USER}, {C1}, NULL, {Y1}, 1, 0, 1, 'homeroom');"
        )
        try:
            # Guard the guard: the homeroom row exists and is active.
            self.assertEqual("1", self.scalar(
                "SELECT COUNT(*) FROM teacher_assignments "
                f"WHERE teacher_id = {SCRATCH_USER} AND subject_id IS NULL "
                "AND is_active = 1"))

            detail = self.detail(C1)
            self.assertEqual(
                [], [t["teacher_id"] for t in detail["homeroom_teachers"]])
            self.assertEqual("no_teacher_assignments",
                             detail["data_state"]["homeroom"])
            self.assertNotIn(SCRATCH_USER,
                             self.ids_of(self.teachers(C1), "teachers", "teacher_id"))

            # Guard the guard, positively: promote them and the SAME
            # homeroom row surfaces, so the role was the only thing
            # keeping it out.
            self.sql(f"UPDATE users SET role = 'teacher' WHERE id = {SCRATCH_USER}")
            promoted = self.detail(C1)
            self.assertEqual(
                [SCRATCH_USER],
                [t["teacher_id"] for t in promoted["homeroom_teachers"]])
            self.assertEqual("ok", promoted["data_state"]["homeroom"])
        finally:
            self.sql(
                f"DELETE FROM teacher_assignments WHERE teacher_id = {SCRATCH_USER};"
                f"DELETE FROM users WHERE id = {SCRATCH_USER};"
            )

    def test_a_standing_homeroom_assignment_is_kept_and_flagged(self):
        """
        The homeroom query is separate from the teacher list and builds
        its own year predicate, so it needs its own standing-assignment
        case. Without this, the "OR academic_year_id IS NULL" branch
        could be dropped from the homeroom query alone and a homeroom
        teacher with no year would silently vanish.
        """
        self.sql(
            "INSERT INTO teacher_assignments "
            "(teacher_id, class_id, subject_id, academic_year_id, "
            " is_class_teacher, is_primary, is_active, assignment_role) "
            f"VALUES ({T_ALMAZ}, {C1}, NULL, NULL, 1, 0, 1, 'homeroom')"
        )
        try:
            # Guard the guard: the row really has no academic year.
            self.assertEqual("1", self.scalar(
                "SELECT COUNT(*) FROM teacher_assignments "
                f"WHERE teacher_id = {T_ALMAZ} AND class_id = {C1} "
                "AND subject_id IS NULL AND academic_year_id IS NULL"))

            # It appears for the selected year...
            scoped = self.detail(C1, year_id=Y1)["homeroom_teachers"]
            self.assertEqual([T_ALMAZ], [t["teacher_id"] for t in scoped])
            self.assertTrue(scoped[0]["is_standing"])

            # ...and for a year it was never stamped with, because it
            # belongs to no year at all.
            other = self.detail(C1, year_id=99)["homeroom_teachers"]
            self.assertEqual([T_ALMAZ], [t["teacher_id"] for t in other])
            self.assertTrue(other[0]["is_standing"])

            # The stored row is untouched -- not rewritten into the year.
            self.assertEqual("1", self.scalar(
                "SELECT COUNT(*) FROM teacher_assignments "
                f"WHERE teacher_id = {T_ALMAZ} AND class_id = {C1} "
                "AND subject_id IS NULL AND academic_year_id IS NULL"))
        finally:
            self.sql(
                "DELETE FROM teacher_assignments "
                f"WHERE teacher_id = {T_ALMAZ} AND class_id = {C1} "
                "AND subject_id IS NULL"
            )

    def test_a_year_scoped_homeroom_assignment_is_not_flagged_standing(self):
        """The other half: a year-stamped homeroom row is NOT standing."""
        self.sql(
            "INSERT INTO teacher_assignments "
            "(teacher_id, class_id, subject_id, academic_year_id, "
            " is_class_teacher, is_primary, is_active, assignment_role) "
            f"VALUES ({T_ALMAZ}, {C1}, NULL, {Y1}, 1, 0, 1, 'homeroom')"
        )
        try:
            scoped = self.detail(C1, year_id=Y1)["homeroom_teachers"]
            self.assertEqual([T_ALMAZ], [t["teacher_id"] for t in scoped])
            self.assertFalse(scoped[0]["is_standing"])
            # And it does NOT leak into another year.
            self.assertEqual([], self.detail(C1, year_id=99)["homeroom_teachers"])
        finally:
            self.sql(
                "DELETE FROM teacher_assignments "
                f"WHERE teacher_id = {T_ALMAZ} AND class_id = {C1} "
                "AND subject_id IS NULL"
            )

    def test_a_standing_assignment_is_kept_and_flagged(self):
        """
        academic_year_id IS NULL means the assignment is not tied to a
        year. It must appear in every year and be labelled, never
        rewritten into the selected one.
        """
        self.sql(
            "INSERT INTO teacher_assignments "
            "(teacher_id, class_id, subject_id, academic_year_id, "
            " is_class_teacher, is_primary, is_active, assignment_role) "
            f"VALUES ({T_KEBEDE}, {C1}, {S_GEEZ}, NULL, 0, 0, 1, 'assistant')"
        )
        try:
            rows = {t["teacher_id"]: t for t in self.teachers(C1)["teachers"]}
            self.assertIn(T_KEBEDE, rows)
            self.assertTrue(rows[T_KEBEDE]["is_standing"])
            # Guard the guard: a year-scoped assignment is NOT flagged.
            self.assertFalse(rows[T_BEKELE]["is_standing"])

            # It survives a year it was never stamped with.
            other = {t["teacher_id"] for t in self.teachers(C1, year_id=99)["teachers"]}
            self.assertIn(T_KEBEDE, other)
            self.assertNotIn(T_BEKELE, other)

            # And the stored row is untouched.
            self.assertEqual("1", self.scalar(
                "SELECT COUNT(*) FROM teacher_assignments "
                f"WHERE teacher_id = {T_KEBEDE} AND class_id = {C1} "
                "AND academic_year_id IS NULL"))
        finally:
            self.sql(
                "DELETE FROM teacher_assignments "
                f"WHERE teacher_id = {T_KEBEDE} AND class_id = {C1}"
            )


# ══════════════════════════════════════════════════════════════════════
# users.role = 'teacher'
# ══════════════════════════════════════════════════════════════════════

class ClassTeacherRoleTests(_ClassBase):
    """
    Phase 3 found an assignment row outliving the user's teacher role.
    Every surface that exposes a teacher must re-check the role.
    """

    def test_an_assignment_held_by_a_non_teacher_is_not_exposed(self):
        self.sql(
            f"INSERT INTO users (id, username, full_name, role, is_active) "
            f"VALUES ({SCRATCH_USER}, 'ex_teacher_p5', 'Ex Teacher', "
            f"'finance_dept', 1) "
            f"ON DUPLICATE KEY UPDATE role = 'finance_dept';"
            "INSERT INTO teacher_assignments "
            "(teacher_id, class_id, subject_id, academic_year_id, "
            " is_class_teacher, is_primary, is_active, assignment_role) "
            f"VALUES ({SCRATCH_USER}, {C1}, {S_GEEZ}, {Y1}, 0, 0, 1, 'assistant');"
        )
        try:
            # Guard the guard: the assignment row really exists and is active.
            self.assertEqual("1", self.scalar(
                "SELECT COUNT(*) FROM teacher_assignments "
                f"WHERE teacher_id = {SCRATCH_USER} AND is_active = 1"))
            self.assertEqual("finance_dept", self.scalar(
                f"SELECT role FROM users WHERE id = {SCRATCH_USER}"))

            listed = self.ids_of(self.teachers(C1), "teachers", "teacher_id")
            self.assertNotIn(SCRATCH_USER, listed)
            # The summary count must agree with the list.
            self.assertEqual(len(listed), self.detail(C1)["summary"]["teachers"])
            # And it must not reach the offering either.
            geez = [s for s in self.subjects(C1)["subjects"]
                    if s["subject_id"] == S_GEEZ][0]
            self.assertNotIn(SCRATCH_USER, [t["teacher_id"] for t in geez["teachers"]])

            # Guard the guard, positively: promote the same user and the
            # same row now does surface, proving the only thing excluding
            # them was the role.
            self.sql(f"UPDATE users SET role = 'teacher' WHERE id = {SCRATCH_USER}")
            self.assertIn(SCRATCH_USER,
                          self.ids_of(self.teachers(C1), "teachers", "teacher_id"))
        finally:
            self.sql(
                f"DELETE FROM teacher_assignments WHERE teacher_id = {SCRATCH_USER};"
                f"DELETE FROM users WHERE id = {SCRATCH_USER};"
            )

    def test_an_inactive_assignment_is_not_exposed(self):
        self.sql(
            "UPDATE teacher_assignments SET is_active = 0 "
            f"WHERE teacher_id = {T_ALMAZ} AND class_id = {C1}"
        )
        try:
            self.assertNotIn(T_ALMAZ,
                             self.ids_of(self.teachers(C1), "teachers", "teacher_id"))
        finally:
            self.sql(
                "UPDATE teacher_assignments SET is_active = 1 "
                f"WHERE teacher_id = {T_ALMAZ} AND class_id = {C1}"
            )
        # Guard the guard: restored, she is back.
        self.assertIn(T_ALMAZ, self.ids_of(self.teachers(C1), "teachers", "teacher_id"))


# ══════════════════════════════════════════════════════════════════════
# FOUR FACTS STAY FOUR FACTS
# ══════════════════════════════════════════════════════════════════════

class ClassAssessmentFactTests(_ClassBase):
    """
    The assessment exists / a packet was submitted / the packet has a
    status / a mark was recorded. Four independent facts.
    """

    def rows(self, class_id=C1):
        return {a["assessment_id"]: a for a in self.assessments(class_id)["assessments"]}

    def test_an_assessment_with_nothing_against_it_is_not_started(self):
        a = self.rows()[A_MUSIC_PROJECT]
        self.assertIsNone(a["workflow_status"])
        self.assertEqual("Not started", a["workflow_label"])
        self.assertIsNone(a["submission_id"])
        self.assertFalse(a["has_results"])

    def test_a_status_can_exist_without_a_packet(self):
        """
        Assessment 3 has marks but no grade_submissions row. Its status
        is resolved from the marks, and the absence of a packet is
        reported honestly rather than downgrading the status.
        """
        self.assertEqual("0", self.scalar(
            "SELECT COUNT(*) FROM grade_submissions "
            f"WHERE assessment_id = {A_MUSIC_TEST}"))
        a = self.rows()[A_MUSIC_TEST]
        self.assertEqual("submitted", a["workflow_status"])
        self.assertEqual("Complete", a["workflow_label"])
        self.assertIsNone(a["submission_id"])
        self.assertTrue(a["has_results"])

    def test_a_packet_can_exist_with_a_status_and_marks(self):
        a = self.rows()[A_GEEZ_MID]
        self.assertEqual("approved", a["workflow_status"])
        self.assertEqual("Approved", a["workflow_label"])
        self.assertIsNotNone(a["submission_id"])
        self.assertTrue(a["has_results"])

    def test_the_four_facts_are_not_the_same_field(self):
        """
        Across the fixture the four facts must actually disagree with one
        another somewhere, or they could all be aliases of one value.
        """
        rows = list(self.rows().values()) + list(self.rows(C2).values())
        self.assertTrue(any(r["submission_id"] is None and r["workflow_status"]
                            for r in rows),
                        "no assessment has a status without a packet")
        self.assertTrue(any(r["submission_id"] is not None for r in rows),
                        "no assessment has a packet at all")
        self.assertTrue(any(r["has_results"] and r["submission_id"] is None
                            for r in rows),
                        "marks and packets never disagree")
        self.assertTrue(any(not r["has_results"] and r["workflow_status"] is None
                            for r in rows),
                        "no assessment is genuinely untouched")

    def test_a_packet_can_exist_with_no_marks_at_all(self):
        """
        Every packet in the fixture also has marks, so "a status exists"
        and "a mark exists" agree everywhere and either could stand in
        for the other. This introduces the one combination the fixture
        lacks: a submitted mark list with no mark rows behind it.
        """
        self.sql(
            "INSERT INTO assessments "
            "(id, class_id, subject_id, academic_year_id, term_id, "
            " assessment_name, assessment_type, max_score, is_active) "
            f"VALUES (951, {C1}, {S_GEEZ}, {Y1}, {T1}, 'Empty packet', "
            f"'test', 100, 1);"
            "INSERT INTO grade_submissions "
            "(id, teacher_id, class_id, subject_id, academic_year_id, "
            " term_id, assessment_id, submission_type, status, student_count) "
            f"VALUES (951, {T_BEKELE}, {C1}, {S_GEEZ}, {Y1}, {T1}, 951, "
            f"'marklist', 'submitted', 0);"
        )
        try:
            # Guard the guard: the packet exists and has no mark rows.
            self.assertEqual("1", self.scalar(
                "SELECT COUNT(*) FROM grade_submissions WHERE assessment_id = 951"))
            self.assertEqual("0", self.scalar(
                "SELECT COUNT(*) FROM academic_records WHERE assessment_id = 951"))

            a = self.rows()[951]
            self.assertEqual("submitted", a["workflow_status"])
            self.assertEqual("Complete", a["workflow_label"])
            self.assertEqual(951, a["submission_id"])
            # The decisive assertion: a status is NOT a mark.
            self.assertFalse(a["has_results"],
                             "a submitted packet with no mark rows is being "
                             "reported as having results")

            # The offering must not claim marks on the strength of it.
            geez = [x for x in self.subjects(C1)["subjects"]
                    if x["subject_id"] == S_GEEZ][0]
            self.assertEqual(3, geez["assessment_count"])
            # Guard the guard: Geez genuinely does have marks elsewhere,
            # so has_results staying true here is correct and not luck.
            self.assertTrue(geez["has_results"])
        finally:
            self.sql(
                "DELETE FROM grade_submissions WHERE id = 951;"
                "DELETE FROM assessments WHERE id = 951;"
            )

    def test_a_mark_without_a_packet_is_still_a_mark(self):
        """
        The mirror of the test above, and the other half of what keeps
        'status' and 'has marks' from collapsing into one field.
        """
        a = self.rows()[A_MUSIC_TEST]
        self.assertIsNone(a["submission_id"])
        self.assertTrue(a["has_results"])

    def test_a_missing_mark_is_never_a_zero(self):
        for a in self.assessments(C1)["assessments"]:
            with self.subTest(assessment=a["assessment_id"]):
                self.assertIsInstance(a["has_results"], bool)
                # No score, percentage or average is served here at all.
                for forbidden in ("score", "average", "percentage", "grade_letter",
                                  "pass_rate", "rank"):
                    self.assertNotIn(forbidden, a)

    def test_a_null_weight_stays_null(self):
        rows = self.rows()
        self.assertIsNone(rows[A_GEEZ_MID]["weight"])
        # Guard the guard: a real weight is served as a number.
        self.assertEqual(50, rows[A_MUSIC_TEST]["weight"])


# ══════════════════════════════════════════════════════════════════════
# AUTHORIZATION — eight cross-scope cases
# ══════════════════════════════════════════════════════════════════════

class ClassAuthorizationTests(_ClassBase):

    ACTIONS = ("tracking_class_detail", "tracking_class_students",
               "tracking_class_subjects", "tracking_class_teachers",
               "tracking_class_assessments")

    def test_1_every_class_action_refuses_non_education_roles(self):
        for action in self.ACTIONS:
            for role in NON_EDU_ROLES:
                with self.subTest(action=action, role=role):
                    got = self.call("api_education.php", action, role, class_id=C1)
                    self.assertEqual("error", got["status"])
                    self.assertNotIn("class", got)
                    self.assertNotIn("students", got)

    def test_2_the_education_roles_are_allowed(self):
        """Guard the guard: the refusals above are about the role."""
        for role in EDU_ROLES:
            with self.subTest(role=role):
                got = self.detail(C1, role=role)
                self.assertEqual("success", got["status"])

    def test_3_a_missing_class_is_rejected_before_any_read(self):
        got = self.call("api_education.php", "tracking_class_detail", "edu_dept")
        self.assertEqual("error", got["status"])
        self.assertEqual("invalid_class", got["code"])

    def test_4_a_non_positive_class_is_rejected(self):
        for bad in (0, -1, -999):
            with self.subTest(class_id=bad):
                got = self.detail(bad)
                self.assertEqual("error", got["status"])
                self.assertEqual("invalid_class", got["code"])

    def test_5_a_non_numeric_class_cannot_inject(self):
        """
        class_id is cast to int before it is used, so a payload either
        collapses to a harmless number or to 0. What must never happen
        is the trailing SQL reaching the database, or the answer being
        for anything other than the number the cast produced.
        """
        before = self.scalar("SELECT COUNT(*) FROM classes")

        # These collapse to 1. The answer must be class 1 and nothing more.
        for bad in ("1 OR 1=1", "1; DROP TABLE classes", "1 UNION SELECT 1",
                    "1 AND SLEEP(5)"):
            with self.subTest(class_id=bad):
                got = self.detail(bad)
                self.assertEqual("success", got["status"])
                self.assertEqual(C1, got["class"]["id"])
                self.assertEqual(C1, got["scope"]["class_id"])

        # These collapse to 0 and are refused by the validator.
        for bad in ("abc", "", "DROP TABLE classes", "null"):
            with self.subTest(class_id=bad):
                got = self.detail(bad)
                self.assertEqual("error", got["status"])
                self.assertEqual("invalid_class", got["code"])

        # Nothing was dropped, altered or truncated.
        self.assertEqual(before, self.scalar("SELECT COUNT(*) FROM classes"))
        self.assertTrue(int(before) >= 2)

    def test_6_an_unknown_class_is_its_own_answer(self):
        got = self.detail(99999)
        self.assertEqual("error", got["status"])
        self.assertEqual("unknown_class", got["code"])
        self.assertNotIn("students", got)

    def test_7_a_subject_filter_is_validated_against_the_class(self):
        """
        A browser may send any subject_id. Asking C1 about History — a
        subject it does not offer — must be refused rather than silently
        answered with an empty list or, worse, C2's History assessments.
        """
        got = self.assessments(C1, subject_id=S_HIST)
        self.assertEqual("error", got["status"])
        self.assertEqual("not_offered_here", got["code"])
        self.assertNotIn("assessments", got)
        # Guard the guard: a subject it DOES offer is answered.
        ok = self.assessments(C1, subject_id=S_GEEZ)
        self.assertEqual("success", ok["status"])
        self.assertEqual({1, 2}, self.ids_of(ok, "assessments", "assessment_id"))
        # And History is a real subject with real assessments elsewhere.
        other = self.assessments(C2, subject_id=S_HIST)
        self.assertEqual("success", other["status"])
        self.assertEqual({A_HISTORY}, self.ids_of(other, "assessments", "assessment_id"))

    def test_8_authorization_is_checked_before_the_class_is_read(self):
        """
        Pinned at source: the canViewClass call must precede any service
        call in the combined action block, so a refused caller cannot
        learn whether the class exists from a timing or error difference.
        """
        src = _strip_comments(API_EDU.read_text(encoding="utf-8"))
        start = src.index("tracking_class_detail':")
        block = src[start:src.index("case 'get_academic_intelligence'", start)]
        authz = block.index("canViewClass")
        self.assertIn("AcademicTrackingService::classDetail", block)
        service = block.index("AcademicTrackingService::")
        self.assertLess(authz, service,
                        "a class is read before it is authorized")
        # And the validation precedes the authorization.
        self.assertLess(block.index("invalid_class"), authz)

    def test_a_refused_class_leaks_nothing_about_it(self):
        for role in ("teacher", "finance_dept"):
            with self.subTest(role=role):
                refused = self.call("api_education.php", "tracking_class_detail",
                                    role, class_id=C1)
                blob = json.dumps(refused)
                self.assertNotIn("Grade 4", blob)
                self.assertNotIn("G4", blob)
                for sid in C1_STUDENTS:
                    self.assertNotIn(str(sid), blob)


# ══════════════════════════════════════════════════════════════════════
# DATA STATES, PAGINATION AND SEARCH
# ══════════════════════════════════════════════════════════════════════

class ClassDataStateTests(_ClassBase):

    def test_an_empty_search_and_an_empty_class_are_different_states(self):
        full = self.students(C1, per_page=100)
        self.assertEqual("ok", full["data_state"]["students"])

        nomatch = self.students(C1, q="zzzz-no-such-student")
        self.assertEqual("filtered_empty", nomatch["data_state"]["students"])
        self.assertEqual([], nomatch["students"])
        self.assertEqual(0, nomatch["total"])

        # A class with nobody enrolled for the year reports no_records,
        # not filtered_empty — nobody filtered anything.
        empty = self.students(C1, year_id=99)
        self.assertEqual("no_students", empty["data_state"]["students"])

    def test_the_search_actually_searches(self):
        got = self.students(C1, q="Abebe")
        self.assertEqual("ok", got["data_state"]["students"])
        self.assertEqual({101}, self.ids_of(got, "students", "member_id"))
        # By code too.
        self.assertEqual({102}, self.ids_of(
            self.students(C1, q="M-102"), "students", "member_id"))

    def test_the_search_cannot_escape_the_class(self):
        """A search is applied within the scope, never instead of it."""
        self.assertEqual("1", self.scalar(
            "SELECT COUNT(*) FROM members WHERE id = 201"))
        name = self.scalar("SELECT student_name FROM members WHERE id = 201")
        got = self.students(C1, q=name)
        self.assertNotIn(201, self.ids_of(got, "students", "member_id"))
        # Guard the guard: that same search inside C2 does find them.
        self.assertIn(201, self.ids_of(
            self.students(C2, q=name), "students", "member_id"))

    def test_per_page_is_clamped_at_both_ends(self):
        self.assertEqual(10, self.students(C1, per_page=1)["per_page"])
        self.assertEqual(10, self.students(C1, per_page=0)["per_page"])
        self.assertEqual(10, self.students(C1, per_page=-20)["per_page"])
        self.assertEqual(100, self.students(C1, per_page=100000)["per_page"])
        self.assertEqual(25, self.students(C1, per_page=25)["per_page"])

    def test_a_junk_page_falls_back_to_the_first(self):
        for bad in (-5, 0, "abc"):
            with self.subTest(page=bad):
                self.assertEqual(1, self.students(C1, page=bad)["page"])

    def test_pagination_walks_a_class_without_overlap_or_loss(self):
        """
        per_page is clamped to a minimum of 10, so the fixture's five
        students cannot produce a second page. This seeds a class large
        enough to actually cross the boundary.
        """
        rows = ",".join(
            f"({PAGE_MEMBER_BASE + i}, 'Pager {i:02d}')" for i in range(23)
        )
        enrol = ",".join(
            f"({PAGE_MEMBER_BASE + i}, {SCRATCH_CLASS}, {Y1}, 'active')"
            for i in range(23)
        )
        self.sql(
            f"INSERT INTO classes (id, class_name, class_name_en, class_code, "
            f"level_order, is_active) VALUES ({SCRATCH_CLASS}, 'Pager', "
            f"'Pager', 'PGR', 91, 1);"
            f"INSERT INTO members (id, student_name) VALUES {rows};"
            f"INSERT INTO class_enrollments "
            f"(member_id, class_id, academic_year_id, status) VALUES {enrol};"
        )
        try:
            expected = {PAGE_MEMBER_BASE + i for i in range(23)}
            first = self.students(SCRATCH_CLASS, per_page=10, page=1)
            self.assertEqual(23, first["total"])
            self.assertEqual(3, first["pages"])
            self.assertEqual(10, len(first["students"]))

            seen = set()
            for page in (1, 2, 3):
                got = self.students(SCRATCH_CLASS, per_page=10, page=page)
                ids = self.ids_of(got, "students", "member_id")
                self.assertEqual(set(), seen & ids,
                                 f"page {page} repeats an earlier student")
                seen |= ids
            self.assertEqual(expected, seen, "a student was lost between pages")
            self.assertEqual(3, len(self.students(
                SCRATCH_CLASS, per_page=10, page=3)["students"]))

            # Past the end is empty, not a silent wrap to page 1.
            over = self.students(SCRATCH_CLASS, per_page=10, page=99)
            self.assertEqual([], over["students"])
            self.assertEqual(23, over["total"])

            # A search is paginated within the scope too.
            narrowed = self.students(SCRATCH_CLASS, per_page=10, q="Pager 0")
            self.assertEqual(10, narrowed["total"])
            self.assertEqual("ok", narrowed["data_state"]["students"])
        finally:
            self.sql(
                f"DELETE FROM class_enrollments WHERE class_id = {SCRATCH_CLASS};"
                f"DELETE FROM members WHERE id >= {PAGE_MEMBER_BASE} "
                f"AND id < {PAGE_MEMBER_BASE + 40};"
                f"DELETE FROM classes WHERE id = {SCRATCH_CLASS};"
            )

    def test_a_class_with_no_offerings_says_so(self):
        self.sql(
            f"INSERT INTO classes (id, class_name, class_name_en, class_code, "
            f"level_order, is_active) VALUES ({SCRATCH_CLASS}, 'Scratch', "
            f"'Scratch', 'SCR', 91, 1)"
        )
        try:
            detail = self.detail(SCRATCH_CLASS)
            self.assertEqual("success", detail["status"])
            self.assertEqual(0, detail["summary"]["subjects"])
            self.assertEqual("no_subjects_offered", detail["data_state"]["subjects"])
            self.assertEqual("no_students", detail["data_state"]["students"])
            self.assertEqual("no_teacher_assignments",
                             detail["data_state"]["teachers"])
            self.assertEqual("no_assessments", detail["data_state"]["assessments"])
            self.assertEqual([], self.subjects(SCRATCH_CLASS)["subjects"])
            self.assertEqual([], self.teachers(SCRATCH_CLASS)["teachers"])
            self.assertEqual([], self.assessments(SCRATCH_CLASS)["assessments"])
        finally:
            self.sql(f"DELETE FROM classes WHERE id = {SCRATCH_CLASS}")

    def test_the_states_are_the_declared_vocabulary(self):
        # The vocabulary is the one Phases 2-4 established, extended by
        # Phase 5. The brief's generic "no_records" is realised as the
        # entity-specific name each endpoint already used, so the four
        # workflows stay consistent with one another; see the Phase 5
        # document, "Data states".
        allowed = {"ok", "no_students", "no_subjects_offered",
                   "no_teacher_assignments", "no_assessments",
                   "no_results", "not_offered", "filtered_empty"}
        seen = set()
        for cid in (C1, C2):
            seen |= set(self.detail(cid)["data_state"].values())
            for fn in (self.students, self.subjects, self.teachers, self.assessments):
                seen |= set(fn(cid)["data_state"].values())
        seen |= set(self.students(C1, q="zzzz")["data_state"].values())
        self.assertTrue(seen <= allowed, f"undeclared data state(s): {seen - allowed}")


# ══════════════════════════════════════════════════════════════════════
# SORTING, AND THE ROOT LIST
# ══════════════════════════════════════════════════════════════════════

class ClassRootListTests(_ClassBase):

    def test_the_root_list_loads_nothing_academic(self):
        """
        Pinned at source: get_classes must not touch the result, report
        card, attendance or submission tables. The class list is a
        catalogue, not a dashboard.
        """
        src = _strip_comments(API_EDU.read_text(encoding="utf-8"))
        start = src.index("case 'get_classes':")
        block = src[start:src.index("case '", start + 10)]
        for table in ("academic_records", "report_card", "attendance",
                      "grade_submissions"):
            with self.subTest(table=table):
                self.assertNotIn(table, block)

    def test_the_sort_allowlist_rejects_anything_else(self):
        got = self.call("api_education.php", "get_classes", "edu_dept",
                        sort="level")
        self.assertEqual("success", got["status"])
        baseline = [c["id"] for c in got["classes"]]

        # The decisive case: a real column name that is NOT in the map.
        # Anything that is merely invalid SQL would be "caught" by the
        # query failing, which proves nothing about the allowlist.
        raw = self.call("api_education.php", "get_classes", "edu_dept",
                        sort="student_count")
        self.assertEqual("success", raw["status"])
        self.assertEqual(baseline, [c["id"] for c in raw["classes"]],
                         "a raw column name reached ORDER BY; the sort is "
                         "interpolated rather than allowlisted")
        # Guard the guard: that column, requested by its allowlisted
        # alias, really does produce a different order -- so the
        # comparison above could have failed.
        alias = self.call("api_education.php", "get_classes", "edu_dept",
                          sort="students")
        self.assertNotEqual(baseline, [c["id"] for c in alias["classes"]],
                            "sorting by students does not change the order; "
                            "the assertion above is vacuous")

        for bad in ("id; DROP TABLE classes", "(SELECT 1)", "class_name--",
                    "students desc, id"):
            with self.subTest(sort=bad):
                res = self.call("api_education.php", "get_classes", "edu_dept",
                                sort=bad)
                self.assertEqual("success", res["status"])
                self.assertEqual(baseline, [c["id"] for c in res["classes"]],
                                 "an unknown sort changed the order")
        self.assertTrue(int(self.scalar("SELECT COUNT(*) FROM classes")) >= 2)

    def test_an_allowlisted_sort_really_sorts(self):
        """Guard the guard: the comparison above is not comparing noise."""
        by_name = self.call("api_education.php", "get_classes", "edu_dept",
                            sort="name")
        by_level = self.call("api_education.php", "get_classes", "edu_dept",
                             sort="level")
        self.assertEqual("success", by_name["status"])
        levels = [c["level_order"] for c in by_level["classes"]]
        self.assertEqual(sorted(levels), levels)


# ══════════════════════════════════════════════════════════════════════
# NO SECOND IMPLEMENTATION, NO BROWSER CALCULATION
# ══════════════════════════════════════════════════════════════════════

class ClassArchitectureTests(_ClassBase):

    def class_region(self):
        """
        The Phase 5 methods only. The earlier phases legitimately pass
        ReportCardService::PASS_MARK and the grade scale through to the
        client, which is delegation, not a second implementation; this
        phase must not even do that.
        """
        src = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        return src[src.index("function classDetail"):]

    def test_the_class_layer_does_not_reimplement_submission_status(self):
        region = self.class_region()
        self.assertIn("SubmissionService::", region,
                      "the class layer should delegate to SubmissionService")
        # It must not hand-roll the status vocabulary.
        for literal in ("'revision_needed'", "'approved'", "'rejected'",
                        "'Needs revision'", "'Approved'"):
            with self.subTest(literal=literal):
                self.assertNotIn(literal, region)

    def test_the_class_layer_does_not_reimplement_grading(self):
        region = self.class_region()
        for banned in ("PASS_MARK", "GRADE_SCALE", ">= 90", ">= 80", ">= 70",
                       ">= 60", "/ max_score", "* 100", "AVG(", "SUM("):
            with self.subTest(banned=banned):
                self.assertNotIn(banned, region)

    def test_the_class_layer_never_reads_the_result_store(self):
        """
        Whether a mark exists is asked of SubmissionService, which owns
        that question. The class layer must never query academic_records
        itself, or there would be two divergent definitions of "a result
        exists".

        Note that it DOES select assessments.max_score: that is the
        assessment's configured maximum, a property of the plan, not a
        student's mark.
        """
        region = self.class_region()
        self.assertNotIn("academic_records", region)
        self.assertNotIn("report_card", region)
        self.assertIn("SubmissionService::marklistsHaveRows", region)
        # And nothing resembling a derived academic value is returned.
        for banned in ("'score'", "'average'", "'percentage'",
                       "'grade_letter'", "'rank'", "'pass_rate'"):
            with self.subTest(banned=banned):
                self.assertNotIn(f"{banned} =>", region)

    def test_the_batched_helper_is_a_projection_not_a_second_query(self):
        """
        Phase 5 added SubmissionService::marklistsHaveRows(). The singular
        marklistHasRows() must be expressed in terms of it, so there is
        one query and one definition of "this mark list has rows".
        """
        src = _strip_comments(SUBMISSION_SERVICE.read_text(encoding="utf-8"))
        self.assertIn("function marklistsHaveRows", src)
        single = src[src.index("function marklistHasRows"):]
        single = single[:single.index("\n    public") if "\n    public" in single
                        else len(single)]
        self.assertIn("marklistsHaveRows", single,
                      "marklistHasRows no longer delegates; there are now two "
                      "implementations of the same fact")

    def test_no_academic_value_is_calculated_in_the_browser(self):
        src = _strip_comments(CONTROLLER.read_text(encoding="utf-8"))
        block = src[src.index("CLASS_TABS ="):src.index("AcademicTracking.ENTITIES =")]
        for pattern in (r"weight\s*\*", r"/\s*max\w*\s*\*\s*100",
                        r">=\s*(90|80|70|60)\b", r"\.reduce\(",
                        r"pass_mark\s*[:=]\s*\d"):
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, block),
                                  f"the class workspace calculates: {pattern}")

    def test_the_class_workspace_invents_no_metric(self):
        block = CONTROLLER.read_text(encoding="utf-8")
        block = block[block.index("CLASS_TABS ="):block.index("AcademicTracking.ENTITIES =")]
        for banned in ("performance score", "Performance Score", "ranking",
                       "Ranking", "effectiveness", "Pass rate", "Class average"):
            with self.subTest(banned=banned):
                self.assertNotIn(banned, block)

    def test_the_class_workspace_uses_no_emoji(self):
        block = CONTROLLER.read_text(encoding="utf-8")
        block = block[block.index("CLASS_TABS ="):block.index("AcademicTracking.ENTITIES =")]
        emoji = re.compile("[\U0001F300-\U0001FAFF\u2600-\u27BF]")
        found = emoji.findall(block)
        self.assertEqual([], found, f"emoji used as UI: {found}")
        self.assertIn("fa-solid", block, "no icon font is used at all")

    def test_no_parallel_api_file_was_added(self):
        for name in ("api_class_tracking.php", "api_tracking.php",
                     "api_classes.php"):
            with self.subTest(name=name):
                self.assertFalse((ROOT / "admin" / name).exists())

    def test_the_class_endpoints_live_in_the_existing_analytics_tier(self):
        src = _strip_comments(API_EDU.read_text(encoding="utf-8"))
        tier = src[src.index("$__analyticsActions = ["):]
        tier = tier[:tier.index("];")]
        for action in ClassAuthorizationTests.ACTIONS:
            with self.subTest(action=action):
                self.assertIn(f"'{action}'", tier)


# ══════════════════════════════════════════════════════════════════════
# THE FRONTEND HARNESS
# ══════════════════════════════════════════════════════════════════════

class ClassFrontendHarnessTests(unittest.TestCase):
    """Runs tests/e2e/class_tracking.js so one pytest run covers both sides."""

    def test_the_class_tracking_harness_passes(self):
        node = shutil.which("node")
        if not node:
            raise unittest.SkipTest("node not available — class tracking UI skipped")
        if not CLASS_HARNESS.is_file():
            self.fail("tests/e2e/class_tracking.js is missing")
        proc = subprocess.run(
            [node, str(CLASS_HARNESS)], capture_output=True, text=True,
            timeout=300, cwd=str(ROOT),
        )
        self.assertEqual(0, proc.returncode,
                         f"class tracking harness failed:\n{proc.stdout}\n{proc.stderr}")
        self.assertIn("PASS", proc.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
