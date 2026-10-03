"""
Schema conformance — the tracking code must run on the schema production
actually has, not the one the fixture invents.

Why this file exists
--------------------
Academic Tracking Phases 3-5 shipped queries against two columns that do
not exist in production:

  * `assessments.is_active` — invented by this project's own e2e fixture.
    The canonical table (admin/migrations/003_add_assessments.php) and the
    production database both carry `is_published` and nothing else. No
    migration in sql/ ever added `is_active`, and no established query
    filters assessments by any such flag.
  * `class_subjects.duration_type` / `.term_id` — added by migration 056,
    which had not been applied to production.

Both produced `mysqli_sql_exception: Unknown column ...`, which the
api_education.php catch-all turned into "Could not load this teacher /
subject / class right now." Teacher, Subject, Class AND Student tracking
were all down, along with the whole report-card subsystem.

Every one of the 1958 tests passed throughout, because the fixture built a
post-056 schema with the invented column. The suite was verifying the code
against itself.

These tests close that gap by seeding a deliberately PRE-056 database
(SSMS_FIXTURE_PRE056=1) that mirrors the production shape, and driving the
real endpoints against it. A query that references a column production
lacks fails here instead of in production.

Guard-the-guard
---------------
A test that proves "it works without the 056 columns" is worthless if the
columns were never missing, and a test that proves "the code no longer
filters is_active" is worthless if assessments were never read at all. So
each negative is paired with a positive:

  * the pre-056 database is asserted to genuinely LACK the columns;
  * the normal e2e database is asserted to genuinely HAVE them;
  * the duration value is asserted to be read for real post-056 and to be
    NULL (unclassified, not invented) pre-056;
  * the endpoints are asserted to return real rows, not empty successes.

Requires: php CLI, .fkss_env.php, and a disposable pre-056 database
          $SSMS_PRE056_DB (default ssms_pre056_e2e) -- never production.
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
ENV_FILE = ROOT / ".fkss_env.php"
SERVICE = ROOT / "admin" / "backend" / "services" / "AcademicTrackingService.php"
REPORTCARD = ROOT / "admin" / "backend" / "services" / "ReportCardService.php"
API_SUBJECTS = ROOT / "admin" / "api_subjects.php"
CANONICAL = ROOT / "admin" / "migrations" / "003_add_assessments.php"
FIXTURE = ROOT / "tests" / "e2e" / "academic_intelligence.php"

SYNC_DB = os.environ.get("SSMS_SYNC_DB", "ssms_e2e")
PRE056_DB = os.environ.get("SSMS_PRE056_DB", "ssms_pre056_e2e")

# Fixture landmarks, same seed as the other tracking suites.
C1, C2 = 1, 2
S_GEEZ, S_MUSIC = 1, 2
T_BEKELE = 11
Y1 = 1


def _php_binary():
    override = os.environ.get("SSMS_E2E_PHP", "").strip()
    if override:
        return override if Path(override).is_file() else None
    return shutil.which("php")


def _probe_db(php, db):
    probe = (
        "require %s; "
        "$m = @new mysqli(DB_HOST, DB_USER, DB_PASS, %s); "
        "exit($m->connect_errno ? 3 : 0);" % (repr(str(ENV_FILE)), repr(db))
    )
    proc = subprocess.run([php, "-r", probe], capture_output=True, text=True, timeout=30)
    return proc.returncode == 0


class _Pre056Base(unittest.TestCase):
    """Seeds a production-shaped (pre-056) database once."""

    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — schema conformance skipped")
        if not RUNNER.is_file() or not ROLE_RUNNER.is_file():
            raise unittest.SkipTest("e2e runners not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(".fkss_env.php not present — schema conformance skipped")
        if not _probe_db(php, PRE056_DB):
            raise unittest.SkipTest(f"pre-056 database '{PRE056_DB}' unreachable — skipped")
        cls.php = php

        proc = subprocess.run(
            [php, str(RUNNER), "student_tracking_fixture"],
            capture_output=True, text=True, timeout=600, cwd=str(ROOT),
            env={**os.environ,
                 "SSMS_AUDIT_TESTING": "1",
                 "SSMS_FIXTURE_PRE056": "1",
                 "SSMS_DISPOSABLE_DB": PRE056_DB,
                 "SSMS_DB_NAME": PRE056_DB,
                 "SSMS_SYNC_DB": PRE056_DB},
        )
        if proc.returncode != 0:
            raise unittest.SkipTest(
                f"could not seed pre-056 fixture:\n{proc.stdout}\n{proc.stderr}"
            )

    def call(self, action, role="edu_dept", db=None, **params):
        db = db or PRE056_DB
        payload = {"action": action}
        payload.update({k: v for k, v in params.items() if v is not None})
        proc = subprocess.run(
            [self.php, str(ROLE_RUNNER), "api_education.php", role, "GET", json.dumps(payload)],
            capture_output=True, text=True, timeout=120, cwd=str(ROOT),
            env={**os.environ, "SSMS_DB_NAME": db, "SSMS_SYNC_DB": db},
        )
        lines = (proc.stdout or "").strip().splitlines()
        if not lines:
            self.fail(f"{action}: no output (exit {proc.returncode})\n{proc.stderr}")
        try:
            return json.loads(lines[-1])
        except json.JSONDecodeError:
            self.fail(f"{action}: non-JSON response: {lines[-1][:300]}")

    def columns(self, db, table):
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$r = $c->query(\"SELECT column_name FROM information_schema.columns"
            " WHERE table_schema = DATABASE() AND table_name = \" . \"'\" . %s . \"'\");"
            "$o = [];"
            "while ($x = $r->fetch_assoc()) { $o[] = $x['column_name']; }"
            "echo json_encode($o);"
            % (repr(str(ENV_FILE)), repr(db), repr(table))
        )
        proc = subprocess.run([self.php, "-r", script], capture_output=True,
                              text=True, timeout=60, cwd=str(ROOT))
        return {c.lower() for c in json.loads(proc.stdout.strip() or "[]")}


class SchemaShape(_Pre056Base):
    """The two databases really do differ, so the rest of the file means something."""

    def test_the_pre056_database_genuinely_lacks_the_056_columns(self):
        cols = self.columns(PRE056_DB, "class_subjects")
        self.assertNotIn("duration_type", cols)
        self.assertNotIn("term_id", cols)
        # positive half: the table exists and carries its real columns
        self.assertIn("class_id", cols)
        self.assertIn("subject_id", cols)

    def test_the_normal_e2e_database_genuinely_has_the_056_columns(self):
        if not _probe_db(self.php, SYNC_DB):
            self.skipTest(f"{SYNC_DB} unreachable")
        cols = self.columns(SYNC_DB, "class_subjects")
        self.assertIn("duration_type", cols,
                      "post-056 fixture lost its columns; the pre-056 test is vacuous")
        self.assertIn("term_id", cols)

    def test_assessments_has_is_published_and_never_is_active(self):
        cols = self.columns(PRE056_DB, "assessments")
        self.assertIn("is_published", cols)
        self.assertNotIn("is_active", cols,
                         "the fixture re-invented a column production does not have")

    def test_the_fixture_matches_the_canonical_migration(self):
        """The fixture's assessments table may not invent columns."""
        canon = set(re.findall(r"^\s*`(\w+)`\s+(?:INT|VARCHAR|DECIMAL|ENUM|TEXT|DATE|TINYINT|TIMESTAMP)",
                               CANONICAL.read_text(encoding="utf-8"), re.M | re.I))
        self.assertIn("is_published", {c.lower() for c in canon})
        self.assertNotIn("is_active", {c.lower() for c in canon})
        fixture_cols = self.columns(PRE056_DB, "assessments")
        invented = fixture_cols - {c.lower() for c in canon}
        self.assertEqual(set(), invented,
                         f"fixture invents columns absent from the canonical migration: {invented}")


class EndpointsSurviveAPre056Database(_Pre056Base):
    """The outage, as a test. Each of these returned server_error in production."""

    def _ok(self, payload, action):
        self.assertNotEqual("error", payload.get("status"),
                            f"{action} failed on a pre-056 database: {payload}")
        self.assertNotEqual("server_error", payload.get("code"),
                            f"{action} raised the catch-all: {payload}")

    def test_teacher_detail_loads(self):
        # The production failure: assessmentCounts() filtered assessments.is_active.
        r = self.call("tracking_teacher_detail", teacher_id=T_BEKELE, year_id=Y1)
        self._ok(r, "tracking_teacher_detail")
        self.assertTrue(r.get("assignments"), "teacher has assignments in the fixture")

    def test_subject_detail_loads(self):
        # The production failure: subjectOfferings() selected cs.duration_type.
        r = self.call("tracking_subject_detail", subject_id=S_GEEZ, year_id=Y1)
        self._ok(r, "tracking_subject_detail")
        self.assertTrue(r.get("offerings"), "GEEZ is offered to two classes in the fixture")

    def test_class_detail_loads(self):
        r = self.call("tracking_class_detail", class_id=C1, year_id=Y1)
        self._ok(r, "tracking_class_detail")

    def test_class_subjects_loads(self):
        # The production failure: classSubjects() selected cs.duration_type.
        r = self.call("tracking_class_subjects", class_id=C1, year_id=Y1)
        self._ok(r, "tracking_class_subjects")
        self.assertTrue(r.get("subjects"), "C1 offers two subjects in the fixture")

    def test_class_assessments_loads(self):
        r = self.call("tracking_class_assessments", class_id=C1, year_id=Y1)
        self._ok(r, "tracking_class_assessments")

    def test_class_teachers_and_students_load(self):
        self._ok(self.call("tracking_class_teachers", class_id=C1, year_id=Y1),
                 "tracking_class_teachers")
        self._ok(self.call("tracking_class_students", class_id=C1, year_id=Y1),
                 "tracking_class_students")

    def test_student_detail_loads(self):
        # Student Tracking reads ReportCardService::fetchSubjects, whose
        # pre-056 fallback was unreachable.
        r = self.call("tracking_student_detail", member_id=101, class_id=C1, year_id=Y1)
        self._ok(r, "tracking_student_detail")


class AssessmentsAreActuallyCounted(_Pre056Base):
    """Removing the is_active filter must not quietly zero the counts."""

    def test_the_assessment_count_is_a_real_count(self):
        r = self.call("tracking_class_assessments", class_id=C1, year_id=Y1)
        rows = r.get("assessments") or r.get("rows") or []
        self.assertTrue(rows, "C1 has four assessments in the fixture; got none")

    def test_the_teacher_assessment_count_is_not_zero(self):
        r = self.call("tracking_teacher_detail", teacher_id=T_BEKELE, year_id=Y1)
        counts = [a.get("assessment_count") for a in r.get("assignments", [])
                  if a.get("subject_id") is not None]
        self.assertTrue(any((c or 0) > 0 for c in counts),
                        f"every assessment count is zero/None: {counts}")


class DurationDegradesWithoutInventingValues(_Pre056Base):
    """Pre-056 means unclassified, which is NULL — never a guessed default."""

    def test_duration_is_null_pre056(self):
        r = self.call("tracking_class_subjects", class_id=C1, year_id=Y1)
        for row in r.get("subjects", []):
            self.assertIsNone(row.get("duration_type"),
                              "a pre-056 database must report duration as unclassified, "
                              f"not {row.get('duration_type')!r}")

    def test_duration_is_read_for_real_post056(self):
        """Guard-the-guard: prove the NULL above is degradation, not a dead path."""
        if not _probe_db(self.php, SYNC_DB):
            self.skipTest(f"{SYNC_DB} unreachable")
        seed = subprocess.run(
            [self.php, str(RUNNER), "student_tracking_fixture"],
            capture_output=True, text=True, timeout=600, cwd=str(ROOT),
            env={**os.environ, "SSMS_AUDIT_TESTING": "1", "SSMS_SYNC_DB": SYNC_DB,
                 "SSMS_DB_NAME": SYNC_DB},
        )
        if seed.returncode != 0:
            self.skipTest("could not seed the post-056 fixture")
        r = self.call("tracking_class_subjects", class_id=C1, year_id=Y1, db=SYNC_DB)
        durations = {row.get("subject_id"): row.get("duration_type")
                     for row in r.get("subjects", [])}
        self.assertEqual("FULL_YEAR", durations.get(S_GEEZ),
                         f"post-056 duration was not read: {durations}")
        self.assertEqual("SEMESTER_ONLY", durations.get(S_MUSIC))


class TheBrokenIdiomIsGone(unittest.TestCase):
    """
    `@$conn->prepare($sql)` + `if (!$stmt)` cannot detect a missing column:
    `@` suppresses diagnostics, not exceptions, and mysqli throws by default
    on PHP 8.1+. Any reintroduction silently restores the outage.
    """

    def _sources(self):
        return {p: p.read_text(encoding="utf-8")
                for p in (SERVICE, REPORTCARD, API_SUBJECTS)}

    def test_no_056_column_is_prepared_behind_an_at_suppressor(self):
        offenders = []
        for path, src in self._sources().items():
            for m in re.finditer(r"@\$\w+->prepare\s*\((.{0,400}?)\)\s*;", src, re.S):
                if "duration_type" in m.group(1) or "s1_weight_pct" in m.group(1):
                    line = src[:m.start()].count("\n") + 1
                    offenders.append(f"{path.name}:{line}")
        self.assertEqual([], offenders,
                         "the @prepare idiom cannot detect a pre-056 database: "
                         + ", ".join(offenders))

    def test_the_056_readers_ask_the_database_first(self):
        """Every file reading the 056 columns must consult the capability probe."""
        for path, src in self._sources().items():
            if "duration_type" not in src:
                continue
            self.assertIn("supportsOfferingDuration", src,
                          f"{path.name} reads migration-056 columns without checking "
                          "whether the database has them")

    def test_assessments_are_never_filtered_by_is_active(self):
        """The column does not exist. Guard against reintroduction."""
        src = SERVICE.read_text(encoding="utf-8")
        hits = []
        for m in re.finditer(r"FROM\s+assessments\b(?:\s+(\w+))?(.{0,400}?)(?:'|\");", src, re.S):
            alias = m.group(1) or ""
            body = m.group(2)
            if re.search(r"\b(?:%s\.)?is_active\b" % re.escape(alias) if alias else r"\bis_active\b", body):
                hits.append(src[:m.start()].count("\n") + 1)
        self.assertEqual([], hits,
                         f"assessments.is_active does not exist in production; lines {hits}")


if __name__ == "__main__":
    unittest.main()
