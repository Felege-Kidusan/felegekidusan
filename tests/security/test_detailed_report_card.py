"""
Detailed semester report card — A4 landscape redesign (1.6.9+35, 2026-10-09)
══════════════════════════════════════════════════════════════════════════════════
MODEL (user Decision A, 2026-10-08, unchanged): the annual report card is
DETAILED and semester-based —
  • per subject, every assessment listed ONE BY ONE under its semester,
    then that semester's total from 100% in its own column;
  • a summary table: subject | 1st semester | 2nd semester | annual;
  • annual = the plain AVERAGE of the two semester totals (the per-year
    s1/s2 weights from 056 are superseded and no longer applied);
  • semester-only subjects show a "—" in the semester they don't run, so a
    dash means "semester-based subject, not offered here" — never a zero.

1.6.9 (user-approved reference design): the card is A4 LANDSCAPE with a
two-column layout (left: profile, metrics, summary, attendance, scale &
signatures; right: assessment ledger) and a READABLE type scale — data
values ≥ 7.6pt (the reference's 5–7pt text was unreadable).

SURFACES PINNED HERE
  SubjectDurationPolicy      FULL_YEAR annual = plain average
  ReportCardService          per-assessment term tagging + semester_detail
  report_card.js             A4-landscape ledger layout + summary + dashes
  report_card.css            landscape page, ledger grid, readable sizes
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

    def test_sheet_is_a4_landscape_two_column(self):
        self.assertIn('class="rc-sheet rc-a4l"', self.js)
        self.assertIn("rc-content", self.js)
        self.assertIn("rc-col-left", self.js)
        self.assertIn("rc-col-right", self.js)

    def test_assessments_render_one_by_one_in_the_ledger(self):
        self.assertIn("function slot(a)", self.js)
        self.assertIn("rc-slot-name", self.js)
        self.assertIn("rc-slot-score", self.js)
        self.assertIn("rc-slot-wt", self.js)
        self.assertIn("rc-slot-pct", self.js)
        # real assessment names — not A1–A6 slot codes
        self.assertIn("esc(a.assessment_name || '')", self.js)
        self.assertNotIn("A1", self.js.split("function slot")[1][:400])

    def test_ledger_grid_is_bilingual_with_totals_columns(self):
        self.assertIn("rc-ledg-grid", self.js)
        self.assertIn("1st semester · 1ኛ ሴሚስተር", self.js)
        self.assertIn("2nd semester · 2ኛ ሴሚስተር", self.js)
        self.assertIn("semTotal(det.s1 && det.s1.total)", self.js)
        self.assertIn("semTotal(det.s2 && det.s2.total)", self.js)

    def test_each_semester_total_is_explained_from_100(self):
        self.assertIn("<b>Total</b> = semester result from 100%", self.js)
        self.assertIn("<b>Annual</b> = average of the semester totals", self.js)

    def test_semester_only_subjects_show_a_dash_not_a_zero(self):
        self.assertIn("Not offered this semester", self.js)
        self.assertIn("rc-slot-off", self.js)
        self.assertIn("SEMESTER_ONLY", self.js)
        self.assertIn("offering_term_number", self.js)

    def test_running_subjects_say_so(self):
        self.assertIn("Full year — continues next semester", self.js)

    def test_summary_table_columns(self):
        self.assertIn("<th>Subject</th>", self.js)
        self.assertIn('<th class="num">1st sem.</th>', self.js)
        self.assertIn('<th class="num">2nd sem.</th>', self.js)
        self.assertIn('<th class="num">Annual</th>', self.js)
        self.assertIn("rc-total-row", self.js)

    def test_header_and_footer_identity(self):
        self.assertIn("Student Report Card", self.js)
        self.assertIn("rc-school-am", self.js)
        self.assertIn("Confidential academic record", self.js)

    def test_term_view_gets_the_matching_single_semester_skin(self):
        self.assertIn("rc-ledg-grid-term", self.js)
        self.assertIn("<div>Assessments</div>", self.js)

    def test_exports_are_unchanged(self):
        self.assertIn("renderSheet: renderSheet,", self.js)
        self.assertIn("fillModal: fillModal,", self.js)
        self.assertIn("printSheets: printSheets,", self.js)


class StylesPinned(unittest.TestCase):
    def setUp(self):
        self.css = read("admin/css/report_card.css")

    def test_print_page_is_a4_landscape(self):
        self.assertIn("@page{size:A4 landscape; margin:0}", self.css)
        self.assertIn("width:297mm", self.css)
        self.assertIn("min-height:207mm", self.css)

    def test_reference_palette_and_gold_frame(self):
        self.assertIn("--maroon:#6f171e", self.css)
        self.assertIn("inset:4mm", self.css)

    def test_type_is_readable(self):
        # base body size and the smallest data size must stay legible
        self.assertIn("font-size:9pt", self.css)
        self.assertIn("font-size:7.6pt", self.css)
        self.assertIn("font-variant-numeric:tabular-nums", self.css)

    def test_ledger_grid_and_cards_exist(self):
        for cls in (
            ".rc-ledg-grid{",
            ".rc-ledg-grid-term{",
            ".rc-slot{",
            ".rc-ledg-total{",
            ".rc-metric{",
            ".rc-badge{",
            ".rc-identity{",
            ".rc-progress{",
            ".rc-signs{",
        ):
            self.assertIn(cls, self.css)

    def test_ledger_rows_do_not_break_across_print_pages(self):
        self.assertIn(".rc-ledg-row{break-inside:avoid}", self.css)


if __name__ == "__main__":
    unittest.main()
