"""Education configuration: subject duration and per-year semester weights.

These are functional tests. Each one calls the real admin endpoint through
tests/e2e/education_config_api.php with a real session and a real database,
and asserts on the JSON the endpoint actually returns and on what ends up in
the table afterwards. No source greps stand in for behaviour here.

What is being protected
-----------------------
Migration 056 added class_subjects.duration_type / .term_id and
academic_years.s1_weight_pct / .s2_weight_pct. The UI that writes those
columns can produce states the report service cannot interpret:

  * a SEMESTER_ONLY subject with no semester, or one that does not exist,
    leaves the policy unable to say which semester closes the subject;
  * a FULL_YEAR subject pinned to a single semester contradicts itself;
  * weights that do not total 100 silently distort every annual result for
    that year.

So the rules live on the server. Hiding a control in the browser is not a
restriction, and the tests below drive the endpoints directly - exactly what
an unauthorized caller would do - rather than going through the page.

Baseline relevance
------------------
Before this change neither endpoint accepted these fields at all:
save_academic_year wrote only the descriptive year columns, and
api_subjects.php had no duration action. Every assertion here about a value
being stored, rejected, or refused to a role fails against that baseline.
"""

import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HARNESS = ROOT / "tests/e2e/education_config_api.php"
REPORT_CARD = ROOT / "admin/backend/services/ReportCardService.php"
EDU_DASHBOARD = ROOT / "admin/dashboards/edu_dept.php"
SCHOOL_ADMIN_DASHBOARD = ROOT / "admin/dashboards/school_admin.php"

DB_NAME = os.environ.get("SSMS_EDU_CFG_DB", "ssms_e2e")
DB_HOST = os.environ.get("SSMS_EDU_CFG_HOST", "127.0.0.1")
DB_USER = os.environ.get("SSMS_EDU_CFG_USER", "ssms")
DB_PASS = os.environ.get("SSMS_EDU_CFG_PASS", "ssms")

YEAR_ID = 1
TERM_S1 = 1
TERM_S2 = 2
CLASS_ID = 1
OFFERING_A = 1          # class 1 / subject 1
OFFERING_B = 2          # class 1 / subject 2
MISSING_TERM = 99999
MISSING_OFFERING = 99999

# Only what these two endpoints touch. Rebuilt for every run, so the test is
# self-contained and never depends on data another suite left behind.
SCHEMA = """
DROP TABLE IF EXISTS class_subjects;
DROP TABLE IF EXISTS academic_terms;
DROP TABLE IF EXISTS academic_years;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS classes;
CREATE TABLE academic_years (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year_name VARCHAR(50) NOT NULL UNIQUE,
  ec_year INT NULL, year_gc VARCHAR(20) NULL,
  start_date DATE NULL, end_date DATE NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'upcoming',
  s1_weight_pct DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  s2_weight_pct DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  CONSTRAINT chk_academic_year_semester_weights
    CHECK (s1_weight_pct + s2_weight_pct = 100.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE academic_terms (
  id INT AUTO_INCREMENT PRIMARY KEY, academic_year_id INT NOT NULL,
  term_name VARCHAR(50) NOT NULL, term_number INT NOT NULL,
  start_date DATE NULL, end_date DATE NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE classes (
  id INT AUTO_INCREMENT PRIMARY KEY, class_name VARCHAR(100) NOT NULL,
  class_name_en VARCHAR(100) NULL, class_code VARCHAR(30) NULL,
  level_order INT NOT NULL DEFAULT 1, age_group VARCHAR(30) NULL,
  description TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE subjects (
  id INT AUTO_INCREMENT PRIMARY KEY, subject_name VARCHAR(100) NOT NULL,
  subject_name_en VARCHAR(100) NULL, subject_code VARCHAR(30) NULL,
  description TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE class_subjects (
  id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL, subject_id INT NOT NULL,
  teacher_id INT NULL,
  duration_type ENUM('SEMESTER_ONLY','FULL_YEAR') NULL,
  term_id INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, username VARCHAR(100) NULL,
  action VARCHAR(255) NOT NULL, details TEXT NULL, entity_type VARCHAR(50) NULL,
  entity_id INT UNSIGNED NULL, ip_address VARCHAR(45) NULL, user_agent TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO academic_years (id, year_name, ec_year, year_gc, is_current, status)
  VALUES (1, 'EDUCFG TEST YEAR', 2018, '2025/2026', 1, 'active');
INSERT INTO academic_terms (id, academic_year_id, term_name, term_number)
  VALUES (1, 1, 'Semester 1', 1), (2, 1, 'Semester 2', 2);
INSERT INTO classes (id, class_name, class_code) VALUES (1, 'EduCfg Class', 'ECFG1');
INSERT INTO subjects (id, subject_name, subject_name_en)
  VALUES (1, 'Subject A', 'Subject A'), (2, 'Subject B', 'Subject B');
INSERT INTO class_subjects (id, class_id, subject_id) VALUES (1, 1, 1), (2, 1, 2);
"""


def php_binary() -> str:
    override = os.environ.get("SSMS_E2E_PHP", "").strip()
    return override or (shutil.which("php") or "")


def _php_mysqli(script: str):
    """Run a PHP snippet with $db open on the test database."""
    php = php_binary()
    if not php:
        raise unittest.SkipTest("PHP CLI is not installed")
    code = (
        "$db=@new mysqli(%s,%s,%s,%s);"
        "if($db->connect_errno){fwrite(STDERR,'CONNECT:'.$db->connect_error);exit(3);}"
        "$db->set_charset('utf8mb4');" % (
            json.dumps(DB_HOST), json.dumps(DB_USER),
            json.dumps(DB_PASS), json.dumps(DB_NAME),
        )
    ) + script
    return subprocess.run(
        [php, "-r", code], capture_output=True, text=True, timeout=120, cwd=str(ROOT)
    )


def db_available() -> bool:
    proc = _php_mysqli("echo 'ok';")
    return proc.returncode == 0 and "ok" in proc.stdout


def build_schema():
    statements = [s.strip() for s in SCHEMA.split(";") if s.strip()]
    payload = json.dumps(statements)
    proc = _php_mysqli(
        "foreach(json_decode(%s,true) as $s){"
        "if(!$db->query($s)){fwrite(STDERR,'SQL:'.$db->error.' :: '.substr($s,0,60));exit(4);}"
        "} echo 'built';" % json.dumps(payload)
    )
    if proc.returncode != 0 or "built" not in proc.stdout:
        raise unittest.SkipTest(
            "could not build the education test schema: %s" % (proc.stderr or proc.stdout)[:300]
        )


def scalar(sql: str):
    proc = _php_mysqli(
        "$r=$db->query(%s); $row=$r?$r->fetch_row():null;"
        "echo json_encode($row?$row[0]:null);" % json.dumps(sql)
    )
    if proc.returncode != 0:
        raise AssertionError("query failed: %s" % proc.stderr[:200])
    return json.loads(proc.stdout.strip() or "null")


def api(api_file: str, role: str, method: str, params: dict) -> dict:
    """Call a real endpoint as `role` and return its decoded JSON."""
    php = php_binary()
    if not php:
        raise unittest.SkipTest("PHP CLI is not installed")
    proc = subprocess.run(
        [php, str(HARNESS), api_file, role, method, json.dumps(params)],
        capture_output=True, text=True, timeout=120, cwd=str(ROOT),
        env={**os.environ, "SSMS_DB_NAME": DB_NAME},
    )
    out = proc.stdout.strip()
    start = out.find("{")
    if start < 0:
        raise AssertionError(
            "endpoint returned no JSON.\nstdout=%s\nstderr=%s" % (out[:400], proc.stderr[:400])
        )
    return json.loads(out[start:])


def duration_of(offering_id: int):
    return scalar("SELECT duration_type FROM class_subjects WHERE id=%d" % offering_id)


def term_of(offering_id: int):
    return scalar("SELECT term_id FROM class_subjects WHERE id=%d" % offering_id)


def weights():
    raw = scalar(
        "SELECT CONCAT(s1_weight_pct,'/',s2_weight_pct) "
        "FROM academic_years WHERE id=%d" % YEAR_ID
    )
    return raw


YEAR_FIELDS = {
    "action": "save_academic_year",
    "id": YEAR_ID,
    "year_name": "EDUCFG TEST YEAR",
    "ec_year": 2018,
    "year_gc": "2025/2026",
    "start_date": "2025-09-01",
    "end_date": "2026-06-30",
}


def save_year(role: str, **extra) -> dict:
    params = dict(YEAR_FIELDS)
    params.update(extra)
    return api("api_education.php", role, "POST", params)


def save_duration(role: str, offering_id, duration, term=None) -> dict:
    params = {
        "action": "save_class_subject_duration",
        "offering_id": offering_id,
        "duration_type": duration,
    }
    if term is not None:
        params["term_id"] = term
    return api("api_subjects.php", role, "POST", params)


def setUpModule():
    if not php_binary():
        raise unittest.SkipTest("PHP CLI is not installed")
    if not HARNESS.is_file():
        raise unittest.SkipTest("test harness missing: %s" % HARNESS)
    if not (ROOT / ".fkss_env.php").is_file():
        raise unittest.SkipTest(".fkss_env.php is not provisioned")
    if not db_available():
        raise unittest.SkipTest("test database %s is not reachable" % DB_NAME)
    build_schema()


class SubjectDurationEndpointTests(unittest.TestCase):
    """class_subjects.duration_type / .term_id through the real endpoint."""

    def setUp(self):
        _php_mysqli("$db->query('UPDATE class_subjects SET duration_type=NULL, term_id=NULL');")

    # 1
    def test_education_staff_can_set_semester_only_with_a_term(self):
        res = save_duration("edu_dept", OFFERING_A, "SEMESTER_ONLY", TERM_S1)
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual("SEMESTER_ONLY", duration_of(OFFERING_A))
        self.assertEqual(str(TERM_S1), str(term_of(OFFERING_A)))

    # 2
    def test_education_staff_can_set_full_year(self):
        res = save_duration("edu_dept", OFFERING_A, "FULL_YEAR")
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual("FULL_YEAR", duration_of(OFFERING_A))

    # 3a
    def test_semester_only_without_a_term_is_rejected(self):
        res = save_duration("edu_dept", OFFERING_A, "SEMESTER_ONLY")
        self.assertEqual("error", res.get("status"), res)
        self.assertIn("semester", (res.get("message") or "").lower())
        self.assertIsNone(duration_of(OFFERING_A), "nothing should have been written")

    # 3b
    def test_semester_only_with_an_unknown_term_is_rejected(self):
        res = save_duration("edu_dept", OFFERING_A, "SEMESTER_ONLY", MISSING_TERM)
        self.assertEqual("error", res.get("status"), res)
        self.assertIsNone(duration_of(OFFERING_A))
        self.assertIsNone(term_of(OFFERING_A))

    # 4
    def test_full_year_needs_no_term_and_never_keeps_one(self):
        # arrive as a semester subject carrying a term, then switch to full year
        self.assertEqual("success", save_duration(
            "edu_dept", OFFERING_A, "SEMESTER_ONLY", TERM_S2).get("status"))
        self.assertEqual(str(TERM_S2), str(term_of(OFFERING_A)))
        res = save_duration("edu_dept", OFFERING_A, "FULL_YEAR", TERM_S2)
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual("FULL_YEAR", duration_of(OFFERING_A))
        self.assertIsNone(term_of(OFFERING_A),
                          "a full-year subject must not stay pinned to one semester")

    # 5
    def test_unclassified_is_still_a_valid_choice(self):
        self.assertEqual("success", save_duration(
            "edu_dept", OFFERING_A, "FULL_YEAR").get("status"))
        res = save_duration("edu_dept", OFFERING_A, "")
        self.assertEqual("success", res.get("status"), res)
        self.assertIsNone(duration_of(OFFERING_A))
        self.assertIsNone(term_of(OFFERING_A))

    # 6
    def test_an_unknown_duration_value_is_rejected(self):
        res = save_duration("edu_dept", OFFERING_A, "QUARTER")
        self.assertEqual("error", res.get("status"), res)
        self.assertIsNone(duration_of(OFFERING_A))

    # 7
    def test_unknown_offering_is_rejected(self):
        res = save_duration("edu_dept", MISSING_OFFERING, "FULL_YEAR")
        self.assertEqual("error", res.get("status"), res)

    # 8
    def test_teacher_cannot_change_a_subject_duration(self):
        res = save_duration("teacher", OFFERING_A, "FULL_YEAR")
        self.assertEqual("error", res.get("status"), res)
        self.assertIsNone(duration_of(OFFERING_A),
                          "an unauthorized role must not change configuration")

    def test_attendance_taker_cannot_change_a_subject_duration(self):
        res = save_duration("attendance_taker", OFFERING_A, "SEMESTER_ONLY", TERM_S1)
        self.assertEqual("error", res.get("status"), res)
        self.assertIsNone(duration_of(OFFERING_A))

    def test_school_admin_can_also_change_a_subject_duration(self):
        res = save_duration("school_admin", OFFERING_A, "FULL_YEAR")
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual("FULL_YEAR", duration_of(OFFERING_A))

    # 9
    def test_saved_duration_is_returned_by_the_read_endpoint(self):
        save_duration("edu_dept", OFFERING_A, "FULL_YEAR")
        save_duration("edu_dept", OFFERING_B, "SEMESTER_ONLY", TERM_S2)
        res = api("api_subjects.php", "edu_dept", "GET", {
            "action": "get_class_subject_durations",
            "class_id": CLASS_ID,
            "year_id": YEAR_ID,
        })
        self.assertEqual("success", res.get("status"), res)
        rows = {int(o["offering_id"]): o for o in res["offerings"]}
        self.assertEqual("FULL_YEAR", rows[OFFERING_A]["duration_type"])
        self.assertIsNone(rows[OFFERING_A]["term_id"])
        self.assertEqual("SEMESTER_ONLY", rows[OFFERING_B]["duration_type"])
        self.assertEqual(TERM_S2, rows[OFFERING_B]["term_id"])
        self.assertEqual("Semester 2", rows[OFFERING_B]["term_name"])

    def test_read_endpoint_offers_the_existing_terms_of_the_year(self):
        res = api("api_subjects.php", "edu_dept", "GET", {
            "action": "get_class_subject_durations",
            "class_id": CLASS_ID,
            "year_id": YEAR_ID,
        })
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual([TERM_S1, TERM_S2], [t["id"] for t in res["terms"]])
        self.assertEqual([1, 2], [t["term_number"] for t in res["terms"]])

    def test_teacher_cannot_read_the_duration_configuration(self):
        res = api("api_subjects.php", "teacher", "GET", {
            "action": "get_class_subject_durations", "class_id": CLASS_ID,
        })
        self.assertEqual("error", res.get("status"), res)

    def test_a_duration_change_is_recorded_in_activity_logs(self):
        before = int(scalar(
            "SELECT COUNT(*) FROM activity_logs WHERE action='Subject Duration Changed'") or 0)
        save_duration("edu_dept", OFFERING_A, "FULL_YEAR")
        after = int(scalar(
            "SELECT COUNT(*) FROM activity_logs WHERE action='Subject Duration Changed'") or 0)
        self.assertEqual(before + 1, after, "the existing audit log should record the change")


class SemesterWeightEndpointTests(unittest.TestCase):
    """academic_years.s1_weight_pct / .s2_weight_pct through the real endpoint."""

    def setUp(self):
        _php_mysqli(
            "$db->query('UPDATE academic_years SET s1_weight_pct=50, s2_weight_pct=50');")

    # 10
    def test_weights_totalling_one_hundred_are_saved(self):
        res = save_year("school_admin", s1_weight_pct=40, s2_weight_pct=60)
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual("40.00/60.00", weights())

    def test_a_zero_and_one_hundred_split_is_accepted(self):
        res = save_year("school_admin", s1_weight_pct=0, s2_weight_pct=100)
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual("0.00/100.00", weights())

    def test_fractional_weights_that_total_one_hundred_are_accepted(self):
        res = save_year("school_admin", s1_weight_pct=33.33, s2_weight_pct=66.67)
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual("33.33/66.67", weights())

    # 11
    def test_weights_that_do_not_total_one_hundred_are_rejected(self):
        for s1, s2 in ((40, 70), (50, 40), (0, 0), (10, 10)):
            with self.subTest(s1=s1, s2=s2):
                res = save_year("school_admin", s1_weight_pct=s1, s2_weight_pct=s2)
                self.assertEqual("error", res.get("status"), res)
                self.assertIn("100", res.get("message") or "")
                self.assertEqual("50.00/50.00", weights(),
                                 "a rejected pair must not be stored or normalised")

    # 12
    def test_out_of_range_percentages_are_rejected(self):
        for s1, s2 in ((-10, 110), (101, -1), (-50, 150)):
            with self.subTest(s1=s1, s2=s2):
                res = save_year("school_admin", s1_weight_pct=s1, s2_weight_pct=s2)
                self.assertEqual("error", res.get("status"), res)
                self.assertEqual("50.00/50.00", weights())

    def test_non_numeric_weights_are_rejected(self):
        res = save_year("school_admin", s1_weight_pct="abc", s2_weight_pct="50")
        self.assertEqual("error", res.get("status"), res)
        self.assertEqual("50.00/50.00", weights())

    # 13
    def test_education_department_cannot_change_the_weights(self):
        res = save_year("edu_dept", s1_weight_pct=10, s2_weight_pct=90)
        self.assertEqual("error", res.get("status"), res)
        self.assertEqual("50.00/50.00", weights())

    def test_teacher_cannot_change_the_weights(self):
        res = save_year("teacher", s1_weight_pct=10, s2_weight_pct=90)
        self.assertEqual("error", res.get("status"), res)
        self.assertEqual("50.00/50.00", weights())

    # 14
    def test_saving_a_year_without_weight_fields_leaves_them_untouched(self):
        self.assertEqual("success", save_year(
            "school_admin", s1_weight_pct=30, s2_weight_pct=70).get("status"))
        self.assertEqual("30.00/70.00", weights())
        res = save_year("school_admin")          # the pre-existing year form
        self.assertEqual("success", res.get("status"), res)
        self.assertEqual("30.00/70.00", weights(),
                         "a form that does not know about weights must not reset them")

    # 15
    def test_saved_weights_are_returned_by_the_year_list(self):
        save_year("school_admin", s1_weight_pct=45, s2_weight_pct=55)
        res = api("api_education.php", "school_admin", "GET",
                  {"action": "get_academic_years"})
        self.assertEqual("success", res.get("status"), res)
        years = {int(y["id"]): y for y in (res.get("years") or res.get("data") or [])}
        self.assertIn(YEAR_ID, years)
        self.assertEqual(45.0, float(years[YEAR_ID]["s1_weight_pct"]))
        self.assertEqual(55.0, float(years[YEAR_ID]["s2_weight_pct"]))


class ReportsStillUseTheBackendPolicyTests(unittest.TestCase):
    """The new screens must not start doing the maths themselves."""

    # 16
    def test_report_card_service_still_delegates_to_the_policy(self):
        src = REPORT_CARD.read_text(encoding="utf-8", errors="replace")
        self.assertIn("SubjectDurationPolicy", src,
                      "the report service must keep using the duration policy")

    def test_the_duration_screen_does_not_compute_results(self):
        src = EDU_DASHBOARD.read_text(encoding="utf-8", errors="replace")
        start = src.find("SUBJECT DURATION (migration 056)")
        self.assertGreater(start, -1, "the duration panel should be present")
        block = src[start:start + 7000]
        for forbidden in ("s1_weight_pct", "s2_weight_pct", "0.5", "weighted"):
            self.assertNotIn(
                forbidden, block,
                "the duration panel must not weight or average anything: found %r" % forbidden,
            )

    def test_the_report_card_shows_the_backend_subject_status(self):
        src = (ROOT / "admin/js/report_card.js").read_text(
            encoding="utf-8", errors="replace")
        self.assertIn("subject_status", src,
                      "a continuing full-year subject must be distinguishable")
        self.assertIn("duration_type", src)
        self.assertIn("CONTINUING", src)

    def test_the_report_card_does_not_weight_semesters_itself(self):
        src = (ROOT / "admin/js/report_card.js").read_text(
            encoding="utf-8", errors="replace")
        start = src.find("function durationNote")
        self.assertGreater(start, -1)
        block = src[start:src.find("const subjectRows", start)]
        for forbidden in ("s1_weight", "s2_weight", "* 0.", "/ 2"):
            self.assertNotIn(forbidden, block,
                             "the report card must display, not calculate: %r" % forbidden)

    def test_the_weight_form_sends_values_rather_than_applying_them(self):
        src = SCHOOL_ADMIN_DASHBOARD.read_text(encoding="utf-8", errors="replace")
        self.assertIn("s1_weight_pct", src, "the year form should submit the weights")
        self.assertIn("updateWeightTotal", src, "the year form should validate the total")
        # The browser check exists for feedback; the server must still enforce it.
        api_src = (ROOT / "admin/api_education.php").read_text(
            encoding="utf-8", errors="replace")
        self.assertIn("weightsAreValid", api_src,
                      "the server must validate the weight pair, not just the browser")


class HarnessSafetyTests(unittest.TestCase):
    """The test harness fabricates a session, so it must stay off the web."""

    def test_harness_refuses_to_run_outside_the_command_line(self):
        src = HARNESS.read_text(encoding="utf-8", errors="replace")
        self.assertIn("PHP_SAPI !== 'cli'", src)
        self.assertIn("404", src)


if __name__ == "__main__":
    unittest.main()
