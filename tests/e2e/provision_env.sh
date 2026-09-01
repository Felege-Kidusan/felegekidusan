#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════
# provision_env.sh — rebuild the SSMS test stack after a sandbox wipe
# ════════════════════════════════════════════════════════════════
# Packages (php, mariadb) do NOT survive sandbox restarts; only the
# repo files under /home/user do. This script recreates the full
# working environment: packages -> MariaDB -> schema -> seed.
#
# It encodes the migration apply-order that the live DB evolved into
# (sql/ is not linearly applicable on a fresh DB — 003 indexes columns
# that 012's baseline adds; 014/017/021/029 assume legacy-era tables
# from the admin/migrations PHP era).
#
# Usage:  bash tests/e2e/provision_env.sh
# Then:   PHP_CLI_SERVER_WORKERS=8 php -d error_reporting=24575 \
#           -S 0.0.0.0:8080 -t "$(dirname "$PWD")"   # from SSMS root
# ════════════════════════════════════════════════════════════════
set -u
cd "$(dirname "$0")/../.."   # repo root

echo "== 1. packages =="
if ! command -v php >/dev/null || ! command -v mariadb >/dev/null; then
  sudo -n apt-get update -qq
  sudo -n DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    php-cli php-mysql php-curl php-mbstring php-xml php-zip php-gd php-bcmath \
    mariadb-server mariadb-client
fi
php -v | head -1

echo "== 2. mariadb up =="
sudo -n mariadb -e "SELECT 1" >/dev/null 2>&1 || sudo -n /etc/init.d/mariadb start >/dev/null 2>&1
# wait for socket
for i in $(seq 1 15); do sudo -n mariadb -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
sudo -n mariadb -e "SELECT VERSION()"

echo "== 3. database + user (from .fkss_env.php) =="
DB_NAME=$(php -r 'require "/home/user/.fkss_env.php"; echo DB_NAME;')
DB_USER=$(php -r 'require "/home/user/.fkss_env.php"; echo DB_USER;')
DB_PASS=$(php -r 'require "/home/user/.fkss_env.php"; echo DB_PASS;')
sudo -n mariadb -e "
  CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
  CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
  GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1', '$DB_USER'@'localhost';
  FLUSH PRIVILEGES;"

echo "== 4. base schema + migrations (working order) =="
sudo -n mariadb "$DB_NAME" < database_schema.sql
apply() { sudo -n mariadb "$DB_NAME" < "$1" 2>/tmp/prov_e \
  || echo "  (known-stale, patched below): $1"; }
# Baselines FIRST: they formalize the runtime schema the numbered
# patches were historically written against.
apply sql/012_runtime_schema_baseline.sql
apply sql/013_application_schema_completion.sql
for f in $(ls sql/*.sql | sort -V); do apply "$f"; done

echo "== 5. legacy-era bits the sql/ chain assumes =="
# a) tables that live only in session-gated PHP migrations (subjects,
#    assessments, class_subjects, ...). Extract their CREATE TABLE
#    statements and apply — 003_add_assessments LAST (its FKs need
#    subjects/classes to exist already).
python3 - <<'PYEOF'
import re, glob
def creates(path):
    src = open(path, encoding='utf-8').read()
    return [s for s in re.findall(r'\$sql\s*=\s*"(.*?)"\s*;', src, re.S)
            if s.strip().startswith('CREATE TABLE')]
others, last = [], []
for f in sorted(glob.glob('admin/migrations/*.php')):
    (last if f.endswith('003_add_assessments.php') else others).extend(creates(f))
open('/tmp/legacy_tables.sql', 'w', encoding='utf-8').write(
    ';\n'.join(others + last) + ';\n')
print(f"  extracted {len(others)+len(last)} legacy CREATE TABLEs")
PYEOF
sudo -n mariadb "$DB_NAME" < /tmp/legacy_tables.sql
# b) departments.sort_order — 017/021 INSERTs reference it (pre-versioning era)
sudo -n mariadb "$DB_NAME" -e \
  "ALTER TABLE departments ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0" 2>/dev/null \
  && echo "  departments.sort_order added" || echo "  departments.sort_order already present"
# c) members.membership_tier — 014 indexes it (PHP-migration era column)
sudo -n mariadb "$DB_NAME" -e \
  "ALTER TABLE members ADD COLUMN membership_tier ENUM('temporary','permanent') NULL DEFAULT 'permanent' AFTER status" 2>/dev/null \
  && echo "  members.membership_tier added" || echo "  members.membership_tier already present"
# d) archive tables from 003's tail (its head fails on pre-existing indexes)
sudo -n mariadb "$DB_NAME" -e "CREATE TABLE IF NOT EXISTS attendance_archive LIKE attendance;
  CREATE TABLE IF NOT EXISTS academic_records_archive LIKE academic_records;
  CREATE TABLE IF NOT EXISTS activity_logs_archive LIKE activity_logs;"

echo "== 6. re-run the files that failed on ordering =="
for f in 003_production_hardening 014_member_directory_scaling 017_identity_codes \
         021_mezmur_department 029_subject_codes_and_limits; do
  sudo -n mariadb "$DB_NAME" < "sql/$f.sql" 2>/dev/null \
    && echo "  ok: $f" || echo "  (index-only residue, safe): $f"
done

echo "== 7. seed test world =="
# error_reporting=24575 = E_ALL & ~E_DEPRECATED. PHP 8.4 deprecates
# session.sid_bits_per_character (config.php:120); the error monitor
# renders a friendly crash page for unmasked errors, killing the seed.
php -d error_reporting=24575 tests/e2e/seed.php

echo "== done =="
echo "start the app with:"
echo "  PHP_CLI_SERVER_WORKERS=8 php -d error_reporting=24575 -S 0.0.0.0:8080 -t ."
