"""Static regression pins for the Failure Intelligence Phase 2 hardening.

Phase 2 makes crash identities readable without weakening the privacy
contract: a `crash_signature` telemetry event carries the exception class
plus up to three first-party frame names through the existing exact-once
chain, and `app_crash_signatures` (migration 063) keeps the signature plus
durable lifetime aggregates per crash key.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]


class FailureIntelligencePhase2Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = (ROOT / "sql/063_crash_signatures.sql").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/telemetry.php").read_text(encoding="utf-8")
        cls.crash_log = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/crash_log_service.dart"
        ).read_text(encoding="utf-8")
        cls.telemetry = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/telemetry_service.dart"
        ).read_text(encoding="utf-8")
        cls.main_dart = (
            ROOT / "Mobile/wbws_flutter_app/lib/main.dart"
        ).read_text(encoding="utf-8")
        cls.dart_test = (
            ROOT / "Mobile/wbws_flutter_app/test/crash_signature_test.dart"
        ).read_text(encoding="utf-8")
        cls.hardening_doc = (
            ROOT / "docs/audits/MOBILE_FLEET_TELEMETRY_INTEGRITY_HARDENING.md"
        ).read_text(encoding="utf-8")

    def test_migration_063_table_index_and_backfill(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `app_crash_signatures`", self.migration)
        self.assertIn("`crash_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL", self.migration)
        self.assertIn("`signature_class` VARCHAR(120)", self.migration)
        self.assertIn("`total_events` INT UNSIGNED NOT NULL DEFAULT 0", self.migration)
        self.assertIn("`first_seen_at`", self.migration)
        self.assertIn("`last_seen_at`", self.migration)
        # Covering index so read-time per-key aggregates stay cheap.
        self.assertIn(
            "ADD INDEX IF NOT EXISTS `idx_app_events_type_dedupe`\n    (`event_type`, `dedupe_key`, `installation_id`)",
            self.migration,
        )
        # Backfill is repeat-safe and never overwrites live counters.
        self.assertIn("INSERT IGNORE INTO `app_crash_signatures`", self.migration)
        self.assertIn("event_type IN ('crash', 'crash_recorded')", self.migration)
        self.assertIn("dedupe_key IS NOT NULL", self.migration)

    def test_route_accepts_and_validates_crash_signature_events(self):
        self.assertIn("'crash_signature',", self.route)
        self.assertIn("$eventType === 'crash_signature'", self.route)
        # Allow-listed payload keys only.
        self.assertIn("$assertAllowedKeys($data, ['crash_key', 'signature_class', 'frames'])", self.route)
        # Charset + length caps on both class and frames.
        self.assertEqual(
            self.route.count("preg_match('/^[A-Za-z0-9 .:_\\/<>()$#-]{1,120}$/D'"),
            2,
        )
        self.assertIn("count($framesInput) > 3", self.route)
        # Total JSON budget enforced in-branch, stricter than the 2048 cap.
        self.assertIn("strlen($encoded) > 512", self.route)
        # Exact-once: the crash key doubles as the dedupe key.
        self.assertIn("$crashDedupeKey = strtolower($crashKey);", self.route)

    def test_route_upserts_are_gated_and_best_effort(self):
        # Both upserts require a freshly inserted event (no double counting
        # on duplicate delivery) and sit inside a try/catch so a missing
        # migration can never fail the telemetry write.
        self.assertIn("if ($eventInserted && $crashDedupeKey !== null)", self.route)
        self.assertIn(
            "total_events = total_events + 1",
            self.route,
        )
        self.assertIn(
            "// Signature aggregation is advisory; never fail the event for it.",
            self.route,
        )
        # The signature event must NOT increment the crash counter.
        inc_crash = re.search(r"\$incCrash = \(([^)]+)\)", self.route)
        self.assertIsNotNone(inc_crash)
        self.assertIn("'crash'", inc_crash.group(1))
        self.assertNotIn("crash_signature", inc_crash.group(1))

    def test_client_builder_enforces_the_same_contract(self):
        self.assertIn("class CrashSignature", self.crash_log)
        self.assertIn("CrashSignature? buildCrashSignature(", self.crash_log)
        self.assertIn("_kMaxSignaturePartLength = 120", self.crash_log)
        self.assertIn("_kMaxSignatureFrames = 3", self.crash_log)
        self.assertIn("_kMaxSignatureJsonBytes = 512", self.crash_log)
        # First-party frames only.
        self.assertIn("package:fkss_app/", self.crash_log)
        self.assertIn("com.arkeonethiopia", self.crash_log)
        # Allow-listed charset sanitizer.
        self.assertIn(r"RegExp(r'[^A-Za-z0-9 .:_/<>()$#-]')", self.crash_log)
        # Deterministic budget enforcement.
        self.assertIn("_kMaxSignatureJsonBytes", self.crash_log)

    def test_client_sends_signature_ungated(self):
        self.assertIn("'crash_signature',", self.telemetry)
        self.assertIn("Future<void> recordCrashSignature(CrashLogEntry entry)", self.telemetry)
        self.assertIn("buildCrashSignature(entry)", self.telemetry)
        # Ungated by design (server exact-once), unlike recordCrash.
        record_crash = self.telemetry.split("Future<void> recordCrashSignature", 1)[0]
        self.assertIn("_kLastReportedCrashKey", record_crash)
        signature_body = "Future<void> recordCrashSignature" + self.telemetry.split(
            "Future<void> recordCrashSignature", 1
        )[1]
        self.assertNotIn("_kLastReportedCrashKey", signature_body.split("Future<void> recordUpdateDownloaded", 1)[0])
        # Wired into the boot path next to the crash report.
        self.assertIn("recordCrashSignature(recentCrash)", self.main_dart)

    def test_dart_unit_tests_exist_and_pin_the_caps(self):
        self.assertIn("buildCrashSignature", self.dart_test)
        self.assertIn("lessThanOrEqualTo(512)", self.dart_test)
        self.assertIn("hasLength(3)", self.dart_test)
        self.assertIn("deterministic", self.dart_test)

    def test_contract_documented(self):
        self.assertIn("### Crash signatures (readable WHERE, privacy-preserving)", self.hardening_doc)
        self.assertIn("exact-once", self.hardening_doc)
        self.assertIn("512 bytes", self.hardening_doc)


if __name__ == "__main__":
    unittest.main()
