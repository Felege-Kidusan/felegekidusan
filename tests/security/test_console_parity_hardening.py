"""Console parity & CSRF single-source hardening contracts.

Born from two production bugs (2026-08):

1. The HR console's taker modal + review console embedded
   ``'<?= $csrfToken ?? '' ?>'`` although ``$csrfToken`` was never
   assigned in that file, so every mutation shipped an EMPTY CSRF
   token and failed with "Security token expired".  Big-company
   practice (OWASP CSRF Prevention Cheat Sheet, synchronizer token
   pattern): the token has exactly ONE trusted source per page and
   every emitter must use it; token lifetime tracks the session.

2. The HR console carried the legacy Education "Attendance & Status"
   section which HR can never use (class-based edu attendance), so
   HR users only ever saw "You do not have permission".  UX research
   is unanimous: a menu/surface that can never be enabled for a role
   must be hidden, while the server keeps enforcing (defence in
   depth).  The same rule produced the mezmur Submissions relayout:
   one review surface that mirrors the Education department exactly
   (header + filters, Drafts/Submitted/Insights tabs, stats strip,
   packet table, insights pane) with no duplicate "confuser" cards.

These contracts pin all of that down so regressions fail CI instead
of shipping to production.
"""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

DASHBOARDS = sorted((ROOT / "admin" / "dashboards").glob("*.php"))
FRONTEND_PAGES = sorted((ROOT / "frontend" / "pages").glob("*.php"))


class CsrfSingleSourceTests(unittest.TestCase):
    """Every page that echoes ``<?= $csrfToken`` must also assign it;
    pages without an assignment must not echo it (empty-token bug)."""

    def test_no_undefined_csrf_variable_echoes(self):
        offenders = []
        for path in DASHBOARDS + FRONTEND_PAGES:
            src = path.read_text(encoding="utf-8", errors="replace")
            if "<?= $csrfToken" in src and not re.search(
                r"\$csrfToken\s*=", src
            ):
                offenders.append(path.name)
        self.assertEqual(
            [], offenders,
            "pages echoing an undefined $csrfToken (empty CSRF token "
            "at runtime): %s" % offenders,
        )

    def test_hr_console_uses_page_csrf_constant(self):
        hr = (ROOT / "admin" / "dashboards" / "hr-dept.php").read_text(
            encoding="utf-8", errors="replace")
        # The page defines exactly one trusted token source.
        self.assertIn("const CSRF_TOKEN = '<?= generateCsrfToken() ?>';", hr)
        self.assertNotIn("<?= $csrfToken", hr)
        # Taker modal + HrSub review console both consume the constant.
        self.assertIn("formData.append('csrf_token', CSRF_TOKEN);", hr)
        self.assertIn("const csrfToken = CSRF_TOKEN;", hr)


class HrConsoleSurfaceTests(unittest.TestCase):
    """HR is section-based attendance only; the legacy class-based
    Education attendance surface must not exist there (it can never
    be permitted for hr_dept)."""

    @classmethod
    def setUpClass(cls):
        cls.hr = (ROOT / "admin" / "dashboards" / "hr-dept.php").read_text(
            encoding="utf-8", errors="replace")

    def test_legacy_edu_attendance_removed(self):
        for marker in (
            'id="section-attendance"',
            'data-section="attendance"',
            "showAttTab",
            "loadDailyReport",
            "loadAttOverview",
            "api_attendance_info",
            "Attendance & Status",
        ):
            self.assertNotIn(marker, self.hr, marker)

    def test_hr_keeps_its_own_submission_console(self):
        self.assertIn('id="section-submissions"', self.hr)
        self.assertIn("HrSub", self.hr)
        self.assertIn("api_hr_attendance.php", self.hr)


class MezmurSubmissionsParityTests(unittest.TestCase):
    """Mezmur's Submissions section mirrors the Education department
    layout: one header with filters + Excel/Refresh, the same three
    tabs, stats strip, packet table and insights pane — and none of
    the retired duplicate cards."""

    @classmethod
    def setUpClass(cls):
        cls.mz = (ROOT / "frontend" / "pages" / "mezmur_dept.php").read_text(
            encoding="utf-8", errors="replace")
        cls.edu = (ROOT / "admin" / "dashboards" / "edu_dept.php").read_text(
            encoding="utf-8", errors="replace")

    def test_same_tab_workflow_as_edu(self):
        # Education reference workflow: Drafts | Submitted | Insights.
        for tab in ("Drafts", "Submitted", "Insights"):
            self.assertIn(tab, self.edu)
            self.assertIn(tab, self.mz)
        for el in (
            "mzSubTabDraft", "mzSubTabSubmitted", "mzSubTabInsights",
            "mzSubTabStatus", "mzSubStatsRow",
            "mzSubmissionsList", "mzSubTbody", "mzSubInsights",
        ):
            self.assertIn(el, self.mz, el)

    def test_filters_live_in_the_header_like_edu(self):
        head = self.mz[self.mz.find('<div id="mzSessionListView">'):
                       self.mz.find('class="sub-tabs"')]
        for el in ("mzSubSection", "mzSubFrom", "mzSubTo",
                   "exportSubmissions", "loadSubmissions"):
            self.assertIn(el, head, el)

    def test_retired_confuser_cards_are_gone(self):
        for marker in (
            "Review Inbox",
            "clock-rotate-left",   # retired day-history card icon/title
            "mzSessFrom",
            "mzSessTbody",
            "mzSessPagination",
            "mz-purpose-note",
        ):
            self.assertNotIn(marker, self.mz, marker)


if __name__ == "__main__":
    unittest.main()
