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
define('BACKUP_KEY',         'REPLACE_WITH_A_LONG_RANDOM_STRING_2'); // lets the cron job run backups
define('HEALTH_KEY',         'REPLACE_WITH_A_LONG_RANDOM_STRING_3'); // password for the health-check page
define('MONITOR_SECRET_KEY', 'REPLACE_WITH_A_LONG_RANDOM_STRING_4'); // password for the error-monitor dashboard (/monitor/)
