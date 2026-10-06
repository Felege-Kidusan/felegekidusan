"""Attendance rework Phase D — web parity + HR report-only (2026-10-07).

Two locked contracts:

1. WEB TAKER DEFAULT-ABSENT PARITY (attendance_taker.php + teacher.php):
   every student renders as ABSENT unless marked; Save is always
   available (client completeness gate removed — the sheet is complete
   by construction); presence is never inferred anywhere; the server
   (api_attendance.php -> AttendanceRecordService) still validates the
   complete roster on every write, so the fail-closed backstop holds.

2. HR REPORT-ONLY (hr-dept.php + api_hr_reports.php):
   HR no longer takes, reviews or manages attendance. The dashboard's
   old review console is a READ-ONLY history view (view/export only);
   the taker-management console and QR-roster button are gone
   (DeptTakerService::create refuses new hr_attendance_taker accounts
   server-side); HR reads COMBINED Education + Mezmur attendance
   reports through the new governed read-only endpoint
   api_hr_reports.php, which never touches HR's own retired hr_* tables
   and degrades to zeros when a source table is missing.
"""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class WebTakerDefaultAbsentContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.taker = (ROOT / "admin/dashboards/attendance_taker.php").read_text(encoding="utf-8")
        cls.teacher = (ROOT / "admin/dashboards/teacher.php").read_text(encoding="utf-8")
        cls.sheet_js = (ROOT / "admin/js/attendance-sheet.js").read_text(encoding="utf-8")

    def test_render_defaults_every_row_to_absent(self):
        for src in (self.taker, self.teacher):
            self.assertIn("normalizeStatus(s.status) || 'absent'", src)
            self.assertIn("absent is the conservative default", src)

    def test_client_completeness_gate_is_gone(self):
        for src in (self.taker, self.teacher):
            self.assertNotIn("sheet.unmarked.length > 0", src)
            self.assertNotIn("remaining).", src)
            # Save stays guarded only against a genuinely empty roster.
            self.assertIn("sheet.records.length === 0", src)

    def test_presence_is_never_inferred(self):
        for src in (self.taker, self.teacher):
            self.assertNotIn("let status = 'present'", src)
            self.assertNotIn("s.status || 'present'", src)
            self.assertNotIn("|| 'present'", src)
        # The shared collector stays truthful: unmarked rows are
        # reported as unmarked (server-side backstop consumes this).
        self.assertIn("unmarked.push(memberId)", self.sheet_js)
        self.assertIn("if (!status) unmarked.push(memberId);", self.sheet_js)

    def test_server_backstop_unchanged(self):
        admin_api = (ROOT / "admin/api_attendance.php").read_text(encoding="utf-8")
        self.assertIn("AttendanceRecordService::normalizeCompleteSheet", admin_api)
        self.assertIn("AttendanceRecordService::replaceSheet", admin_api)

    def test_unmarked_counter_retired_from_summary_strip(self):
        self.assertNotIn("countUnmarked", self.taker)
        self.assertNotIn(">Unmarked<", self.taker)


class HrReportsEndpointContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ep = (ROOT / "admin/api_hr_reports.php").read_text(encoding="utf-8")
        cls.acl = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")

    def test_role_gate_mirrors_hr_console(self):
        self.assertIn("'api_hr_reports.php' => ['super_admin', 'school_admin', 'hr_dept']", self.acl)
        self.assertIn("['super_admin', 'school_admin', 'hr_dept']", self.ep)
        self.assertIn("admin_logged_in", self.ep)

    def test_endpoint_is_strictly_read_only(self):
        self.assertIn("$_SERVER['REQUEST_METHOD'] !== 'GET'", self.ep)
        self.assertIn("This endpoint is read-only", self.ep)
        self.assertIn("SecurityRateLimiter", self.ep)
        self.assertIn("hr_reports_read", self.ep)
        # No CSRF surface exists because no write action exists.
        self.assertNotIn("validateCsrf", self.ep)
        self.assertNotIn("$conn->query(", self.ep.replace("$conn->query(\"SELECT 1", ""))

    def test_combined_sources_and_isolation(self):
        # Reads Education + Mezmur attendance only. The summary helper
        # is table-parameterized with FIXED call-site literals; the
        # member-detail queries name the sources directly.
        self.assertIn("FROM `$table`", self.ep)
        self.assertIn("FROM attendance a", self.ep)
        self.assertIn("FROM mezmur_attendance", self.ep)
        # Never HR's own retired tables.
        for retired in ("hr_attendance", "hr_submissions"):
            self.assertNotIn(retired, self.ep)
        # Source-labelled responses for the dashboard.
        self.assertIn("'education' => hr_reports_summary($conn, 'attendance', $month)", self.ep)
        self.assertIn("'mezmur' => hr_reports_summary($conn, 'mezmur_attendance', $month)", self.ep)

    def test_missing_tables_degrade_to_zeros(self):
        # Mezmur migration resilience pattern: a missing source table
        # reports zeros/empty rows, never a 500.
        self.assertIn("hr_reports_empty_summary", self.ep)
        self.assertGreaterEqual(self.ep.count("catch (\\Throwable $e)"), 4)

    def test_prepared_statements_and_pii_discipline(self):
        self.assertIn("$conn->prepare", self.ep)
        self.assertIn("bind_param", self.ep)
        for pii in ("phone_number", "guardian", "alt_phone", "house_number"):
            self.assertNotIn(pii, self.ep)
        # Month input is validated, member search is bounded.
        self.assertIn("preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/'", self.ep)
        self.assertIn("LIMIT 20", self.ep)

    def test_version_handshake(self):
        self.assertIn("'hr-reports-1'", self.ep)


class HrDashboardRetiredContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.hr = (ROOT / "admin/dashboards/hr-dept.php").read_text(encoding="utf-8")
        cls.takers = (ROOT / "admin/backend/services/DeptTakerService.php").read_text(encoding="utf-8")

    def test_review_console_is_read_only_history(self):
        self.assertIn('id="section-submissions"', self.hr)
        self.assertIn("Attendance History", self.hr)
        self.assertIn("HR attendance was retired", self.hr)
        # Reads stay (list/detail/export); every review write is gone.
        self.assertIn("HrSub.viewPacket", self.hr)
        self.assertIn("HrSub.exportSubmissions", self.hr)
        for gone in ("HrSub.openReview", "HrSub.submitReview", "HrSub.quickDecision",
                     "hrReviewModal", "Record Decision", "apiPost("):
            self.assertNotIn(gone, self.hr)

    def test_taker_management_removed(self):
        self.assertNotIn('id="section-attakers"', self.hr)
        self.assertNotIn('data-section="attakers"', self.hr)
        self.assertNotIn("attakerModal", self.hr)
        self.assertNotIn("api_dept_takers.php", self.hr)
        # Server-side guard: no new HR taker accounts, ever.
        self.assertIn("HR attendance was retired — no new HR taker accounts", self.takers)
        create = self.takers[self.takers.index("public static function create"):]
        create = create[:create.index("}")] if "}" in create else create[:800]
        self.assertIn("ROLE_HR_TAKER", create[:600])

    def test_qr_roster_button_removed(self):
        self.assertNotIn("printHrQrRoster", self.hr)
        self.assertNotIn("api_qr_roster.php?dept=hr", self.hr)

    def test_combined_reports_section_exists(self):
        self.assertIn('id="section-reports"', self.hr)
        self.assertIn("Attendance Reports", self.hr)
        self.assertIn("HrReports", self.hr)
        self.assertIn("api_hr_reports.php", self.hr)
        self.assertIn("Education — Class Attendance", self.hr)
        self.assertIn("Mezmur — Section Attendance", self.hr)
        self.assertIn("HrReports.loadSummary", self.hr)
        self.assertIn("HrReports.searchMembers", self.hr)

    def test_education_surface_stays_off_the_hr_dashboard(self):
        # hr_dept may never get the Education class-attendance taker UI.
        for marker in ('id="section-attendance"', "api_attendance_info",
                       "showAttTab", "loadDailyReport"):
            self.assertNotIn(marker, self.hr)


if __name__ == "__main__":
    unittest.main()
