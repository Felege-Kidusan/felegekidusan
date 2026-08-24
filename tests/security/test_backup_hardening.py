"""Regression checks for privileged maintenance and backup handling."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class BackupHardeningTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = (ROOT / "admin/backend/services/BackupService.php").read_text(encoding="utf-8")
        cls.backup = (ROOT / "admin/tools/backup.php").read_text(encoding="utf-8")
        cls.download = (ROOT / "admin/tools/download_backup.php").read_text(encoding="utf-8")
        cls.health = (ROOT / "admin/tools/health_check.php").read_text(encoding="utf-8")
        cls.dashboard = (ROOT / "admin/dashboards/super-admin.php").read_text(encoding="utf-8")
        cls.cron = (ROOT / "admin/backend/cron_backup.php").read_text(encoding="utf-8")

    def test_backup_is_streamed_compressed_encrypted_and_atomic(self):
        for requirement in (
            "MYSQLI_USE_RESULT",
            "START TRANSACTION WITH CONSISTENT SNAPSHOT",
            "EncryptedBackupWriter",
            "deflate_init(ZLIB_ENCODING_GZIP",
            "sodium_crypto_secretstream_xchacha20poly1305_push",
            "@rename($partPath, $finalPath)",
            "flock($lock, LOCK_EX | LOCK_NB)",
            "@chmod($finalPath, 0600)",
        ):
            self.assertIn(requirement, self.service)
        self.assertNotIn("$sql .=", self.service)
        self.assertNotIn("fetch_all", self.service)

    def test_new_backups_are_outside_web_root_with_bounded_retention(self):
        self.assertIn("dirname($projectReal) . DIRECTORY_SEPARATOR . 'ssms_secure_backups'", self.service)
        self.assertIn("Backup storage must be outside the web root.", self.service)
        self.assertIn("private const KEEP_COUNT = 7", self.service)
        self.assertIn("array_slice($files, $keep)", self.service)
        self.assertIn("BACKUP_STORAGE_PATH", self.service)

    def test_backup_controller_requires_session_post_and_csrf(self):
        self.assertIn("($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'", self.backup)
        self.assertIn("($_SESSION['admin_role'] ?? '') !== 'super_admin'", self.backup)
        self.assertIn("validateCsrf($_POST['csrf_token'] ?? '')", self.backup)
        self.assertIn("session_write_close()", self.backup)
        self.assertNotIn("$_GET['key']", self.backup)
        self.assertNotIn("$_POST['key']", self.backup)

    def test_legacy_cron_is_cli_only_and_delegates(self):
        self.assertIn("PHP_SAPI !== 'cli'", self.cron)
        self.assertIn("../tools/backup.php", self.cron)
        self.assertNotIn("$_GET['key']", self.cron)
        self.assertNotIn("SELECT * FROM", self.cron)

    def test_dashboard_delegates_backup_domain_work(self):
        self.assertIn("BackupService::listBackups", self.dashboard)
        self.assertIn('action="/admin/tools/backup.php"', self.dashboard)
        self.assertNotIn("SHOW CREATE TABLE", self.dashboard)
        self.assertNotIn("$sql .=", self.dashboard)

    def test_download_is_allowlisted_role_restricted_and_no_store(self):
        self.assertIn("BackupService::resolveForDownload", self.download)
        self.assertIn("($_SESSION['admin_role'] ?? '') !== 'super_admin'", self.download)
        self.assertIn("Cache-Control: no-store", self.download)
        self.assertIn("Content-Security-Policy: default-src 'none'; sandbox", self.download)
        self.assertNotIn("readfile(", self.download)

    def test_health_credentials_are_not_accepted_in_urls(self):
        self.assertIn("HTTP_X_HEALTH_KEY", self.health)
        self.assertIn("PHP_AUTH_PW", self.health)
        self.assertIn("hash_equals($expectedKey, $providedKey)", self.health)
        self.assertNotIn("$_GET['key']", self.health)
        self.assertNotIn("$conn->connect_error ??", self.health)
        self.assertNotIn("$error->getMessage()", self.health)
        self.assertNotIn("@file(", self.health)
        self.assertIn("Cache-Control: no-store", self.health)

    def test_encrypted_backups_have_a_bounded_cli_restore_path(self):
        self.assertIn("BackupService::decryptToSql", self.backup)
        self.assertIn("EncryptedBackupReader::decrypt", self.service)
        self.assertIn("Backup authentication failed.", self.service)
        self.assertIn("file_exists($outputPath)", self.service)
        self.assertIn("unexpected trailing data", self.service)


if __name__ == "__main__":
    unittest.main()
