"""
Academic Tracking — Phase 2 Student Tracking workflow.

Phase 1 built the four root lists and stopped at an explicit selection.
Phase 2 builds the first real detail workflow: pick a student, then read
their overview, subjects, assessments, attendance and report card.

These tests drive the real endpoints against the live fixture database
through tests/e2e/education_config_api.php — the existing harness for
calling an admin endpoint as a session role. No second harness is added.
The frontend half lives in tests/e2e/student_tracking.js and is executed
from here so one pytest run checks both sides.

What is being defended:

  * Nothing in the tracking layer calculates anything. Every academic
    number it emits is byte-identical to what ReportCardService returns
    for the same student (CalculationEquivalenceTests — the mandatory
    §35 check).
  * The semester/full-year duration policy survives the trip: a
    SEMESTER_ONLY subject is never presented as a full-year one, and a
    full-year subject still pending is never counted as zero.
  * Assessment workflow status and the student's own result are separate
    facts. An approved mark list with no score for this student shows the
    status and withholds the score.
  * An absent record is never a zero. "No attendance recorded" and "0%
    attendance" are different answers, and the payload can tell them
    apart.
  * An API error never degrades into empty data.
  * Authorization is server-side and unchanged: the Phase 2 actions join
    the existing tier-3 analytics gate, reuse canViewClass, and grant
    nobody anything new. Changing member_id in the query string does not
    reach another class's data.
  * No parallel API file, no second permission system, no new chart
    library, no emoji.

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
STUDENT_HARNESS = ROOT / "tests" / "e2e" / "student_tracking.js"
CONTROLLER = ROOT / "admin" / "js" / "academic_tracking.js"
SERVICE = ROOT / "admin" / "backend" / "services" / "AcademicTrackingService.php"
SUBMISSION = ROOT / "admin" / "backend" / "services" / "SubmissionService.php"
API_EDU = ROOT / "admin" / "api_education.php"
ENV_FILE = ROOT / ".fkss_env.php"

SYNC_DB = os.environ.get("SSMS_SYNC_DB", "ssms_e2e")

EDU_ROLES = ("super_admin", "school_admin", "edu_dept")
# Roles that may reach api_education.php but must not reach tier 3.
NON_EDU_ROLES = ("teacher", "attendance_taker", "hr_dept", "finance_dept")

# Fixture landmarks (tests/e2e/academic_intelligence.php :: seed +
# scenario_student_tracking_fixture).
S_FULL = 101        # both durations, perfect attendance, every mark present
S_MID = 102         # marks across the board, partial attendance
S_NO_ATTENDANCE = 103   # marks, but not one attendance row
S_PENDING = 104     # full-year Geez has no semester-2 mark -> PENDING
S_BLANK = 105       # enrolled in C1, nothing recorded at all
S_NO_CLASS = 150    # a real member in no class
S_C2 = 201          # a student in the other class
C1, C2 = 1, 2


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


class _TrackingBase(unittest.TestCase):
    """Seeds the student-tracking fixture once, then calls endpoints as a role."""

    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — student tracking e2e skipped")
        if not RUNNER.is_file() or not ROLE_RUNNER.is_file():
            raise unittest.SkipTest("e2e runners not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(".fkss_env.php not present — student tracking e2e skipped")
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
                f"could not seed student tracking fixture:\n{proc.stdout}\n{proc.stderr}"
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

    def detail(self, member_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_student_detail", role,
                         member_id=member_id, **kw)

    def assessments(self, member_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_student_assessments", role,
                         member_id=member_id, **kw)

    def report_card(self, member_id, role="edu_dept", **kw):
        return self.call("api_communication.php", "get_report_card", role,
                         member_id=member_id, **kw)

    def engine_card(self, member_id, class_id=0, year_id=0, term_id=0):
        """ReportCardService::getCard() called directly — the authority."""
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$c->set_charset('utf8mb4');"
            "require_once %s;"
            "echo json_encode(\\App\\Services\\ReportCardService::getCard($c, %d, %d, %d, %d));"
            % (
                repr(str(ENV_FILE)), repr(SYNC_DB),
                repr(str(ROOT / "admin" / "backend" / "services" / "ReportCardService.php")),
                member_id, class_id, year_id, term_id,
            )
        )
        proc = subprocess.run(
            [self.php, "-r", script], capture_output=True, text=True, timeout=120,
            cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )
        lines = (proc.stdout or "").strip().splitlines()
        if not lines:
            self.fail(f"engine_card({member_id}): no output\n{proc.stderr}")
        return json.loads(lines[-1])


# ══════════════════════════════════════════════════════════════════════
# §35 CALCULATION EQUIVALENCE — the mandatory check
# ══════════════════════════════════════════════════════════════════════

class CalculationEquivalenceTests(_TrackingBase):
    """
    Everything the tracking screen displays must equal what
    ReportCardService independently produces. Not 'close to'. Equal.

    If these ever diverge, a formula has been reimplemented somewhere and
    the two will drift apart silently.
    """

    def test_overview_totals_match_the_engine(self):
        for member in (S_FULL, S_MID, S_PENDING, S_C2):
            with self.subTest(member=member):
                card = self.engine_card(member)
                self.assertEqual("success", card["status"])
                got = self.detail(member)
                self.assertEqual("success", got["status"])

                self.assertEqual(card["totals"]["average"], got["overview"]["overall_average"])
                self.assertEqual(card["totals"]["grade_letter"], got["overview"]["overall_grade"])
                self.assertEqual(int(card["total_in_class"]), got["overview"]["total_in_class"])
                self.assertEqual(
                    int(card["totals"]["assessments_count"]),
                    got["overview"]["assessments_count"],
                )

    def test_rank_matches_the_engine(self):
        for member in (S_FULL, S_MID, S_PENDING):
            with self.subTest(member=member):
                card = self.engine_card(member)
                expected = card.get("rank")
                expected = int(expected) if expected else None
                self.assertEqual(expected, self.detail(member)["overview"]["rank"])

    def test_every_subject_line_matches_the_engine(self):
        """Field by field, for every subject of every representative student."""
        fields = (
            ("final_percentage", "final_percentage"),
            ("grade_letter", "grade_letter"),
            ("semester_1_score", "semester_1_score"),
            ("semester_2_score", "semester_2_score"),
            ("duration_type", "duration_type"),
            ("subject_status", "subject_status"),
        )
        for member in (S_FULL, S_MID, S_PENDING, S_BLANK, S_C2):
            card = self.engine_card(member)
            if card.get("status") != "success":
                continue
            got = self.detail(member)
            engine = {int(s["id"]): s for s in card["subjects"]}
            shown = {int(s["subject_id"]): s for s in got["subjects"]}
            self.assertEqual(
                set(engine), set(shown),
                f"member {member}: the tracking view must show exactly the engine's subjects",
            )
            for sid, esub in engine.items():
                for ekey, gkey in fields:
                    with self.subTest(member=member, subject=sid, field=ekey):
                        self.assertEqual(esub.get(ekey), shown[sid].get(gkey))

    def test_attendance_matches_the_engine_when_it_exists(self):
        for member in (S_FULL, S_MID):
            with self.subTest(member=member):
                card = self.engine_card(member)
                att = card["attendance"]
                got = self.detail(member)["attendance"]
                self.assertTrue(got["has_attendance"])
                for key in ("total", "present", "absent", "late", "excused", "rate"):
                    self.assertEqual(att[key], got[key], f"{key} must come from the engine")

    def test_a_semester_only_subject_keeps_its_own_final(self):
        """Student 101's Music is SEMESTER_ONLY: final = the semester score, s2 absent."""
        got = self.detail(S_FULL)
        music = [s for s in got["subjects"] if s["subject_name_en"] == "Music"][0]
        self.assertEqual("SEMESTER_ONLY", music["duration_type"])
        self.assertEqual(70, music["final_percentage"])
        self.assertIsNone(music["semester_2_score"], "a semester-only subject has no semester 2")

    def test_a_full_year_subject_uses_the_configured_weights(self):
        """Geez 80/90 at 40/60 is 86.0 — not the 85.0 a mean would give."""
        got = self.detail(S_FULL)
        geez = [s for s in got["subjects"] if s["subject_name_en"] == "Geez"][0]
        self.assertEqual("FULL_YEAR", geez["duration_type"])
        self.assertEqual(80, geez["semester_1_score"])
        self.assertEqual(90, geez["semester_2_score"])
        self.assertEqual(86, geez["final_percentage"],
                         "the year's 40/60 weights must be applied, not a mean")
        self.assertEqual({"s1": 40, "s2": 60}, geez["semester_weights"])

    def test_a_pending_full_year_subject_is_null_and_never_zero(self):
        """Student 104 has no Geez semester-2 mark. Null, not 0."""
        got = self.detail(S_PENDING)
        geez = [s for s in got["subjects"] if s["subject_name_en"] == "Geez"][0]
        self.assertIsNone(geez["final_percentage"])
        self.assertNotEqual(0, geez["final_percentage"])
        self.assertIsNone(geez["grade_letter"])
        self.assertFalse(geez["has_result"])

        # And it must not drag the average down as if it were a zero.
        card = self.engine_card(S_PENDING)
        self.assertEqual(card["totals"]["average"], got["overview"]["overall_average"])

    def test_the_pass_mark_and_grade_scale_come_from_the_engine(self):
        got = self.detail(S_FULL)
        card = self.engine_card(S_FULL)
        self.assertEqual(card["pass_mark"], got["pass_mark"])
        self.assertEqual(card["grade_scale"], got["grade_scale"])


# ══════════════════════════════════════════════════════════════════════
# SUBJECTS / CONTEXT
# ══════════════════════════════════════════════════════════════════════

class StudentDetailTests(_TrackingBase):

    def test_scope_and_context_are_separate_concepts(self):
        d = self.detail(S_FULL)
        self.assertEqual("student", d["scope"]["type"])
        self.assertEqual(S_FULL, d["scope"]["member_id"])
        self.assertEqual(C1, d["scope"]["class_id"])
        # The year is context and lives nowhere near the scope.
        self.assertIn("year_id", d["context"])
        self.assertNotIn("member_id", d["context"])
        self.assertNotIn("filters", d, "a selected student is not a filter")

    def test_the_class_is_resolved_from_the_active_enrolment(self):
        """Omitting class_id must not mean 'no class'."""
        self.assertEqual(C1, self.detail(S_FULL)["scope"]["class_id"])
        self.assertEqual(C2, self.detail(S_C2)["scope"]["class_id"])

    def test_an_explicit_class_is_honoured(self):
        d = self.detail(S_FULL, class_id=C1)
        self.assertEqual("success", d["status"])
        self.assertEqual(C1, d["scope"]["class_id"])

    def test_the_wrong_class_is_refused_rather_than_guessed(self):
        d = self.detail(S_FULL, class_id=C2)
        self.assertEqual("error", d["status"])
        self.assertEqual("not_in_class", d["code"])

    def test_the_annual_view_is_reported_as_annual(self):
        d = self.detail(S_FULL)
        self.assertTrue(d["context"]["is_annual"])
        self.assertEqual({"s1": 40, "s2": 60}, d["context"]["semester_weights"])

    def test_a_term_scoped_request_is_not_annual(self):
        d = self.detail(S_FULL, term_id=1)
        self.assertEqual("success", d["status"])
        self.assertFalse(d["context"]["is_annual"])

    def test_identity_is_present_and_private_fields_are_not(self):
        d = self.detail(S_FULL)
        s = d["student"]
        self.assertTrue(s["student_name"])
        self.assertTrue(s["member_code"])
        self.assertEqual("active", s["status"])
        blob = json.dumps(d).lower()
        for leak in ("phone", "password", "token", "email", "hash", "secret"):
            with self.subTest(field=leak):
                self.assertNotIn(leak, blob, f"{leak} must not appear in a tracking payload")

    def test_subject_counts_agree_with_the_subject_list(self):
        for member in (S_FULL, S_PENDING, S_BLANK):
            with self.subTest(member=member):
                d = self.detail(member)
                o = d["overview"]
                self.assertEqual(len(d["subjects"]), o["subjects_total"])
                self.assertEqual(
                    sum(1 for s in d["subjects"] if s["has_result"]),
                    o["subjects_with_result"],
                )
                self.assertEqual(
                    o["subjects_total"] - o["subjects_with_result"],
                    o["subjects_pending"],
                )


# ══════════════════════════════════════════════════════════════════════
# ASSESSMENTS — status is not result
# ══════════════════════════════════════════════════════════════════════

class StudentAssessmentsTests(_TrackingBase):

    def test_every_planned_assessment_appears_even_when_unmarked(self):
        """
        'Music Project' exists in the fixture and nobody has marked it. A
        view built only from marks would hide the outstanding work.
        """
        rows = self.assessments(S_FULL)["rows"]
        names = {r["assessment_name"] for r in rows}
        self.assertIn("Music Project", names)
        self.assertIn("Music Test", names)

    def test_an_unmarked_assessment_has_no_result_and_no_zero(self):
        rows = {r["assessment_name"]: r for r in self.assessments(S_FULL)["rows"]}
        project = rows["Music Project"]
        self.assertFalse(project["has_result"])
        self.assertIsNone(project["score"])
        self.assertIsNone(project["percentage"])
        self.assertNotEqual(0, project["score"])

    def test_workflow_status_is_the_submission_services_own_vocabulary(self):
        rows = {r["assessment_name"]: r for r in self.assessments(S_FULL)["rows"]}
        # Geez Midterm packet is 'approved' in the fixture.
        self.assertEqual("approved", rows["Geez Midterm"]["workflow_status"])
        self.assertEqual("Approved", rows["Geez Midterm"]["workflow_label"])
        # Nobody ever started the Music Project mark list.
        self.assertIsNone(rows["Music Project"]["workflow_status"])
        self.assertEqual("Not started", rows["Music Project"]["workflow_label"])

    def test_status_and_result_are_independent(self):
        """
        An assessment can carry a status and no result, which is exactly
        why they are two columns rather than one.
        """
        rows = self.assessments(S_FULL)["rows"]
        have_status_no_result = [
            r for r in rows if r["workflow_status"] is not None and not r["has_result"]
        ]
        have_result = [r for r in rows if r["has_result"]]
        self.assertTrue(have_result, "the fixture must contain a marked assessment")
        # Music Project: not started AND unmarked -> both facts reported.
        unstarted = [r for r in rows if r["workflow_label"] == "Not started"]
        self.assertTrue(unstarted)
        for r in unstarted:
            self.assertFalse(r["has_result"])
        # The service never copies one field into the other.
        for r in rows:
            self.assertIn("workflow_status", r)
            self.assertIn("has_result", r)

    def test_the_marked_assessments_carry_the_engines_scores(self):
        card = self.engine_card(S_FULL)
        engine_scores = {}
        for s in card["subjects"]:
            for a in s["assessments"]:
                engine_scores[int(a["id"])] = a
        for r in self.assessments(S_FULL)["rows"]:
            if not r["has_result"]:
                continue
            with self.subTest(assessment=r["assessment_name"]):
                e = engine_scores[r["assessment_id"]]
                self.assertEqual(e["score"], r["score"])
                self.assertEqual(e["percentage"], r["percentage"])
                self.assertEqual(e["max_score"], r["max_score"])

    def test_a_revision_needed_mark_list_is_reported_as_such(self):
        rows = {r["assessment_name"]: r for r in self.assessments(S_C2)["rows"]}
        hist = rows.get("History Test")
        self.assertIsNotNone(hist, "the C2 history assessment must be listed")
        self.assertEqual("revision_needed", hist["workflow_status"])
        self.assertEqual("Needs revision", hist["workflow_label"])

    def test_weights_are_reported_when_the_assessment_has_one(self):
        rows = {r["assessment_name"]: r for r in self.assessments(S_FULL)["rows"]}
        self.assertEqual(50.0, rows["Music Project"]["weight"])
        self.assertEqual(50.0, rows["Music Test"]["weight"])

    def test_a_student_with_no_marks_still_sees_the_planned_work(self):
        d = self.assessments(S_BLANK)
        self.assertEqual("success", d["status"])
        self.assertEqual("ok", d["data_state"])
        self.assertTrue(d["rows"])
        self.assertTrue(all(not r["has_result"] for r in d["rows"]),
                        "student 105 has no marks at all")


# ══════════════════════════════════════════════════════════════════════
# ATTENDANCE — absence is not zero
# ══════════════════════════════════════════════════════════════════════

class StudentAttendanceTests(_TrackingBase):

    def test_a_student_with_attendance_reports_real_numbers(self):
        a = self.detail(S_FULL)["attendance"]
        self.assertTrue(a["has_attendance"])
        self.assertEqual(4, a["total"])
        self.assertEqual(4, a["present"])
        self.assertEqual(100, a["rate"])

    def test_a_student_with_no_attendance_reports_null_not_zero(self):
        """
        The engine's emptyAttendance() legitimately returns rate => 0.
        Passing that on would claim the student attended 0% of classes.
        """
        a = self.detail(S_NO_ATTENDANCE)["attendance"]
        self.assertFalse(a["has_attendance"])
        for key in ("total", "present", "absent", "late", "excused", "rate"):
            with self.subTest(field=key):
                self.assertIsNone(a[key], f"{key} must be null, never 0, when no register exists")

    def test_the_data_state_names_the_situation(self):
        self.assertEqual("no_attendance", self.detail(S_NO_ATTENDANCE)["data_state"]["attendance"])
        self.assertEqual("ok", self.detail(S_FULL)["data_state"]["attendance"])

    def test_a_partial_record_is_not_rounded_into_a_full_one(self):
        card = self.engine_card(S_MID)
        a = self.detail(S_MID)["attendance"]
        self.assertEqual(card["attendance"]["rate"], a["rate"])
        self.assertEqual(card["attendance"]["absent"], a["absent"])


# ══════════════════════════════════════════════════════════════════════
# EMPTY AND ERROR STATES
# ══════════════════════════════════════════════════════════════════════

class StudentEmptyStateTests(_TrackingBase):

    def test_a_student_in_no_class_is_its_own_state(self):
        d = self.detail(S_NO_CLASS)
        self.assertEqual("error", d["status"])
        self.assertEqual("no_enrolment", d["code"])
        self.assertIn("not in a class", d["message"].lower())

    def test_no_results_is_distinct_from_no_subjects(self):
        d = self.detail(S_BLANK)
        self.assertEqual("success", d["status"])
        self.assertEqual("ok", d["data_state"]["subjects"], "the class does offer subjects")
        self.assertEqual("no_results", d["data_state"]["results"])
        self.assertIsNone(d["overview"]["overall_average"])
        self.assertIsNone(d["overview"]["overall_grade"])

    def test_no_results_does_not_fabricate_a_rank(self):
        self.assertIsNone(self.detail(S_BLANK)["overview"]["rank"])

    def test_an_unknown_student_is_refused_not_invented(self):
        d = self.detail(999999)
        self.assertEqual("error", d["status"])
        self.assertNotEqual("success", d.get("status"))

    def test_a_missing_student_id_is_rejected(self):
        d = self.call("api_education.php", "tracking_student_detail", "edu_dept")
        self.assertEqual("error", d["status"])
        self.assertEqual("invalid_student", d["code"])

    def test_a_non_numeric_student_id_is_rejected(self):
        for bad in ("abc", "1 OR 1=1", "", "-5", "0"):
            with self.subTest(member_id=bad):
                d = self.detail(bad)
                self.assertEqual("error", d["status"])

    def test_the_four_states_never_collapse_into_one_message(self):
        """Each situation must be reachable and distinguishable."""
        seen = {
            "no_enrolment": self.detail(S_NO_CLASS).get("code"),
            "no_results": self.detail(S_BLANK)["data_state"]["results"],
            "no_attendance": self.detail(S_NO_ATTENDANCE)["data_state"]["attendance"],
            "ok": self.detail(S_FULL)["data_state"]["attendance"],
        }
        self.assertEqual("no_enrolment", seen["no_enrolment"])
        self.assertEqual("no_results", seen["no_results"])
        self.assertEqual("no_attendance", seen["no_attendance"])
        self.assertEqual("ok", seen["ok"])


# ══════════════════════════════════════════════════════════════════════
# AUTHORIZATION
# ══════════════════════════════════════════════════════════════════════

class StudentTrackingAuthorizationTests(_TrackingBase):

    def test_the_education_roles_are_allowed(self):
        for role in EDU_ROLES:
            with self.subTest(role=role):
                self.assertEqual("success", self.detail(S_FULL, role=role)["status"])

    def test_every_other_role_is_refused_both_actions(self):
        for role in NON_EDU_ROLES:
            for action in ("tracking_student_detail", "tracking_student_assessments"):
                with self.subTest(role=role, action=action):
                    d = self.call("api_education.php", action, role, member_id=S_FULL)
                    self.assertEqual("error", d["status"])
                    self.assertNotIn("subjects", d,
                                     "a refused role must receive no academic payload")

    def test_a_refused_role_gets_no_academic_data_at_all(self):
        for role in NON_EDU_ROLES:
            with self.subTest(role=role):
                blob = json.dumps(self.detail(S_FULL, role=role))
                for leak in ("overall_average", "grade_letter", "final_percentage", "rank"):
                    self.assertNotIn(leak, blob)

    def test_the_new_actions_joined_the_existing_tier_rather_than_a_new_gate(self):
        src = API_EDU.read_text(encoding="utf-8")
        tier = re.search(r"\$__analyticsActions\s*=\s*\[(.*?)\];", src, re.S)
        self.assertIsNotNone(tier, "the tier-3 analytics list must still exist")
        body = tier.group(1)
        self.assertIn("tracking_student_detail", body)
        self.assertIn("tracking_student_assessments", body)

    def test_no_second_permission_system_was_introduced(self):
        src = SERVICE.read_text(encoding="utf-8")
        for forbidden in ("$_SESSION", "admin_role", "http_response_code", "hasPermission"):
            with self.subTest(token=forbidden):
                self.assertNotIn(forbidden, src,
                                 "authorization belongs in the endpoint, not the service")

    def test_class_visibility_is_the_existing_rule(self):
        """
        No second authorization system: class visibility must be decided
        by ReportCardService::canViewClass, the rule the rest of the
        Education API already uses.
        """
        src = API_EDU.read_text(encoding="utf-8")
        block = src[src.index("case 'tracking_student_detail'"):src.index("case 'get_academic_intelligence'")]
        self.assertIn("canViewClass", block,
                      "the tracking actions must reuse ReportCardService::canViewClass")
        # "no second authorization system" means the endpoint decides
        # nothing itself: it must not issue queries of its own. Matching
        # on the word SELECT is not safe here (the prose contains
        # "must be SELECTED"), so pin the execution calls instead.
        for sql_call in ("->query(", "->prepare(", "->real_query(", "mysqli_query"):
            self.assertNotIn(sql_call, block,
                             f"the endpoint must not run its own SQL ({sql_call})")

    def test_both_visibility_checks_are_present_and_independent(self):
        """
        There are two canViewClass calls and they guard different things:
        the inbound one covers a class_id the caller supplied, the second
        covers the class the engine resolved when none was supplied.
        Deleting either one must fail this test — neither is observable
        today, because canViewClass is true for all three roles that can
        reach tier 3, so only a source pin can hold them in place.
        """
        src = API_EDU.read_text(encoding="utf-8")
        # Bounded by the next case, so a later phase adding its own
        # endpoints after this one cannot inflate the count and make this
        # assertion pass for the wrong reason.
        block = src[src.index("case 'tracking_student_detail'"):
                    src.index("case 'tracking_teacher_detail'")]
        self.assertEqual(
            2, block.count("canViewClass"),
            "expected exactly two visibility checks: inbound class_id and resolved class")
        inbound, recheck = [block.index("canViewClass"),
                            block.index("canViewClass", block.index("canViewClass") + 1)]
        call = block.index("AcademicTrackingService::")
        self.assertLess(inbound, call,
                        "the supplied class_id must be checked before any data is loaded")
        self.assertGreater(recheck, call,
                           "the resolved class must be checked after the engine returns")
        self.assertIn("$trkClass > 0", block[:call],
                      "the inbound check only applies when a class_id was actually supplied")

    def test_id_tampering_cannot_reach_another_class(self):
        """
        Asking for a C2 student while naming C1 must fail, and vice versa.
        The class in the query string is never trusted over the enrolment.
        """
        self.assertEqual("error", self.detail(S_C2, class_id=C1)["status"])
        self.assertEqual("error", self.detail(S_FULL, class_id=C2)["status"])

    def test_the_payload_always_belongs_to_the_requested_student(self):
        """
        Changing member_id must change whose data comes back — never
        return a neighbouring student's card under the requested id.
        """
        for member in (S_FULL, S_MID, S_PENDING, S_C2):
            with self.subTest(member=member):
                d = self.detail(member)
                self.assertEqual(member, d["scope"]["member_id"])
                self.assertEqual(member, d["student"]["id"])
                a = self.assessments(member)
                self.assertEqual(member, a["scope"]["member_id"])

    def test_the_resolved_class_is_rechecked_when_it_was_not_supplied(self):
        """
        Defence in depth, and deliberately a source pin rather than a
        behavioural one.

        When class_id is omitted the engine resolves it from the active
        enrolment, so the visibility check performed on the way in saw
        nothing. The endpoint therefore re-checks the class it got back.
        For the three roles that can reach tier 3 canViewClass is always
        true, so this branch cannot be observed from outside today — it
        exists so that widening the tier later cannot silently open a
        hole. Pinning the code is the only way to keep it.
        """
        src = API_EDU.read_text(encoding="utf-8")
        block = src[src.index("case 'tracking_student_detail'"):
                    src.index("case 'get_academic_intelligence'")]
        self.assertIn("$trkResolved", block)
        self.assertIn("$trkResolved !== $trkClass", block,
                      "the resolved class must be re-checked when it differs")
        # The recheck must sit after the service call, where the resolved
        # class is actually known.
        self.assertLess(block.index("AcademicTrackingService::"),
                        block.index("$trkResolved !== $trkClass"))

    def test_report_card_reuse_keeps_its_own_authorization(self):
        d = self.report_card(S_FULL)
        self.assertEqual("success", d["status"])
        for role in ("hr_dept", "finance_dept"):
            with self.subTest(role=role):
                r = self.report_card(S_FULL, role=role)
                self.assertNotEqual("success", r.get("status"))


# ══════════════════════════════════════════════════════════════════════
# REPORT CARD REUSE
# ══════════════════════════════════════════════════════════════════════

class StudentReportCardTests(_TrackingBase):

    def test_the_report_card_section_reuses_the_existing_endpoint(self):
        """No tracking_student_report_card action was invented."""
        src = API_EDU.read_text(encoding="utf-8")
        self.assertNotIn("tracking_student_report_card", src)
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("get_report_card", controller)
        self.assertIn("api_communication.php", controller)

    def test_the_endpoint_returns_the_engines_card(self):
        card = self.engine_card(S_FULL)
        served = self.report_card(S_FULL)
        self.assertEqual("success", served["status"])
        self.assertEqual(card["overall_average"], served["overall_average"])
        self.assertEqual(card["rank"], served["rank"])
        self.assertEqual(
            [s["final_percentage"] for s in card["subjects"]],
            [s["final_percentage"] for s in served["subjects"]],
        )

    def test_the_tracking_overview_and_the_report_card_agree(self):
        """Two screens, one engine — they must not disagree."""
        for member in (S_FULL, S_MID, S_PENDING):
            with self.subTest(member=member):
                overview = self.detail(member)["overview"]
                card = self.report_card(member)
                self.assertEqual(card["overall_average"], overview["overall_average"])
                self.assertEqual(card["overall_grade"], overview["overall_grade"])


# ══════════════════════════════════════════════════════════════════════
# ARCHITECTURE CONTRACT
# ══════════════════════════════════════════════════════════════════════

class StudentTrackingContractTests(unittest.TestCase):
    """Source-level pins for the rules that a behavioural test cannot see."""

    def test_no_parallel_api_file_was_created(self):
        for name in ("api_academic_tracking.php", "api_academic_tracking_student.php",
                     "api_tracking.php"):
            with self.subTest(file=name):
                self.assertFalse((ROOT / "admin" / name).exists())

    def test_the_tracking_service_contains_no_academic_formula(self):
        """
        The service may name a field; it may not compute one. Each pattern
        is a calculation, not a mention.
        """
        src = SERVICE.read_text(encoding="utf-8")
        # Strip comments: the file explains at length what it does NOT do.
        code = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
        code = re.sub(r"(?m)^\s*//.*$", "", code)
        forbidden = {
            "weighted sum": r"\*\s*\$?weight",
            "division into a percentage": r"/\s*\$?max\w*\s*\*\s*100",
            "grade letter decision": r"(?i)if\s*\(.*>=\s*90",
            "pass mark comparison": r">=\s*(self::)?PASS_MARK",
            "ranking": r"(?i)\brank\s*\+\+|sort.*rank",
            "semester weighting": r"s1_weight|s2_weight|\bweightsForYear\b",
            "averaging": r"array_sum\s*\(.*\)\s*/\s*count",
        }
        for label, pattern in forbidden.items():
            with self.subTest(rule=label):
                self.assertIsNone(
                    re.search(pattern, code),
                    f"AcademicTrackingService must not {label}",
                )

    def test_the_controller_contains_no_academic_formula(self):
        src = CONTROLLER.read_text(encoding="utf-8")
        code = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
        code = re.sub(r"(?m)^\s*//.*$", "", code)
        forbidden = {
            "weighting": r"(?<!font-)(?<!font_)\bweight\b\s*\*",
            "percentage maths": r"/\s*\w*[Mm]ax\w*\s*\*\s*100",
            "grade thresholds": r">=\s*90|>=\s*80|>=\s*70",
            "averaging": r"reduce\s*\(.*\)\s*/\s*\w+\.length",
        }
        for label, pattern in forbidden.items():
            with self.subTest(rule=label):
                self.assertIsNone(re.search(pattern, code),
                                  f"the controller must not perform {label}")

    def test_the_controller_uses_no_emoji(self):
        """Hard requirement: the professional icon set only."""
        src = CONTROLLER.read_text(encoding="utf-8")
        emoji = re.compile(
            "[\U0001F300-\U0001FAFF\U0001F000-\U0001F2FF\u2600-\u27BF\uFE0F\u2B00-\u2BFF]"
        )
        found = emoji.findall(src)
        self.assertEqual([], found, f"emoji found in the tracking UI: {found}")

    def test_icons_come_from_the_existing_font_awesome_set(self):
        src = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("fa-solid", src)
        # Every icon reference must be a Font Awesome class, not a symbol.
        self.assertGreater(len(re.findall(r"fa-solid fa-[a-z-]+", src)), 10)

    def test_no_second_chart_library_was_added(self):
        src = CONTROLLER.read_text(encoding="utf-8")
        for lib in ("d3", "echarts", "highcharts", "plotly", "apexcharts"):
            with self.subTest(lib=lib):
                self.assertNotIn(lib, src.lower())
        # Charting is optional and guarded, so a missing runtime degrades
        # to the table rather than throwing.
        self.assertIn("typeof Chart === 'undefined'", src)

    def test_the_batched_status_lookup_has_one_implementation(self):
        """
        marklistPacketStatus() must delegate to the batch rather than keep
        a second copy of the submitted/approved precedence rule.
        """
        src = SUBMISSION.read_text(encoding="utf-8")
        single = re.search(
            r"public static function marklistPacketStatus\(.*?\n    \}", src, re.S
        )
        self.assertIsNotNone(single)
        self.assertIn("marklistPacketStatuses", single.group(0))
        self.assertNotIn("ORDER BY id DESC LIMIT 1", single.group(0),
                         "the precedence SQL must live in exactly one place")

    def test_the_service_delegates_calculation_to_the_engine(self):
        src = SERVICE.read_text(encoding="utf-8")
        self.assertIn("ReportCardService::getCard", src)
        self.assertIn("SubmissionService::", src)

    def test_later_workflows_were_not_built(self):
        """
        Class tracking belongs to a later phase, and this is the guard that
        keeps it out.

        It was written in Phase 2 to exclude Teacher, Subject and Class
        tracking, narrowed in Phase 3 when Teacher landed, and narrowed
        again in Phase 4 when Subject landed. It has never been deleted,
        and what remains is the boundary that is still in front of us.
        """
        src = CONTROLLER.read_text(encoding="utf-8")
        for absent in ("renderClassDetail", "renderClassTracking",
                       "tracking_class_detail", "tracking_class_students"):
            with self.subTest(symbol=absent):
                self.assertNotIn(absent, src)
        api = API_EDU.read_text(encoding="utf-8")
        for absent in ("tracking_class_detail", "tracking_class_assessments",
                       "tracking_class_students", "tracking_class_subjects"):
            with self.subTest(action=absent):
                self.assertNotIn(absent, api)

    def test_the_student_workflow_is_unchanged_by_later_phases(self):
        """Later phases must not modify Student Tracking to fit themselves."""
        api = API_EDU.read_text(encoding="utf-8")
        self.assertIn("case 'tracking_student_detail':", api)
        self.assertIn("case 'tracking_student_assessments':", api)
        src = CONTROLLER.read_text(encoding="utf-8")
        for kept in ("renderStudent =", "loadStudentDetail", "loadStudentAssessments",
                     "loadStudentReportCard"):
            with self.subTest(symbol=kept):
                self.assertIn(kept, src)


# ══════════════════════════════════════════════════════════════════════
# FRONTEND BEHAVIOUR
# ══════════════════════════════════════════════════════════════════════

class StudentTrackingControllerTests(unittest.TestCase):
    """Runs the node harness so one pytest run covers both halves."""

    def test_the_student_workflow_harness_passes(self):
        node = shutil.which("node")
        if not node:
            raise unittest.SkipTest("node not available — controller harness skipped")
        if not STUDENT_HARNESS.is_file():
            self.fail("tests/e2e/student_tracking.js is missing")
        proc = subprocess.run(
            [node, str(STUDENT_HARNESS)], capture_output=True, text=True,
            timeout=300, cwd=str(ROOT),
        )
        self.assertEqual(
            0, proc.returncode,
            f"student tracking controller harness failed:\n{proc.stdout}\n{proc.stderr}",
        )
        self.assertIn("0 failed", proc.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
