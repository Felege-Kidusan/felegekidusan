#!/usr/bin/env bash
# =============================================================================
# restore_production_dump.sh — deterministic restore of a phpMyAdmin export
# =============================================================================
# Audit finding R, 2026-10-02.
#
# THE PROBLEM
# -----------
# The production export is a phpMyAdmin dump that contains NO
# "SET FOREIGN_KEY_CHECKS=0" preamble (mysqldump emits one; phpMyAdmin only
# does so when the "disable foreign key checks" export option is ticked, and
# it was not). The dump declares 42 foreign keys and appends them as ALTER
# TABLE statements at the end, after all rows are loaded.
#
# Three of those 42 are violated by data that is actually in the dump:
#
#   fk_mhc_hymn      mezmur_hymn_categories.hymn_id     ->  mezmur_hymns.id
#   fk_mhc_category  mezmur_hymn_categories.category_id ->  mezmur_categories.id
#   fk_mhz_hymn      mezmur_hymn_zemarians.hymn_id      ->  mezmur_hymns.id
#
# A plain `mariadb db < dump.sql` therefore aborts at the first of them and
# exits 1, having created 87 tables but only 31 of 42 constraints. Eight of
# the eleven missing constraints are NOT broken -- they are collateral: the
# client stopped and simply never ran their ALTER statements. Two more are
# lost because the dump groups two constraints into a single ALTER, so a
# valid constraint dies alongside its violated neighbour.
#
# WHAT THIS SCRIPT DOES
# ---------------------
# Loads schema and data with constraint creation deferred, then applies every
# declared constraint ONE AT A TIME so that one bad constraint cannot take
# healthy ones down with it. Result: 87 tables and 39 of 42 foreign keys,
# with no row inserted, altered or deleted. Every constraint it creates is
# fully validated against the data.
#
# WHAT IT DELIBERATELY DOES NOT DO
# --------------------------------
# It does not use SET FOREIGN_KEY_CHECKS=0 to force all 42 into place. That
# makes the restore *complete* while leaving the three constraints
# UNVALIDATED: MariaDB records them but never checks the existing rows, so
# the database silently carries 101 link rows that violate a constraint the
# application believes is enforced. A later UPDATE touching one of those rows
# fails with errno 1452 in production, far from the restore that caused it.
# A clean 39 is more honest than a dirty 42.
#
# It does not delete the orphaned rows. Reaching 42/42 requires removing 101
# rows from mezmur_hymn_categories and 1 from mezmur_hymn_zemarians. Those
# are retired references left behind by the taxonomy re-seed in sql/030 and
# sql/034 -- but discarding user-owned records is an owner's decision, not a
# restore script's. See docs/audits for the evidence and the open question.
#
# USAGE
#   scripts/restore_production_dump.sh <database> <dump.sql> [mysql args...]
#
# EXIT CODES
#   0  87 tables and 39/42 constraints, exactly as expected
#   1  usage / connection error
#   2  the restore did not reach the expected inventory -- investigate
# =============================================================================
set -uo pipefail

DB="${1:-}"
DUMP="${2:-}"
shift 2 2>/dev/null || true
MYSQL=(mariadb "$@")

if [[ -z "$DB" || -z "$DUMP" ]]; then
  sed -n '/^# USAGE/,/^# ====/p' "$0" >&2
  exit 1
fi
[[ -r "$DUMP" ]] || { echo "error: cannot read dump '$DUMP'" >&2; exit 1; }

# The expected inventory. If the dump changes, these change with it, and a
# mismatch is a signal rather than something to paper over.
EXPECTED_TABLES=87
EXPECTED_FKS=39
KNOWN_VIOLATED=("fk_mhc_hymn" "fk_mhc_category" "fk_mhz_hymn")

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

echo "==> 1/4  extracting declared foreign keys"
python3 - "$DUMP" "$work" <<'PY'
import re, sys
dump, work = sys.argv[1], sys.argv[2]
src = open(dump, encoding='utf-8', errors='replace').read()

fks = []
def strip(m):
    table, body = m.group(1), m.group(2)
    for c in re.finditer(
        r'ADD CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY\s*\(([^)]*)\)\s*'
        r'REFERENCES\s+(`?\w+`?)\s*\(([^)]*)\)'
        r'((?:\s+ON (?:DELETE|UPDATE) (?:CASCADE|SET NULL|RESTRICT|NO ACTION))*)',
        body):
        fks.append((table,) + c.groups())
    # Remove only the FK clauses; a table may also be gaining an index here.
    rest = re.sub(
        r',?\s*ADD CONSTRAINT\s+`?\w+`?\s+FOREIGN KEY\s*\([^)]*\)\s*'
        r'REFERENCES\s+`?\w+`?\s*\([^)]*\)'
        r'((?:\s+ON (?:DELETE|UPDATE) (?:CASCADE|SET NULL|RESTRICT|NO ACTION))*)',
        '', body)
    return '' if not rest.strip() else 'ALTER TABLE %s%s;' % (table, rest)

base = re.sub(r'ALTER TABLE\s+(`?\w+`?)(.*?);', strip, src, flags=re.S)
open('%s/base.sql' % work, 'w').write(base)
with open('%s/fks.tsv' % work, 'w') as fh:
    for t, name, col, ref, refcol, act in fks:
        fh.write('\t'.join([t, name, col, ref, refcol, act.strip()]) + '\n')
print("    %d constraints deferred" % len(fks))
PY
[[ -s "$work/fks.tsv" ]] || { echo "error: no constraints parsed" >&2; exit 2; }

echo "==> 2/4  loading schema and data (constraints deferred)"
if ! "${MYSQL[@]}" "$DB" < "$work/base.sql" 2> "$work/base.err"; then
  echo "error: base load failed:" >&2; head -5 "$work/base.err" >&2; exit 2
fi
if grep -q '^ERROR' "$work/base.err"; then
  echo "error: base load reported errors:" >&2; grep '^ERROR' "$work/base.err" | head -5 >&2; exit 2
fi

echo "==> 3/4  applying each constraint individually"
applied=0; rejected=0; rejected_names=()
while IFS=$'\t' read -r table name col ref refcol actions; do
  [[ -z "${name:-}" ]] && continue
  if "${MYSQL[@]}" "$DB" -e \
       "ALTER TABLE ${table} ADD CONSTRAINT \`${name}\` FOREIGN KEY (${col}) REFERENCES ${ref} (${refcol}) ${actions};" \
       2> "$work/fk.err"; then
    applied=$((applied + 1))
  else
    rejected=$((rejected + 1)); rejected_names+=("$name")
    # mariadb prefixes the statement echo with a rule of dashes; take the
    # ERROR line itself, not whatever happens to be first.
    echo "    REJECTED ${name}: $(grep -m1 -E '^ERROR|ERROR [0-9]+' "$work/fk.err" | cut -c1-120)"
  fi
done < "$work/fks.tsv"
echo "    applied=${applied} rejected=${rejected}"

echo "==> 4/4  verifying the inventory"
tables=$("${MYSQL[@]}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB}';")
fks=$("${MYSQL[@]}" -N -e \
  "SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema='${DB}' AND constraint_type='FOREIGN KEY';")
echo "    tables=${tables}/${EXPECTED_TABLES}  foreign keys=${fks}/${EXPECTED_FKS}"

status=0
[[ "$tables" == "$EXPECTED_TABLES" ]] || { echo "    MISMATCH: table count"; status=2; }
[[ "$fks"    == "$EXPECTED_FKS"    ]] || { echo "    MISMATCH: constraint count"; status=2; }

# The three rejections are known and expected. A DIFFERENT rejection means
# the data changed shape and needs a human, so do not pass silently.
for n in "${rejected_names[@]:-}"; do
  [[ -z "$n" ]] && continue
  found=0
  for k in "${KNOWN_VIOLATED[@]}"; do [[ "$n" == "$k" ]] && found=1; done
  if [[ "$found" -eq 0 ]]; then
    echo "    UNEXPECTED rejection: ${n} — this is new; investigate before deploying"
    status=2
  fi
done

if [[ "$status" -eq 0 ]]; then
  cat <<EOF

RESTORE STATUS: PASS (expected inventory)
  87 tables, 39/42 foreign keys, no data modified.
  3 constraints are absent because production data violates them:
    fk_mhc_hymn, fk_mhc_category, fk_mhz_hymn
  Reaching 42/42 requires deleting 102 orphaned link rows, which is an
  owner decision and is NOT performed here.
EOF
else
  echo
  echo "RESTORE STATUS: FAIL — inventory did not match; see above."
fi
exit "$status"
