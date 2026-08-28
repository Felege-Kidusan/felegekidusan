"""App Lock + long session tests (2026-08-28).

Telegram-style local passcode: the session stays signed in (90-day
rotating refresh tokens on the server), but the app content sits
behind a device-local PIN (+ optional biometrics) with auto-lock.

Security contract locked here:
  • PIN stored as sha256(salt ‖ pin) in platform secure storage —
    never plaintext, never on the server
  • wrong attempts throttled locally; biometrics fail closed to PIN
  • lock gate sits in front of the whole app (main.dart home swap)
  • cold start opens locked when a passcode is set
  • lifecycle auto-lock hooks in AppShell
  • hymn library + queued hymn edits SURVIVE logout (shared content),
    member/attendance data is still wiped
  • MainActivity extends FlutterFragmentActivity (local_auth requires)
"""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
M = ROOT / "Mobile/wbws_flutter_app"


class AppLockSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.lock_svc = (M / "lib/services/app_lock_service.dart").read_text(encoding="utf-8")
        cls.lock_screen = (M / "lib/screens/lock/lock_screen.dart").read_text(encoding="utf-8")
        cls.main = (M / "lib/main.dart").read_text(encoding="utf-8")
        cls.shell = (M / "lib/screens/shell/app_shell.dart").read_text(encoding="utf-8")
        cls.profile = (M / "lib/screens/profile/profile_screen.dart").read_text(encoding="utf-8")
        cls.main_activity = (
            M / "android/app/src/main/kotlin/com/arkeonethiopia/fkss/MainActivity.kt"
        ).read_text(encoding="utf-8")
        cls.manifest = (M / "android/app/src/main/AndroidManifest.xml").read_text(encoding="utf-8")
        cls.pubspec = (M / "pubspec.yaml").read_text(encoding="utf-8")
        cls.db = (M / "lib/services/local_db.dart").read_text(encoding="utf-8")
        cls.store = (M / "lib/services/hymn_store.dart").read_text(encoding="utf-8")
        cls.auth = (ROOT / "api/v1/core/auth.php").read_text(encoding="utf-8")

    # ── PIN handling: hashed, salted, secure-storage only ─────
    def test_pin_never_stored_plaintext(self):
        self.assertIn("sha256", self.lock_svc)
        self.assertIn("_newSalt()", self.lock_svc)
        self.assertIn("Random.secure()", self.lock_svc)
        self.assertIn("FlutterSecureStorage", self.lock_svc)
        self.assertNotIn("pin'", self.lock_svc.split("_kPinHash")[0])
        # the hash is written to secure storage, the pin itself is not
        self.assertIn("_secure.write(key: _kPinHash, value: _hash(clean, salt))",
                      self.lock_svc)

    def test_pin_rules_and_throttle(self):
        self.assertIn("at least 4 digits", self.lock_svc)
        self.assertIn("at most 8 digits", self.lock_svc)
        self.assertIn("_failedAttempts", self.lock_svc)
        self.assertIn("_throttleRemaining", self.lock_svc)
        # digits only — no exotic input through the keypad
        self.assertIn(r"RegExp(r'[^0-9]')", self.lock_svc)

    def test_biometrics_fail_closed(self):
        self.assertIn("biometricOnly: true", self.lock_svc)
        self.assertIn("canCheckBiometrics", self.lock_svc)
        self.assertIn("isDeviceSupported", self.lock_svc)
        self.assertIn("// Any platform hiccup falls back to the PIN — never an open door.",
                      self.lock_svc)
        # the catch block of authenticateWithBiometrics returns false
        body = self.lock_svc.split("authenticateWithBiometrics")[1]
        catch_block = body.split("catch (_)")[1]
        self.assertIn("return false;", catch_block)

    def test_lock_settings_include_autolock_ladder(self):
        for secs in ("0:", "60:", "300:", "3600:", "86400:"):
            self.assertIn(secs, self.lock_svc.split("autoLockOptions")[1].split("};")[0])

    # ── app-wide gate ─────────────────────────────────────────
    def test_gate_in_main_swaps_home(self):
        self.assertIn("LockScreen", self.main)
        self.assertIn("_appLock.isLocked && api.isLoggedIn", self.main)
        self.assertIn("lockAtColdStartIfConfigured", self.main)
        self.assertIn("_appLock.addListener(_onLockChanged)", self.main)

    def test_lock_screen_is_a_real_gate(self):
        self.assertIn("verifyPin", self.lock_screen)
        self.assertIn("authenticateWithBiometrics", self.lock_screen)
        self.assertIn("Wrong passcode", self.lock_screen)
        # no navigation trick can bypass: unlock only via service
        self.assertNotIn("popUntil", self.lock_screen)
        self.assertNotIn("Navigator.of(context).pop()", self.lock_screen)

    def test_lifecycle_autolock_hooks(self):
        self.assertIn("AppLockService().evaluateOnResume()", self.shell)
        self.assertIn("AppLockService().recordBackgrounded()", self.shell)

    # ── settings UI in Profile ────────────────────────────────
    def test_profile_exposes_lock_settings(self):
        for needle in (
            "App Lock",
            "Auto-lock",
            "Unlock with fingerprint",
            "Change passcode",
            "Lock now",
            "_setupPasscode",
            "_disablePasscode",
            "_pinDialog",
        ):
            self.assertIn(needle, self.profile)
        # disabling requires proving knowledge of the PIN
        self.assertIn("_appLock.disable(pin)", self.profile)

    # ── platform wiring for biometrics ────────────────────────
    def test_platform_wiring(self):
        self.assertIn("local_auth:", self.pubspec)
        self.assertIn("FlutterFragmentActivity", self.main_activity)
        self.assertNotIn("class MainActivity : FlutterActivity()", self.main_activity)
        self.assertIn("USE_BIOMETRIC", self.manifest)

    # ── long session: server-side rotation stays long ─────────
    def test_server_session_is_long_lived_and_rotating(self):
        # 15-minute access token + 90-day rotating refresh session —
        # the lock (not a forced logout) protects the long session.
        self.assertIn("API_TOKEN_EXPIRY', 900", self.auth)
        self.assertIn("86400 * 90", self.auth)

    # ── logout boundary: hymns stay, member data goes ─────────
    def test_logout_keeps_hymns_wipes_member_data(self):
        wipe = self.db.split("clearAllUserData")[1]
        for sensitive in ("'cached_members',", "'pending_attendance',",
                          "'pending_mezmur',", "'cached_mezmur_sheet',"):
            self.assertIn(sensitive, wipe)
        for kept in ("'cached_hymns',", "'pending_hymn_ops',", "'hymn_sync_meta',"):
            self.assertNotIn(kept, wipe)
        self.assertIn("if (!canEdit) return 0;", self.store)
        self.assertIn("hymn library changes are kept", self.profile)


if __name__ == "__main__":
    unittest.main()
