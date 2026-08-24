"""Regression checks for deployment-only schema, diagnostic, and test-data tools."""
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]


class DeploymentToolHardeningTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.access = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        cls.apache = (ROOT / ".htaccess").read_text(encoding="utf-8")
        cls.school = (ROOT / "school_config.php").read_text(encoding="utf-8")
        cls.environment = (ROOT / "env.example.php").read_text(encoding="utf-8")
        cls.seed_api = (ROOT / "admin/api_test_seed.php").read_text(encoding="utf-8")
        cls.super_dashboard = (
            ROOT / "admin/dashboards/super-admin.php"
        ).read_text(encoding="utf-8")
        cls.seed_service = (
            ROOT / "admin/backend/services/TestMemberSeed.php"
        ).read_text(encoding="utf-8")

    def test_legacy_php_migrations_are_directly_cli_only(self):
        paths = sorted((ROOT / "admin/migrations").glob("*.php"))
        paths += sorted((ROOT / "backend/migrations").glob("*.php"))
        self.assertTrue(paths)
        for path in paths:
            source = path.read_text(encoding="utf-8")
            guard = source.find("PHP_SAPI !== 'cli'")
            first_include = source.find("require_once")
            self.assertGreaterEqual(guard, 0, str(path.relative_to(ROOT)))
            self.assertTrue(
                first_include < 0 or guard < first_include,
                str(path.relative_to(ROOT)),
            )
            self.assertIn("versioned sql/*.sql migrations", source)

    def test_application_and_apache_deny_deployment_tools(self):
        self.assertIn("'/migrations/'", self.access)
        for tool in ("get_schema.php", "qr_diagnostic.php", "leak_detector.php"):
            self.assertIn(tool, self.access)
        for rule in (
            r"RewriteRule ^admin/migrations/",
            r"RewriteRule ^backend/migrations/",
            r"RewriteRule ^admin/get_schema\.php$",
            r"RewriteRule ^admin/id_cards/qr_diagnostic\.php$",
            r"RewriteRule (^|/)leak_detector\.php$",
        ):
            self.assertIn(rule, self.apache)

    def test_diagnostics_enforce_direct_non_http_contracts(self):
        for relative in ("leak_detector.php", "admin/leak_detector.php"):
            source = (ROOT / relative).read_text(encoding="utf-8")
            self.assertIn("PHP_SAPI !== 'cli'", source, relative)
        retired = (ROOT / "admin/get_schema.php").read_text(encoding="utf-8")
        self.assertIn("http_response_code(404)", retired)
        self.assertIn("versioned sql/*.sql migration", retired)
        self.assertNotIn("d:/FKSS", retired)
        self.assertNotIn("new mysqli", retired)

    def test_application_pages_do_not_link_to_web_migrations(self):
        offenders = []
        link = re.compile(r"href\s*=\s*['\"][^'\"]*migrations/", re.IGNORECASE)
        for path in ROOT.rglob("*.php"):
            relative = path.relative_to(ROOT).as_posix()
            if relative.startswith(("vendor/", "admin/vendor/")):
                continue
            if link.search(path.read_text(encoding="utf-8", errors="replace")):
                offenders.append(relative)
        self.assertEqual([], offenders)

    def test_test_data_mutation_is_explicitly_gated_and_super_admin_only(self):
        self.assertIn(
            "if (!defined('ENABLE_TEST_DATA_TOOLS')) define('ENABLE_TEST_DATA_TOOLS', false);",
            self.school,
        )
        self.assertIn("define('ENABLE_TEST_DATA_TOOLS', true);", self.environment)
        self.assertIn("ENABLE_TEST_DATA_TOOLS !== true", self.seed_api)
        self.assertIn("http_response_code(404)", self.seed_api)
        self.assertIn("$role !== 'super_admin'", self.seed_api)
        self.assertIn("session_write_close()", self.seed_api)
        self.assertIn("SecurityAuditService::record", self.seed_api)
        self.assertNotIn("$_REQUEST", self.seed_api)
        self.assertIn("'api_test_seed.php'      => ['super_admin']", self.access)
        self.assertIn("if ($testSeedEnabled)", self.super_dashboard)
        self.assertIn("if ($testSeedEnabled):", self.super_dashboard)
        self.assertIn("LOCK_EX | LOCK_NB", self.seed_service)
        self.assertIn("@rename($temporary, self::flagFile())", self.seed_service)
        self.assertNotIn("@file_put_contents(self::flagFile()", self.seed_service)


if __name__ == "__main__":
    unittest.main()
