#!/usr/bin/env bash
# ============================================================
# SSMS regression smoke — run after EVERY patch.
# Covers: login (all roles), page render integrity, encoding,
# education APIs, and the standing security probes.
# Exit code 0 = all pass. Any FAIL line = regression.
# ============================================================
cd "$(dirname "$0")/../.." || exit 1
PASS_CNT=0; FAIL_CNT=0
ok()   { PASS_CNT=$((PASS_CNT+1)); echo "  PASS: $1"; }
fail() { FAIL_CNT=$((FAIL_CNT+1)); echo "  FAIL: $1"; }

source tests/e2e/client.sh

# --- 1. Encoding integrity (Patch 1) ---------------------------------------
ssms_login audit_edu > /dev/null 2>&1 || { fail "edu login"; }
HTML=$(ssms_get /admin/dashboards/edu_dept.php)
[ "$(echo "$HTML" | grep -c 'á‹¨á‰µ\|â€”\|áˆáŒ\|ðŸ')" = "0" ] && ok "edu_dept.php: no mojibake" || fail "edu_dept.php still has mojibake"
echo "$HTML" | grep -q 'የትምህርት ክፍል' && ok "edu_dept.php: sidebar Amharic" || fail "sidebar Amharic missing"
echo "$HTML" | grep -q 'ፈለገ ቅዱሳን ሰንበት ትምህርት ቤት' && ok "edu_dept.php: report banner" || fail "report banner wrong"
echo "$HTML" | grep -q '<title>Education Department — FKSS</title>' && ok "edu_dept.php: title em-dash" || fail "title wrong"

# --- 2. All roles can log in -----------------------------------------------
for U in audit_super audit_teach audit_fin; do
  CODE=$(ssms_login "$U" | awk '{print $1}')
  [ "$CODE" = "302" ] && ok "login $U" || fail "login $U ($CODE)"
done

# --- 3. Education APIs still answer ----------------------------------------
ssms_login audit_edu > /dev/null 2>&1
D=$(ssms_get "/admin/api_subjects.php?action=get_class_subjects&class_id=$(sudo -n mariadb --default-character-set=utf8mb4 ssms -N -e "SELECT id FROM classes WHERE class_code='grade_1'")")
echo "$D" | python3 -c 'import sys,json;d=json.load(sys.stdin);exit(0 if d["status"]=="success" and len(d["subjects"])==2 else 1)' \
  && ok "get_class_subjects (grade_1 = 2 subjects)" || fail "get_class_subjects: $D"
D=$(ssms_get "/admin/api_education.php?action=enrollment_overview")
echo "$D" | python3 -c 'import sys,json;d=json.load(sys.stdin);exit(0 if d["status"]=="success" else 1)' \
  && ok "enrollment_overview" || fail "enrollment_overview"

# --- 3b. Web grade save/re-save (Patch C1) ---------------------------------
php tests/e2e/seed.php 2>&1 >/dev/null | tail -1 > /tmp/seed.json
AID=$(python3 -c 'import json;print(json.load(open("/tmp/seed.json"))["assessment_id"])')
ssms_login audit_edu > /dev/null 2>&1
R1=$(ssms_post /admin/api_subjects.php action=save_grades assessment_id=$AID "grades=[{\"member_id\":900000,\"score\":62,\"remark\":\"s1\"}]")
R2=$(ssms_post /admin/api_subjects.php action=save_grades assessment_id=$AID "grades=[{\"member_id\":900000,\"score\":71,\"remark\":\"s2\"}]")
echo "$R1" | grep -q '"saved":1' && ok "grade save #1" || fail "grade save #1: $R1"
echo "$R2" | grep -q '"saved":1' && ok "grade re-save (was C1 bug)" || fail "grade re-save: $R2"
DUPS=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM (SELECT member_id FROM academic_records WHERE assessment_id=$AID GROUP BY member_id HAVING COUNT(*)>1) t")
[ "$DUPS" = "0" ] && ok "no duplicate grade rows" || fail "duplicate rows: $DUPS"
FINAL=$(sudo -n mariadb ssms -N -e "SELECT score FROM academic_records WHERE assessment_id=$AID AND member_id=900000")
[ "$FINAL" = "71.00" ] && ok "final score is last saved value" || fail "final score: $FINAL"

# --- 4. Access control (finance must stay blocked from edu APIs) ------------
ssms_login audit_fin > /dev/null 2>&1
D=$(ssms_get "/admin/api_subjects.php?action=get_subjects")
echo "$D" | grep -q "permission" && ok "finance blocked from api_subjects" || fail "finance reached api_subjects!"

# --- 5. Public pages still up ----------------------------------------------
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/index.php")
[ "$C" = "200" ] && ok "public index 200" || fail "public index=$C"

echo
echo "SMOKE RESULT: $PASS_CNT passed, $FAIL_CNT failed"
[ "$FAIL_CNT" = "0" ]
