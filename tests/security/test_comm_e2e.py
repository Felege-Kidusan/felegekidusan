"""
Communication E2E gate — REAL MariaDB lifecycle (P73, incident round)
═════════════════════════════════════════════════════════════════════════════
Drives the REAL admin/api_notifications.php + NotificationCenterService against
a REAL MySQL/MariaDB database via tests/e2e/comm_lifecycle.php. This is the
class of verification the offline harness cannot do: actual mysqli error modes,
actual migrations, actual session/CSRF gates.

Why this exists (2026-09-12 outage): the user's server ran PHP 8.1+ (mysqli
throw mode) with sql/043 not applied, and every conversation failed with
"Could not load the conversation." The scenarios pin the three guarantees that
must never regress:

  pre043   incident regression   — a conversation MUST open and badges MUST
                                   clear even when sql/043 is missing
  mid043   deploy-before-migration — sql/044 missing (the state production
                                   is in the moment this code deploys):
                                   conversations open, markers degrade,
                                   edit/delete fail with clean errors
  full     full lifecycle        — receipts watermark, ✓✓, edit + edited
                                   marker, delete + tombstone (body never
                                   shipped again), soft-delete audit trail,
                                   ownership + role matrix enforcement
  reset_full migration idempotency (043 + 044 re-run as no-ops)
  csrf_bad / unauth  fail-closed exits (asserted on raw output)

ENVIRONMENT (all optional — the test skips cleanly when absent):
  .fkss_env.php   in the repo root, pointing at a DEDICATED test database
                  (the runner drops/recreates the communication tables —
                  never point it at production).
  php             on PATH, or SSMS_E2E_PHP=/path/to/php (needs mysqli).
"""
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / "tests" / "e2e" / "comm_lifecycle.php"
ENV_FILE = ROOT / ".fkss_env.php"


def _php_binary():
    """PHP CLI to use: $SSMS_E2E_PHP wins, else `php` on PATH."""
    override = os.environ.get("SSMS_E2E_PHP", "").strip()
    if override:
        return override if Path(override).is_file() else None
    return shutil.which("php")


def _probe_db(php):
    """Exit 0 = e2e database reachable. Any other exit = skip."""
    probe = (
        "require %s; "
        "$m = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); "
        "exit($m->connect_errno ? 3 : 0);" % repr(str(ENV_FILE))
    )
    proc = subprocess.run(
        [php, "-r", probe], capture_output=True, text=True, timeout=30,
    )
    return proc.returncode == 0


class CommEndToEndTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — comm e2e skipped")
        if not RUNNER.is_file():
            raise unittest.SkipTest("tests/e2e/comm_lifecycle.php not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(
                ".fkss_env.php not present — comm e2e skipped "
                "(dedicated test DB not configured)"
            )
        if not _probe_db(php):
            raise unittest.SkipTest(
                "e2e database unreachable — comm e2e skipped"
            )
        cls.php = php

    def _run(self, scenario):
        return subprocess.run(
            [self.php, str(RUNNER), scenario],
            capture_output=True, text=True, timeout=300, cwd=str(ROOT),
        )

    def _assert_verdict_pass(self, proc, scenario):
        self.assertEqual(
            proc.returncode, 0,
            f"{scenario} exited {proc.returncode}:\n{proc.stdout}\n{proc.stderr}",
        )
        self.assertIn("E2E-VERDICT: PASS", proc.stdout,
                      f"{scenario} did not pass:\n{proc.stdout}")
        self.assertNotIn("E2E-FAIL", proc.stdout, proc.stdout)

    def test_incident_regression_pre043(self):
        """sql/043 missing: conversations open, receipts degrade, badges clear."""
        self._assert_verdict_pass(self._run("pre043"), "pre043")

    def test_deploy_before_migration_mid043(self):
        """sql/044 missing: conversations open, edit/delete degrade cleanly."""
        self._assert_verdict_pass(self._run("mid043"), "mid043")

    def test_full_lifecycle(self):
        """Full schema: receipts, edit/delete, tombstones, permission matrix."""
        self._assert_verdict_pass(self._run("full"), "full")

    def test_migrations_idempotent(self):
        """043 + 044 re-run as no-ops on a fully migrated schema."""
        proc = self._run("reset_full")
        self.assertEqual(proc.returncode, 0,
                         f"reset_full exited {proc.returncode}:\n{proc.stdout}")
        self.assertIn("E2E-PASS: full: 043+044 re-run idempotent", proc.stdout)
        self.assertIn("E2E-RESET: full", proc.stdout)

    def test_csrf_bad_token_fails_closed(self):
        """Wrong CSRF token → the API's 403 envelope, process exits."""
        proc = self._run("csrf_bad")
        self.assertIn("Security token expired", proc.stdout)
        self.assertNotIn("success", proc.stdout)

    def test_unauthenticated_fails_closed(self):
        """No session → Unauthorized envelope, process exits."""
        proc = self._run("unauth")
        self.assertIn("Unauthorized", proc.stdout)
        self.assertNotIn("success", proc.stdout)


if __name__ == "__main__":
    unittest.main()
