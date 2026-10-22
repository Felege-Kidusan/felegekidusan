"""Phase 2 (2026-10-22) — private result card API + web View-only lock.

LOCAL test server only. Creates its own tests in class 1 / subject 1 and
removes them at the end.

  API  v1 GET /grades/student-results and web api_subjects.php?action=student_results:
       states scored / not_taken / not_entered, totals exclude "Did not take"
       (owner D1), only this student's data, permissions (teacher assigned,
       not assigned -> 403, other class -> 404, Education allowed).
  WEB  teacher Grade entry + Submit marks: View-only toggle locks boxes and
       Save, banner, search, remembered after reload, Save refused, click a
       student -> private result card with other results, unlock restores
       each box's own state (a locked box stays locked).

usage: SSMS_AUDIT_TESTING=1 python3 -u tests/e2e/view_only_results.py [base] [--shots DIR]
"""
import os, re, sys, subprocess
import requests
from playwright.sync_api import sync_playwright

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: set SSMS_AUDIT_TESTING=1 (local disposable test DB only).')
av = sys.argv[1:]
SHOTS = None
if '--shots' in av:
    i = av.index('--shots'); SHOTS = av[i + 1]; av = av[:i] + av[i + 2:]
    os.makedirs(SHOTS, exist_ok=True)
BASE = (av[0] if av else 'http://127.0.0.1:8080').rstrip('/')
if 'felegekidusan' in BASE:
    sys.exit('Refusing to run against production.')
PW = 'AuditTest#2026'
PASS = FAIL = 0
ROSTER = [900000, 900001, 900002, 900006]


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


def shot(page, name):
    if SHOTS:
        page.wait_for_timeout(400)
        page.screenshot(path=os.path.join(SHOTS, name + '.png'))


def v1_token(user):
    d = requests.post(BASE + '/api/v1/auth/login', json={'username': user, 'password': PW, 'device_id': 'vo-test'}).json()
    return (d.get('data') or {}).get('token')


def v1(tok, method, path, body=None, params=None):
    r = requests.request(method, BASE + '/api/v1' + path, json=body, params=params, headers={'Authorization': 'Bearer ' + tok})
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, {'raw': r.text[:300]}


def web_session(user):
    s = requests.Session()
    h = s.get(BASE + '/admin/index.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
    s.post(BASE + '/admin/backend/login.php', data={'username': user, 'password': PW, 'csrf_token': tok}, allow_redirects=False)
    return s


def mk(name, mx, cls=1, subj=1):
    sql("INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, "
        f"weight_percentage, max_score, created_by) VALUES ({cls},{subj},1,1,'{name}','test',5,{mx},2)")
    return int(sql(f"SELECT id FROM assessments WHERE assessment_name='{name}' ORDER BY id DESC LIMIT 1"))


def cleanup():
    ids = sql("SELECT GROUP_CONCAT(id) FROM assessments WHERE assessment_name LIKE 'VO test%'")
    if ids and ids != 'NULL':
        sql(f"DELETE FROM attendance_submission_events WHERE packet_id IN (SELECT id FROM grade_submissions WHERE assessment_id IN ({ids}))")
        sql(f"DELETE FROM grade_submissions WHERE assessment_id IN ({ids})")
        sql(f"DELETE FROM academic_records WHERE assessment_id IN ({ids})")
        sql(f"DELETE FROM assessments WHERE id IN ({ids})")
    sql("DELETE FROM security_rate_limits")


cleanup()
A = mk('VO test quiz', 10)    # submitted: 900000=8, 900001=10, others blank -> Did not take
B = mk('VO test mid', 20)     # submitted: only 900001=15 -> 900000 Did not take
C = mk('VO test homework', 5)  # nothing -> Not entered yet
D = mk('VO test other class', 10, cls=3, subj=2)  # audit_teach is NOT assigned
tok = v1_token('audit_teach')
c1, d1 = v1(tok, 'POST', '/grades/submit', {'assessment_id': A, 'grades': [{'member_id': 900000, 'score': 8}, {'member_id': 900001, 'score': 10}]})
c2, d2 = v1(tok, 'POST', '/grades/submit', {'assessment_id': B, 'grades': [{'member_id': 900001, 'score': 15}]})
ck('setup: two lists submitted', c1 == 200 and c2 == 200, (c1, d1, c2, d2))

# ── API: v1 student-results ───────────────────────────────────────────────
c, d = v1(tok, 'GET', '/grades/student-results', params={'assessment_id': A, 'member_id': 900000})
ck('v1 teacher (assigned) -> 200', c == 200, (c, d))
data = d.get('data') or {}
res = {r['assessment_id']: r for r in data.get('results', [])}
ck('v1 lists this class+subject+semester tests', all(x in res for x in (A, B, C)), list(res))
ck('v1 never lists another class', D not in res)
ck('v1 current test scored 8 / 10', res.get(A, {}).get('score_state') == 'scored' and res[A]['score'] == 8 and res[A]['is_current'] is True, res.get(A))
ck('v1 blank on a submitted list = not_taken "Did not take"', res.get(B, {}).get('score_state') == 'not_taken' and res[B]['score_label'] == 'Did not take', res.get(B))
ck('v1 no list yet = not_entered', res.get(C, {}).get('score_state') == 'not_entered', res.get(C))
t = data.get('totals') or {}
scored = [r for r in data.get('results', []) if r['score_state'] == 'scored']
ck('v1 totals count scored tests only (Did not take not counted)',
   abs(t.get('max', -1) - sum(r['max_score'] for r in scored)) < 0.01 and abs(t.get('score', -1) - sum(r['score'] for r in scored)) < 0.01
   and t.get('not_taken') == 1, t)
ck('v1 only this student (no other names)', set(data.keys()) == {'student', 'subject', 'results', 'totals'}
   and data['student']['member_id'] == 900000 and 'ብርሃኑ' not in str(data), list(data.keys()))
c, d = v1(tok, 'GET', '/grades/student-results', params={'assessment_id': B, 'member_id': 900001})
r1 = {r['assessment_id']: r for r in (d.get('data') or {}).get('results', [])}
ck('v1 other student: 15 / 20 scored on the mid', r1.get(B, {}).get('score') == 15 and r1[B]['is_current'], r1.get(B))
c, d = v1(tok, 'GET', '/grades/student-results', params={'assessment_id': D, 'member_id': 900000})
ck('v1 teacher NOT assigned to that class/subject -> 403', c == 403, (c, d))
other = sql("SELECT member_id FROM class_enrollments WHERE class_id=2 LIMIT 1")
c, d = v1(tok, 'GET', '/grades/student-results', params={'assessment_id': A, 'member_id': other})
ck('v1 student from another class -> 404', c == 404, (c, d))
c, d = v1(tok, 'GET', '/grades/student-results', params={'assessment_id': A})
ck('v1 missing member_id -> 422', c == 422, (c, d))
c, d = v1(v1_token('audit_edu'), 'GET', '/grades/student-results', params={'assessment_id': D, 'member_id': sql("SELECT member_id FROM class_enrollments WHERE class_id=3 LIMIT 1") or 900000})
ck('v1 Education is not limited to assignments (200 or 404 for no enrolment, never 403)', c in (200, 404), (c, d))

# ── API: web action ───────────────────────────────────────────────────────
s = web_session('audit_teach')
r = s.get(BASE + f'/admin/api_subjects.php?action=student_results&assessment_id={A}&member_id=900000')
j = r.json() if r.headers.get('content-type', '').startswith('application/json') or r.text.startswith('{') else {}
ck('web teacher -> success with the same rows', r.status_code == 200 and j.get('status') == 'success' and len(j.get('results', [])) == len(res), (r.status_code, r.text[:200]))
r = s.get(BASE + f'/admin/api_subjects.php?action=student_results&assessment_id={D}&member_id=900000')
ck('web teacher NOT assigned -> refused', r.status_code in (403,) or '"error"' in r.text, (r.status_code, r.text[:200]))
r = s.get(BASE + f'/admin/api_subjects.php?action=student_results&assessment_id={A}&member_id={other}')
ck('web student from another class -> 404', r.status_code == 404, (r.status_code, r.text[:200]))
anon = requests.get(BASE + f'/admin/api_subjects.php?action=student_results&assessment_id={A}&member_id=900000')
ck('web not signed in -> refused', anon.status_code in (401, 403, 302) or 'success' not in anon.text, (anon.status_code, anon.text[:120]))

# ── WEB: View-only lock on the teacher screens ───────────────────────────
with sync_playwright() as p:
    br = p.chromium.launch()
    ctx = br.new_context(viewport={'width': 1366, 'height': 900})
    page = ctx.new_page()
    errs = []
    page.on('pageerror', lambda e: errs.append(str(e)[:200]))
    page.on('dialog', lambda dl: (errs.append('native ' + dl.type), dl.dismiss()))
    page.goto(BASE + '/admin/index.php')
    page.fill('#username', 'audit_teach'); page.fill('#password', PW)
    page.click('button[type=submit]'); page.wait_for_load_state('networkidle')
    page.goto(BASE + '/admin/dashboards/teacher.php'); page.wait_for_load_state('networkidle')

    # Grade entry for C (open draft list -> boxes editable)
    def open_entry(aid):
        page.evaluate("(a)=>{showSection('grades'); const s=document.getElementById('gradeAssessmentSelect'); "
                      "if(![...s.options].some(o=>o.value==a)){const o=document.createElement('option'); o.value=a; o.textContent='x'; s.appendChild(o);} "
                      "s.value=a; loadStudentsForGrading(); return 1}", str(aid))
        page.wait_for_selector('#gradeEntryBody .grade-input', timeout=8000)
        page.wait_for_selector('#gradeEntryCard [data-fk-vo-toggle]', timeout=8000)
        page.wait_for_timeout(300)

    page.evaluate("()=>{localStorage.clear(); return 1}")
    open_entry(C)
    tog = page.locator('#gradeEntryCard [data-fk-vo-toggle]')
    ck('WEB toggle shown, off by default', tog.get_attribute('aria-pressed') == 'false' and not page.locator('#gradeEntryBody .grade-input').first.is_disabled())
    # one box pre-locked (stands in for a closed-semester / submitted box)
    page.evaluate("()=>{document.querySelectorAll('#gradeEntryBody .grade-input')[3].disabled=true; return 1}")
    tog.click()
    st = page.evaluate("""()=>({
        boxes:[...document.querySelectorAll('#gradeEntryBody .grade-input, #gradeEntryBody .remarks-input')].every(i=>i.disabled),
        save:document.getElementById('saveGradesBtn').disabled,
        banner:getComputedStyle(document.querySelector('#gradeEntryCard [data-fk-vo-banner]')).display!=='none',
        search:getComputedStyle(document.querySelector('#gradeEntryCard [data-fk-vo-search]')).display!=='none',
        pressed:document.querySelector('#gradeEntryCard [data-fk-vo-toggle]').getAttribute('aria-pressed'),
        stored:localStorage.getItem('fkss_view_only_%s')})""" % C)
    ck('WEB View only: every score + remark box locked', st['boxes'], st)
    ck('WEB View only: Save disabled, banner + search shown, remembered', st['save'] and st['banner'] and st['search'] and st['pressed'] == 'true' and st['stored'] == '1', st)
    shot(page, 'W1_view_only_on')
    page.evaluate("()=>{saveAllGrades(); return 1}")
    page.wait_for_timeout(400)
    toast = page.evaluate("()=>document.body.innerText.includes('View only is on')")
    ck('WEB Save refused while View only is on (toast)', toast)
    page.fill('#gradeEntryCard [data-fk-vo-search]', 'FK-T0002')
    vis = page.evaluate("()=>[...document.querySelectorAll('#gradeEntryBody tr')].filter(t=>t.style.display!=='none').length")
    ck('WEB search finds a student by code', vis == 1, vis)
    page.fill('#gradeEntryCard [data-fk-vo-search]', '')

    # result card from the Grade entry list of A (900000 = 8 / 10)
    open_entry(A)
    page.locator('#gradeEntryCard [data-fk-vo-toggle]').click()
    row = page.locator('#gradeEntryBody tr', has=page.locator('.grade-input[data-member-id="900000"]'))
    row.locator('td').nth(1).click()
    page.wait_for_selector('.fk-rc [data-fk-rc-row]', timeout=8000)
    card = page.evaluate("""()=>({score:(document.querySelector('.fk-rc [data-fk-rc-score]')||{}).textContent,
        rows:document.querySelectorAll('.fk-rc [data-fk-rc-row]').length,
        dnt:[...document.querySelectorAll('.fk-rc .fk-rc-chip.dnt')].map(e=>e.textContent),
        ne:[...document.querySelectorAll('.fk-rc .fk-rc-chip.ne')].map(e=>e.textContent),
        total:(document.querySelector('.fk-rc [data-fk-rc-total]')||{}).textContent||'',
        note:document.querySelector('.fk-rc').innerText.includes('Only this student'),
        other:document.querySelector('.fk-rc').innerText.includes('ብርሃኑ')})""")
    ck('WEB result card: big mark 8', (card['score'] or '').strip() == '8', card)
    ck('WEB result card: other results with Did not take + Not entered yet', card['rows'] >= 3 and card['dnt'] == ['Did not take'] and 'Not entered yet' in card['ne'], card)
    ck('WEB result card: marks so far + privacy note, no other student', card['total'] != '' and card['note'] and not card['other'], card)
    shot(page, 'W2_result_card')
    page.keyboard.press('Escape')
    page.wait_for_timeout(200)
    ck('WEB Escape closes the card', page.locator('.fk-rc-back').count() == 0)
    page.locator('#gradeEntryCard [data-fk-vo-toggle]').click()

    # remembered after reload; unlock restores each box's own state
    page.reload(); page.wait_for_load_state('networkidle')
    open_entry(C)
    ck('WEB View only remembered after reload', page.locator('#gradeEntryCard [data-fk-vo-toggle]').get_attribute('aria-pressed') == 'true'
       and page.locator('#gradeEntryBody .grade-input').first.is_disabled())
    page.evaluate("()=>{const b=document.querySelectorAll('#gradeEntryBody .grade-input')[3]; b.setAttribute('data-fk-vo-was','1'); return 1}")
    page.locator('#gradeEntryCard [data-fk-vo-toggle]').click()
    st = page.evaluate("()=>{const b=[...document.querySelectorAll('#gradeEntryBody .grade-input')]; return {open:b.slice(0,3).every(i=>!i.disabled), locked:b[3].disabled, save:document.getElementById('saveGradesBtn').disabled, stored:localStorage.getItem('fkss_view_only_%s')}}" % C)
    ck('WEB unlock: boxes editable again, Save back', st['open'] and not st['save'] and st['stored'] is None, st)
    ck('WEB unlock: a box that was locked before stays locked', st['locked'], st)

    # Submit marks screen gets the same toggle
    sql(f"INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, weight_percentage, max_score, created_by) VALUES (1,1,1,1,'VO test submit','test',5,10,2)")
    E = sql("SELECT id FROM assessments WHERE assessment_name='VO test submit'")
    page.evaluate("()=>{showSection('submissions'); const s=document.getElementById('submitAssignmentSelect'); s.value='1-1'; loadSubmitAssessments(); return 1}")
    page.wait_for_function("(a)=>[...document.querySelectorAll('#submitAssessmentSelect option')].some(o=>o.value===a)", arg=E, timeout=8000)
    page.evaluate("(a)=>{const s=document.getElementById('submitAssessmentSelect'); s.value=a; loadSubmitStudents(); return 1}", E)
    page.wait_for_selector('#submitEntryCard [data-fk-vo-toggle]', timeout=8000)
    page.wait_for_timeout(300)
    page.locator('#submitEntryCard [data-fk-vo-toggle]').click()
    st = page.evaluate("()=>({boxes:[...document.querySelectorAll('.submit-score, .submit-remark')].every(i=>i.disabled), btn:document.getElementById('submitBtn').disabled})")
    ck('WEB Submit marks: View only locks boxes and Submit', st['boxes'] and st['btn'], st)
    page.evaluate("()=>{submitMarklist(); return 1}")
    page.wait_for_timeout(400)
    ck('WEB Submit refused while View only is on (no dialog)', page.locator('[role=alertdialog]').count() == 0)
    ck('WEB no JS errors / native pop-ups', not errs, errs)
    br.close()

cleanup()
print(f'\nView-only + result card: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
