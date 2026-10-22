"""Score limit + "Did not take" (Phase 1, 2026-10-22) — static and unit pins.

Live behaviour is covered by tests/e2e/score_limit_api.py (41 checks) and
tests/e2e/score_limit_browser.py (34 checks). These pins catch a lost cache
tag, a lost script include, or a drift between the web and server rules.
"""
import json
import os
import re
import subprocess
import unittest

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))


def read(rel):
    with open(os.path.join(ROOT, rel), encoding='utf-8') as fh:
        return fh.read()


def php(code):
    r = subprocess.run(['php', '-r', code], capture_output=True, text=True, cwd=ROOT)
    return r.stdout.strip()


class Includes(unittest.TestCase):
    def test_every_entry_screen_loads_the_guard(self):
        self.assertIn('/admin/js/score_limit.js?v=20261022s', read('admin/dashboards/teacher.php'))
        self.assertIn('/admin/js/score_limit.js?v=20261022s', read('admin/dashboards/edu_dept.php'))
        af = read('admin/assessment_followups.php')
        self.assertIn("$assetVer = '20261022s';", af)
        self.assertLess(af.index('score_limit.js'), af.index('assessment_followups.js'))

    def test_changed_assets_have_new_versions(self):
        edu, teacher = read('admin/dashboards/edu_dept.php'), read('admin/dashboards/teacher.php')
        for page in (edu, teacher):
            self.assertIn('/admin/js/report_card.js?v=20261022s', page)
            self.assertIn('/admin/css/report_card.css?v=20261022s', page)
        self.assertIn('academic_tracking.js?v=20261022s', edu)
        self.assertNotIn('academic_tracking.js?v=20261003', edu)

    def test_screens_bind_and_check_before_saving(self):
        teacher = read('admin/dashboards/teacher.php')
        self.assertIn("FKSSScoreLimit.bind(tbody, '.grade-input')", teacher)
        self.assertIn("FKSSScoreLimit.bind(tbody, '.submit-score')", teacher)
        self.assertGreaterEqual(teacher.count('FKSSScoreLimit.problems('), 2)
        self.assertIn('will be marked “Did not take”', teacher)
        edu = read('admin/dashboards/edu_dept.php')
        self.assertIn("'.grade-input')", edu)
        self.assertIn('FKSSScoreLimit.problems(', edu)
        af = read('admin/js/assessment_followups.js')
        self.assertIn("'#af-a-score, #af-a-max'", af)
        self.assertIn('FKSSScoreLimit.problems(', af)


class GuardRules(unittest.TestCase):
    """isValid() in Node must agree with the server rule."""
    CASES = [('', 10, True, True), ('8', 10, True, True), ('10', 10, True, True), ('9.75', 10, True, True),
             ('9.', 10, False, True), ('15', 10, False, False), ('10.5', 10, False, False),
             ('9.755', 10, False, False), ('-1', 10, False, False), ('abc', 10, False, False),
             ('1e1', 10, False, False), ('12345', None, False, False), ('0', 10, True, True)]

    def test_is_valid(self):
        js = read('admin/js/score_limit.js')
        cases = json.dumps([[c[0], c[1]] for c in self.CASES])
        script = ('var window={};' + js +
                  ';var out=' + cases + '.map(function(c){return [window.FKSSScoreLimit.isValid(c[0],c[1],true),'
                  'window.FKSSScoreLimit.isValid(c[0],c[1],false)]});console.log(JSON.stringify(out));')
        r = subprocess.run(['node', '-e', script], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        got = json.loads(r.stdout)
        for (text, mx, final_ok, typing_ok), (f, t) in zip(self.CASES, got):
            self.assertEqual(f, final_ok, 'final %r/%r' % (text, mx))
            self.assertEqual(t, typing_ok, 'typing %r/%r' % (text, mx))

    def test_hint_never_floats_over_other_screens(self):
        js = read('admin/js/score_limit.js')
        self.assertIn("addEventListener('focusout'", js)
        self.assertIn("addEventListener('scroll'", js)
        self.assertNotIn("dispatchEvent(new Event('input'", js)


class ServerContract(unittest.TestCase):
    def test_every_write_path_refuses_invalid_scores(self):
        self.assertIn('INVALID_SCORES', read('api/v1/routes/grades.php'))
        self.assertIn('INVALID_SCORES', read('admin/api_subjects.php'))
        self.assertIn('MAX_BELOW_SAVED_SCORES', read('admin/api_subjects.php'))
        self.assertIn('INVALID_SCORES', read('admin/api_communication.php'))

    def test_server_rule_matches_web_rule(self):
        inc = ("require 'admin/backend/services/SubmissionService.php';"
               "mysqli_report(MYSQLI_REPORT_OFF); $conn=@new mysqli('127.0.0.1','ssms','ssms','ssms_test');"
               "if($conn->connect_errno){echo 'NODB';exit;}")
        rows = json.dumps([{'member_id': i, 'score': c[0]} for i, c in enumerate(GuardRules.CASES) if c[1] == 10])
        out = php(inc + "$r=\\App\\Services\\SubmissionService::invalidScores($conn,json_decode('" + rows +
                  "',true),10.0); echo json_encode(array_map(function($x){return (int)$x['member_id'];},$r));")
        if out == 'NODB':
            self.skipTest('local ssms_test database not available')
        bad = set(json.loads(out))
        for i, c in enumerate(GuardRules.CASES):
            if c[1] != 10:
                continue
            self.assertEqual(i in bad, not c[2], 'server vs web for %r' % c[0])

    def test_makeup_out_of_above_test_max_is_refused_not_rewritten(self):
        svc = read('admin/backend/services/AssessmentFollowupService.php')
        self.assertIn('"Out of" cannot be more than the test', svc)
        self.assertNotRegex(svc, r'if \(\$assessMax > 0 && \$max > \$assessMax\) \{\s*\$max = \$assessMax;')

    def test_update_assessment_empty_due_date_is_null(self):
        self.assertIn("trim($_POST['due_date']) !== ''", read('admin/api_subjects.php'))

    def test_report_card_and_tracking_carry_not_taken(self):
        self.assertIn('addNotTaken', read('admin/backend/services/ReportCardService.php'))
        self.assertIn("'not_taken'", read('admin/backend/services/AcademicTrackingService.php'))
        self.assertIn('rc-dnt', read('admin/js/report_card.js'))
        self.assertIn('at-dnt', read('admin/js/academic_tracking.js'))


if __name__ == '__main__':
    unittest.main()
