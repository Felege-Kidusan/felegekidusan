"""Browser test — live score limit + "Did not take" (Phase 1, 2026-10-22).

LOCAL test server only. Creates its own out-of-10 assessment (class 1 /
subject 1) and removes it at the end.

  T  teacher "Submit marks": 15 cannot be typed (stays 1), 9.755 -> 9.75,
     'e'/'-' blocked, pasted 12 undone, an old bad value blocks Submit,
     blanks are named "Did not take" in the dialog, submit works
  G  teacher "Grade entry": live limit + Save refused while invalid
  E  Education "Grade Entry": live limit
  R  Education review modal: all students, amber "Did not take" badges
  C  report card: "Did not take: <test>" note under the subject

usage: SSMS_AUDIT_TESTING=1 python3 -u tests/e2e/score_limit_browser.py [base] [--shots DIR]
"""
import os, sys, subprocess
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
NAME = 'SL browser quiz'


def check(c, m, info=''):
    global PASS, FAIL
    if c:
        PASS += 1; print('PASS', m)
    else:
        FAIL += 1; print('FAIL', m, '::', str(info)[:300])


def sql(q):
    r = subprocess.run(['mysql', '-ussms', '-pssms', '-h127.0.0.1', 'ssms_test', '--default-character-set=utf8mb4', '-N', '-B', '-e', q],
                       capture_output=True, text=True)
    return r.stdout.strip()


def shot(page, name):
    if SHOTS:
        page.wait_for_timeout(400)
        page.screenshot(path=os.path.join(SHOTS, name + '.png'))


def cleanup():
    ids = sql(f"SELECT GROUP_CONCAT(id) FROM assessments WHERE assessment_name='{NAME}'")
    if ids and ids != 'NULL':
        sql(f"DELETE FROM attendance_submission_events WHERE packet_id IN (SELECT id FROM grade_submissions WHERE assessment_id IN ({ids}))")
        sql(f"DELETE FROM grade_submissions WHERE assessment_id IN ({ids})")
        sql(f"DELETE FROM academic_records WHERE assessment_id IN ({ids})")
        sql(f"DELETE FROM assessments WHERE id IN ({ids})")
    sql("DELETE FROM security_rate_limits")


def login(br, user, url):
    ctx = br.new_context(viewport={'width': 1366, 'height': 900})
    page = ctx.new_page()
    errs = []
    page.on('pageerror', lambda e: errs.append(str(e)[:200]))
    page.on('dialog', lambda d: (errs.append('native ' + d.type), d.dismiss()))
    page.goto(BASE + '/admin/index.php')
    page.fill('#username', user); page.fill('#password', PW)
    page.click('button[type=submit]')
    page.wait_for_load_state('networkidle')
    page.goto(BASE + url)
    page.wait_for_load_state('networkidle')
    return page, errs


def type_into(page, loc, text):
    loc.click(); loc.fill('')
    page.keyboard.type(text, delay=30)
    return loc.input_value()


cleanup()
sql("INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, max_score, weight_percentage) "
    f"VALUES (1,1,1,1,'{NAME}','test',10,5)")
AID = sql(f"SELECT id FROM assessments WHERE assessment_name='{NAME}'")
check(AID.isdigit(), 'setup: out-of-10 assessment %s' % AID)

with sync_playwright() as p:
    br = p.chromium.launch()

    # ── T: teacher Submit marks ───────────────────────────────────────────
    page, errs = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
    page.evaluate("()=>{showSection('submissions'); const s=document.getElementById('submitAssignmentSelect'); s.value='1-1'; loadSubmitAssessments(); return 1}")
    page.wait_for_function("(a)=>[...document.querySelectorAll('#submitAssessmentSelect option')].some(o=>o.value===a)", arg=AID, timeout=8000)
    page.evaluate("(a)=>{const s=document.getElementById('submitAssessmentSelect'); s.value=a; loadSubmitStudents(); return 1}", AID)
    page.wait_for_selector('.submit-score', timeout=8000)
    boxes = page.locator('.submit-score')
    n = boxes.count()
    check(n == 4, 'T: 4 students on the sheet', n)
    check(boxes.first.get_attribute('data-fk-score') == '1', 'T: score boxes are guarded (data-fk-score)')
    v = type_into(page, boxes.nth(0), '15')
    check(v == '1', 'T: typing 1-5 on a /10 test keeps "1" (the 5 is refused)', v)
    hint = page.evaluate("()=>{const h=document.querySelector('.fk-score-hint'); return h && h.style.display!=='none' ? h.textContent : ''}")
    check(hint == 'Max is 10', 'T: hint "Max is 10" shown', hint)
    shot(page, 'T1_max_hint')
    v = type_into(page, boxes.nth(0), '9.755')
    check(v == '9.75', 'T: 9.755 -> 9.75 (max 2 decimals)', v)
    v = type_into(page, boxes.nth(1), '1e5')
    check(v == '1', 'T: "e" is blocked (1e5 -> 1)', v)
    v = type_into(page, boxes.nth(1), '-3')
    check(v == '3', 'T: "-" is blocked', v)
    v = type_into(page, boxes.nth(1), '10')
    check(v == '10', 'T: exactly 10 is accepted', v)
    boxes.nth(2).fill('12')  # paste-like: one input event with 12
    check(boxes.nth(2).input_value() == '', 'T: pasted 12 is undone', boxes.nth(2).input_value())
    # an old bad value the live guard never saw (e.g. set by script) blocks Submit
    page.evaluate("()=>{document.querySelectorAll('.submit-score')[2].value='11'; return 1}")
    page.locator('#submitBtn').click()
    page.wait_for_timeout(500)
    open_ = page.evaluate("()=>!!document.querySelector('[role=alertdialog]')")
    bad = page.evaluate("()=>document.querySelectorAll('.submit-score')[2].classList.contains('fk-score-bad')")
    check(not open_ and bad, 'T: invalid 11 blocks Submit (no dialog) and the box is marked red', (open_, bad))
    shot(page, 'T2_invalid_blocked')
    boxes.nth(2).fill('')
    # 2 scored (9.75, 10), 2 blank -> dialog names them "Did not take"
    page.locator('#submitBtn').click()
    page.locator('[role=alertdialog]').wait_for(state='visible', timeout=6000)
    page.click('[data-dcx-act=next]')
    txt = page.locator('[role=alertdialog]').inner_text()
    names = sql("SELECT GROUP_CONCAT(TRIM(CONCAT(m.student_name,' ',COALESCE(m.father_name,''))) ORDER BY m.student_name SEPARATOR '|') FROM members m WHERE m.id IN (900002,900006)")
    check('2 student(s) will be marked “Did not take”' in txt, 'T: dialog says 2 students will be marked Did not take', txt[:300])
    blank_names = page.evaluate("()=>[...document.querySelectorAll('.submit-score')].filter(i=>!i.value.trim()).map(i=>i.dataset.name)")
    check(all(nm in txt for nm in blank_names) and len(blank_names) == 2, 'T: dialog lists the blank students by name', blank_names)
    shot(page, 'T3_dialog_did_not_take')
    page.click('[data-dcx-act=next]')
    page.fill('.dcx-in', 'SUBMIT MARKS')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1800)
    st = sql(f"SELECT status FROM grade_submissions WHERE assessment_id={AID} AND submission_type='marklist' ORDER BY id DESC LIMIT 1")
    check(st == 'submitted', 'T: mark list submitted', st)
    check(sql(f"SELECT COUNT(*) FROM academic_records WHERE assessment_id={AID} AND score IS NOT NULL") == '2', 'T: 2 scores stored')
    check(not errs, 'T: no JS errors / native pop-ups', errs)
    page.context.close()

    # ── G: teacher Grade entry (same page, other section) ─────────────────
    sql(f"UPDATE grade_submissions SET status='revision_needed' WHERE assessment_id={AID} AND submission_type='marklist'")
    page, errs = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
    page.evaluate("(a)=>{showSection('grades'); const s=document.getElementById('gradeAssessmentSelect'); const o=document.createElement('option'); o.value=a; o.textContent='x'; s.appendChild(o); s.value=a; loadStudentsForGrading(); return 1}", AID)
    page.wait_for_selector('#gradeEntryBody .grade-input', timeout=8000)
    gb = page.locator('#gradeEntryBody .grade-input')
    v = type_into(page, gb.nth(3), '25')
    check(v == '2', 'G: Grade entry refuses 25 on /10 (keeps 2)', v)
    page.evaluate("()=>{document.querySelectorAll('#gradeEntryBody .grade-input')[3].value='99'; return 1}")
    before = sql(f"SELECT COUNT(*) FROM academic_records WHERE assessment_id={AID}")
    page.evaluate("()=>{saveAllGrades(); return 1}")
    page.wait_for_timeout(900)
    check(sql(f"SELECT COUNT(*) FROM academic_records WHERE assessment_id={AID}") == before, 'G: Save refused while a box is invalid (nothing sent)')
    check(not errs, 'G: no JS errors', errs)
    page.context.close()
    sql(f"UPDATE grade_submissions SET status='submitted' WHERE assessment_id={AID} AND submission_type='marklist'")

    # ── E: Education Grade Entry ──────────────────────────────────────────
    page, errs = login(br, 'audit_edu', '/admin/dashboards/edu_dept.php')
    page.evaluate("""(a)=>{
        nav('grades'); const c=document.getElementById('gradeClass'); c.value='1';
        const s=document.getElementById('gradeSubject'); s.innerHTML='<option value="1">S</option>'; s.value='1';
        const g=document.getElementById('gradeAssessment'); g.innerHTML='<option value="'+a+'" data-max="10">x</option>'; g.value=a;
        loadGradeStudents(); return 1}""", AID)
    page.wait_for_selector('#gradeArea .grade-input', timeout=8000)
    eb = page.locator('#gradeArea .grade-input')
    v = type_into(page, eb.nth(3), '11')
    check(v == '1', 'E: Education Grade Entry refuses 11 on /10 (keeps 1)', v)
    shot(page, 'E1_edu_grade_entry')
    page.evaluate("()=>{document.activeElement && document.activeElement.blur(); return 1}")
    hv = page.evaluate("()=>{const h=document.querySelector('.fk-score-hint'); return h ? h.style.display : 'none'}")
    check(hv == 'none', 'E: hint hides as soon as the box loses focus (never floats over a modal)', hv)

    # ── R: Education review modal ─────────────────────────────────────────
    pid = sql(f"SELECT id FROM grade_submissions WHERE assessment_id={AID} AND submission_type='marklist' ORDER BY id DESC LIMIT 1")
    page.evaluate("(id)=>{reviewSubmission(Number(id)); return 1}", pid)
    page.wait_for_function("()=>document.querySelectorAll('#reviewRowsBox tbody tr').length>=4", timeout=8000)
    rows = page.locator('#reviewRowsBox tbody tr').count()
    badges = page.locator('#reviewRowsBox .rv-dnt').all_inner_texts()
    head = page.evaluate("()=>{const e=document.querySelector('.rv-dnt-count'); return e?e.textContent:''}")
    check(rows == 4, 'R: review lists all 4 students (blanks included)', rows)
    check(badges == ['Did not take', 'Did not take'], 'R: 2 amber "Did not take" badges', badges)
    check(head.strip() == '2 Did not take', 'R: header count "2 Did not take"', head)
    shot(page, 'R1_review_did_not_take')
    check(not errs, 'R/E: no JS errors', errs)

    # ── C: report card note ───────────────────────────────────────────────
    html = page.evaluate("""async()=>{
        const r=await fetch('/admin/api_communication.php?action=get_report_card&member_id=900002&class_id=1&year_id=1&term_id=1',{credentials:'same-origin'});
        const d=await r.json();
        const host=document.createElement('div'); host.id='slCardHost'; host.innerHTML=FKSSReportCard.renderSheet(d);
        document.body.appendChild(host);
        const n=host.querySelector('.rc-dnt'); return n?n.textContent:''}""")
    check(html.startswith('Did not take:') and NAME in html, 'C: card shows "Did not take: %s"' % NAME, html)
    page.context.close()

    # ── M: make-up result form (assessment follow-ups) ────────────────────
    sql("INSERT INTO member_department_periods (member_id, department, status, started_at, source, reason) "
        "VALUES (900006,'edu','inactive', NOW() - INTERVAL 30 DAY, 'manual', 'SL browser test')")
    per = sql("SELECT MAX(id) FROM member_department_periods WHERE reason='SL browser test'")
    sql(f"INSERT INTO assessment_followups (member_id, period_id, assessment_id, class_id, subject_id, decision) VALUES (900006,{per},{AID},1,1,'makeup_required')")
    fid = sql(f"SELECT id FROM assessment_followups WHERE period_id={per}")
    page, errs = login(br, 'audit_edu', '/admin/assessment_followups.php?id=%s' % fid)
    page.wait_for_selector('#af-a-score', timeout=8000)
    sc = page.locator('#af-a-score')
    check(sc.get_attribute('data-fk-score') == '1', 'M: make-up score box is guarded')
    v = type_into(page, sc, '15')
    check(v == '1', 'M: make-up score refuses 15 on /10 (keeps 1)', v)
    v = type_into(page, page.locator('#af-a-max'), '20')
    check(v == '2', 'M: "Out of" cannot go above the test max 10 (keeps 2)', v)
    page.locator('#af-a-max').fill('10')
    page.evaluate("()=>{document.getElementById('af-a-score').value='12'; return 1}")
    page.click('#af-a-save')
    page.wait_for_timeout(800)
    n_att = sql(f"SELECT COUNT(*) FROM assessment_makeup_attempts WHERE followup_id={fid}")
    check(n_att in ('0', ''), 'M: Save refused for 12/10 (no attempt stored)', n_att)
    shot(page, 'M1_makeup_form')
    # server-side cap (a client that skips the browser guard)
    srv = page.evaluate("""async(fid)=>{
        const c=window.AF_CONFIG, out=[];
        for (const [score,max] of [['12','10'],['5','20'],['abc','10'],['8','10']]) {
          const r=await fetch(c.api||'/admin/api_assessment_followups.php',{method:'POST',credentials:'same-origin',
            headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':c.csrf},
            body:JSON.stringify({action:'attempt',id:Number(fid),score:score,max_score:max,remarks:'',csrf_token:c.csrf})});
          const j=await r.json().catch(()=>({})); out.push([r.status, j.status||'']);
        }
        return out}""", fid)
    check([x[1] for x in srv[:3]] == ['error', 'error', 'error'], 'M: server refuses 12/10, out-of 20 > 10, and "abc"', srv)
    check(srv[3][1] == 'success', 'M: server accepts 8/10', srv)
    check(sql(f"SELECT CONCAT(score,'/',max_score) FROM assessment_makeup_attempts WHERE followup_id={fid}") == '8.00/10.00', 'M: only the valid 8/10 attempt stored',
          sql(f"SELECT GROUP_CONCAT(CONCAT(score,'/',max_score)) FROM assessment_makeup_attempts WHERE followup_id={fid}"))
    check(not errs, 'M: no JS errors', errs)
    page.context.close()
    sql(f"DELETE FROM member_department_periods WHERE id={per}")
    br.close()

cleanup()
print(f'\nScore limit browser: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
