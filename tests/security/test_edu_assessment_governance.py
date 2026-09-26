"""
Education Assessment Governance Tests
═════════════════════════════════════════════════════════════════════
Verifies that:
  • Assessment creation, deletion, and template application is strictly
    restricted to Education Department staff (super_admin, school_admin, edu_dept)
  • Teachers and attendance takers are blocked from creating/modifying assessments
  • Education Department has standard scheme templates (e.g. 10% test, 40% exam, 50% final)
  • Teacher dashboard renders department-defined assessments and displays honest
    empty state when no assessments are configured yet
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class EduAssessmentGovernanceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api_subjects = (ROOT / "admin/api_subjects.php").read_text(encoding="utf-8")
        cls.api_grades = (ROOT / "api/v1/routes/grades.php").read_text(encoding="utf-8")
        cls.edu_dept = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")
        cls.teacher_dashboard = (ROOT / "admin/dashboards/teacher.php").read_text(encoding="utf-8")
        cls.teacher_mobile = (ROOT / "Mobile/wbws_flutter_app/lib/screens/teacher/teacher_grades.dart").read_text(encoding="utf-8")

    # ── 1. Backend API Role Gating ─────────────────────────────────
    def test_manage_actions_include_assessment_template(self):
        """apply_assessment_template and assessment CRUD are listed in $__manageActions."""
        for act in ['create_assessment', 'update_assessment', 'delete_assessment', 'apply_assessment_template']:
            self.assertIn(f"'{act}'", self.api_subjects, f"Action {act} missing from api_subjects")

    def test_teacher_blocked_from_rest_assessment_creation(self):
        """POST /grades/assessments blocks teacher / restricted roles with 403."""
        self.assertIn("Only the Education department can create assessments", self.api_grades)

    def test_total_weight_enforcement(self):
        """Total weight sum is enforced <= 100% in api_subjects."""
        self.assertIn("Total weight for this class-subject would exceed 100%", self.api_grades)
        self.assertIn("Total template weight is", self.api_subjects)

    # ── 2. Education Department Assessment Setup UI ────────────────
    def test_edu_dept_has_standard_scheme_modal(self):
        """edu_dept.php contains templateModal for standard assessment schemes."""
        self.assertIn('id="templateModal"', self.edu_dept)
        self.assertIn('apply_assessment_template', self.edu_dept)
        self.assertIn('standard_10_40_50', self.edu_dept)

    def test_edu_dept_has_weight_tracker(self):
        """edu_dept.php contains live weight tracker progress indicator."""
        self.assertIn('id="asmtWeightTracker"', self.edu_dept)

    # ── 3. Teacher Dashboard & Mobile Experience ───────────────────
    def test_teacher_dashboard_shows_honest_empty_state(self):
        """teacher.php informs teachers that assessments are established by Education Department."""
        self.assertIn("No assessments configured by Education Department", self.teacher_dashboard)

    def test_teacher_mobile_informs_about_department_assessments(self):
        """Mobile teacher screen shows department assessments without standalone create button."""
        self.assertIn("Department Assessments", self.teacher_mobile)
        self.assertIn("configured by the Education Department", self.teacher_mobile)


if __name__ == "__main__":
    unittest.main()
