"""Reporting Centre V2 — static pins + registry unit harness.

Static checks run everywhere; the PHP harness runs when a php binary exists
(no database needed).
"""
import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REG = ROOT / "admin" / "backend" / "reporting" / "ReportRegistry.php"
QS = ROOT / "admin" / "backend" / "reporting" / "ReportQueryService.php"
EXP = ROOT / "admin" / "backend" / "reporting" / "ReportExportService.php"
API = ROOT / "admin" / "api_reports_v2.php"
ACL = ROOT / "admin" / "access_control.php"
GATE = ROOT / "admin" / "backend" / "services" / "FeatureGate.php"
HARNESS = ROOT / "tests" / "e2e" / "report_query_service_unit.php"
VENDOR_JS = ROOT / "admin" / "js" / "vendor"
PAGE = ROOT / "admin" / "reports_v2.php"
PAGE_JS = ROOT / "admin" / "js" / "reports" / "reports_v2.js"
PAGE_CSS = ROOT / "admin" / "css" / "reports_v2.css"


def _read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


class ReportingCentreV2Static(unittest.TestCase):
    def test_files_exist(self):
        for p in (REG, QS, EXP, API, HARNESS):
            self.assertTrue(p.is_file(), p)

    def test_api_is_role_gated_in_acl_and_feature_gate(self):
        acl = _read(ACL)
        m = re.search(r"'api_reports_v2\.php'\s*=>\s*\[([^\]]+)\]", acl)
        self.assertIsNotNone(m, "api_reports_v2.php missing from ROLE_MAP")
        roles = set(re.findall(r"'([a-z_]+)'", m.group(1)))
        self.assertEqual(roles, {"super_admin", "school_admin", "info_dept", "hr_dept"})
        self.assertIn("'api_reports_v2.php' => 'reports'", _read(GATE))

    def test_page_shell_is_gated_and_self_hosted(self):
        """V2-1: the page is in ROLE_MAP + FeatureGate, re-checks the role in-file,
        serves only local assets and never embeds data or SQL."""
        for p in (PAGE, PAGE_JS, PAGE_CSS):
            self.assertTrue(p.is_file(), p)
        acl = _read(ACL)
        m = re.search(r"'reports_v2\.php'\s*=>\s*\[([^\]]+)\]", acl)
        self.assertIsNotNone(m, "reports_v2.php missing from ROLE_MAP")
        self.assertEqual(set(re.findall(r"'([a-z_]+)'", m.group(1))),
                         {"super_admin", "school_admin", "info_dept", "hr_dept"})
        self.assertIn("'reports_v2.php' => 'reports'", _read(GATE))
        page = _read(PAGE)
        self.assertIn("$_SESSION['admin_logged_in']", page)
        self.assertIn("['super_admin', 'school_admin', 'info_dept', 'hr_dept']", page)
        self.assertNotRegex(page, r"https?://(cdn|unpkg|cdnjs|fonts\.googleapis)", "no CDN assets")
        self.assertNotRegex(page, r"\$(conn|pdo)\b|mysqli|->query\(", "page must not touch the DB")
        self.assertIn("ENT_QUOTES", page)
        js = _read(PAGE_JS)
        # Exports and all data go through the governed API, never a client-side writer.
        self.assertNotRegex(js, r"XLSX\.|jsPDF|downloadToTab|table\.download\(")
        self.assertIn("credentials: 'same-origin'", js)
        self.assertIn("error=session_expired", js)
        # Rows are rendered as text (Tabulator plaintext formatters) — no innerHTML of API data.
        self.assertNotRegex(js, r"formatter:\s*['\"]html['\"]")

    def test_v2_2_cross_domain_guards(self):
        """V2-2: export history is admin-only; cross-domain joins are one-row-per-member
        and read-only; every metric that references a join declares `requires`."""
        api = _read(API)
        self.assertIn("'export_history'", api)
        i = api.index("case 'export_history':")
        self.assertIn("in_array($role, ['super_admin', 'school_admin'], true)", api[i:i + 400])
        self.assertIn("$fail(403", api[i:i + 400])
        self.assertIn("FROM activity_logs al", api)
        reg = _read(REG)
        # one-row-per-member pins for the enrolment joins (no member duplication)
        self.assertIn("ce.id = (SELECT MAX(e.id)", reg)
        self.assertIn("pe.id = (SELECT MAX(e.id)", reg)
        self.assertIn("GROUP BY a.member_id) ea", reg)
        self.assertIn("GROUP BY x.member_id) ma", reg)
        # read-only: the registry must never contain a write verb
        self.assertNotRegex(reg, r"\b(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b")
        # grades are out of scope for Info/HR (decision 2026-10-18)
        self.assertNotRegex(reg, r"academic_records|grade_submissions|assessment")
        # metrics that use a join alias declare `requires` (the query service attaches joins from it)
        i = reg.index("public static function metrics()")
        j = reg.index("public static function starters()")
        for block in re.split(r"\n\s{12}'[a-z_0-9]+' => \[", reg[i:j])[1:]:
            used = {a for a in ("ce", "pe", "ea", "ma") if re.search(r"\b" + a + r"\.[a-z_]+", block)}
            if used:
                m = re.search(r"'requires' => \[([^\]]*)\]", block)
                self.assertIsNotNone(m, "metric uses join alias %s without requires: %s" % (used, block[:80]))
                declared = set(re.findall(r"'([a-z]+)'", m.group(1)))
                self.assertTrue(used <= declared, "metric requires %s but uses %s" % (declared, used))
        self.assertIn("ReportRegistry::metricRequires($m)", _read(QS))

    def test_api_defense_in_depth(self):
        src = _read(API)
        self.assertIn("ReportRegistry::READER_ROLES", src)
        self.assertIn("!== 'GET'", src)
        self.assertIn("SecurityRateLimiter", src)
        self.assertIn("'member_report_export'", src)
        self.assertIn("'member_reports_v2_read'", src)
        self.assertIn("'Member Report Exported'", src)
        self.assertIn("'Member Analytics Explorer Queried'", src)
        self.assertIn("strlen($q) > 8192", src)
        # Audit must be recorded BEFORE any file byte is streamed.
        self.assertLess(src.index("'Member Report Exported'"), src.index("streamXlsx("))
        # Generic 500 message, details only to error_log.
        self.assertIn("'The report could not be produced.'", src)
        self.assertNotRegex(src, r"json_encode\([^\n]*getMessage")

    def test_registry_invariants(self):
        src = _read(REG)
        self.assertIn("const MAX_PAGE_SIZE   = 200", src)
        self.assertIn("const MAX_LIST_ROWS   = 2000", src)
        self.assertIn("const MAX_EXPORT_ROWS = 5000", src)
        # export must never exceed view (normalisation line present)
        self.assertIn("array_intersect($def['export'], $def['view'])", src)
        # no raw SQL ever accepted from clients
        qs = _read(QS)
        self.assertNotIn("$_GET", qs)
        self.assertNotIn("$_POST", qs)
        self.assertNotIn("$_REQUEST", qs)
        # LIKE escaping and prepared statements
        self.assertIn("escapeLike", qs)
        self.assertIn("->prepare(", qs)
        self.assertNotIn("->query(", qs)

    def test_no_client_side_downloads_shipped(self):
        # Exports must stay server side (audit + FLS): no SheetJS / jsPDF vendored.
        names = {p.name.lower() for p in VENDOR_JS.rglob("*") if p.is_file()}
        for banned in ("xlsx.full.min.js", "xlsx.min.js", "jspdf.umd.min.js", "jspdf.min.js"):
            self.assertNotIn(banned, names)
        self.assertTrue((VENDOR_JS / "tabulator" / "tabulator.min.js").is_file())
        self.assertTrue((VENDOR_JS / "echarts" / "echarts.min.js").is_file())

    def test_export_service_neutralises_formulas(self):
        src = _read(EXP)
        self.assertIn("spreadsheetSafe", src)
        self.assertIn("X-Content-Type-Options: nosniff", src)
        self.assertIn("MAX_EXPORT_ROWS", src)


class ReportingCentreV2Harness(unittest.TestCase):
    def test_php_unit_harness_passes(self):
        php = shutil.which("php")
        if not php:
            raise unittest.SkipTest("php binary not available")
        proc = subprocess.run([php, str(HARNESS)], capture_output=True, text=True, timeout=120, cwd=str(ROOT))
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        data = json.loads(proc.stdout)
        self.assertEqual(data["failures"], [])
        self.assertGreater(data["checks"], 200)


if __name__ == "__main__":
    unittest.main()
