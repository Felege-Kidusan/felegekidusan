"""Every production entry point must load the classes it statically calls.

This project has NO autoloader: not one spl_autoload_register() outside
vendor/. Every class reference therefore needs an explicit require_once
somewhere in the entry point's include closure, or the call is a runtime
fatal that `php -l` cannot detect -- lint resolves syntax, not class names.

Findings pinned here
--------------------
P  admin/backend/services/MemberReportRenderer.php
   tableRow() calls MemberCategory::letterFor()/::sectionAm(). The renderer
   declared no dependencies and admin/export_pdf.php never loaded the class,
   so Word and PDF/print member exports streamed a truncated document with
   zero members -- HTTP 200, no visible error, because config.php sets
   display_errors=0. CSV was unaffected (it does not reach tableRow()).

Q  admin/backend/services/HrSubmissionService.php
   reviewPacket() calls SecurityAuditService::record() AFTER the status
   UPDATE has committed. api/v1/routes/hr.php loads neither the class nor
   anything that loads it, so POST /hr/submission-review approved the packet,
   then threw -- losing the audit record permanently while telling the
   reviewer it had failed.

The closure walker follows EVERY resolvable include, not just those naming a
service: a service-only walk produced false positives in an earlier cycle by
missing config.php (loads FeatureGate) and api/v1/core/database.php (loads
three services). Optional dependencies guarded by class_exists() are a
deliberate pattern here and are not reported.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICES = ROOT / "admin" / "backend" / "services"

ENTRY_POINTS = [
    "admin/export_pdf.php",
    "admin/api_mezmur.php",
    "admin/api_communication.php",
    "admin/api_education.php",
    "admin/api_attendance.php",
    "admin/api_hr_attendance.php",
    "api/v1/routes/hr.php",
    "api/v1/routes/mezmur.php",
    "api/v1/routes/grades.php",
    "api/v1/routes/attendance.php",
    "api/v1/routes/members.php",
]

INCLUDE_RE = re.compile(r"\b(?:require|include)(?:_once)?\s*(?:\(|\s)([^;]+);")
STRING_RE = re.compile(r"'([^']*)'|\"([^\"]*)\"")
STATIC_CALL_RE = re.compile(r"\b([A-Z]\w+)::")
DECLARES_RE = r"\b(?:class|interface|trait|enum)\s+%s\b"


def strip_php_comments(src: str) -> str:
    # Line comments MUST go first. A "//" comment containing a "/*" sequence
    # (e.g. one mentioning "api/v1/core/*") would otherwise open a phantom
    # block comment and swallow the real code after it -- exactly how an
    # earlier version of this test lost a require_once and reported a false
    # positive.
    src = re.sub(r"(?m)//.*$", "", src)
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    src = re.sub(r"(?m)^\s*\*.*$", "", src)
    return src


def service_index() -> dict:
    return {p.stem: p for p in SERVICES.glob("*.php")}


def resolve_includes(path: Path) -> set:
    if not path.exists():
        return set()
    src = strip_php_comments(path.read_text(encoding="utf-8", errors="replace"))
    out = set()
    for expr in INCLUDE_RE.findall(src):
        rel = "".join(a or b for a, b in STRING_RE.findall(expr))
        if not rel.endswith(".php"):
            continue
        rel = rel.lstrip("/")
        bases = [path.parent, ROOT, ROOT / "admin"]
        m = re.search(r"dirname\(\s*__DIR__\s*,\s*(\d+)\s*\)", expr)
        if m:
            b = path.parent
            for _ in range(int(m.group(1))):
                b = b.parent
            bases.insert(0, b)
        elif "dirname(__DIR__)" in expr:
            bases.insert(0, path.parent.parent)
        for base in bases:
            try:
                cand = (base / rel).resolve()
            except (OSError, ValueError):
                continue
            if cand.is_file():
                out.add(cand)
                break
    return out


def include_closure(path: Path) -> set:
    seen, stack = set(), [path.resolve()]
    while stack:
        cur = stack.pop()
        if cur in seen:
            continue
        seen.add(cur)
        stack.extend(resolve_includes(cur))
    return seen


def optional_guard(body: str, cls: str) -> bool:
    return re.search(
        r"class_exists\(\s*['\"]\\*(?:App\\+Services\\+)?%s['\"]" % cls, body
    ) is not None


def static_calls(path: Path, services: dict) -> set:
    body = strip_php_comments(path.read_text(encoding="utf-8", errors="replace"))
    out = set()
    for cls in set(STATIC_CALL_RE.findall(body)):
        if cls not in services:
            continue
        if re.search(DECLARES_RE % cls, body):
            continue
        if optional_guard(body, cls):
            continue
        out.add(cls)
    return out


class NoAutoloaderExists(unittest.TestCase):
    def test_no_spl_autoload_register_outside_vendor(self):
        hits = []
        for php in ROOT.rglob("*.php"):
            s = str(php)
            if "/vendor/" in s or "/node_modules/" in s:
                continue
            try:
                if "spl_autoload_register" in php.read_text(encoding="utf-8", errors="replace"):
                    hits.append(php.relative_to(ROOT).as_posix())
            except OSError:
                continue
        self.assertEqual(
            [], hits,
            "An autoloader appeared; class references no longer need explicit "
            "require_once. Revisit this file.\n" + "\n".join(hits),
        )


class EntryPointsLoadWhatTheyCall(unittest.TestCase):
    def setUp(self):
        self.services = service_index()
        self.assertGreater(len(self.services), 40, "service directory not found")

    def test_every_entry_point_closure_covers_its_calls(self):
        problems = []
        for rel in ENTRY_POINTS:
            entry = ROOT / rel
            if not entry.exists():
                continue
            closure = include_closure(entry)
            loaded = {p.stem for p in closure}
            for f in sorted(closure):
                if "/vendor/" in str(f):
                    continue
                for cls in sorted(static_calls(f, self.services)):
                    if cls not in loaded:
                        problems.append(
                            f"{rel}: {f.name} calls {cls}:: but {cls}.php is not "
                            f"in that entry point's include closure"
                        )
        self.assertEqual(
            [], problems,
            "Unloaded class references are runtime fatals (no autoloader "
            "exists); php -l cannot detect these.\n  " + "\n  ".join(problems),
        )

    def test_finding_P_renderer_declares_member_category(self):
        renderer = SERVICES / "MemberReportRenderer.php"
        body = renderer.read_text(encoding="utf-8", errors="replace")
        self.assertIn("MemberCategory::", body, "test pinned to the wrong file")
        self.assertIn(
            "MemberCategory.php", body,
            "MemberReportRenderer calls MemberCategory:: but no longer requires "
            "it. Word and PDF member exports will stream a truncated, "
            "member-less document with HTTP 200 (finding P).",
        )
        self.assertIn(
            "MemberCategory",
            {p.stem for p in include_closure(ROOT / "admin/export_pdf.php")},
            "the export entry point's closure no longer reaches MemberCategory",
        )

    def test_finding_Q_hr_service_declares_audit_service(self):
        svc = SERVICES / "HrSubmissionService.php"
        body = svc.read_text(encoding="utf-8", errors="replace")
        self.assertIn("SecurityAuditService::", body, "test pinned to the wrong file")
        self.assertIn(
            "SecurityAuditService.php", body,
            "HrSubmissionService::reviewPacket() calls SecurityAuditService::"
            "record() after the status UPDATE has already committed. Without "
            "this require the mobile route approves the packet and then throws, "
            "losing the audit record permanently (finding Q).",
        )
        self.assertIn(
            "SecurityAuditService",
            {p.stem for p in include_closure(ROOT / "api/v1/routes/hr.php")},
            "POST /hr/submission-review no longer reaches SecurityAuditService",
        )

    def test_audit_writers_load_the_audit_service(self):
        """Generalises Q: a service that records an audit trail must be able
        to, regardless of which entry point reached it."""
        problems = []
        for svc in sorted(SERVICES.glob("*.php")):
            body = strip_php_comments(svc.read_text(encoding="utf-8", errors="replace"))
            if "SecurityAuditService::" not in body or svc.stem == "SecurityAuditService":
                continue
            if "SecurityAuditService" not in {p.stem for p in include_closure(svc)}:
                problems.append(svc.name)
        self.assertEqual(
            [], problems,
            "These services write an audit trail but cannot guarantee the audit "
            "class is loaded; the write that precedes it may already have "
            "committed: " + ", ".join(problems),
        )


if __name__ == "__main__":
    unittest.main()
