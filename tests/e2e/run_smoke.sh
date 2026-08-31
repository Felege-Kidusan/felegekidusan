#!/usr/bin/env bash
# ============================================================
# SSMS regression smoke — run after EVERY patch.
# Covers: login (all roles), page render integrity, encoding,
# education APIs, and the standing security probes.
# Exit code 0 = all pass. Any FAIL line = regression.
# NOTE: logins are rate-limited (20 / 5 min / IP). Do NOT run manual
# login probes right before this suite or sessions will 401.
# ============================================================
cd "$(dirname "$0")/../.." || exit 1
PASS_CNT=0; FAIL_CNT=0
ok()   { PASS_CNT=$((PASS_CNT+1)); echo "  PASS: $1"; }
fail() { FAIL_CNT=$((FAIL_CNT+1)); echo "  FAIL: $1"; }

# Deterministic runs: clear login rate-limit buckets (this suite logs in ~8x
# and the limiter allows 20 / 5 min / IP — see SecurityRateLimiter).
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1 || true

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

# --- 3b2. Teacher assignment scoping (Patch C3) ----------------------------
# (edu session is still open here — create an assessment the teacher is NOT
#  assigned to: grade_2 + history)
G2=$(sudo -n mariadb ssms -N -e "SELECT id FROM classes WHERE class_code='grade_2'")
ssms_post /admin/api_subjects.php action=create_assessment class_id=$G2 subject_id=3 assessment_name="C3 Probe" assessment_type=test max_score=100 weight_percentage=10 > /dev/null
OUTA=$(sudo -n mariadb ssms -N -e "SELECT a.id FROM assessments a WHERE a.class_id=$G2 AND a.subject_id=3 ORDER BY a.id DESC LIMIT 1")

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

# --- 3d. Teacher blocked outside their assignments (Patch C3) --------------
ssms_login audit_teach > /dev/null 2>&1
SCOPE=$(ssms_get "/admin/api_subjects.php?action=get_assessments&class_id=$G2")
echo "$SCOPE" | grep -q '"assessments":\[\]' && ok "teacher sees no unassigned assessments" || fail "scope leak: $SCOPE"
CS=$(ssms_get "/admin/api_subjects.php?action=get_class_subjects&class_id=$G2")
echo "$CS" | grep -q '"subjects":\[\]' && ok "teacher sees no unassigned subjects" || fail "subject leak: $CS"
B403=$(curl -s -o /tmp/c3_body -w '%{http_code}' -b "$JAR" -X POST "$BASE/admin/api_subjects.php" -d "action=save_grades" -d "assessment_id=$OUTA" -d "csrf_token=$(ssms_csrf)" --data-urlencode 'grades=[{"member_id":900000,"score":44}]')
[ "$B403" = "403" ] && ok "teacher cannot write unassigned assessment (403)" || fail "expected 403, got $B403: $(cat /tmp/c3_body)"
GS=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/admin/api_subjects.php?action=get_grade_summary&class_id=$G2")
[ "$GS" = "403" ] && ok "teacher bulk grade summary blocked (403)" || fail "expected 403, got $GS"
OWN=$(ssms_get "/admin/api_subjects.php?action=get_class_subjects&class_id=$(sudo -n mariadb ssms -N -e "SELECT id FROM classes WHERE class_code='grade_1'")")
echo "$OWN" | grep -q 'bible' && ok "teacher still sees own assigned subject" || fail "own subject missing: $OWN"

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
