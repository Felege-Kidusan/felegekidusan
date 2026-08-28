"""Scale hardening — Phase E contracts (2026-08-28).

The system must stay fast at hundreds of thousands of attendance
rows. Strategy (researched: CQRS read models + governed reads):

  1. Dashboard/analytics reads hit the pre-aggregated
     attendance_rollup (migration 027) — O(days × groups), never a
     raw-table scan.
  2. Every read path carries a hard budget (page size, window
     width, row caps) so no request can grow with the dataset.
  3. Member-based reads (the only raw-table paths left) get exact
     (member_id, attendance_date) seek indexes (migration 028).

Locked contracts below.
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]


class RollupReadModelContracts(unittest.TestCase):
    """Analytics reads come from the rollup, not raw tables."""

    @classmethod
    def setUpClass(cls):
        cls.svc = (ROOT / "admin/backend/services/InfoAnalyticsService.php").read_text(encoding="utf-8")

    def test_read_apis_use_rollup_only(self):
        # every read method body queries attendance_rollup
        for fn in ("kpiBand", "trends", "groupTable", "sourceMeta"):
            idx = self.svc.index(f"function {fn}")
            body = self.svc[idx:idx + 2600]
            self.assertIn("FROM attendance_rollup", body, f"{fn} must read the rollup")

    def test_reads_never_scan_raw_attendance_tables(self):
        for fn in ("kpiBand", "trends", "groupTable", "sourceMeta"):
            idx = self.svc.index(f"function {fn}")
            body = self.svc[idx:idx + 2600]
            for table in ("FROM attendance ", "FROM mezmur_attendance", "FROM hr_attendance"):
                self.assertNotIn(table, body, f"{fn} must not scan `{table}`")


class ReadBudgetContracts(unittest.TestCase):
    """No request may grow unboundedly with the dataset."""

    @classmethod
    def setUpClass(cls):
        cls.svc = (ROOT / "admin/backend/services/InfoAnalyticsService.php").read_text(encoding="utf-8")
        cls.pdf = (ROOT / "admin/backend/services/PdfReportService.php").read_text(encoding="utf-8")

    def test_page_size_budget(self):
        self.assertIn("MAX_PER_PAGE = 200", self.svc)
        self.assertIn("min(max(1, $perPage), self::MAX_PER_PAGE)", self.svc)

    def test_window_budget(self):
        self.assertIn("MAX_TREND_DAYS = 366", self.svc)

    def test_trend_and_meta_row_caps(self):
        self.assertIn("LIMIT 400", self.svc)   # trend rows
        self.assertIn("LIMIT 300", self.svc)   # source meta groups

    def test_sort_columns_whitelisted(self):
        self.assertIn("sortWhitelist", self.svc)

    def test_pdf_member_history_cap(self):
        self.assertIn("MAX_MEMBER_ROWS = 200", self.pdf)
        # each member source history applies the cap
        self.assertEqual(self.pdf.count("LIMIT \" . self::MAX_MEMBER_ROWS"), 3)

    def test_endpoint_rate_limits(self):
        ep = (ROOT / "admin/api_info_analytics.php").read_text(encoding="utf-8")
        self.assertIn("240", ep)  # reads/min
        self.assertIn("? 10 : 240", ep)
        rp = (ROOT / "admin/api_info_reports.php").read_text(encoding="utf-8")
        self.assertIn("30, 60", rp)  # 30 PDF builds/min


class ScaleIndexMigrationContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = (ROOT / "sql/028_analytics_scale.sql").read_text(encoding="utf-8")

    def test_guarded_idempotent_procedure(self):
        self.assertIn("CREATE PROCEDURE `ssms_add_index_if_missing_028`", self.sql)
        self.assertIn("information_schema.STATISTICS", self.sql)
        self.assertIn("DROP PROCEDURE IF EXISTS `ssms_add_index_if_missing_028`;", self.sql)

    def test_member_history_seek_indexes(self):
        self.assertIn(
            "CALL ssms_add_index_if_missing_028('mezmur_attendance', 'idx_mezmur_att_member_date', '`member_id`, `attendance_date`');",
            self.sql,
        )
        self.assertIn(
            "CALL ssms_add_index_if_missing_028('attendance', 'idx_att_member_date', '`member_id`, `attendance_date`');",
            self.sql,
        )

    def test_no_destructive_statements(self):
        self.assertNotIn("DROP INDEX", self.sql)
        self.assertNotIn("DROP TABLE", self.sql)
        self.assertNotIn("TRUNCATE", self.sql)


if __name__ == "__main__":
    unittest.main()
