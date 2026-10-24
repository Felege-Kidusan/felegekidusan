"""Regression pin: a guarded request value must also be read with a default.

Pattern that wrote "Undefined array key" warnings to the production error log
(fixed 2026-10-24 for `q` in api_education.php, and 2026-10-25 for 27 more
lines in 10 files):

    is_scalar($_GET['x'] ?? '') ? (string)$_GET['x'] : ''

When `x` is missing, the check sees '' (passes) but the read side has no
default, so PHP warns and the cast turns null into ''. The value is the same
as `(string)($_GET['x'] ?? '')`, which does not warn. The warnings also
reached the error monitor and became "Warning at ..." issues in Failure
Intelligence.

A guard whose default is null (e.g. `is_string($_REQUEST['a'] ?? null)`) is
safe: the check is false when the key is missing, so the read never runs.
"""

from pathlib import Path
import re
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
SKIP_PREFIXES = ("vendor/", "tests/", "sql/", "scripts/")

# check($_X['k'] ?? DEFAULT) ... ? (cast) $_X['k']   with no `??` after the read
PATTERN = re.compile(
    r"(is_\w+|filter_var|ctype_\w+|strlen|trim)\(\s*\$_(GET|POST|REQUEST)\[(['\"])(\w+)\3\]"
    r"\s*\?\?\s*([^)]*?)\)[^;\n]{0,40}?\?\s*(\([a-z]+\)\s*)?"
    r"\$_(GET|POST|REQUEST)\[(['\"])(\w+)\8\](?!\s*\?\?)"
)


def find_unguarded_reads(text: str):
    hits = []
    for lineno, line in enumerate(text.splitlines(), 1):
        for m in PATTERN.finditer(line):
            same_value = m.group(2) == m.group(7) and m.group(4) == m.group(9)
            guard_default = m.group(5).strip().lower()
            if same_value and guard_default != "null":
                hits.append((lineno, f"$_{m.group(2)}['{m.group(4)}']"))
    return hits


class RequestReadsAreGuardedTests(unittest.TestCase):
    def test_pattern_detects_the_bad_shape_and_accepts_the_fixed_ones(self):
        bad = "$a = is_scalar($_GET['q'] ?? '') ? (string)$_GET['q'] : '';"
        bad_string = "$s = is_string($_POST['scope'] ?? '') ? (string)$_POST['scope'] : 'alerts';"
        fixed = "$a = is_scalar($_GET['q'] ?? '') ? (string)($_GET['q'] ?? '') : '';"
        null_guard = "$a = is_string($_REQUEST['action'] ?? null) ? $_REQUEST['action'] : '';"
        self.assertEqual(len(find_unguarded_reads(bad)), 1)
        self.assertEqual(len(find_unguarded_reads(bad_string)), 1)
        self.assertEqual(find_unguarded_reads(fixed), [])
        self.assertEqual(find_unguarded_reads(null_guard), [])

    def test_no_unguarded_request_reads_in_tracked_php(self):
        files = subprocess.run(
            ["git", "ls-files", "*.php"], cwd=ROOT, capture_output=True, text=True, check=True
        ).stdout.split()
        self.assertGreater(len(files), 100, "git ls-files returned too few PHP files")
        found = []
        for rel in files:
            if rel.startswith(SKIP_PREFIXES):
                continue
            text = (ROOT / rel).read_text(encoding="utf-8", errors="replace")
            found += [f"{rel}:{n} {key}" for n, key in find_unguarded_reads(text)]
        self.assertEqual(
            found, [],
            "Read the value with a default too, e.g. (string)($_GET['x'] ?? ''):\n" + "\n".join(found),
        )


if __name__ == "__main__":
    unittest.main()
