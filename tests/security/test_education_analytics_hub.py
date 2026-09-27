import unittest
from pathlib import Path

ROOT = Path("/home/user/SSMS")


class EducationAnalyticsHubSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.edu_service = (ROOT / "admin/backend/services/EducationAnalyticsService.php").read_text(encoding="utf-8")
        cls.api_edu = (ROOT / "admin/api_education.php").read_text(encoding="utf-8")
        cls.export_pdf = (ROOT / "admin/export_executive_report_pdf.php").read_text(encoding="utf-8")
        cls.export_excel = (ROOT / "admin/export_executive_report_excel.php").read_text(encoding="utf-8")
        cls.hub_js = (ROOT / "admin/js/education_analytics_hub.js").read_text(encoding="utf-8")
        cls.edu_dashboard = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")

    def test_education_analytics_service_methods(self):
        """EducationAnalyticsService must define all 4 governance pillars and streaming exports."""
        self.assertIn("public static function getHubData(", self.edu_service)
        self.assertIn("public static function buildClassBenchmarks(", self.edu_service)
        self.assertIn("public static function buildExamGovernance(", self.edu_service)
        self.assertIn("public static function buildTriageRoster(", self.edu_service)
        self.assertIn("public static function streamExecutivePdf(", self.edu_service)
        self.assertIn("public static function streamExecutiveExcel(", self.edu_service)
        self.assertIn("delivery_rate", self.edu_service)
        self.assertIn("missing_count", self.edu_service)
        self.assertIn("audit_matrix", self.edu_service)

    def test_api_education_exposes_hub_action(self):
        """api_education.php must expose get_education_hub route with security guards."""
        self.assertIn("case 'get_education_hub':", self.api_edu)
        self.assertIn("EducationAnalyticsService::getHubData", self.api_edu)

    def test_executive_export_endpoints_security(self):
        """export_executive_report_pdf.php & export_executive_report_excel.php must enforce session and role gates."""
        self.assertIn("admin_id", self.export_pdf)
        self.assertIn("edu_dept", self.export_pdf)
        self.assertIn("EducationAnalyticsService::streamExecutivePdf", self.export_pdf)

        self.assertIn("admin_id", self.export_excel)
        self.assertIn("edu_dept", self.export_excel)
        self.assertIn("EducationAnalyticsService::streamExecutiveExcel", self.export_excel)

    def test_hub_js_controller_structure(self):
        """education_analytics_hub.js must define EducationAnalyticsHub with clean workspace renderers."""
        self.assertIn("class EducationAnalyticsHub", self.hub_js)
        self.assertIn("global.EducationAnalyticsHub = EducationAnalyticsHub", self.hub_js)
        self.assertIn("renderKPIs", self.hub_js)
        self.assertIn("renderStudentIntel", self.hub_js)
        self.assertIn("renderTeacherGovernance", self.hub_js)
        self.assertIn("renderClassBenchmarks", self.hub_js)
        self.assertIn("renderExecutiveReportsTab", self.hub_js)
        self.assertIn("renderCharts", self.hub_js)
        self.assertIn("function esc(text)", self.hub_js)
        self.assertNotIn("eval(", self.hub_js)

    def test_edu_dept_dashboard_hub_integration(self):
        """edu_dept.php must include the dedicated #sec-analytics section and sidebar link."""
        self.assertIn('data-sec="analytics"', self.edu_dashboard)
        self.assertIn('id="sec-analytics"', self.edu_dashboard)
        self.assertIn('src="/admin/js/education_analytics_hub.js', self.edu_dashboard)
        self.assertIn('id="hubTabBtn_student_intel"', self.edu_dashboard)
        self.assertIn('id="hubTabBtn_teacher_governance"', self.edu_dashboard)
        self.assertIn('id="hubTabBtn_class_benchmarks"', self.edu_dashboard)
        self.assertIn('id="hubTabBtn_executive_reports"', self.edu_dashboard)
        self.assertIn('window.EduHubInstance', self.edu_dashboard)


if __name__ == "__main__":
    unittest.main()
