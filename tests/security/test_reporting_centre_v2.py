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

    def test_api_defense_in_depth(self):
        src = _read(API)
        self.assertIn("ReportRegistry::READER_ROLES", src)
        self.assertIn("!== 'GET'", src)
        self.assertIn("SecurityRateLimiter", src)
        self.assertIn("'member_report_export'", src)
        self.assertIn("'member_analytics_read'", src)
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
