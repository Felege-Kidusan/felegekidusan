#!/usr/bin/env python3
"""Phase 3 paging follow-up — screens found after the first pass (local test server only).

Covers: Edu Enrollment roster, Academic Tracking, lesson attendance history,
make-up follow-ups, Super Admin Failure Intelligence (issues + reports), Fleet
telemetry (devices + sync attempts), Mezmur submissions inbox / hymn library /
analytics, and the Attendance Analytics hub. Seeds rows with PG markers and
removes them at the end.

Usage: SSMS_AUDIT_TESTING=1 python3 -u tests/e2e/paging_web_more.py [base_url]
"""
import os, re, subprocess, sys
import requests
from playwright.sync_api import sync_playwright

av = [a for a in sys.argv[1:] if not a.startswith('-')]
BASE = (av[0] if av else 'http://127.0.0.1:8080').rstrip('/')
if 'felegekidusan' in BASE:
    sys.exit('Refusing to run against production.')
PW = 'AuditTest#2026'
SHOTS = '/home/user/SSMS/tests/e2e/shots_paging'
os.makedirs(SHOTS, exist_ok=True)
RES = []
OLD_DAY = '1990-12-31'   # seed dates far in the past so real data is never touched


def ck(name, cond, info=''):
    RES.append((name, bool(cond)))
    print(('PASS ' if cond else 'FAIL ') + name + (('  — ' + str(info)[:300]) if (info and not cond) else ''))


def sql(q):
    r = subprocess.run(['mysql', '-ussms', '-pssms', '-h127.0.0.1', 'ssms_test', '-N', '-B',
                        '--default-character-set=utf8mb4', '-e', q], capture_output=True, text=True)
    if r.returncode:
        print('SQL ERR', r.stderr[:300])
    return r.stdout.strip()


def session(user):
    s = requests.Session()
    h = s.get(BASE + '/admin/index.php').text
    tok = re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
    s.post(BASE + '/admin/backend/login.php', data={'username': user, 'password': PW, 'csrf_token': tok}, allow_redirects=False)
    return s


def cleanup():
    sql("DELETE FROM attendance_sessions WHERE session_date < '1991-01-01'")
    sql("DELETE FROM assessment_followups WHERE member_id IN (SELECT id FROM members WHERE member_code LIKE 'PG-%')")
    sql("DELETE FROM member_department_periods WHERE member_id IN (SELECT id FROM members WHERE member_code LIKE 'PG-%')")
    sql("DELETE FROM failure_reports WHERE report_markdown='PGSEED'")
    sql("DELETE FROM failure_issues WHERE issue_key LIKE 'PG-%'")
    sql("DELETE FROM app_installations WHERE installation_id LIKE 'PGINST%'")
    sql("DELETE FROM api_sync_attempts WHERE request_id LIKE 'PGREQ%'")
    sql("DELETE FROM mezmur_submissions WHERE section='PGSEC'")
    sql("DELETE FROM mezmur_hymns WHERE title LIKE 'PG hymn %'")
    sql("DELETE FROM class_enrollments WHERE member_id IN (SELECT id FROM members WHERE member_code LIKE 'PG-%')")
    sql("DELETE FROM members WHERE member_code LIKE 'PG-%'")


# ── seed ──────────────────────────────────────────────────────────────
cleanup()
sql("DELETE FROM security_rate_limits")
subprocess.run('rm -f /tmp/ssms_ratelimit/* /home/user/SSMS/admin/uploads/cache/rate_*.json', shell=True)
vals = ','.join(f"('PG ሙከራ {i}','PGtest{i:03d}','Seed','PG-{i:04d}','active')" for i in range(1, 131))
sql(f"INSERT INTO members (full_name_am, student_name, father_name, member_code, status) VALUES {vals}")
# lesson attendance: 120 lessons for member 900000
vals = ','.join(f"(1,1,DATE_SUB('{OLD_DAY}', INTERVAL {i} DAY),3,'submitted')" for i in range(120))
sql(f"INSERT INTO attendance_sessions (class_id, subject_id, session_date, teacher_id, status) VALUES {vals}")
sql(f"INSERT INTO attendance_session_records (session_id, member_id, status, recorded_by) "
    f"SELECT id, 900000, 'present', 3 FROM attendance_sessions WHERE session_date < '1991-01-01'")
# follow-ups: one closed Education period + one follow-up per PG member
sql("INSERT INTO member_department_periods (member_id, department, status, started_at) "
    "SELECT id, 'edu', 'inactive', '1990-01-01' FROM members WHERE member_code LIKE 'PG-%'")
sql("INSERT INTO assessment_followups (member_id, period_id, assessment_id, class_id, subject_id) "
    "SELECT p.member_id, p.id, 1, 1, 1 FROM member_department_periods p JOIN members m ON m.id=p.member_id WHERE m.member_code LIKE 'PG-%'")
# failure intelligence
vals = ','.join(f"('PG-{i:03d}','sync','PG issue {i:03d}',NOW(),NOW())" for i in range(60))
sql(f"INSERT INTO failure_issues (issue_key, source, title, first_seen_at, last_seen_at) VALUES {vals}")
sql("INSERT INTO failure_reports (issue_id, window_start, window_end, `trigger`, report_markdown) "
    "SELECT id, NOW() - INTERVAL 1 DAY, NOW(), 'manual', 'PGSEED' FROM failure_issues WHERE issue_key LIKE 'PG-%' LIMIT 45")
# telemetry
vals = ','.join(f"('PGINST{i:04d}','1.7.3')" for i in range(40))
sql(f"INSERT INTO app_installations (installation_id, app_version) VALUES {vals}")
vals = ','.join(f"('PGREQ{i:04d}',1,'grades','PG op','completed','none','none',NOW() - INTERVAL {i} MINUTE)" for i in range(50))
sql(f"INSERT INTO api_sync_attempts (request_id, user_id, domain, operation, status, idempotency_state, retry_decision, started_at) VALUES {vals}")
# mezmur
vals = ','.join(f"(DATE_SUB('{OLD_DAY}', INTERVAL {i} DAY),'PGSEC','approved')" for i in range(70))
sql(f"INSERT INTO mezmur_submissions (attendance_date, section, status) VALUES {vals}")
vals = ','.join(f"(DATE_SUB('{OLD_DAY}', INTERVAL {100 + i} DAY),'PGSEC','submitted')" for i in range(27))
sql(f"INSERT INTO mezmur_submissions (attendance_date, section, status) VALUES {vals}")
vals = ','.join(f"('PG hymn {i:03d}')" for i in range(70))
sql(f"INSERT INTO mezmur_hymns (title) VALUES {vals}")

N_FI = int(sql("SELECT COUNT(*) FROM failure_issues"))
N_FR = int(sql("SELECT COUNT(*) FROM failure_reports"))
N_LA = int(sql("SELECT COUNT(*) FROM attendance_session_records WHERE member_id=900000"))
N_INST = int(sql("SELECT COUNT(*) FROM app_installations"))
N_MZS = int(sql("SELECT COUNT(*) FROM mezmur_submissions WHERE status='approved'"))
N_MZQ = int(sql("SELECT COUNT(*) FROM mezmur_submissions WHERE status='submitted'"))
N_HYMN = int(sql("SELECT COUNT(*) FROM mezmur_hymns WHERE status='active'"))
print(f'seeded: issues={N_FI} reports={N_FR} lessons={N_LA} installs={N_INST} mz_subs={N_MZS} hymns={N_HYMN}')


def txt(page, sel):
    try:
        return page.locator(sel).first.inner_text(timeout=3000)
    except Exception:
        return ''


def eshot(page, sel, name):
    try:
        page.locator(sel).first.scroll_into_view_if_needed(timeout=3000)
        page.locator(sel).first.screenshot(path=SHOTS + '/el_' + name + '.png', timeout=3000)
    except Exception as e:
        print('  (shot skipped', name, str(e)[:80], ')')


def wait_txt(page, sel, pattern, ms=8000):
    for _ in range(ms // 250):
        if re.search(pattern, txt(page, sel)):
            return True
        page.wait_for_timeout(250)
    return False


try:
    # ── API: new opt-in total for Mezmur analytics; default unchanged ──
    mz = session('audit_mez')
    d = mz.get(BASE + '/admin/api_mezmur.php?action=analytics_members&page=1&per_page=25').json()
    ck('A01 Mezmur analytics default: no total (mobile contract)', d.get('status') == 'success' and 'total' not in d, list(d.keys()))
    d = mz.get(BASE + '/admin/api_mezmur.php?action=analytics_members&with_total=1&page=2&per_page=25').json()
    n_active = int(sql("SELECT COUNT(*) FROM members WHERE status='active'"))
    ck('A02 Mezmur analytics with_total: total = active members', d.get('total') == n_active and len(d.get('items', [])) == 25, {k: d.get(k) for k in ('total', 'page', 'total_pages')})
    ck('A03 Mezmur analytics with_total: total_pages', d.get('total_pages') == -(-n_active // 25), d.get('total_pages'))
    d = mz.get(BASE + '/admin/api_mezmur.php?action=analytics_members&with_total=1&min_attended=999').json()
    ck('A04 Mezmur analytics: total follows HAVING filters', d.get('total') == 0 and d.get('items') == [], d.get('total'))
    edu = session('audit_edu')
    d = edu.get(BASE + '/admin/api_education.php?action=roster&page=1&per_page=200').json()
    ck('A05 Edu roster: per_page 200 accepted', d.get('per_page') == 200, d.get('per_page'))

    with sync_playwright() as p:
        br = p.chromium.launch()

        def open_as(user, url):
            ctx = br.new_context(viewport={'width': 1400, 'height': 1000})
            page = ctx.new_page()
            errs = []
            page.on('pageerror', lambda e: errs.append(str(e)))
            page.goto(BASE + '/admin/index.php')
            page.fill('input[name=username]', user)
            page.fill('input[name=password]', PW)
            page.click('button[type=submit]')
            page.wait_for_load_state('networkidle')
            page.goto(BASE + url)
            page.wait_for_load_state('networkidle')
            return ctx, page, errs

        # C1 Edu Enrollment roster (shared pager)
        ctx, page, errs = open_as('audit_edu', '/admin/dashboards/edu_dept.php')
        page.evaluate("()=>{localStorage.removeItem('fkss_pp_edu_roster');nav('enrollment');switchEnrollTab('roster');return 1}")
        page.wait_for_timeout(1500)
        rr = '#rosterPager [data-fk-pager-range]'
        total_txt = txt(page, rr)
        m = re.search(r'of (\d+)', total_txt)
        n_roster = int(m.group(1)) if m else 0
        ck('C01 Roster: range 1–25 of N', re.search(r'1[–-]25 of \d+', total_txt) and n_roster > 100, total_txt)
        page.click('#rosterPager [data-fk-pager-next]')
        ck('C02 Roster: Next -> 26–50', wait_txt(page, rr, r'26[–-]50 of'), txt(page, rr))
        page.click('#rosterPager [data-fk-pager-more]')
        ck('C03 Roster: Load more -> 26–75', wait_txt(page, rr, r'26[–-]75 of'), txt(page, rr))
        page.select_option('#rosterPager [data-fk-pager-size]', '200')
        ck('C04 Roster: per page 200', wait_txt(page, rr, rf'1[–-]{min(200, n_roster)} of'), txt(page, rr))
        page.select_option('#rosterPager [data-fk-pager-size]', '25')
        page.wait_for_timeout(800)
        page.click('#rosterPager [data-fk-pager-next]')
        wait_txt(page, rr, r'26[–-]50 of')
        has_search = page.evaluate("()=>!!document.getElementById('rosterQ')")
        if has_search:
            page.fill('#rosterQ', 'PGtest12')
            ck('C05 Roster: search resets to page 1 and narrows', wait_txt(page, rr, r'1[–-]1[01] of 1[01]\b'), txt(page, rr))
        else:
            ck('C05 Roster: search box present', False, 'no #rosterSearch')
        # bulk enroll from page 2 keeps page 2
        page.fill('#rosterQ', 'PGtest')
        wait_txt(page, rr, r'1[–-]25 of 130\b')
        page.click('#rosterPager [data-fk-pager-next]')
        ck('C05b Roster: PG search page 2', wait_txt(page, rr, r'26[–-]50 of 130\b'), txt(page, rr))
        page.evaluate("()=>{const c=[...document.querySelectorAll('.roster-cb')].slice(0,2);c.forEach(x=>{x.checked=true;x.dispatchEvent(new Event('change',{bubbles:true}))});return c.length}")
        page.select_option('#rosterTargetClass', '1')
        page.click('button[onclick*="rosterBulkEnroll"]')
        page.locator('[role=alertdialog]').wait_for(state='visible', timeout=5000)
        page.click('text=I want to enrol these students')
        page.click('text=I have read and understand these effects')
        page.fill('.dcx-in', 'ENROLL STUDENTS')
        page.click('[data-dcx-act=final]')
        page.wait_for_timeout(1500)
        n_enr = sql("SELECT COUNT(*) FROM class_enrollments e JOIN members m ON m.id=e.member_id WHERE m.member_code LIKE 'PG-%'")
        ck('C05c Roster: bulk enroll saved 2', n_enr == '2', n_enr)
        ck('C05d Roster: still on page 2 after bulk enroll', wait_txt(page, rr, r'26[–-]50 of 130\b'), txt(page, rr))
        page.screenshot(path=SHOTS + '/edu_roster.png')
        ck('C06 Roster: no JS errors', not errs, errs)

        # C2 Academic Tracking (own numbered pager)
        page.evaluate("()=>{localStorage.removeItem('fkss_pp_acad_tracking');nav('academic-tracking');return 1}")
        page.wait_for_timeout(1200)
        page.click('#sec-academic-tracking [data-open="students"]')
        page.wait_for_timeout(1500)
        at = '#sec-academic-tracking'
        at_size = page.evaluate("()=>[...document.querySelectorAll('#sec-academic-tracking [data-per-page] option')].map(o=>o.value)")
        ck('C07 Academic Tracking: per page 25/50/100', all(v in at_size for v in ['25', '50', '100']), at_size)
        ck('C07b Academic Tracking students: Showing 1–25 of N', wait_txt(page, at, r'Showing 1[–-]25 of [\d,]+'), txt(page, at)[-300:])
        page.click(at + ' button[data-page="2"]')
        ck('C07c Academic Tracking: page 2 -> 26–50', wait_txt(page, at, r'Showing 26[–-]50 of'), txt(page, at)[-300:])
        page.click(at + ' button[data-more]')
        ck('C07d Academic Tracking: Load more -> 26–75', wait_txt(page, at, r'Showing 26[–-]75 of'), txt(page, at)[-300:])
        page.select_option(at + ' [data-per-page]', '100')
        ck('C07e Academic Tracking: per page 100 -> 1–100', wait_txt(page, at, r'Showing 1[–-]100 of'), txt(page, at)[-300:])
        ck('C08 Academic Tracking: no JS errors', not errs, errs)
        page.screenshot(path=SHOTS + '/academic_tracking.png')
        ctx.close()

        # C3 Lesson attendance history (Load more on the same list)
        ctx, page, errs = open_as('audit_super', '/admin/lesson_attendance.php?member_id=900000')
        page.evaluate("()=>{localStorage.removeItem('fkss_pp_lesson_att');return 1}")
        page.reload(); page.wait_for_load_state('networkidle'); page.wait_for_timeout(800)
        meta = txt(page, '#la-meta')
        ck('C09 Lesson attendance: range 1–50 of N', re.search(rf'1[–-]50 of {N_LA}', meta), meta)
        page.click('#la-more')
        ck('C10 Lesson attendance: Load more -> 1–100', wait_txt(page, '#la-meta', rf'1[–-]100 of {N_LA}'), txt(page, '#la-meta'))
        page.select_option('#la-size', '200')
        ck('C11 Lesson attendance: per page 200 -> all', wait_txt(page, '#la-meta', rf'1[–-]{N_LA} of {N_LA}'), txt(page, '#la-meta'))
        ck('C12 Lesson attendance: Load more hidden/disabled when all shown',
           page.evaluate("()=>{const b=document.getElementById('la-more');return b.hidden||b.disabled}"))
        ck('C13 Lesson attendance: no JS errors', not errs, errs)
        page.screenshot(path=SHOTS + '/lesson_attendance.png')
        eshot(page, '#la-more', 'la_more')

        # C4 Make-up follow-ups
        page.goto(BASE + '/admin/assessment_followups.php'); page.wait_for_load_state('networkidle')
        page.evaluate("()=>{localStorage.removeItem('fkss_pp_followups');return 1}")
        page.reload(); page.wait_for_load_state('networkidle'); page.wait_for_timeout(1000)
        body = page.inner_text('body')
        if 'not enabled' in body.lower() or page.locator('#af-size').count() == 0:
            ck('C14 Follow-ups: page available (attendance_v2)', False, body[:200])
        else:
            ck('C14 Follow-ups: per page + Load more controls', page.locator('#af-size').count() == 1 and page.locator('#af-more').count() == 1)
            ok = wait_txt(page, '#af-meta, .af-meta, #af-range', r'1[–-]50 of \d+')
            ck('C15 Follow-ups: range 1–50 of N', ok, txt(page, '#af-meta, .af-meta, #af-range'))
            if ok:
                page.click('#af-more')
                ck('C16 Follow-ups: Load more -> 1–100', wait_txt(page, '#af-meta, .af-meta, #af-range', r'1[–-]100 of'), txt(page, '#af-meta, .af-meta, #af-range'))
        ck('C17 Follow-ups: no JS errors', not errs, errs)
        page.screenshot(path=SHOTS + '/followups.png')
        ctx.close()

        # C5 Failure Intelligence (shared pager x2)
        ctx, page, errs = open_as('audit_super', '/admin/dashboards/super-admin.php')
        page.evaluate("()=>{localStorage.removeItem('fkss_pp_fi_issues');localStorage.removeItem('fkss_pp_fi_reports');return 1}")
        page.click('.nav-link[data-section="failure_intelligence"]')
        page.wait_for_timeout(2000)
        ir = '#fi-issues-pager [data-fk-pager-range]'
        ck('C18 Failure issues: range 1–25 of N', wait_txt(page, ir, r'1[–-]25 of \d+'), txt(page, ir) or txt(page, '#fi-issues-pager'))
        page.click('#fi-issues-pager [data-fk-pager-next]')
        ck('C19 Failure issues: Next -> 26–50', wait_txt(page, ir, r'26[–-]50 of'), txt(page, ir))
        page.click('#fi-issues-pager [data-fk-pager-more]')
        ck('C20 Failure issues: Load more appends rows', wait_txt(page, ir, rf'26[–-]{min(75, N_FI)} of {N_FI}') and page.locator('#fi-issues-body tr').count() == min(75, N_FI) - 25, txt(page, ir))
        rr2 = '#fi-reports-pager [data-fk-pager-range]'
        ck('C21 Failure reports: range of all reports', wait_txt(page, rr2, rf'1[–-]25 of {N_FR}'), txt(page, rr2) or txt(page, '#fi-reports-pager'))
        page.select_option('#fi-reports-pager [data-fk-pager-size]', '50')
        ck('C22 Failure reports: per page 50', wait_txt(page, rr2, rf'1[–-]{min(50, N_FR)} of {N_FR}') and page.locator('#fi-reports-body tr').count() == min(50, N_FR), txt(page, rr2))
        ck('C23 Failure Intelligence: no JS errors', not errs, errs)
        page.screenshot(path=SHOTS + '/failure_intelligence.png')
        eshot(page, '#fi-issues-pager', 'fi_issues'); eshot(page, '#fi-reports-pager', 'fi_reports')

        # C6 Fleet telemetry: devices + sync attempts
        page.evaluate("()=>{localStorage.removeItem('fkss_pp_telemetry');localStorage.removeItem('fkss_pp_sync_attempts');return 1}")
        page.click('.nav-link[data-section="app_telemetry"]')
        page.wait_for_timeout(1500)
        page.select_option('#telemetry-size', '15')
        page.wait_for_timeout(1200)
        ck('C24 Devices: range 1–15 of N', wait_txt(page, '#telemetry-page-info', rf'1[–-]15 of {N_INST}'), txt(page, '#telemetry-page-info'))
        page.click('#btn-telemetry-more')
        ck('C25 Devices: Load more -> 1–30', wait_txt(page, '#telemetry-page-info', rf'1[–-]30 of {N_INST}') and page.locator('#telemetry-table-body tr').count() == 30, txt(page, '#telemetry-page-info'))
        page.click('#btn-telemetry-next')
        ck('C26 Devices: Next after Load more -> 31–N', wait_txt(page, '#telemetry-page-info', rf'31[–-]{N_INST} of {N_INST}'), txt(page, '#telemetry-page-info'))
        ck('C27 Devices: Load more shows all-shown state', wait_txt(page, '#btn-telemetry-more', r'All \d+ shown'), txt(page, '#btn-telemetry-more'))
        page.select_option('#sync-monitor-size', '20')
        page.wait_for_timeout(1200)
        ck('C28 Sync attempts: range 1–20 of N', wait_txt(page, '#sync-monitor-page-info', r'1[–-]20 of \d+'), txt(page, '#sync-monitor-page-info'))
        page.click('#sync-monitor-more')
        ck('C29 Sync attempts: Load more -> 1–40', wait_txt(page, '#sync-monitor-page-info', r'1[–-]40 of \d+'), txt(page, '#sync-monitor-page-info'))
        ck('C30 Telemetry: no JS errors', not errs, errs)
        page.screenshot(path=SHOTS + '/telemetry.png')
        eshot(page, '#telemetry-pagination', 'telemetry'); eshot(page, '#sync-monitor-more', 'sync_more')
        ctx.close()

        # C7 Mezmur: submissions inbox, hymn library, analytics
        ctx, page, errs = open_as('audit_mez', '/frontend/pages/mezmur_dept.php')
        page.evaluate("()=>{['fkss_pp_mz_subs','fkss_pp_mz_lib','fkss_pp_mz_an'].forEach(k=>localStorage.removeItem(k));return 1}")
        page.reload(); page.wait_for_load_state('networkidle')
        page.evaluate("()=>{window.switchSection('attendance');Mezmur.switchSubTab('approved');return 1}")
        ck('C31 Mezmur inbox: range 1–50 of all approved', wait_txt(page, '#mzSubRange', rf'1[–-]50 of {N_MZS}'), txt(page, '#mzSubRange') or txt(page, '#mzSubPagination'))
        ck('C32 Mezmur inbox: 50 rows', page.locator('#mzSubTbody tr').count() == 50, page.locator('#mzSubTbody tr').count())
        page.click('#mzSubMore')
        ck('C33 Mezmur inbox: Load more -> all', wait_txt(page, '#mzSubRange', rf'1[–-]{N_MZS} of {N_MZS}') and page.locator('#mzSubTbody tr').count() == N_MZS, txt(page, '#mzSubRange'))
        page.select_option('#mzSubSize', '25')
        eshot(page, '#mzSubPagination', 'mz_inbox')
        ck('C34 Mezmur inbox: per page 25', wait_txt(page, '#mzSubRange', rf'1[–-]25 of {N_MZS}'), txt(page, '#mzSubRange'))
        page.click('#mzSubNext')
        ck('C35 Mezmur inbox: Next -> 26–50', wait_txt(page, '#mzSubRange', rf'26[–-]50 of {N_MZS}'), txt(page, '#mzSubRange'))
        page.evaluate("()=>{Mezmur.switchSubTab('draft');return 1}")
        page.wait_for_timeout(800)
        page.evaluate("()=>{Mezmur.switchSubTab('approved');return 1}")
        ck('C36 Mezmur inbox: tab change resets to page 1', wait_txt(page, '#mzSubRange', rf'1[–-]25 of {N_MZS}'), txt(page, '#mzSubRange'))
        # a decision on page 2 keeps page 2; when page 2 empties, the last page shows
        def approve_first():
            page.click('#mzSubTbody tr:first-child button[title="Approve now"]')
            dlg = page.locator('[role=alertdialog]'); dlg.wait_for(state='visible', timeout=5000)
            page.click('text=I want to approve this packet')
            page.click('text=I have read and understand these effects')
            page.fill('.dcx-in', 'APPROVE')
            page.click('[data-dcx-act=final]')
        page.evaluate("()=>{Mezmur.switchSubTab('submitted');return 1}")
        ck('C36b Mezmur queue: 1–25 of submitted', wait_txt(page, '#mzSubRange', rf'1[–-]25 of {N_MZQ}'), txt(page, '#mzSubRange'))
        page.click('#mzSubNext')
        ck('C36c Mezmur queue: Next -> 26–27', wait_txt(page, '#mzSubRange', rf'26[–-]{N_MZQ} of {N_MZQ}'), txt(page, '#mzSubRange'))
        approve_first()
        ck('C36d Mezmur queue: approve on page 2 stays on page 2', wait_txt(page, '#mzSubRange', rf'26[–-]{N_MZQ - 1} of {N_MZQ - 1}'), txt(page, '#mzSubRange'))
        approve_first()
        ck('C36e Mezmur queue: page 2 emptied -> last page shown', wait_txt(page, '#mzSubRange', rf'1[–-]25 of {N_MZQ - 2}'), txt(page, '#mzSubRange'))
        page.evaluate("()=>{Mezmur.switchSubTab('insights');return 1}")
        page.wait_for_timeout(500)
        ck('C37 Mezmur inbox: pager hidden on Insights', page.evaluate("()=>document.getElementById('mzSubPagination').classList.contains('is-hidden')"))
        page.screenshot(path=SHOTS + '/mezmur_inbox.png')

        page.evaluate("()=>{window.switchSection('library');return 1}")
        ck('C38 Hymn library: range 1–25 of N', wait_txt(page, '#mzLibRange', rf'1[–-]25 of {N_HYMN}'), txt(page, '#mzLibRange') or txt(page, '#mzPagination'))
        page.click('#mzLibMore')
        ck('C39 Hymn library: Load more -> 1–50', wait_txt(page, '#mzLibRange', rf'1[–-]50 of {N_HYMN}'), txt(page, '#mzLibRange'))
        page.click('#mzLibNext')
        ck('C40 Hymn library: Next after Load more -> 51–', wait_txt(page, '#mzLibRange', rf'51[–-]\d+ of {N_HYMN}'), txt(page, '#mzLibRange'))
        page.select_option('#mzLibSize', '100')
        ck('C41 Hymn library: per page 100', wait_txt(page, '#mzLibRange', rf'1[–-]{min(100, N_HYMN)} of {N_HYMN}'), txt(page, '#mzLibRange'))
        page.screenshot(path=SHOTS + '/mezmur_library.png')
        eshot(page, '#mzPagination', 'mz_library')

        page.evaluate("()=>{window.switchSection('analytics');return 1}")
        page.wait_for_timeout(800)
        page.evaluate("()=>{Mezmur.runAnalytics(1);return 1}")
        ck('C42 Mezmur analytics: range 1–25 of all members', wait_txt(page, '#mzAnRange', rf'1[–-]25 of {n_active}'), txt(page, '#mzAnRange') or txt(page, '#mzAnPagination'))
        ck('C43 Mezmur analytics: Members card shows the real total', wait_txt(page, '#mzAnMembers', rf'^{n_active}$'), txt(page, '#mzAnMembers'))
        page.click('#mzAnMore')
        ck('C44 Mezmur analytics: Load more -> 1–50, rank continues', wait_txt(page, '#mzAnRange', rf'1[–-]50 of {n_active}') and txt(page, '#mzAnTbody tr:nth-child(50) td:first-child').strip() == '50', txt(page, '#mzAnRange'))
        page.click('#mzAnNext')
        ck('C45 Mezmur analytics: Next -> 51–75, rank 51', wait_txt(page, '#mzAnRange', rf'51[–-]75 of {n_active}') and txt(page, '#mzAnTbody tr:first-child td:first-child').strip() == '51', txt(page, '#mzAnRange'))
        ck('C46 Mezmur: no JS errors', not errs, errs)
        page.screenshot(path=SHOTS + '/mezmur_analytics.png')
        eshot(page, '#mzAnPagination', 'mz_analytics')
        ctx.close()

        # C8 Attendance Analytics hub loads cleanly
        ctx, page, errs = open_as('audit_info', '/admin/dashboards/info-dept.php')
        page.wait_for_timeout(1500)
        ck('C47 Attendance Analytics hub: no JS errors', not errs, errs)
        ctx.close()
        br.close()
finally:
    cleanup()

passed = sum(1 for _, ok in RES if ok)
print(f'\n{passed}/{len(RES)} passed')
sys.exit(0 if passed == len(RES) else 1)
