"""Pre-production end-to-end user-journey test (LOCAL test server only).

Part A  Role sweep: every test role logs in, opens every sidebar section /
        menu page, and we record JS errors, HTTP 5xx, and native browser
        pop-ups (which must no longer exist).
Part B  Real workflows through the UI, checked in the database:
        J1 teacher day attendance: first save (no dialog) -> change -> re-save
           (REPLACE ATTENDANCE lists the change) -> unchanged re-save (no dialog)
        J2 teacher lesson sheet -> Education cancels the lesson (CANCEL LESSON,
           reason >= 3 characters) -> session cancelled
        J3 teacher submits a mark list (SUBMIT MARKS) -> submission row
        J4 HR deactivates a member in Education (DEACTIVATE MEMBER + reason)
           -> reactivates (simple dialog)
        J5 HR renews an expired ID card (RENEW CARD): cancel keeps the page
        J6 teacher starts a conversation, deletes own message for everyone
           (DELETE MESSAGE) -> message tombstoned

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/prod_readiness_journeys.py [base] [--shots DIR] [--only A|B]
"""
import os, sys, time, json, subprocess, traceback
from playwright.sync_api import sync_playwright

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: set SSMS_AUDIT_TESTING=1 (local disposable test DB only).')
av = sys.argv[1:]
opt = {}
for k in ('--shots', '--only'):
    if k in av:
        i = av.index(k); opt[k] = av[i + 1]; av = av[:i] + av[i + 2:]
BASE = (av[0] if av else 'http://127.0.0.1:8080').rstrip('/')
if 'felegekidusan' in BASE:
    sys.exit('Refusing to run against production.')
SHOTS = opt.get('--shots')
if SHOTS:
    os.makedirs(SHOTS, exist_ok=True)
ONLY = opt.get('--only')
PW = 'AuditTest#2026'
PASS = FAIL = 0
FAILS = []


def check(c, m):
    global PASS, FAIL
    if c:
        PASS += 1; print('PASS', m)
    else:
        FAIL += 1; FAILS.append(m); print('FAIL', m)


def sql(q):
    r = subprocess.run(['mysql', '-ussms', '-pssms', '-h127.0.0.1', 'ssms_test', '--default-character-set=utf8mb4', '-N', '-B', '-e', q],
                       capture_output=True, text=True)
    if r.stderr.strip() and 'Warning' not in r.stderr:
        print('  SQL-ERR', r.stderr.strip()[:200])
    return r.stdout.strip()


def shot(page, name):
    if SHOTS:
        page.wait_for_timeout(450)
        page.screenshot(path=os.path.join(SHOTS, name + '.png'))


class Watch:
    """Collects JS errors, 5xx responses and native dialogs for one page."""
    def __init__(self, page):
        self.errors, self.http5, self.native, self.http4 = [], [], [], []
        page.on('pageerror', lambda e: self.errors.append(str(e)[:200]))
        page.on('dialog', self._dlg)
        page.on('response', self._resp)

    def _dlg(self, d):
        self.native.append(d.type + ': ' + d.message[:120])
        d.dismiss()

    def _resp(self, r):
        u = r.url.replace(BASE, '')
        if r.status >= 500:
            self.http5.append('%d %s' % (r.status, u[:120]))
        elif r.status >= 400 and '/admin/' in u and 'favicon' not in u:
            self.http4.append('%d %s' % (r.status, u[:120]))

    def reset(self):
        self.errors.clear(); self.http5.clear(); self.native.clear(); self.http4.clear()


def login(br, user, url=None):
    ctx = br.new_context(viewport={'width': 1366, 'height': 900})
    page = ctx.new_page()
    w = Watch(page)
    page.goto(BASE + '/admin/index.php')
    page.fill('#username', user)
    page.fill('#password', PW)
    page.click('button[type=submit]')
    page.wait_for_load_state('networkidle')
    if url:
        page.goto(BASE + url)
        page.wait_for_load_state('networkidle')
    return page, w


def dialog(page, timeout=6000):
    d = page.locator('[role=alertdialog]')
    d.wait_for(state='visible', timeout=timeout)
    return d


def dialog_open(page):
    return page.evaluate("()=>{const d=document.querySelector('[role=alertdialog]'); return !!(d && d.offsetParent!==null)}")


def walk(page, phrase, reason=None):
    """Steps 1 -> 2 -> 3 of a HIGH/MEDIUM dialog, then confirm."""
    page.click('[data-dcx-act=next]')
    page.wait_for_timeout(150)
    page.click('[data-dcx-act=next]')
    page.wait_for_timeout(150)
    if reason is not None:
        page.fill('.dcx-ta', reason)
    page.fill('.dcx-in', phrase)
    page.click('[data-dcx-act=final]')


def cancel(page):
    page.click('[data-dcx-act=cancel]')
    page.wait_for_timeout(400)


# ═══════════════════════════ Part A: role sweep ═══════════════════════════
ROLES = ['audit_super', 'audit_school', 'audit_edu', 'audit_teach', 'audit_fin', 'audit_hr', 'audit_info', 'audit_taker', 'audit_mez']
NAV_JS = r"""()=>{
  const out=[], seen=new Set();
  const scope=document.querySelectorAll('aside, .sb, nav, .sidebar, #sidebar, .nav-link, [data-section], [data-sec]');
  const add=(el)=>{
    const oc=el.getAttribute('onclick')||''; const ds=el.getAttribute('data-section')||el.getAttribute('data-sec')||'';
    const href=el.tagName==='A'?(el.getAttribute('href')||''):'';
    let key='';
    if(oc && /(nav|showSection|switchSection|showSec|go|openSection|switchTab)\s*\(/.test(oc)) key='oc:'+oc.trim();
    else if(ds) key='ds:'+ds;
    else if(href && href.startsWith('/') && !/logout|export|download|\.xlsx|\.csv|\.pdf|action=|#/.test(href)) key='href:'+href;
    if(!key||seen.has(key)) return; seen.add(key);
    out.push({key, label:(el.innerText||el.title||'').trim().slice(0,40)});
  };
  scope.forEach(s=>{ if(s.matches('a,button,div,[onclick]')) add(s); s.querySelectorAll('a,button,[onclick]').forEach(add); });
  return out;
}"""


def run_sweep(br):
    print('\n══ Part A: role sweep ══')
    for user in ROLES:
        page, w = login(br, user)
        landing = page.url.replace(BASE, '')
        check('/index.php' not in landing and w.http5 == [], '%s: logs in and lands on %s' % (user, landing))
        check(not w.errors, '%s: landing page has no JS errors %s' % (user, w.errors[:2]))
        items = page.evaluate(NAV_JS)
        bad = []
        opened = 0
        for it in items[:40]:
            w.reset()
            k = it['key']
            try:
                if k.startswith('href:'):
                    page.goto(BASE + k[5:]); page.wait_for_load_state('networkidle', timeout=15000)
                    page.goto(BASE + landing); page.wait_for_load_state('networkidle', timeout=15000)
                elif k.startswith('oc:'):
                    page.evaluate("(c)=>{ window.__navErr=''; try{ (new Function(c))(); }catch(e){ window.__navErr=String(e); } return 1}", k[3:])
                    page.wait_for_timeout(700)
                    ne = page.evaluate("()=>window.__navErr||''")
                    if ne:
                        w.errors.append('menu call threw: ' + ne[:150])
                else:
                    page.evaluate("(d)=>{const el=document.querySelector('[data-section=\"'+d+'\"],[data-sec=\"'+d+'\"]'); if(el) el.click(); return 1}", k[3:])
                    page.wait_for_timeout(700)
                opened += 1
            except Exception as e:
                bad.append('%s -> %s' % (it['label'] or k, str(e)[:80]))
                continue
            if w.errors or w.http5 or w.native:
                bad.append('%s -> js=%s 5xx=%s native=%s' % (it['label'] or k[:50], w.errors[:1], w.http5[:2], w.native[:1]))
            # close any modal / dialog a section may have opened
            page.keyboard.press('Escape')
        check(opened > 0, '%s: opened %d sidebar sections/pages' % (user, opened))
        check(not bad, '%s: no JS errors / 5xx / native pop-ups in any section %s' % (user, bad[:3]))
        page.context.close()


# ═══════════════════════════ Part B: workflows ═══════════════════════════
def j1_day_attendance(br):
    print('\n── J1 teacher day attendance (re-save guard in the real flow)')
    sql("DELETE FROM member_department_periods WHERE member_id IN (SELECT member_id FROM class_enrollments WHERE class_id=2)")
    page, w = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
    today = page.evaluate("()=>document.getElementById('attDateInput').value")   # school-local (Addis Ababa) date
    page.context.close()
    sql("DELETE FROM attendance WHERE class_id=2 AND attendance_date='%s'" % today)
    page, w = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
    page.evaluate("()=>{showSection('attendance'); const s=document.getElementById('attClassSelect'); s.value='2'; onAttClassChange(); return 1}")
    page.wait_for_timeout(800)
    page.evaluate("()=>{const l=document.getElementById('attLessonSelect'); if(l){l.value='';} loadStudentsForAttendance(); return 1}")
    page.wait_for_selector('#attendanceBody tr[data-member-id]', timeout=8000)
    rows = page.locator('#attendanceBody tr[data-member-id]').count()
    check(rows == 3, 'J1: class sheet shows the 3 enrolled students (%d)' % rows)
    page.click('#attSaveBtn')
    page.wait_for_timeout(1500)
    check(not dialog_open(page), 'J1: FIRST save goes straight through (no dialog)')
    saved = sql("SELECT COUNT(*) FROM attendance WHERE class_id=2 AND attendance_date='%s'" % today)
    check(saved == '3', 'J1: 3 attendance rows saved (%s)' % saved)
    page.wait_for_selector('#attendanceBody tr[data-member-id]', timeout=8000)
    page.wait_for_timeout(600)
    first = page.locator('#attendanceBody tr[data-member-id]').first
    mid = first.get_attribute('data-member-id')
    before = sql("SELECT status FROM attendance WHERE class_id=2 AND attendance_date='%s' AND member_id=%s" % (today, mid))
    target = 'present' if before != 'present' else 'late'
    first.locator('[data-attendance-status="%s"]' % target).click()
    page.click('#attSaveBtn')
    d = dialog(page)
    page.click('[data-dcx-act=next]')
    txt = dialog(page).inner_text()
    check(('%s → %s' % (before, target)) in txt, 'J1: re-save dialog lists the change (%s → %s)' % (before, target))
    shot(page, 'J1_replace_attendance')
    page.click('[data-dcx-act=next]')
    page.fill('.dcx-in', 'REPLACE ATTENDANCE')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1500)
    after = sql("SELECT status FROM attendance WHERE class_id=2 AND attendance_date='%s' AND member_id=%s" % (today, mid))
    check(after == target, 'J1: database now has the new status (%s)' % after)
    page.wait_for_selector('#attendanceBody tr[data-member-id]', timeout=8000)
    page.wait_for_timeout(600)
    page.click('#attSaveBtn')
    page.wait_for_timeout(1200)
    check(not dialog_open(page), 'J1: re-save with NO changes goes straight through')
    check(not w.errors and not w.http5 and not w.native, 'J1: no JS errors/5xx/native pop-ups %s %s %s' % (w.errors[:1], w.http5[:1], w.native[:1]))
    page.context.close()


def j2_lesson_cancel(br):
    print('\n── J2 teacher lesson sheet -> Education cancels the lesson')
    day = sql("SELECT DATE_SUB(CURDATE(), INTERVAL ((WEEKDAY(CURDATE())+2) % 7) DAY)")  # last Saturday (class 1 meets Sat/Sun)
    sql("DELETE r FROM attendance_session_records r JOIN attendance_sessions s ON s.id=r.session_id WHERE s.class_id=1 AND s.session_date='%s'" % day)
    sql("DELETE FROM attendance_sessions WHERE class_id=1 AND session_date='%s'" % day)
    sql("DELETE FROM attendance WHERE class_id=1 AND attendance_date='%s'" % day)
    page, w = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
    page.evaluate("(d)=>{showSection('attendance'); const s=document.getElementById('attClassSelect'); s.value='1'; document.getElementById('attDateInput').value=d; onAttClassChange(); return 1}", day)
    page.wait_for_timeout(1200)
    has_lesson = page.evaluate("()=>{const l=document.getElementById('attLessonSelect'); if(!l) return false; const o=[...l.options].find(x=>x.value==='1'); if(o){l.value='1';} loadStudentsForAttendance(); return !!o}")
    check(has_lesson, 'J2: teacher can pick the lesson (subject 1) for class 1 on %s' % day)
    page.wait_for_selector('#attendanceBody tr[data-member-id]', timeout=8000)
    page.wait_for_timeout(500)
    page.click('#attSaveBtn')
    page.wait_for_timeout(1800)
    sid = sql("SELECT id FROM attendance_sessions WHERE class_id=1 AND session_date='%s' AND subject_id=1 AND status<>'cancelled' ORDER BY id DESC LIMIT 1" % day)
    check(sid.isdigit(), 'J2: lesson session saved (id %s)' % sid)
    member = sql("SELECT member_id FROM attendance_session_records WHERE session_id=%s LIMIT 1" % (sid or 0))
    page.context.close()
    if not sid.isdigit() or not member:
        check(False, 'J2: cannot continue without a saved session'); return
    page, w = login(br, 'audit_edu', '/admin/lesson_attendance.php?member_id=%s' % member)
    page.wait_for_selector('#la-list [data-cancel="%s"]' % sid, timeout=8000)
    page.click('#la-list [data-cancel="%s"]' % sid)
    dialog(page)
    page.click('[data-dcx-act=next]'); page.wait_for_timeout(150)
    page.click('[data-dcx-act=next]'); page.wait_for_timeout(150)
    page.fill('.dcx-in', 'CANCEL LESSON')
    page.fill('.dcx-ta', 'ab')
    check(page.locator('[data-dcx-act=final]').is_disabled(), 'J2: Cancel-lesson button disabled with a 2-character reason')
    page.fill('.dcx-ta', 'Teacher was absent')
    check(not page.locator('[data-dcx-act=final]').is_disabled(), 'J2: enabled once the reason has 3+ characters')
    shot(page, 'J2_cancel_lesson')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1500)
    st = sql("SELECT CONCAT(status,'|',IFNULL(cancel_reason,'')) FROM attendance_sessions WHERE id=%s" % sid)
    check(st == 'cancelled|Teacher was absent', 'J2: session cancelled in the database with the reason (%s)' % st)
    check(not w.errors and not w.http5 and not w.native, 'J2: no JS errors/5xx/native pop-ups %s %s %s' % (w.errors[:1], w.http5[:1], w.native[:1]))
    page.context.close()


def j3_submit_marks(br):
    print('\n── J3 teacher submits a mark list')
    tag = str(int(time.time()))[-6:]
    sql("INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, max_score, weight_percentage) VALUES (1, 1, 1, 1, 'Journey quiz %s', 100, 5)" % tag)
    aid = sql("SELECT id FROM assessments WHERE assessment_name='Journey quiz %s'" % tag)
    check(aid.isdigit(), 'J3: fresh assessment created (%s)' % aid)
    page, w = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
    page.evaluate("()=>{showSection('submissions'); const s=document.getElementById('submitAssignmentSelect'); s.value='1-1'; loadSubmitAssessments(); return 1}")
    page.wait_for_function("document.querySelectorAll('#submitAssessmentSelect option').length>1", timeout=8000)
    page.wait_for_function("(a)=>[...document.querySelectorAll('#submitAssessmentSelect option')].some(o=>o.value===a)", arg=aid, timeout=8000)
    page.evaluate("(a)=>{const s=document.getElementById('submitAssessmentSelect'); s.value=a; loadSubmitStudents(); return 1}", aid)
    page.wait_for_selector('.submit-score', timeout=8000)
    n = page.locator('.submit-score').count()
    for i in range(n - 1):
        page.locator('.submit-score').nth(i).fill(str(70 + i))
    page.locator('#submitBtn').click()
    d = dialog(page)
    page.click('[data-dcx-act=next]')
    txt = dialog(page).inner_text()
    check(('%d score(s) are submitted' % (n - 1)) in txt and '1 student(s) will be marked “Did not take”' in txt, 'J3: dialog shows filled/blank counts (%d filled, 1 blank)' % (n - 1))
    shot(page, 'J3_submit_marks')
    page.click('[data-dcx-act=next]')
    page.fill('.dcx-in', 'SUBMIT MARKS')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1800)
    st = sql("SELECT status FROM grade_submissions WHERE assessment_id=%s AND submission_type='marklist' ORDER BY id DESC LIMIT 1" % aid)
    check(st == 'submitted', 'J3: mark-list submission saved with status "submitted" (%s)' % st)
    nrec = sql("SELECT COUNT(*) FROM academic_records WHERE assessment_id=%s" % aid)
    check(nrec == str(n - 1), 'J3: %s scores stored (expected %d)' % (nrec, n - 1))
    check(not w.errors and not w.http5 and not w.native, 'J3: no JS errors/5xx/native pop-ups %s %s %s' % (w.errors[:1], w.http5[:1], w.native[:1]))
    page.context.close()


def j4_department_status(br):
    print('\n── J4 department status: deactivate (MEDIUM + reason) / reactivate')
    mid = sql("SELECT member_id FROM class_enrollments WHERE class_id=2 LIMIT 1")
    sql("DELETE FROM member_department_periods WHERE member_id=%s" % mid)
    for user in ('audit_hr', 'audit_edu', 'audit_info'):
        page, w = login(br, user, '/admin/info_manage_member.php?id=%s' % mid)
        try:
            page.wait_for_selector('[data-ds-change="edu"]', timeout=5000)
            break
        except Exception:
            page.context.close(); page = None
    check(page is not None, 'J4: a role with permission sees the Deactivate button (%s)' % user)
    if page is None:
        return
    page.click('[data-ds-change="edu"][data-ds-to="inactive"]')
    dialog(page)
    page.click('[data-dcx-act=next]'); page.wait_for_timeout(150)
    page.click('[data-dcx-act=next]'); page.wait_for_timeout(150)
    page.fill('.dcx-in', 'DEACTIVATE MEMBER')
    page.fill('.dcx-ta', 'no')
    check(page.locator('[data-dcx-act=final]').is_disabled(), 'J4: deactivate blocked with a 2-character reason')
    page.fill('.dcx-ta', 'Moved to another parish')
    shot(page, 'J4_deactivate_member')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1500)
    st = sql("SELECT CONCAT(status,'|',IFNULL(reason,'')) FROM member_department_periods WHERE member_id=%s AND department='edu' AND ended_at IS NULL ORDER BY id DESC LIMIT 1" % mid)
    check(st == 'inactive|Moved to another parish', 'J4: member inactive in Education with the reason (%s)' % st)
    page.wait_for_selector('[data-ds-change="edu"][data-ds-to="active"]', timeout=6000)
    page.click('[data-ds-change="edu"][data-ds-to="active"]')
    page.wait_for_selector('[data-ds-confirm]', timeout=5000)
    check(not dialog_open(page), 'J4: reactivate uses the simple dialog (no typing)')
    page.fill('.ds-modal textarea', 'Back in class')
    page.click('[data-ds-confirm]')
    page.wait_for_timeout(1500)
    st = sql("SELECT status FROM member_department_periods WHERE member_id=%s AND department='edu' AND ended_at IS NULL ORDER BY id DESC LIMIT 1" % mid)
    check(st in ('active', ''), 'J4: member active again (%s)' % (st or 'no open inactive period'))
    check(not w.errors and not w.http5 and not w.native, 'J4: no JS errors/5xx/native pop-ups %s %s %s' % (w.errors[:1], w.http5[:1], w.native[:1]))
    page.context.close()


def j5_id_renew(br):
    print('\n── J5 HR renews an expired ID card')
    mid = sql("SELECT member_id FROM class_enrollments WHERE class_id=1 LIMIT 1")
    old = sql("SELECT CONCAT(IFNULL(id_card_status,'NULL'),'|',IFNULL(id_card_generated_at,'NULL')) FROM members WHERE id=%s" % mid)
    oldreg = sql("SELECT IFNULL(registration_type,'NULL') FROM members WHERE id=%s" % mid)
    sql("UPDATE members SET id_card_status='generated', id_card_generated_at='2020-01-01 10:00:00', registration_type='direct' WHERE id=%s" % mid)
    page, w = login(br, 'audit_hr', '/admin/dashboards/hr-dept.php')
    page.evaluate("()=>{const b=document.querySelector('[data-section=\"idcards\"]'); if(b) b.click(); return 1}")
    page.wait_for_timeout(500)
    page.wait_for_timeout(1500)
    btn = page.locator('form[action*="generate_id_card"]:has(input[value="%s"]) button' % mid)
    try:
        btn.first.wait_for(state='attached', timeout=8000)
    except Exception:
        pass
    renew = page.locator('form[action*="generate_id_card"]:has(input[name="action"][value="renew"]):has(input[value="%s"]) button' % mid)
    check(renew.count() >= 1, 'J5: expired card shows a Renew button')
    if renew.count():
        url0 = page.url
        renew.first.evaluate("b=>b.scrollIntoView()")
        renew.first.click()
        d = dialog(page)
        check('I want to renew this card' in d.inner_text(), 'J5: Renew opens the MEDIUM dialog')
        shot(page, 'J5_renew_card')
        cancel(page)
        page.wait_for_timeout(600)
        check(page.url == url0, 'J5: Cancel keeps you on the page (nothing submitted)')
    check(not w.errors and not w.http5 and not w.native, 'J5: no JS errors/5xx/native pop-ups %s %s %s' % (w.errors[:1], w.http5[:1], w.native[:1]))
    sql("UPDATE members SET registration_type=%s WHERE id=%s" % ('NULL' if oldreg == 'NULL' else "'%s'" % oldreg, mid))
    s, g = old.split('|')
    sql("UPDATE members SET id_card_status=%s, id_card_generated_at=%s WHERE id=%s" % ('NULL' if s == 'NULL' else "'%s'" % s, 'NULL' if g == 'NULL' else "'%s'" % g, mid))
    page.context.close()


def j6_delete_message(br):
    print('\n── J6 teacher deletes own message for everyone')
    page, w = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
    res = page.evaluate("""async ()=>{
        const tok = window.CSRF_TOKEN || (document.querySelector('meta[name=csrf-token]')||{}).content;
        const p = await (await fetch('/admin/api_notifications.php?action=partners',{credentials:'same-origin'})).json();
        const to = (p.partners||[])[0]; if(!to) return {err:'no partner', p};
        const fd = new URLSearchParams({action:'thread_start', csrf_token:tok, subject:'DC4 journey', to:String(to.id||to.user_id), body:'please delete me'});
        const r = await (await fetch('/admin/api_notifications.php',{method:'POST',body:fd,credentials:'same-origin'})).json();
        return r; }""")
    tid = str(res.get('id', '')) if isinstance(res, dict) else ''
    check(tid.isdigit(), 'J6: conversation started (%s)' % (tid or res))
    if not tid.isdigit():
        page.context.close(); return
    msg = sql("SELECT id FROM messages WHERE thread_id=%s ORDER BY id LIMIT 1" % tid)
    page.goto(BASE + '/admin/dashboards/teacher.php?j6=%s#messages' % tid)   # full reload, not just a hash change
    page.wait_for_load_state('networkidle')
    page.wait_for_selector('.nc-im-thread[data-th="%s"]' % tid, state='attached', timeout=8000)
    page.evaluate("(t)=>{document.querySelector('.nc-im-thread[data-th=\"'+t+'\"]').click(); return 1}", tid)
    page.wait_for_selector('.nc-msg [data-msg-menu]', timeout=8000)
    own = page.locator('.nc-msg:has([data-msg-del])').last
    own.locator('[data-msg-menu]').click()
    own.locator('[data-msg-del]').click()
    own.locator('[data-msg-del-yes]').click()
    d = dialog(page)
    check('Delete this message for everyone?' in d.inner_text(), 'J6: "Delete for everyone" opens the MEDIUM dialog')
    shot(page, 'J6_delete_message')
    walk(page, 'DELETE MESSAGE')
    page.wait_for_timeout(1500)
    gone = sql("SELECT deleted_at IS NOT NULL FROM messages WHERE id=%s" % msg)
    check(gone == '1', 'J6: message deleted for everyone in the database')
    check(not w.errors and not w.http5 and not w.native, 'J6: no JS errors/5xx/native pop-ups %s %s %s' % (w.errors[:1], w.http5[:1], w.native[:1]))
    page.context.close()


# The workflows write real rows (attendance, lesson sessions, marks, messages,
# department periods). Snapshot every table they touch and restore it at the
# end, so later test suites (e.g. "Day Off refused once attendance exists")
# never trip over journey data.
TOUCHED = ['attendance', 'attendance_sessions', 'attendance_session_records', 'attendance_submission_events',
           'attendance_summary', 'attendance_warnings', 'grade_submissions', 'academic_records', 'assessments',
           'member_department_periods', 'message_threads', 'message_thread_participants', 'messages',
           'message_reads', 'notifications', 'members']
SNAP = '/tmp/journeys_snapshot.sql'


def snapshot():
    have = set(sql("SHOW TABLES").split())
    tables = [t for t in TOUCHED if t in have]
    with open(SNAP, 'w') as fh:
        subprocess.run(['mysqldump', '-ussms', '-pssms', '-h127.0.0.1', '--default-character-set=utf8mb4',
                        '--skip-triggers', '--single-transaction', 'ssms_test'] + tables, stdout=fh, check=True)
    print('snapshot of %d tables saved' % len(tables))


def restore():
    with open(SNAP) as fh:
        r = subprocess.run(['mysql', '-ussms', '-pssms', '-h127.0.0.1', '--default-character-set=utf8mb4', 'ssms_test'],
                           stdin=fh, capture_output=True, text=True)
    print('test data restored' if r.returncode == 0 else 'RESTORE FAILED: ' + r.stderr[:200])


if ONLY in (None, 'B'):
    snapshot()

with sync_playwright() as p:
    br = p.chromium.launch()
    if ONLY in (None, 'A'):
        try:
            run_sweep(br)
        except Exception:
            check(False, 'sweep crashed: ' + traceback.format_exc(limit=2)[-300:])
    if ONLY in (None, 'B'):
        print('\n══ Part B: workflows ══')
        for j in (j1_day_attendance, j2_lesson_cancel, j3_submit_marks, j4_department_status, j5_id_renew, j6_delete_message):
            try:
                j(br)
            except Exception:
                check(False, '%s crashed: %s' % (j.__name__, traceback.format_exc(limit=1)[-300:].replace('\n', ' ')))
    br.close()
if ONLY in (None, 'B'):
    restore()

print('\nJourneys: %d passed, %d failed' % (PASS, FAIL))
for f in FAILS:
    print('  - ' + f)
sys.exit(1 if FAIL else 0)
