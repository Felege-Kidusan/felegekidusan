"""
Detailed semester report card — foldable booklet redesign (1.6.10+36, 2026-10-09)
══════════════════════════════════════════════════════════════════════════════════
MODEL (user Decision A, 2026-10-08, unchanged): the annual report card is
semester-based —
  • a summary table: subject | 1st semester | 2nd semester | annual;
  • annual = the plain AVERAGE of the two semester totals (the per-year
    s1/s2 weights from 056 are superseded and no longer applied);
  • semester-only subjects show a "—" in the semester they don't run, so a
    dash means "semester-based subject, not offered here" — never a zero.

1.6.10 (user-approved foldable design): the card is an A5 BOOKLET — two A4
portrait pages printed duplex (short edge) and folded. Per-assessment
detail is intentionally GONE: totals only, ONE table. Semester-only
subjects still appear in the annual column with their semester result
(their final IS that semester's score, per SubjectDurationPolicy).

SURFACES PINNED HERE
  SubjectDurationPolicy      SEMESTER_ONLY final = its semester; FULL_YEAR needs both
  ReportCardService          per-assessment term tagging + semester_detail
  report_card.js             booklet layout + one totals table + cover panels
  report_card.css            two A4 pages, fold, readable sizes
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

    def test_semester_only_final_is_its_own_semester_score(self):
        # 1.6.10: a semester subject's annual IS its semester result.
        self.assertIn("semester-only subject closed at its own semester score", self.php)


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

    def test_booklet_has_two_a4_pages(self):
        self.assertIn('class="rc-sheet rc-book"', self.js)
        self.assertEqual(self.js.count("rc-book-page"), 2)  # one per <section>
        self.assertIn("Sheet 1 · inside face", self.js)
        self.assertIn("Sheet 2 · outside face", self.js)
        self.assertIn("flip on SHORT edge", self.js)

    def test_per_assessment_ledger_is_gone(self):
        # 1.6.10: totals only — the ledger must not come back.
        self.assertNotIn("rc-ledg-grid", self.js)
        self.assertNotIn("function slot(a)", self.js)
        self.assertNotIn("rc-slot", self.js)

    def test_one_totals_table_columns(self):
        self.assertIn("<th>Subject · <span class=\"am\">ትምህርት</span></th>", self.js)
        self.assertIn('<th class="num">1st sem.</th>', self.js)
        self.assertIn('<th class="num">2nd sem.</th>', self.js)
        self.assertIn('<th class="num">Annual</th>', self.js)
        self.assertIn('<th class="num">Grade</th>', self.js)
        self.assertIn("rc-total-row", self.js)

    def test_semester_only_subjects_appear_in_the_annual_column(self):
        # their semester result IS their annual result (dagger footnote)
        self.assertIn("&thinsp;†", self.js)
        self.assertIn("semester subject runs one semester only", self.js)
        self.assertIn("semester subject", self.js)  # row tag

    def test_continuing_full_year_subjects_are_pended_not_guessed(self):
        self.assertIn("&thinsp;*", self.js)
        self.assertIn("full-year subject still in progress", self.js)

    def test_dashes_never_zeros(self):
        self.assertIn("rc-dash", self.js)

    def test_cover_identity_panels(self):
        self.assertIn("rc-cover-frame", self.js)
        self.assertIn("rc-cover-strip", self.js)
        self.assertIn("rc-school-am", self.js)
        self.assertIn("Student<br>Report Card", self.js)

    def test_back_cover_blocks(self):
        self.assertIn("Grade scale", self.js)
        self.assertIn("How to read", self.js)
        self.assertIn("Office use", self.js)
        self.assertIn("School stamp", self.js)
        self.assertIn("deliver to parent / guardian", self.js)

    def test_signature_lines(self):
        self.assertIn("Class Teacher", self.js)
        self.assertIn("Education Department", self.js)

    def test_term_view_gets_single_result_column(self):
        self.assertIn("'Semester<br>Report Card'", self.js)
        self.assertIn('<th class="num">Result</th>', self.js)

    def test_exports_are_unchanged(self):
        self.assertIn("renderSheet: renderSheet,", self.js)
        self.assertIn("fillModal: fillModal,", self.js)
        self.assertIn("printSheets: printSheets,", self.js)


class StylesPinned(unittest.TestCase):
    def setUp(self):
        self.css = read("admin/css/report_card.css")

    def test_print_is_two_a4_portrait_pages(self):
        self.assertIn("@page{size:A4 portrait; margin:0}", self.css)
        self.assertIn("width:210mm; max-width:none; height:297mm", self.css)
        self.assertIn("page-break-after:always; break-after:page", self.css)

    def test_panels_are_half_page_for_the_fold(self):
        self.assertIn("min-height:148.5mm", self.css)
        self.assertIn(".rc-fold", self.css)

    def test_fold_labels_are_screen_only(self):
        self.assertIn(".rc-fold,\n  body.rc-print-mode #rcPrintRoot .rc-sheet-label{display:none}", self.css)

    def test_type_is_readable(self):
        self.assertIn("font-size:9.5pt", self.css)
        self.assertIn("font-size:16pt", self.css)
        self.assertIn("font-variant-numeric:tabular-nums", self.css)

    def test_reference_palette(self):
        self.assertIn("--maroon:#6f171e", self.css)
        self.assertIn("--gold:#c7a347", self.css)

    def test_responsive_block_is_screen_only(self):
        # Chromium print layout evaluates width queries against the portrait
        # paper width — print must never match the responsive block.
        self.assertIn("@media screen and (max-width:780px)", self.css)
        self.assertNotIn("@media(max-width", self.css.replace("@media screen and (max-width", ""))


if __name__ == "__main__":
    unittest.main()

class DashboardScreenStyles(unittest.TestCase):
    """Report Cards dashboards (Education #sec-reportcards, teacher #sec-reports)
    render .rc-sem / .rc-stat / .rc-subj-card / .rc-items / .rc-print-banner.
    The 1.6.9 sheet redesign dropped those rules and the page fell back to
    unstyled text (fixed 2026-10-21). Pin them, scoped to the dashboards."""

    def setUp(self):
        self.css = read("admin/css/report_card.css")
        self.edu = read("admin/dashboards/edu_dept.php")
        self.teacher = read("admin/dashboards/teacher.php")

    def test_every_dashboard_class_is_styled(self):
        for cls in ("rc-sem", "rc-sem-title", "rc-sem-nums", "rc-stat", "rc-donebar",
                    "rc-done-lbl", "rc-done-miss", "rc-items", "rc-subj-card"):
            self.assertIn("#sec-reportcards .%s" % cls, self.css, cls)
            self.assertIn("#sec-reports .%s" % cls, self.css, cls)

    def test_print_banner_is_paper_only(self):
        self.assertIn(".rc-print-banner{display:none}", self.css)
        self.assertIn("body:not(.rc-print-mode) .rc-print-banner{", self.css)

    def test_print_list_shows_only_the_report_section(self):
        self.assertIn("body:has(#sec-reportcards.act) .sec:not(#sec-reportcards)", self.css)

    def test_dashboard_rules_never_touch_the_booklet(self):
        block = self.css[self.css.index("Report Cards DASHBOARD screen"):]
        for line in block.splitlines():
            s = line.strip()
            if s.startswith(".rc-") and "{" in s:
                self.assertTrue(s.startswith(".rc-print-banner"), s)

    def test_stylesheet_cache_busted_on_both_pages(self):
        self.assertIn("/admin/css/report_card.css?v=20261022s", self.edu)
        self.assertIn("/admin/css/report_card.css?v=20261022s", self.teacher)
        self.assertIn("/admin/js/report_card.js?v=20261022s", self.edu)
        self.assertIn("/admin/js/report_card.js?v=20261022s", self.teacher)

    # Print fix 2026-10-22: printed sheets must not stay on screen, must not
    # leak into the class-list print, and the paper holds only the cards.
    def test_print_root_hidden_except_while_printing_cards(self):
        self.assertIn("#rcPrintRoot{display:none}", self.css)
        self.assertIn("body.rc-print-mode > *:not(#rcPrintRoot){display:none!important}", self.css)
        self.assertIn("body.rc-print-mode #rcPrintRoot{display:block}", self.css)

    def test_print_mode_not_dropped_on_a_fixed_timer(self):
        js = read("admin/js/report_card.js")
        self.assertNotIn("setTimeout(done, 1500)", js)
        self.assertIn("window.addEventListener('afterprint', done);", js)
        self.assertIn("['pointerdown', 'keydown', 'focus']", js)
