"""
Semester close & reopen — term-close model, release 1 (1.6.5+31, 2026-10-08)
═════════════════════════════════════════════════════════════════════════════
MODEL (user decision, 2026-10-08): reporting is semester-based. When the
Education Department flips the current semester the previous one CLOSES:
teachers may view its marks for analysis but not edit them; corrections
flow through the department, which can REOPEN a closed semester for
teacher corrections (a flip re-closes every window). Staff
(edu_dept / school_admin / super_admin) always pass the write gate.

This is the standard SIS pattern (PowerSchool locked reporting terms +
admin unlock; Skyward grade-change requests approved by an administrator;
Canvas closed grading periods / concluded read-only courses).

SURFACES PINNED HERE
  sql/067                      is_reopened column (idempotent)
  SubmissionService            the ONE write gate (term + status, reasons)
  api_education.php            reopen_term / close_term_reopen + flip reset
  api_subjects.php (web)       save/entry/submit lock + audit + read flags
  api/v1/routes/grades.php     mobile save/submit lock + term-scoped lists
  api/v1/routes/app.php        /app/config current_term
  api/v1/routes/dashboard.php  hero-box current_term
  teacher.php / school_admin   web lock UI + reopen controls
  teacher_home / teacher_grades (Flutter) hero chip + switcher + read-only
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile/wbws_flutter_app"


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class MigrationPinned(unittest.TestCase):
    def test_067_adds_the_reopen_flag_idempotently(self):
        sql = read("sql/067_semester_reopen_flag.sql")
        self.assertIn("ADD COLUMN IF NOT EXISTS `is_reopened`", sql)
        self.assertIn("TINYINT(1) NOT NULL DEFAULT 0", sql)
        self.assertIn("UPDATE `academic_terms` SET `is_reopened` = 0", sql)


class WriteGatePinned(unittest.TestCase):
    """The ONE server-side rule every surface inherits."""

    def setUp(self):
        self.svc = read("admin/backend/services/SubmissionService.php")

    def test_current_term_is_scoped_to_the_active_year(self):
        self.assertIn("function currentTermOfActiveYear", self.svc)
        seg = self.svc.split("function currentTermOfActiveYear")[1][:900]
        self.assertIn("y.status = 'active'", seg)
        self.assertIn("t.is_current = 1", seg)

    def test_writable_terms_for_teachers(self):
        self.assertIn("function termWritableForTeachers", self.svc)
        seg = self.svc.split("function termWritableForTeachers")[1][:1400]
        self.assertIn("staffCanOverride($auth)", seg)           # staff bypass
        self.assertIn("$termId <= 0", seg)                       # legacy NULL
        self.assertIn("is_reopened", seg)                        # reopen window
        self.assertIn("=== 1", seg)

    def test_refusal_reasons_carry_the_close_and_submitted_cases(self):
        self.assertIn("function teacherWriteRefusal", self.svc)
        seg = self.svc.split("function teacherWriteRefusal")[1][:2200]
        self.assertIn("staffCanOverride($auth)", seg)
        self.assertIn("is closed for editing", seg)
        self.assertIn("reopen it for corrections", seg)
        self.assertIn("already submitted", seg)
        self.assertIn("resolvedMarklistStatus", seg)

    def test_teacherMayWriteMarklist_delegates_to_the_reason_gate(self):
        self.assertIn(
            "return self::teacherWriteRefusal($conn, $auth, $assessmentId) === null;",
            self.svc,
        )


class EducationApiPinned(unittest.TestCase):
    def setUp(self):
        self.api = read("admin/api_education.php")

    def test_reopen_actions_are_post_only_and_term_tier_gated(self):
        self.assertIn("'reopen_term', 'close_term_reopen'", self.api)
        self.assertIn(
            "$__termActions = ['save_term', 'delete_term', 'set_current_term', 'reopen_term', 'close_term_reopen']",
            self.api,
        )

    def test_reopen_is_refused_for_non_active_years_and_current_semesters(self):
        seg = self.api.split("case 'reopen_term'")[1].split("case 'delete_term'")[0]
        self.assertIn("Only a semester of the ACTIVE academic year can be reopened.", seg)
        self.assertIn("The current semester is already open for grade entry.", seg)
        self.assertIn("UPDATE academic_terms SET is_reopened = ?", seg)

    def test_flipping_the_current_semester_re_closes_all_windows(self):
        seg = self.api.split("case 'set_current_term'")[1].split("case 'reopen_term'")[0]
        self.assertIn("UPDATE academic_terms SET is_reopened=0", seg)


class WebSavePathPinned(unittest.TestCase):
    def setUp(self):
        self.api = read("admin/api_subjects.php")

    def test_save_grades_uses_the_reason_gate(self):
        seg = self.api.split("case 'save_grades'")[1].split("case 'get_assessments'")[0]
        self.assertIn("teacherWriteRefusal($conn, $auth, $assessmentId)", seg)
        self.assertIn("http_response_code(409)", seg)
        self.assertIn("'message' => $refusal", seg)

    def test_web_saves_are_audited_with_term_context(self):
        seg = self.api.split("case 'save_grades'")[1].split("case 'get_assessments'")[0]
        self.assertIn("INSERT INTO activity_logs", seg)
        self.assertIn("'save_grades'", seg)
        self.assertIn("(term #{$termId})", seg)

    def test_entry_payload_carries_the_lock_state(self):
        seg = self.api.split("case 'get_students_for_grading'")[1].split("case 'save_grades'")[0]
        self.assertIn("termWritableForTeachers(", seg)
        self.assertIn("$assessment['term_locked']", seg)
        self.assertIn("$assessment['term_reopened']", seg)

    def test_assessment_list_flags_closed_and_reopened_rows(self):
        seg = self.api.split("case 'get_assessments'")[1].split("case 'create_assessment'")[0]
        self.assertIn("is_reopened = 1", seg)
        self.assertIn("termWritableForTeachers(", seg)
        self.assertIn("$a['term_reopened']", seg)
        self.assertIn("$a['term_locked']", seg)


class MobileApiPinned(unittest.TestCase):
    def setUp(self):
        self.api = read("api/v1/routes/grades.php")

    def test_save_and_submit_refuse_with_term_closed_code(self):
        for action in ("save", "submit"):
            seg = self.api.split(f"action === '{action}'")[1]
            self.assertIn("teacherWriteRefusal($conn, $auth, $assessmentId)", seg[:4000])
            self.assertIn("'TERM_CLOSED'", seg[:4000])

    def test_assessment_list_defaults_to_the_current_semester(self):
        seg = self.api.split("action === 'assessments' && $method === 'GET'")[1]
        seg = seg.split("if ($action === 'assessments' && $method === 'POST'")[0]
        self.assertIn("currentTermOfActiveYear($conn)", seg)
        self.assertIn("a.term_id = ? OR a.term_id IS NULL", seg)  # legacy stays visible
        self.assertIn("$row['term_locked']", seg)
        self.assertIn("'terms' => $termsOut", seg)
        self.assertIn("'current_term' => $currentOut", seg)

    def test_bootstrap_publishes_the_semester_context(self):
        seg = self.api.split("=== 'bootstrap'")[1]
        self.assertIn("'terms' => $bootTerms", seg)
        self.assertIn("'current_term' => $bootCurrent", seg)

    def test_students_payload_carries_the_term_gate(self):
        seg = self.api.split("action === 'students' && $method === 'GET'")[1]
        self.assertIn("'term' => $termCtx", seg)
        self.assertIn("'term_locked' => $termLocked", seg)
        self.assertIn("'term_reopened' => $termReopened", seg)
        self.assertIn("termWritableForTeachers($conn, $auth, $atid)", seg)


class PublicConfigPinned(unittest.TestCase):
    def test_app_config_publishes_current_term(self):
        app = read("api/v1/routes/app.php")
        self.assertIn("'current_term'", app)
        self.assertIn("currentTermOfActiveYear($conn)", app)

    def test_dashboard_stats_publishes_current_term(self):
        dash = read("api/v1/routes/dashboard.php")
        self.assertIn("'current_term' => $currentTerm", dash)


class TeacherWebUiPinned(unittest.TestCase):
    def setUp(self):
        self.ui = read("admin/dashboards/teacher.php")

    def test_grade_entry_locks_closed_semesters(self):
        self.assertIn("const entryLocked = (a.term_locked === true);", self.ui)
        self.assertIn("Semester closed.", self.ui)
        self.assertIn("applyTermLockToEntry(entryLocked);", self.ui)

    def test_submit_flow_locks_too(self):
        self.assertIn("const submitLocked = (a.term_locked === true);", self.ui)
        self.assertIn("document.getElementById('submitBtn').disabled = submitLocked;", self.ui)

    def test_reopened_semesters_are_explained(self):
        self.assertIn("corrections window reopened by the Education Department", self.ui)


class AdminReopenUiPinned(unittest.TestCase):
    def test_terms_table_has_reopen_controls(self):
        ui = read("admin/dashboards/school_admin.php")
        self.assertIn("Reopened for corrections", ui)
        self.assertIn("Reopen for corrections", ui)
        self.assertIn("async function reopenTerm(id,open)", ui)
        self.assertIn("open?'reopen_term':'close_term_reopen'", ui)


class MobileAppPinned(unittest.TestCase):
    def test_hero_box_shows_the_running_semester(self):
        home = (MOBILE / "lib/screens/teacher/teacher_home.dart").read_text(encoding="utf-8")
        self.assertIn("statsRes.data['current_term']", home)
        self.assertIn("አሁን ያለው ሴሚስተር", home)

    def test_grades_screen_has_the_semester_switcher(self):
        g = (MOBILE / "lib/screens/teacher/teacher_grades.dart").read_text(encoding="utf-8")
        self.assertIn("Widget _buildTermSwitcher()", g)
        self.assertIn("_termViewLocked", g)
        self.assertIn("Viewing a closed semester — read-only.", g)

    def test_entry_screen_is_read_only_for_closed_semesters(self):
        g = (MOBILE / "lib/screens/teacher/teacher_grades.dart").read_text(encoding="utf-8")
        self.assertIn("final bool initialTermLocked;", g)
        self.assertIn("bool _termLocked = false;", g)
        self.assertIn("_commitInProgress || _termLocked ||", g)
        self.assertIn("Closed semester — read only", g)
        self.assertIn("termInfo?['term_locked']", g)

    def test_api_client_passes_the_term_filter(self):
        api = (MOBILE / "lib/services/api_service.dart").read_text(encoding="utf-8")
        self.assertIn("{int? termId}", api)
        self.assertIn("'term_id': '$termId'", api)


if __name__ == "__main__":
    unittest.main()
