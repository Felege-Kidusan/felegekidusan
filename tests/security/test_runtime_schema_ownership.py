"""Regression checks that shared bootstrap never mutates database schema."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class RuntimeSchemaOwnershipTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.config = (ROOT / "config.php").read_text(encoding="utf-8")
        cls.migration = (
            ROOT / "sql/012_runtime_schema_baseline.sql"
        ).read_text(encoding="utf-8")

    def test_shared_request_bootstrap_contains_no_schema_queries(self):
        for statement in ("CREATE TABLE", "ALTER TABLE", "SHOW TABLES", "SHOW COLUMNS"):
            self.assertNotIn(statement, self.config)
        self.assertNotIn("_db_checked", self.config)
        self.assertNotIn("_healSchema", self.config)

    def test_deployment_migration_owns_previous_bootstrap_tables(self):
        for table in (
            "notifications",
            "activity_logs",
            "system_branding",
            "academic_years",
            "classes",
        ):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS `{table}`", self.migration)

    def test_repeat_safe_upgrade_covers_previous_compatibility_columns(self):
        for column in (
            "last_login",
            "member_id",
            "spiritual_education",
            "current_class_id",
            "promoted_at",
            "academic_status",
            "group_name_en",
            "leader_full_name_en",
        ):
            self.assertIn(f"column_name='{column}'", self.migration)
        self.assertIn("information_schema.columns", self.migration)
        self.assertIn("PREPARE wbws_schema_stmt", self.migration)

    def test_branding_seed_is_repeat_safe_even_on_legacy_schema(self):
        self.assertGreaterEqual(self.migration.count("WHERE NOT EXISTS"), 4)
        self.assertNotIn("INSERT IGNORE INTO `system_branding`", self.migration)


if __name__ == "__main__":
    unittest.main()
