"""
Per-semester 100% weight budget — term-close model, release 2 (1.6.6+32, 2026-10-08)
═══════════════════════════════════════════════════════════════════════════════════
MODEL (user decision, 2026-10-08): every subject's assessments must total
100% PER SEMESTER — each semester closes from 100% and the next one starts
from 0. Before this release the budget was scoped per (class, subject,
YEAR) on the website, the mobile single-create path was also year-scoped,
and the mobile batch template apply had NO weight validation at all (plus
its has-grades guard and delete were year-wide, so a Semester 2 template
could never be applied once Semester 1 had grades).

TEACHER LIMITATION (user directive, 2026-10-08): teachers NEVER create
assessments — the Education Department creates and manages them. The
mobile API already refused teacher creation with 403; this release also
removed the dead "New Assessment" dialog from the teacher app and its
orphaned API client method.

SURFACES PINNED HERE
  admin/api_subjects.php       create / update(+move) / apply-template
  api/v1/routes/grades.php     POST single / multi / batch template
  teacher_grades.dart          no creation UI
  api_service.dart             no createAssessment method
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile/wbws_flutter_app"


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class WebCreateBudgetPinned(unittest.TestCase):
    def setUp(self):
        seg = read("admin/api_subjects.php").split("case 'create_assessment'")[1]
        self.seg = seg.split("case 'update_assessment'")[0]

    def test_semester_is_resolved_before_the_budget_is_charged(self):
        self.assertIn("$termId = null; $termName = null;", self.seg)
        self.assertIn("_subj_term_of_active_year($conn, $postedTermId)", self.seg)

    def test_budget_is_scoped_to_the_target_semester(self):
        self.assertIn(
            "WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?",
            self.seg,
        )

    def test_budget_message_names_the_semester(self):
        self.assertIn("only {$remaining}% remaining this semester.", self.seg)
        self.assertIn("Assessments {$scopeLabel} already total", self.seg)

    def test_legacy_no_semester_databases_fall_back_to_year_scope(self):
        self.assertIn("$termId !== null", self.seg)


class WebUpdateBudgetPinned(unittest.TestCase):
    def setUp(self):
        seg = read("admin/api_subjects.php").split("case 'update_assessment'")[1]
        self.seg = seg.split("case 'delete_assessment'")[0]

    def test_budget_charges_the_destination_semester_on_a_move(self):
        self.assertIn("SELECT class_id, subject_id, academic_year_id, weight_percentage, term_id FROM assessments", self.seg)
        self.assertIn("$budgetTermId = (int)$vtBudget['id'];", self.seg)
        self.assertIn(
            "WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ? AND id != ?",
            self.seg,
        )

    def test_budget_message_is_semester_scoped(self):
        self.assertIn("max allowed: {$remaining}% this semester.", self.seg)


class WebTemplateBudgetPinned(unittest.TestCase):
    def setUp(self):
        seg = read("admin/api_subjects.php").split("case 'apply_assessment_template'")[1]
        self.seg = seg.split("case 'get_assessments'")[0] if "case 'get_assessments'" in seg else seg[:9000]

    def test_template_targets_one_semester_with_validated_override(self):
        self.assertIn("_subj_current_term($conn)", self.seg)
        self.assertIn("$postedTermIdTpl = (int)($_POST['term_id'] ?? 0);", self.seg)

    def test_guard_and_delete_are_term_scoped_and_protect_graded_work(self):
        self.assertIn("AND (a.term_id = ? OR a.term_id IS NULL)", self.seg)
        self.assertIn("AND (term_id = ? OR term_id IS NULL)", self.seg)

    def test_template_weight_total_is_validated(self):
        self.assertIn("Total template weight is {$totalWeight}%", self.seg)


class MobileCreateBudgetPinned(unittest.TestCase):
    def setUp(self):
        self.api = read("api/v1/routes/grades.php")

    def test_teachers_are_refused_on_creation(self):
        seg = self.api.split("$action === 'assessments' && $method === 'POST'")[1][:1200]
        self.assertIn("if ($isRestricted) {", seg)
        self.assertIn("Only the Education department can create assessments", seg)

    def test_single_create_budget_is_per_semester(self):
        seg = self.api.split("If single target")[1][:2600]
        self.assertIn("AND term_id = ?", seg)
        self.assertIn("only {$remaining}% remains", seg)

    def test_semester_override_is_validated_against_the_active_year(self):
        seg = self.api.split("If single target")[0]
        self.assertIn("y.status = 'active'", seg)
        self.assertIn("That semester is not part of the active academic year.", seg)

    def test_batch_template_validates_weights_before_any_delete(self):
        seg = self.api.split("1.6.6: weights are validated before anything is deleted")[1][:1400]
        self.assertIn("Each assessment item needs a weight between 1 and 100", seg)
        self.assertIn("exceeds the 100% semester budget", seg)

    def test_batch_guard_and_delete_are_term_scoped(self):
        seg = self.api.split("1.6.6: weights are validated before anything is deleted")[1]
        self.assertIn("AND (a.term_id = ? OR a.term_id IS NULL)", seg)
        self.assertIn("AND (term_id = ? OR term_id IS NULL)", seg)

    def test_multi_target_budget_is_per_semester(self):
        seg = self.api.split("multi-target budget")[1] if "multi-target budget" in self.api else ""
        if not seg:
            # fall back: find the multi loop by its soft-skip semantics
            seg = self.api.split("1.6.6: per-semester budget (year-wide only when no")[1][:1500]
        self.assertIn("AND term_id = ?", seg)


class TeacherNeverCreatesPinned(unittest.TestCase):
    def test_teacher_screen_has_no_creation_dialog(self):
        g = (MOBILE / "lib/screens/teacher/teacher_grades.dart").read_text(encoding="utf-8")
        self.assertNotIn("_showCreateAssessmentDialog", g)
        self.assertNotIn("createAssessment", g)
        # the explanatory note is the tombstone for the removed capability
        self.assertIn("assessments are created and managed by the Education Department", g)

    def test_api_client_has_no_create_assessment_method(self):
        api = (MOBILE / "lib/services/api_service.dart").read_text(encoding="utf-8")
        self.assertNotIn("Future<ApiResponse> createAssessment", api)
        self.assertIn("assessments are created and\n  // managed by the Education Department", api)


if __name__ == "__main__":
    unittest.main()
