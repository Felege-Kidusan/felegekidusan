"""Regression checks for encrypted and safely migrated mobile offline data."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "Mobile/wbws_flutter_app"


class MobileLocalEncryptionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.security = (APP / "lib/services/local_database_security.dart").read_text(encoding="utf-8")
        cls.database = (APP / "lib/services/local_db.dart").read_text(encoding="utf-8")
        cls.api = (APP / "lib/services/api_service.dart").read_text(encoding="utf-8")
        cls.main = (APP / "lib/main.dart").read_text(encoding="utf-8")
        cls.pubspec = (APP / "pubspec.yaml").read_text(encoding="utf-8")
        cls.lock = (APP / "pubspec.lock").read_text(encoding="utf-8")
        cls.proguard = (APP / "android/app/proguard-rules.pro").read_text(encoding="utf-8")
        cls.manifest = (APP / "android/app/src/main/AndroidManifest.xml").read_text(encoding="utf-8")

    def test_sqlcipher_is_pinned_and_standard_sqflite_is_not_used(self):
        self.assertIn("sqflite_sqlcipher: 3.3.0", self.pubspec)
        self.assertNotIn("\n  sqflite:", self.pubspec)
        self.assertIn("name: sqflite_sqlcipher", self.lock)
        self.assertIn('version: "3.3.0"', self.lock)
        self.assertIn("package:sqflite_sqlcipher/sqflite.dart", self.database)
        self.assertNotIn("package:sqflite/sqflite.dart", self.database)

    def test_database_key_is_random_and_platform_protected(self):
        self.assertIn("Random.secure()", self.security)
        self.assertIn("List<int>.generate(32", self.security)
        self.assertIn("FlutterSecureStorage", self.security)
        self.assertIn("KeychainAccessibility.unlocked_this_device", self.security)
        self.assertIn("encryptedDataExists", self.security)
        self.assertIn("throw const OfflineDataSecurityException()", self.security)
        self.assertNotRegex(self.database, r"password:\s*['\"]")

    def test_plaintext_migration_exports_verifies_then_atomically_swaps(self):
        for requirement in (
            "SQLite format 3\\u0000",
            "ATTACH DATABASE ? AS encrypted KEY ?",
            "sqlcipher_export('encrypted')",
            "PRAGMA encrypted.integrity_check",
            "PRAGMA encrypted.application_id",
            ".plaintext-migration-backup",
            "await main.rename(backup.path)",
            "await temp.rename(main.path)",
            "await _verifyOrThrow(main.path, password)",
            "await _secureDelete(backup.path, required: true)",
        ):
            self.assertIn(requirement, self.security)
        self.assertNotIn("deleteDatabase(databasePath)", self.security)

    def test_encrypted_database_uses_secure_delete_and_schema_callbacks(self):
        self.assertIn("password: password", self.database)
        self.assertIn("onConfigure: security.configureEncryptedDatabase", self.database)
        self.assertIn("PRAGMA secure_delete = ON", self.security)
        self.assertIn("version: 8", self.database)
        self.assertIn("CREATE TABLE IF NOT EXISTS cached_grade_sheets", self.database)
        self.assertIn("PRAGMA wal_checkpoint(TRUNCATE)", self.database)
        self.assertIn("await db.transaction", self.database)

    def test_profile_metadata_is_migrated_out_of_plain_preferences(self):
        self.assertIn("legacyUserJson", self.api)
        self.assertIn("_secureStorage.write(\n          key: AppConfig.userDataKey", self.api)
        self.assertIn("prefs.remove(AppConfig.userDataKey)", self.api)
        self.assertNotIn("prefs.setString(AppConfig.userDataKey", self.api)
        self.assertIn("_secureStorage.delete(key: AppConfig.userDataKey)", self.api)
        self.assertIn("discardedInvalidSession", self.api)
        self.assertIn("await LocalDb().clearAllUserData()", self.main)

    def test_mobile_build_configuration_preserves_sqlcipher(self):
        self.assertIn("-keep class net.sqlcipher.**", self.proguard)
        self.assertIn('android:allowBackup="false"', self.manifest)
        self.assertIn('android:fullBackupContent="false"', self.manifest)

    def test_startup_fails_closed_without_resetting_offline_work(self):
        self.assertIn("OfflineDataProtectionFailureApp", self.main)
        self.assertIn("before reinstalling because reinstalling removes unsynced work", self.main)
        self.assertNotIn("deleteDatabase(", self.main)


if __name__ == "__main__":
    unittest.main()
