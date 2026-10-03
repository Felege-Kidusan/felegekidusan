"""
Academic Tracking — Phase 4 Subject Tracking workflow.

Phase 2 built the student workflow and Phase 3 the teacher one. Phase 4
builds the subject workflow: pick a subject, see the classes that offer
it, pick one, and see that offering's teachers, enrolled students and
the state of its mark lists.

These tests drive the real endpoints against the live fixture database
through tests/e2e/education_config_api.php — the existing harness for
calling an admin endpoint as a session role. No second harness is added.
The frontend half lives in tests/e2e/subject_tracking.js and is executed
from here so one pytest run checks both sides.

What is being defended:

  * The offering model is the one the schema actually has. class_subjects
    carries no academic_year_id and neither does classes, so an offering
    is a standing (class x subject) arrangement and the academic year
    scopes the data hanging off it. "Not offered" means no class_subjects
    row exists — not an invented per-year flag.
  * Relationships are READ. Teachers come from teacher_assignments and
    students from class_enrollments; neither is ever derived from a mark,
    an assessment or a submitted packet. The fixture contains an offering
    with an assessment and a packet but no teacher assignment at all.
  * Both meaningful NULLs survive: subject_id IS NULL is homeroom and
    must never match a subject; academic_year_id IS NULL is a standing
    assignment and must be kept, flagged, and never rewritten.
  * users.role='teacher' is required, so the Phase 3 defect — an
    assignment row outliving the role — cannot recur here.
  * Every scoped call re-validates that the named class really offers the
    named subject, server-side.
  * No subject ranking, score or effectiveness figure exists anywhere.
  * An absent record is never a zero, and the empty states stay distinct.
  * Authorization reuses the existing tier-3 gate and canViewClass.

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
SUBJECT_HARNESS = ROOT / "tests" / "e2e" / "subject_tracking.js"
CONTROLLER = ROOT / "admin" / "js" / "academic_tracking.js"
SERVICE = ROOT / "admin" / "backend" / "services" / "AcademicTrackingService.php"
API_EDU = ROOT / "admin" / "api_education.php"
API_SUBJECTS = ROOT / "admin" / "api_subjects.php"
ENV_FILE = ROOT / ".fkss_env.php"

SYNC_DB = os.environ.get("SSMS_SYNC_DB", "ssms_e2e")

EDU_ROLES = ("super_admin", "school_admin", "edu_dept")
NON_EDU_ROLES = ("teacher", "attendance_taker", "hr_dept", "finance_dept")

# Fixture landmarks (tests/e2e/academic_intelligence.php :: seed +
# scenario_student_tracking_fixture).
S_GEEZ, S_MUSIC, S_HIST = 1, 2, 3
C1, C2 = 1, 2
T_BEKELE, T_ALMAZ, T_KEBEDE = 11, 12, 13
Y1 = 1
# Offerings: C1/GEEZ FULL_YEAR | C1/MUSIC SEMESTER_ONLY | C2/GEEZ FULL_YEAR | C2/HIST NULL
A_GEEZ_MID, A_GEEZ_FIN = 1, 2
A_MUSIC_TEST, A_MUSIC_PROJECT = 3, 7
A_HISTORY = 6
# Scratch ids, seeded and removed by the tests that need them.
SCRATCH_SUBJECT = 90
SCRATCH_USER = 4244


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


class _SubjectBase(unittest.TestCase):
    """Seeds the tracking fixture once, then calls endpoints as a role."""

    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — subject tracking e2e skipped")
        if not RUNNER.is_file() or not ROLE_RUNNER.is_file():
            raise unittest.SkipTest("e2e runners not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(".fkss_env.php not present — subject tracking e2e skipped")
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

    def detail(self, subject_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_subject_detail", role,
                         subject_id=subject_id, **kw)

    def offering(self, subject_id, class_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_subject_offering", role,
                         subject_id=subject_id, class_id=class_id, **kw)

    def students(self, subject_id, class_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_subject_students", role,
                         subject_id=subject_id, class_id=class_id, **kw)

    def classes_of(self, payload):
        return {o["class_id"] for o in payload["offerings"]}

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
# THE OFFERING MODEL
# ══════════════════════════════════════════════════════════════════════

class SubjectOfferingModelTests(_SubjectBase):
    """
    An offering is a class_subjects row. The table has no academic year,
    so the offering is a standing arrangement and the year scopes only
    the data hanging off it.
    """

    def test_a_subject_lists_exactly_its_recorded_offerings(self):
        got = self.detail(S_GEEZ)
        self.assertEqual("success", got["status"])
        self.assertEqual({C1, C2}, self.classes_of(got))

    def test_a_single_class_subject_lists_one(self):
        self.assertEqual({C1}, self.classes_of(self.detail(S_MUSIC)))
        self.assertEqual({C2}, self.classes_of(self.detail(S_HIST)))

    def test_the_offering_survives_a_year_it_has_no_data_for(self):
        """
        class_subjects has no academic_year_id. Filtering offerings by
        year would therefore be inventing a column, and would make a
        subject vanish from a year merely because nothing happened in it
        yet. The offering stays; its counts go to zero.
        """
        got = self.detail(S_GEEZ, year_id=99)
        self.assertEqual("success", got["status"])
        self.assertEqual({C1, C2}, self.classes_of(got))
        self.assertEqual("ok", got["data_state"]["offerings"])
        for o in got["offerings"]:
            with self.subTest(class_id=o["class_id"]):
                self.assertEqual(0, o["teacher_count"])
                self.assertEqual(0, o["student_count"])
                self.assertEqual(0, o["assessment_count"])

    def test_that_the_schema_really_has_no_year_on_the_offering(self):
        """
        Guards the test above. If a year column is ever added to
        class_subjects, the model changes and this layer must be
        revisited rather than silently keeping the old behaviour.
        """
        n = self.scalar(
            "SELECT COUNT(*) FROM information_schema.columns "
            "WHERE table_schema = DATABASE() AND table_name = 'class_subjects' "
            "AND column_name = 'academic_year_id'"
        )
        self.assertEqual("0", n, "class_subjects gained an academic_year_id; "
                                 "the Phase 4 offering model must be re-examined")

    def test_the_duration_is_reported_verbatim(self):
        by_class = {o["class_id"]: o["duration_type"] for o in self.detail(S_GEEZ)["offerings"]}
        self.assertEqual("FULL_YEAR", by_class[C1])
        self.assertEqual("FULL_YEAR", by_class[C2])
        self.assertEqual("SEMESTER_ONLY", self.detail(S_MUSIC)["offerings"][0]["duration_type"])

    def test_an_unclassified_duration_stays_null(self):
        """History in C2 has a NULL duration. NULL is migration 056's
        third value, not a missing one, and must not become FULL_YEAR."""
        self.assertIsNone(self.detail(S_HIST)["offerings"][0]["duration_type"])

    def test_a_subject_offered_nowhere_says_so(self):
        self.sql(
            "DELETE FROM subjects WHERE id = %d;" % SCRATCH_SUBJECT
            + "INSERT INTO subjects (id, subject_name, subject_name_en, is_active) "
              "VALUES (%d, 'ያልተሰጠ', 'Unoffered', 1);" % SCRATCH_SUBJECT
        )
        try:
            got = self.detail(SCRATCH_SUBJECT)
            self.assertEqual("success", got["status"])
            self.assertEqual([], got["offerings"])
            self.assertEqual("not_offered", got["data_state"]["offerings"])
            # It is a real subject, not an unknown one.
            self.assertEqual(SCRATCH_SUBJECT, got["subject"]["id"])
            self.assertTrue(got["subject"]["subject_name"])
        finally:
            self.sql("DELETE FROM subjects WHERE id = %d;" % SCRATCH_SUBJECT)

    def test_an_unknown_subject_is_not_a_subject_offered_nowhere(self):
        got = self.detail(999999)
        self.assertEqual("error", got["status"])
        self.assertEqual("unknown_subject", got["code"])

    def test_invalid_subject_ids_are_rejected(self):
        for bad in (0, -5):
            with self.subTest(subject_id=bad):
                self.assertEqual("invalid_subject", self.detail(bad)["code"])


# ══════════════════════════════════════════════════════════════════════
# CROSS-SCOPE VALIDATION
# ══════════════════════════════════════════════════════════════════════

class SubjectScopeValidationTests(_SubjectBase):
    """Every id arrives from the browser and none is trusted."""

    def test_a_valid_offering_is_accepted(self):
        got = self.offering(S_GEEZ, C1)
        self.assertEqual("success", got["status"])
        self.assertEqual(S_GEEZ, got["scope"]["subject_id"])
        self.assertEqual(C1, got["scope"]["class_id"])

    def test_a_class_that_does_not_offer_the_subject_is_refused(self):
        got = self.offering(S_MUSIC, C2)
        self.assertEqual("error", got["status"])
        self.assertEqual("not_offered_here", got["code"])

    def test_the_other_direction_is_refused_too(self):
        got = self.offering(S_HIST, C1)
        self.assertEqual("not_offered_here", got["code"])

    def test_students_are_refused_for_an_unrelated_offering(self):
        """
        The students endpoint must validate the pair as well. Without it,
        naming any class alongside any subject would read that class's
        roll under the selected subject's name.
        """
        got = self.students(S_MUSIC, C2)
        self.assertEqual("error", got["status"])
        self.assertEqual("not_offered_here", got["code"])

    def test_a_refusal_returns_no_data_at_all(self):
        for got in (self.offering(S_MUSIC, C2), self.students(S_MUSIC, C2)):
            with self.subTest(code=got.get("code")):
                for leaked in ("teachers", "students", "assessments", "class", "subject"):
                    self.assertNotIn(leaked, got)

    def test_a_refusal_is_not_an_empty_success(self):
        got = self.offering(S_MUSIC, C2)
        self.assertEqual("error", got["status"])

    def test_an_unknown_subject_with_a_real_class_is_refused(self):
        got = self.offering(999999, C1)
        self.assertEqual("error", got["status"])
        self.assertEqual("unknown_subject", got["code"])

    def test_missing_ids_are_rejected_before_any_query(self):
        for sid, cid, code in (
            (0, C1, "invalid_subject"),
            (S_GEEZ, 0, "invalid_class"),
        ):
            with self.subTest(subject=sid, class_id=cid):
                got = self.offering(sid, cid)
                self.assertEqual("error", got["status"])
                self.assertEqual(code, got["code"])

    def test_a_nonexistent_class_is_refused(self):
        got = self.offering(S_GEEZ, 999999)
        self.assertEqual("not_offered_here", got["code"])


# ══════════════════════════════════════════════════════════════════════
# TEACHERS — read, never inferred
# ══════════════════════════════════════════════════════════════════════

class SubjectTeacherTests(_SubjectBase):
    """Teachers come from teacher_assignments and from nowhere else."""

    def test_the_assigned_teacher_is_listed(self):
        got = self.offering(S_GEEZ, C1)
        self.assertEqual([T_BEKELE], [t["teacher_id"] for t in got["teachers"]])
        self.assertEqual("ok", got["data_state"]["teachers"])

    def test_a_different_offering_has_a_different_teacher(self):
        got = self.offering(S_MUSIC, C1)
        self.assertEqual([T_ALMAZ], [t["teacher_id"] for t in got["teachers"]])

    def test_an_offering_with_no_assignment_reports_no_teachers(self):
        """
        History in C2 has an assessment and a revision_needed packet filed
        by Bekele, who holds no History assignment. Inferring the teacher
        from the packet — an obvious shortcut — would name him here.
        """
        got = self.offering(S_HIST, C2)
        self.assertEqual("success", got["status"])
        self.assertEqual([], got["teachers"])
        self.assertEqual("no_teachers", got["data_state"]["teachers"])

    def test_that_offering_really_does_have_a_packet(self):
        """Guards the test above: if the fixture loses that packet, the
        negative assertion silently stops proving anything."""
        n = self.scalar(
            "SELECT COUNT(*) FROM grade_submissions "
            "WHERE teacher_id = %d AND class_id = %d AND subject_id = %d"
            % (T_BEKELE, C2, S_HIST)
        )
        self.assertEqual("1", n, "fixture no longer contains the unassigned-submission case")

    def test_and_really_does_have_an_assessment(self):
        got = self.offering(S_HIST, C2)
        self.assertTrue(got["assessments"], "the offering should still have work on it")
        self.assertEqual("ok", got["data_state"]["assessments"])

    def test_a_homeroom_assignment_is_not_a_subject_assignment(self):
        """
        teacher_assignments.subject_id IS NULL is homeroom: an assignment
        to the CLASS. It must never make that teacher the teacher of
        every subject the class offers.
        """
        self.sql(
            "DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE
            + "INSERT INTO teacher_assignments "
              "(teacher_id, class_id, subject_id, academic_year_id, is_active, "
              " is_class_teacher, assignment_role) "
              "VALUES (%d, %d, NULL, %d, 1, 1, 'homeroom');" % (T_KEBEDE, C2, Y1)
        )
        try:
            got = self.offering(S_HIST, C2)
            self.assertEqual([], got["teachers"],
                             "a homeroom assignment became a subject assignment")
            self.assertEqual("no_teachers", got["data_state"]["teachers"])

            geez = self.offering(S_GEEZ, C2)
            self.assertNotIn(T_KEBEDE, [t["teacher_id"] for t in geez["teachers"]])
        finally:
            self.sql("DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE)

    def test_a_homeroom_assignment_is_not_counted_either(self):
        """The batched count must apply the same rule as the detail list."""
        self.sql(
            "DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE
            + "INSERT INTO teacher_assignments "
              "(teacher_id, class_id, subject_id, academic_year_id, is_active, "
              " is_class_teacher, assignment_role) "
              "VALUES (%d, %d, NULL, %d, 1, 1, 'homeroom');" % (T_KEBEDE, C2, Y1)
        )
        try:
            got = self.detail(S_HIST)
            self.assertEqual(0, got["offerings"][0]["teacher_count"])
        finally:
            self.sql("DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE)

    def test_a_standing_assignment_is_kept_and_flagged(self):
        """
        academic_year_id IS NULL is a standing assignment. It must be kept
        when a year is in scope, and reported as standing rather than
        presented as a this-year assignment.
        """
        self.sql(
            "DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE
            + "INSERT INTO teacher_assignments "
              "(teacher_id, class_id, subject_id, academic_year_id, is_active, assignment_role) "
              "VALUES (%d, %d, %d, NULL, 1, 'primary');" % (T_KEBEDE, C2, S_HIST)
        )
        try:
            got = self.offering(S_HIST, C2, year_id=Y1)
            ids = [t["teacher_id"] for t in got["teachers"]]
            self.assertIn(T_KEBEDE, ids, "a standing assignment was discarded")
            row = [t for t in got["teachers"] if t["teacher_id"] == T_KEBEDE][0]
            self.assertTrue(row["is_standing"])
        finally:
            self.sql("DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE)

    def test_a_standing_assignment_survives_any_year(self):
        self.sql(
            "DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE
            + "INSERT INTO teacher_assignments "
              "(teacher_id, class_id, subject_id, academic_year_id, is_active, assignment_role) "
              "VALUES (%d, %d, %d, NULL, 1, 'primary');" % (T_KEBEDE, C2, S_HIST)
        )
        try:
            got = self.offering(S_HIST, C2, year_id=99)
            self.assertIn(T_KEBEDE, [t["teacher_id"] for t in got["teachers"]])
        finally:
            self.sql("DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE)

    def test_a_year_scoped_assignment_does_not_leak_into_another_year(self):
        got = self.offering(S_GEEZ, C1, year_id=99)
        self.assertEqual([], got["teachers"])
        self.assertEqual("no_teachers", got["data_state"]["teachers"])

    def test_an_inactive_assignment_is_excluded(self):
        self.sql(
            "DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE
            + "INSERT INTO teacher_assignments "
              "(teacher_id, class_id, subject_id, academic_year_id, is_active) "
              "VALUES (%d, %d, %d, %d, 0);" % (T_KEBEDE, C2, S_HIST, Y1)
        )
        try:
            self.assertEqual([], self.offering(S_HIST, C2)["teachers"])
        finally:
            self.sql("DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE)

    def test_a_non_teacher_with_an_assignment_row_is_excluded(self):
        """
        The Phase 3 defect class: an assignment row can outlive the role
        it was granted for. Possession of the row is not proof of being a
        teacher, so users.role is checked as well.
        """
        self.sql(
            "DELETE FROM users WHERE id = %d;" % SCRATCH_USER
            + "INSERT INTO users (id, username, full_name, email, role, is_active) "
              "VALUES (%d, 'notateacher4', 'Not A Teacher', 'n4@example.org', "
              "'finance_dept', 1);" % SCRATCH_USER
            + "INSERT INTO teacher_assignments "
              "(teacher_id, class_id, subject_id, academic_year_id, is_active) "
              "VALUES (%d, %d, %d, %d, 1);" % (SCRATCH_USER, C2, S_HIST, Y1)
        )
        try:
            got = self.offering(S_HIST, C2)
            self.assertNotIn(SCRATCH_USER, [t["teacher_id"] for t in got["teachers"]])
            self.assertEqual("no_teachers", got["data_state"]["teachers"])
            # and the batched count must agree
            self.assertEqual(0, self.detail(S_HIST)["offerings"][0]["teacher_count"])
        finally:
            self.sql(
                "DELETE FROM teacher_assignments WHERE teacher_id = %d;" % SCRATCH_USER
                + "DELETE FROM users WHERE id = %d;" % SCRATCH_USER
            )

    def test_multiple_teachers_on_one_offering_are_all_returned(self):
        """
        The schema permits more than one assignment per class+subject.
        Collapsing them to one would silently pick a winner.
        """
        self.sql(
            "DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE
            + "INSERT INTO teacher_assignments "
              "(teacher_id, class_id, subject_id, academic_year_id, is_active, assignment_role) "
              "VALUES (%d, %d, %d, %d, 1, 'assistant');" % (T_KEBEDE, C1, S_GEEZ, Y1)
        )
        try:
            got = self.offering(S_GEEZ, C1)
            ids = sorted(t["teacher_id"] for t in got["teachers"])
            self.assertEqual([T_BEKELE, T_KEBEDE], ids)
            self.assertEqual(2, self.detail(S_GEEZ)["offerings"][0]["teacher_count"])
        finally:
            self.sql("DELETE FROM teacher_assignments WHERE teacher_id = %d;" % T_KEBEDE)

    def test_the_assignment_role_is_reported(self):
        row = self.offering(S_GEEZ, C1)["teachers"][0]
        self.assertEqual("primary", row["assignment_role"])
        self.assertIn("is_primary", row)

    def test_no_teacher_metric_is_returned(self):
        for t in self.offering(S_GEEZ, C1)["teachers"]:
            for banned in ("effectiveness", "score", "rating", "average",
                           "performance", "rank"):
                self.assertNotIn(banned, t)


# ══════════════════════════════════════════════════════════════════════
# STUDENTS — enrolment, never inference
# ══════════════════════════════════════════════════════════════════════

class SubjectStudentTests(_SubjectBase):
    """A student takes a subject because they are enrolled in a class
    that offers it."""

    def test_the_enrolled_students_are_listed(self):
        got = self.students(S_GEEZ, C1)
        self.assertEqual("success", got["status"])
        ids = {s["member_id"] for s in got["students"]}
        self.assertTrue({101, 102, 103, 104} <= ids)

    def test_the_other_class_has_its_own_roll(self):
        got = self.students(S_GEEZ, C2)
        ids = {s["member_id"] for s in got["students"]}
        self.assertEqual({201, 202, 203}, ids)
        self.assertNotIn(101, ids, "students were mixed across offerings")

    def test_the_two_offerings_of_one_subject_do_not_merge(self):
        c1 = {s["member_id"] for s in self.students(S_GEEZ, C1)["students"]}
        c2 = {s["member_id"] for s in self.students(S_GEEZ, C2)["students"]}
        self.assertEqual(set(), c1 & c2)

    def test_a_student_with_marks_but_no_enrolment_is_not_listed(self):
        """
        Membership is not inferred. A mark can outlive an enrolment, and
        a mark list can contain somebody who has since been moved.
        """
        self.sql(
            "DELETE FROM class_enrollments WHERE member_id = 101 AND class_id = %d;" % C1
        )
        try:
            got = self.students(S_GEEZ, C1)
            ids = {s["member_id"] for s in got["students"]}
            self.assertNotIn(101, ids,
                             "a student was listed from marks rather than enrolment")
        finally:
            self.sql(
                "INSERT INTO class_enrollments (member_id, class_id, academic_year_id, status) "
                "VALUES (101, %d, %d, 'active');" % (C1, Y1)
            )

    def test_that_student_really_does_have_marks(self):
        """Guards the test above."""
        n = self.scalar(
            "SELECT COUNT(*) FROM academic_records WHERE member_id = 101 AND class_id = %d" % C1
        )
        self.assertNotEqual("0", n, "fixture no longer gives student 101 marks in C1")

    def test_a_withdrawn_student_is_excluded(self):
        self.sql(
            "UPDATE class_enrollments SET status = 'withdrawn' "
            "WHERE member_id = 102 AND class_id = %d;" % C1
        )
        try:
            ids = {s["member_id"] for s in self.students(S_GEEZ, C1)["students"]}
            self.assertNotIn(102, ids)
        finally:
            self.sql(
                "UPDATE class_enrollments SET status = 'active' "
                "WHERE member_id = 102 AND class_id = %d;" % C1
            )

    def test_the_year_scopes_the_roll(self):
        got = self.students(S_GEEZ, C1, year_id=99)
        self.assertEqual([], got["students"])
        self.assertEqual(0, got["total"])
        self.assertEqual("no_students", got["data_state"]["students"])

    def test_an_empty_roll_is_not_an_error(self):
        got = self.students(S_GEEZ, C1, year_id=99)
        self.assertEqual("success", got["status"])
        self.assertNotIn("code", got)

    def test_the_total_is_truthful(self):
        got = self.students(S_GEEZ, C2)
        self.assertEqual(3, got["total"])
        self.assertEqual(3, len(got["students"]))

    def test_per_page_is_clamped_not_trusted(self):
        """Same bounds as the Phase 1 catalogue: 10 <= per_page <= 100."""
        wide = self.students(S_GEEZ, C1, page=1, per_page=100000)
        self.assertEqual(100, wide["per_page"])
        narrow = self.students(S_GEEZ, C1, page=1, per_page=1)
        self.assertEqual(10, narrow["per_page"])
        negative = self.students(S_GEEZ, C1, page=1, per_page=-5)
        self.assertEqual(10, negative["per_page"])

    def test_a_page_below_one_is_clamped(self):
        got = self.students(S_GEEZ, C1, page=-3)
        self.assertEqual(1, got["page"])

    def test_pagination_actually_pages(self):
        """Seeded above the minimum page size so real paging is exercised
        rather than inferred from a single-page class."""
        extra = list(range(9101, 9116))      # 15 additional students
        values = ",".join(
            "(%d, 'P-%d', 'Pager%d', 'Father', 'male', 'active')" % (m, m, m)
            for m in extra
        )
        enrol = ",".join(
            "(%d, %d, %d, 'active')" % (m, C1, Y1) for m in extra
        )
        self.sql(
            "DELETE FROM class_enrollments WHERE member_id >= 9101 AND member_id <= 9115;"
            + "DELETE FROM members WHERE id >= 9101 AND id <= 9115;"
            + "INSERT INTO members (id, member_code, student_name, father_name, gender, status) "
              "VALUES " + values + ";"
            + "INSERT INTO class_enrollments (member_id, class_id, academic_year_id, status) "
              "VALUES " + enrol + ";"
        )
        try:
            first = self.students(S_GEEZ, C1, page=1, per_page=10)
            self.assertEqual(20, first["total"])      # 5 fixture + 15 seeded
            self.assertEqual(10, len(first["students"]))
            self.assertEqual(2, first["pages"])

            second = self.students(S_GEEZ, C1, page=2, per_page=10)
            self.assertEqual(2, second["page"])
            self.assertEqual(10, len(second["students"]))

            # The two pages must not overlap, or rows are being skipped
            # or repeated at the boundary.
            a = [s["member_id"] for s in first["students"]]
            b = [s["member_id"] for s in second["students"]]
            self.assertEqual(set(), set(a) & set(b))
            self.assertEqual(20, len(set(a) | set(b)))
        finally:
            self.sql(
                "DELETE FROM class_enrollments WHERE member_id >= 9101 AND member_id <= 9115;"
                + "DELETE FROM members WHERE id >= 9101 AND id <= 9115;"
            )

    def test_a_page_past_the_end_is_not_an_empty_class(self):
        """total drives the state, so an over-scrolled page does not
        read as 'nobody is enrolled'."""
        got = self.students(S_GEEZ, C1, page=999)
        self.assertEqual([], got["students"])
        self.assertGreater(got["total"], 0)
        self.assertEqual("ok", got["data_state"]["students"])

    def test_no_academic_value_is_attached_to_a_student(self):
        for s in self.students(S_GEEZ, C1)["students"]:
            for banned in ("average", "grade", "score", "percentage",
                           "final_percentage", "rank"):
                self.assertNotIn(banned, s)


# ══════════════════════════════════════════════════════════════════════
# ASSESSMENTS AND WORKFLOW
# ══════════════════════════════════════════════════════════════════════

class SubjectAssessmentTests(_SubjectBase):
    """Status is retrieved from SubmissionService, never decided here."""

    def test_the_offerings_assessments_are_listed(self):
        got = self.offering(S_GEEZ, C1)
        ids = {a["assessment_id"] for a in got["assessments"]}
        self.assertEqual({A_GEEZ_MID, A_GEEZ_FIN}, ids)

    def test_assessments_are_scoped_to_the_class(self):
        """C2's Geez assessments must not appear under C1's offering."""
        c1 = {a["assessment_id"] for a in self.offering(S_GEEZ, C1)["assessments"]}
        c2 = {a["assessment_id"] for a in self.offering(S_GEEZ, C2)["assessments"]}
        self.assertEqual(set(), c1 & c2)
        self.assertEqual({4, 5}, c2)

    def test_assessments_are_scoped_to_the_subject(self):
        """C1 offers both Geez and Music; their assessments must not mix."""
        geez = {a["assessment_id"] for a in self.offering(S_GEEZ, C1)["assessments"]}
        music = {a["assessment_id"] for a in self.offering(S_MUSIC, C1)["assessments"]}
        self.assertEqual(set(), geez & music)
        self.assertEqual({A_MUSIC_TEST, A_MUSIC_PROJECT}, music)

    def test_the_workflow_status_comes_through(self):
        rows = {a["assessment_id"]: a for a in self.offering(S_GEEZ, C1)["assessments"]}
        self.assertEqual("approved", rows[A_GEEZ_MID]["workflow_status"])
        self.assertEqual("Approved", rows[A_GEEZ_MID]["workflow_label"])
        self.assertEqual("submitted", rows[A_GEEZ_FIN]["workflow_status"])
        self.assertEqual("Complete", rows[A_GEEZ_FIN]["workflow_label"])

    def test_a_never_started_marklist_is_a_real_answer(self):
        rows = {a["assessment_id"]: a for a in self.offering(S_MUSIC, C1)["assessments"]}
        self.assertIsNone(rows[A_MUSIC_PROJECT]["workflow_status"])
        self.assertEqual("Not started", rows[A_MUSIC_PROJECT]["workflow_label"])

    def test_no_row_carries_a_blank_label(self):
        for sid, cid in ((S_GEEZ, C1), (S_GEEZ, C2), (S_MUSIC, C1), (S_HIST, C2)):
            for row in self.offering(sid, cid)["assessments"]:
                with self.subTest(subject=sid, class_id=cid, a=row["assessment_id"]):
                    self.assertTrue(row["workflow_label"].strip())

    def test_only_the_submission_services_own_statuses_appear(self):
        allowed = {"incomplete", "submitted", "approved", "rejected",
                   "revision_needed", "draft", None}
        for sid, cid in ((S_GEEZ, C1), (S_GEEZ, C2), (S_MUSIC, C1), (S_HIST, C2)):
            for row in self.offering(sid, cid)["assessments"]:
                with self.subTest(a=row["assessment_id"]):
                    self.assertIn(row["workflow_status"], allowed)

    def test_a_packet_id_is_returned_for_the_existing_screen(self):
        rows = {a["assessment_id"]: a for a in self.offering(S_GEEZ, C1)["assessments"]}
        self.assertIsInstance(rows[A_GEEZ_MID]["submission_id"], int)
        self.assertGreater(rows[A_GEEZ_MID]["submission_id"], 0)

    def test_the_packet_id_addresses_the_right_row(self):
        rows = {a["assessment_id"]: a for a in self.offering(S_GEEZ, C1)["assessments"]}
        pid = rows[A_GEEZ_MID]["submission_id"]
        aid = self.scalar("SELECT assessment_id FROM grade_submissions WHERE id = %d" % pid)
        self.assertEqual(str(A_GEEZ_MID), aid)

    def test_an_assessment_with_no_packet_gets_no_invented_id(self):
        rows = {a["assessment_id"]: a for a in self.offering(S_MUSIC, C1)["assessments"]}
        row = rows[A_MUSIC_TEST]
        self.assertIsNotNone(row["workflow_status"])
        self.assertIsNone(row["submission_id"])

    def test_the_term_narrows_the_assessments(self):
        both = self.offering(S_GEEZ, C1)
        term1 = self.offering(S_GEEZ, C1, term_id=1)
        self.assertEqual(2, len(both["assessments"]))
        self.assertEqual(1, len(term1["assessments"]))
        self.assertEqual(A_GEEZ_MID, term1["assessments"][0]["assessment_id"])

    def test_an_offering_with_no_assessments_says_so(self):
        got = self.offering(S_GEEZ, C1, term_id=99)
        self.assertEqual("success", got["status"])
        self.assertEqual([], got["assessments"])
        self.assertEqual("no_assessments", got["data_state"]["assessments"])

    def test_no_academic_value_is_returned_for_an_assessment(self):
        for row in self.offering(S_GEEZ, C1)["assessments"]:
            with self.subTest(a=row["assessment_id"]):
                for banned in ("score", "percentage", "average", "grade",
                               "grade_letter", "final_percentage", "pass_mark"):
                    self.assertNotIn(banned, row)

    def test_the_tracking_layer_does_not_reimplement_status_precedence(self):
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        part = code[code.index("function offeringAssessments"):]
        self.assertNotIn("grade_submissions", part,
                         "the subject layer is querying the workflow table directly")
        self.assertIn("SubmissionService::resolvedMarklistRefs", part)

    def test_an_absent_weight_stays_null(self):
        weights = [a["weight"] for a in self.offering(S_GEEZ, C1)["assessments"]]
        self.assertIn(None, weights)
        self.assertNotIn(0, weights)
        self.assertNotIn(0.0, weights)


# ══════════════════════════════════════════════════════════════════════
# AUTHORIZATION
# ══════════════════════════════════════════════════════════════════════

class SubjectTrackingAuthorizationTests(_SubjectBase):
    """The Phase 4 actions join the existing tier-3 gate. Nothing new."""

    def test_education_roles_are_allowed(self):
        for role in EDU_ROLES:
            with self.subTest(role=role):
                self.assertEqual("success", self.detail(S_GEEZ, role=role)["status"])

    def test_everyone_else_is_refused_every_action(self):
        for role in NON_EDU_ROLES:
            for got in (self.detail(S_GEEZ, role=role),
                        self.offering(S_GEEZ, C1, role=role),
                        self.students(S_GEEZ, C1, role=role)):
                with self.subTest(role=role):
                    self.assertEqual("error", got["status"])

    def test_the_refusal_names_the_education_department(self):
        self.assertIn("Education", self.detail(S_GEEZ, role="finance_dept")["message"])

    def test_all_three_actions_are_inside_the_existing_tier(self):
        src = API_EDU.read_text(encoding="utf-8")
        block = src.split("$__analyticsActions")[1].split("];")[0]
        for a in ("tracking_subject_detail", "tracking_subject_offering",
                  "tracking_subject_students"):
            with self.subTest(action=a):
                self.assertIn(a, block)

    def test_no_second_permission_system_was_added(self):
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        for invented in ("function canTrackSubject", "function hasSubjectAccess",
                         "SUBJECT_PERMISSIONS", "function checkSubjectRole"):
            self.assertNotIn(invented, code)

    def test_the_service_runs_no_authorization_of_its_own(self):
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        part = code[code.index("function subjectDetail"):]
        for role in ("super_admin", "school_admin", "edu_dept", "$_SESSION"):
            self.assertNotIn(role, part)

    def test_class_visibility_is_enforced_on_the_subject_detail(self):
        """
        tracking_subject_detail takes no class_id, so the endpoint's class
        gate cannot run for it. The offering list must be filtered by
        canViewClass instead, or a hidden class leaks through a subject.
        """
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_subject_detail':")[1].split("case 'get_academic_intelligence':")[0]
        self.assertGreaterEqual(case.count("canViewClass"), 2)

    def test_the_visibility_filter_is_actually_applied(self):
        """
        Pins the filter in place. It cannot be observed from outside:
        canViewClass() returns true unconditionally for the only three
        roles that reach these endpoints, so removing it changes no
        response today. It would matter the day the tier is widened,
        which is exactly when nobody would remember to re-add it. Same
        limitation Phases 2 and 3 recorded.
        """
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_subject_detail':")[1].split("case 'get_academic_intelligence':")[0]
        self.assertIn("array_filter", case)
        self.assertIn("$tsVisible[(int)$r['class_id']]", case)
        self.assertIn("$tsRes['data_state']['offerings'] = $tsRes['offerings']", case)

    def test_the_visibility_check_runs_after_the_data_is_fetched(self):
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_subject_detail':")[1].split("case 'get_academic_intelligence':")[0]
        self.assertGreater(case.index("array_filter"),
                           case.index("AcademicTrackingService::subjectDetail"))

    def test_the_class_id_is_validated_at_both_layers(self):
        """Defence in depth; removing either leaves behaviour unchanged,
        so both are pinned in a form the mutation breaks."""
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_subject_detail':")[1].split("case 'get_academic_intelligence':")[0]
        self.assertIn("if ($action !== 'tracking_subject_detail' && $tsClass <= 0) {", case)
        svc = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        part = svc[svc.index("function findOffering"):]
        self.assertIn("if ($classId <= 0) {", part)
        self.assertIn("if ($subjectId <= 0) {", part)

    def test_the_endpoint_executes_no_sql_of_its_own(self):
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_subject_detail':")[1].split("case 'get_academic_intelligence':")[0]
        code = _strip_comments(case)
        for sql in ("$conn->query(", "$conn->prepare("):
            self.assertNotIn(sql, code)


# ══════════════════════════════════════════════════════════════════════
# CONTRACT, STATES AND SCOPE HYGIENE
# ══════════════════════════════════════════════════════════════════════

class SubjectTrackingContractTests(_SubjectBase):
    """Scope, context and filters stay separate; the contract is additive."""

    def test_the_detail_reports_its_scope(self):
        got = self.detail(S_GEEZ)
        self.assertEqual("subject", got["scope"]["type"])
        self.assertEqual(S_GEEZ, got["scope"]["subject_id"])

    def test_the_offering_reports_the_full_scope(self):
        got = self.offering(S_GEEZ, C1)
        self.assertEqual({"type", "subject_id", "class_id"}, set(got["scope"].keys()))

    def test_context_is_separate_from_scope(self):
        got = self.offering(S_GEEZ, C1)
        self.assertIn("year_id", got["context"])
        self.assertNotIn("subject_id", got["context"])
        self.assertNotIn("class_id", got["context"])

    def test_scope_ids_are_not_returned_as_filters(self):
        for got in (self.detail(S_GEEZ), self.offering(S_GEEZ, C1),
                    self.students(S_GEEZ, C1)):
            self.assertNotIn("filters", got)

    def test_each_empty_state_is_distinct(self):
        """not_offered / no_teachers / no_students / no_assessments must
        never be interchangeable."""
        self.sql(
            "DELETE FROM subjects WHERE id = %d;" % SCRATCH_SUBJECT
            + "INSERT INTO subjects (id, subject_name, is_active) "
              "VALUES (%d, 'Scratch', 1);" % SCRATCH_SUBJECT
        )
        try:
            self.assertEqual("not_offered",
                             self.detail(SCRATCH_SUBJECT)["data_state"]["offerings"])
            self.assertEqual("no_teachers",
                             self.offering(S_HIST, C2)["data_state"]["teachers"])
            self.assertEqual("no_assessments",
                             self.offering(S_GEEZ, C1, term_id=99)["data_state"]["assessments"])
            self.assertEqual("no_students",
                             self.students(S_GEEZ, C1, year_id=99)["data_state"]["students"])
        finally:
            self.sql("DELETE FROM subjects WHERE id = %d;" % SCRATCH_SUBJECT)

    def test_an_offering_with_a_teacher_is_not_reported_empty(self):
        self.assertEqual("ok", self.offering(S_GEEZ, C1)["data_state"]["teachers"])

    def test_no_subject_level_metric_is_returned(self):
        flat = json.dumps(self.detail(S_GEEZ)).lower()
        for banned in ("effectiveness", "subject_score", "ranking", "rank",
                       "leaderboard", "strongest", "weakest", "pass_rate",
                       "overall_average", "performance_score"):
            self.assertNotIn(banned, flat)

    def test_no_new_api_file_was_created(self):
        for invented in ("api_subject_tracking.php", "api_tracking.php",
                         "api_academic_tracking.php"):
            self.assertFalse((ROOT / "admin" / invented).exists())

    def test_the_subject_catalogue_was_not_modified(self):
        """The Phase 1 root list is reused as-is. Its allowlisted sort and
        validated pagination must still be there."""
        src = API_SUBJECTS.read_text(encoding="utf-8")
        self.assertIn("$subOrderMap", src)
        self.assertIn("'classes' => 'assigned_classes'", src)
        self.assertIn("min(100, max(10,", src)

    def test_the_earlier_phases_actions_are_untouched(self):
        src = API_EDU.read_text(encoding="utf-8")
        for kept in ("case 'tracking_student_detail':", "case 'tracking_student_assessments':",
                     "case 'tracking_teacher_detail':", "case 'tracking_teacher_assessments':"):
            with self.subTest(action=kept):
                self.assertIn(kept, src)

    def test_no_migration_was_added_for_phase_4(self):
        for f in (ROOT / "sql").glob("*.sql"):
            text = f.read_text(encoding="utf-8", errors="ignore").lower()
            self.assertNotIn("phase 4 subject tracking", text)
            self.assertNotIn("academic_tracking_subject", text)

    def test_the_offering_table_is_the_one_the_engine_uses(self):
        """Confirms Phase 4 reads the same table ReportCardService does.
        Two sources of truth for 'does this class offer this subject'
        would be a divergence."""
        rc = (ROOT / "admin" / "backend" / "services" / "ReportCardService.php").read_text(encoding="utf-8")
        self.assertIn("class_subjects", rc)
        self.assertIn("class_subjects", SERVICE.read_text(encoding="utf-8"))

    def test_the_subject_layer_never_calls_the_calculation_engine(self):
        """A subject has no academic result of its own in this phase."""
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        part = code[code.index("function subjectDetail"):]
        self.assertNotIn("ReportCardService", part)
        self.assertNotIn("getCard", part)

    def test_the_subject_layer_contains_no_academic_formula(self):
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        part = code[code.index("function subjectDetail"):]
        for formula in (r"weight\s*\*", r"\*\s*weight", r"/\s*max\s*\*\s*100",
                        r">=\s*(?:90|80|70|60)", r"array_sum\s*\(",
                        r"pass_mark\s*[:=]\s*\d"):
            with self.subTest(formula=formula):
                self.assertIsNone(re.search(formula, part),
                                  f"a calculation appeared in the subject layer: {formula}")


# ══════════════════════════════════════════════════════════════════════
# THE CONTROLLER
# ══════════════════════════════════════════════════════════════════════

class SubjectTrackingControllerTests(unittest.TestCase):
    """Source-level prohibitions, plus the behavioural harness."""

    def setUp(self):
        if not CONTROLLER.is_file():
            self.skipTest("academic_tracking.js is missing")
        self.src = CONTROLLER.read_text(encoding="utf-8")
        self.code = _strip_comments(self.src)

    def test_the_subject_screen_performs_no_academic_calculation(self):
        start = self.code.find("prototype.loadSubjectDetail")
        self.assertGreater(start, 0, "the subject workflow is missing")
        part = self.code[start:]
        for formula in (r">=\s*(?:90|80|70|60)", r"\*\s*weight", r"weight\s*\*",
                        r"weight\s*/", r"\.reduce\(", r"pass_mark\s*[:=]\s*\d",
                        r"final_percentage\s*="):
            with self.subTest(formula=formula):
                self.assertIsNone(re.search(formula, part),
                                  f"the browser is calculating: {formula}")

    def test_the_subject_screen_never_auto_selects(self):
        start = self.code.find("prototype.renderOfferingsBody")
        part = self.code[start:]
        for auto in (r"offerings\[0\]", r"rows\[0\]", r"\.first\(\)",
                     r"selectSubjectOffering\(\s*(?:rows|offerings)"):
            with self.subTest(pattern=auto):
                self.assertIsNone(re.search(auto, part),
                                  f"an offering is being auto-selected: {auto}")

    def test_no_emoji_is_used_as_an_icon(self):
        emoji = re.compile("[\U0001F300-\U0001FAFF\u2600-\u27BF\u2B00-\u2BFF\uFE0F]")
        start = self.src.find("PHASE 4 — SUBJECT TRACKING")
        self.assertGreater(start, 0)
        found = emoji.findall(self.src[start:])
        self.assertEqual([], found, f"emoji used in the UI: {found}")

    def test_professional_icons_are_used(self):
        start = self.src.find("prototype.renderSubjectHeader")
        part = self.src[start:start + 4000]
        self.assertIn("fa-book-open", part)

    def test_the_screen_does_not_implement_the_review_workflow(self):
        start = self.code.find("prototype.renderOfferingAssessments")
        part = self.code[start:]
        for action in ("review_submission", "approve_submission",
                       "reject_submission", "submit_marklist"):
            self.assertNotIn(action, part)

    def test_it_opens_the_existing_review_modal(self):
        self.assertIn("openReviewModal", self.code)

    def test_the_offering_is_not_stored_in_the_filters_bag(self):
        start = self.code.find("prototype.selectSubjectOffering")
        part = self.code[start:self.code.find("prototype.clearSubjectOffering")]
        self.assertNotIn("filters", part)

    def test_the_race_guard_is_present_on_all_three_requests(self):
        for fn, seq in (("loadSubjectDetail", "_subjectSeq"),
                        ("loadSubjectOffering", "_subjOfferingSeq"),
                        ("loadSubjectStudents", "_subjStudentSeq")):
            with self.subTest(fn=fn):
                start = self.code.find("prototype." + fn)
                part = self.code[start:start + 3200]
                self.assertIn("++this." + seq, part)
                self.assertIn("!== self." + seq, part)

    def test_the_guards_also_check_the_entity_not_just_the_sequence(self):
        """
        The sequence number alone happens to suffice today because every
        load increments it. The identity checks keep that true if a future
        caller ever reloads without a new sequence, and they cost nothing.
        Behaviourally redundant, which is why a mutation removing them
        survives the harness; this is the pin that replaces that coverage.
        """
        for fn in ("loadSubjectOffering", "loadSubjectStudents"):
            with self.subTest(fn=fn):
                start = self.code.find("prototype." + fn)
                part = self.code[start:start + 3200]
                self.assertIn("self.subjectOffering.class_id === want", part)
        start = self.code.find("prototype.loadSubjectDetail")
        self.assertIn("self.scope.id !== subjectId", self.code[start:start + 3200])

    def test_students_are_lazy(self):
        """They must not be fetched when an offering is opened."""
        start = self.code.find("prototype.selectSubjectOffering")
        part = self.code[start:self.code.find("prototype.clearSubjectOffering")]
        self.assertNotIn("loadSubjectStudents", part)
        tab = self.code[self.code.find("prototype.openSubjectTab"):]
        self.assertIn("loadSubjectStudents", tab[:800])

    def test_the_behavioural_harness_passes(self):
        node = shutil.which("node")
        if not node:
            self.skipTest("node not available")
        if not SUBJECT_HARNESS.is_file():
            self.fail("tests/e2e/subject_tracking.js is missing")
        proc = subprocess.run(
            [node, str(SUBJECT_HARNESS)], capture_output=True, text=True,
            timeout=300, cwd=str(ROOT),
        )
        self.assertEqual(
            0, proc.returncode,
            f"subject tracking controller harness failed:\n{proc.stdout}\n{proc.stderr}",
        )
        self.assertIn("0 failed", proc.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
