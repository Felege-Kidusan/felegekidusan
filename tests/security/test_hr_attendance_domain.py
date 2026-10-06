"""HR attendance domain — RETIRED contract (server phase, 2026-10-07).

Product decision (2026-10-07): HR no longer takes attendance; the
department reads combined Education + Mezmur attendance reports
instead. Server retirement (this phase):

  • Every write path returns an honest 410 with code
    HR_ATTENDANCE_RETIRED — recording (POST /api/v1/hr/sheet) and
    review (POST /api/v1/hr/submission-review, admin console
    submission_review). Applies to every role; not overridable.
  • Old app versions surface the 410 through the outbox attention
    banner (outbox_policy classifies 410 as needs-attention) with the
    server's exact message — no silent data loss.
  • History is preserved and readable: hr_* tables, GET sheet/days/
    sections/submissions stay available. Nothing is dropped.

Still-true legacy contracts kept under pin: sql/026 schema (history
tables), HrAttendanceService / HrSubmissionService internals (now
read-only by entry-point policy), isolation from other departments.

The Flutter contracts below remain from the pre-retirement app and
stay green until Phase C removes the HR attendance UI — the outbox
HR drain deliberately stays so queued packets on upgraded devices
settle honestly against the 410 instead of hanging forever.
"""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class HrDomainContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = (ROOT / "sql/026_hr_attendance.sql").read_text(encoding="utf-8")
        cls.att = (ROOT / "admin/backend/services/HrAttendanceService.php").read_text(encoding="utf-8")
        cls.sub = (ROOT / "admin/backend/services/HrSubmissionService.php").read_text(encoding="utf-8")

    # ── schema ─────────────────────────────────────────────────
    def test_schema_objects_and_uniques(self):
        for table in ("hr_attendance", "hr_submissions", "hr_attendance_audit"):
            self.assertIn("CREATE TABLE IF NOT EXISTS `%s`" % table, self.sql)
        self.assertIn("uq_hr_attendance_date_member", self.sql)
        self.assertIn("uq_hr_submissions_date_section", self.sql)
        self.assertIn("ENUM('present','late','absent','excused')", self.sql)
        self.assertIn("idx_hr_attendance_section_date", self.sql)

    # ── isolation ──────────────────────────────────────────────
    def test_hr_services_never_touch_other_departments(self):
        for src in (self.att, self.sub):
            self.assertNotIn("mezmur_", src)
            self.assertNotIn("grade_submissions", src)
            self.assertNotIn("FROM attendance ", src)
            self.assertNotIn("INSERT INTO attendance", src)
        # section roster comes from the shared member directory only
        self.assertIn("FROM members", self.att)

    # ── sheet mechanics ────────────────────────────────────────
    def test_sheet_write_is_transactional_and_validated(self):
        self.assertIn("begin_transaction", self.att)
        self.assertIn("rollback", self.att)
        self.assertIn("Attendance cannot be recorded for a future date", self.att)
        self.assertIn("out of date with the current roster", self.att)
        self.assertIn("Duplicate member in sheet", self.att)
        # section snapshot on the row → scoped deletes, never global
        self.assertIn("DELETE FROM hr_attendance WHERE attendance_date = ? AND section = ?", self.att)

    # ── packet state machine parity ────────────────────────────
    def test_packet_state_machine(self):
        for token in (
            "STATUS_INCOMPLETE", "STATUS_SUBMITTED", "STATUS_APPROVED",
            "STATUS_REJECTED", "STATUS_REVISION", "STATUS_DRAFT",
            "takerMayWrite", "isLockedForTaker", "reviewPacket",
            "listPackets", "packetStats", "client_op_id",
        ):
            self.assertIn(token, self.sub)
        # HR review attribution
        self.assertIn("['hr_dept', 'school_admin', 'super_admin']", self.sub)
        self.assertIn("['school_admin', 'super_admin']", self.sub)  # override is admin-only
        # non-approved decisions need a note for the taker
        self.assertIn("Write a short reason so the taker knows what to fix", self.sub)
        # legacy rows-without-packet read as submitted (mezmur parity)
        self.assertIn("packetHasRows", self.sub)
        # audit wiring on the HR audit table
        self.assertIn("hr_attendance_audit", self.sub)
        self.assertIn("'HR Submission Reviewed'", self.sub)

    # ── prepared statements only ───────────────────────────────
    def test_no_interpolated_queries(self):
        import re
        for src in (self.att, self.sub):
            for m in re.finditer(r'\$conn->query\(\s*"([^"]*)"', src):
                # static aggregation reads only — no user input in them
                self.assertNotIn("?", m.group(1))
                self.assertNotIn("$_", m.group(1))
            self.assertIn("$conn->prepare", src)

    # ── canReview is a department capability (2026-08-28 fix) ──
    def test_can_review_uses_review_roles_not_override(self):
        # Regression: canReview() used to delegate to staffCanOverride()
        # (admin-only), which locked hr_dept / mezmur_dept out of their
        # own review inboxes. Reviewing stays dept+admins; sheet-lock
        # overrides stay admin-only.
        for src in (self.sub,):
            idx = src.index("function canReview")
            body = src[idx:idx + 700]
            self.assertIn("REVIEW_ROLES", body)
            self.assertNotIn("return self::staffCanOverride", body)
        mez = (ROOT / "admin/backend/services/MezmurSubmissionService.php").read_text(encoding="utf-8")
        idx = mez.index("function canReview")
        body = mez[idx:idx + 700]
        self.assertIn("REVIEW_ROLES", body)
        self.assertNotIn("return self::staffCanOverride", body)


class HrEndpointContracts(unittest.TestCase):
    """Retired server contract (2026-10-07): writes 410, reads live."""

    @classmethod
    def setUpClass(cls):
        cls.console = (ROOT / "admin/api_hr_attendance.php").read_text(encoding="utf-8")
        cls.v1 = (ROOT / "api/v1/routes/hr.php").read_text(encoding="utf-8")
        cls.router = (ROOT / "api/v1/index.php").read_text(encoding="utf-8")
        cls.acl = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")

    # ── mobile v1 route: reads stay, writes are retired ─────────
    def test_mobile_route_write_paths_are_retired(self):
        # Both POST handlers return an honest 410 with the machine code.
        self.assertEqual(self.v1.count("['code' => 'HR_ATTENDANCE_RETIRED']"), 2)
        self.assertIn("err('HR attendance was retired. HR no longer takes attendance", self.v1)
        self.assertIn("err('Reviewing HR attendance was retired", self.v1)
        self.assertEqual(self.v1.count(", 410,"), 2)
        # No write entry points remain in the route at all.
        self.assertNotIn("HrAttendanceService::saveSectionSheet", self.v1)
        self.assertNotIn("HrSubmissionService::upsert", self.v1)
        self.assertNotIn("HrSubmissionService::reviewPacket", self.v1)
        self.assertNotIn("begin_transaction", self.v1)
        self.assertNotIn("apiIdempotencyBegin", self.v1)

    def test_mobile_route_reads_stay_available(self):
        # History is preserved and readable for old apps + the archive.
        for action in ("sections", "days", "sheet", "submissions", "submission"):
            self.assertIn("'%s'" % action, self.v1)
        self.assertIn("HrAttendanceService::fetchSectionSheet", self.v1)
        self.assertIn("HrSubmissionService::listPackets", self.v1)
        self.assertIn("HrSubmissionService::detail", self.v1)

    def test_mobile_route_roles_and_isolation(self):
        self.assertIn("hr_attendance_taker", self.v1)
        self.assertIn("['hr_attendance_taker', 'hr_dept', 'school_admin', 'super_admin']", self.v1)
        # Retirement version handshake (was phase6-hr26).
        self.assertIn("'hr-retired-1'", self.v1)
        self.assertNotIn("phase6-hr26", self.v1)
        # never touches other departments' tables or routes
        self.assertNotIn("mezmur_", self.v1)
        self.assertNotIn("attendance_days", self.v1)

    # ── web console: read-only history surface ──────────────────
    def test_console_endpoint_guards(self):
        # auth: session re-check + role re-check (defense in depth)
        self.assertIn("admin_logged_in", self.console)
        self.assertIn("['super_admin', 'school_admin', 'hr_dept']", self.console)
        # CSRF on POST, per-user rate limiting, safe exception
        self.assertIn("validateCsrf", self.console)
        self.assertIn("SecurityRateLimiter", self.console)
        self.assertIn("set_exception_handler", self.console)
        # schema probe points operators at the right migration
        self.assertIn("sql/026_hr_attendance.sql", self.console)
        # version handshake (was phase6-hr26)
        self.assertIn("'hr-retired-1'", self.console)
        self.assertNotIn("phase6-hr26", self.console)
        # read actions stay
        for action in ("sections", "submissions_list", "submission_detail",
                       "days_list", "takers_list"):
            self.assertIn("'%s'" % action, self.console)

    def test_console_review_action_is_retired(self):
        self.assertIn("http_response_code(410)", self.console)
        self.assertIn("'HR_ATTENDANCE_RETIRED'", self.console)
        self.assertIn("read-only history now", self.console)
        # The review write no longer reaches the service.
        self.assertNotIn("HrSubmissionService::reviewPacket", self.console)

    def test_router_and_acl_registration(self):
        self.assertIn("'hr'            => 'hr.php'", self.router)
        self.assertIn("api_hr_attendance.php", self.acl)


class HrMobileContracts(unittest.TestCase):
    """Flutter side: HR sheet UX clone + separate offline outbox."""

    @classmethod
    def setUpClass(cls):
        app = ROOT / "Mobile/wbws_flutter_app/lib"
        cls.screen = (app / "screens/hr/hr_attendance.dart").read_text(encoding="utf-8")
        cls.localdb = (app / "services/local_db.dart").read_text(encoding="utf-8")
        cls.sync = (app / "services/sync_service.dart").read_text(encoding="utf-8")
        cls.api = (app / "services/api_service.dart").read_text(encoding="utf-8")
        cls.config = (app / "utils/config.dart").read_text(encoding="utf-8")
        cls.shell = (app / "screens/shell/app_shell.dart").read_text(encoding="utf-8")

    def test_screen_clones_teacher_workflow_on_hr_endpoints(self):
        self.assertIn("getHrSections", self.screen)
        self.assertIn("getHrSheet", self.screen)
        # Telegram-send model: SQLite write IS the save
        self.assertIn("saveHrLocal", self.screen)
        self.assertIn("PacketLock", self.screen)
        self.assertIn("showEthiopianDatePicker", self.screen)
        self.assertIn("sql/026_hr_attendance.sql", self.screen)

    def test_offline_outbox_is_separate_from_mezmur(self):
        for table in ("pending_hr", "cached_hr_sheet", "cached_hr_sections"):
            self.assertIn(table, self.localdb)
        # Current schema version is centralized; v12 HR DDL must remain in
        # both the historical migration path and fresh-install schema.
        self.assertIn("version: localDatabaseSchemaVersion", self.localdb)
        self.assertIn("CREATE TABLE pending_hr", self.localdb)
        # The generic immutable legacy drain flushes HR packets through
        # /hr/sheet and settles only the exact claimed operation.
        self.assertIn("LegacyOperationKind.hr", self.sync)
        self.assertIn("saveHrSheet", self.sync)
        self.assertIn("settleLegacyOperation", self.sync)
        self.assertIn("pendingHr", self.sync)

    def test_api_client_and_navigation(self):
        for method in ("getHrDays", "getHrSheet", "saveHrSheet", "getHrSections"):
            self.assertIn(method, self.api)
        self.assertIn("/hr/sheet", self.api)
        self.assertIn("hr_attendance", self.config)
        self.assertIn("hr_attendance", self.shell)
        self.assertIn("HrAttendanceScreen", self.shell)


if __name__ == "__main__":
    unittest.main()
