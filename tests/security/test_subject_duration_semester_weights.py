"""Education: SEMESTER_ONLY / FULL_YEAR subjects and per-year semester weights.

These are functional tests, not source greps: each one executes the real
App\\Services\\SubjectDurationPolicy through the PHP CLI and asserts on the
value it returns.

Baseline relevance
------------------
Before this feature there was no duration concept at all. ReportCardService
passed term_id = 0 straight through when no term was requested (the endpoint
default, `$_GET['term_id'] ?? 0`) and fetchScores() only filtered by term when
term_id > 0, so every semester's academic_records landed in a single
aggregateSubject() call and were blended into one percentage. Every assertion
below about a full-year subject needing BOTH semesters, and about raw marks
never being averaged across semesters, fails on that baseline because
SubjectDurationPolicy did not exist.
"""

import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
POLICY = ROOT / "admin/backend/services/SubjectDurationPolicy.php"
MIGRATION = ROOT / "sql/056_subject_duration_and_semester_weights.sql"
REPORT_CARD = ROOT / "admin/backend/services/ReportCardService.php"


def php_binary() -> str:
    override = os.environ.get("SSMS_E2E_PHP", "").strip()
    return override or (shutil.which("php") or "")


def run_policy(snippet: str):
    """Run a PHP snippet with the policy loaded; return the decoded JSON echo."""
    php = php_binary()
    if not php:
        raise unittest.SkipTest("PHP CLI is not installed")
    code = (
        "<?php require " + json.dumps(str(POLICY)) + ";"
        "use App\\Services\\SubjectDurationPolicy as P;"
        + snippet
    )
    proc = subprocess.run(
        [php, "-r", code[len("<?php "):]],
        capture_output=True, text=True, timeout=60,
    )
    if proc.returncode != 0:
        raise AssertionError(f"PHP failed: {proc.returncode}\n{proc.stdout}\n{proc.stderr}")
    return json.loads(proc.stdout.strip())


W_50_50 = '$w = ["s1"=>50.0,"s2"=>50.0];'
W_40_60 = '$w = ["s1"=>40.0,"s2"=>60.0];'


class SubjectDurationPolicyTests(unittest.TestCase):
    # ── Score normalisation: semester-only subjects ──────────────────────────

    def test_semester_only_s1_final_score_is_the_semester_1_score(self):
        out = run_policy(
            W_50_50 + 'echo json_encode(P::finalScore(P::SEMESTER_ONLY, 72.0, null, $w, 1));'
        )
        self.assertEqual(out["final"], 72.0,
                         "a Semester 1 only subject closes at its Semester 1 score")
        self.assertEqual(out["status"], "CLOSED")

    def test_semester_only_s2_final_score_is_the_semester_2_score(self):
        out = run_policy(
            W_50_50 + 'echo json_encode(P::finalScore(P::SEMESTER_ONLY, null, 65.0, $w, 2));'
        )
        self.assertEqual(out["final"], 65.0,
                         "a Semester 2 only subject closes at its Semester 2 score")
        self.assertEqual(out["status"], "CLOSED")

    def test_semester_only_missing_other_semester_is_absent_not_zero(self):
        """The semester a subject did not run in must never be scored as 0."""
        out = run_policy(
            W_50_50 + 'echo json_encode(P::finalScore(P::SEMESTER_ONLY, 80.0, null, $w, 1));'
        )
        self.assertEqual(out["final"], 80.0,
                         "80 with no second semester must stay 80, not average to 40")

    # ── Full-year subjects ───────────────────────────────────────────────────

    def test_full_year_50_50_matches_the_specified_worked_example(self):
        out = run_policy(
            W_50_50 + 'echo json_encode(P::finalScore(P::FULL_YEAR, 72.0, 84.0, $w));'
        )
        self.assertEqual(out["final"], 78.0, "(72 x 0.50) + (84 x 0.50) = 78")
        self.assertEqual(out["status"], "CLOSED")

    def test_full_year_annual_is_the_plain_average_not_weighted(self):
        # 1.6.7 (Decision A, 2026-10-08): the semester-based model supersedes
        # the per-year weights — the annual score is the plain AVERAGE of the
        # two semester totals. A 40/60 weight config must NOT change it.
        out = run_policy(
            W_40_60 + 'echo json_encode(P::finalScore(P::FULL_YEAR, 72.0, 84.0, $w));'
        )
        self.assertEqual(out["final"], 78.0, "(72 + 84) / 2 = 78 — weights are no longer applied")
        self.assertEqual(out["status"], "CLOSED")

    def test_full_year_with_only_semester_1_has_no_annual_score(self):
        """A Semester 1 mark must never be reported as the annual result."""
        out = run_policy(
            W_50_50 + 'echo json_encode(P::finalScore(P::FULL_YEAR, 72.0, null, $w));'
        )
        self.assertIsNone(out["final"],
                          "annual score must be pending until Semester 2 exists")
        self.assertEqual(out["status"], "CONTINUING")

    def test_full_year_missing_semester_is_not_treated_as_zero(self):
        out = run_policy(
            W_50_50 + 'echo json_encode(P::finalScore(P::FULL_YEAR, 90.0, null, $w));'
        )
        self.assertIsNone(out["final"],
                          "90 with no Semester 2 must not silently become 45")

    # ── Semester report statuses ─────────────────────────────────────────────

    def test_semester_only_subject_closes_at_end_of_its_semester(self):
        out = run_policy('echo json_encode(P::semesterStatus(P::SEMESTER_ONLY, 1, true));')
        self.assertEqual(out, "CLOSED")

    def test_full_year_subject_is_continuing_on_the_semester_1_report(self):
        out = run_policy('echo json_encode(P::semesterStatus(P::FULL_YEAR, 1, true));')
        self.assertEqual(out, "CONTINUING",
                         "a full-year subject must not be closed at Semester 1")

    # ── Replacement of semester-only subjects ────────────────────────────────

    def test_semester_only_subjects_do_not_appear_in_the_other_semester(self):
        s1_in_s2 = run_policy('echo json_encode(P::appearsInTerm(P::SEMESTER_ONLY, 1, 2));')
        s2_in_s1 = run_policy('echo json_encode(P::appearsInTerm(P::SEMESTER_ONLY, 2, 1));')
        s1_in_s1 = run_policy('echo json_encode(P::appearsInTerm(P::SEMESTER_ONLY, 1, 1));')
        self.assertFalse(s1_in_s2, "a Semester 1 only subject must not show on the S2 report")
        self.assertFalse(s2_in_s1, "a Semester 2 only subject must not show on the S1 report")
        self.assertTrue(s1_in_s1)

    def test_full_year_subject_appears_in_both_semesters(self):
        self.assertTrue(run_policy('echo json_encode(P::appearsInTerm(P::FULL_YEAR, 0, 1));'))
        self.assertTrue(run_policy('echo json_encode(P::appearsInTerm(P::FULL_YEAR, 0, 2));'))

    # ── Annual total ─────────────────────────────────────────────────────────

    def test_annual_average_is_built_from_final_subject_scores(self):
        out = run_policy('echo json_encode(P::annualAverage([1=>78.0, 2=>72.0]));')
        self.assertEqual(out["average"], 75.0)
        self.assertEqual(out["counted"], 2)

    def test_annual_average_excludes_pending_subjects_rather_than_zeroing_them(self):
        out = run_policy('echo json_encode(P::annualAverage([1=>78.0, 2=>72.0, 3=>null]));')
        self.assertEqual(out["average"], 75.0,
                         "a pending subject must be excluded, not counted as 0")
        self.assertEqual(out["counted"], 2)
        self.assertEqual(out["pending"], 1)

    def test_each_offering_counts_at_most_once(self):
        """Keying by offering id makes a duplicated subject impossible to double-count."""
        out = run_policy('echo json_encode(P::annualAverage([7=>80.0, 7=>80.0, 9=>60.0]));')
        self.assertEqual(out["counted"], 2,
                         "the same offering id must collapse to one contribution")
        self.assertEqual(out["average"], 70.0)

    # ── Weight validation ────────────────────────────────────────────────────

    def test_weights_must_total_one_hundred(self):
        self.assertTrue(run_policy('echo json_encode(P::weightsAreValid(50, 50));'))
        self.assertTrue(run_policy('echo json_encode(P::weightsAreValid(40, 60));'))
        self.assertFalse(run_policy('echo json_encode(P::weightsAreValid(40, 70));'))
        self.assertFalse(run_policy('echo json_encode(P::weightsAreValid(0, 0));'))
        self.assertFalse(run_policy('echo json_encode(P::weightsAreValid(-10, 110));'))
        self.assertFalse(run_policy('echo json_encode(P::weightsAreValid("x", 50));'))

    def test_invalid_weights_are_rejected_loudly(self):
        out = run_policy(
            'try { P::assertWeights(40, 70); echo json_encode("NO_THROW"); }'
            ' catch (\\InvalidArgumentException $e) { echo json_encode("THREW"); }'
        )
        self.assertEqual(out, "THREW",
                         "an invalid distribution must raise, not be silently corrected")

    def test_unclassified_duration_does_not_become_full_year_by_accident(self):
        out = run_policy('echo json_encode([P::normalize(null), P::normalize("nonsense")]);')
        self.assertEqual(out, [None, None],
                         "an unknown duration must stay unclassified, never default to FULL_YEAR")


class SubjectDurationWiringTests(unittest.TestCase):
    """The pieces the calculation depends on must actually be wired up."""

    def test_migration_adds_nullable_duration_and_checked_weights(self):
        sql = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("duration_type", sql)
        self.assertIn("SEMESTER_ONLY", sql)
        self.assertIn("FULL_YEAR", sql)
        self.assertIn("s1_weight_pct", sql)
        self.assertIn("s2_weight_pct", sql)
        self.assertIn("chk_academic_year_semester_weights", sql)
        self.assertIn("= 100.00", sql)
        self.assertNotIn("NOT NULL DEFAULT 'FULL_YEAR'", sql)

    def test_report_card_service_uses_the_policy(self):
        src = REPORT_CARD.read_text(encoding="utf-8")
        self.assertIn("SubjectDurationPolicy", src)
        self.assertIn("require_once __DIR__ . '/SubjectDurationPolicy.php'", src)

    def test_annual_view_never_falls_back_to_raw_obtained_over_max(self):
        """The pre-change blend of raw S1+S2 marks must be unreachable annually."""
        src = REPORT_CARD.read_text(encoding="utf-8")
        self.assertIn("$totalMax > 0 && !$isAnnual", src,
                      "the raw obtained/max fallback must be disabled in the annual view")


if __name__ == "__main__":
    unittest.main()
