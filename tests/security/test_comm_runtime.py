"""
comm.js runtime gate (P73 Phase 2.2)
═════════════════════════════════════════════════════════════════════════════
Executes the REAL admin/js/comm.js in a Node vm with a DOM shim and simulates
the exact user interactions that were dead in production (bell click,
Communication sidebar button, Escape, view switching, page-mode boot).

Why this exists: `node --check` proves syntax only; the PHP harness proves the
HTML renders only. A strict-mode block-scoping bug (function declarations
inside if/else blocks, referenced from outside) shipped through both gates and
killed every bell + communication button on every page. This gate executes the
runtime — the class of bug it catches cannot pass it.

Runs via `node tests/js/comm_runtime_test.js`; the test skips cleanly when a
Node runtime is unavailable (matching the suite's existing skip conventions).
"""
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "tests" / "js" / "comm_runtime_test.js"


class CommRuntimeTests(unittest.TestCase):
    def test_comm_js_runtime_interactions(self):
        node = shutil.which("node")
        if not node:
            self.skipTest("node runtime not available — JS runtime gate skipped")
        self.assertTrue(SCRIPT.is_file(), "tests/js/comm_runtime_test.js must exist")
        proc = subprocess.run(
            [node, str(SCRIPT)],
            capture_output=True, text=True, timeout=120, cwd=str(ROOT),
        )
        self.assertEqual(
            proc.returncode, 0,
            "comm.js runtime gate failed:\n" + proc.stdout + proc.stderr,
        )
        self.assertIn("PASS", proc.stdout)
        self.assertIn("zero handler errors", proc.stdout)


if __name__ == "__main__":
    unittest.main()
