"""
Academic Tracking — Phase 1 root lists.

Phase 1 builds the navigation layer of Academic Tracking: four entry points
(Students, Teachers, Subjects, Classes), each with a searchable, sortable,
server-paginated list, and an explicit selection step. No tracking detail,
no academic calculation.

These tests cover the backend half of that. They drive the four real list
endpoints against the live fixture database through
tests/e2e/education_config_api.php, which is the existing harness for
calling an admin endpoint with a session role; no second harness is
introduced. The frontend half is covered by the node harness
tests/e2e/academic_tracking_lists.js, which is run from here too so that a
single pytest invocation checks both sides.

What is being defended:

  * The list endpoints really page on the server. The total describes the
    whole query, not the rows that came back.
  * Search narrows the total, it does not filter a page that was already
    fetched.
  * Sorting is chosen from an allowlist, so a column name from the query
    string can never reach the SQL.
  * Paging is bounded, so per_page=99999 cannot be used to pull the school.
  * The pre-existing authorization of each endpoint is unchanged. Phase 1
    reuses these endpoints and grants nobody any access they did not
    already have.
  * Extending get_classes and get_subjects did not change what their
    existing callers see.

Requires: php CLI, .fkss_env.php and the dedicated e2e database
          $SSMS_SYNC_DB (default ssms_e2e) -- never production.
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
LIST_HARNESS = ROOT / "tests" / "e2e" / "academic_tracking_lists.js"
CONTROLLER = ROOT / "admin" / "js" / "academic_tracking.js"
DASHBOARD = ROOT / "admin" / "dashboards" / "edu_dept.php"
API_EDU = ROOT / "admin" / "api_education.php"
API_SUBJ = ROOT / "admin" / "api_subjects.php"
ENV_FILE = ROOT / ".fkss_env.php"

SYNC_DB = os.environ.get("SSMS_SYNC_DB", "ssms_e2e")

# The three education roles that may open the Academic Tracking page.
EDU_ROLES = ("super_admin", "school_admin", "edu_dept")


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


class _ListBase(unittest.TestCase):
    """Seeds the bulk tracking fixture once, then calls endpoints as a role."""

    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — academic tracking e2e skipped")
        if not RUNNER.is_file() or not ROLE_RUNNER.is_file():
            raise unittest.SkipTest("e2e runners not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(".fkss_env.php not present — academic tracking e2e skipped")
        if not _probe_db(php):
            raise unittest.SkipTest(f"e2e database '{SYNC_DB}' unreachable — skipped")
        cls.php = php

        # tracking_list_fixture = base academic seed + 30 students + 12
        # teachers, which is what makes paging and sorting observable.
        proc = subprocess.run(
            [php, str(RUNNER), "tracking_list_fixture"],
            capture_output=True, text=True, timeout=600, cwd=str(ROOT),
            env={**os.environ, "SSMS_AUDIT_TESTING": "1", "SSMS_SYNC_DB": SYNC_DB},
        )
        if proc.returncode != 0:
            raise unittest.SkipTest(f"could not seed tracking fixture:\n{proc.stdout}\n{proc.stderr}")

    def call(self, api, action, role="edu_dept", **params):
        payload = {"action": action}
        payload.update({k: v for k, v in params.items() if v is not None})
        proc = subprocess.run(
            [self.php, str(ROLE_RUNNER), api, role, "GET", json.dumps(payload)],
            capture_output=True, text=True, timeout=120, cwd=str(ROOT),
            env={**os.environ, "SSMS_DB_NAME": SYNC_DB, "SSMS_SYNC_DB": SYNC_DB},
        )
        line = (proc.stdout or "").strip().splitlines()
        if not line:
            self.fail(f"{action}: no output (exit {proc.returncode})\n{proc.stderr}")
        try:
            return json.loads(line[-1])
        except json.JSONDecodeError:
            self.fail(f"{action}: non-JSON response: {line[-1][:300]}")

    # convenience wrappers for the four root lists
    def students(self, role="edu_dept", **kw):
        return self.call("api_education.php", "roster", role, **kw)

    def teachers(self, role="edu_dept", **kw):
        return self.call("api_education.php", "list_teachers", role, **kw)

    def classes(self, role="edu_dept", **kw):
        return self.call("api_education.php", "get_classes", role, **kw)

    def subjects(self, role="edu_dept", **kw):
        return self.call("api_subjects.php", "get_subjects", role, **kw)


class StudentsRootListTests(_ListBase):
    """The students entry point, served by the existing school-wide roster."""

    def test_list_loads_with_a_server_side_page(self):
        d = self.students(per_page=10, page=1)
        self.assertEqual("success", d["status"])
        self.assertEqual(45, d["total"], "total must describe the whole query")
        self.assertEqual(10, len(d["rows"]), "the server, not the browser, limits the page")
        self.assertEqual(5, d["pages"])

    def test_total_is_independent_of_page_size(self):
        """A count taken from the rendered page would change here; a real one does not."""
        for per_page in (10, 25, 100):
            with self.subTest(per_page=per_page):
                d = self.students(per_page=per_page)
                self.assertEqual(45, d["total"])

    def test_paging_walks_the_whole_list_without_gaps_or_repeats(self):
        seen = []
        for page in range(1, 6):
            d = self.students(per_page=10, page=page)
            self.assertEqual(page, d["page"])
            seen.extend(r["id"] for r in d["rows"])
        self.assertEqual(45, len(seen), "every row appears exactly once across the pages")
        self.assertEqual(45, len(set(seen)), "no row is served on two pages")

    def test_final_page_is_the_remainder(self):
        d = self.students(per_page=10, page=5)
        self.assertEqual(5, len(d["rows"]))

    def test_search_narrows_the_total_not_just_the_page(self):
        d = self.students(q="Bulk Student 1", per_page=10)
        # "Bulk Student 1" is a substring of 10..19 only -- the zero padding
        # means "Bulk Student 01" does not contain it. Ten of forty-five.
        self.assertEqual(10, d["total"])
        self.assertEqual(10, len(d["rows"]))
        self.assertTrue(all("Bulk Student 1" in r["student_name"] for r in d["rows"]))

    def test_search_that_matches_nothing_is_an_honest_zero(self):
        d = self.students(q="no-such-student-zzz")
        self.assertEqual(0, d["total"])
        self.assertEqual([], d["rows"])
        self.assertEqual("success", d["status"], "an empty result is not an error")

    def test_class_filter_scopes_the_list(self):
        d = self.students(class_id=1, per_page=100)
        self.assertEqual(4, d["total"])
        self.assertTrue(all(r["class_id"] in (1, "1") for r in d["rows"]))

    def test_sort_is_chosen_from_an_allowlist(self):
        asc = self.students(sort="code", dir="asc", per_page=100)["rows"]
        desc = self.students(sort="code", dir="desc", per_page=100)["rows"]
        self.assertEqual([r["id"] for r in asc], list(reversed([r["id"] for r in desc])))

    def test_a_sort_column_from_the_query_string_cannot_reach_the_sql(self):
        baseline = [r["id"] for r in self.students(per_page=100)["rows"]]
        for attack in ("name' OR 1=1 --", "m.id; DROP TABLE members", "(SELECT 1)", "../../etc/passwd"):
            with self.subTest(attack=attack):
                d = self.students(sort=attack, per_page=100)
                self.assertEqual("success", d["status"])
                self.assertEqual(baseline, [r["id"] for r in d["rows"]],
                                 "an unknown sort must fall back to the default order")

    def test_search_is_a_bound_parameter(self):
        d = self.students(q="' OR '1'='1")
        self.assertEqual(0, d["total"], "a quote must be data, not syntax")

    def test_page_size_is_bounded(self):
        self.assertEqual(100, self.students(per_page=99999)["per_page"], "upper bound")
        self.assertEqual(10, self.students(per_page=-5)["per_page"], "lower bound")
        self.assertEqual(1, self.students(page=-1, per_page=10)["page"])

    def test_the_list_is_not_an_academic_calculation(self):
        """A root list must not carry results; that is Phase 2's job."""
        row = self.students(per_page=10)["rows"][0]
        for forbidden in ("final_percentage", "average", "grade_letter", "rank", "pass_rate"):
            self.assertNotIn(forbidden, row, f"the students list leaked {forbidden}")


class TeachersRootListTests(_ListBase):
    """The teachers entry point, served by the existing list_teachers action."""

    def test_list_loads(self):
        d = self.teachers(per_page=10)
        self.assertEqual("success", d["status"])
        self.assertEqual(15, d["total"])
        self.assertEqual(10, len(d["teachers"]))
        self.assertEqual(2, d["pages"])

    def test_the_member_relationship_is_resolved(self):
        """users.member_id -> members.id is the production shape; prove both sides."""
        rows = self.teachers(per_page=100)["teachers"]
        by_name = {t["full_name"]: t for t in rows}

        linked = by_name["Bekele Tadesse"]
        self.assertEqual(901, int(linked["member_id"]))
        self.assertEqual("T-901", linked["member_code"])

        unlinked = by_name["Kebede Haile"]
        self.assertIsNone(unlinked["member_id"], "a login need not have a member record")
        self.assertEqual("", unlinked["member_code"])

    def test_a_teacher_without_a_member_record_is_not_dropped(self):
        """The join is LEFT; an inner join here would silently lose staff."""
        names = [t["full_name"] for t in self.teachers(per_page=100)["teachers"]]
        self.assertIn("Kebede Haile", names)
        self.assertEqual(15, len(names))

    def test_search_works(self):
        d = self.teachers(q="Bekele")
        self.assertEqual(1, d["total"])
        self.assertEqual("Bekele Tadesse", d["teachers"][0]["full_name"])

    def test_pagination_works(self):
        first = self.teachers(per_page=10, page=1)["teachers"]
        second = self.teachers(per_page=10, page=2)["teachers"]
        self.assertEqual(10, len(first))
        self.assertEqual(5, len(second))
        self.assertFalse({t["id"] for t in first} & {t["id"] for t in second})

    def test_assignment_filter_partitions_the_list(self):
        assigned = self.teachers(assigned="1", per_page=100)
        unassigned = self.teachers(assigned="0", per_page=100)
        self.assertEqual(2, assigned["total"])
        self.assertEqual(13, unassigned["total"])
        self.assertEqual(15, assigned["total"] + unassigned["total"],
                         "the two halves must account for every teacher exactly once")

    def test_empty_result_is_a_zero_not_an_error(self):
        d = self.teachers(q="no-such-teacher-zzz")
        self.assertEqual("success", d["status"])
        self.assertEqual(0, d["total"])
        self.assertEqual([], d["teachers"])

    def test_no_teacher_quality_or_ranking_is_exposed(self):
        """A standing product rule: teachers are never scored or ranked."""
        row = self.teachers(per_page=10)["teachers"][0]
        for forbidden in ("quality", "score", "rank", "effectiveness", "performance"):
            self.assertFalse(
                any(forbidden in k.lower() for k in row),
                f"the teachers list exposes a '{forbidden}' field",
            )


class SubjectsRootListTests(_ListBase):
    def test_list_loads(self):
        d = self.subjects()
        self.assertEqual("success", d["status"])
        self.assertEqual(3, d["total"])

    def test_class_count_is_a_relational_count(self):
        """Geez is offered by two classes; this must not cost a report card."""
        rows = self.subjects(per_page=100)["subjects"]
        geez = [s for s in rows if s["subject_name_en"] == "Geez"][0]
        self.assertEqual(2, int(geez["assigned_classes"]))

    def test_search_works(self):
        d = self.subjects(q="Music")
        self.assertEqual(1, d["total"])
        self.assertEqual("Music", d["subjects"][0]["subject_name_en"])

    def test_sort_by_class_count(self):
        rows = self.subjects(sort="classes", dir="desc", per_page=100)["subjects"]
        counts = [int(s["assigned_classes"]) for s in rows]
        self.assertEqual(sorted(counts, reverse=True), counts)

    def test_pagination_is_available(self):
        d = self.subjects(per_page=10, page=1)
        self.assertEqual(1, d["pages"])
        self.assertEqual(10, d["per_page"])

    def test_empty_result_is_a_zero(self):
        d = self.subjects(q="no-such-subject-zzz")
        self.assertEqual("success", d["status"])
        self.assertEqual(0, d["total"])

    def test_sort_injection_is_refused(self):
        baseline = [s["id"] for s in self.subjects(per_page=100)["subjects"]]
        d = self.subjects(sort="s.id; DROP TABLE subjects", per_page=100)
        self.assertEqual("success", d["status"])
        self.assertEqual(baseline, [s["id"] for s in d["subjects"]])

    def test_no_academic_result_is_carried(self):
        row = self.subjects()["subjects"][0]
        for forbidden in ("average", "final_percentage", "pass_rate", "grade_letter"):
            self.assertNotIn(forbidden, row)


class ClassesRootListTests(_ListBase):
    def test_list_loads(self):
        d = self.classes()
        self.assertEqual("success", d["status"])
        self.assertEqual(3, d["total"])

    def test_student_count_is_present_and_cheap(self):
        rows = self.classes(per_page=100)["classes"]
        counts = {c["class_code"]: int(c["student_count"]) for c in rows}
        self.assertEqual(4, counts["G4"])
        self.assertEqual(3, counts["G5"])
        self.assertEqual(30, counts["G6"], "the bulk cohort is enrolled in C3")

    def test_search_works(self):
        d = self.classes(q="Grade 5")
        self.assertEqual(1, d["total"])
        self.assertEqual("G5", d["classes"][0]["class_code"])

    def test_sort_by_student_count(self):
        rows = self.classes(sort="students", dir="desc", per_page=100)["classes"]
        self.assertEqual("G6", rows[0]["class_code"])

    def test_pagination_works(self):
        d = self.classes(per_page=10, page=1)
        self.assertEqual(3, d["total"])
        self.assertEqual(1, d["pages"])

    def test_empty_result_is_a_zero(self):
        d = self.classes(q="no-such-class-zzz")
        self.assertEqual("success", d["status"])
        self.assertEqual(0, d["total"])
        self.assertEqual([], d["classes"])

    def test_sort_injection_is_refused(self):
        baseline = [c["id"] for c in self.classes(per_page=100)["classes"]]
        d = self.classes(sort="c.id; DROP TABLE classes", per_page=100)
        self.assertEqual("success", d["status"])
        self.assertEqual(baseline, [c["id"] for c in d["classes"]])


class ListBackwardCompatibilityTests(_ListBase):
    """Extending a shared endpoint must not disturb its existing callers.

    get_classes is read by the class management screen and the HR
    registration dropdown; get_subjects is read by the smoke suite. Both are
    called with no parameters at all, so that call must behave exactly as it
    did before the list controls were added.
    """

    def test_get_classes_without_parameters_returns_every_class_in_level_order(self):
        d = self.classes()
        self.assertEqual("success", d["status"])
        self.assertEqual(3, len(d["classes"]), "no implicit paging for the old call")
        self.assertEqual(["G4", "G5", "G6"], [c["class_code"] for c in d["classes"]])

    def test_get_classes_still_carries_the_columns_the_management_screen_uses(self):
        row = self.classes()["classes"][0]
        for field in ("id", "class_name", "class_name_en", "class_code",
                      "level_order", "is_active", "student_count"):
            self.assertIn(field, row)

    def test_get_subjects_without_parameters_is_unchanged(self):
        d = self.subjects()
        self.assertEqual("success", d["status"])
        self.assertEqual(3, len(d["subjects"]))
        self.assertTrue(all(int(s["is_active"]) == 1 for s in d["subjects"]),
                        "the historic default is active subjects only")

    def test_include_inactive_still_means_what_it_meant(self):
        d = self.subjects(include_inactive="1")
        self.assertEqual("success", d["status"])
        self.assertGreaterEqual(len(d["subjects"]), 3)

    def test_the_new_envelope_keys_are_additive(self):
        for payload in (self.classes(), self.subjects()):
            for key in ("total", "page", "per_page", "pages"):
                self.assertIn(key, payload)


class ListAuthorizationTests(_ListBase):
    """Phase 1 grants no new access.

    Every root list is an endpoint that already existed with its own gate.
    These checks pin the gates that are enforced inside the API files, which
    is the layer a CLI harness can observe: access_control.php deliberately
    exempts the command line, so the file-level ROLE_MAP is asserted by
    reading it rather than by calling through it.
    """

    def test_the_three_education_roles_can_read_every_root_list(self):
        for role in EDU_ROLES:
            for name, fn in (("students", self.students), ("teachers", self.teachers),
                             ("subjects", self.subjects), ("classes", self.classes)):
                with self.subTest(role=role, list=name):
                    self.assertEqual("success", fn(role=role)["status"])

    def test_hr_is_confined_to_the_class_catalogue(self):
        """HR needs the class dropdown for registration and nothing else."""
        self.assertEqual("success", self.classes(role="hr_dept")["status"])
        for name, fn in (("students", self.students), ("teachers", self.teachers)):
            with self.subTest(list=name):
                d = fn(role="hr_dept")
                self.assertEqual("error", d["status"])
                self.assertNotIn("rows", d)
                self.assertNotIn("teachers", d)

    def test_the_file_level_role_map_still_guards_these_endpoints(self):
        guard = (ROOT / "admin" / "access_control.php").read_text(encoding="utf-8")
        self.assertIn(
            "'api_education.php' => ['super_admin', 'school_admin', 'edu_dept', "
            "'hr_dept', 'teacher', 'attendance_taker']",
            guard,
            "the education API must stay behind the file-level role map",
        )
        self.assertIn(
            "'api_subjects.php'  => ['super_admin', 'school_admin', 'edu_dept', 'teacher']",
            guard,
        )
        self.assertIn("'edu_dept.php'      => ['super_admin', 'school_admin', 'edu_dept']", guard)

    def test_the_tracking_ui_lives_on_a_page_restricted_to_education_roles(self):
        guard = (ROOT / "admin" / "access_control.php").read_text(encoding="utf-8")
        self.assertIn("'edu_dept.php'      => ['super_admin', 'school_admin', 'edu_dept']", guard)
        self.assertIn('id="sec-academic-tracking"', DASHBOARD.read_text(encoding="utf-8"))

    def test_the_analytics_tier_is_untouched_by_phase_1(self):
        src = API_EDU.read_text(encoding="utf-8")
        self.assertIn("$__analyticsActions = [", src)
        for action in ("get_education_hub", "filter_students_performance",
                       "get_academic_intelligence", "get_academic_intelligence_options"):
            self.assertIn(f"'{action}'", src)
        self.assertNotIn("'roster',\n", src.split("$__analyticsActions = [")[1].split("]")[0],
                         "Phase 1 must not quietly re-tier a shared endpoint")

    def test_no_second_authorization_system_was_introduced(self):
        """
        The browser must not make authorization decisions.

        Comments are stripped before the check — the same thing
        test_the_controller_never_indexes_the_first_row already does.
        A comment that says "the server checks canViewClass" documents
        where the decision is made; it does not make one. The ban on the
        tokens appearing in executable code is unchanged.
        """
        import re
        src = CONTROLLER.read_text(encoding="utf-8")
        code = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
        code = re.sub(r"(?m)^\s*//.*$", "", code)
        for forbidden in ("admin_role", "super_admin", "school_admin", "edu_dept", "canViewClass"):
            self.assertNotIn(forbidden, code,
                             "the browser must not make authorization decisions")


class TrackingControllerBehaviourTests(unittest.TestCase):
    """Runs the node behavioural harness for the root-list controller."""

    @classmethod
    def setUpClass(cls):
        if not shutil.which("node"):
            raise unittest.SkipTest("node not available — tracking controller harness skipped")
        if not LIST_HARNESS.is_file():
            raise unittest.SkipTest("tests/e2e/academic_tracking_lists.js not present")

    def test_root_list_behaviour(self):
        run = subprocess.run(
            ["node", str(LIST_HARNESS)],
            capture_output=True, text=True, timeout=300, cwd=str(ROOT),
        )
        self.assertEqual(0, run.returncode,
                         f"tracking list harness failed:\n{run.stdout}\n{run.stderr}")
        self.assertIn("0 failed", run.stdout)


class TrackingContractTests(unittest.TestCase):
    """Source-level pins for the rules that have no runtime observation."""

    @classmethod
    def setUpClass(cls):
        if not CONTROLLER.is_file():
            raise unittest.SkipTest("academic_tracking.js not present")
        cls.src = CONTROLLER.read_text(encoding="utf-8")

    def test_the_controller_performs_no_academic_calculation(self):
        """
        ReportCardService is the only calculation engine; JS must not compute.

        Phase 1 could enforce this by banning the vocabulary outright,
        because the root lists never touched an academic field. Phase 2
        legitimately *displays* pass_mark, grade_letter, final_percentage
        and assessment weight, so banning the words would now forbid the
        feature rather than the defect.

        The rule being enforced has not changed and has not been relaxed:
        the controller may render a number the engine produced, and may
        not derive one. So the ban moves from the nouns to the arithmetic
        — every way a grade could actually be recomputed in the browser.
        """
        import re
        code = re.sub(r"/\*.*?\*/", "", self.src, flags=re.S)
        code = re.sub(r"(?m)^\s*//.*$", "", code)
        forbidden = {
            "a grade letter threshold":
                r">=\s*(?:90|80|70|60)\b",
            "a hard-coded pass mark":
                r"(?:PASS_MARK|pass_mark)\s*[:=]\s*\d",
            "a weighted combination":
                r"\*\s*(?:\w+\.)?weight\b|\bweight\s*\*",
            "weight used as a divisor":
                r"\bweight\s*/",
            "an average folded in the browser":
                r"\.reduce\s*\(",
            "an assignment to final_percentage":
                r"final_percentage\s*=[^=]",
            "an assignment to a semester average":
                r"semester_[12]_average\s*=[^=]",
        }
        for name, pattern in forbidden.items():
            with self.subTest(calculation=name):
                hit = re.search(pattern, code)
                self.assertIsNone(
                    hit, f"the controller computes a result itself: {name} "
                         f"({hit.group(0) if hit else ''!r})")

    def test_the_controller_still_only_displays_engine_numbers(self):
        """
        The companion to the test above: proof that the academic numbers
        on screen arrive from a payload rather than from local state.
        Every academic field must be read off a response object, never
        initialised as a literal in the controller.
        """
        import re
        code = re.sub(r"/\*.*?\*/", "", self.src, flags=re.S)
        code = re.sub(r"(?m)^\s*//.*$", "", code)
        for field in ("pass_mark", "grade_letter", "final_percentage",
                      "semester_1_score", "semester_2_score"):
            with self.subTest(field=field):
                self.assertIsNotNone(
                    re.search(r"[\w\]\)]\.\s*" + field + r"\b", code),
                    f"{field} should be read from a server payload")
                self.assertIsNone(
                    re.search(field + r"\s*[:=]\s*(?:\d|'|\")", code),
                    f"{field} must never be given a literal value in JS")

    def test_the_controller_never_indexes_the_first_row(self):
        """The auto-selection bug of the first attempt, pinned shut."""
        import re
        code = re.sub(r"/\*.*?\*/", "", self.src, flags=re.S)
        code = re.sub(r"^\s*//.*$", "", code, flags=re.M)
        for pattern in ("rows[0]", "items[0]", "classes[0]", "teachers[0]", "subjects[0]"):
            self.assertNotIn(pattern, code,
                             f"{pattern} is how the first attempt selected for the user")

    def test_the_four_entry_points_are_declared_in_order(self):
        self.assertIn("var ORDER = ['students', 'teachers', 'subjects', 'classes'];", self.src)

    def test_no_parallel_api_file_was_created(self):
        """Phase 1 reuses existing endpoints; it must not invent a new one."""
        self.assertIn("action=roster", self.src)
        self.assertIn("action=list_teachers", self.src)
        self.assertIn("action=get_classes", self.src)
        self.assertIn("action=get_subjects", self.src)
        self.assertNotIn("api_academic_tracking.php", self.src)
        self.assertFalse((ROOT / "admin" / "api_academic_tracking.php").exists(),
                         "no parallel API file may exist")

    def test_the_dashboard_boots_the_tracking_controller(self):
        page = DASHBOARD.read_text(encoding="utf-8")
        self.assertIn("academic_tracking.js", page)
        self.assertIn('data-sec="academic-tracking"', page)
        self.assertIn("window.AcademicTrackingInstance.boot()", page)

    def test_the_unbuilt_entities_state_their_boundary_rather_than_faking_it(self):
        """
        Phase 2 built the student tracking screens, so the old wording
        ("Tracking view arrives in Phase 2") is legitimately gone for
        students. The principle it protected is not: an entity whose
        workflow does not exist yet must say so, not fake it with
        placeholder numbers.

        The check is therefore re-pointed at teachers, subjects and
        classes, which are still unbuilt — it is not deleted.
        """
        # Pin the string the user actually reads, not a source comment.
        self.assertIn("tracking is not built yet", self.src,
                      "an unbuilt entity must say so on screen")
        self.assertIn("is a later phase", self.src)
        # ...and it must not be faked with numbers.
        boundary = self.src[self.src.index("renderSelection = function"):
                            self.src.index("renderList = function")]
        self.assertIn("renderStudent()", boundary,
                      "students must route to the real workflow")
        for faked in ("drawChart", "canvas", "KPI", "0%"):
            with self.subTest(fake=faked):
                self.assertNotIn(faked, boundary,
                                 "the boundary panel must not fake a dashboard")
        # Students are built now, so the old placeholder must be gone.
        self.assertNotIn("Tracking view arrives in Phase 2", self.src,
                         "students are built; the placeholder must not survive")


if __name__ == "__main__":
    unittest.main()
