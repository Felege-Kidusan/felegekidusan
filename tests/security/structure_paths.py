"""Shared helpers for the folder-structure safety tests.

Not a test module (no ``test_`` prefix), so pytest does not collect it.

The helpers read first-party PHP files and resolve file paths that are
written in a *static* form, the forms this codebase actually uses:

    __DIR__ . '/x.php'
    dirname(__DIR__) . '/x.php'       dirname(__DIR__, 2) . '/x.php'
    dirname(__FILE__) . '/x.php'
    ROOT_PATH . '/x.php'              ADMIN_PATH . '/x.php'
    'x.php'                           (plain literal, relative)

ROOT_PATH and ADMIN_PATH are defined in config.php as __DIR__ and
__DIR__ . '/admin'. Anything else (a variable, a function call, string
interpolation) is reported as "dynamic". The tests track dynamic paths
in a reviewed allowlist, so a reviewer must look at each new one.
"""
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# Third-party code, tests, the Flutter app and docs are not first-party PHP
# that the web server runs as our application.
SKIP_PREFIXES = (
    "vendor/",
    "tests/",
    "Mobile/",
    "docs/",
    "admin/id_cards/libs/",
    "admin/backend/pdf/tcpdf/",
    # Archived legacy PHP migrations (RFC 0001 step 2b). Moved as-is; their
    # config links point at the old folders on purpose, so they stop before
    # touching any database. They are records, not code the site runs.
    "sql/legacy/",
)

# Root-level folders that the website must never need at run time.
NON_WEB_ROOT_DIRS = ("tests", "sql", "docs", "Mobile", "scripts", "tools", ".github")

CONSTANTS = {"ROOT_PATH": "", "ADMIN_PATH": "admin"}

_LIT = r"""(?P<q>['"])(?P<p>[^'"$\\]+)(?P=q)"""
_FORMS = [
    # __DIR__ . '/x'
    (re.compile(r"^__DIR__\s*\.\s*" + _LIT + r"$"),
     lambda here, m: here / m.group("p").lstrip("/")),
    # dirname(__DIR__[, n]) . '/x'
    (re.compile(r"^dirname\(\s*__DIR__\s*(?:,\s*(\d+)\s*)?\)\s*\.\s*" + _LIT + r"$"),
     lambda here, m: here.parents[int(m.group(1) or 1) - 1] / m.group("p").lstrip("/")),
    # dirname(__FILE__) . '/x'
    (re.compile(r"^dirname\(\s*__FILE__\s*\)\s*\.\s*" + _LIT + r"$"),
     lambda here, m: here / m.group("p").lstrip("/")),
    # ROOT_PATH . '/x'   ADMIN_PATH . '/x'
    (re.compile(r"^(ROOT_PATH|ADMIN_PATH)\s*\.\s*" + _LIT + r"$"),
     lambda here, m: ROOT / CONSTANTS[m.group(1)] / m.group("p").lstrip("/")),
]
_PLAIN = re.compile(r"^" + _LIT + r"$")

# A require/include *statement*, ending in ';' or '?>'. The keyword must
# start a statement or expression (including right after '<?php'), so prose
# such as "requires sql/020 first" is not matched, and the expression must
# start like a path expression.
INCLUDE_RX = re.compile(
    r"(?:^|[;{}()=?:!&|,]|<\?php\b|\breturn\b|\belse\b)\s*"
    r"(?:@\s*)?\b(require|include)(?:_once)?\b\s*\(?\s*"
    r"((?:__DIR__|dirname\s*\(|ROOT_PATH|ADMIN_PATH|['\"]|\$)[^;]*?)\s*\)?\s*(?:;|\?>)",
    re.M,
)


def first_party_php():
    """Tracked first-party PHP files, as repository-relative strings."""
    out = subprocess.run(["git", "ls-files", "*.php"], cwd=ROOT,
                         capture_output=True, text=True, check=True).stdout.split()
    return sorted(f for f in out if not f.startswith(SKIP_PREFIXES))


def code_only(text):
    """Drop PHP comments so documentation inside comments is ignored."""
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    return re.sub(r"(?m)^\s*(//|#).*$", "", text)


def read_code(rel):
    return code_only((ROOT / rel).read_text(encoding="utf-8", errors="ignore"))


def resolve(rel_file, expr):
    """Return (kind, Path|None) for a path expression found in rel_file.

    kind is "static" (resolved to an absolute path), "plain" (a bare
    relative literal) or "dynamic" (cannot be resolved statically).
    """
    expr = " ".join(expr.split())
    here = (ROOT / rel_file).parent
    for rx, build in _FORMS:
        m = rx.match(expr)
        if m:
            return "static", Path(_norm(build(here, m)))
    m = _PLAIN.match(expr)
    if m:
        return "plain", Path(_norm(here / m.group("p")))
    return "dynamic", None


def _norm(path):
    import os
    return os.path.normpath(str(path))


def includes(rel_file):
    """Yield (expression, kind, resolved_path) for each include statement."""
    code = read_code(rel_file)
    for m in INCLUDE_RX.finditer(code):
        expr = m.group(2).strip()
        kind, target = resolve(rel_file, expr)
        yield expr, kind, target


def repo_relative(path):
    try:
        return Path(path).resolve().relative_to(ROOT).as_posix()
    except ValueError:
        return None
