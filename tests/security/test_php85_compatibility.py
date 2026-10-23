"""PHP 8.5 compatibility guards.

Production was observed running PHP 8.5 on 2026-10-23, when every request
logged "Constant PDO::MYSQL_ATTR_INIT_COMMAND is deprecated since 8.5".
These guards stop first-party code from reintroducing constants that PHP 8.4
or 8.5 deprecated. Supported runtimes remain PHP 8.2 to 8.5:
Pdo\\Mysql::* exists from 8.4, so code picks it when available and falls
back to the old name through constant() on older versions.
"""
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SKIP = ("vendor/", "/vendor/", "id_cards/libs/", "admin/backend/pdf/tcpdf/")


def first_party_php():
    files = subprocess.run(["git", "ls-files", "*.php"], cwd=ROOT,
                           capture_output=True, text=True, check=True).stdout.split()
    return [f for f in files if not any(s in f or f.startswith(s) for s in SKIP)]


def code_only(text):
    # Drop comments so explanatory notes may name the old constants.
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    return re.sub(r"(?m)(^|\s)(//|#).*$", r"\1", text)


class Php85Compatibility(unittest.TestCase):
    def setUp(self):
        self.files = first_party_php()
        self.assertGreater(len(self.files), 250)

    def offenders(self, pattern):
        rx = re.compile(pattern)
        return [f for f in self.files
                if rx.search(code_only((ROOT / f).read_text(encoding="utf-8", errors="ignore")))]

    def test_no_direct_pdo_mysql_attr_constants(self):
        # Deprecated since PHP 8.5. constant('PDO::MYSQL_ATTR_...') inside a
        # string is the allowed fallback for PHP < 8.4.
        self.assertEqual(self.offenders(r"(?<!['\"])\bPDO::MYSQL_ATTR_\w+"), [])

    def test_no_e_strict_constant(self):
        # Deprecated since PHP 8.4 (the error level was removed).
        self.assertEqual(self.offenders(r"\bE_STRICT\b"), [])

    def test_connection_uses_version_safe_init_command(self):
        config = (ROOT / "config.php").read_text(encoding="utf-8")
        self.assertIn("class_exists('Pdo\\Mysql', false)", config)
        self.assertIn("\\Pdo\\Mysql::ATTR_INIT_COMMAND", config)
        self.assertIn("constant('PDO::MYSQL_ATTR_INIT_COMMAND')", config)
        self.assertIn('$ssmsMysqlInitCommand => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"', config)


if __name__ == "__main__":
    unittest.main()
