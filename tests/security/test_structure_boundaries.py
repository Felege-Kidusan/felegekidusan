"""Folder-structure safety net: website code and private folders stay apart.

These guards protect the folder clean-up (RFC 0001, Option 1):

1. Website code must not load or read files from the root folders that are
   not part of the website (tests, sql, docs, Mobile, scripts, tools,
   .github). Then those folders can be tidied without touching any page.
2. The root .htaccess must keep blocking those folders (plus vendor) and
   the repository file types, so nothing private becomes downloadable.
3. Command-line and cron scripts that live inside website folders must
   refuse web requests: a PHP_SAPI/php_sapi_name() guard, or a deny rule
   in a .htaccess on their path.
4. Folders whose own .htaccess switches on rewrite rules do not inherit
   the root rules (Apache semantics without RewriteOptions Inherit). No
   new repository-internal file types (.md, .sql, .sh, ...) may be added
   there, because the root block may not protect them.
"""
import re
import subprocess
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from structure_paths import (  # noqa: E402
    NON_WEB_ROOT_DIRS, ROOT, first_party_php, includes, read_code, repo_relative, resolve,
)

ROOT_BLOCKED_DIRS = set(NON_WEB_ROOT_DIRS) | {"vendor"}
INTERNAL_EXT = re.compile(r"\.(sql|md|sh|py|lock|ya?ml|dist)$", re.I)

# File-reading calls whose first argument is a static path expression.
READ_CALL_RX = re.compile(
    r"\b(?:file_get_contents|fopen|readfile|file|glob|parse_ini_file|is_file|"
    r"file_exists|is_dir|scandir|opendir|filemtime|filesize)\s*\(\s*"
    r"((?:__DIR__|dirname\s*\(\s*__(?:DIR|FILE)__\s*(?:,\s*\d+\s*)?\)|ROOT_PATH|ADMIN_PATH)"
    r"\s*\.\s*(['\"])[^'\"$]+\2)")

CLI_MARKER_RX = re.compile(r"\$argv\b|\bSTDIN\b|fwrite\(\s*STD(?:OUT|ERR)\b")
SAPI_GUARD_RX = re.compile(r"\bPHP_SAPI\b|\bphp_sapi_name\s*\(")

# Existing third-party/legacy files in folders with their own rewrite rules.
# Reviewed 2026-10-23: all three answered 403 on dev and production, but
# that comes from the host's security filter, not from our rules, so it is
# not relied on. Do not add to this list; put such files outside the web tree.
KNOWN_INTERNAL_FILES_IN_OWN_REWRITE_DIRS = {
    "admin/backend/pdf/tcpdf/README.md",
    "admin/id_cards/libs/phpqrcode/tools/merge.sh",
    "api/v1/tests/test_version.py",
}


def tracked(*patterns):
    return subprocess.run(["git", "ls-files", *patterns], cwd=ROOT,
                          capture_output=True, text=True, check=True).stdout.split()


def website_php():
    """First-party PHP files that live in the website tree."""
    return [f for f in first_party_php() if f.split("/", 1)[0] not in NON_WEB_ROOT_DIRS]


def lands_in_non_web_root_dir(target):
    rel = repo_relative(target)
    return rel is not None and rel.split("/", 1)[0] in NON_WEB_ROOT_DIRS


class StructureBoundaries(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.htaccess = (ROOT / ".htaccess").read_text(encoding="utf-8")
        cls.web_php = website_php()

    # 1 ------------------------------------------------------------------
    def test_website_code_does_not_load_files_from_non_web_folders(self):
        hits = []
        for f in self.web_php:
            for expr, kind, target in includes(f):
                if kind != "dynamic" and lands_in_non_web_root_dir(target):
                    hits.append(f"{f}: include {expr}")
            for m in READ_CALL_RX.finditer(read_code(f)):
                kind, target = resolve(f, m.group(1))
                if kind != "dynamic" and lands_in_non_web_root_dir(target):
                    hits.append(f"{f}: reads {m.group(1)}")
        self.assertGreater(len(self.web_php), 250)
        self.assertEqual(
            hits, [],
            "Website code must not depend on non-website folders "
            f"({', '.join(NON_WEB_ROOT_DIRS)}):\n  " + "\n  ".join(hits))

    # 2 ------------------------------------------------------------------
    def test_root_htaccess_still_blocks_private_folders(self):
        m = re.search(r"RewriteRule\s+\^\(([^)]*)\)\(/\|\$\)\s+-\s+\[F", self.htaccess)
        self.assertIsNotNone(m, "Root .htaccess lost the private-folder block rule")
        blocked = {d.replace("\\.", ".") for d in m.group(1).split("|")}
        missing = sorted(ROOT_BLOCKED_DIRS - blocked)
        self.assertEqual(missing, [], f"Root .htaccess no longer blocks: {missing}")

    def test_root_htaccess_still_blocks_repository_file_types(self):
        self.assertIn(r"RewriteRule \.(sql|md|sh|py|lock|ya?ml|dist)$ - [F,L]", self.htaccess)

    # 3 ------------------------------------------------------------------
    def test_cli_scripts_in_website_folders_refuse_web_requests(self):
        unprotected = []
        cli_files = [f for f in self.web_php if CLI_MARKER_RX.search(read_code(f))]
        for f in cli_files:
            if SAPI_GUARD_RX.search(read_code(f)):
                continue
            if not self._denied_by_htaccess(f):
                unprotected.append(f)
        self.assertGreater(len(cli_files), 5, "CLI detector matched too few files")
        self.assertEqual(
            unprotected, [],
            "Command-line/cron scripts reachable from the web without a "
            "PHP_SAPI guard or a .htaccess deny:\n  " + "\n  ".join(unprotected))

    @staticmethod
    def _denied_by_htaccess(rel):
        name = Path(rel).name
        folder = (ROOT / rel).parent
        while True:
            ht = folder / ".htaccess"
            if ht.is_file():
                text = ht.read_text(encoding="utf-8", errors="ignore")
                files_block = re.search(
                    r'<Files\s+"?' + re.escape(name) + r'"?\s*>(.*?)</Files>', text, re.S | re.I)
                if files_block and re.search(r"Deny from all|Require all denied", files_block.group(1), re.I):
                    return True
                for line in text.splitlines():
                    if "RewriteRule" in line and "[F" in line and Path(name).stem in line:
                        return True
            if folder == ROOT:
                return False
            folder = folder.parent

    # 4 ------------------------------------------------------------------
    def test_no_new_internal_files_where_root_rules_do_not_reach(self):
        own_rewrite_dirs = []
        for ht in tracked("*.htaccess"):
            if ht == ".htaccess" or ht.startswith("vendor/"):
                continue
            text = (ROOT / ht).read_text(encoding="utf-8", errors="ignore")
            if re.search(r"^\s*RewriteEngine\s+On", text, re.M | re.I) and \
                    not re.search(r"RewriteOptions\s+Inherit", text, re.I):
                own_rewrite_dirs.append(str(Path(ht).parent))
        self.assertIn("admin", own_rewrite_dirs)
        found = {f for d in own_rewrite_dirs for f in tracked(f"{d}/")
                 if INTERNAL_EXT.search(f)}
        new = sorted(found - KNOWN_INTERNAL_FILES_IN_OWN_REWRITE_DIRS)
        self.assertEqual(
            new, [],
            "Repository-internal files added under a folder with its own "
            "rewrite rules, where the root .htaccess block may not apply. "
            "Keep them outside the website tree:\n  " + "\n  ".join(new))


if __name__ == "__main__":
    unittest.main()
