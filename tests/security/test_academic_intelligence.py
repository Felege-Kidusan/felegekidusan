"""Academic Intelligence: four perspectives over ONE calculation engine.

These are behavioural tests, not source greps. Every scenario runs against a
live MariaDB through the real App\\Services\\AcademicIntelligenceService and
the real App\\Services\\ReportCardService, and the authorization cases
dispatch the real admin/api_education.php endpoint with a real session.

The claim that matters most is checked directly: for every subject of every
class, in the annual view and in BOTH semesters, the number the perspective
layer reports is compared against
ReportCardService::getClassReport($conn, $classId, $subjectId, ...) -- the
pre-existing authority. If the feature ever grows a second calculation
engine, scenario `breakdown_matches_subject_report` fails.

Baseline relevance
------------------
Before this feature there was no way to ask the system about one teacher's
assignments or one subject across classes at all: EducationAnalyticsService
offered a cross-school student list, a per-class league table and a
submission matrix, and nothing joined them. AcademicIntelligenceService did
not exist, so every scenario here fails on that baseline by import error.

The two authorization tests fail on the baseline for a different and more
interesting reason: `get_education_hub` and `filter_students_performance`
had no role gate beyond "is logged in", so a teacher or attendance-taker
session could read the whole school's marks through the API.

ENVIRONMENT (mirrors tests/security/test_sync_change_feed.py):
  .fkss_env.php   in the repo root, pointing at a DEDICATED test database.
                  The runner creates and drops its own tables in
                  $SSMS_SYNC_DB (default ssms_e2e) -- never production.
  php             on PATH, or SSMS_E2E_PHP=/path/to/php (needs mysqli).
"""
import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / "tests" / "e2e" / "academic_intelligence.php"
ROLE_RUNNER = ROOT / "tests" / "e2e" / "education_config_api.php"
SERVICE = ROOT / "admin" / "backend" / "services" / "AcademicIntelligenceService.php"
REPORT_CARD = ROOT / "admin" / "backend" / "services" / "ReportCardService.php"
API_EDU = ROOT / "admin" / "api_education.php"
ENV_FILE = ROOT / ".fkss_env.php"

SYNC_DB = os.environ.get("SSMS_SYNC_DB", "ssms_e2e")


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


class _LiveBase(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — academic intelligence e2e skipped")
        if not RUNNER.is_file():
            raise unittest.SkipTest("tests/e2e/academic_intelligence.php not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(
                ".fkss_env.php not present — academic intelligence e2e skipped "
                "(dedicated test DB not configured)"
            )
        if not _probe_db(php):
            raise unittest.SkipTest(
                f"e2e database '{SYNC_DB}' unreachable — academic intelligence e2e skipped"
            )
        cls.php = php


class AcademicIntelligenceEndToEndTests(_LiveBase):
    """Behavioural scenarios driven through the live runner."""

    def _run(self, scenario):
        proc = subprocess.run(
            [self.php, str(RUNNER), scenario],
            capture_output=True, text=True, timeout=600, cwd=str(ROOT),
            env={**os.environ, "SSMS_AUDIT_TESTING": "1", "SSMS_SYNC_DB": SYNC_DB},
        )
        if proc.returncode != 0:
            self.fail(
                f"scenario '{scenario}' failed (exit {proc.returncode})\n"
                f"{proc.stdout}\n{proc.stderr}"
            )
        return proc.stdout

    # ── the central claim ───────────────────────────────────────────────

    def test_every_subject_number_matches_the_report_card_service(self):
        """No second calculation engine.

        Each subject of each class, annual and both semesters, compared
        against ReportCardService's own subject-filtered class report:
        average, graded count, pass rate, grade distribution, highest,
        lowest.
        """
        out = self._run("breakdown_matches_subject_report")
        self.assertIn("0 failed", out)

    def test_a_class_is_computed_once_not_once_per_subject(self):
        """The perspective layer must reuse one class pack, not rebuild it."""
        out = self._run("breakdown_is_one_pass")
        self.assertIn("0 failed", out)

    def test_semester_and_duration_policy_survive_the_projection(self):
        """Migration 056 behaviour is preserved: configured weights, no
        zero-filling of an unfinished subject, absent semesters stay absent."""
        out = self._run("duration_policy_preserved")
        self.assertIn("0 failed", out)

    # ── the four perspectives ───────────────────────────────────────────

    def test_student_perspective(self):
        out = self._run("student_perspective")
        self.assertIn("0 failed", out)

    def test_teacher_perspective(self):
        """Only that teacher's assignments, with real submission state."""
        out = self._run("teacher_perspective")
        self.assertIn("0 failed", out)

    def test_subject_perspective(self):
        """Only the classes that actually offer the subject."""
        out = self._run("subject_perspective")
        self.assertIn("0 failed", out)

    def test_class_perspective(self):
        out = self._run("class_perspective")
        self.assertIn("0 failed", out)

    # ── edges ───────────────────────────────────────────────────────────

    def test_invalid_ids_do_not_leak_data(self):
        out = self._run("invalid_input")
        self.assertIn("0 failed", out)

    def test_filters_move_the_summary_not_only_the_table(self):
        out = self._run("filters_apply_to_summary")
        self.assertIn("0 failed", out)

    def test_every_perspective_returns_the_same_envelope(self):
        out = self._run("contract_shape")
        self.assertIn("0 failed", out)

    def test_term_scoping_hides_subjects_that_do_not_run_that_semester(self):
        out = self._run("term_scoping")
        self.assertIn("0 failed", out)


class AcademicIntelligenceAuthorizationTests(_LiveBase):
    """The real endpoint, dispatched with a real session, per role."""

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        if not ROLE_RUNNER.is_file():
            raise unittest.SkipTest("tests/e2e/education_config_api.php not present")
        # Leave a seeded world behind for the endpoint to read.
        subprocess.run(
            [cls.php, str(RUNNER), "term_scoping"],
            capture_output=True, text=True, timeout=600, cwd=str(ROOT),
            env={**os.environ, "SSMS_AUDIT_TESTING": "1", "SSMS_SYNC_DB": SYNC_DB},
        )

    def _call(self, role, params):
        proc = subprocess.run(
            [self.php, str(ROLE_RUNNER), "api_education.php", role, "GET", json.dumps(params)],
            capture_output=True, text=True, timeout=120, cwd=str(ROOT),
            env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )
        tail = proc.stdout.strip().splitlines()
        if not tail:
            self.fail(f"no output for role {role}: {proc.stderr}")
        try:
            return json.loads(tail[-1])
        except json.JSONDecodeError:
            self.fail(f"non-JSON reply for role {role}: {tail[-1][:300]}")

    ALLOWED = ("super_admin", "school_admin", "edu_dept")
    DENIED = ("teacher", "attendance_taker", "hr_dept")

    def test_education_roles_may_read_each_perspective(self):
        cases = [
            {"perspective": "student", "member_id": 101},
            {"perspective": "teacher", "teacher_id": 11},
            {"perspective": "subject", "subject_id": 1},
            {"perspective": "class", "class_id": 1},
        ]
        for role in self.ALLOWED:
            for case in cases:
                with self.subTest(role=role, perspective=case["perspective"]):
                    body = self._call(
                        role,
                        {"action": "get_academic_intelligence", "year_id": 1, **case},
                    )
                    self.assertEqual("success", body.get("status"))
                    self.assertEqual(case["perspective"], body.get("perspective"))

    def test_other_roles_are_refused_and_receive_no_academic_data(self):
        for role in self.DENIED:
            for perspective, extra in (
                ("student", {"member_id": 101}),
                ("teacher", {"teacher_id": 11}),
                ("subject", {"subject_id": 1}),
                ("class", {"class_id": 1}),
            ):
                with self.subTest(role=role, perspective=perspective):
                    body = self._call(
                        role,
                        {
                            "action": "get_academic_intelligence",
                            "perspective": perspective,
                            "year_id": 1,
                            **extra,
                        },
                    )
                    self.assertEqual("error", body.get("status"))
                    # Nothing academic may ride along with the refusal.
                    for leaked in ("rows", "summary", "charts", "drilldown"):
                        self.assertNotIn(leaked, body)
                    self.assertNotIn("Abebe", json.dumps(body))

    def test_filter_options_are_education_only(self):
        for role in self.ALLOWED:
            with self.subTest(role=role):
                body = self._call(role, {"action": "get_academic_intelligence_options", "year_id": 1})
                self.assertEqual("success", body.get("status"))
        for role in self.DENIED:
            with self.subTest(role=role):
                body = self._call(role, {"action": "get_academic_intelligence_options", "year_id": 1})
                self.assertEqual("error", body.get("status"))
                self.assertNotIn("classes", body)

    def test_preexisting_analytics_endpoints_are_also_gated(self):
        """Regression guard for the hole this feature closed.

        get_education_hub and filter_students_performance return school-wide
        student academic data. Both were reachable by any logged-in session,
        including teacher and attendance_taker, even though the only page
        that hosts them is already restricted to the Education roles.
        """
        for action in ("get_education_hub", "filter_students_performance"):
            for role in self.DENIED:
                with self.subTest(action=action, role=role):
                    body = self._call(role, {"action": action, "year_id": 1})
                    self.assertEqual("error", body.get("status"))
                    self.assertNotIn("students", body)
                    self.assertNotIn("macro_stats", body)
            for role in self.ALLOWED:
                with self.subTest(action=action, role=role):
                    body = self._call(role, {"action": action, "year_id": 1})
                    self.assertEqual(
                        "success", body.get("status"),
                        "the Education roles must keep working",
                    )

    def test_unknown_perspective_is_rejected(self):
        body = self._call(
            "edu_dept",
            {"action": "get_academic_intelligence", "perspective": "../etc/passwd", "year_id": 1},
        )
        self.assertEqual("error", body.get("status"))
        self.assertNotIn("rows", body)


class AcademicIntelligenceContractTests(unittest.TestCase):
    """Source-level pins for decisions a runtime harness cannot show.

    Deliberately few: everything that can be executed is executed above.
    """

    @classmethod
    def setUpClass(cls):
        cls.service = SERVICE.read_text(encoding="utf-8")
        cls.report_card = REPORT_CARD.read_text(encoding="utf-8")
        cls.api = API_EDU.read_text(encoding="utf-8")

    def test_service_computes_no_semester_weighting_of_its_own(self):
        """Weighting, final scores and letters belong to ReportCardService.

        The perspective layer may count and group, but it must never decide
        what a subject's score IS.
        """
        for forbidden in (
            "s1_weight_pct",
            "s2_weight_pct",
            "SubjectDurationPolicy::finalScore",
            "weightsForYear",
        ):
            self.assertNotIn(
                forbidden, self.service,
                f"{forbidden} means the perspective layer started doing the "
                f"engine's job",
            )

    def test_service_derives_grade_letters_from_the_authority(self):
        """PASS_MARK must be referenced, never restated as a literal 50."""
        self.assertIn("ReportCardService::PASS_MARK", self.service)
        self.assertIn("ReportCardService::GRADE_SCALE", self.service)

    def test_no_second_report_endpoint_was_created(self):
        """The feature extends api_education.php rather than adding an API."""
        self.assertIn("case 'get_academic_intelligence':", self.api)
        self.assertIn("case 'get_academic_intelligence_options':", self.api)
        self.assertFalse(
            (ROOT / "admin" / "api_academic_intelligence.php").exists(),
            "a parallel analytics API was created instead of extending the "
            "existing one",
        )

    def test_teacher_view_publishes_no_quality_ranking(self):
        """A teacher view reports activity, never a teacher score."""
        lowered = self.service.lower()
        for forbidden in ("teacher_score", "teacher_rating", "best_teacher",
                          "worst_teacher", "teacher_rank", "effectiveness"):
            self.assertNotIn(forbidden, lowered)
        self.assertIn("Not a measure of teacher quality", self.service)

    def test_current_year_resolver_is_callable_from_outside(self):
        """EducationAnalyticsService::getHubData() calls this across class
        boundaries; while it was private that call was a fatal Error whenever
        no current academic year was configured."""
        self.assertIn("public static function currentYearId(", self.report_card)


if __name__ == "__main__":
    unittest.main()
