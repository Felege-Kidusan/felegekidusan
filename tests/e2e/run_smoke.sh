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

# --- 1b. Stored-XSS canary (Patch C5): seed class xss_test carries
# "<img src=x onerror=alert(1)>" in its name; it must NEVER reach the
# browser as a raw "<img" (only &lt; or \u003C forms are acceptable).
XS=$(printf '%s' "$HTML" | python3 -c '
import re,sys
s = sys.stdin.read()
raw = len(re.findall(r"(?<![&\\\\])<img src=x onerror", s))
print(raw)')
[ "$XS" = "0" ] && ok "edu_dept.php: no raw XSS payload (canary class)" || fail "$XS raw XSS payload occurrences"

# --- 2. All roles can log in -----------------------------------------------
for U in audit_super audit_teach audit_fin; do
  CODE=$(ssms_login "$U" | awk '{print $1}')
  [ "$CODE" = "302" ] && ok "login $U" || fail "login $U ($CODE)"
done

# --- 3. Education APIs still answer ----------------------------------------
ssms_login audit_edu > /dev/null 2>&1
D=$(ssms_get "/admin/api_subjects.php?action=get_class_subjects&class_id=$(sudo -n mariadb --default-character-set=utf8mb4 ssms -N -e "SELECT id FROM classes WHERE class_code='grade_1'")")
echo "$D" | python3 -c 'import sys,json;d=json.load(sys.stdin);exit(0 if d["status"]=="success" and len(d["subjects"])==2 and d.get("linked") is True else 1)' \
  && ok "get_class_subjects (grade_1 = 2 subjects)" || fail "get_class_subjects: $D"
D=$(ssms_get "/admin/api_subjects.php?action=get_class_subjects&class_id=$(sudo -n mariadb --default-character-set=utf8mb4 ssms -N -e "SELECT id FROM classes WHERE class_code='grade_3'")")
echo "$D" | python3 -c 'import sys,json;d=json.load(sys.stdin);exit(0 if d["status"]=="success" and len(d["subjects"])>0 and d.get("linked") is False and bool(d.get("message")) else 1)' \
  && ok "unlinked class falls back to all subjects + notice" || fail "grade_3 fallback: $D"
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

# --- 3a2. Subject creation: long Amharic names + error envelope (Patch 8) --
LNG1="የብሔረ ቅዱሳን ሰንበት ትምህርት ታሪክ እና ትምህርት ስርዓት"
LNG2="የድንግል ማርያም ሥርዓተ ትምህርት በቀን ሰላም እና ምስጋና ጸሎት"
S1=$(ssms_post /admin/api_subjects.php action=create_subject "subject_name=$LNG1")
S2=$(ssms_post /admin/api_subjects.php action=create_subject "subject_name=$LNG2")
echo "$S1" | grep -q '"status":"success"' && ok "long Amharic subject #1 created" || fail "long subject #1: $S1"
echo "$S2" | grep -q '"status":"success"' && ok "long Amharic subject #2 (old Data-too-long path)" || fail "long subject #2: $S2"
BADCODE=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM subjects WHERE subject_code REGEXP '^_+$'")
[ "$BADCODE" = "0" ] && ok "no underscore-only codes" || fail "$BADCODE underscore-only codes remain"
OVLEN=$(python3 -c "print('ትምህርት'*34)")
OVC=$(curl -s -o /tmp/p8ov -w '%{http_code}' -b "$JAR" -X POST "$BASE/admin/api_subjects.php" -d "action=create_subject" -d "csrf_token=$(ssms_csrf)" --data-urlencode "subject_name=$OVLEN")
[ "$OVC" = "422" ] && grep -q "maximum is 150" /tmp/p8ov && ok "over-limit name -> 422 + friendly message" || fail "over-limit: $OVC $(cat /tmp/p8ov)"
grep -q '"code":"validation_error"' /tmp/p8ov && ok "structured error code present" || fail "no error code in envelope"
sudo -n mariadb ssms -e "DELETE FROM subjects WHERE subject_code LIKE 'subj_%'" >/dev/null 2>&1

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

# --- 3e. PII minimization (Patch H7) ---------------------------------------
RPH=$(ssms_get "/admin/api_education.php?action=roster&class_id=$(sudo -n mariadb ssms -N -e "SELECT id FROM classes WHERE class_code='grade_1'")")
echo "$RPH" | grep -q '251911000001' && fail "teacher roster leaks phone PII" || ok "teacher roster phone masked"
NPH=$(echo "$RPH" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(sum(1 for r in d.get("rows",[]) if r.get("phone_number") is not None))')
[ "$NPH" = "0" ] && ok "teacher roster all phones null" || fail "$NPH roster phones visible"

# --- 3f. H1 honest empty-state + H4 delete cascades (Patch 9) --------------
ssms_api_login audit_teach > /dev/null 2>&1
NB=$(ssms_api GET "grades/bootstrap&class_id=$G2" | python3 -c 'import json,sys;d=json.load(sys.stdin)["data"];print("1" if (len(d["subjects"])==0 and "class teacher" in (d.get("notice") or "")) else "0")')
[ "$NB" = "1" ] && ok "class-teacher gets honest empty-state (mobile)" || fail "H1 notice missing on bootstrap"
G1C=$(sudo -n mariadb ssms -N -e "SELECT id FROM classes WHERE class_code='grade_1'")
ssms_login audit_edu > /dev/null 2>&1
DS=$(ssms_post /admin/api_subjects.php action=create_subject "subject_name=የመጥፋት ሙከራ" "subject_name_en=DelProbe")
DSID=$(echo "$DS" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("subject_id","0"))')
ssms_post /admin/api_subjects.php action=assign_subject_to_classes subject_id=$DSID "class_ids=[$G1C]" > /dev/null
ssms_post /admin/api_subjects.php action=delete_subject subject_id=$DSID > /dev/null
ORP=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM class_subjects WHERE subject_id=$DSID")
[ "$ORP" = "0" ] && ok "subject hard-delete cascades links (H4)" || fail "$ORP orphan links after subject delete"
ssms_post /admin/api_education.php action=save_class class_name="Probe" class_name_en="Probe" class_code="smoke_probe" level_order=9 > /dev/null
PC=$(sudo -n mariadb ssms -N -e "SELECT id FROM classes WHERE class_code='smoke_probe' LIMIT 1")
ssms_post /admin/api_subjects.php action=assign_subject_to_classes subject_id=3 "class_ids=[$PC]" > /dev/null
ssms_post /admin/api_education.php action=delete_class class_id=$PC > /dev/null
CLEFT=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM class_subjects WHERE class_id=$PC")
CGONE=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM classes WHERE id=$PC")
[ "$CLEFT" = "0" ] && [ "$CGONE" = "0" ] && ok "class hard-delete cascades links (H4)" || fail "class delete left orphans ($CLEFT links, class=$CGONE)"

# --- 3g. CMS admin flow (Patch 10): super admin provisions, editor logs in ---
ssms_login audit_super > /dev/null 2>&1
ST=$(ssms_get "/admin/dashboard.php" | grep -oE 'csrf_token" value="[a-f0-9]+"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /tmp/cmsprobe -b "$JAR" -c "$JAR" -L "$BASE/admin/backend/user-save.php" \
  -d "csrf_token=$ST" -d "full_name=CMS Probe" -d "username=cms_probe" \
  -d "role=content_editor" -d "password=CmsProbe#2026x" -d "confirm_password=CmsProbe#2026x" -d "is_active=1" > /dev/null
CMSROW=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM users WHERE username='cms_probe' AND role='content_editor'")
[ "$CMSROW" = "1" ] && ok "super admin can create content_editor account" || fail "content_editor creation failed"
PASS="CmsProbe#2026x" bash -c '. tests/e2e/client.sh
  ssms_login cms_probe > /dev/null 2>&1
  ssms_get /admin/dashboard.php > /tmp/cmsdash
  curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$BASE/admin/api_subjects.php?action=get_subjects" > /tmp/cmsdeny'
grep -q "Content Manager" /tmp/cmsdash && ok "content editor lands on CMS dashboard" || fail "CMS dashboard not served"
[ "$(cat /tmp/cmsdeny)" = "403" ] && ok "content editor blocked from education APIs" || fail "CMS role reached edu API ($(cat /tmp/cmsdeny))"
sudo -n mariadb ssms -e "DELETE FROM users WHERE username IN ('cms_probe','cms_manager')" > /dev/null 2>&1

# --- 3i. Roster dedupe + unassigned pagination (Patch 11: H2/H5) -----------
YR=$(sudo -n mariadb ssms -N -e "SELECT id FROM academic_years ORDER BY id LIMIT 1")
sudo -n mariadb ssms -e "INSERT IGNORE INTO class_enrollments (member_id, class_id, academic_year_id, status, enrolled_at) SELECT 900000, id, $YR, 'active', CURDATE() FROM classes WHERE class_code='grade_2'" >/dev/null
ROS=$(ssms_get "/admin/api_education.php?action=roster&per_page=100")
DUP=$(echo "$ROS" | python3 -c 'import json,sys;d=json.load(sys.stdin);rows=d.get("rows") or [];print(sum(1 for r in rows if int(r.get("id",0))==900000))')
[ "$DUP" = "1" ] && ok "roster: dual-enrolled member appears once (H2)" || fail "roster duplicate: x$DUP"
CM=$(echo "$ROS" | python3 -c 'import json,sys;d=json.load(sys.stdin);rows=d.get("rows") or [];print(1 if d.get("total")==len(rows) else 0)')
[ "$CM" = "1" ] && ok "roster: count matches rows (H2)" || fail "roster count/rows mismatch"
sudo -n mariadb ssms -e "DELETE FROM class_enrollments WHERE member_id=900000 AND class_id=(SELECT id FROM classes WHERE class_code='grade_2')" >/dev/null
UP1=$(ssms_get "/admin/api_education.php?action=get_unassigned_members&limit=10&offset=0")
U1=$(echo "$UP1" | python3 -c 'import json,sys;d=json.load(sys.stdin);print(len(d["members"]),d["total"])')
[ "$U1" = "10 11" ] && ok "unassigned page 1 = 10 of 11 (H5)" || fail "unassigned page1: $U1"
UP2=$(ssms_get "/admin/api_education.php?action=get_unassigned_members&limit=10&offset=10")
U2=$(echo "$UP2" | python3 -c 'import json,sys;d=json.load(sys.stdin);print(len(d["members"]))')
[ "$U2" = "1" ] && ok "unassigned page 2 = 1 row (H5)" || fail "unassigned page2: $U2"
HP=$(ssms_get "/admin/dashboards/edu_dept.php")
echo "$HP" | grep -q "function pagerButtons" && echo "$HP" | grep -q "unassignedFooter" && ok "unassigned UI has pager + page-size controls" || fail "pager missing from edu_dept UI"

# --- 3j. Teacher soft-remove keeps history (Patch 12: H3) -------------------
H3HASH=$(php -r "echo password_hash('H3Smoke#2026', PASSWORD_DEFAULT);")
sudo -n mariadb ssms -e "INSERT INTO users (username, email, full_name, role, password_hash, is_active) VALUES ('h3_smoke', NULL, 'H3 Smoke Teacher', 'teacher', '$H3HASH', 1)" >/dev/null
H3T=$(sudo -n mariadb ssms -N -e "SELECT id FROM users WHERE username='h3_smoke'")
sudo -n mariadb ssms -e "INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, academic_year_id, is_class_teacher, is_primary, is_active, assigned_at) SELECT $H3T, id, 1, 1, 0, 1, 1, NOW() FROM classes WHERE class_code='grade_1'" >/dev/null
sudo -n mariadb ssms -e "INSERT INTO grade_submissions (teacher_id, class_id, subject_id, submission_type, status, student_count, submitted_at) SELECT $H3T, id, 1, 'marklist', 'submitted', 1, NOW() FROM classes WHERE class_code='grade_1'" >/dev/null
ssms_login audit_edu > /dev/null 2>&1
H3R=$(ssms_post /admin/api_teachers.php action=delete_teacher teacher_id=$H3T)
echo "$H3R" | grep -q '"status":"success"' && ok "delete_teacher succeeds (soft)" || fail "delete_teacher: $H3R"
H3U=$(sudo -n mariadb ssms -N -e "SELECT CONCAT(username,':',is_active) FROM users WHERE id=$H3T")
[ "$H3U" = "h3_smoke:0" ] && ok "teacher row retained, deactivated" || fail "user state: $H3U"
H3S=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM grade_submissions gs LEFT JOIN users u ON u.id=gs.teacher_id WHERE gs.teacher_id=$H3T AND u.full_name IS NOT NULL")
[ "$H3S" = "1" ] && ok "submission history stays attributed" || fail "orphaned submission ($H3S)"
H3RX=$(ssms_post /admin/api_teachers.php action=toggle_status teacher_id=$H3T)
echo "$H3RX" | grep -q "1 assignment(s) restored" && ok "reactivation restores suspended assignments" || fail "reactivation: $H3RX"
H3A=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id=$H3T AND is_active=1")
[ "$H3A" = "1" ] && ok "teacher got the assignment back (data restored)" || fail "assignments after reactivation: $H3A"
sudo -n mariadb ssms -e "DELETE FROM grade_submissions WHERE teacher_id=$H3T; DELETE FROM teacher_assignments WHERE teacher_id=$H3T; DELETE FROM activity_logs WHERE entity_type='user' AND entity_id=$H3T; DELETE FROM users WHERE id=$H3T" >/dev/null 2>&1

# --- 3k. Review transitions + resubmission hygiene (Patch 13: H9/H10) -------
G1C=$(sudo -n mariadb ssms -N -e "SELECT id FROM classes WHERE class_code='grade_1'")
H9C1=$(ssms_post /admin/api_subjects.php action=create_assessment class_id=$G1C subject_id=1 assessment_name="H9 Probe" assessment_type=test max_score=100 weight_percentage=5)
echo "$H9C1" | grep -q '"status":"success"' && ok "H9 probe assessment created" || fail "H9 probe create: $H9C1"
H9A=$(sudo -n mariadb ssms -N -e "SELECT id FROM assessments WHERE class_id=$G1C AND subject_id=1 AND assessment_name='H9 Probe' ORDER BY id DESC LIMIT 1")
sudo -n mariadb ssms -e "DELETE FROM academic_records WHERE assessment_id=$H9A; DELETE FROM grade_submissions WHERE assessment_id=$H9A" >/dev/null 2>&1
ssms_login audit_teach > /dev/null 2>&1
ssms_post /admin/api_subjects.php action=save_grades assessment_id=$H9A "grades=[{\"member_id\":900000,\"score\":60}]" > /dev/null
H9S=$(sudo -n mariadb ssms -N -e "SELECT id FROM grade_submissions WHERE assessment_id=$H9A")
ssms_login audit_edu > /dev/null 2>&1
H9C=$(curl -s -o /tmp/h9c -w '%{http_code}' -b "$JAR" -X POST "$BASE/admin/api_communication.php" -d "action=review_submission" -d "submission_id=$H9S" -d "new_status=approved" -d "csrf_token=$(ssms_csrf)")
[ "$H9C" = "422" ] && grep -q "invalid_transition" /tmp/h9c && ok "cannot approve a draft (422, H9)" || fail "draft approve: $H9C $(cat /tmp/h9c)"
ssms_api_login audit_teach > /dev/null 2>&1
ssms_api POST grades/submit "{\"assessment_id\":$H9A,\"grades\":[{\"member_id\":900000,\"score\":60}]}" > /dev/null
ssms_login audit_edu > /dev/null 2>&1
ssms_post /admin/api_communication.php action=review_submission submission_id=$H9S new_status=revision_needed notes="Fix the top score." > /dev/null
H9N=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM grade_submissions WHERE id=$H9S AND review_notes IS NOT NULL")
[ "$H9N" = "1" ] && ok "review note recorded on return" || fail "review note missing"
ssms_api_login audit_teach > /dev/null 2>&1
ssms_api POST grades/submit "{\"assessment_id\":$H9A,\"grades\":[{\"member_id\":900000,\"score\":75}]}" > /dev/null
H10=$(sudo -n mariadb ssms -N -e "SELECT CONCAT(status,':',IF(review_notes IS NULL AND reviewed_by IS NULL,'clean','stale')) FROM grade_submissions WHERE id=$H9S")
[ "$H10" = "submitted:clean" ] && ok "resubmission clears review note (H10)" || fail "resubmit state: $H10"
ssms_login audit_edu > /dev/null 2>&1
H9AP=$(ssms_post /admin/api_communication.php action=review_submission submission_id=$H9S new_status=approved)
echo "$H9AP" | grep -q '"status":"success"' && ok "approve after resubmission works" || fail "approve: $H9AP"
H9R=$(curl -s -o /tmp/h9r -w '%{http_code}' -b "$JAR" -X POST "$BASE/admin/api_communication.php" -d "action=review_submission" -d "submission_id=$H9S" -d "new_status=rejected" -d "notes=late try" -d "csrf_token=$(ssms_csrf)")
[ "$H9R" = "422" ] && ok "cannot reject an approved list (422, H9)" || fail "reject approved: $H9R"
sudo -n mariadb ssms -e "DELETE FROM academic_records WHERE assessment_id=$H9A; DELETE FROM grade_submissions WHERE assessment_id=$H9A; DELETE FROM assessments WHERE id=$H9A" >/dev/null 2>&1
HP=$(ssms_get "/admin/dashboards/edu_dept.php")
echo "$HP" | grep -q "scorePct" && echo "$HP" | grep -q "80% and above" && ok "review filters are percentage-based (H6)" || fail "H6 filters missing"

# --- 3m. Mobile weight-sum rule + legacy assignments (Patch 14: H11/H12) ----
G1W=$(sudo -n mariadb ssms -N -e "SELECT 101 - COALESCE(SUM(a.weight_percentage),0) FROM assessments a JOIN classes c ON c.id=a.class_id WHERE c.class_code='grade_1' AND a.subject_id=1")
ssms_api_login audit_teach > /dev/null 2>&1
H11R=$(ssms_api POST grades/assessments "{\"class_id\":$(sudo -n mariadb ssms -N -e "SELECT id FROM classes WHERE class_code='grade_1'"),\"subject_id\":1,\"assessment_name\":\"H11 smoke over\",\"weight_percentage\":$G1W,\"max_score\":100}")
echo "$H11R" | grep -q "exceed 100%" && ok "mobile blocks over-budget weight (H11)" || fail "H11: $H11R"
G3C=$(sudo -n mariadb ssms -N -e "SELECT id FROM classes WHERE class_code='grade_3'")
sudo -n mariadb ssms -e "INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, academic_year_id, is_class_teacher, is_primary, is_active, assigned_at) VALUES (3,$G3C,2,NULL,0,1,1,NOW())" >/dev/null
ssms_login audit_edu > /dev/null 2>&1
H12M=$(ssms_get "/admin/api_assignments.php?action=matrix")
H12K=$(echo "$H12M" | G3="$G3C" python3 -c 'import json,sys,os;d=json.load(sys.stdin);c=(d.get("cells") or {}).get(os.environ["G3"]+"-2",[]);print(1 if any(x.get("teacher_id")==3 for x in c) else 0)')
[ "$H12K" = "1" ] && ok "legacy NULL-year assignment visible on matrix (H12)" || fail "H12 matrix missing legacy row"
ssms_post /admin/api_assignments.php action=assign teacher_id=3 class_id=$G3C subject_id=2 role=primary > /dev/null
H12D=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id=3 AND class_id=$G3C AND subject_id=2")
[ "$H12D" = "1" ] && ok "assign() reuses legacy row, no duplicate (H12)" || fail "H12 duplicates: $H12D"
sudo -n mariadb ssms -e "DELETE FROM teacher_assignments WHERE teacher_id=3 AND class_id=$G3C AND subject_id=2" >/dev/null 2>&1

# --- 3p. Mezmur single-writer + audit parity (Patch 16: MZ-1/2/7) ----------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P16Z=$(ssms_api POST mezmur/zemarian '{"name":"P16 Smoke Singer"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
ssms_api POST mezmur/zemarian "{\"id\":$P16Z,\"name\":\"P16 Smoke Renamed\"}" >/dev/null
P16H=$(ssms_api POST mezmur/hymn '{"title":"P16 Smoke Hymn"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
P16R1=$(sudo -n mariadb ssms -N -e "SELECT revision FROM mezmur_hymns WHERE id=$P16H")
ssms_api POST mezmur/hymn-status "{\"id\":$P16H,\"status\":\"archived\"}" >/dev/null
P16R2=$(sudo -n mariadb ssms -N -e "SELECT revision FROM mezmur_hymns WHERE id=$P16H")
[ "$P16R2" -gt "$P16R1" ] && ok "mobile archive bumps revision (sync contract)" || fail "mobile archive revision $P16R1->$P16R2"
# web path: super-admin login-page token flow (super dashboard has no CSRF const)
P16HTML=$(curl -s -c "$JAR" "$BASE/admin/index.php")
P16TOK=$(printf '%s' "$P16HTML" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -d "csrf_token=$P16TOK" -d "username=audit_super" -d "password=$PASS" "$BASE/admin/backend/login.php"
P16W=$(curl -s -b "$JAR" -d "csrf_token=$P16TOK" -d "action=set_status" -d "id=$P16H" -d "status=active" "$BASE/admin/api_mezmur.php")
P16R3=$(sudo -n mariadb ssms -N -e "SELECT revision FROM mezmur_hymns WHERE id=$P16H")
echo "$P16W" | grep -q '"success"' && [ "$P16R3" -gt "$P16R2" ] && ok "web restore bumps revision via single writer (MZ-2)" || fail "web set_status: $P16W rev $P16R2->$P16R3"
P16NOOP=$(curl -s -b "$JAR" -d "csrf_token=$P16TOK" -d "action=set_status" -d "id=$P16H" -d "status=active" "$BASE/admin/api_mezmur.php")
P16R4=$(sudo -n mariadb ssms -N -e "SELECT revision FROM mezmur_hymns WHERE id=$P16H")
echo "$P16NOOP" | grep -q "already in that state" && [ "$P16R4" = "$P16R3" ] && ok "no-op transition refused, no phantom revision" || fail "no-op guard: $P16NOOP rev $P16R3->$P16R4"
P16A1=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM activity_logs WHERE entity_type='mezmur_zemarian' AND entity_id=$P16Z AND action='Mezmur Singer Created'")
P16A2=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM activity_logs WHERE entity_type='mezmur_zemarian' AND entity_id=$P16Z AND action='Mezmur Singer Renamed'")
[ "$P16A1" = "1" ] && ok "mobile singer create is audited (MZ-1)" || fail "singer create audit rows: $P16A1"
[ "$P16A2" = "1" ] && ok "singer rename is audited with from/to (MZ-7)" || fail "singer rename audit rows: $P16A2"
P16A3=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM activity_logs WHERE entity_type='mezmur_hymn' AND entity_id=$P16H")
[ "$P16A3" = "3" ] && ok "hymn trail exact: create+archive+restore, no phantoms" || fail "hymn audit rows: $P16A3 (expect 3)"
sudo -n mariadb ssms -e "DELETE FROM mezmur_hymns WHERE id=$P16H; DELETE FROM mezmur_zemarians WHERE id=$P16Z; DELETE FROM activity_logs WHERE (entity_type='mezmur_hymn' AND entity_id=$P16H) OR (entity_type='mezmur_zemarian' AND entity_id=$P16Z)" >/dev/null 2>&1

# --- 3q. Mezmur rename propagation + join-aware filter (Patch 17: MZ-3/4) ----
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P17A=$(ssms_api POST mezmur/category '{"name":"P17 Alpha"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
P17B=$(ssms_api POST mezmur/category '{"name":"P17 Beta"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
P17H=$(ssms_api POST mezmur/hymn "{\"title\":\"P17 Smoke Hymn\",\"categories\":[$P17A,$P17B]}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
P17REV=$(sudo -n mariadb ssms -N -e "SELECT revision FROM mezmur_hymns WHERE id=$P17H")
sleep 1.2; P17TS=$(sudo -n mariadb ssms -N -e "SELECT updated_at FROM mezmur_hymns WHERE id=$P17H")
ssms_api POST mezmur/category "{\"id\":$P17A,\"name\":\"P17 Alpha Renamed\"}" >/dev/null
P17MIRROR=$(sudo -n mariadb ssms -N -e "SELECT category FROM mezmur_hymns WHERE id=$P17H")
P17MOVED=$(sudo -n mariadb ssms -N -e "SELECT updated_at > '$P17TS' FROM mezmur_hymns WHERE id=$P17H")
P17REV2=$(sudo -n mariadb ssms -N -e "SELECT revision FROM mezmur_hymns WHERE id=$P17H")
[ "$P17MIRROR" = "P17 Alpha Renamed" ] && [ "$P17MOVED" = "1" ] && ok "category rename relabels hymns + emits sync delta (MZ-3)" || fail "rename propagation: mirror=$P17MIRROR moved=$P17MOVED"
[ "$P17REV2" = "$P17REV" ] && ok "relabel does NOT bump revision (offline edits protected)" || fail "revision bumped on relabel: $P17REV -> $P17REV2"
P17AUD=$(sudo -n mariadb ssms -N -e "SELECT details FROM activity_logs WHERE entity_type='mezmur_category' AND entity_id=$P17A ORDER BY id DESC LIMIT 1")
echo "$P17AUD" | grep -q '"hymns_relabelled":1' && ok "rename audit carries relabel count" || fail "rename audit: $P17AUD"
P17F=$(ssms_api GET "mezmur/hymns&category=P17%20Beta" | python3 -c 'import sys,json;d=json.load(sys.stdin)["data"];print("P17 Smoke Hymn" in [i["title"] for i in d["items"]])')
[ "$P17F" = "True" ] && ok "join-aware filter finds hymn by 2nd category (MZ-4, mobile)" || fail "mobile join filter: $P17F"
P17HTML=$(curl -s -c "$JAR" "$BASE/admin/index.php")
P17TOK=$(printf '%s' "$P17HTML" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -d "csrf_token=$P17TOK" -d "username=audit_super" -d "password=$PASS" "$BASE/admin/backend/login.php"
P17W=$(curl -s -b "$JAR" "$BASE/admin/api_mezmur.php?action=list&category=P17+Beta" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(any(i["title"]=="P17 Smoke Hymn" for i in d["items"]))')
[ "$P17W" = "True" ] && ok "join-aware filter on web controller too (MZ-4)" || fail "web join filter: $P17W"
P17G=$(ssms_api GET "mezmur/hymns&category=general" | python3 -c 'import sys,json;print(json.load(sys.stdin)["status"])')
[ "$P17G" = "success" ] && ok "legacy 'general' string filter still works" || fail "general filter: $P17G"
sudo -n mariadb ssms -e "DELETE FROM mezmur_hymn_categories WHERE hymn_id=$P17H; DELETE FROM mezmur_hymns WHERE id=$P17H; DELETE FROM mezmur_categories WHERE id IN ($P17A,$P17B); DELETE FROM activity_logs WHERE (entity_type='mezmur_hymn' AND entity_id=$P17H) OR (entity_type='mezmur_category' AND entity_id IN ($P17A,$P17B))" >/dev/null 2>&1

# --- 3r. Mezmur mobile reads are rate limited (Patch 18: MZ-5) ---------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P18CODES=$(for i in $(seq 1 65); do curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $API_TOKEN" "$BASE/api/v1/index.php?_route=mezmur/hymns/changes&include_lyrics=1"; done | sort | uniq -c | tr '\n' ';')
echo "$P18CODES" | grep -q " 60 200" && echo "$P18CODES" | grep -qE "[0-9]+ 429" \
  && ok "delta reads throttled after 60/min (429 + Retry-After)" || fail "read throttle codes: $P18CODES"
P18LIST=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $API_TOKEN" "$BASE/api/v1/index.php?_route=mezmur/hymns&per_page=1")
[ "$P18LIST" = "200" ] && ok "lightweight reads unaffected (separate bucket)" || fail "list read: $P18LIST"
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1

# --- 3s. Mezmur concurrency guards (Patch 19: MZ-6) --------------------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P19H=$(ssms_api POST mezmur/hymn '{"title":"P19 Smoke Hymn","lyrics":"v1"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d "{\"id\":$P19H,\"title\":\"P19 Smoke Hymn\",\"lyrics\":\"writer A\",\"base_revision\":1}" "$BASE/api/v1/index.php?_route=mezmur/hymn" > /tmp/p19a &
curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d "{\"id\":$P19H,\"title\":\"P19 Smoke Hymn\",\"lyrics\":\"writer B\",\"base_revision\":1}" "$BASE/api/v1/index.php?_route=mezmur/hymn" > /tmp/p19b &
wait
P19SET="$(cat /tmp/p19a) $(cat /tmp/p19b)"
[ "$(echo "$P19SET" | tr ' ' '\n' | sort | tr '\n' ' ')" = "200 409 " ] && ok "parallel writers: exactly one 200 + one 409" || fail "parallel writes: $P19SET"
curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"title":"P19 Smoke Twin"}' "$BASE/api/v1/index.php?_route=mezmur/hymn" > /tmp/p19c &
curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"title":"P19 Smoke Twin"}' "$BASE/api/v1/index.php?_route=mezmur/hymn" > /tmp/p19d &
wait
P19TWIN="$(cat /tmp/p19c) $(cat /tmp/p19d)"
P19ROWS=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM mezmur_hymns WHERE title='P19 Smoke Twin'")
[ "$(echo "$P19TWIN" | tr ' ' '\n' | sort | tr '\n' ' ')" = "201 422 " ] && [ "$P19ROWS" = "1" ] && ok "parallel creators: one 201 + one 422, single row" || fail "parallel creates: $P19TWIN rows=$P19ROWS"
P19IDX=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='ssms' AND table_name='mezmur_hymns' AND index_name='uq_mezmur_hymns_title'")
[ "$P19IDX" = "1" ] && ok "storage-level unique title index present (sql/031)" || fail "unique index missing"
sudo -n mariadb ssms -e "DELETE FROM mezmur_hymns WHERE title LIKE 'P19 Smoke%'; DELETE FROM activity_logs WHERE entity_type='mezmur_hymn' AND details LIKE '%P19 Smoke%'" >/dev/null 2>&1

# --- 3t. Mezmur mop-up (Patch 20: MZ-9/10/13) --------------------------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P20V=$(ssms_api POST mezmur/hymn '{"title":"P20 Smoke A","categories":[999999]}')
echo "$P20V" | grep -q "no longer exists" && ok "unknown taxonomy id -> honest 422 (MZ-9)" || fail "unknown id: $P20V"
( echo "BEGIN; INSERT INTO mezmur_hymns (title, category, status, created_by, updated_by) VALUES ('P20 Smoke Lock','general','active',1,1); SELECT SLEEP(2); COMMIT;" | sudo -n mariadb ssms >/dev/null 2>&1 ) &
sleep 0.4
P20CODE=$(curl -s -o /tmp/p20t -w '%{http_code}' -X POST -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"title":"P20 Smoke Lock","category":"P20 Smoke Orphan"}' "$BASE/api/v1/index.php?_route=mezmur/hymn")
wait
P20ORPH=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM mezmur_categories WHERE name='P20 Smoke Orphan'")
[ "$P20CODE" = "422" ] && [ "$P20ORPH" = "0" ] && ok "failed save rolls back legacy category (no orphans, MZ-10)" || fail "orphan test: code=$P20CODE orphans=$P20ORPH"
P20HASH=$(php -r "echo password_hash('P20Smoke#2026', PASSWORD_DEFAULT);")
sudo -n mariadb ssms -e "INSERT INTO users (username,email,full_name,role,password_hash,is_active) VALUES ('p20_mez',NULL,'P20 Mezmur','mezmur_dept','$P20HASH',1)"
P20M=$(curl -s -c /tmp/p20jar "$BASE/admin/index.php")
P20MT=$(printf '%s' "$P20M" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -b /tmp/p20jar -c /tmp/p20jar -o /dev/null -d "csrf_token=$P20MT" -d "username=p20_mez" -d "password=P20Smoke#2026" "$BASE/admin/backend/login.php"
P20R=$(curl -s -b /tmp/p20jar -d "csrf_token=$P20MT" -d "action=migrate" "$BASE/admin/api_mezmur.php")
echo "$P20R" | grep -q "Only administrators" && ok "schema reconcile restricted to admins (MZ-13)" || fail "migrate gate: $P20R"
sudo -n mariadb ssms -e "DELETE FROM users WHERE username='p20_mez'; DELETE FROM mezmur_hymns WHERE title LIKE 'P20 Smoke%'; DELETE FROM mezmur_categories WHERE name LIKE 'P20 Smoke%'; DELETE FROM activity_logs WHERE entity_type='mezmur_hymn' AND details LIKE '%P20 Smoke%'" >/dev/null 2>&1
rm -f /tmp/p20jar /tmp/p20t

# --- 3u. Typo-tolerant search (Patch 22: two-stage + fuzzy rescue) ----------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
ssms_api POST mezmur/hymn '{"title":"P22 Smoke Selamawit"}' >/dev/null
ssms_api POST mezmur/hymn '{"title":"P22 Smoke Zerihun"}' >/dev/null
ssms_api POST mezmur/hymn '{"title":"Qz1 P22 Needle"}' >/dev/null
# 1) service API: misspelled query (LIKE can never match 'Selamwit')
P22S=$(ssms_api GET "mezmur/hymns&search=Selamwit")
echo "$P22S" | grep -q "P22 Smoke Selamawit" && ok "service fuzzy rescue finds typo 'Selamwit'" || fail "service typo search: $P22S"
# 2) web API: same typo + ranked best-first (exact 'Zerihun' above fuzzy 'Selamwit')
ssms_login audit_super >/dev/null 2>&1
P22W=$(curl -s -b "$JAR" "$BASE/admin/api_mezmur.php?action=list&search=Zerihun%20Selamwit")
P22F=$(printf '%s' "$P22W" | python3 -c 'import sys,json;d=json.load(sys.stdin);its=d["items"];print(its[0]["title"] if its else "-", "|", its[1]["title"] if len(its)>1 else "-")' 2>/dev/null)
case "$P22F" in
  *Zerihun*|*Selamawit*) [ "${P22F%%|*}" != "$P22F" ] && case "$P22F" in *Zerihun*\|*Selamawit*) ok "web ranked best-first (exact > fuzzy): $P22F";; *) fail "web ranking order: $P22F";; esac || fail "web rescue returned 1 row: $P22F" ;;
  *) fail "web typo search: $P22W" ;;
esac
# 3) 1-char query dropped: 'Q' must NOT filter to the needle only
P22C=$(curl -s -b "$JAR" "$BASE/admin/api_mezmur.php?action=list&search=Q")
echo "$P22C" | grep -q "P22 Smoke Selamawit" && ok "1-char query ignored server-side (no unindexable scan)" || fail "1-char still filtering: $P22C"
sudo -n mariadb ssms -e "DELETE FROM mezmur_hymns WHERE title LIKE 'P22 Smoke%' OR title LIKE 'Qz1%'; DELETE FROM mezmur_hymns WHERE title IN ('Selamawit Guad','Kidus Giorgis'); DELETE FROM activity_logs WHERE entity_type='mezmur_hymn' AND details LIKE '%P22%'" >/dev/null 2>&1

# --- 3v. Taxonomy sync (Patch 23: natural-key resolution) -------------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
# 1) idempotent create: same name twice -> SAME row (no duplicates)
P23A=$(ssms_api POST mezmur/category '{"id":0,"name":"P23 Smoke Cat"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])' 2>/dev/null)
P23B=$(ssms_api POST mezmur/category '{"id":0,"name":"P23 Smoke Cat"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])' 2>/dev/null)
[ -n "$P23A" ] && [ "$P23A" = "$P23B" ] && ok "category create idempotent (id $P23A twice)" || fail "idempotent create: A=$P23A B=$P23B"
# 2) hymn save with an offline {id:-77,name} ref -> category created + JOINED
ssms_api POST mezmur/hymn '{"title":"P23 Smoke Hymn","categories":[{"id":-77,"name":"P23 Smoke OffCat"}]}' >/dev/null
P23J=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM mezmur_hymns h JOIN mezmur_hymn_categories mhc ON mhc.hymn_id=h.id JOIN mezmur_categories c ON c.id=mhc.category_id WHERE h.title='P23 Smoke Hymn' AND c.name='P23 Smoke OffCat'")
[ "$P23J" = "1" ] && ok "offline name-ref resolved + joined server-side" || fail "name-ref join missing ($P23J)"
# 3) ref to an EXISTING name links to the existing row (no duplicate created)
ssms_api POST mezmur/hymn '{"title":"P23 Smoke Hymn2","categories":[{"id":-88,"name":"P23 Smoke Cat"}]}' >/dev/null
P23N=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM mezmur_categories WHERE name='P23 Smoke Cat'")
[ "$P23N" = "1" ] && ok "existing-name ref linked (still exactly 1 row)" || fail "duplicate category created ($P23N)"
# 4) renaming a HIDDEN category echoes is_active=0 (was hardcoded 1)
P23S=$(ssms_api POST mezmur/category-status "{\"id\":$P23A,\"active\":false}")
P23R=$(ssms_api POST mezmur/category "{\"id\":$P23A,\"name\":\"P23 Smoke Cat R\"}")
P23H=$(printf '%s' "$P23R" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["is_active"])' 2>/dev/null)
[ "$P23H" = "0" ] && ok "hidden category rename echoes is_active=0" || fail "rename echo is_active=$P23H hide=$P23S rename=$P23R"
sudo -n mariadb ssms -e "DELETE mhc FROM mezmur_hymn_categories mhc JOIN mezmur_hymns h ON h.id=mhc.hymn_id WHERE h.title LIKE 'P23 Smoke%'; DELETE FROM mezmur_hymns WHERE title LIKE 'P23 Smoke%'; DELETE FROM mezmur_categories WHERE name LIKE 'P23 Smoke%'; DELETE FROM activity_logs WHERE details LIKE '%P23 Smoke%'" >/dev/null 2>&1

# --- 3w. Lyrics markup + styled delivery (Patch 24) -------------------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
# 1) markup round-trips VERBATIM (plain text stored; clients render)
ssms_api POST mezmur/hymn '{"title":"P24 Smoke Hymn","lyrics":"[Verse 1]\n**bold** line and *italic* word\n\n[Chorus]\nnormal stanza"}' >/dev/null
P24G=$(curl -s -H "Authorization: Bearer $API_TOKEN" "$BASE/api/v1/index.php?_route=mezmur/hymn&id=$(sudo -n mariadb ssms -N -e "SELECT id FROM mezmur_hymns WHERE title='P24 Smoke Hymn'")" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["lyrics"])' 2>/dev/null)
case "$P24G" in
  *"[Verse 1]"*"*bold*"*"*italic*"*"[Chorus]"*) ok "markup lyrics round-trip verbatim (render-time parsing)";;
  *) fail "lyrics round-trip: $P24G";;
esac
# 2) the styled web assets are actually served
curl -s "$BASE/frontend/js/mezmur.js" | grep -q "function renderLyrics" && ok "web lyrics renderer served" || fail "renderLyrics missing from served JS"
curl -s "$BASE/frontend/pages/mezmur_dept.php" -b "$JAR" | grep -q "mz-ed-toolbar" && ok "web visual lyrics editor served (P30)" || fail "visual editor missing from served page"
curl -s "$BASE/frontend/pages/mezmur_dept.php" -b "$JAR" | grep -qi "genius\|spotify" && fail "third-party company name in UI" || ok "no company names in web UI"
curl -s "$BASE/frontend/pages/mezmur_dept.php" -b "$JAR" | grep -q "toolbar-compact" && ok "compact library toolbar served (P30)" || fail "compact toolbar missing"
curl -s "$BASE/frontend/js/mezmur.js" | grep -q "editorToMarkup" && ok "visual editor converter served" || fail "editor converter missing from JS"
sudo -n mariadb ssms -e "DELETE FROM mezmur_hymns WHERE title LIKE 'P24 Smoke%'; DELETE FROM activity_logs WHERE details LIKE '%P24 Smoke%'" >/dev/null 2>&1

# --- 3x. Word-index lyrics search (Patch 25) --------------------------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
ssms_api POST mezmur/hymn '{"title":"P25 Smoke En","lyrics":"line one\nQZXSMOKEWORD beacon\nline three"}' >/dev/null
ssms_api POST mezmur/hymn '{"title":"P25 Smoke Am","lyrics":"የመዝሙር ግጥም\nሰላም ለሁሉም ሕዝብ\nእናደምማለን"}' >/dev/null
# 1) SERVICE: english word that exists ONLY in the lyrics
P25S=$(ssms_api GET "mezmur/hymns&search=QZXSMOKEWORD")
echo "$P25S" | grep -q "P25 Smoke En" && echo "$P25S" | grep -q '"match_in":"lyrics"' && ok "service finds lyrics-only word (EN)" || fail "service lyrics EN: $P25S"
# 2) SERVICE: amharic word that exists ONLY in the lyrics (FULLTEXT-blind script)
P25A=$(ssms_api GET "mezmur/hymns&search=$(python3 -c "import urllib.parse;print(urllib.parse.quote('ሰላም'))")")
echo "$P25A" | grep -q "P25 Smoke Am" && ok "service finds lyrics-only word (AM — Ge'ez)" || fail "service lyrics AM: $P25A"
# 3) WEB: word mode + snippet, no lyrics blob in the payload
ssms_login audit_super >/dev/null 2>&1
P25W=$(curl -s -b "$JAR" "$BASE/admin/api_mezmur.php?action=list&search=QZXSMOKEWORD")
echo "$P25W" | grep -q '"search_mode":"word"' && echo "$P25W" | grep -q '"match_in":"lyrics"' && echo "$P25W" | grep -vq '"lyrics":' && ok "web word mode + match_in + no lyrics leak" || fail "web word search: $P25W"
# 4) title typo rescue still intact through the new engine
P25T=$(ssms_api GET "mezmur/hymns&search=P25%20Smoke%20Enlgish")
echo "$P25T" | grep -q "P25 Smoke En" && ok "title typo still rescued (fuzzy tier)" || fail "typo through word engine: $P25T"
sudo -n mariadb ssms -e "DELETE w FROM mezmur_hymn_words w LEFT JOIN mezmur_hymns h ON h.id=w.hymn_id WHERE h.id IS NULL OR h.title LIKE 'P25%'; DELETE FROM mezmur_hymns WHERE title LIKE 'P25%'; DELETE FROM activity_logs WHERE details LIKE '%P25%'" >/dev/null 2>&1

# --- 3y. Search chain completion (Patch 27) ---------------------------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_login audit_super >/dev/null 2>&1
# the served page must cache-bust the page JS (stale-JS trap closed)
P27V=$(ssms_get "/frontend/pages/mezmur_dept.php" | grep -c "frontend/js/mezmur.js?v=")
[ "$P27V" -ge 1 ] && ok "page JS cache-busted (?v=filemtime)" || fail "no cache-buster on mezmur.js"
# and the current JS on the wire still contains the search stack
P27J=$(curl -s "$BASE/frontend/js/mezmur.js" | grep -c "listCache")
[ "$P27J" -ge 1 ] && ok "served mezmur.js is current (query cache present)" || fail "served JS looks stale"

# --- 3z. Single Amharic title + catalog management (Patch 28) ---------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
# 0) migration 033 applied: retired columns GONE server-side
P28C=$(sudo -n mariadb ssms -N -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mezmur_hymns' AND COLUMN_NAME IN ('title_am','reference')")
[ "$P28C" = "0" ] && ok "mezmur_hymns single-title schema (title_am/reference dropped)" || fail "retired columns still present ($P28C)"
# 1) OLD app builds still send title_am/reference -> accepted-and-ignored
P28S=$(ssms_api POST mezmur/hymn '{"title":"P28 ሰላምታ Smoke","title_am":"legacy field","reference":"legacy ref","lyrics":"የመዝሙር ግጥም ሰላም"}')
P28T=$(sudo -n mariadb --default-character-set=utf8mb4 ssms -N -e "SELECT title FROM mezmur_hymns WHERE title LIKE 'P28%'")
if echo "$P28S" | grep -q '"success"' && [ "$P28T" = "P28 ሰላምታ Smoke" ]; then
  ok "legacy title_am/reference payloads accepted + ignored (no breakage)"
else
  fail "legacy payload: resp=$P28S title=$P28T"
fi
# 2) single-title read contract: no retired keys in the API echo
P28ID=$(sudo -n mariadb ssms -N -e "SELECT id FROM mezmur_hymns WHERE title LIKE 'P28%'")
P28G=$(curl -s -H "Authorization: Bearer $API_TOKEN" "$BASE/api/v1/index.php?_route=mezmur/hymn&id=$P28ID")
echo "$P28G" | grep -vq 'title_am' && echo "$P28G" | grep -vq '"reference"' && ok "API echo is single-title (no retired keys)" || fail "retired keys in echo: $P28G"
# 3) search still finds the Amharic-only title through the word engine
P28Q=$(ssms_api GET "mezmur/hymns&search=$(python3 -c "import urllib.parse;print(urllib.parse.quote('ሰላምታ'))")")
echo "$P28Q" | grep -q "P28" && ok "Amharic single-title searchable" || fail "amharic search: $P28Q"
# 4) catalog lists carry usage counts (web manager, item 11)
P28CAT=$(ssms_api GET "mezmur/categories")
echo "$P28CAT" | grep -q 'hymn_count' && ok "categories list carries hymn_count" || fail "no hymn_count: $P28CAT"
# 5) web form is single-title
ssms_login audit_super >/dev/null 2>&1
P28P=$(ssms_get "/frontend/pages/mezmur_dept.php")
echo "$P28P" | grep -q "mzTitleAm" && fail "web still renders the Amharic-title field" || ok "web form single title (no mzTitleAm)"
echo "$P28P" | grep -q "Search by title or lyrics" && ok "web search hint updated" || fail "web search hint stale"
sudo -n mariadb ssms -e "DELETE w FROM mezmur_hymn_words w LEFT JOIN mezmur_hymns h ON h.id=w.hymn_id WHERE h.id IS NULL OR h.title LIKE 'P28%'; DELETE FROM mezmur_hymns WHERE title LIKE 'P28%'; DELETE FROM activity_logs WHERE details LIKE '%P28%'" >/dev/null 2>&1

# --- 3aa. Two-level taxonomy (Patch 30) --------------------------------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P30M=$(ssms_api POST mezmur/category '{"id":0,"name":"P30 Smoke Main"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
P30S=$(ssms_api POST mezmur/category "{\"id\":0,\"name\":\"P30 Smoke Sub\",\"parent_id\":$P30M}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
ssms_api POST mezmur/hymn "{\"title\":\"P30 Smoke Hymn\",\"categories\":[$P30S],\"lyrics\":\"p30 body\"}" >/dev/null
# 1) filtering by the MAIN finds the hymn stored under its SUB (roll-up)
P30L=$(ssms_api GET "mezmur/hymns&category_id=$P30M")
echo "$P30L" | grep -q "P30 Smoke Hymn" && ok "category roll-up: main matches sub hymns (API)" || fail "rollup API: $P30L"
# 2) the web controller rolls up too
ssms_login audit_super >/dev/null 2>&1
P30W=$(curl -s -b "$JAR" "$BASE/admin/api_mezmur.php?action=list&category_id=$P30M")
echo "$P30W" | grep -q "P30 Smoke Hymn" && ok "category roll-up (web controller)" || fail "rollup web: $P30W"
# 3) tree shape: parent_id + rolled-up counts in the categories API
P30T=$(ssms_api GET "mezmur/categories")
echo "$P30T" | grep -q '"parent_id":null' && echo "$P30T" | grep -q 'hymn_count_total' && ok "categories API returns two-level tree" || fail "tree API: $P30T"
# 4) depth-3 rejected
P30D=$(ssms_api POST mezmur/category "{\"id\":0,\"name\":\"P30 Smoke Deep\",\"parent_id\":$P30S}")
echo "$P30D" | grep -q "two levels maximum" && ok "depth limited to two levels" || fail "depth guard: $P30D"
sudo -n mariadb --default-character-set=utf8mb4 ssms -e "DELETE hc FROM mezmur_hymn_categories hc JOIN mezmur_hymns h ON h.id=hc.hymn_id WHERE h.title LIKE 'P30 Smoke%'; DELETE FROM mezmur_categories WHERE parent_id IN (SELECT id FROM (SELECT id FROM mezmur_categories WHERE name LIKE 'P30 Smoke%') x); DELETE FROM mezmur_categories WHERE name LIKE 'P30 Smoke%'; DELETE FROM mezmur_hymns WHERE title LIKE 'P30 Smoke%'; DELETE FROM activity_logs WHERE details LIKE '%P30 Smoke%'" >/dev/null 2>&1

# --- 3ab. Standalone catalog manager + cascading form (Patch 31) ------------
ssms_login audit_super >/dev/null 2>&1
P31P=$(ssms_get "/frontend/pages/mezmur_dept.php")
[ "$(echo "$P31P" | grep -c 'data-section="catalog"')" -ge 3 ] && echo "$P31P" | grep -q 'id="section-catalog"' && ok "standalone catalog section + sidebar + mobile nav served" || fail "catalog section/nav missing"
echo "$P31P" | grep -q "mzHymnMainCat" && echo "$P31P" | grep -q "mzHymnSubCat" && ok "cascading category/subcategory selects served" || fail "cascade selects missing"
P31J=$(curl -s "$BASE/frontend/js/mezmur.js")
echo "$P31J" | grep -q "window.prompt\|window.confirm\|window.alert" && fail "browser popup still used in served JS" || ok "zero browser popups in served JS"
echo "$P31J" | grep -q "sysConfirm" && echo "$P31J" | grep -q "populateHymnCats" && ok "system confirm + cascade logic served" || fail "P31 JS missing"
echo "$P31J" | grep -q "function () { migrateRun(); }" && fail "schema-sync confirm loop served" || ok "schema sync has no confirm loop"
echo "$P31J" | grep -q "Choose a category and sub-category" && ok "hymn save enforces category choice" || fail "category save guard missing"

# --- 3ac. REST cover-image upload + validation (Patch 31c) ------------------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P31C=$(ssms_api POST mezmur/category '{"id":0,"name":"P31c Smoke Cat"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
python3 -c "import struct,zlib
def chunk(t,d):
    c=t+d; return struct.pack('>I',len(d))+c+struct.pack('>I',zlib.crc32(c))
w=h=64
raw=b''.join(b'\x00'+bytes([120,30,60]*w) for _ in range(h))
open('/tmp/p31c.png','wb').write(b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',w,h,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(raw))+chunk(b'IEND',b''))"
P31U=$(curl -s -X POST "$BASE/api/v1/mezmur/category-image" -H "Authorization: Bearer $API_TOKEN" -F "id=$P31C" -F "image=@/tmp/p31c.png;type=image/png")
echo "$P31U" | grep -q '"image_url"' && ok "REST cover upload stored (hashed name)" || fail "REST upload: $P31U"
printf 'forged bytes' > /tmp/p31c_bad.png
P31B=$(curl -s -X POST "$BASE/api/v1/mezmur/category-image" -H "Authorization: Bearer $API_TOKEN" -F "id=$P31C" -F "image=@/tmp/p31c_bad.png;type=image/png")
echo "$P31B" | grep -q "Only JPEG, PNG or WebP" && ok "REST upload rejects forged magic bytes" || fail "forged upload accepted: $P31B"
P31N=$(curl -s -X POST "$BASE/api/v1/mezmur/category-image" -H "Authorization: Bearer $API_TOKEN" -F "id=$P31C")
echo "$P31N" | grep -q "Choose an image" && ok "REST upload requires a file" || fail "no-file upload: $P31N"
P31F=$(sudo -n mariadb -N ssms -e "SELECT image_path FROM mezmur_categories WHERE id=$P31C")
P31F="${P31F%%\?*}"   # drop the ?v= cache-buster — the disk file has no query
if [ -n "$P31F" ] && sudo -n rm -f "${P31F#/}"; then ok "uploaded file removed with test row"; else fail "uploaded file not cleaned: $P31F"; fi
sudo -n mariadb --default-character-set=utf8mb4 ssms -e "DELETE FROM mezmur_categories WHERE name LIKE 'P31c%'; DELETE FROM activity_logs WHERE details LIKE '%P31c%'" >/dev/null 2>&1

# --- 3ad. Cover colors: gradient picker + preview + remove (Patch 32) ------
sudo -n mariadb ssms < sql/035_mezmur_category_gradient.sql 2>/dev/null
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P32G=$(ssms_api POST mezmur/category '{"id":0,"name":"P32 Smoke Grad","gradient_start":"#0ea5e9","gradient_end":"#2563eb"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
ssms_login audit_super >/dev/null 2>&1
curl -s -b "$JAR" "$BASE/admin/api_mezmur.php?action=categories" | grep -q '"gradient_start":"#0ea5e9"' && ok "pinned gradient served to the web manager" || fail "gradient missing from categories list"
P32O=$(ssms_api POST mezmur/category '{"id":'"$P32G"',"name":"P32 Smoke Grad","gradient_start":"#059669","gradient_end":"#0d9488"}')
echo "$P32O" | grep -q '"status":"success"' && ok "color-only edit allowed" || fail "color-only edit rejected: $P32O"
P32C=$(ssms_api POST mezmur/category '{"id":'"$P32G"',"name":"P32 Smoke Grad","gradient_start":"","gradient_end":""}')
sudo -n mariadb -N ssms -e "SELECT gradient_start FROM mezmur_categories WHERE id=$P32G" | grep -q NULL && ok "clear-to-auto nulls the colors" || fail "clear-to-auto failed"
P32B=$(ssms_api POST mezmur/category '{"id":0,"name":"P32 Bad","gradient_start":"orange"}')
echo "$P32B" | grep -q "Colors must be hex" && ok "invalid color rejected" || fail "invalid color accepted: $P32B"
python3 -c "import struct,zlib
def chunk(t,d):
    c=t+d; return struct.pack('>I',len(d))+c+struct.pack('>I',zlib.crc32(c))
w=h=64
raw=b''.join(b'\x00'+bytes([9,90,160]*w) for _ in range(h))
open('/tmp/p32s.png','wb').write(b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',w,h,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(raw))+chunk(b'IEND',b''))"
curl -s -X POST "$BASE/api/v1/mezmur/category-image" -H "Authorization: Bearer $API_TOKEN" -F "id=$P32G" -F "image=@/tmp/p32s.png;type=image/png" >/dev/null
P32F=$(sudo -n mariadb -N ssms -e "SELECT image_path FROM mezmur_categories WHERE id=$P32G")
P32R=$(ssms_api POST mezmur/category-image-remove '{"id":'"$P32G"'}')
echo "$P32R" | grep -q '"status":"success"' && ok "REST remove-image works" || fail "remove-image: $P32R"
[ -n "$P32F" ] && [ ! -f "${P32F%%\?*#/}" ] && [ ! -f "/${P32F%%\?*}" ] && ok "removed cover file deleted from disk" || fail "cover file survived removal"
P32P=$(ssms_get "/frontend/pages/mezmur_dept.php")
echo "$P32P" | grep -q 'id="mzColorDialog"' && echo "$P32P" | grep -q 'id="mzImageDialog"' && ok "color + preview dialogs served" || fail "P32 dialogs missing"
P32C2=$(curl -s "$BASE/themes/components.css")
echo "$P32C2" | grep -q ".mz-swatch:hover" && echo "$P32C2" | grep -q ".mz-color-preview::after" && ok "hover states + scrim served" || fail "P32 css missing"
sudo -n mariadb --default-character-set=utf8mb4 ssms -e "DELETE FROM mezmur_categories WHERE name LIKE 'P32 Smoke Grad%'; DELETE FROM mezmur_categories WHERE name LIKE 'P32 Bad%'; DELETE FROM activity_logs WHERE details LIKE '%P32%'" >/dev/null 2>&1

# --- 3ae. Gradient alpha + sync cursor bumps (Patch 33) ---------------------
sudo -n mariadb ssms < sql/036_mezmur_gradient_alpha.sql 2>/dev/null
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P33A=$(ssms_api POST mezmur/category '{"id":0,"name":"P33 Smoke A","gradient_start":"#0ea5e9","gradient_end":"#2563eb80"}')
echo "$P33A" | grep -q '"status":"success"' && ok "8-digit alpha color accepted (opacity)" || fail "alpha color rejected: $P33A"
P33I=$(ssms_api POST mezmur/category '{"id":0,"name":"P33 Smoke B","gradient_start":"#0ea5e91234"}')
echo "$P33I" | grep -q '"status":"error"' && ok "malformed color rejected" || fail "malformed color accepted: $P33I"
P33ID=$(echo "$P33A" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
A_TS=$(sudo -n mariadb -N ssms -e "SELECT updated_at FROM mezmur_categories WHERE id=$P33ID")
sleep 1.1
ssms_api POST mezmur/category '{"id":'"$P33ID"',"name":"P33 Smoke A","gradient_start":"#059669","gradient_end":"#0d9488"}' >/dev/null
B_TS=$(sudo -n mariadb -N ssms -e "SELECT updated_at FROM mezmur_categories WHERE id=$P33ID")
[ "$A_TS" != "$B_TS" ] && ok "gradient edit bumps sync cursor" || fail "gradient edit did not bump updated_at"
P33H=$(ssms_get "/frontend/pages/mezmur_dept.php")
echo "$P33H" | grep -q 'id="mzGradStartOp"' && ok "opacity sliders served" || fail "opacity sliders missing"
sudo -n mariadb --default-character-set=utf8mb4 ssms -e "DELETE FROM mezmur_categories WHERE name LIKE 'P33 Smoke%'; DELETE FROM activity_logs WHERE details LIKE '%P33%'" >/dev/null 2>&1

# --- 3be. P34: singer covers + collapsed catalog + one filter row ------------
sudo -n mariadb ssms < sql/037_zemarian_images.sql 2>/dev/null
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P34Z=$(ssms_api POST mezmur/zemarian '{"id":0,"name":"P34 Smoke Singer"}')
echo "$P34Z" | grep -q '"status":"success"' && ok "singer created" || fail "singer create: $P34Z"
P34ZID=$(echo "$P34Z" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
python3 -c "import struct,zlib
def chunk(t,d):
    c=t+d; return struct.pack('>I',len(d))+c+struct.pack('>I',zlib.crc32(c))
w=h=64; raw=b''.join(b'\x00'+bytes([9,90,160]*w) for _ in range(h))
open('/tmp/p34s.png','wb').write(b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',w,h,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(raw))+chunk(b'IEND',b''))"
P34UP=$(curl -s -X POST "$BASE/api/v1/mezmur/zemarian-image" -H "Authorization: Bearer $API_TOKEN" -F "id=$P34ZID" -F "image=@/tmp/p34s.png;type=image/png")
echo "$P34UP" | grep -q '"saved":true' && echo "$P34UP" | grep -q 'mezmur_zemarians' && ok "singer cover uploaded (own dir)" || fail "singer upload: $P34UP"
P34LIST=$(ssms_api GET "mezmur/zemarians")
echo "$P34LIST" | grep -q 'P34 Smoke Singer' && echo "$P34LIST" | python3 -c "
import sys, json
d = json.load(sys.stdin)
z = [i for i in d['data']['items'] if i['name'] == 'P34 Smoke Singer'][0]
assert z.get('image_url', '').startswith('/uploads/mezmur_zemarians/'), z
print('singer list serves image_url')" >/dev/null && ok "singer list serves image_url" || fail "image_url missing from singer list"
P34HT=$(ssms_get "/uploads/mezmur_zemarians/$(sudo -n mariadb -N ssms -e "SELECT image_path FROM mezmur_zemarians WHERE id=$P34ZID" | sed 's|.*/||;s|?.*||')")
[ -n "$P34HT" ] && echo "$P34HT" | grep -q "PNG" && ok "cover image file served" || fail "cover file not served"
P34RM=$(ssms_api POST mezmur/zemarian-image-remove '{"id":'"$P34ZID"'}')
echo "$P34RM" | grep -q '"status":"success"' && ok "singer cover removed" || fail "singer remove: $P34RM"
P34LEFT=$(ls uploads/mezmur_zemarians/ 2>/dev/null | grep -vc htaccess)
[ "$P34LEFT" = "0" ] && ok "removed cover file deleted from disk" || fail "$P34LEFT file(s) survived removal"
P34PG=$(ssms_get "/frontend/pages/mezmur_dept.php")
echo "$P34PG" | grep -q 'id="mzZemarianFilter"' && ok "library singer filter served" || fail "singer filter missing"
echo "$P34PG" | grep -q 'id="mzBrowse"' && fail "old duplicate browse markup still served" || ok "duplicate tab/chip browse removed"
P34JS=$(curl -s "$BASE/frontend/js/mezmur.js")
echo "$P34JS" | grep -q 'mz-cmgr-exp' && echo "$P34JS" | grep -q 'mgrToggleOpen' && ok "collapsed catalog manager served" || fail "collapse control missing"
P34CSS=$(curl -s "$BASE/themes/components.css")
echo "$P34CSS" | grep -q 'mz-cmgr-exp.open i' && ok "chevron animation css served" || fail "chevron css missing"
sudo -n mariadb --default-character-set=utf8mb4 ssms -e "DELETE FROM mezmur_zemarians WHERE name LIKE 'P34 Smoke%'; DELETE FROM activity_logs WHERE details LIKE '%P34%'" >/dev/null 2>&1

# --- 3bf. P35: Amharic-only singer names + singer-filter dropdown fix --------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null
P35Z=$(ssms_api POST mezmur/zemarian '{"id":0,"name":"P35 የሙከራ ዘማሪያን"}')
P35ZID=$(echo "$P35Z" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
P35M=$(sudo -n mariadb -N ssms -e "SELECT name = name_am AND name_am IS NOT NULL FROM mezmur_zemarians WHERE id=$P35ZID")
[ "$P35M" = "1" ] && ok "name_am mirrors the single Amharic name (create)" || fail "name_am not mirrored on create"
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api POST mezmur/zemarian '{"id":'"$P35ZID"',"name":"P35 የተሻሻለ ስም"}' >/dev/null
P35M2=$(sudo -n mariadb --default-character-set=utf8mb4 -N ssms -e "SELECT name = 'P35 የተሻሻለ ስም' AND name = name_am FROM mezmur_zemarians WHERE id=$P35ZID")
[ "$P35M2" = "1" ] && ok "name_am mirrors on rename too" || fail "name_am not mirrored on rename"
P35PG=$(ssms_get "/frontend/pages/mezmur_dept.php")
echo "$P35PG" | grep -q 'mzMgrZemName" class="school-input amharic"' && ok "single Amharic singer input served" || fail "singer input wrong"
echo "$P35PG" | grep -q 'mzMgrZemNameAm' && fail "old twin name field still served" || ok "twin name field removed"
P35JS=$(curl -s "$BASE/frontend/js/mezmur.js")
node tests/e2e/filter_behavior_test.js && ok "filter state machine behavioral test (dropdowns populate, no silent changes)" || fail "filter behavior test failed"
P35L=$(ssms_get "/admin/api_mezmur.php?action=list&page=1&per_page=25&search=&category=&length=&language=&category_id=&zemarian_id=$P35ZID&status=active")
echo "$P35L" | python3 -c "
import sys, json
d = json.load(sys.stdin)
assert d['status'] == 'success', d
assert all(any(z['id'] == $P35ZID for z in h.get('zemarians', [])) for h in d['items']), 'unfiltered row present'
print('ok')" >/dev/null && ok "admin list filters by singer id" || fail "singer filter leak/broken"
sudo -n mariadb --default-character-set=utf8mb4 ssms -e "DELETE FROM mezmur_hymn_zemarians WHERE zemarian_id=$P35ZID; DELETE FROM mezmur_zemarians WHERE id=$P35ZID; DELETE FROM activity_logs WHERE details LIKE '%P35%'" >/dev/null 2>&1

# --- 3bg. P36 deep audit: honest "All" status + singer endpoint matrix ------
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api_login audit_super >/dev/null 2>&1; ssms_login audit_super >/dev/null 2>&1
P36RAW=$(ssms_api POST mezmur/zemarian '{"id":0,"name":"P36 Matrix Singer"}'); echo "$P36RAW" > /tmp/p36debug.log
P36Z2=$(echo "$P36RAW" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])' 2>/dev/null)
P36H1=$(ssms_api POST mezmur/hymn '{"id":0,"title":"P36 Matrix On","zemarians":['"$P36Z2"'],"status":"active"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
P36H2=$(ssms_api POST mezmur/hymn '{"id":0,"title":"P36 Matrix Off","zemarians":['"$P36Z2"'],"status":"active"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["item"]["id"])')
sudo -n mariadb ssms -e "DELETE FROM security_rate_limits" >/dev/null 2>&1
ssms_api POST mezmur/hymn-status '{"id":'"$P36H2"',"status":"archived"}' >/dev/null
P36ALL=$(ssms_get "/admin/api_mezmur.php?action=list&search=P36+Matrix&zemarian_id=$P36Z2&status=")
P36ACT=$(ssms_get "/admin/api_mezmur.php?action=list&search=P36+Matrix&zemarian_id=$P36Z2&status=active")
echo "$P36ALL" | grep -q "P36 Matrix On" && echo "$P36ALL" | grep -q "P36 Matrix Off" && ok "status='' is a TRUE all (archived included)" || fail "status='' hides archived: $P36ALL"
echo "$P36ACT" | grep -q "P36 Matrix On" && echo "$P36ACT" | grep -vq "P36 Matrix Off" && ok "status=active excludes archived" || fail "active filter wrong: $P36ACT"
P36R=$(curl -s -H "Authorization: Bearer $API_TOKEN" "$BASE/api/v1/index.php?_route=mezmur/hymns&zemarian_id=$P36Z2&status=active&search=P36%20Matrix")
echo "$P36R" | grep -q "P36 Matrix On" && echo "$P36R" | grep -vq "P36 Matrix Off" && ok "REST singer filter parity" || fail "REST singer filter: $P36R"
P36HID=$(ssms_api POST mezmur/zemarian '{"id":'"$P36Z2"',"name":"P36 Matrix Singer"}')
echo "$P36HID" | grep -q '"id":' && ok "duplicate singer create stays idempotent" || fail "dup create: $P36HID"
P36CNT=$(sudo -n mariadb -N ssms -e "SELECT COUNT(*) FROM mezmur_zemarians WHERE name='P36 Matrix Singer'")
[ "$P36CNT" = "1" ] && ok "idempotent create made no duplicate row" || fail "dup row created: $P36CNT"
sudo -n mariadb --default-character-set=utf8mb4 ssms -e "DELETE hz FROM mezmur_hymn_zemarians hz JOIN mezmur_hymns h ON h.id=hz.hymn_id WHERE h.title LIKE 'P36 Matrix%'; DELETE FROM mezmur_hymn_words WHERE hymn_id IN (SELECT id FROM mezmur_hymns WHERE title LIKE 'P36 Matrix%'); DELETE FROM mezmur_hymns WHERE title LIKE 'P36 Matrix%'; DELETE FROM mezmur_zemarians WHERE name='P36 Matrix Singer'; DELETE FROM activity_logs WHERE details LIKE '%P36 Matrix%'" >/dev/null 2>&1

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
