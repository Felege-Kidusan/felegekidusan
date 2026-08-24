"""Regression checks for fail-closed, non-diagnostic HTTP errors."""
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
RUNTIME_ROOTS = (ROOT / "admin", ROOT / "backend", ROOT / "api")
EXCLUDED_PARTS = {"vendor", "migrations", "libs", "tools"}


class ErrorDisclosureTests(unittest.TestCase):
    def runtime_php_sources(self):
        for base in RUNTIME_ROOTS:
            for path in base.rglob("*.php"):
                if EXCLUDED_PARTS.intersection(path.relative_to(ROOT).parts):
                    continue
                yield path, path.read_text(encoding="utf-8", errors="replace")

    def test_http_error_builders_do_not_embed_diagnostics(self):
        diagnostic = re.compile(
            r"(?:json_encode|apiResponse|\berr\s*\(|\bout\s*\()[^\n;]*"
            r"(?:->\s*error\b|getMessage\s*\(|errorInfo\s*\()",
            re.IGNORECASE,
        )
        offenders = []
        for path, source in self.runtime_php_sources():
            for match in diagnostic.finditer(source):
                line = source.count("\n", 0, match.start()) + 1
                offenders.append(f"{path.relative_to(ROOT)}:{line}")
        self.assertEqual([], offenders)

    def test_batch_error_details_do_not_expose_exceptions(self):
        offenders = []
        pattern = re.compile(r"error_details[^\n;]*getMessage\s*\(", re.IGNORECASE)
        for path, source in self.runtime_php_sources():
            if pattern.search(source):
                offenders.append(path.relative_to(ROOT).as_posix())
        self.assertEqual([], offenders)

    def test_shared_internal_logger_normalizes_and_limits_details(self):
        config = (ROOT / "config.php").read_text(encoding="utf-8")
        self.assertIn("function reportInternalError", config)
        self.assertIn("preg_replace('/[\\r\\n]+/'", config)
        self.assertIn("strlen($detail) > 2000", config)
        self.assertIn("random_bytes(6)", config)

    def test_mobile_api_routes_use_generic_database_failures(self):
        for relative in (
            "api/v1/routes/classes.php",
            "api/v1/routes/grades.php",
            "api/v1/routes/members.php",
        ):
            source = (ROOT / relative).read_text(encoding="utf-8")
            self.assertNotRegex(source, r"err\([^\n]*(?:getMessage\(|->error)", relative)


if __name__ == "__main__":
    unittest.main()
