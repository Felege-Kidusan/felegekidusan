"""Regression tests: admin search/filter inputs must never autofill.

Production report: the HR ID-card search bar suggested the logged-in
admin username to anyone standing at the screen (browser credential
autofill on unlabeled text inputs). Fix standard: every search/filter
input carries exactly one autocomplete="off"; the sensitive member
search fields additionally use type="search", to which browsers never
apply credential autofill.
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]

DASHBOARDS = [
    "admin/dashboards/edu_dept.php",
    "admin/dashboards/finance_department.php",
    "admin/dashboards/hr-dept.php",
    "admin/dashboards/info-dept.php",
    "admin/dashboards/material_department.php",
    "admin/dashboards/school_admin.php",
    "admin/dashboards/super-admin.php",
    "admin/dashboards/teacher.php",
    "admin/dashboards/attendance_taker.php",
    "admin/groups.php",
    "admin/reports.php",
]

INPUT_TAG = re.compile(r"<input\b[^>]*>", re.IGNORECASE | re.DOTALL)


class SearchAutofillHardeningTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sources = {
            rel: (ROOT / rel).read_text(encoding="utf-8", errors="replace")
            for rel in DASHBOARDS
        }

    def _search_inputs(self, source):
        for match in INPUT_TAG.finditer(source):
            tag = match.group(0)
            if re.search(r"(search|filter)", tag, re.IGNORECASE) and (
                "text" in tag or "search" in tag or 'type=' not in tag
            ):
                yield tag

    def test_every_search_input_disables_autocomplete_exactly_once(self):
        for rel, source in self.sources.items():
            for tag in self._search_inputs(source):
                occurrences = len(re.findall(r"\bautocomplete=", tag))
                self.assertEqual(
                    1,
                    occurrences,
                    f"{rel}: search input must carry exactly one autocomplete "
                    f"attribute, found {occurrences} in: {tag[:120]}",
                )
                self.assertIn('autocomplete="off"', tag)

    def test_no_leftover_autofill_hacks(self):
        for rel, source in self.sources.items():
            self.assertNotIn('autocomplete="nope"', source, rel)

    def test_id_card_and_attaker_search_use_search_type(self):
        hr = self.sources["admin/dashboards/hr-dept.php"]
        info = self.sources["admin/dashboards/info-dept.php"]
        self.assertIn('type="search" inputmode="search" id="idCardMemberSearch"', hr)
        # HR's taker modal lost its member-link picker when takers moved
        # to the department-owned pipeline (2026-08), so only the ID-card
        # search remains; the username field must not autofill.
        self.assertEqual(1, hr.count('type="search" inputmode="search"'))
        self.assertIn('id="attakerUsername" autocomplete="off"', hr)
        self.assertIn('type="search" inputmode="search"', info)
        self.assertIn('name="fkss_attaker_search"', info)

    def test_add_user_modal_fields_do_not_autofill(self):
        school = self.sources["admin/dashboards/school_admin.php"]
        self.assertIn('id="nuUser" class="inp" style="width:100%" autocomplete="off"', school)
        self.assertIn('id="nuName" class="inp" style="width:100%" autocomplete="off"', school)


if __name__ == "__main__":
    unittest.main()
