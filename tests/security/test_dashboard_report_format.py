"""Regression checks for resilient browser report timestamp formatting."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class DashboardReportFormatTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.dashboard = (
            ROOT / "admin/dashboards/school_admin.php"
        ).read_text(encoding="utf-8")
        cls.formatter = (
            ROOT / "admin/js/report-format.js"
        ).read_text(encoding="utf-8")

    def test_dashboard_uses_shared_non_recursive_formatter(self):
        self.assertIn('<script src="/admin/js/report-format.js"></script>', self.dashboard)
        self.assertIn("WBWSReportFormat.timestamp()", self.dashboard)
        self.assertIn("WBWSReportFormat.longDate()", self.dashboard)
        self.assertNotIn("return genStamp();", self.dashboard)

    def test_formatter_has_calendar_locale_and_iso_fallbacks(self):
        self.assertIn("root.WBWSCalendar", self.formatter)
        self.assertIn("date.toLocaleString('en-GB'", self.formatter)
        self.assertIn("date.toISOString()", self.formatter)
        self.assertIn("Object.freeze", self.formatter)

    def test_actual_formatter_survives_missing_or_broken_calendar(self):
        node = shutil.which("node")
        if node is None:
            self.skipTest("Node.js is not installed")
        completed = subprocess.run(
            [
                node,
                str(ROOT / "tests/fixtures/report_format.fixture.js"),
                str(ROOT / "admin/js/report-format.js"),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(json.loads(completed.stdout), {"ok": True})


if __name__ == "__main__":
    unittest.main()
