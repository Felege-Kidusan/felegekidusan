"""Regression checks for the mobile local-storage posture.

The offline database is a sandboxed cache of server data protected by the OS
(device file-based encryption + app sandbox), the mainstream pattern used by
large consumer apps. Tokens and the staff profile stay in platform secure
storage. Startup must degrade gracefully instead of dead-ending the app, and
an interrupted 1.1.15 SQLCipher upgrade must self-heal without losing data.
"""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "Mobile/wbws_flutter_app"


class MobileLocalStorageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.database = (APP / "lib/services/local_db.dart").read_text(encoding="utf-8")
        cls.api = (APP / "lib/services/api_service.dart").read_text(encoding="utf-8")
        cls.main = (APP / "lib/main.dart").read_text(encoding="utf-8")
        cls.pubspec = (APP / "pubspec.yaml").read_text(encoding="utf-8")
        cls.lock = (APP / "pubspec.lock").read_text(encoding="utf-8")
        cls.proguard = (APP / "android/app/proguard-rules.pro").read_text(encoding="utf-8")
        cls.manifest = (APP / "android/app/src/main/AndroidManifest.xml").read_text(encoding="utf-8")
        cls.encryption_module_removed = not (
            APP / "lib/services/local_database_security.dart"
        ).exists()

    def test_local_db_uses_sandboxed_plain_sqflite(self):
        self.assertIn("sqflite: ^2.3.0", self.pubspec)
        self.assertNotIn("sqflite_sqlcipher", self.pubspec)
        self.assertNotIn("name: sqflite_sqlcipher", self.lock)
        self.assertIn("name: sqflite", self.lock)
        self.assertIn("package:sqflite/sqflite.dart", self.database)
        self.assertNotIn("package:sqflite_sqlcipher", self.database)
        self.assertNotIn("local_database_security", self.database)
        self.assertNotIn("LocalDatabaseKeyStore", self.database)
        self.assertNotIn("EncryptedDatabaseMigrator", self.database)
        self.assertNotRegex(self.database, r"password:\s*")
        self.assertIn("version: 9", self.database)
        # Set-form PRAGMAs must use rawQuery on Android; db.execute() throws
        # and crashed every database open in 1.1.15.
        self.assertIn("PRAGMA secure_delete = ON", self.database)
        self.assertIn("rawQuery('PRAGMA secure_delete = ON')", self.database)
        self.assertNotIn("db.execute('PRAGMA secure_delete", self.database)
        self.assertIn("CREATE TABLE IF NOT EXISTS cached_grade_sheets", self.database)
        self.assertIn("await db.transaction", self.database)

    def test_app_level_encryption_module_is_removed(self):
        self.assertTrue(self.encryption_module_removed)
        self.assertNotIn("net.sqlcipher", self.proguard)
        self.assertNotIn("sqflite_sqlcipher", self.proguard)
        self.assertIn("io.flutter.plugins.sqflite", self.proguard)

    def test_interrupted_encryption_upgrade_self_heals(self):
        self.assertIn("_recoverFromInterruptedEncryptionUpgrade", self.database)
        self.assertIn(".plaintext-migration-backup", self.database)
        self.assertIn(".encrypted-migration", self.database)
        self.assertIn("await backup.rename(path)", self.database)

    def test_tokens_and_profile_stay_in_platform_secure_storage(self):
        self.assertIn("flutter_secure_storage", self.pubspec)
        self.assertIn("FlutterSecureStorage", self.api)
        self.assertIn("legacyUserJson", self.api)
        self.assertIn("prefs.remove(AppConfig.userDataKey)", self.api)
        self.assertNotIn("prefs.setString(AppConfig.userDataKey", self.api)
        self.assertIn("discardedInvalidSession", self.api)
        self.assertIn("await LocalDb().clearAllUserData()", self.main)

    def test_bootstrap_degrades_gracefully_with_diagnostics(self):
        # Secure-storage reads must never throw out of init().
        self.assertIn("best-effort at bootstrap", self.api)
        # legacyUserJson must be declared OUTSIDE the try block: the
        # session-discard check below it references it (Dart scope).
        self.assertIn("String? legacyUserJson;", self.api)
        self.assertNotIn("final legacyUserJson = prefs.getString", self.api)
        # The failure screen keeps a retry path and records the real error.
        self.assertIn("OfflineDataProtectionFailureApp", self.main)
        self.assertIn("runBootstrap", self.main)
        self.assertIn("onPressed", self.main)
        self.assertIn("fkss_bootstrap_error.log", self.main)
        self.assertNotIn("deleteDatabase(", self.main)

    def test_app_data_backup_stays_disabled(self):
        self.assertIn('android:allowBackup="false"', self.manifest)
        self.assertIn('android:fullBackupContent="false"', self.manifest)


if __name__ == "__main__":
    unittest.main()
