"""HR attendance removal + shared Attendance Analytics Hub (2026-10-18).

Owner decisions pinned here:
  • HR takes NO attendance; the read-only HR attendance history viewer is
    removed completely (web + mobile read endpoints → 410, like the writes).
  • The hr_* tables stay in the database as an untouched archive — no
    migration, no DDL, no data destroyed.
  • The Information department's Analytics Hub (Education + Mezmur only) is
    one shared partial/module rendered by BOTH the Info and HR dashboards;
    hr_dept gets READ access to api_info_analytics / api_info_reports,
    while the rollup refresh stays admin-only.
  • HR keeps its own per-member Education + Mezmur view (api_hr_reports).
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class SharedHubAssets(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.partial = read("admin/dashboards/sections/attendance_analytics_hub.php")
        cls.js = read("admin/js/attendance_analytics_hub.js")
        cls.info = read("admin/dashboards/info-dept.php")
        cls.hr = read("admin/dashboards/hr-dept.php")

    def test_both_dashboards_render_the_same_partial(self):
        needle = "include __DIR__ . '/sections/attendance_analytics_hub.php'"
        self.assertEqual(self.info.count(needle), 1)
        self.assertEqual(self.hr.count(needle), 1)
        # the old inline module is gone from the Info page (single source of truth)
        self.assertNotIn("const InfoHub = (function", self.info)
        self.assertNotIn("window.InfoHub = InfoHub;", self.info)

    def test_partial_is_edu_plus_mezmur_only(self):
        self.assertIn('id="ihSrcEdu"', self.partial)
        self.assertIn('id="ihSrcMezmur"', self.partial)
        self.assertNotIn("ihSrcHr", self.partial)
        self.assertIn('id="attendanceHub"', self.partial)
        self.assertIn("api_info_analytics.php", self.partial)
        self.assertIn("view-only", self.partial)
        # refresh control only for admins (server gate mirrors this)
        self.assertIn("in_array($hubRole, ['super_admin', 'school_admin'], true)", self.partial)
        self.assertIn("SSMS_ASSET_VER", self.partial)

    def test_module_contract(self):
        self.assertIn("const SOURCES = ['edu', 'mezmur'];", self.js)
        self.assertIn("window.AttendanceHub = AttendanceHub;", self.js)
        self.assertIn("window.InfoHub = AttendanceHub;", self.js)
        for fn in ("init", "reload", "setSource", "exportAll", "refresh"):
            self.assertIn(fn + ":", self.js)
        self.assertNotIn("hr_attendance", self.js)
        # refresh is the only POST and carries the CSRF token
        self.assertEqual(self.js.count("method: 'POST'"), 1)
        self.assertIn("csrf_token", self.js)
        self.assertIn("fd.append('action', 'refresh')", self.js)

    def test_hr_dashboard_wiring(self):
        self.assertIn("AttendanceHub.init()", self.hr)
        for gone in ('id="section-submissions"', 'data-section="submissions"',
                     "HrSub", "hrPacketModal", "api_hr_attendance.php", "hrRepCards"):
            self.assertNotIn(gone, self.hr)
        # per-member Education + Mezmur view stays
        self.assertIn("api_hr_reports.php", self.hr)
        self.assertIn("HrReports.openMember", self.hr)
        self.assertIn('id="hrRepMonth"', self.hr)

    def test_info_exports_drop_hr(self):
        self.assertNotIn('<option value="hr">HR (sections)</option>', self.info)
        self.assertIn('<option value="sections">Section-based (Mezmur)</option>', self.info)


class BackendSources(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.svc = read("admin/backend/services/InfoAnalyticsService.php")
        cls.pdf = read("admin/backend/services/PdfReportService.php")
        cls.an = read("admin/api_info_analytics.php")
        cls.rp = read("admin/api_info_reports.php")
        cls.acl = read("admin/access_control.php")

    def test_service_sources(self):
        self.assertIn("public const SOURCES = ['edu', 'mezmur'];", self.svc)
        self.assertNotIn("'hr' => 'HR'", self.svc)
        self.assertNotIn("hr_attendance", self.svc.split("public const SOURCES")[1])
        self.assertNotIn("hr_submissions", self.svc.split("public const SOURCES")[1])
        # rollup rebuild still truncates first, which purges stale hr rows
        idx = self.svc.index("function refreshRollup")
        self.assertIn("DELETE FROM attendance_rollup", self.svc[idx:idx + 6000])

    def test_pdf_sources(self):
        self.assertNotIn("FROM hr_attendance", self.pdf)
        self.assertIn("foreach (['mezmur', 'edu'] as $src)", self.pdf)
        self.assertNotIn("=== 'hr' ? 'hr'", self.pdf)

    def test_role_gates(self):
        self.assertIn("['super_admin', 'school_admin', 'info_dept', 'hr_dept']", self.an)
        self.assertIn("['super_admin', 'school_admin', 'info_dept', 'hr_dept']", self.rp)
        self.assertIn("'api_info_analytics.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept']", self.acl)
        self.assertIn("'api_info_reports.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept']", self.acl)
        # refresh remains admin-only
        idx = self.an.index("'refresh'")
        self.assertIn("['super_admin', 'school_admin']", self.an[idx - 1500:idx + 1500])
        # HR has no takers to manage any more
        self.assertIn("'api_dept_takers.php' => ['super_admin', 'school_admin', 'mezmur_dept']", self.acl)

    def test_reports_reject_hr_source(self):
        self.assertIn("if ($source === 'hr')", self.rp)
        self.assertIn("410", self.rp[self.rp.index("if ($source === 'hr')"):][:300])


class RetiredEndpoints(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.web = read("admin/api_hr_attendance.php")
        cls.v1 = read("api/v1/routes/hr.php")

    def test_web_console_is_410_for_everything(self):
        self.assertIn("http_response_code(410)", self.web)
        self.assertIn("'HR_ATTENDANCE_RETIRED'", self.web)
        self.assertNotIn("switch ($action)", self.web)
        self.assertNotIn("HrAttendanceService", self.web)
        self.assertNotIn("HrSubmissionService", self.web)
        # guards kept: auth, role, CSRF on POST, rate limit
        for keep in ("admin_logged_in", "['super_admin', 'school_admin', 'hr_dept']",
                     "validateCsrf", "SecurityRateLimiter"):
            self.assertIn(keep, self.web)

    def test_mobile_route_is_410_for_everything(self):
        self.assertIn("apiRequireAuth()", self.v1)
        self.assertEqual(self.v1.count(", 410,"), 3)
        self.assertEqual(self.v1.count("'HR_ATTENDANCE_RETIRED'"), 3)
        self.assertNotIn("HrAttendanceService", self.v1)
        self.assertNotIn("HrSubmissionService", self.v1)
        self.assertNotIn("$conn", self.v1)  # touches no table at all
        # old outbox packets keep the exact classifier contract
        self.assertIn("$method === 'POST' && $action === 'sheet'", self.v1)
        self.assertIn("HR attendance was retired. HR no longer takes attendance", self.v1)

    def test_no_schema_change(self):
        # Archive stays: no new migration touches hr_* tables.
        sql_dir = ROOT / "sql"
        for f in sorted(sql_dir.glob("*.sql")):
            body = f.read_text(encoding="utf-8", errors="replace")
            self.assertIsNone(
                re.search(r"DROP\s+TABLE\s+(IF\s+EXISTS\s+)?`?hr_(attendance|submissions|attendance_audit)`?", body, re.I),
                f"{f.name} must not drop hr_* archive tables")


if __name__ == "__main__":
    unittest.main()
