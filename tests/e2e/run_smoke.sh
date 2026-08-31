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

# --- 3c. Submission workflow lock (Patch C2/H8) ----------------------------
sudo -n mariadb ssms -e "DELETE FROM academic_records WHERE assessment_id=$AID; DELETE FROM grade_submissions WHERE assessment_id=$AID" >/dev/null 2>&1
ssms_login audit_teach > /dev/null 2>&1
T=$(ssms_post /admin/api_subjects.php action=save_grades assessment_id=$AID "grades=[{\"member_id\":900000,\"score\":50}]")
echo "$T" | grep -q '"saved":1' && ok "teacher web save -> draft packet" || fail "teacher web save: $T"
PKT=$(sudo -n mariadb ssms -N -e "SELECT CONCAT(teacher_id,':',status) FROM grade_submissions WHERE assessment_id=$AID")
[ "$PKT" = "3:draft" ] && ok "draft packet owned by teacher" || fail "packet: $PKT"
ssms_api_login audit_teach > /dev/null 2>&1
ssms_api POST grades/submit "{\"assessment_id\":$AID,\"grades\":[{\"member_id\":900000,\"score\":50}]}" | grep -q '"submission_status":"submitted"' && ok "mobile submit" || fail "mobile submit failed"
LOCK=$(curl -s -o /tmp/lock_body -w '%{http_code}' -b "$JAR" -X POST "$BASE/admin/api_subjects.php" -d "action=save_grades" -d "assessment_id=$AID" -d "csrf_token=$(ssms_csrf)" --data-urlencode 'grades=[{"member_id":900000,"score":99}]')
[ "$LOCK" = "409" ] && ok "teacher locked after submit (409)" || fail "expected 409, got $LOCK: $(cat /tmp/lock_body)"
ssms_login audit_edu > /dev/null 2>&1
E=$(ssms_post /admin/api_subjects.php action=save_grades assessment_id=$AID "grades=[{\"member_id\":900000,\"score\":71}]")
echo "$E" | grep -q '"saved":1' && ok "edu override still allowed" || fail "edu override: $E"
LOCK2=$(sudo -n mariadb ssms -N -e "SELECT CONCAT(teacher_id,':',status) FROM grade_submissions WHERE assessment_id=$AID")
[ "$LOCK2" = "3:submitted" ] && ok "edu correction keeps lock + ownership" || fail "packet after edu save: $LOCK2"

# --- 4. Access control (finance must stay blocked from edu APIs) ------------
ssms_login audit_fin > /dev/null 2>&1
D=$(ssms_get "/admin/api_subjects.php?action=get_subjects")
if echo "$D" | grep -q '"status":"success"\|"subjects"'; then
  fail "finance reached api_subjects!"
else
  ok "finance blocked from api_subjects"
fi

# --- 5. Public pages still up ----------------------------------------------
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/index.php")
[ "$C" = "200" ] && ok "public index 200" || fail "public index=$C"

echo
echo "SMOKE RESULT: $PASS_CNT passed, $FAIL_CNT failed"
[ "$FAIL_CNT" = "0" ]
