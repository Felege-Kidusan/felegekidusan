"""Every first-party PHP source must be structurally well formed.

Motivating incident (audit 2026-10-02, finding I — CRITICAL):
commit d865ee9 ("runtime DDL removal") edited
admin/backend/services/AssessmentTypeService.php and left SEVEN duplicated
lines — the tail of the seed INSERT plus three closing braces — sitting in
the class body AFTER ensureTable()'s closing brace:

        }                      <- ensureTable() ends here
                    ('participation', ...),
                    ('oral_exam', ...),
                    ('memorization', ...);
                ");
            }
        }
    }

That is not parseable PHP. The file is require_once'd by api/v1/routes/
grades.php, admin/api_subjects.php and admin/api_education.php, so every
assessment-type request would fatal. It sat on main from 2026-09-27 to
2026-10-02 because nothing in CI parses PHP (the CI workflow runs Flutter
only, and no .github/ directory is even present in the repository).

This test is the cheap, dependency-free guard that closes that gap: it
tokenises each file — skipping strings, comments, heredocs and escapes —
and asserts the brackets balance and every literal is closed.

LIMITATION, stated plainly: this is NOT `php -l`. It detects unbalanced
brackets, unterminated strings and unclosed block comments — the realistic
damage a bad merge or text edit does. It cannot detect parse or semantic
errors that happen to be bracket-balanced. Run `php -l` in an environment
that has the PHP binary for full coverage.
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCAN_DIRS = ("admin", "api", "backend", "frontend")
SCAN_FILES = ("config.php", "school_config.php")
SKIP_PARTS = ("/vendor/", "/node_modules/")

OPEN = {"(": ")", "[": "]", "{": "}"}
CLOSE = {v: k for k, v in OPEN.items()}


def structural_error(src: str):
    """Return a human-readable problem description, or None when clean."""
    i, n, line = 0, len(src), 1
    stack = []
    in_php = False
    while i < n:
        ch = src[i]
        if ch == "\n":
            line += 1
            i += 1
            continue
        if not in_php:
            j = src.find("<?php", i)
            if j == -1:
                break
            line += src.count("\n", i, j)
            i, in_php = j + 5, True
            continue
        if src.startswith("?>", i):
            in_php = False
            i += 2
            continue
        if src.startswith("//", i) or ch == "#":
            j = src.find("\n", i)
            i = n if j == -1 else j
            continue
        if src.startswith("/*", i):
            j = src.find("*/", i + 2)
            if j == -1:
                return f"line {line}: unterminated block comment"
            line += src.count("\n", i, j)
            i = j + 2
            continue
        if src.startswith("<<<", i):
            j = src.find("\n", i)
            if j == -1:
                return f"line {line}: malformed heredoc"
            tag = src[i + 3:j].strip().strip("'\"")
            end = src.find("\n" + tag, j)
            while end != -1:
                after = end + 1 + len(tag)
                if after >= n or not (src[after].isalnum() or src[after] == "_"):
                    break
                end = src.find("\n" + tag, after)
            if end == -1:
                return f"line {line}: unterminated heredoc <<<{tag}"
            line += src.count("\n", i, end)
            i = end + 1 + len(tag)
            continue
        if ch in "'\"":
            quote, start = ch, line
            i += 1
            while i < n:
                if src[i] == "\\":
                    i += 2
                    continue
                if src[i] == "\n":
                    line += 1
                if src[i] == quote:
                    break
                i += 1
            if i >= n:
                return f"line {start}: unterminated {quote} string"
            i += 1
            continue
        if ch in OPEN:
            stack.append((ch, line))
            i += 1
            continue
        if ch in CLOSE:
            if not stack:
                return f"line {line}: unexpected closing {ch!r}"
            opener, oline = stack.pop()
            if OPEN[opener] != ch:
                return (f"line {line}: {ch!r} closes {opener!r} "
                        f"opened at line {oline}")
            i += 1
            continue
        i += 1
    if stack:
        opener, oline = stack[-1]
        return f"line {oline}: unclosed {opener!r}"
    return None


def php_sources():
    seen = []
    for name in SCAN_FILES:
        path = ROOT / name
        if path.is_file():
            seen.append(path)
    for directory in SCAN_DIRS:
        base = ROOT / directory
        if not base.is_dir():
            continue
        for path in sorted(base.rglob("*.php")):
            if any(part in str(path) for part in SKIP_PARTS):
                continue
            seen.append(path)
    return seen


class PhpSourcesAreWellFormed(unittest.TestCase):
    def test_the_checker_detects_the_historical_breakage(self):
        """Guard the guard: the exact d865ee9 damage must be caught."""
        broken = (
            "<?php\n"
            "class X {\n"
            "    public static function seed($conn): void\n"
            "    {\n"
            "        $conn->query(\"\n"
            "            INSERT INTO t VALUES\n"
            "            ('a', 1);\n"
            "        \");\n"
            "    }\n"
            "            ('b', 2);\n"
            "        \");\n"
            "    }\n"
            "}\n"
        )
        self.assertIsNotNone(structural_error(broken),
                             "checker failed to notice duplicated tail lines")
        clean = (
            "<?php\n"
            "class X {\n"
            "    public static function seed($conn): void\n"
            "    {\n"
            "        $conn->query(\"\n"
            "            INSERT INTO t VALUES\n"
            "            ('a', 1);\n"
            "        \");\n"
            "    }\n"
            "}\n"
        )
        self.assertIsNone(structural_error(clean))

    def test_checker_tolerates_normal_php_constructs(self):
        ok = (
            "<?php\n"
            "// a comment with 'quotes' and (brackets\n"
            "# hash comment \"too\n"
            "/* block ' \" ( */\n"
            "$a = \"interpolated {$arr['key']} value\";\n"
            "$b = 'escaped \\' quote';\n"
            "$c = <<<SQL\n"
            "  SELECT * FROM t WHERE x = 'y' AND (z)\n"
            "SQL;\n"
            "$d = [1, 2, 3];\n"
        )
        self.assertIsNone(structural_error(ok))

    def test_assessment_type_service_is_parseable(self):
        """The specific file that was broken on main."""
        path = ROOT / "admin/backend/services/AssessmentTypeService.php"
        src = path.read_text(encoding="utf-8")
        self.assertIsNone(structural_error(src))
        # the duplicated tail must not come back
        self.assertEqual(src.count("'memorization'"), 1)
        self.assertEqual(src.count("'participation'"), 1)
        # and the seed statement it belongs to is still complete
        self.assertIn("'Hymn / Memorization', 15.00, 15.00, 9);", src)

    def test_every_first_party_php_file_is_well_formed(self):
        sources = php_sources()
        self.assertGreater(len(sources), 250, "source scan found too few files")
        problems = []
        for path in sources:
            try:
                src = path.read_text(encoding="utf-8")
            except (UnicodeDecodeError, OSError) as exc:  # pragma: no cover
                problems.append(f"{path.relative_to(ROOT)}: unreadable ({exc})")
                continue
            err = structural_error(src)
            if err:
                problems.append(f"{path.relative_to(ROOT)}: {err}")
        self.assertEqual(problems, [], "malformed PHP:\n  " + "\n  ".join(problems))

    def test_no_php_file_opens_without_a_php_tag(self):
        for path in php_sources():
            with self.subTest(path=str(path.relative_to(ROOT))):
                head = path.read_text(encoding="utf-8")[:4096]
                self.assertIn("<?php", head)


if __name__ == "__main__":
    unittest.main()


# ---------------------------------------------------------------------------
# Orphaned class-body statements (strengthening added 2026-10-02, finding I)
#
# The AssessmentTypeService breakage was not an unbalanced brace: the file's
# braces matched. Seven lines of a seed INSERT's VALUES tuples were left
# stranded *inside the class body* after the method that owned them had
# already closed. PHP allows only declarations in a class body, so this is an
# unconditional fatal parse error, and the generic structural checker cannot
# see it. This detector models that rule directly.
# ---------------------------------------------------------------------------

_ALLOWED_CLASS_MEMBERS = {
    "public", "private", "protected", "static", "final", "abstract", "function",
    "const", "var", "use", "readonly", "case", "enum", "class", "interface", "trait",
    "#[",
}

_SCRUB = re.compile(
    r"""(?P<block>/\*.*?\*/)
      | (?P<line>//[^\n]*)
      | (?P<hash>\#(?!\[)[^\n]*)
      | (?P<heredoc><<<[ \t]*(?P<q>['"]?)(?P<tag>\w+)(?P=q)\r?\n.*?\n[ \t]*(?P=tag)\b)
      | (?P<sq>'(?:\\.|[^'\\])*')
      | (?P<dq>"(?:\\.|[^"\\])*")
      | (?P<close>\?>.*?(?:<\?php|<\?=|\Z))
    """,
    re.S | re.X,
)


def _scrub(src: str) -> str:
    """Blank comments, strings and inline-HTML, preserving every offset."""
    def blank(m):
        return re.sub(r"[^\n]", " ", m.group(0))
    head = src.find("<?php")
    if head == -1:
        return " " * len(src)
    prefix = re.sub(r"[^\n]", " ", src[:head])
    return prefix + _SCRUB.sub(blank, src[head:])


def orphaned_class_body_statements(src: str):
    """Statements sitting directly in a class body, which PHP forbids."""
    scrubbed = _scrub(src)
    found = []
    for match in re.finditer(r"\b(?:class|interface|trait|enum)\s+(\w+)[^{;]*\{", scrubbed):
        cls = match.group(1)
        start = match.end()
        depth, i = 1, start
        while i < len(scrubbed) and depth:
            if scrubbed[i] == "{":
                depth += 1
            elif scrubbed[i] == "}":
                depth -= 1
            i += 1
        body_end = i - 1

        i, depth, chunk_start = start, 0, start
        while i < body_end:
            ch = scrubbed[i]
            if ch == "{":
                depth += 1
            elif ch == "}":
                depth -= 1
                if depth == 0:
                    chunk_start = i + 1
            elif ch == ";" and depth == 0:
                chunk = scrubbed[chunk_start:i].strip()
                if chunk:
                    tok = re.match(r"(#\[|\w+)", chunk)
                    if not tok or tok.group(1) not in _ALLOWED_CLASS_MEMBERS:
                        found.append((cls, scrubbed.count("\n", 0, chunk_start) + 1,
                                      chunk[:60].replace("\n", " ")))
                chunk_start = i + 1
            i += 1
    return found


class ClassBodiesContainOnlyDeclarations(unittest.TestCase):
    """Guards the exact defect class behind finding I."""

    BROKEN_FIXTURE = """<?php
namespace App\\Services;
final class AssessmentTypeService
{
    public static function ensureTable(\\mysqli $conn): void
    {
        $conn->query("INSERT INTO assessment_types VALUES (1)");
    }
        ('participation', 'Participation', 10.00, 10.00, 8),
        ('memorization', 'Hymn / Memorization', 15.00, 15.00, 9);

    public static function getAll(\\mysqli $conn): array
    {
        return [];
    }
}
"""

    def test_detector_catches_the_historical_orphaned_tail(self):
        """Reconstruction of the shape that shipped broken for ~5 days."""
        found = orphaned_class_body_statements(self.BROKEN_FIXTURE)
        self.assertTrue(found, "orphaned VALUES tuples must be detected")
        self.assertEqual(found[0][0], "AssessmentTypeService")

    def test_detector_tolerates_ordinary_class_members(self):
        ok = """<?php
#[SomeAttribute]
final class Thing extends Base implements Contract
{
    use SomeTrait;
    public const A = 1;
    private const MAP = ['x' => '};', 'y' => ";{"];
    protected static ?string $name = null;
    public readonly int $n;
    private array $rows = [1, 2, 3];

    public function __construct(private int $dep = 0) {}

    public function go(): string
    {
        $sql = "SELECT ';' FROM t WHERE a = '{'";
        if ($this->n > 0) { return $sql; }
        return <<<SQL
            SELECT 1; FROM nowhere {
SQL;
    }
}

interface Contract { public function go(): string; }
"""
        self.assertEqual(orphaned_class_body_statements(ok), [])

    def test_no_first_party_class_body_contains_stray_statements(self):
        problems = []
        for path in php_sources():
            src = path.read_text(encoding="utf-8", errors="replace")
            for cls, line, chunk in orphaned_class_body_statements(src):
                problems.append(f"{path.relative_to(ROOT)}:{line} class {cls}: {chunk}")
        self.assertEqual(problems, [],
                         "orphaned class-body statements (PHP fatal):\n  " + "\n  ".join(problems))
