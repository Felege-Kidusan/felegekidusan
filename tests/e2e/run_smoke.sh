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
sudo -n mariadb ssms -e "DELETE FROM grade_submissions WHERE teacher_id=$H3T; DELETE FROM teacher_assignments WHERE teacher_id=$H3T; DELETE FROM users WHERE id=$H3T" >/dev/null 2>&1

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
