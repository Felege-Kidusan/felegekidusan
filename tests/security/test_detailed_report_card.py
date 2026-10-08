"""
Detailed semester report card — term-close model, release 3 (1.6.7+33, 2026-10-08)
══════════════════════════════════════════════════════════════════════════════════
MODEL (user Decision A, 2026-10-08): the annual report card is DETAILED and
semester-based —
  • per subject, every assessment listed ONE BY ONE under its semester,
    then that semester's total from 100% on its own row;
  • a summary table: subject | 1st semester | 2nd semester | annual;
  • annual = the plain AVERAGE of the two semester totals (the per-year
    s1/s2 weights from 056 are superseded and no longer applied);
  • semester-only subjects show a "—" in the semester they don't run, so a
    dash means "semester-based subject, not offered here" — never a zero.

SURFACES PINNED HERE
  SubjectDurationPolicy      FULL_YEAR annual = plain average
  ReportCardService          per-assessment term tagging + semester_detail
  report_card.js             detailed layout + summary table + dash rows
  report_card.css            detail styles
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class AnnualAveragePinned(unittest.TestCase):
    def setUp(self):
        self.php = read("admin/backend/services/SubjectDurationPolicy.php")

    def test_full_year_annual_is_the_plain_average(self):
        self.assertIn("$annual = ($s1 + $s2) / 2.0;", self.php)
        self.assertIn(
            "average of the two semester totals",
            self.php,
        )

    def test_weights_are_no_longer_applied_to_the_annual_score(self):
        # The old formula (s1*w1 + s2*w2) must be gone from the policy.
        self.assertNotIn("$weights['s1'] / self::WEIGHT_TOTAL", self.php)

    def test_one_semester_alone_is_never_the_annual_result(self):
        self.assertIn("full-year subject needs both semesters", self.php)


class ServiceDetailPinned(unittest.TestCase):
    def setUp(self):
        self.svc = read("admin/backend/services/ReportCardService.php")

    def test_each_assessment_row_carries_its_term(self):
        self.assertIn("'term_id' => !empty($rec['term_id']) ? (int)$rec['term_id'] : null,", self.svc)

    def test_annual_views_split_marks_by_semester_with_totals(self):
        self.assertIn("$semesterDetail = [];", self.svc)
        self.assertIn("['assessments' => $aggT['assessments'], 'total' => $aggT['average']]", self.svc)

    def test_subject_rows_expose_the_detail_and_the_method(self):
        self.assertIn("'semester_detail' => $semesterDetail,", self.svc)
        self.assertIn("'annual_method' => $isAnnual ? 'average_of_semesters' : null,", self.svc)

    def test_unresolvable_marks_stay_surfaced_not_guessed(self):
        self.assertIn("$untagged++;", self.svc)


class RendererPinned(unittest.TestCase):
    def setUp(self):
        self.js = read("admin/js/report_card.js")

    def test_annual_view_branches_on_the_term(self):
        self.assertIn("const isAnnualView = !(data.term && data.term.id);", self.js)

    def test_detailed_blocks_list_assessments_one_by_one(self):
        self.assertIn("function assessmentLine(a)", self.js)
        self.assertIn("rc-detail-row", self.js)
        self.assertIn("'1st Semester · 1ኛ ሴሚስተር'", self.js)
        self.assertIn("'2nd Semester · 2ኛ ሴሚስተር'", self.js)

    def test_each_semester_total_is_shown_from_100(self):
        self.assertIn("rc-sem-total", self.js)
        self.assertIn("semTotal(total) + ' / 100%", self.js)

    def test_semester_only_subjects_show_a_dash_not_a_zero(self):
        self.assertIn("Not offered this semester", self.js)
        self.assertIn("rc-dash", self.js)
        self.assertIn("SEMESTER_ONLY", self.js)

    def test_summary_table_columns(self):
        self.assertIn("<th>Subject</th><th class=\"num\">1st Semester</th>", self.js)
        self.assertIn("<th class=\"num\">2nd Semester</th>", self.js)
        self.assertIn("<th class=\"num\">Annual (average)</th>", self.js)

    def test_term_views_keep_the_existing_table(self):
        self.assertIn("isAnnualView\n          ? '<div class=\"rc-detail-wrap\">'", self.js)


class StylesPinned(unittest.TestCase):
    def test_detail_styles_exist(self):
        css = read("admin/css/report_card.css")
        for cls in (".rc-detail-wrap", ".rc-subj", ".rc-sem-hd", ".rc-sem-total", ".rc-detail-row.rc-dash"):
            self.assertIn(cls, css)


if __name__ == "__main__":
    unittest.main()
