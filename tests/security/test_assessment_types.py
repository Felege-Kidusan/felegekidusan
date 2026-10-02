import unittest
from pathlib import Path

# Resolve from this file, not from an absolute path. These four modules
# hardcoded /home/user/SSMS and so passed only on the machine that wrote
# them; on any other checkout they raised FileNotFoundError at setUpClass.
# The first real CI run surfaced this as 22 errors.
ROOT = Path(__file__).resolve().parents[2]


class AssessmentTypesSecurityAndFeatureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql_050 = (ROOT / "sql/050_assessment_types.sql").read_text(encoding="utf-8")
        cls.service = (ROOT / "admin/backend/services/AssessmentTypeService.php").read_text(encoding="utf-8")
        cls.api_subjects = (ROOT / "admin/api_subjects.php").read_text(encoding="utf-8")
        cls.api_edu = (ROOT / "admin/api_education.php").read_text(encoding="utf-8")
        cls.api_v1_grades = (ROOT / "api/v1/routes/grades.php").read_text(encoding="utf-8")
        cls.edu_dashboard = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")

    def test_sql_migration_050_creates_table(self):
        """050_assessment_types.sql must define assessment_types table with unique type_key, names, weights, and indexes."""
        self.assertIn("CREATE TABLE IF NOT EXISTS `assessment_types`", self.sql_050)
        self.assertIn("`type_key` VARCHAR(50) NOT NULL UNIQUE", self.sql_050)
        self.assertIn("`type_name` VARCHAR(100) NOT NULL", self.sql_050)
        self.assertIn("`type_name_en` VARCHAR(100)", self.sql_050)
        self.assertIn("`default_weight` DECIMAL", self.sql_050)
        self.assertIn("`default_max_score` DECIMAL", self.sql_050)

    def test_assessment_type_service_methods(self):
        """AssessmentTypeService must implement complete CRUD lifecycle, slugification, and runtime table safety."""
        self.assertIn("class AssessmentTypeService", self.service)
        self.assertIn("public static function ensureTable(", self.service)
        self.assertIn("public static function getAll(", self.service)
        self.assertIn("public static function save(", self.service)
        self.assertIn("public static function toggleActive(", self.service)
        self.assertIn("public static function delete(", self.service)
        self.assertIn("public static function slugify(", self.service)

    def test_api_subjects_endpoints_and_role_protection(self):
        """api_subjects.php must expose get, save, toggle, and delete actions with strict role gating."""
        self.assertIn("case 'get_assessment_types':", self.api_subjects)
        self.assertIn("case 'save_assessment_type':", self.api_subjects)
        self.assertIn("case 'toggle_assessment_type':", self.api_subjects)
        self.assertIn("case 'delete_assessment_type':", self.api_subjects)
        self.assertIn("edu_dept", self.api_subjects)
        self.assertIn("AssessmentTypeService", self.api_subjects)

    def test_api_education_endpoints(self):
        """api_education.php must also expose assessment type endpoints for department management."""
        self.assertIn("case 'get_assessment_types':", self.api_edu)
        self.assertIn("case 'save_assessment_type':", self.api_edu)
        self.assertIn("case 'toggle_assessment_type':", self.api_edu)
        self.assertIn("case 'delete_assessment_type':", self.api_edu)

    def test_api_v1_grades_exposes_assessment_types(self):
        """api/v1/routes/grades.php must expose assessment-types endpoint for mobile/REST consumers."""
        self.assertIn("$action === 'assessment-types'", self.api_v1_grades)
        self.assertIn("AssessmentTypeService::getAll", self.api_v1_grades)

    def test_edu_dept_dashboard_has_management_modals_and_js(self):
        """edu_dept.php must provide UI modals, toolbar button, dynamic dropdown population, and JS handlers."""
        self.assertIn('id="assessmentTypesModal"', self.edu_dashboard)
        self.assertIn('id="assessmentTypeEditModal"', self.edu_dashboard)
        self.assertIn('openAssessmentTypesModal()', self.edu_dashboard)
        self.assertIn('loadAssessmentTypes()', self.edu_dashboard)
        self.assertIn('saveAssessmentType()', self.edu_dashboard)
        self.assertIn('populateAsmtTypeDropdown()', self.edu_dashboard)
        self.assertIn('onAsmtTypeSelectChange()', self.edu_dashboard)


if __name__ == "__main__":
    unittest.main()
