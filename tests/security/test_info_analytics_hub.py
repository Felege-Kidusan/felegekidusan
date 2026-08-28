"""Information-department analytics hub — Phase C contracts (2026-08-28).

Product rule: THREE independent attendance sources (Education classes,
Mezmur sections, HR sections). Their data is NEVER combined and their
takers are NEVER shared. The Information department is the main
analytics hub but is strictly READ-ONLY: it analyzes, compares and
exports — it never records or edits attendance.

Architecture: a governed rollup read model (attendance_rollup) rebuilt
by ONE writer (InfoAnalyticsService::refreshRollup), consumed by a
governed endpoint (api_info_analytics.php) that info_dept can read but
never write.

Locked contracts:
  • sql/027 creates attendance_rollup idempotently, one row per
    source+date+group, never merging identities across sources
  • InfoAnalyticsService is the only writer and only touches the
    rollup table — never INSERT/UPDATE/DELETE on the source tables
  • reads are budgeted (page size / window length) for 100k+ scale
  • endpoint: info_dept + admins read; refresh is ADMIN-only; CSRF on
    writes; per-user rate limits; every access audited
  • web hub renders KPI band / comparison / drill-down / Excel export;
    the mobile info home is a read-only note (no attendance taking)
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]

# source tables owned by the three departments — the hub must never write them
SOURCE_TABLES = (
    "attendance",            # edu
    "mezmur_attendance",
    "mezmur_submissions",
    "hr_attendance",
    "hr_submissions",
)


class RollupSchemaContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = (ROOT / "sql/027_attendance_rollup.sql").read_text(encoding="utf-8")

    def test_table_shape(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `attendance_rollup`", self.sql)
        self.assertIn("ENUM('edu','mezmur','hr')", self.sql)
        self.assertIn("uq_rollup_source_date_group", self.sql)
        self.assertIn("idx_rollup_source_date", self.sql)
        # source identity preserved on every row — no merged identity
        self.assertIn("`source`", self.sql)
        # idempotent migration
        self.assertIn("IF NOT EXISTS", self.sql)


class InfoAnalyticsServiceContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.src = (ROOT / "admin/backend/services/InfoAnalyticsService.php").read_text(encoding="utf-8")

    def test_source_whitelist(self):
        self.assertIn("public const SOURCES = ['edu', 'mezmur', 'hr'];", self.src)

    def test_only_writer_is_transactional_rebuild_of_rollup(self):
        idx = self.src.index("function refreshRollup")
        body = self.src[idx:idx + 5500]
        self.assertIn("begin_transaction", body)
        self.assertIn("rollback", body)
        self.assertIn("DELETE FROM attendance_rollup", body)
        self.assertIn("INSERT INTO attendance_rollup", body)

    def test_never_writes_source_tables(self):
        # word-boundary regex: `attendance` must not match `attendance_rollup`
        for table in SOURCE_TABLES:
            pat = re.compile(rf"\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?{re.escape(table)}`?\b(?!_)")
            self.assertIsNone(
                pat.search(self.src),
                f"analytics hub must never write `{table}`",
            )

    def test_mezmur_section_derived_from_member_directory(self):
        # mezmur_attendance has NO section column — derive from members
        self.assertIn("COALESCE(NULLIF(TRIM(mb.current_section), ''), '—')", self.src)

    def test_edu_excludes_holiday_rows(self):
        self.assertIn("WHERE a.status <> 'holiday'", self.src)

    def test_read_budgets_for_scale(self):
        self.assertIn("private const MAX_PER_PAGE = 200;", self.src)
        self.assertIn("private const MAX_TREND_DAYS = 366;", self.src)

    def test_read_api_surface(self):
        for method in ("kpiBand", "trends", "groupTable", "comparison", "sourceMeta"):
            self.assertIn(f"function {method}", self.src)


class InfoAnalyticsEndpointContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ep = (ROOT / "admin/api_info_analytics.php").read_text(encoding="utf-8")
        cls.acl = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")

    def test_read_roles(self):
        self.assertIn("['super_admin', 'school_admin', 'info_dept']", self.ep)

    def test_refresh_is_admin_only(self):
        self.assertIn("['super_admin', 'school_admin']", self.ep)
        self.assertIn(
            "The Information department is read-only. Only administrators can refresh the analytics data.",
            self.ep,
        )

    def test_csrf_and_rate_limits(self):
        self.assertIn("validateCsrf", self.ep)
        self.assertIn("SecurityRateLimiter", self.ep)
        self.assertIn("info_analytics_read", self.ep)
        self.assertIn("info_analytics_write", self.ep)

    def test_every_access_audited(self):
        self.assertIn("SecurityAuditService::record", self.ep)
        self.assertIn("Info Analytics Viewed", self.ep)
        self.assertIn("Info Analytics Refreshed", self.ep)

    def test_version_marker_and_schema_probe(self):
        self.assertIn("phase7-info27", self.ep)
        self.assertIn("sql/027_attendance_rollup.sql", self.ep)

    def test_endpoint_never_writes_source_tables(self):
        for table in SOURCE_TABLES:
            pat = re.compile(rf"\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?{re.escape(table)}`?\b(?!_)")
            self.assertIsNone(pat.search(self.ep), f"endpoint must never write `{table}`")

    def test_endpoint_only_writes_through_service_refresh(self):
        # the sole write action delegates to the single-writer service
        self.assertIn("InfoAnalyticsService::refreshRollup", self.ep)

    def test_role_map_registration(self):
        self.assertIn(
            "'api_info_analytics.php' => ['super_admin', 'school_admin', 'info_dept']",
            self.acl,
        )


class InfoHubWebContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.page = (ROOT / "admin/dashboards/info-dept.php").read_text(encoding="utf-8")

    def test_analytics_surface_wired(self):
        self.assertIn('id="section-analytics"', self.page)
        self.assertIn("InfoHub", self.page)
        self.assertIn("api_info_analytics.php", self.page)
        # Excel export path
        self.assertIn("xlsx/0.18.5/xlsx.full.min.js", self.page)
        self.assertIn("InfoHub.exportAll", self.page)

    def test_read_only_posture_in_ui(self):
        self.assertIn("view-only", self.page)
        # refresh button is admin-gated server-side
        self.assertIn("in_array($userRole, ['super_admin', 'school_admin'], true)", self.page)

    def test_member_category_require_path_fixed(self):
        # regression: a dashboards-relative path used to break the whole page
        self.assertIn("require_once __DIR__ . '/../backend/services/MemberCategory.php';", self.page)
        self.assertNotIn("require_once __DIR__ . '/backend/services/MemberCategory.php';", self.page)


class InfoHubMobileContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.home = (ROOT / "Mobile/wbws_flutter_app/lib/screens/info_dept/info_home.dart").read_text(encoding="utf-8")

    def test_no_attendance_taking_from_info_home(self):
        self.assertNotIn("AttendanceScreen", self.home)
        self.assertNotIn("attendance_screen.dart", self.home)

    def test_read_only_note_present(self):
        self.assertIn("Analytics is read-only here", self.home)
        self.assertIn("never records attendance", self.home)


if __name__ == "__main__":
    unittest.main()
