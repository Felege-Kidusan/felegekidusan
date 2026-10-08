"""
Grade-entry term fence (1.6.4+30, 2026-10-08) — semester-boundary gap 1
═════════════════════════════════════════════════════════════════════════════
GAP (from the semester-boundary analysis): marks are stamped with the
ASSESSMENT's term (save_grades / mobile save_grades read
assessments.term_id), and assessments are stamped with the CURRENT term at
creation. After a semester flip that boundary was invisible and
unoverridable: a test created post-flip silently belonged to the new
semester, and a teacher entering marks could not see which semester they
were landing in. Back-fill was possible only by accident, never by choice.

FIX (pinned here):
  • api_subjects.php: _subj_current_term() resolves the ACTIVE year's
    current semester; _subj_term_of_active_year() validates fence overrides
    (only semesters of the ACTIVE year are legal targets).
  • create_assessment: optional validated term_id override; response
    names the semester the test was assigned to.
  • update_assessment: optional validated term_id move — re-stamps the
    assessment's existing academic_records in the same transaction, so all
    marks of one test sit in one semester (remediation for legacy NULLs).
  • get_assessments: joins term name/number, exposes is_current_term per
    assessment and a top-level current_term object.
  • get_students_for_grading: the assessment payload carries term_name,
    current_term_name and is_current_term.
  • teacher.php: "Recording into: <semester>" on both entry screens, amber
    fence notices on semester mismatch / missing semester, and term tags in
    the assessment dropdowns.

NOT changed (deliberate): back-fill stays ALLOWED (Ethiopian schools
back-fill after the flip — the attendance layer even documents backfilling
as a normal flow). The fence makes the boundary explicit, never blocks it.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class TermResolverPinned(unittest.TestCase):
    def setUp(self):
        self.api = read("admin/api_subjects.php")

    def test_current_term_is_scoped_to_the_active_year(self):
        self.assertIn("function _subj_current_term(mysqli $conn)", self.api)
        seg = self.api.split("function _subj_current_term")[1][:900]
        self.assertIn("y.status = 'active'", seg)
        self.assertIn("t.is_current = 1", seg)

    def test_override_validation_only_allows_active_year_terms(self):
        self.assertIn("function _subj_term_of_active_year(mysqli $conn, int $termId)", self.api)
        seg = self.api.split("function _subj_term_of_active_year")[1][:700]
        self.assertIn("y.status = 'active'", seg)
        self.assertIn("WHERE t.id = ? AND y.status = 'active' LIMIT 1", seg)


class CreateAssessmentFencePinned(unittest.TestCase):
    def setUp(self):
        self.api = read("admin/api_subjects.php")

    def test_create_defaults_to_current_term(self):
        seg = self.api.split("case 'create_assessment'")[1].split("case 'update_assessment'")[0]
        self.assertIn("$ct = _subj_current_term($conn);", seg)

    def test_create_accepts_a_validated_override(self):
        seg = self.api.split("case 'create_assessment'")[1].split("case 'update_assessment'")[0]
        self.assertIn("$postedTermId = (int)($_POST['term_id'] ?? 0);", seg)
        self.assertIn("_subj_term_of_active_year($conn, $postedTermId)", seg)
        self.assertIn("That semester is not part of the active academic year.", seg)

    def test_create_response_names_the_semester(self):
        seg = self.api.split("case 'create_assessment'")[1].split("case 'update_assessment'")[0]
        self.assertIn("'term_name' => $termName", seg)


class UpdateAssessmentFencePinned(unittest.TestCase):
    def setUp(self):
        self.api = read("admin/api_subjects.php")

    def test_moving_a_test_re_stamps_its_marks_in_one_transaction(self):
        seg = self.api.split("case 'update_assessment'")[1].split("case 'delete_assessment'")[0]
        self.assertIn("$conn->begin_transaction();", seg)
        self.assertIn("UPDATE assessments SET term_id=? WHERE id=?", seg)
        self.assertIn("UPDATE academic_records SET term_id=? WHERE assessment_id=?", seg)
        self.assertIn("$conn->commit();", seg)
        self.assertIn("$conn->rollback();", seg)

    def test_move_target_is_validated(self):
        seg = self.api.split("case 'update_assessment'")[1].split("case 'delete_assessment'")[0]
        self.assertIn("_subj_term_of_active_year($conn, $postedTermId)", seg)
        self.assertIn("That semester is not part of the active academic year.", seg)


class ReadSurfacesPinned(unittest.TestCase):
    def setUp(self):
        self.api = read("admin/api_subjects.php")

    def test_get_assessments_joins_term_and_exposes_current(self):
        seg = self.api.split("case 'get_assessments'")[1].split("case 'create_assessment'")[0]
        self.assertIn("LEFT JOIN academic_terms t ON t.id = a.term_id", seg)
        self.assertIn("'current_term' => $ctermOut", seg)
        self.assertIn("$a['is_current_term'] =", seg)

    def test_students_for_grading_carries_term_context(self):
        seg = self.api.split("case 'get_students_for_grading'")[1].split("case 'save_grades'")[0]
        self.assertIn("$assessment['current_term_name']", seg)
        self.assertIn("$assessment['is_current_term']", seg)
        self.assertIn("$assessment['term_name']", seg)

    def test_save_grades_stamps_from_the_assessment_term(self):
        seg = self.api.split("case 'save_grades'")[1]
        end = seg.find("    case '")
        if end > 0:
            seg = seg[:end]
        self.assertIn("$termId = !empty($assessment['term_id']) ? (int)$assessment['term_id'] : null;", seg)


class TeacherUiFencePinned(unittest.TestCase):
    def setUp(self):
        self.ui = read("admin/dashboards/teacher.php")

    def test_grade_entry_shows_recording_target(self):
        self.assertIn("Recording into:", self.ui)

    def test_grade_entry_has_fence_notice_element_and_logic(self):
        self.assertIn('id="termFenceNotice"', self.ui)
        self.assertIn("a.is_current_term === false", self.ui)
        self.assertIn("Semester fence.", self.ui)

    def test_submit_flow_has_the_same_fence(self):
        self.assertIn('id="submitTermFenceNotice"', self.ui)

    def test_dropdowns_tag_non_current_and_missing_semesters(self):
        self.assertIn("no semester", self.ui)

    def test_legacy_null_semester_is_flagged(self):
        self.assertIn("No semester assigned.", self.ui)
        self.assertIn("count in <strong>every</strong> report", self.ui)


if __name__ == "__main__":
    unittest.main()
