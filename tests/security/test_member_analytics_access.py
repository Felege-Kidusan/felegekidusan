"""Member Analytics & Export Centre (admin/reports.php) — access + governance.

2026-10-18 owner decision (Option A): the Information and HR departments
analyse and export member demographics, so they may open reports.php and
its data endpoint api_reports.php (both read-only). They already had every
export behind the page (export_pdf.php, api_export_members.php).

Also pins the fixes shipped with it:
  • api_reports.php: role re-check, GET-only, action whitelist, per-user
    rate limit, audit of bulk member reads, LIKE wildcard escaping,
    analytics totals failure surfaces as an error (not silent zeros).
  • reports.php: the Data Explorer "Show Charts" ReferenceError (`u6`,
    leftover from the under-6 removal) is gone.
  • A permission bounce is explained on the dashboard instead of looking
    like a dead button (index.php keeps ?error=access_denied).
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class AccessMatrix(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.acl = read("admin/access_control.php")
        cls.page = read("admin/reports.php")
        cls.api = read("admin/api_reports.php")
        cls.exp = read("admin/export_pdf.php")
        cls.roster = read("admin/api_export_members.php")

    def test_role_map_matches_exports(self):
        allowed = "['super_admin', 'school_admin', 'info_dept', 'hr_dept']"
        self.assertIn("'api_reports.php' => " + allowed, self.acl)
        self.assertIn("'reports.php'     => " + allowed, self.acl)
        # the exports behind the page were already open to the same roles
        self.assertIn("'export_pdf.php'  => " + allowed, self.acl)
        self.assertIn("'api_export_members.php' => " + allowed, self.acl)
        self.assertIn(allowed, self.exp)
        self.assertIn(allowed, self.roster)

    def test_defense_in_depth_in_files(self):
        allowed = "['super_admin', 'school_admin', 'info_dept', 'hr_dept']"
        self.assertIn(allowed, self.page)
        self.assertIn(allowed, self.api)
        # no other department sneaks in
        for role in ("edu_dept", "teacher", "finance_dept", "material_dept", "mezmur_dept"):
            self.assertNotIn("'%s'" % role, self.api)

    def test_links_now_resolve_for_their_audience(self):
        for dash in ("admin/dashboards/info-dept.php", "admin/dashboards/hr-dept.php"):
            self.assertIn('href="/admin/reports.php"', read(dash))


class ApiGovernance(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = read("admin/api_reports.php")

    def test_read_only_surface(self):
        self.assertIn("!== 'GET'", self.api)
        self.assertIn("['get_analytics', 'get_filtered_members', 'get_filter_options']", self.api)
        for verb in ("INSERT", "UPDATE ", "DELETE", "REPLACE"):
            self.assertNotIn(verb, self.api)

    def test_rate_limit_and_audit(self):
        self.assertIn("SecurityRateLimiter", self.api)
        self.assertIn("'member_analytics_read'", self.api)
        self.assertIn("SecurityAuditService::record", self.api)
        self.assertIn("'Member Analytics Explorer Queried'", self.api)
        # audit never stores the raw free-text search (only its length)
        self.assertIn("'search_len'", self.api)

    def test_filters_are_bound_and_like_escaped(self):
        self.assertIn("$stmt->bind_param($types, ...$params)", self.api)
        self.assertIn("addcslashes(", self.api)
        self.assertIn("LIMIT 2000", self.api)
        # no interpolated user input in SQL strings
        self.assertIsNone(re.search(r"\$_GET\[[^\]]+\]\s*\.\s*\"", self.api))

    def test_totals_failure_is_an_error_not_zeros(self):
        self.assertIn("throw new RuntimeException('analytics totals query failed')", self.api)
        self.assertIn("catch (Throwable $e)", self.api)
        self.assertNotIn("$e->getMessage()]", self.api)  # never echoed to the client


class PageFixes(unittest.TestCase):
    def test_under6_chart_bug_removed(self):
        page = read("admin/reports.php")
        self.assertNotIn("u6", re.sub(r"[A-Za-z0-9_]u6|u6[A-Za-z0-9_]", "", page))
        self.assertNotIn("'Under 6'", page)
        self.assertIn("labels:Object.values(RP_SECTIONS),datasets:[{data:[a13,a17,a18]", page)

    def test_access_denied_bounce_is_explained(self):
        index = read("admin/index.php")
        self.assertIn("'?error=access_denied'", index)
        for dash in ("admin/dashboards/info-dept.php", "admin/dashboards/hr-dept.php",
                     "admin/dashboards/school_admin.php"):
            self.assertIn("searchParams.get('error') === 'access_denied'", read(dash))
            self.assertIn("You do not have permission to open that page.", read(dash))


if __name__ == "__main__":
    unittest.main()
