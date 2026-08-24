"""Regression checks for bounded, constant-memory member reports."""
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class MemberReportScalingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT / "admin/export_pdf.php").read_text(encoding="utf-8")
        cls.service = (
            ROOT / "admin/backend/services/MemberReportService.php"
        ).read_text(encoding="utf-8")
        cls.renderer = (
            ROOT / "admin/backend/services/MemberReportRenderer.php"
        ).read_text(encoding="utf-8")
        cls.reports_ui = (ROOT / "admin/reports.php").read_text(encoding="utf-8")

    def test_controller_only_orchestrates_policy_audit_and_renderer(self):
        self.assertIn("new MemberReportService($pdo, $_GET)", self.controller)
        self.assertIn("MemberReportRenderer::streamCsv", self.controller)
        self.assertIn("MemberReportRenderer::streamWord", self.controller)
        self.assertIn("MemberReportRenderer::streamPrintPage", self.controller)
        self.assertIn("SecurityAuditService::record", self.controller)
        self.assertNotIn("SELECT ", self.controller)
        self.assertNotIn("$members = []", self.controller)
        self.assertNotIn("fetch_assoc", self.controller)

    def test_service_has_a_hard_5000_row_bound_and_unbuffered_mysql_cursor(self):
        self.assertIn("public const MAX_ROWS = 5000", self.service)
        self.assertIn("PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false", self.service)
        self.assertIn("LIMIT \" . self::MAX_ROWS", self.service)
        self.assertIn("ORDER BY `student_name` ASC, `father_name` ASC, `id` ASC", self.service)
        self.assertIn("$format === 'csv' ? self::ROW_COLUMNS : self::PRINT_COLUMNS", self.service)
        print_projection = self.service.split("private const PRINT_COLUMNS", 1)[1].split("];", 1)[0]
        self.assertNotIn("guardian_name", print_projection)
        self.assertNotIn("alt_phone_number", print_projection)
        self.assertNotIn("SELECT *", self.service)

    def test_filters_are_allowlisted_and_search_uses_scalable_paths(self):
        self.assertIn("Invalid report filter", self.service)
        self.assertIn("enumFilter(", self.service)
        self.assertIn("yesNoFilter(", self.service)
        self.assertIn("MATCH (`student_name`", self.service)
        self.assertIn("AGAINST (? IN BOOLEAN MODE)", self.service)
        self.assertIn("LIKE ? ESCAPE", self.service)
        self.assertNotIn("LIKE '%", self.service)

    def test_renderers_stream_rows_and_never_collect_the_report(self):
        self.assertGreaterEqual(
            self.renderer.count("MemberReportService::nextRow($rows)"), 3
        )
        self.assertIn("flushEvery", self.renderer)
        self.assertIn("connection_aborted()", self.renderer)
        self.assertNotIn("$members[]", self.renderer)
        self.assertNotIn("iterator_to_array", self.renderer)

    def test_sensitive_outputs_are_non_cacheable_encoded_and_csv_safe(self):
        self.assertIn("Cache-Control: no-store", self.renderer)
        self.assertIn("Referrer-Policy: no-referrer", self.renderer)
        self.assertIn("Content-Security-Policy", self.renderer)
        self.assertIn("ENT_QUOTES | ENT_SUBSTITUTE", self.renderer)
        self.assertIn("spreadsheetSafe", self.renderer)
        self.assertIn("/^[=+\\-@\\t\\r]/u", self.renderer)
        self.assertNotIn("fonts.googleapis.com", self.renderer)

    def test_existing_pdf_word_csv_contracts_remain_and_truncation_is_explicit(self):
        self.assertIn("['pdf', 'docx', 'csv']", self.controller)
        self.assertIn("window.print()", self.renderer)
        self.assertIn("application/msword", self.renderer)
        self.assertIn("text/csv", self.renderer)
        self.assertIn("X-Report-Truncated", self.renderer)
        self.assertIn("first '", self.renderer)
        self.assertIn("Complete Roster CSV", self.reports_ui)
        self.assertIn("tier=all&amp;format=csv", self.reports_ui)
        self.assertIn("bounded to 5,000 rows", self.reports_ui)

    def test_real_render_adapters_escape_html_and_guard_csv_formulas(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        fixture = ROOT / "tests/fixtures/member_report_render.fixture"
        html = subprocess.run(
            [php, str(fixture), str(ROOT), "pdf"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        self.assertEqual(html.returncode, 0, html.stderr)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html.stdout)
        self.assertNotIn("<script>alert(1)</script>", html.stdout)
        self.assertIn("Test &lt;Report&gt;", html.stdout)

        csv = subprocess.run(
            [php, str(fixture), str(ROOT), "csv"],
            cwd=ROOT,
            capture_output=True,
            timeout=15,
            check=False,
        )
        self.assertEqual(csv.returncode, 0, csv.stderr.decode(errors="replace"))
        decoded = csv.stdout.decode("utf-8-sig")
        self.assertIn("'=HYPERLINK", decoded)


if __name__ == "__main__":
    unittest.main()
