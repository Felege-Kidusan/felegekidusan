"""PDF report engine — Phase D contracts (2026-08-28).

The Information department's analytics hub exports governed PDF
reports over the three independent attendance sources. TCPDF is
vendored in-repo (production is cPanel without Composer) together
with an OFL-licensed Ethiopic font so Amharic renders everywhere.

Locked contracts:
  • five report templates: general / sections / classes / member / full
  • reports render READ-ONLY — the service never writes any table
  • sources stay separate even inside one PDF (per-source headings)
  • engine: vendored TCPDF 6.11 + Noto Sans Ethiopic (regular + bold)
  • endpoint: GET-only download, same role gate as the hub, rate
    limited, audit-logged; role-map registered
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]

SOURCE_TABLES = (
    "attendance", "mezmur_attendance", "mezmur_submissions",
    "hr_attendance", "hr_submissions",
)


class PdfReportServiceContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.src = (ROOT / "admin/backend/services/PdfReportService.php").read_text(encoding="utf-8")

    def test_five_templates(self):
        self.assertIn("public const TYPES = ['general', 'sections', 'classes', 'member', 'full'];", self.src)
        for fn in ("renderGeneral", "renderGroupDetail", "renderMember"):
            self.assertIn(f"function {fn}", self.src)

    def test_reads_through_analytics_service(self):
        self.assertIn("InfoAnalyticsService::kpiBand", self.src)
        self.assertIn("InfoAnalyticsService::groupTable", self.src)
        self.assertIn("InfoAnalyticsService::trends", self.src)

    def test_never_writes_anything(self):
        for table in SOURCE_TABLES + ("attendance_rollup",):
            pat = re.compile(rf"\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?{re.escape(table)}`?\b(?!_)")
            self.assertIsNone(pat.search(self.src), f"report engine must never write `{table}`")
        self.assertNotIn("->query(\"INSERT", self.src)
        self.assertNotIn("->query('INSERT", self.src)

    def test_member_history_keeps_sources_separate(self):
        # one table per source, each under its own heading
        self.assertIn("Education (class-based, recorded by teachers)", self.src)
        self.assertIn("Mezmur (section-based, recorded by mezmur takers)", self.src)
        self.assertIn("HR (section-based, recorded by HR takers)", self.src)

    def test_read_budgets(self):
        self.assertIn("MAX_MEMBER_ROWS = 200", self.src)
        self.assertIn("366 * 86400", self.src)

    def test_ethiopic_font_wiring(self):
        self.assertIn("private const FONT = 'notosansethiopic';", self.src)
        self.assertIn("private const FONT_BOLD = 'notosansethiopicb';", self.src)
        self.assertIn("setFontSubsetting(true)", self.src)


class VendoredEngineContracts(unittest.TestCase):
    def test_tcpdf_vendored(self):
        base = ROOT / "admin/backend/pdf/tcpdf"
        for f in ("tcpdf.php", "tcpdf_autoconfig.php", "LICENSE.TXT", "VERSION",
                  "include/tcpdf_fonts.php", "fonts/helvetica.php",
                  "fonts/dejavusans.php", "fonts/dejavusans.z", "fonts/OFL.txt"):
            self.assertTrue((base / f).is_file(), f"missing vendored file {f}")
        version = (base / "VERSION").read_text(encoding="utf-8").strip()
        self.assertEqual(version, "6.11.4")

    def test_ethiopic_font_bundled_and_preconverted(self):
        fonts = ROOT / "admin/backend/pdf/tcpdf/fonts"
        for f in ("notosansethiopic.ttf", "notosansethiopic.php", "notosansethiopic.ctg.z",
                  "notosansethiopicb.ttf", "notosansethiopicb.php", "notosansethiopicb.ctg.z"):
            self.assertTrue((fonts / f).is_file(), f"missing Ethiopic font artifact {f}")
        # license text preserved with the vendored engine
        lic = (ROOT / "admin/backend/pdf/tcpdf/LICENSE.TXT").read_text(encoding="utf-8", errors="ignore")
        self.assertIn("LESSER GENERAL PUBLIC LICENSE", lic.upper())


class ReportsEndpointContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ep = (ROOT / "admin/api_info_reports.php").read_text(encoding="utf-8")
        cls.acl = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")

    def test_role_gate_matches_hub(self):
        self.assertIn("['super_admin', 'school_admin', 'info_dept']", self.ep)

    def test_get_only_download(self):
        self.assertIn("REQUEST_METHOD", self.ep)
        self.assertIn("Use GET to download reports", self.ep)
        self.assertIn("Content-Type: application/pdf", self.ep)
        self.assertIn("Content-Disposition: attachment", self.ep)

    def test_rate_limited_and_audited(self):
        self.assertIn("SecurityRateLimiter", self.ep)
        self.assertIn("info_reports_build", self.ep)
        self.assertIn("SecurityAuditService::record", self.ep)
        self.assertIn("Info Report Generated", self.ep)

    def test_version_marker_and_role_map(self):
        self.assertIn("phase7-pdf27", self.ep)
        self.assertIn("'api_info_reports.php' => ['super_admin', 'school_admin', 'info_dept']", self.acl)

    def test_endpoint_never_writes_source_tables(self):
        for table in SOURCE_TABLES:
            pat = re.compile(rf"\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?{re.escape(table)}`?\b(?!_)")
            self.assertIsNone(pat.search(self.ep), f"reports endpoint must never write `{table}`")


class ReportsUiContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.page = (ROOT / "admin/dashboards/info-dept.php").read_text(encoding="utf-8")

    def test_reports_panel_wired(self):
        self.assertIn("id=\"pdfReportType\"", self.page)
        self.assertIn("InfoReports.download", self.page)
        self.assertIn("api_info_reports.php", self.page)
        # all five template choices offered
        for t in ("general", "sections", "classes", "member", "full"):
            self.assertIn(f"value=\"{t}\"", self.page)
        # read-only posture stated in the panel
        self.assertIn("never change attendance data", self.page)


if __name__ == "__main__":
    unittest.main()
