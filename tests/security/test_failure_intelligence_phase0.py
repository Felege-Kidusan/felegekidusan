"""Static regression pins for the Failure Intelligence Phase 0 hardening.

Pins the six audit-hardening fixes (F1, F4, F6, F10, F13, F14) and the
F17 corruption repair discovered during Phase 0 verification: a fatal
double-backslash parse error in api/v1/core/response.php (committed by
the original sync-monitoring commit) that made every API request fail to
load the response layer at HEAD. These are static contract tests in the
same style as test_mobile_fleet_telemetry_retention.py; the optional
php -l gate runs only when a PHP CLI binary is available.
"""

from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class FailureIntelligencePhase0Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.response = (ROOT / "api/v1/core/response.php").read_bytes()
        cls.app_route = (ROOT / "api/v1/routes/app.php").read_text(encoding="utf-8")
        cls.monitor = (
            ROOT / "admin/backend/services/ApiSyncAttemptMonitorService.php"
        ).read_text(encoding="utf-8")
        cls.retention_job = (
            ROOT / "admin/backend/mobile_telemetry_retention.php"
        ).read_text(encoding="utf-8")
        cls.section = (
            ROOT / "admin/dashboards/sections/app_telemetry_section.php"
        ).read_text(encoding="utf-8")
        cls.hardening_doc = (
            ROOT / "docs/audits/MOBILE_FLEET_TELEMETRY_INTEGRITY_HARDENING.md"
        ).read_text(encoding="utf-8")
        cls.retention_doc = (
            ROOT / "docs/audits/MOBILE_FLEET_TELEMETRY_RETENTION.md"
        ).read_text(encoding="utf-8")
        cls.postdrill = (
            ROOT / "sql/preflight/mobile_fleet_telemetry_postdrill.sql"
        ).read_text(encoding="utf-8")
        cls.kotlin_trap = (
            ROOT
            / "Mobile/wbws_flutter_app/android/app/src/main/kotlin"
            / "com/arkeonethiopia/fkss/FkssApplication.kt"
        ).read_text(encoding="utf-8")

    # ── F17: the committed parse error must never return ─────────────────────

    def test_f17_response_php_uses_valid_fqcn_bytes(self):
        # Byte-level asserts: a single-backslash FQCN in code position.
        self.assertIn(
            b"\x5cApp\x5cServices\x5cApiSyncAttemptMonitorService::entityReference",
            self.response,
        )
        # The corruption signature: double backslashes in code position.
        self.assertNotIn(
            b"\x5c\x5cApp\x5c\x5cServices\x5c\x5cApiSyncAttemptMonitorService::entityReference",
            self.response,
        )

    def test_f17_touched_php_files_parse(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("php CLI not available; byte pins still enforced")
        targets = [
            "api/v1/core/response.php",
            "api/v1/routes/app.php",
            "admin/backend/services/ApiSyncAttemptMonitorService.php",
            "admin/backend/mobile_telemetry_retention.php",
            "admin/dashboards/sections/app_telemetry_section.php",
        ]
        for rel in targets:
            with self.subTest(target=rel):
                result = subprocess.run(
                    [php, "-l", str(ROOT / rel)],
                    capture_output=True,
                    text=True,
                    timeout=60,
                )
                self.assertEqual(result.returncode, 0, result.stderr)

    # ── F1: download ip_hash must be keyed and UTC-day rotated ───────────────

    def test_f1_download_ip_hash_is_keyed_hmac_on_utc_day(self):
        self.assertIn("hash_hmac('sha256'", self.app_route)
        self.assertIn("gmdate('Ymd')", self.app_route)
        # The unkeyed, local-time writer must not return.
        self.assertNotIn("hash('sha256', ($_SERVER['REMOTE_ADDR']", self.app_route)
        self.assertNotIn("'::' . date('Ymd')", self.app_route)

    # ── F4: the form-encoded fallback must obey the byte budget ──────────────

    def test_f4_form_fallback_is_byte_bounded(self):
        self.assertIn("if (!is_array($data)) {", self.response.decode("utf-8"))
        self.assertIn("http_build_query($data)", self.response.decode("utf-8"))
        self.assertIn("$formSize > $maxBytes", self.response.decode("utf-8"))

    # ── F6: one clock for the whole sync-attempt ledger ──────────────────────

    def test_f6_monitor_ledger_uses_db_clock_only(self):
        self.assertNotIn("date('Y-m-d H:i:s')", self.monitor)
        self.assertIn("NOW(), NOW(), NOW(), NOW())'", self.monitor)
        self.assertIn("database session", self.monitor)

    # ── F10: installations retained by design, growth made visible ───────────

    def test_f10_installations_retained_and_growth_visible(self):
        self.assertIn(
            "installations unseen for 395+ days (retained by design)",
            self.postdrill,
        )
        self.assertIn("retained by design", self.retention_doc)
        # The pinned invariant stands: the job never deletes installations.
        self.assertNotIn("DELETE FROM `app_installations`", self.retention_job)

    # ── F13: crash-log rotation must trim, not wipe ──────────────────────────

    def test_f13_native_crash_log_rotation_trims_tail(self):
        self.assertIn("trimLogTail(log)", self.kotlin_trap)
        self.assertIn("MAX_LOG_BYTES / 2", self.kotlin_trap)
        self.assertIn("copyOfRange", self.kotlin_trap)
        # log.delete() survives only as the never-throw fallback.
        self.assertEqual(self.kotlin_trap.count("log.delete()"), 1)

    # ── F14: crash-counter semantics disclosed where the KPI is shown ────────

    def test_f14_crash_counter_semantics_disclosed(self):
        self.assertIn(
            "Process-level failures (incl. background sync)",
            self.section,
        )
        self.assertIn("Counter semantics (interpretation note)", self.hardening_doc)
        # The JS contract for the KPI spans is unchanged.
        ui_js = (ROOT / "admin/js/app_telemetry.js").read_text(encoding="utf-8")
        self.assertIn("setTxt('kpi-launches'", ui_js)
        self.assertIn("setTxt('kpi-crashes'", ui_js)


if __name__ == "__main__":
    unittest.main()
