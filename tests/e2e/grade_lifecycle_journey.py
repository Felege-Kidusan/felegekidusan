#!/usr/bin/env python3
"""Readiness audit 2026-10-22 — J7 cross-role grade lifecycle, one continuous story.

  T1  Teacher (Submit marks): 11 on an out-of-10 test is refused; one student
      left blank; SUBMIT MARKS dialog says 1 will be "Did not take"; packet saved.
  E1  Education: the inbox review shows the blank as "Did not take"; Approve
      (MEDIUM, type APPROVE) → packet approved, marks locked.
  C1  Report card (Education): the blank student's card lists the test as
      "Did not take" and the subject totals leave it out (owner D1);
      a scored student's card counts it.
  T2  Teacher afterwards: the approved test cannot be changed (server refuses);
      View only + tap the blank student → private result card shows
      "Did not take" for this test.

LOCAL test server + disposable DB only. Creates its own test and removes it.
usage: SSMS_AUDIT_TESTING=1 python3 -u tests/e2e/grade_lifecycle_journey.py [base] [--shots DIR]
"""
import json, os, re, subprocess, sys, time
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
NAME = 'J7 Lifecycle %d' % (int(time.time()) % 100000)
PASS = FAIL = 0


def check(c, m, info=''):
    global PASS, FAIL
    if c:
        PASS += 1; print('PASS', m)
    else:
        FAIL += 1; print('FAIL', m, ('-> %s' % (info,))[:400] if info != '' else '')


def sql(q):
    r = subprocess.run(['mysql', '--default-character-set=utf8mb4', '-ussms', '-pssms', '-h127.0.0.1', '-N', '-B', 'ssms_test', '-e', q],
                       capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


def shot(page, name):
    if SHOTS:
        page.wait_for_timeout(250)
        page.screenshot(path=os.path.join(SHOTS, name + '.png'))


def cleanup():
    ids = sql(f"SELECT GROUP_CONCAT(id) FROM assessments WHERE assessment_name LIKE 'J7 Lifecycle %'")
    if ids and ids != 'NULL':
        sql(f"DELETE FROM attendance_submission_events WHERE packet_id IN (SELECT id FROM grade_submissions WHERE assessment_id IN ({ids}))")
        sql(f"DELETE FROM grade_submissions WHERE assessment_id IN ({ids})")
        sql(f"DELETE FROM academic_records WHERE assessment_id IN ({ids})")
        sql(f"DELETE FROM assessments WHERE id IN ({ids})")
    sql("DELETE FROM academic_records WHERE assessment_id=1 AND remarks='J7 seed'")
    sql("DELETE FROM security_rate_limits")


def login(br, user, url):
    page = br.new_context(viewport={'width': 1366, 'height': 900}).new_page()
    w = {'errors': [], 'native': [], 'http5': []}
    page.on('pageerror', lambda e: w['errors'].append(str(e)))
    page.on('dialog', lambda d: (w['native'].append(d.message), d.dismiss()))
    page.on('response', lambda r: w['http5'].append(r.url) if r.status >= 500 else None)
    page.goto(BASE + '/admin/index.php')
    page.fill('#username', user); page.fill('#password', PW)
    page.click('button[type=submit]')
    page.wait_for_load_state('networkidle')
    page.goto(BASE + url)
    page.wait_for_load_state('networkidle')
    return page, w


def card(page, member_id):
    return page.evaluate("""async(m)=>{
        const r=await fetch('/admin/api_communication.php?action=get_report_card&member_id='+m+'&class_id=1&year_id=1&term_id=1',{credentials:'same-origin'});
        const d=await r.json(); let note='';
        try{const h=document.createElement('div'); h.innerHTML=FKSSReportCard.renderSheet(d); const n=h.querySelector('.rc-dnt'); note=n?n.textContent:'';}catch(e){note='RENDER ERROR '+e;}
        return {d:d, note:note}}""", member_id)


def subject1(d):
    subs = (d.get('data') or d).get('subjects') or (d.get('card') or {}).get('subjects') or []
    for s in subs:
        if int(s.get('id') or 0) == 1:
            return s
    return None


cleanup()
sql("INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, max_score, weight_percentage) "
    f"VALUES (1,1,1,1,'{NAME}','test',10,5)")
AID = sql(f"SELECT id FROM assessments WHERE assessment_name='{NAME}'")
# Every class-1 student gets a Quiz 1 (assessment 1, out of 100) score first, so the
# blank student has a real taken test: their subject max must stay 100, never 110.
sql("INSERT INTO academic_records (member_id, class_id, subject_id, academic_year_id, term_id, assessment_id, score, remarks) "
    "SELECT e.member_id, 1, 1, 1, 1, 1, 70, 'J7 seed' FROM class_enrollments e WHERE e.class_id=1 AND e.status='active' "
    "AND e.member_id NOT IN (SELECT member_id FROM academic_records WHERE assessment_id=1)")
check(AID.isdigit(), 'setup: out-of-10 test "%s" (#%s) in class 1 / subject 1' % (NAME, AID))

try:
    with sync_playwright() as p:
        br = p.chromium.launch()

        # ── T1: teacher submits with one blank ──
        page, w = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
        page.evaluate("()=>{showSection('submissions'); const s=document.getElementById('submitAssignmentSelect'); s.value='1-1'; loadSubmitAssessments(); return 1}")
        page.wait_for_function("(a)=>[...document.querySelectorAll('#submitAssessmentSelect option')].some(o=>o.value===a)", arg=AID, timeout=8000)
        page.evaluate("(a)=>{const s=document.getElementById('submitAssessmentSelect'); s.value=a; loadSubmitStudents(); return 1}", AID)
        page.wait_for_selector('.submit-score', timeout=8000)
        boxes = page.locator('.submit-score')
        n = boxes.count()
        check(n >= 2, 'T1: Submit marks lists the class (%d students)' % n)
        names = page.evaluate("()=>[...document.querySelectorAll('.submit-score')].map(i=>i.dataset.name)")
        mids = page.evaluate("()=>[...document.querySelectorAll('.submit-score')].map(i=>i.dataset.mid||i.dataset.memberId||i.closest('tr')?.dataset.mid||'')")
        for i in range(n - 1):
            boxes.nth(i).fill(str(6 + (i % 4)))           # 6..9 out of 10
        # try an over-max mark in the last box first: refused live
        boxes.nth(n - 1).click(); boxes.nth(n - 1).fill(''); page.keyboard.type('11')
        v = boxes.nth(n - 1).input_value()
        check(v != '11', 'T1: typing 11 on an out-of-10 test is refused live (box shows %r)' % v)
        boxes.nth(n - 1).fill('')
        blank_name = names[n - 1]
        page.locator('#submitBtn').click()
        page.locator('[role=alertdialog]').wait_for(state='visible', timeout=6000)
        page.click('text=I want to submit these marks')          # step 1 → step 2 (effects)
        page.wait_for_timeout(200)
        txt = page.locator('[role=alertdialog]').inner_text()
        check('1 student(s) will be marked' in txt and 'Did not take' in txt, 'T1: SUBMIT MARKS dialog warns 1 student will be "Did not take"', txt[:400])
        page.click('[data-dcx-act=next]')                       # step 2 → step 3 (type)
        page.wait_for_timeout(200)
        page.fill('.dcx-in', 'SUBMIT MARKS')
        page.click('[data-dcx-act=final]')
        page.wait_for_function("(a)=>true", arg=0)
        page.wait_for_timeout(1500)
        st = sql(f"SELECT status FROM grade_submissions WHERE assessment_id={AID} AND submission_type='marklist' ORDER BY id DESC LIMIT 1")
        check(st in ('submitted', 'pending', 'pending_review'), 'T1: mark list packet saved for review (status %s)' % st)
        scored = sql(f"SELECT COUNT(*) FROM academic_records WHERE assessment_id={AID} AND score IS NOT NULL")
        check(scored == str(n - 1), 'T1: %d scores stored, the blank is not stored as a score (%s)' % (n - 1, scored))
        shot(page, 'J7_T1_submitted')
        check(not w['errors'] and not w['http5'] and not w['native'], 'T1: no JS errors / 5xx / native pop-ups', w)
        page.context.close()

        # ── E1: Education reviews + approves ──
        pid = sql(f"SELECT id FROM grade_submissions WHERE assessment_id={AID} AND submission_type='marklist' ORDER BY id DESC LIMIT 1")
        page, w = login(br, 'audit_edu', '/admin/dashboards/edu_dept.php')
        page.evaluate("(id)=>{reviewSubmission(Number(id)); return 1}", pid)
        page.wait_for_function("(n)=>document.querySelectorAll('#reviewRowsBox tbody tr').length>=n", arg=n, timeout=8000)
        badges = page.locator('#reviewRowsBox .rv-dnt').all_inner_texts()
        check(badges == ['Did not take'], 'E1: review shows the blank student as "Did not take"', badges)
        shot(page, 'J7_E1_review')
        page.evaluate("(id)=>{closeModal('reviewModal'); quickReview(Number(id),'approved'); return 1}", pid)
        page.locator('[role=alertdialog]').wait_for(state='visible', timeout=6000)
        page.click('text=I want to approve this mark list')
        page.click('text=I have read and understand these effects')
        check(page.locator('[data-dcx-act=final]').is_disabled(), 'E1: Approve locked until APPROVE is typed')
        page.fill('.dcx-in', 'APPROVE')
        page.click('[data-dcx-act=final]')
        page.wait_for_timeout(1500)
        st = sql(f"SELECT status FROM grade_submissions WHERE id={pid}")
        check(st == 'approved', 'E1: packet approved (%s)' % st)

        # ── C1: report cards ──
        blank_mid = sql(f"SELECT m.id FROM members m JOIN class_enrollments e ON e.member_id=m.id AND e.class_id=1 AND e.status='active' "
                        f"WHERE m.id NOT IN (SELECT member_id FROM academic_records WHERE assessment_id={AID} AND score IS NOT NULL) ORDER BY m.id LIMIT 1")
        scored_mid = sql(f"SELECT member_id FROM academic_records WHERE assessment_id={AID} AND score IS NOT NULL ORDER BY member_id LIMIT 1")
        cb = card(page, blank_mid); cs = card(page, scored_mid)
        sb = subject1(cb['d']); ss = subject1(cs['d'])
        check(sb is not None and ss is not None, 'C1: both report cards have subject 1', (str(cb['d'])[:200], str(cs['d'])[:200]))
        if sb and ss:
            ab = [a for a in sb['assessments'] if int(a['id']) == int(AID)]
            as_ = [a for a in ss['assessments'] if int(a['id']) == int(AID)]
            check(len(ab) == 1 and ab[0]['not_taken'] == 1 and ab[0]['score'] is None, 'C1: blank student — test listed as not taken', ab)
            check(len(as_) == 1 and as_[0]['not_taken'] == 0 and as_[0]['score'] is not None, 'C1: scored student — test counted with a score', as_)
            taken_max = round(sum(float(a['max_score'] or 0) for a in sb['assessments'] if not a['not_taken'] and a['score'] is not None), 2)
            check(taken_max > 0 and sb['max'] is not None and abs(float(sb['max']) - taken_max) < 0.01,
                  'C1: blank student — subject max = taken tests only (%s == %s), the 10 is NOT added' % (sb['max'], taken_max))
            check(ss['max'] is not None and any(int(a['id']) == int(AID) for a in ss['assessments']) and
                  abs(float(ss['max']) - round(sum(float(a['max_score'] or 0) for a in ss['assessments'] if a['score'] is not None), 2)) < 0.01,
                  'C1: scored student — subject max includes this test (%s)' % ss['max'])
        check(cb['note'].startswith('Did not take:') and NAME in cb['note'], 'C1: card note "Did not take: %s"' % NAME, cb['note'])
        check(NAME not in (cs['note'] or ''), 'C1: scored student card has no Did-not-take note for this test', cs['note'])
        check(not w['errors'] and not w['http5'] and not w['native'], 'E1/C1: no JS errors / 5xx / native pop-ups', w)
        page.context.close()

        # ── T2: teacher after approval ──
        page, w = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
        # server refuses changing an approved test
        r = page.evaluate("""async(a)=>{
            const fd=new FormData(); fd.append('action','save_grades'); fd.append('assessment_id',a);
            fd.append('grades', JSON.stringify([{member_id:%s, score:5, remarks:''}]));
            fd.append('csrf_token', (typeof CSRF_TOKEN!=='undefined'?CSRF_TOKEN:(document.querySelector('input[name=csrf_token]')?.value||'')));
            const r=await fetch('/admin/api_subjects.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-Token':(typeof CSRF_TOKEN!=='undefined'?CSRF_TOKEN:(document.querySelector('input[name=csrf_token]')?.value||''))}});
            let j={}; try{j=await r.json()}catch(e){}; return {code:r.status, j:j}}""" % blank_mid, AID)
        after = sql(f"SELECT COUNT(*) FROM academic_records WHERE assessment_id={AID} AND member_id={blank_mid} AND score IS NOT NULL")
        check(after == '0' and 'token' not in json.dumps(r.get('j') or {}).lower(), 'T2: approved marks cannot be changed by the teacher (server %s, nothing stored)' % r['code'], r)
        # result card from Grade entry with View only on
        page.evaluate("(a)=>{showSection('grades'); const s=document.getElementById('gradeAssessmentSelect'); "
                      "if(![...s.options].some(o=>o.value===a)){const o=document.createElement('option'); o.value=a; o.textContent='J7'; s.appendChild(o);} "
                      "s.value=a; s.dispatchEvent(new Event('change')); return 1}", AID)
        page.wait_for_selector('#gradeEntryBody tr', timeout=8000)
        res = page.evaluate("""async(p)=>{
            const r=await fetch('/admin/api_subjects.php?action=student_results&assessment_id='+p.a+'&member_id='+p.m,{credentials:'same-origin'});
            return await r.json()}""", {'a': AID, 'm': blank_mid})
        mine = [x for x in (res.get('results') or []) if int(x.get('assessment_id') or x.get('id') or 0) == int(AID)]
        check(res.get('status') == 'success' and mine and mine[0].get('score_label') == 'Did not take',
              'T2: private result card data — this test shows "Did not take"', mine or res)
        tot = res.get('totals') or {}
        check(tot.get('not_taken', 0) >= 1, 'T2: result card totals count it as not taken, not as zero (%s)' % tot)
        shot(page, 'J7_T2_teacher_after')
        check(not w['errors'] and not w['http5'] and not w['native'], 'T2: no JS errors / 5xx / native pop-ups', w)
        page.context.close()
        br.close()
finally:
    cleanup()

print(f'\nJ7 grade lifecycle: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
