"""Live API test — score limit + "Did not take" (Phase 1, 2026-10-22).

Runs against a LOCAL test server only (default http://127.0.0.1:8080, DB
ssms_test). It creates its own assessments (class 1 / subject 1, out of 10)
and removes them, their marks and packets at the end.

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/score_limit_api.py [base_url]
"""
import os, re, sys, json, subprocess
import requests

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: set SSMS_AUDIT_TESTING=1 (local disposable test DB only).')
BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
if 'felegekidusan' in BASE:
    sys.exit('Refusing to run against production.')
PW = 'AuditTest#2026'
PASS = FAIL = 0
ROSTER = [900000, 900001, 900002, 900006]  # class 1, year 1 (seed)


def ck(name, cond, info=''):
    global PASS, FAIL
    if cond:
        PASS += 1; print('PASS', name)
    else:
        FAIL += 1; print('FAIL', name, '::', str(info)[:400])


def sql(q):
    r = subprocess.run(['mysql', '-ussms', '-pssms', '-h127.0.0.1', 'ssms_test', '-N', '-B',
                        '--default-character-set=utf8mb4', '-e', q], capture_output=True, text=True)
    return r.stdout.strip()


def login(user, page):
    s = requests.Session()
    h = s.get(BASE + '/admin/index.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
    s.post(BASE + '/admin/backend/login.php', data={'username': user, 'password': PW, 'csrf_token': tok}, allow_redirects=False)
    h = s.get(BASE + page).text
    m = re.search(r"CSRF(?:_TOKEN)?\s*=\s*'([a-f0-9]{20,})'", h)
    s.csrf = m.group(1) if m else ''
    return s


def jpost(s, url, **data):
    data.setdefault('csrf_token', s.csrf)
    r = s.post(BASE + url, data=data)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {'raw': r.text[:300]}


def jget(s, url):
    r = s.get(BASE + url)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {'raw': r.text[:300]}


def v1_token(user):
    r = requests.post(BASE + '/api/v1/auth/login', json={'username': user, 'password': PW, 'device_id': 'score-limit-test'})
    d = r.json()
    t = d.get('access_token') or d.get('token') or (d.get('data') or {}).get('token') or (d.get('data') or {}).get('access_token')
    return t


def v1(tok, method, path, body=None, params=None):
    r = requests.request(method, BASE + '/api/v1' + path, json=body, params=params,
                         headers={'Authorization': 'Bearer ' + tok})
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {'raw': r.text[:300]}


def mk_assessment(name, mx=10):
    sql("INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, "
        f"weight_percentage, max_score, created_by) VALUES (1,1,1,1,'{name}','test',0,{mx},2)")
    return int(sql(f"SELECT id FROM assessments WHERE assessment_name='{name}' ORDER BY id DESC LIMIT 1"))


def recs(aid):
    return sql(f"SELECT member_id, score FROM academic_records WHERE assessment_id={aid} ORDER BY member_id")


def packet(aid):
    return sql(f"SELECT status FROM grade_submissions WHERE assessment_id={aid} AND submission_type='marklist' ORDER BY id DESC LIMIT 1")


def cleanup():
    ids = sql("SELECT GROUP_CONCAT(id) FROM assessments WHERE assessment_name LIKE 'SL test%'")
    if ids and ids != 'NULL':
        sql(f"DELETE FROM attendance_submission_events WHERE packet_id IN (SELECT id FROM grade_submissions WHERE assessment_id IN ({ids}))")
        sql(f"DELETE FROM grade_submissions WHERE assessment_id IN ({ids})")
        sql(f"DELETE FROM academic_records WHERE assessment_id IN ({ids})")
        sql(f"DELETE FROM assessments WHERE id IN ({ids})")
    sql("DELETE FROM security_rate_limits")


cleanup()
A = mk_assessment('SL test quiz')       # submitted path
B = mk_assessment('SL test draft')      # stays a draft
ck('setup: two out-of-10 assessments', A > 0 and B > 0, (A, B))

# ── 1. mobile /grades/save + /grades/submit refuse invalid scores ─────────
tok = v1_token('audit_teach')
ck('teacher mobile token', bool(tok))
c, d = v1(tok, 'POST', '/grades/save', {'assessment_id': A, 'grades': [
    {'member_id': ROSTER[0], 'score': 8}, {'member_id': ROSTER[1], 'score': 15}]})
ck('v1 save 15/10 -> 422 INVALID_SCORES', c == 422 and d.get('code') == 'INVALID_SCORES', (c, d))
ck('v1 save refusal names the student + max', '15 is more than the maximum 10' in (d.get('message') or ''), d.get('message'))
ck('v1 save refusal lists the row', any(x.get('member_id') == ROSTER[1] and x.get('reason') == 'over_max' for x in d.get('invalid') or []), d.get('invalid'))
ck('v1 save refused -> NOTHING saved (not even the valid 8)', recs(A) == '', recs(A))
ck('v1 save refused -> no packet', packet(A) == '', packet(A))

for bad in ['abc', '-1', '9.755', '1e1', '10.5']:
    c, d = v1(tok, 'POST', '/grades/submit', {'assessment_id': A, 'grades': [
        {'member_id': ROSTER[0], 'score': 8}, {'member_id': ROSTER[2], 'score': bad}]})
    ck(f'v1 submit "{bad}" -> 422', c == 422 and d.get('code') == 'INVALID_SCORES', (c, d))
ck('v1 submit refused -> nothing saved, not locked', recs(A) == '' and packet(A) == '', (recs(A), packet(A)))

c, d = v1(tok, 'POST', '/grades/save', {'assessment_id': A, 'grades': [
    {'member_id': ROSTER[0], 'score': 8}, {'member_id': ROSTER[1], 'score': '9.5'}, {'member_id': ROSTER[2], 'score': ''}]})
ck('v1 save valid (8, 9.5, blank) -> 200', c == 200 and d.get('status') == 'success', (c, d))
ck('v1 valid save stored the 2 real marks (8, 9.5)', sql(f"SELECT GROUP_CONCAT(score ORDER BY member_id) FROM academic_records WHERE assessment_id={A} AND score IS NOT NULL") == '8.00,9.50', recs(A))
ck('packet is draft', packet(A) == 'draft', packet(A))
c, d = v1(tok, 'POST', '/grades/save', {'assessment_id': A, 'grades': [{'member_id': ROSTER[0], 'score': 10}]})
ck('v1 exactly the max (10) is allowed', c == 200, (c, d))

# ── 2. web teacher save / submit ─────────────────────────────────────────
t = login('audit_teach', '/admin/dashboards/teacher.php')
ck('teacher web login', bool(t.csrf))
c, d = jpost(t, '/admin/api_subjects.php', action='save_grades', assessment_id=A,
             grades=json.dumps([{'member_id': ROSTER[0], 'score': '11'}]))
ck('web save_grades 11/10 -> 422', c == 422 and d.get('code') == 'INVALID_SCORES', (c, d))
ck('web save refused -> old mark (10) kept', f'{ROSTER[0]}\t10.00' in recs(A), recs(A))
c, d = jpost(t, '/admin/api_communication.php', action='submit_marklist', class_id=1, subject_id=1, assessment_id=A,
             grades=json.dumps([{'member_id': ROSTER[0], 'score': '8'}, {'member_id': ROSTER[1], 'score': '12'}]))
ck('web submit_marklist with 12/10 -> 422', c == 422 and d.get('code') == 'INVALID_SCORES', (c, d))
ck('web submit refused -> still a draft', packet(A) == 'draft', packet(A))
c, d = jpost(t, '/admin/api_communication.php', action='submit_marklist', class_id=1, subject_id=1, assessment_id=A,
             grades=json.dumps([{'member_id': ROSTER[0], 'score': '8'}, {'member_id': ROSTER[1], 'score': '9.5'},
                                {'member_id': ROSTER[2], 'score': ''}, {'member_id': ROSTER[3], 'score': ''}]))
ck('web submit valid (2 scored, 2 blank) -> success', d.get('status') == 'success', (c, d))
ck('packet now submitted', packet(A) == 'submitted', packet(A))

# ── 3. Education review lists EVERY student; blanks = Did not take ───────
e = login('audit_edu', '/admin/dashboards/edu_dept.php')
pid = int(sql(f"SELECT id FROM grade_submissions WHERE assessment_id={A} AND submission_type='marklist' ORDER BY id DESC LIMIT 1") or 0)
c, d = jget(e, f'/admin/api_communication.php?action=get_submission_detail&id={pid}')
sub = d.get('submission') or {}
rows = sub.get('rows') or []
ck('review: all 4 enrolled students listed (was 2)', len(rows) == 4, len(rows))
nt = [r for r in rows if r.get('score_state') == 'not_taken']
ck('review: 2 rows "Did not take"', len(nt) == 2 and all(r.get('score_label') == 'Did not take' for r in nt), rows)
ck('review: blank rows are the 2 blank students', sorted(r['member_id'] for r in nt) == sorted(ROSTER[2:]), nt)
ck('review: counts scored=2 not_taken=2', sub.get('scored_count') == 2 and sub.get('not_taken_count') == 2, sub)
ck('review: blank_label', sub.get('blank_label') == 'Did not take', sub.get('blank_label'))
etok = v1_token('audit_edu')
c, d = v1(etok, 'GET', '/grades/submission', params={'id': pid})
mrows = ((d.get('data') or d).get('submission') or {}).get('rows') or []
ck('mobile review detail also lists 4 with not_taken', len(mrows) == 4 and sum(1 for r in mrows if r.get('score_state') == 'not_taken') == 2, (c, len(mrows)))

# draft packet: blanks are "Not entered yet", not "Did not take"
c, d = v1(tok, 'POST', '/grades/save', {'assessment_id': B, 'grades': [{'member_id': ROSTER[0], 'score': 7}]})
pidB = int(sql(f"SELECT id FROM grade_submissions WHERE assessment_id={B} AND submission_type='marklist' ORDER BY id DESC LIMIT 1") or 0)
c, d = jget(e, f'/admin/api_communication.php?action=get_submission_detail&id={pidB}')
subB = d.get('submission') or {}
ne = [r for r in subB.get('rows') or [] if r.get('score_state') == 'not_entered']
ck('draft review: 3 "Not entered yet", none "Did not take"', len(ne) == 3 and subB.get('not_taken_count') == 0 and subB.get('blank_label') == 'Not entered yet', subB)

# ── 4. max cannot be lowered below a saved score ─────────────────────────
base = dict(action='update_assessment', assessment_id=A, assessment_name='SL test quiz', assessment_type='test',
            weight_percentage='5', weight='5', description='', due_date='', term_id='1')
c, d = jpost(e, '/admin/api_subjects.php', max_score='5', **base)
ck('lower max 10 -> 5 below saved 9.5 -> 422', c == 422 and d.get('code') == 'MAX_BELOW_SAVED_SCORES', (c, d))
ck('max unchanged (10)', sql(f"SELECT max_score FROM assessments WHERE id={A}") == '10.00', sql(f"SELECT max_score FROM assessments WHERE id={A}"))
c, d = jpost(e, '/admin/api_subjects.php', max_score='20', **base)
ck('raise max 10 -> 20 is allowed', d.get('status') == 'success', (c, d))
jpost(e, '/admin/api_subjects.php', max_score='10', **base)
ck('max back to 10', sql(f"SELECT max_score FROM assessments WHERE id={A}") == '10.00')

# ── 5. report card: Did not take listed, NOT counted (owner D1) ──────────
c, d = jget(e, '/admin/api_communication.php?action=get_class_cards&class_id=1&year_id=1&term_id=1')
cards = d.get('cards') or d.get('students') or []
def card_for(mid):
    for cd in cards:
        st = cd.get('student') or cd
        if int(st.get('member_id') or st.get('id') or 0) == mid:
            return cd
    return None
c2 = card_for(ROSTER[2])
subj = None
if c2:
    for s_ in c2.get('subjects') or []:
        if int(s_.get('id') or 0) == 1:
            subj = s_
asm = [a for a in (subj or {}).get('assessments') or [] if a.get('assessment_name') == 'SL test quiz']
ck('card (blank student): SL test quiz listed as not_taken', len(asm) == 1 and asm[0].get('not_taken') == 1 and asm[0].get('score') is None, asm or list((c2 or {}).keys()))
c0 = card_for(ROSTER[0])
s0 = next((s_ for s_ in (c0 or {}).get('subjects') or [] if int(s_.get('id') or 0) == 1), {})
a0 = [a for a in s0.get('assessments') or [] if a.get('assessment_name') == 'SL test quiz']
ck('card (scored student): score 8, not_taken 0', len(a0) == 1 and float(a0[0].get('score')) == 8.0 and a0[0].get('not_taken') == 0, a0)
# not counted: blank student's subject max must not include the 10 points
ck('card: blank test adds 0 to the max (not counted)', (subj or {}).get('max') in (None, 100.0, 100) or float((subj or {}).get('max') or 0) == float(sql(f"SELECT COALESCE(SUM(a.max_score),0) FROM academic_records r JOIN assessments a ON a.id=r.assessment_id WHERE r.member_id={ROSTER[2]} AND a.class_id=1 AND a.subject_id=1 AND r.score IS NOT NULL") or 0), subj)

# ── 6. student profile assessments tab ───────────────────────────────────
c, d = jget(e, f'/admin/api_education.php?action=tracking_student_assessments&member_id={ROSTER[3]}&class_id=1&year_id=1')
items = d.get('assessments') or d.get('rows') or (d.get('data') or {}).get('assessments') or []
row = [x for x in items if x.get('assessment_name') == 'SL test quiz']
ck('profile tab: not_taken true for blank student', len(row) == 1 and row[0].get('not_taken') is True, (c, row or list(d.keys())))
row = [x for x in items if x.get('assessment_name') == 'SL test draft']
ck('profile tab: draft blank is NOT "did not take" (absent or false)', len(row) == 0 or row[0].get('not_taken') is False, row)

cleanup()
ck('cleanup: test assessments removed', sql("SELECT COUNT(*) FROM assessments WHERE assessment_name LIKE 'SL test%'") == '0')
print(f'\nScore limit API: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
