"""Finding S: destructive lifecycle harnesses must fail closed.

tests/e2e/comm_lifecycle.php and comm_v1_lifecycle.php DROP TABLE and
TRUNCATE users/notifications/department_tasks in whatever database
.fkss_env.php names -- the same filename a production deployment uses, and
config.php explicitly supports a repo-root copy as a fallback layout.

Measured harm on the unfixed baseline (2026-10-02): run against a database
restored from the production dump and named arkeonet_felegekidusan, the
unguarded harness destroyed 4 tables (87 -> 83) before dying on an unrelated
foreign-key error. The guarded harness exits 2 with all 87 intact.

The required property is NOT "the script prints a warning". It is that the
destructive operation is BLOCKED unless the target is provably disposable.
These tests assert the five cases from the audit brief at RUNTIME -- they
execute the real scripts rather than reading them for reassuring words. A
source-scanning version of this test would pass against a script whose only
safety feature is a comment.

Every case runs against a throwaway database. None touches production.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HARNESSES = ["tests/e2e/comm_lifecycle.php", "tests/e2e/comm_v1_lifecycle.php"]
GUARD = ROOT / "tests/e2e/destructive_guard.php"
REFUSED = 2

PHP = os.environ.get("SSMS_E2E_PHP") or shutil.which("php")
SANDBOX_DB = os.environ.get("SSMS_DB_NAME", "ssms_comm_e2e")

# A name with no test/e2e/smoke/sandbox token in it. This is the real
# production database name; it is never created or contacted by these tests,
# it is only passed to the guard so we can assert the guard refuses it.
PRODUCTION_SHAPED_NAME = "arkeonet_felegekidusan"


def run_harness(script: str, db: str, marker=None, extra=None, arg="full"):
    env = dict(os.environ)
    env["SSMS_DB_NAME"] = db
    env.pop("SSMS_AUDIT_TESTING", None)
    env.pop("SSMS_DISPOSABLE_DB", None)
    if marker is not None:
        env["SSMS_AUDIT_TESTING"] = marker
    if extra:
        env.update(extra)
    return subprocess.run(
        [PHP, str(ROOT / script), arg],
        capture_output=True, text=True, timeout=180, cwd=str(ROOT), env=env,
    )


@unittest.skipIf(PHP is None, "php CLI not available")
class GuardFailsClosed(unittest.TestCase):
    """Cases 1, 3 and 4: the destructive op must be blocked."""

    def test_case1_production_named_database_is_blocked(self):
        for script in HARNESSES:
            with self.subTest(script=script):
                r = run_harness(script, PRODUCTION_SHAPED_NAME, marker="1")
                self.assertEqual(
                    REFUSED, r.returncode,
                    f"{script} did not refuse a production-named database even "
                    f"though the operator set the testing marker. On the "
                    f"unfixed baseline this destroyed 4 tables.\n"
                    f"{r.stdout[-600:]}{r.stderr[-600:]}",
                )
                self.assertIn("REFUSED", r.stdout + r.stderr)

    def test_case3_missing_marker_fails_closed(self):
        for script in HARNESSES:
            with self.subTest(script=script):
                r = run_harness(script, SANDBOX_DB, marker=None)
                self.assertEqual(
                    REFUSED, r.returncode,
                    f"{script} ran destructively with no SSMS_AUDIT_TESTING "
                    f"marker at all; absence of consent must not read as "
                    f"consent.\n{r.stdout[-600:]}{r.stderr[-600:]}",
                )

    def test_case4_malformed_or_invalid_marker_fails_closed(self):
        # Every value a human might reasonably expect to mean "yes", plus
        # whitespace-padded forms. Only the exact string '1' may pass.
        for bad in ["", "0", "true", "True", "TRUE", "yes", "y", "on",
                    " 1", "1 ", "01", "1.0", "2", "-1", "null", "false"]:
            for script in HARNESSES:
                with self.subTest(script=script, marker=repr(bad)):
                    r = run_harness(script, SANDBOX_DB, marker=bad)
                    self.assertEqual(
                        REFUSED, r.returncode,
                        f"{script} treated SSMS_AUDIT_TESTING={bad!r} as "
                        f"consent. Only the exact string '1' may authorise a "
                        f"destructive run.\n{r.stdout[-400:]}{r.stderr[-400:]}",
                    )

    def test_empty_database_name_fails_closed(self):
        """Called directly, not through a harness.

        An earlier version of this test set SSMS_DB_NAME="" and expected the
        run to be refused. It was not: .fkss_env.php reads
        getenv('SSMS_DB_NAME') ?: 'ssms_e2e', so an empty value falls back to
        a real database and the harness destroyed it. The test was both
        wrong and dangerous. The guard is exercised in isolation instead.
        """
        probe = (
            "require '%s'; ssms_require_disposable_database('', 'probe');"
            % (ROOT / "tests/e2e/destructive_guard.php")
        )
        r = subprocess.run(
            [PHP, "-r", probe], capture_output=True, text=True, timeout=60,
            env={**os.environ, "SSMS_AUDIT_TESTING": "1"},
        )
        self.assertEqual(REFUSED, r.returncode,
                         "an unresolved database name must not be destroyed")

    def test_whitespace_only_database_name_fails_closed(self):
        probe = (
            "require '%s'; ssms_require_disposable_database('   ', 'probe');"
            % (ROOT / "tests/e2e/destructive_guard.php")
        )
        r = subprocess.run(
            [PHP, "-r", probe], capture_output=True, text=True, timeout=60,
            env={**os.environ, "SSMS_AUDIT_TESTING": "1"},
        )
        self.assertEqual(REFUSED, r.returncode)


@unittest.skipIf(PHP is None, "php CLI not available")
class GuardAllowsLegitimateUse(unittest.TestCase):
    """Case 2: the guard must not break the job the harness exists to do.

    A gate that blocks everything is not a fix, it is an outage. This is the
    half of the property that a 'does it refuse?' test cannot see.
    """

    def test_case2_sandbox_database_still_executes(self):
        r = run_harness(HARNESSES[0], SANDBOX_DB, marker="1")
        if r.returncode != 0 and "Access denied" in (r.stdout + r.stderr):
            self.skipTest("no database available to this runner")
        self.assertEqual(
            0, r.returncode,
            f"the interlock broke legitimate sandbox lifecycle use; the "
            f"harness must still run on a disposable database.\n"
            f"{r.stdout[-900:]}{r.stderr[-900:]}",
        )
        self.assertIn("E2E-VERDICT: PASS", r.stdout)

    def test_explicit_allowlist_lets_an_operator_opt_in(self):
        """Default-deny must stay overridable, or teams whose test database
        is named unconventionally will delete the guard instead."""
        r = run_harness(HARNESSES[0], SANDBOX_DB, marker="1",
                        extra={"SSMS_DISPOSABLE_DB": SANDBOX_DB})
        self.assertNotEqual(REFUSED, r.returncode,
                            "an explicitly allowlisted database was refused")


class GuardIsStructurallySound(unittest.TestCase):
    """Static properties that runtime cases cannot cover."""

    def test_guard_file_exists(self):
        self.assertTrue(GUARD.is_file(), "the shared interlock was removed")

    def test_guard_is_default_deny_not_a_denylist(self):
        import re as _re
        raw = GUARD.read_text(encoding="utf-8")
        # Strip comments first. The guard's header documents WHY a denylist
        # is unsafe and names the production database as the example -- a
        # naive substring scan matches that prose and reports the file as
        # its own violation. Anchor on executable code only. (Line comments
        # must be removed before block comments, or a "//" line containing
        # "/*" opens a phantom block.)
        body = _re.sub(r"(?m)//.*$", "", raw)
        body = _re.sub(r"/\*.*?\*/", "", body, flags=_re.S)
        # The guard must decide by recognising SAFE names, not by listing
        # known-dangerous ones; a denylist only stops the names someone
        # remembered to write down, and the real production database name
        # looks like an ordinary identifier.
        self.assertNotIn(
            PRODUCTION_SHAPED_NAME, body,
            "the interlock hardcodes the production database name, which "
            "means it is a denylist; it must recognise disposable names "
            "instead so unknown databases fail closed by default",
        )
        self.assertIn("SSMS_SAFE_NAME_PATTERN", body)
        self.assertRegex(body, r"!\$explicitlyAllowed\s*&&\s*!\$looksDisposable")

    def test_every_destructive_harness_loads_the_guard(self):
        """Finding S closes only if the sweep stays closed. A new lifecycle
        script that DROPs or TRUNCATEs must load the interlock too."""
        import re
        executed = re.compile(
            r"""query\(\s*["'`]\s*(?:DROP\s+TABLE|TRUNCATE)""", re.I)
        unguarded = []
        for php in sorted((ROOT / "tests").rglob("*.php")):
            body = php.read_text(encoding="utf-8", errors="replace")
            # Anchor on executed syntax, never on bare prose: this file and
            # the guard both *describe* DROP TABLE, and mezmur_phase5_smoke
            # contains a "DROP TABLE" SQL-injection payload inside a string
            # it asserts is safely rejected. None of those are destructive.
            if not executed.search(body):
                continue
            if "ssms_require_disposable_database" not in body:
                unguarded.append(php.relative_to(ROOT).as_posix())
        self.assertEqual(
            [], unguarded,
            "these harnesses execute DROP/TRUNCATE with no interlock: "
            + ", ".join(unguarded),
        )


if __name__ == "__main__":
    unittest.main()
