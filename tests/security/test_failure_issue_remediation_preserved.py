"""Regression pins: a status change must not erase saved remediation notes.

Bug (fixed 2026-10-25): the Failure Intelligence status buttons (Acknowledge,
Resolve, Reopen) post only id/status/resolved_note. The endpoint still passed
all three remediation fields to FailureIssueService::updateIssue(), which
treats a *present* field as "set this value" (empty clears it). Every status
change therefore wiped the saved remediation cause, steps and link.

The fix: the endpoint passes a remediation field only when the request sent
it. Save remediation always sends all three, so saving and clearing are
unchanged. The behaviour was proven old-vs-new on a local MariaDB (10
scenarios); these static pins keep the contract from regressing.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
FIELDS = ("remediation_cause", "remediation_steps", "remediation_link")


def _js_function(source: str, name: str) -> str:
    start = source.index(f"function {name}(")
    nxt = source.find("\n  function ", start + 1)
    return source[start:nxt if nxt != -1 else len(source)]


class FailureIssueRemediationPreservedTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        endpoint = (ROOT / "admin/api_failure_intelligence.php").read_text(encoding="utf-8")
        start = endpoint.index("case 'update_failure_issue':")
        end = endpoint.index("case '", start + 1)
        cls.update_case = endpoint[start:end]
        cls.service = (
            ROOT / "admin/backend/services/FailureIssueService.php"
        ).read_text(encoding="utf-8")
        cls.ui_js = (ROOT / "admin/js/failure_intelligence.js").read_text(encoding="utf-8")

    def test_status_buttons_send_no_remediation_fields(self):
        # Why the guard below matters: setStatus never sends these fields.
        body = _js_function(self.ui_js, "setStatus")
        self.assertIn("'update_failure_issue'", body)
        for field in FIELDS:
            self.assertNotIn(field, body)

    def test_save_remediation_still_sends_all_three_fields(self):
        # Saving (and clearing with empty values) relies on all three being sent.
        body = _js_function(self.ui_js, "saveRemediation")
        self.assertIn("'update_failure_issue'", body)
        for field in FIELDS:
            self.assertIn(f"{field}:", body)

    def test_endpoint_passes_remediation_only_when_sent(self):
        self.assertIn("array_key_exists($field, $_POST)", self.update_case)
        loop = re.search(r"foreach \(\[([^\]]*)\] as \$field\)", self.update_case)
        self.assertIsNotNone(loop, "remediation field loop not found")
        for field in FIELDS:
            self.assertIn(f"'{field}'", loop.group(1))
            # Never an unconditional array entry (the old, wiping shape).
            self.assertNotRegex(self.update_case, rf"'{field}'\s*=>")

    def test_service_updates_only_present_remediation_fields(self):
        self.assertIn("array_key_exists($field, $fields)", self.service)


if __name__ == "__main__":
    unittest.main()
