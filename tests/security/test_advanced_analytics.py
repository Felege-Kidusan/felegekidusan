import unittest
from pathlib import Path

ROOT = Path("/home/user/SSMS")


class AdvancedAnalyticsCenterTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.report_service = (ROOT / "admin/backend/services/ReportCardService.php").read_text(encoding="utf-8")
        cls.analytics_js = (ROOT / "admin/js/advanced_analytics.js").read_text(encoding="utf-8")
        cls.edu_dashboard = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")

    def test_backend_advanced_statistics_calculations(self):
        """ReportCardService must compute statistical spreads, triage tiers, and subject benchmarks."""
        self.assertIn("median_grade", self.report_service)
        self.assertIn("stdev_grade", self.report_service)
        self.assertIn("p25_grade", self.report_service)
        self.assertIn("p75_grade", self.report_service)
        self.assertIn("median_attendance", self.report_service)
        self.assertIn("stdev_attendance", self.report_service)
        self.assertIn("triage_health", self.report_service)
        self.assertIn("subject_benchmarks", self.report_service)
        self.assertIn("grade_bins", self.report_service)
        self.assertIn("att_bins", self.report_service)

    def test_analytics_js_module_structure(self):
        """advanced_analytics.js must export AdvancedAnalyticsCenter with clean visual renderers and export tools."""
        self.assertIn("class AdvancedAnalyticsCenter", self.analytics_js)
        self.assertIn("global.AdvancedAnalyticsCenter = AdvancedAnalyticsCenter", self.analytics_js)
        self.assertIn("renderScatterChart", self.analytics_js)
        self.assertIn("renderDistributionChart", self.analytics_js)
        self.assertIn("renderWaveformChart", self.analytics_js)
        self.assertIn("renderRadarChart", self.analytics_js)
        self.assertIn("renderTriage", self.analytics_js)
        self.assertIn("renderKPIs", self.analytics_js)
        self.assertIn("renderInsights", self.analytics_js)
        self.assertIn("exportChartPNG", self.analytics_js)

    def test_analytics_js_security_and_escaping(self):
        """advanced_analytics.js must sanitize strings to prevent XSS and avoid unsafe eval."""
        self.assertIn("function esc(text)", self.analytics_js)
        self.assertNotIn("eval(", self.analytics_js)
        self.assertNotIn("innerHTML = text", self.analytics_js)

    def test_edu_dept_dashboard_analytics_integration(self):
        """edu_dept.php must load chart dependencies, sub-tab toggles, and chart canvas containers."""
        self.assertIn('src="/admin/js/chart.umd.min.js"', self.edu_dashboard)
        self.assertIn('src="/admin/js/advanced_analytics.js', self.edu_dashboard)
        self.assertIn('id="pfSubTabTable"', self.edu_dashboard)
        self.assertIn('id="pfSubTabAnalytics"', self.edu_dashboard)
        self.assertIn('id="pfTableView"', self.edu_dashboard)
        self.assertIn('id="pfAnalyticsArea"', self.edu_dashboard)
        self.assertIn('id="pfScatterChart"', self.edu_dashboard)
        self.assertIn('id="pfDistributionChart"', self.edu_dashboard)
        self.assertIn('id="pfWaveformChart"', self.edu_dashboard)
        self.assertIn('id="pfRadarChart"', self.edu_dashboard)
        self.assertIn('id="pfAnalyticsKpis"', self.edu_dashboard)
        self.assertIn('id="pfAnalyticsTriage"', self.edu_dashboard)
        self.assertIn('switchPfSubTab', self.edu_dashboard)
        self.assertIn('window.FKSSAnalyticsInstance', self.edu_dashboard)


if __name__ == "__main__":
    unittest.main()
