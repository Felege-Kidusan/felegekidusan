#!/usr/bin/env bash
# ============================================================
# One-time preparation for the communication E2E suite
# (tests/e2e/comm_lifecycle.php + tests/security/test_comm_e2e.py)
#
# Creates a DEDICATED test database + user, writes the repo-root
# .fkss_env.php the runner reads, and verifies the PHP CLI has
# mysqli. The runner DROPs and re-CREATEs the communication tables
# on every scenario run — NEVER point it at a production database.
#
# Usage:  bash tests/e2e/comm_e2e_setup.sh
# Then:   php tests/e2e/comm_lifecycle.php full
#         SSMS_E2E_PHP=/path/to/php python3 -m pytest tests/security/test_comm_e2e.py
# ============================================================
set -euo pipefail
cd "$(dirname "$0")/../.."

DB_NAME="${SSMS_E2E_DB:-ssms_e2e}"
DB_USER="${SSMS_E2E_USER:-ssms_e2e}"
DB_PASS="${SSMS_E2E_PASS:-$(head -c 18 /dev/urandom | base64 | tr -d '/+=')}"
DB_HOST="${SSMS_E2E_HOST:-127.0.0.1}"

echo "==> Creating database ${DB_NAME} and user ${DB_USER}@localhost/127.0.0.1"
echo "    (run this as a MySQL/MariaDB root-capable user; adjust auth as needed)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "==> Writing .fkss_env.php (gitignored — local test credentials only)"
cat > .fkss_env.php <<PHP
<?php
define('DB_HOST', '${DB_HOST}');
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');
define('JWT_SECRET', 'e2e-jwt-secret-for-tests-only');
define('BACKUP_KEY', 'e2e-backup-key-for-tests-only');
define('HEALTH_KEY', 'e2e-health-key-for-tests-only');
define('MONITOR_SECRET_KEY', 'e2e-monitor-key-for-tests-only');
PHP

PHP_BIN="${SSMS_E2E_PHP:-php}"
echo "==> Checking ${PHP_BIN} for the mysqli extension"
"$PHP_BIN" -m | grep -qi mysqli || {
  echo "ERROR: ${PHP_BIN} has no mysqli — install php-mysql or point SSMS_E2E_PHP at a PHP with mysqli." >&2
  exit 1
}

echo "==> Smoke: scenario 'full' (drops/recreates comm tables in ${DB_NAME})"
"$PHP_BIN" tests/e2e/comm_lifecycle.php full

echo
echo "Done. Run the wrapper any time with:"
echo "  SSMS_E2E_PHP=$(command -v "$PHP_BIN") python3 -m pytest tests/security/test_comm_e2e.py -v"
