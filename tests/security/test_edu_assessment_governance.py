"""
Education Assessment Governance Tests
═════════════════════════════════════════════════════════════════════
Verifies that:
  • Assessment creation, deletion, and template application is strictly
    restricted to Education Department staff (super_admin, school_admin, edu_dept)
  • Teachers and attendance takers are blocked from creating/modifying assessments
  • Education Department has standard scheme templates and batch assigning across
    multiple classes and subjects at once
  • Assessment queries return both graded student count and total enrolled students
    with completion percentages for easy grade tracking
  • Teacher dashboard and mobile app display mandated assessments and live grade
    entry progress
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
        """Total weight sum is enforced <= 100% — per SEMESTER since 1.6.6."""
        # mobile single-create: per-semester budget with a semester-aware message
        self.assertIn("only {$remaining}% remains", self.api_grades)
        self.assertIn("AND term_id = ?", self.api_grades)
        # mobile batch template: weights validated before any delete
        self.assertIn("exceeds the 100% semester budget", self.api_grades)
        # web template: total validated
        self.assertIn("Total template weight is", self.api_subjects)

    # ── 2. Batch Assigning Across Multiple Classes & Subjects ───────
    def test_api_subjects_supports_batch_class_ids_and_subject_ids(self):
        """apply_assessment_template and create_assessment accept class_ids and subject_ids."""
        self.assertIn("class_ids", self.api_subjects)
        self.assertIn("targetClassIds", self.api_subjects)
        self.assertIn("applied_count", self.api_subjects)

    def test_api_grades_supports_batch_template_provisioning(self):
        """POST /grades/assessments accepts batch class_ids and items."""
        self.assertIn("targetClassIds", self.api_grades)
        self.assertIn("applied_count", self.api_grades)

    def test_edu_dept_has_batch_assign_ui_controls(self):
        """edu_dept.php contains batch selection checkboxes for classes and subjects."""
        self.assertIn('tmplClassCb', self.edu_dept)
        self.assertIn('tmplSubjCb', self.edu_dept)
        self.assertIn('setSubjectScopeMode', self.edu_dept)
        self.assertIn('updateBatchSummary', self.edu_dept)

    # ── 3. Grade Tracking & Completion Metrics ──────────────────────
    def test_assessments_endpoint_returns_total_students_and_completion(self):
        """get_assessments returns total_students, graded_count, pending_count, completion_percentage."""
        self.assertIn("total_students", self.api_subjects)
        self.assertIn("completion_percentage", self.api_subjects)
        self.assertIn("pending_count", self.api_subjects)

    def test_grade_students_returns_completion_metrics(self):
        """get_students_for_grading calculates total_students and graded_count."""
        self.assertIn("graded_count", self.api_subjects)
        self.assertIn("completion_percentage", self.api_subjects)

    def test_edu_dept_ui_renders_grading_progress_column(self):
        """edu_dept.php displays Graded / Total Students progress with visual indicator."""
        self.assertIn("Grading Progress", self.edu_dept)
        self.assertIn("updateLiveGradingProgress", self.edu_dept)

    def test_teacher_dashboard_shows_live_grading_progress(self):
        """teacher.php tracks entered marks and displays graded/total ratio."""
        self.assertIn("updateTeacherLiveGrading", self.teacher_dashboard)
        self.assertIn("teacherLiveGraded", self.teacher_dashboard)

    def test_teacher_mobile_displays_graded_over_total_students(self):
        """Flutter mobile screen displays total students vs graded count."""
        self.assertIn("total_students", self.teacher_mobile)
        self.assertIn("grades_entered", self.teacher_mobile)
        self.assertIn("totalStudents > 0", self.teacher_mobile)


if __name__ == "__main__":
    unittest.main()
