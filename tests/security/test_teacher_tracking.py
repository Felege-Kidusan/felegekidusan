"""
Academic Tracking — Phase 3 Teacher Tracking workflow.

Phase 2 built the student workflow. Phase 3 builds the teacher one:
pick a teacher, see the classes and subjects they are actually assigned
to, pick one, and see where each of that offering's mark lists stands.

These tests drive the real endpoints against the live fixture database
through tests/e2e/education_config_api.php — the existing harness for
calling an admin endpoint as a session role. No second harness is added.
The frontend half lives in tests/e2e/teacher_tracking.js and is executed
from here so one pytest run checks both sides.

What is being defended:

  * Teacher -> class/subject relationships come from `teacher_assignments`
    and from nowhere else. The fixture contains a mark list submitted by
    a teacher for an offering they hold NO assignment for, which is
    exactly the relationship a "subjects this teacher has submitted for"
    shortcut would invent. It must not appear.
  * The teacher/class/subject triple is validated SERVER-SIDE on every
    scoped call. A browser that edits teacher_id, class_id or subject_id
    cannot read an offering through a teacher who does not hold it.
  * Workflow status comes from SubmissionService, is never recomputed,
    and is never presented as an academic result.
  * There is no teacher ranking, score, average or effectiveness figure
    anywhere in the payload or the screen. This is workflow tracking,
    not staff evaluation.
  * An absent record is never a zero, and the empty states stay distinct:
    no assignments, no assessments, homeroom-without-subject, refusal.
  * Authorization is server-side and unchanged: the Phase 3 actions join
    the existing tier-3 analytics gate and reuse canViewClass. No second
    permission system, no new workflow state, no schema change.

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
TEACHER_HARNESS = ROOT / "tests" / "e2e" / "teacher_tracking.js"
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
T_BEKELE = 11       # assigned C1/Geez and C2/Geez
T_ALMAZ = 12        # assigned C1/Music only
T_KEBEDE = 13       # a real teacher login with NO assignment at all
C1, C2 = 1, 2
S_GEEZ, S_MUSIC, S_HIST = 1, 2, 3
# Assessment ids: 1,2 = C1/Geez   3,7 = C1/Music   4,5 = C2/Geez   6 = C2/History
A_GEEZ_MID, A_GEEZ_FIN = 1, 2
A_MUSIC_TEST, A_MUSIC_PROJECT = 3, 7
A_HISTORY = 6


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


class _TeacherBase(unittest.TestCase):
    """Seeds the tracking fixture once, then calls endpoints as a role."""

    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — teacher tracking e2e skipped")
        if not RUNNER.is_file() or not ROLE_RUNNER.is_file():
            raise unittest.SkipTest("e2e runners not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(".fkss_env.php not present — teacher tracking e2e skipped")
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

    def detail(self, teacher_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_teacher_detail", role,
                         teacher_id=teacher_id, **kw)

    def assessments(self, teacher_id, class_id, subject_id, role="edu_dept", **kw):
        return self.call("api_education.php", "tracking_teacher_assessments", role,
                         teacher_id=teacher_id, class_id=class_id,
                         subject_id=subject_id, **kw)

    def offerings(self, payload):
        """(class_id, subject_id) pairs in a detail response."""
        return {(a["class_id"], a["subject_id"]) for a in payload["assignments"]}


# ══════════════════════════════════════════════════════════════════════
# THE AUTHORITATIVE RELATIONSHIP
# ══════════════════════════════════════════════════════════════════════

class TeacherAssignmentSourceTests(_TeacherBase):
    """
    Assignments are read from teacher_assignments. Nothing else may create
    one — not a submitted mark list, not an assessment, not a class the
    teacher once marked.
    """

    def test_a_teacher_shows_exactly_their_recorded_assignments(self):
        got = self.detail(T_BEKELE)
        self.assertEqual("success", got["status"])
        self.assertEqual({(C1, S_GEEZ), (C2, S_GEEZ)}, self.offerings(got))

    def test_a_second_teacher_shows_a_different_set(self):
        got = self.detail(T_ALMAZ)
        self.assertEqual({(C1, S_MUSIC)}, self.offerings(got))

    def test_a_submitted_marklist_does_not_create_an_assignment(self):
        """
        The fixture has Bekele submitting a History mark list for C2 while
        holding no C2/History assignment. Inferring assignments from
        submissions — an obvious shortcut — would surface it. It must not.
        """
        got = self.detail(T_BEKELE)
        self.assertNotIn(
            (C2, S_HIST), self.offerings(got),
            "an assignment was inferred from a mark list rather than read "
            "from teacher_assignments",
        )

    def test_that_submission_really_does_exist(self):
        """Guards the test above: if the fixture loses that packet, the
        negative assertion silently stops proving anything."""
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$r = $c->query(\"SELECT COUNT(*) n FROM grade_submissions "
            "WHERE teacher_id = %d AND class_id = %d AND subject_id = %d\");"
            "echo $r->fetch_assoc()['n'];"
            % (repr(str(ENV_FILE)), repr(SYNC_DB), T_BEKELE, C2, S_HIST)
        )
        proc = subprocess.run(
            [self.php, "-r", script], capture_output=True, text=True, timeout=60,
            cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )
        self.assertEqual(
            "1", (proc.stdout or "").strip().splitlines()[-1],
            "fixture no longer contains the unassigned-submission case",
        )

    def test_the_relationship_is_scoped_to_the_academic_year(self):
        """A year that holds no assignment returns none, not all of them."""
        got = self.detail(T_BEKELE, year_id=99)
        self.assertEqual("success", got["status"])
        self.assertEqual([], got["assignments"])
        self.assertEqual("no_assignments", got["data_state"]["assignments"])

    def test_assignments_carry_the_names_they_claim(self):
        got = self.detail(T_ALMAZ)
        row = got["assignments"][0]
        self.assertTrue(row["class_name"])
        self.assertTrue(row["subject_name"])
        self.assertFalse(row["is_homeroom"])


# ══════════════════════════════════════════════════════════════════════
# CROSS-SCOPE VALIDATION — the browser is not trusted
# ══════════════════════════════════════════════════════════════════════

class TeacherScopeValidationTests(_TeacherBase):
    """
    Every id arrives from the browser. The teacher/class/subject triple is
    therefore re-validated server-side before any workflow row is returned.
    """

    def test_a_valid_triple_is_accepted(self):
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        self.assertEqual("success", got["status"])
        self.assertEqual(T_BEKELE, got["scope"]["teacher_id"])
        self.assertEqual(C1, got["scope"]["class_id"])
        self.assertEqual(S_GEEZ, got["scope"]["subject_id"])

    def test_another_teachers_offering_is_refused(self):
        got = self.assessments(T_ALMAZ, C1, S_GEEZ)
        self.assertEqual("error", got["status"])
        self.assertEqual("not_assigned", got["code"])

    def test_an_offering_the_teacher_only_submitted_for_is_refused(self):
        """
        Bekele has a History packet for C2 but no History assignment. The
        packet must not become a back door into that offering's workflow.
        """
        got = self.assessments(T_BEKELE, C2, S_HIST)
        self.assertEqual("error", got["status"])
        self.assertEqual("not_assigned", got["code"])

    def test_a_subject_the_teacher_does_not_teach_in_that_class_is_refused(self):
        """Bekele teaches Geez in C1 and C2 — but not Music in C1."""
        got = self.assessments(T_BEKELE, C1, S_MUSIC)
        self.assertEqual("not_assigned", got["code"])

    def test_a_class_the_teacher_does_not_teach_is_refused(self):
        got = self.assessments(T_ALMAZ, C2, S_MUSIC)
        self.assertEqual("not_assigned", got["code"])

    def test_a_refusal_returns_no_data_at_all(self):
        got = self.assessments(T_ALMAZ, C1, S_GEEZ)
        self.assertNotIn("assessments", got)
        self.assertNotIn("class", got)
        self.assertNotIn("subject", got)

    def test_a_refusal_is_not_an_empty_success(self):
        """The difference between 'refused' and 'nothing here' matters."""
        refused = self.assessments(T_ALMAZ, C1, S_GEEZ)
        self.assertEqual("error", refused["status"])
        self.assertNotEqual("success", refused.get("status"))

    def test_missing_ids_are_rejected_before_any_query(self):
        for cid, sid, code in (
            (0, S_GEEZ, "invalid_class"),
            (C1, 0, "invalid_subject"),
        ):
            with self.subTest(class_id=cid, subject_id=sid):
                got = self.assessments(T_BEKELE, cid, sid)
                self.assertEqual("error", got["status"])
                self.assertEqual(code, got["code"])

    def test_a_missing_teacher_id_is_rejected(self):
        got = self.detail(0)
        self.assertEqual("invalid_teacher", got["code"])

    def test_a_negative_teacher_id_is_rejected(self):
        got = self.detail(-5)
        self.assertEqual("invalid_teacher", got["code"])

    def test_an_unknown_teacher_is_not_an_empty_teacher(self):
        got = self.detail(999999)
        self.assertEqual("error", got["status"])
        self.assertEqual("unknown_teacher", got["code"])

    def test_a_non_teacher_user_is_not_reachable_as_a_teacher(self):
        """
        Teachers are users with role='teacher'. Passing any other user's id
        must not turn them into a trackable teacher.

        The non-teacher is seeded here rather than looked for, so the test
        always runs: a skipped test proves nothing.
        """
        other = 4242
        self._sql(
            "DELETE FROM users WHERE id = %d;" % other
            + "INSERT INTO users (id, username, full_name, email, role, is_active) "
              "VALUES (%d, 'notateacher', 'Not A Teacher', 'n@example.org', "
              "'finance_dept', 1);" % other
        )
        try:
            # It is also given an assignment, so that a lookup which keyed
            # only on teacher_assignments — and forgot the role — would
            # wrongly succeed.
            self._sql(
                "INSERT INTO teacher_assignments "
                "(teacher_id, class_id, subject_id, academic_year_id, is_active) "
                "VALUES (%d, %d, %d, 1, 1);" % (other, C1, S_GEEZ)
            )
            got = self.detail(other)
            self.assertEqual("error", got["status"])
            self.assertEqual("unknown_teacher", got["code"])

            # The scoped call must agree. It validates an assignment row,
            # and an assignment row can outlive the role it was granted
            # for, so it has to check the role too.
            scoped = self.assessments(other, C1, S_GEEZ)
            self.assertEqual("error", scoped["status"])
            self.assertEqual("unknown_teacher", scoped["code"])
        finally:
            self._sql(
                "DELETE FROM teacher_assignments WHERE teacher_id = %d;" % other
                + "DELETE FROM users WHERE id = %d;" % other
            )

    def _sql(self, statements):
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


# ══════════════════════════════════════════════════════════════════════
# AUTHORIZATION
# ══════════════════════════════════════════════════════════════════════

class TeacherTrackingAuthorizationTests(_TeacherBase):
    """The Phase 3 actions join the existing tier-3 gate. Nothing new."""

    def test_education_roles_are_allowed(self):
        for role in EDU_ROLES:
            with self.subTest(role=role):
                got = self.detail(T_BEKELE, role=role)
                self.assertEqual("success", got["status"])

    def test_everyone_else_is_refused_the_detail(self):
        for role in NON_EDU_ROLES:
            with self.subTest(role=role):
                got = self.detail(T_BEKELE, role=role)
                self.assertEqual("error", got["status"])

    def test_everyone_else_is_refused_the_assessments(self):
        for role in NON_EDU_ROLES:
            with self.subTest(role=role):
                got = self.assessments(T_BEKELE, C1, S_GEEZ, role=role)
                self.assertEqual("error", got["status"])

    def test_a_teacher_cannot_read_their_own_tracking_record(self):
        """
        Not an oversight: this is an Education oversight screen, and
        widening it to the subject of the record is a product decision
        nobody has taken. It fails closed.
        """
        got = self.detail(T_BEKELE, role="teacher")
        self.assertEqual("error", got["status"])

    def test_the_refusal_names_the_education_department(self):
        got = self.detail(T_BEKELE, role="finance_dept")
        self.assertIn("Education", got["message"])

    def test_both_actions_are_inside_the_existing_tier(self):
        src = API_EDU.read_text(encoding="utf-8")
        block = src.split("$__analyticsActions")[1].split("];")[0]
        self.assertIn("tracking_teacher_detail", block)
        self.assertIn("tracking_teacher_assessments", block)

    def test_no_second_permission_system_was_added(self):
        """Authorization reuses canViewClass; it does not grow a rival."""
        src = SERVICE.read_text(encoding="utf-8")
        code = _strip_comments(src)
        for invented in ("function canTrackTeacher", "function hasTeacherAccess",
                         "TEACHER_PERMISSIONS", "function checkTeacherRole"):
            self.assertNotIn(invented, code)

    def test_the_service_runs_no_authorization_of_its_own(self):
        """
        The service validates a relationship; it does not decide who may
        look. Role checks belong at the endpoint.
        """
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        teacher_part = code[code.index("function teacherDetail"):]
        for role in ("super_admin", "school_admin", "edu_dept", "$_SESSION"):
            self.assertNotIn(role, teacher_part)

    def test_class_visibility_is_enforced_on_the_teacher_detail(self):
        """
        tracking_teacher_detail takes no class_id, so the endpoint's class
        gate cannot run for it. The assignment list must be filtered by
        canViewClass instead, or a hidden class leaks through a teacher.
        """
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_teacher_detail':")[1].split("case 'get_academic_intelligence':")[0]
        self.assertGreaterEqual(
            case.count("canViewClass"), 2,
            "the teacher endpoints must gate the named class AND the "
            "classes the detail response returns",
        )

    def test_the_visibility_filter_is_actually_applied(self):
        """
        Pins the filter in place.

        It cannot be observed from outside: canViewClass() returns true
        unconditionally for super_admin, school_admin and edu_dept, and
        those are the only roles that reach these endpoints. So removing
        the filter changes no response today — it would only start
        mattering on the day the tier is widened, which is exactly when
        nobody would remember to re-add it. Source is the only available
        hold. (Same limitation Phase 2 recorded for its own recheck.)
        """
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_teacher_detail':")[1].split("case 'get_academic_intelligence':")[0]
        self.assertIn("array_filter", case,
                      "the assignment list is no longer filtered by visibility")
        self.assertIn("$ttVisible[(int)$r['class_id']]", case,
                      "the filter no longer consults the per-class visibility map")
        # And the filtered list, not the raw one, must drive the state.
        self.assertIn("$ttRes['data_state']['assignments'] = $ttRes['assignments']", case,
                      "the empty state no longer follows the filtered list")

    def test_the_visibility_check_runs_after_the_data_is_fetched(self):
        """A check placed before the service call would filter nothing."""
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_teacher_detail':")[1].split("case 'get_academic_intelligence':")[0]
        service_call = case.index("AcademicTrackingService::teacherDetail")
        self.assertGreater(case.index("array_filter"), service_call)

    def test_the_subject_id_is_validated_at_both_layers(self):
        """
        Defence in depth. The endpoint rejects a missing subject_id before
        the service is reached, and the service rejects it again for any
        other caller. Removing either leaves the behaviour unchanged, so
        both are pinned here rather than left to an outcome assertion.
        """
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_teacher_assessments':")[1].split("case 'get_academic_intelligence':")[0]
        self.assertIn("if ($ttSubject <= 0) {", case,
                      "the endpoint no longer validates the subject id")
        self.assertIn("if ($ttClass <= 0) {", case,
                      "the endpoint no longer validates the class id")
        svc = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        part = svc[svc.index("function teacherAssessments"):]
        self.assertIn("if ($subjectId <= 0) {", part,
                      "the service no longer validates the subject id")
        self.assertIn("if ($classId <= 0) {", part,
                      "the service no longer validates the class id")

    def test_the_endpoint_executes_no_sql_of_its_own(self):
        """Scoped reads belong in the service, where they are tested."""
        src = API_EDU.read_text(encoding="utf-8")
        case = src.split("case 'tracking_teacher_detail':")[1].split("case 'get_academic_intelligence':")[0]
        code = _strip_comments(case)
        for sql in ("$conn->query(", "$conn->prepare("):
            self.assertNotIn(sql, code)


# ══════════════════════════════════════════════════════════════════════
# WORKFLOW STATUS
# ══════════════════════════════════════════════════════════════════════

class TeacherWorkflowStatusTests(_TeacherBase):
    """Status is retrieved from SubmissionService, never decided here."""

    def test_an_approved_marklist_is_reported_as_approved(self):
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        row = [a for a in got["assessments"] if a["assessment_id"] == A_GEEZ_MID][0]
        self.assertEqual("approved", row["workflow_status"])
        self.assertEqual("Approved", row["workflow_label"])

    def test_a_submitted_marklist_uses_the_services_own_label(self):
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        row = [a for a in got["assessments"] if a["assessment_id"] == A_GEEZ_FIN][0]
        self.assertEqual("submitted", row["workflow_status"])
        self.assertEqual("Complete", row["workflow_label"])

    def test_a_never_started_marklist_is_a_real_answer(self):
        """Null status is the honest answer, rendered as 'Not started'."""
        got = self.assessments(T_ALMAZ, C1, S_MUSIC)
        row = [a for a in got["assessments"] if a["assessment_id"] == A_MUSIC_PROJECT][0]
        self.assertIsNone(row["workflow_status"])
        self.assertEqual("Not started", row["workflow_label"])

    def test_no_row_ever_carries_a_blank_label(self):
        for cid, sid in ((C1, S_GEEZ), (C2, S_GEEZ)):
            got = self.assessments(T_BEKELE, cid, sid)
            for row in got["assessments"]:
                with self.subTest(assessment=row["assessment_id"]):
                    self.assertTrue(row["workflow_label"].strip())

    def test_the_labels_come_from_the_submission_service(self):
        """Every label emitted must be one the owning service produces."""
        allowed = {"Approved", "Complete", "Rejected", "Needs revision",
                   "Incomplete", "Not started"}
        seen = set()
        for tid, cid, sid in ((T_BEKELE, C1, S_GEEZ), (T_BEKELE, C2, S_GEEZ),
                              (T_ALMAZ, C1, S_MUSIC)):
            for row in self.assessments(tid, cid, sid)["assessments"]:
                seen.add(row["workflow_label"])
        self.assertTrue(seen)
        self.assertEqual(set(), seen - allowed, f"invented labels: {seen - allowed}")

    def test_no_new_workflow_state_was_introduced(self):
        """Only SubmissionService's own statuses, plus a real null."""
        allowed = {"incomplete", "submitted", "approved", "rejected",
                   "revision_needed", "draft", None}
        for tid, cid, sid in ((T_BEKELE, C1, S_GEEZ), (T_ALMAZ, C1, S_MUSIC)):
            for row in self.assessments(tid, cid, sid)["assessments"]:
                with self.subTest(assessment=row["assessment_id"]):
                    self.assertIn(row["workflow_status"], allowed)

    def test_a_packet_id_is_returned_so_the_existing_screen_can_open_it(self):
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        row = [a for a in got["assessments"] if a["assessment_id"] == A_GEEZ_MID][0]
        self.assertIsInstance(row["submission_id"], int)
        self.assertGreater(row["submission_id"], 0)

    def test_the_packet_id_is_the_real_row(self):
        """It must address the actual grade_submissions row, not the
        assessment, or 'Open review' opens the wrong thing."""
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        row = [a for a in got["assessments"] if a["assessment_id"] == A_GEEZ_MID][0]
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$r = $c->query('SELECT assessment_id FROM grade_submissions WHERE id = %d');"
            "$row = $r ? $r->fetch_assoc() : null;"
            "echo $row ? $row['assessment_id'] : 'none';"
            % (repr(str(ENV_FILE)), repr(SYNC_DB), row["submission_id"])
        )
        proc = subprocess.run(
            [self.php, "-r", script], capture_output=True, text=True, timeout=60,
            cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )
        self.assertEqual(str(A_GEEZ_MID), (proc.stdout or "").strip().splitlines()[-1])

    def test_an_assessment_with_no_packet_gets_no_invented_id(self):
        """
        Assessment 3 has marks but no packet: a real status, nothing to
        open. A fabricated id would send the user to a dead modal.
        """
        got = self.assessments(T_ALMAZ, C1, S_MUSIC)
        row = [a for a in got["assessments"] if a["assessment_id"] == A_MUSIC_TEST][0]
        self.assertIsNotNone(row["workflow_status"])
        self.assertIsNone(row["submission_id"])

    def test_the_tracking_layer_does_not_reimplement_status_precedence(self):
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        teacher_part = code[code.index("function teacherAssessments"):]
        self.assertNotIn("grade_submissions", teacher_part,
                         "the tracking layer is querying the workflow table directly")
        self.assertIn("SubmissionService::", teacher_part)

    def test_marklist_status_precedence_still_has_one_implementation(self):
        """
        Phase 3 needed the packet id as well as the status. Both must come
        from the same resolution, or two copies of the precedence rule can
        disagree about which packet is the right one.
        """
        code = _strip_comments(SUBMISSION.read_text(encoding="utf-8"))
        self.assertEqual(
            1, code.count("FROM grade_submissions\n                 WHERE submission_type = 'marklist'"),
            "the batched marklist precedence query exists more than once",
        )

    def test_a_locked_packet_wins_over_a_newer_draft(self):
        """
        The C2/H8 precedence rule: a submitted/approved packet is the
        workflow truth even when a NEWER draft row exists, or a later
        correction would silently re-open a locked mark list.

        Phase 3 reads that rule through marklistPacketRefs(), so the rule
        is exercised here for both the status and the id it returns.
        """
        self._sql(
            "INSERT INTO grade_submissions "
            "(teacher_id, class_id, subject_id, academic_year_id, term_id, "
            " assessment_id, submission_type, status, student_count) "
            "VALUES (%d, %d, %d, 1, 1, %d, 'marklist', 'draft', 0);"
            % (T_BEKELE, C1, S_GEEZ, A_GEEZ_MID)
        )
        try:
            got = self.assessments(T_BEKELE, C1, S_GEEZ)
            row = [a for a in got["assessments"] if a["assessment_id"] == A_GEEZ_MID][0]
            self.assertEqual(
                "approved", row["workflow_status"],
                "a newer draft packet overrode an approved mark list",
            )
            # And the id must address the APPROVED packet, not the draft,
            # or "Open review" opens the wrong row.
            self.assertEqual(1, row["submission_id"])
        finally:
            self._sql(
                "DELETE FROM grade_submissions WHERE assessment_id = %d "
                "AND status = 'draft';" % A_GEEZ_MID
            )

    def test_the_precedence_rule_is_exercised_through_both_entry_points(self):
        """Both the status map and the ref map must apply the same rule."""
        self._sql(
            "INSERT INTO grade_submissions "
            "(teacher_id, class_id, subject_id, academic_year_id, term_id, "
            " assessment_id, submission_type, status, student_count) "
            "VALUES (%d, %d, %d, 1, 1, %d, 'marklist', 'draft', 0);"
            % (T_BEKELE, C1, S_GEEZ, A_GEEZ_MID)
        )
        try:
            script = (
                "require %s;"
                "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
                "require_once %s;"
                "$s = \\App\\Services\\SubmissionService::marklistPacketStatuses($c, [%d]);"
                "$r = \\App\\Services\\SubmissionService::marklistPacketRefs($c, [%d]);"
                "echo json_encode([$s[%d] ?? null, $r[%d]['status'] ?? null, $r[%d]['id'] ?? null]);"
                % (repr(str(ENV_FILE)), repr(SYNC_DB),
                   repr(str(ROOT / "admin" / "backend" / "services" / "SubmissionService.php")),
                   A_GEEZ_MID, A_GEEZ_MID, A_GEEZ_MID, A_GEEZ_MID, A_GEEZ_MID)
            )
            proc = subprocess.run(
                [self.php, "-r", script], capture_output=True, text=True, timeout=60,
                cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
            )
            status, ref_status, ref_id = json.loads(
                (proc.stdout or "").strip().splitlines()[-1])
            self.assertEqual("approved", status)
            self.assertEqual("approved", ref_status)
            self.assertEqual(1, ref_id)
        finally:
            self._sql(
                "DELETE FROM grade_submissions WHERE assessment_id = %d "
                "AND status = 'draft';" % A_GEEZ_MID
            )

    def _sql(self, statements):
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

    def test_the_status_projection_matches_the_refs(self):
        """marklistPacketStatuses must stay a faithful view of the refs."""
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "require_once %s;"
            "$ids = [1,2,3,6,7];"
            "$a = \\App\\Services\\SubmissionService::marklistPacketStatuses($c, $ids);"
            "$b = \\App\\Services\\SubmissionService::marklistPacketRefs($c, $ids);"
            "$c2 = []; foreach ($b as $k => $v) { $c2[$k] = $v['status']; }"
            "echo json_encode([$a, $c2]);"
            % (repr(str(ENV_FILE)), repr(SYNC_DB),
               repr(str(ROOT / "admin" / "backend" / "services" / "SubmissionService.php")))
        )
        proc = subprocess.run(
            [self.php, "-r", script], capture_output=True, text=True, timeout=60,
            cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )
        statuses, projected = json.loads((proc.stdout or "").strip().splitlines()[-1])
        self.assertEqual(statuses, projected)
        self.assertTrue(statuses, "fixture produced no packets to compare")


# ══════════════════════════════════════════════════════════════════════
# STATUS IS NOT A RESULT
# ══════════════════════════════════════════════════════════════════════

class TeacherStatusIsNotAResultTests(_TeacherBase):
    """
    An approved mark list says the marks were accepted. It says nothing
    about how anyone performed, and the payload must not conflate them.
    """

    def test_no_academic_value_is_returned_for_an_assessment(self):
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        for row in got["assessments"]:
            with self.subTest(assessment=row["assessment_id"]):
                for banned in ("score", "percentage", "average", "grade",
                               "grade_letter", "final_percentage", "pass_mark"):
                    self.assertNotIn(banned, row)

    def test_max_score_is_the_assessment_definition_not_a_result(self):
        """max_score describes the assessment; it is not anyone's mark."""
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        row = got["assessments"][0]
        self.assertIn("max_score", row)
        self.assertNotIn("score", row)

    def test_no_teacher_level_academic_figure_is_returned(self):
        got = self.detail(T_BEKELE)
        flat = json.dumps(got).lower()
        for banned in ("effectiveness", "productivity", "performance_score",
                       "teacher_score", "ranking", "rank", "leaderboard",
                       "overall_average", "pass_rate"):
            self.assertNotIn(banned, flat)

    def test_the_teacher_payload_carries_no_marks(self):
        got = self.detail(T_BEKELE)
        for row in got["assignments"]:
            for banned in ("average", "score", "grade", "pass_rate", "result"):
                self.assertNotIn(banned, row)

    def test_no_report_card_engine_is_invoked_for_a_teacher(self):
        """
        A teacher has no report card. Calling the engine here would be
        both meaningless and expensive.
        """
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        teacher_part = code[code.index("function teacherDetail"):]
        self.assertNotIn("ReportCardService", teacher_part)
        self.assertNotIn("getCard", teacher_part)

    def test_the_teacher_layer_contains_no_academic_formula(self):
        code = _strip_comments(SERVICE.read_text(encoding="utf-8"))
        teacher_part = code[code.index("function teacherDetail"):]
        for formula in (r"weight\s*\*", r"\*\s*weight", r"/\s*max\s*\*\s*100",
                        r">=\s*(?:90|80|70|60)", r"array_sum\s*\(",
                        r"pass_mark\s*[:=]\s*\d"):
            with self.subTest(formula=formula):
                self.assertIsNone(re.search(formula, teacher_part),
                                  f"a calculation appeared in the teacher layer: {formula}")


# ══════════════════════════════════════════════════════════════════════
# EMPTY STATES — an absent record is never a zero
# ══════════════════════════════════════════════════════════════════════

class TeacherEmptyStateTests(_TeacherBase):
    """Each kind of nothing is a different answer and says so."""

    def test_a_teacher_with_no_assignments_says_so(self):
        got = self.detail(T_KEBEDE)
        self.assertEqual("success", got["status"])
        self.assertEqual([], got["assignments"])
        self.assertEqual("no_assignments", got["data_state"]["assignments"])

    def test_that_teacher_is_a_real_teacher(self):
        """Guards the test above: an unknown teacher would also be empty."""
        got = self.detail(T_KEBEDE)
        self.assertTrue(got["teacher"]["full_name"])
        self.assertEqual(T_KEBEDE, got["teacher"]["id"])

    def test_an_unlinked_teacher_keeps_a_null_member_code(self):
        """
        users.member_id is nullable. An absent member record must stay
        absent rather than become an empty string pretending to be a code.
        """
        got = self.detail(T_KEBEDE)
        self.assertIsNone(got["teacher"]["member_code"])

    def test_no_assignments_is_not_the_same_as_no_assessments(self):
        no_assign = self.detail(T_KEBEDE)["data_state"]["assignments"]
        has_assign = self.detail(T_BEKELE)["data_state"]["assignments"]
        self.assertEqual("no_assignments", no_assign)
        self.assertEqual("ok", has_assign)

    def test_an_offering_with_no_assessments_has_its_own_state(self):
        got = self.assessments(T_BEKELE, C2, S_GEEZ, term_id=99)
        self.assertEqual("success", got["status"])
        self.assertEqual([], got["assessments"])
        self.assertEqual("no_assessments", got["data_state"]["assessments"])

    def test_an_empty_offering_is_not_an_error(self):
        got = self.assessments(T_BEKELE, C2, S_GEEZ, term_id=99)
        self.assertNotEqual("error", got["status"])
        self.assertNotIn("code", got)

    def test_an_absent_weight_stays_null(self):
        """The fixture's Geez assessments have no weight. Null, not 0."""
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        weights = [a["weight"] for a in got["assessments"]]
        self.assertIn(None, weights)
        self.assertNotIn(0, weights)
        self.assertNotIn(0.0, weights)

    def test_a_present_weight_is_the_real_number(self):
        got = self.assessments(T_ALMAZ, C1, S_MUSIC)
        row = [a for a in got["assessments"] if a["assessment_id"] == A_MUSIC_PROJECT][0]
        self.assertEqual(50.0, row["weight"])

    def test_an_assessment_count_of_zero_is_a_real_count(self):
        """
        A count is allowed to be 0 because it is a genuine COUNT(*). What
        is forbidden is a 0 standing in for a value nobody looked up —
        which is why homeroom, where the question does not apply, is null.
        """
        got = self.detail(T_BEKELE, term_id=99)
        counts = [a["assessment_count"] for a in got["assignments"]]
        self.assertTrue(all(c == 0 for c in counts), counts)

    def _service_detail(self, teacher_id, year_id=0, term_id=0):
        """
        Calls AcademicTrackingService::teacherDetail() directly.

        The endpoint recomputes data_state after filtering the assignment
        list by class visibility, which masks whatever the service itself
        decided. Going through the API alone therefore cannot tell whether
        the service's own empty-state is right, so it is checked here.
        """
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$c->set_charset('utf8mb4');"
            "require_once %s;"
            "echo json_encode(\\App\\Services\\AcademicTrackingService::teacherDetail($c, %d, %d, %d));"
            % (repr(str(ENV_FILE)), repr(SYNC_DB), repr(str(SERVICE)),
               teacher_id, year_id, term_id)
        )
        proc = subprocess.run(
            [self.php, "-r", script], capture_output=True, text=True, timeout=120,
            cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )
        lines = (proc.stdout or "").strip().splitlines()
        if not lines:
            self.fail(f"teacherDetail({teacher_id}): no output\n{proc.stderr}")
        return json.loads(lines[-1])

    def test_the_service_itself_reports_no_assignments(self):
        got = self._service_detail(T_KEBEDE, year_id=1)
        self.assertEqual("no_assignments", got["data_state"]["assignments"])

    def test_the_service_itself_reports_ok_when_there_are_assignments(self):
        got = self._service_detail(T_BEKELE, year_id=1)
        self.assertEqual("ok", got["data_state"]["assignments"])

    def test_the_service_never_uses_the_assessment_state_for_assignments(self):
        """
        The two empty states are different answers and must not be
        interchangeable: "this teacher teaches nothing" is not "this
        offering has no assessments planned".
        """
        for teacher in (T_KEBEDE, T_BEKELE):
            with self.subTest(teacher=teacher):
                got = self._service_detail(teacher, year_id=1)
                self.assertNotEqual("no_assessments", got["data_state"]["assignments"])

    def test_a_real_count_is_reported_truthfully(self):
        got = self.detail(T_BEKELE)
        by_class = {a["class_id"]: a["assessment_count"] for a in got["assignments"]}
        self.assertEqual(2, by_class[C1])
        self.assertEqual(2, by_class[C2])


# ══════════════════════════════════════════════════════════════════════
# HOMEROOM — a nullable subject is a real distinction
# ══════════════════════════════════════════════════════════════════════

class TeacherHomeroomTests(_TeacherBase):
    """
    teacher_assignments.subject_id is nullable because, per migration 006,
    "Homeroom does not need a subject". That NULL must survive to the
    payload instead of becoming 0 or a placeholder subject.
    """

    def setUp(self):
        self.hr_id = self._seed_homeroom()

    def _seed_homeroom(self):
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$c->query(\"DELETE FROM teacher_assignments WHERE teacher_id = %d AND subject_id IS NULL\");"
            "$c->query(\"INSERT INTO teacher_assignments "
            "(teacher_id, class_id, subject_id, academic_year_id, is_active, "
            "is_class_teacher, assignment_role) VALUES (%d, %d, NULL, 1, 1, 1, 'homeroom')\");"
            "echo $c->insert_id;"
            % (repr(str(ENV_FILE)), repr(SYNC_DB), T_ALMAZ, T_ALMAZ, C1)
        )
        proc = subprocess.run(
            [self.php, "-r", script], capture_output=True, text=True, timeout=60,
            cwd=str(ROOT), env={**os.environ, "SSMS_DB_NAME": SYNC_DB},
        )
        out = (proc.stdout or "").strip().splitlines()
        return int(out[-1]) if out and out[-1].isdigit() else 0

    def tearDown(self):
        script = (
            "require %s;"
            "$c = new mysqli(DB_HOST, DB_USER, DB_PASS, %s);"
            "$c->query('DELETE FROM teacher_assignments WHERE id = %d');"
            % (repr(str(ENV_FILE)), repr(SYNC_DB), self.hr_id)
        )
        subprocess.run([self.php, "-r", script], capture_output=True, text=True,
                       timeout=60, cwd=str(ROOT),
                       env={**os.environ, "SSMS_DB_NAME": SYNC_DB})

    def test_a_homeroom_assignment_is_listed(self):
        rows = [a for a in self.detail(T_ALMAZ)["assignments"] if a["is_homeroom"]]
        self.assertEqual(1, len(rows), "the homeroom assignment was dropped")

    def test_its_subject_stays_null_rather_than_zero(self):
        row = [a for a in self.detail(T_ALMAZ)["assignments"] if a["is_homeroom"]][0]
        self.assertIsNone(row["subject_id"])
        self.assertIsNone(row["subject_name"])

    def test_its_assessment_count_is_null_not_zero(self):
        """
        The question "how many assessments" does not apply without a
        subject. Null says that; 0 would claim there are none.
        """
        row = [a for a in self.detail(T_ALMAZ)["assignments"] if a["is_homeroom"]][0]
        self.assertIsNone(row["assessment_count"])

    def test_the_class_teacher_flag_survives(self):
        row = [a for a in self.detail(T_ALMAZ)["assignments"] if a["is_homeroom"]][0]
        self.assertTrue(row["is_class_teacher"])

    def test_a_homeroom_row_cannot_be_opened_as_an_offering(self):
        """A subjectless assignment has no mark list to track."""
        got = self.assessments(T_ALMAZ, C1, 0)
        self.assertEqual("invalid_subject", got["code"])

    def test_the_real_subject_assignment_is_unaffected(self):
        rows = self.detail(T_ALMAZ)["assignments"]
        self.assertEqual(2, len(rows))
        self.assertEqual(1, len([a for a in rows if not a["is_homeroom"]]))


# ══════════════════════════════════════════════════════════════════════
# CONTRACT AND SCOPE HYGIENE
# ══════════════════════════════════════════════════════════════════════

class TeacherTrackingContractTests(_TeacherBase):
    """Scope, context and filters stay separate; the contract is additive."""

    def test_the_detail_reports_its_scope(self):
        got = self.detail(T_BEKELE)
        self.assertEqual("teacher", got["scope"]["type"])
        self.assertEqual(T_BEKELE, got["scope"]["teacher_id"])

    def test_the_assessments_report_the_full_scope(self):
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        self.assertEqual({"type", "teacher_id", "class_id", "subject_id"},
                         set(got["scope"].keys()))

    def test_context_is_separate_from_scope(self):
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        self.assertIn("year_id", got["context"])
        self.assertIn("term_id", got["context"])
        self.assertNotIn("teacher_id", got["context"])
        self.assertNotIn("class_id", got["context"])

    def test_scope_ids_are_not_returned_as_filters(self):
        got = self.assessments(T_BEKELE, C1, S_GEEZ)
        self.assertNotIn("filters", got)

    def test_the_term_is_reporting_context_and_narrows_the_rows(self):
        both = self.assessments(T_BEKELE, C1, S_GEEZ)
        term1 = self.assessments(T_BEKELE, C1, S_GEEZ, term_id=1)
        self.assertEqual(2, len(both["assessments"]))
        self.assertEqual(1, len(term1["assessments"]))
        self.assertEqual(A_GEEZ_MID, term1["assessments"][0]["assessment_id"])

    def test_no_new_api_file_was_created(self):
        """Phase 3 extends the existing education API, as Phase 1 and 2 did."""
        for invented in ("api_teacher_tracking.php", "api_tracking.php",
                         "api_academic_tracking.php"):
            self.assertFalse((ROOT / "admin" / invented).exists(),
                             f"a parallel API file appeared: {invented}")

    def test_the_existing_student_actions_are_untouched(self):
        src = API_EDU.read_text(encoding="utf-8")
        self.assertIn("case 'tracking_student_detail':", src)
        self.assertIn("case 'tracking_student_assessments':", src)

    def test_no_migration_was_added_for_phase_3(self):
        """Everything Phase 3 needs already exists in the schema."""
        sql_dir = ROOT / "sql"
        for f in sql_dir.glob("*.sql"):
            text = f.read_text(encoding="utf-8", errors="ignore").lower()
            self.assertNotIn("phase 3 teacher tracking", text)
            self.assertNotIn("academic_tracking_teacher", text)

    def test_the_assignment_table_is_the_one_the_rest_of_the_app_uses(self):
        """
        Confirms Phase 3 reads the same table ReportCardService::canViewClass
        authorises against. Two different sources of truth for "does this
        teacher teach this class" would be a security divergence.
        """
        rc = (ROOT / "admin" / "backend" / "services" / "ReportCardService.php").read_text(encoding="utf-8")
        self.assertIn("teacher_assignments", rc)
        self.assertIn("teacher_assignments", SERVICE.read_text(encoding="utf-8"))


# ══════════════════════════════════════════════════════════════════════
# THE CONTROLLER
# ══════════════════════════════════════════════════════════════════════

class TeacherTrackingControllerTests(unittest.TestCase):
    """Source-level prohibitions, plus the behavioural harness."""

    def setUp(self):
        if not CONTROLLER.is_file():
            self.skipTest("academic_tracking.js is missing")
        self.src = CONTROLLER.read_text(encoding="utf-8")
        self.code = _strip_comments(self.src)

    def test_the_teacher_screen_performs_no_academic_calculation(self):
        start = self.code.find("prototype.loadTeacherDetail")
        self.assertGreater(start, 0, "the teacher workflow is missing")
        part = self.code[start:]
        for formula in (r">=\s*(?:90|80|70|60)", r"\*\s*weight", r"weight\s*\*",
                        r"weight\s*/", r"\.reduce\(", r"pass_mark\s*[:=]\s*\d",
                        r"final_percentage\s*="):
            with self.subTest(formula=formula):
                self.assertIsNone(re.search(formula, part),
                                  f"the browser is calculating: {formula}")

    def test_the_teacher_screen_never_auto_selects(self):
        start = self.code.find("prototype.renderAssignmentsBody")
        part = self.code[start:]
        for auto in (r"assignments\[0\]", r"rows\[0\]", r"\.first\(\)",
                     r"selectOffering\(\s*(?:rows|assignments)"):
            with self.subTest(pattern=auto):
                self.assertIsNone(re.search(auto, part),
                                  f"an offering is being auto-selected: {auto}")

    def test_no_emoji_is_used_as_an_icon(self):
        emoji = re.compile(
            "[\U0001F300-\U0001FAFF\u2600-\u27BF\u2B00-\u2BFF\uFE0F]"
        )
        start = self.src.find("PHASE 3 — TEACHER TRACKING")
        self.assertGreater(start, 0)
        found = emoji.findall(self.src[start:])
        self.assertEqual([], found, f"emoji used in the UI: {found}")

    def test_professional_icons_are_used(self):
        start = self.src.find("prototype.renderTeacherHeader")
        part = self.src[start:start + 4000]
        self.assertIn("fa-chalkboard-user", part)

    def test_the_screen_does_not_implement_the_review_workflow(self):
        start = self.code.find("prototype.openSubmission")
        part = self.code[start:]
        for action in ("review_submission", "approve_submission",
                       "reject_submission", "submit_marklist"):
            self.assertNotIn(action, part,
                             "the tracking screen is performing the workflow "
                             "instead of opening the existing one")

    def test_it_opens_the_existing_review_modal(self):
        self.assertIn("openReviewModal", self.code)

    def test_the_offering_is_not_stored_in_the_filters_bag(self):
        start = self.code.find("prototype.selectOffering")
        part = self.code[start:self.code.find("prototype.clearOffering")]
        self.assertNotIn("filters", part)

    def test_the_race_guard_is_present_on_both_teacher_requests(self):
        for fn, seq in (("loadTeacherDetail", "_teacherSeq"),
                        ("loadTeacherAssessments", "_offeringSeq")):
            with self.subTest(fn=fn):
                start = self.code.find("prototype." + fn)
                part = self.code[start:start + 3000]
                self.assertIn("++this." + seq, part)
                self.assertIn("!== self." + seq, part)

    def test_the_guards_also_check_the_entity_not_just_the_sequence(self):
        """
        The sequence number alone happens to be sufficient today, because
        every load increments it. The identity checks are what keep that
        true if a future caller ever reloads without a new sequence, and
        they cost nothing — so they are held in place here. Behaviourally
        they are redundant, which is why a mutation removing them survives
        the harness; this is the pin that replaces that coverage.
        """
        start = self.code.find("prototype.loadTeacherDetail")
        detail = self.code[start:start + 3000]
        self.assertIn("self.scope.id !== teacherId", detail,
                      "the detail guard no longer checks the teacher changed")

        start = self.code.find("prototype.loadTeacherAssessments")
        offering = self.code[start:start + 3500]
        self.assertIn("=== want", offering,
                      "the offering guard no longer checks WHICH offering returned")
        self.assertIn("self.offering.class_id + ':' + self.offering.subject_id", offering)

    def test_the_behavioural_harness_passes(self):
        node = shutil.which("node")
        if not node:
            self.skipTest("node not available")
        if not TEACHER_HARNESS.is_file():
            self.fail("tests/e2e/teacher_tracking.js is missing")
        proc = subprocess.run(
            [node, str(TEACHER_HARNESS)], capture_output=True, text=True,
            timeout=300, cwd=str(ROOT),
        )
        self.assertEqual(
            0, proc.returncode,
            f"teacher tracking controller harness failed:\n{proc.stdout}\n{proc.stderr}",
        )
        self.assertIn("0 failed", proc.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
