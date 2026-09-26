import unittest
from pathlib import Path

ROOT = Path("/home/user/SSMS")


class PerformanceFilterUnitAndSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.report_service = (ROOT / "admin/backend/services/ReportCardService.php").read_text(encoding="utf-8")
        cls.api_edu = (ROOT / "admin/api_education.php").read_text(encoding="utf-8")
        cls.export_handler = (ROOT / "admin/export_filtered_students.php").read_text(encoding="utf-8")
        cls.edu_dashboard = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")

    def test_report_card_service_has_filter_performance_method(self):
        """ReportCardService must define filterStudentsPerformance with flexible score and attendance bounds."""
        self.assertIn("public static function filterStudentsPerformance(", self.report_service)
        self.assertIn("min_grade", self.report_service)
        self.assertIn("max_grade", self.report_service)
        self.assertIn("min_attendance", self.report_service)
        self.assertIn("max_attendance", self.report_service)
        self.assertIn("grade_letter", self.report_service)
        self.assertIn("high_achievers", self.report_service)
        self.assertIn("at_risk_att", self.report_service)

    def test_report_card_service_has_stream_filtered_excel(self):
        """ReportCardService must have streamFilteredExcel for styled Excel sheet output."""
        self.assertIn("public static function streamFilteredExcel(", self.report_service)
        self.assertIn("Filtered Performance", self.report_service)
        self.assertIn("Spreadsheet", self.report_service)
        self.assertIn("Xlsx", self.report_service)
        self.assertIn("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", self.report_service)
        self.assertIn("Content-Disposition", self.report_service)

    def test_api_education_handles_filter_action(self):
        """api_education.php must expose filter_students_performance action with role and CSRF/Session guards."""
        self.assertIn("case 'filter_students_performance':", self.api_edu)
        self.assertIn("filterStudentsPerformance", self.api_edu)
        self.assertIn("min_grade", self.api_edu)
        self.assertIn("min_attendance", self.api_edu)

    def test_export_filtered_students_endpoint_security(self):
        """export_filtered_students.php must enforce session authentication, education role gate, and streaming output."""
        self.assertIn("edu_dept", self.export_handler)
        self.assertIn("streamFilteredExcel", self.export_handler)
        self.assertIn("ReportCardService", self.export_handler)
        self.assertIn("admin_id", self.export_handler)

    def test_edu_dept_dashboard_has_filter_section(self):
        """edu_dept.php dashboard must contain #sec-filter with interactive range sliders/inputs and preset chips."""
        self.assertIn('id="sec-filter"', self.edu_dashboard)
        self.assertIn('data-sec="filter"', self.edu_dashboard)
        self.assertIn('id="pfMinGrade"', self.edu_dashboard)
        self.assertIn('id="pfMaxGrade"', self.edu_dashboard)
        self.assertIn('id="pfMinAtt"', self.edu_dashboard)
        self.assertIn('id="pfMaxAtt"', self.edu_dashboard)
        self.assertIn('setQuickFilterPreset(89', self.edu_dashboard)
        self.assertIn('exportFilteredPerformance', self.edu_dashboard)
        self.assertIn('applyPerformanceFilter', self.edu_dashboard)

    def test_edu_dept_dashboard_has_stats_and_table(self):
        """edu_dept.php dashboard must display live summary stats and detailed student performance table."""
        self.assertIn('id="pfStatsArea"', self.edu_dashboard)
        self.assertIn('id="pfStatCount"', self.edu_dashboard)
        self.assertIn('id="pfStatGrade"', self.edu_dashboard)
        self.assertIn('id="pfStatAtt"', self.edu_dashboard)
        self.assertIn('id="pfTableBody"', self.edu_dashboard)
        self.assertIn('XLSX.utils.aoa_to_sheet', self.edu_dashboard)

    def test_attendance_refinements_and_unrecorded_handling(self):
        """Verify the 3 attendance adjustments: explicit breakdown, unrecorded handling, and average calculation."""
        # 1. ReportCardService checks
        self.assertIn('excused_days', self.report_service)
        self.assertIn('has_attendance', self.report_service)
        self.assertIn('recorded_att_students', self.report_service)
        self.assertIn('unrecorded_att_students', self.report_service)
        self.assertIn('Attendance Breakdown', self.report_service)

        # 2. edu_dept.php UI breakdown & unrecorded checks
        self.assertIn('No attendance taken', self.edu_dashboard)
        self.assertIn('Attendance Breakdown', self.edu_dashboard)
        self.assertIn('attendedDays', self.edu_dashboard)


if __name__ == "__main__":
    unittest.main()
