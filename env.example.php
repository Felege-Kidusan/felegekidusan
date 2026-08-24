<?php
/**
 * ============================================================
 * SECRETS TEMPLATE  —  env.example.php
 * ============================================================
 * This is a TEMPLATE. Do the following once, on the live server:
 *
 *   1. Copy this file to your account home folder, ONE LEVEL ABOVE
 *      public_html, and rename it to:   .fkss_env.php
 *      (Example full path:  /home/arkeonet/.fkss_env.php)
 *
 *   2. Fill in the real values below.
 *
 *   3. Set its permission to 0600 (owner read/write only):
 *          chmod 600 /home/arkeonet/.fkss_env.php
 *
 *   4. Reload the website. The "Setup required" message disappears.
 *
 * WHY ABOVE public_html:  files above the web root can never be
 * downloaded through a browser, so the database password stays secret
 * even if a web-server misconfiguration exposes the site folder.
 *
 * NEVER commit the real .fkss_env.php to git. (It is already listed in
 * .gitignore.) This example file is safe because it contains no real
 * secrets.
 * ============================================================
 */

// ---- Database (get these from cPanel → MySQL Databases) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'REPLACE_WITH_YOUR_DB_NAME');
define('DB_USER', 'REPLACE_WITH_YOUR_DB_USER');
define('DB_PASS', 'REPLACE_WITH_YOUR_DB_PASSWORD');

// ---- Security keys ----
// Generate long random strings. On the server you can run:
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// Paste a DIFFERENT value into each of the three below. Keep them stable
// (do not change them after go-live, or existing logins/tokens break).
define('JWT_SECRET',         'REPLACE_WITH_A_LONG_RANDOM_STRING_1'); // mobile app login tokens
define('BACKUP_KEY',         'REPLACE_WITH_A_LONG_RANDOM_STRING_2'); // encrypts backups; required for restore
define('HEALTH_KEY',         'REPLACE_WITH_A_LONG_RANDOM_STRING_3'); // HTTP Basic/X-Health-Key health credential
define('MONITOR_SECRET_KEY', 'REPLACE_WITH_A_LONG_RANDOM_STRING_4'); // password for the error-monitor dashboard (/monitor/)

// ---- Encrypted database backup storage (OPTIONAL) ----
// Defaults to `ssms_secure_backups` beside (not inside) public_html. The path
// must be absolute, outside the web root, and writable by the PHP/cron account.
// BACKUP_KEY above encrypts every new compressed backup. A separate stable key
// may be used if desired; preserve it securely or the backups cannot be restored.
// define('BACKUP_STORAGE_PATH', '/home/YOUR_ACCOUNT/ssms_secure_backups');
// define('BACKUP_ENCRYPTION_KEY', 'REPLACE_WITH_A_LONG_RANDOM_STRING_5');

// ---- Private member document storage (OPTIONAL) ----
// Defaults to a `ssms_private` directory beside (not inside) public_html.
// Set this only when your hosting layout needs a different non-web-accessible path.
// The PHP/web-server account must be able to create/write this directory.
// define('MEMBER_PRIVATE_STORAGE_PATH', '/home/YOUR_ACCOUNT/ssms_private');

// ---- AI provider key encryption (OPTIONAL but recommended) ----
// AI API keys (Gemini/Groq/OpenAI/…) are stored ENCRYPTED in the database.
// If you set a dedicated secret here, it is used to encrypt them; otherwise the
// system derives one from DB_PASS + JWT_SECRET above (which already live in this
// file, above the web root). Set a stable value and do NOT change it after
// saving keys, or the saved AI keys can no longer be decrypted (just re-enter
// them). Generate with:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// define('AI_ENC_KEY', 'REPLACE_WITH_A_LONG_RANDOM_STRING_5');

// ---- Deployment feature capabilities (OPTIONAL) ----
// Omit these to keep every current module enabled. Use real booleans only —
// quoted strings such as 'false' deliberately fail closed. Disabled modules are
// blocked by browser and REST routes; clients also hide their controls.
// define('FEATURE_AI_CHATBOT', false);
// define('FEATURE_GROUPS', false);
// define('FEATURE_FINANCE', false);
// define('FEATURE_MATERIAL', false);
// define('FEATURE_ID_CARDS', false);
// define('FEATURE_ATTENDANCE', false);
// define('FEATURE_GRADES', false);
// define('FEATURE_REPORTS', false);
// define('FEATURE_EXPORT_PDF', false);
// define('FEATURE_MONITOR', false);

// Test-data loading/deletion is disabled by default. Enable only in a controlled
// staging environment, never on a live member database.
// define('ENABLE_TEST_DATA_TOOLS', true);

// ---- Phone app updates (OPTIONAL) ----
// Do NOT put the APK in this file. See Mobile/HOW_TO_SHIP_AN_UPDATE.md
// and api/v1/app_release.example.php  →  /home/USER/.fkss_app_release.php
