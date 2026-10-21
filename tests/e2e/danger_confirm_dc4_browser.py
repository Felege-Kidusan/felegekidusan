"""Browser test for danger-confirmation DC-4 on the real Finance, Groups,
Material, Mezmur, Content editor and Teacher screens (LOCAL test server only).

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/danger_confirm_dc4_browser.py [base] [--shots DIR]
"""
import os, sys, time, subprocess
from playwright.sync_api import sync_playwright

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: set SSMS_AUDIT_TESTING=1 (local disposable test DB only).')
_av = sys.argv[1:]
if '--shots' in _av:
    i = _av.index('--shots'); _av = _av[:i] + _av[i + 2:]
args = [a for a in _av if not a.startswith('--')]
BASE = (args[0] if args else 'http://127.0.0.1:8080').rstrip('/')
if 'felegekidusan' in BASE:
    sys.exit('Refusing to run against production.')
SHOTS = sys.argv[sys.argv.index('--shots') + 1] if '--shots' in sys.argv else None
if SHOTS:
    os.makedirs(SHOTS, exist_ok=True)
PASS = FAIL = 0


def check(cond, msg):
    global PASS, FAIL
    if cond:
        PASS += 1; print('PASS', msg)
    else:
        FAIL += 1; print('FAIL', msg)


def sql(q):
    return subprocess.run(['mysql', '-ussms', '-pssms', '-h127.0.0.1', 'ssms_test', '--default-character-set=utf8mb4', '-N', '-B', '-e', q],
                          capture_output=True, text=True).stdout.strip()


def shot(page, name):
    if SHOTS:
        page.wait_for_timeout(500)
        page.screenshot(path=os.path.join(SHOTS, name + '.png'))


def login(br, user, page_url):
    page = br.new_context(viewport={'width': 1366, 'height': 900}).new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('dialog', lambda d: (errors.append('NATIVE DIALOG: ' + d.message), d.dismiss()))
    page.goto(BASE + '/admin/index.php')
    page.fill('#username', user)
    page.fill('#password', 'AuditTest#2026')
    page.click('button[type=submit]')
    page.wait_for_load_state('networkidle')
    page.goto(BASE + page_url)
    page.wait_for_load_state('networkidle')
    return page, errors


def dialog(page):
    d = page.locator('[role=alertdialog]')
    d.wait_for(state='visible', timeout=6000)
    return d


def cancel(page):
    page.click('[data-dcx-act=cancel]')
    page.wait_for_function("!document.querySelector('[role=alertdialog]') || document.querySelector('[role=alertdialog]').offsetParent===null", timeout=6000)


def native(errs):
    return [e for e in errs if e.startswith('NATIVE DIALOG')]


tag = str(int(time.time()))[-6:]
with sync_playwright() as p:
    br = p.chromium.launch()

    # ───────── Finance: delete transaction (HIGH) ─────────
    sql(f"INSERT INTO finance_transactions (type, amount, description, transaction_date, receipt_number) VALUES ('income', 250, 'DC4 UI {tag}', CURDATE(), 'UI-{tag}')")
    tid = sql(f"SELECT id FROM finance_transactions WHERE receipt_number='UI-{tag}'")
    page, errs = login(br, 'audit_fin', '/admin/dashboards/finance_department.php')
    check(page.evaluate('!!window.DangerConfirm'), 'finance: component loaded')
    page.evaluate("()=>{window.__p=loadTxns('income');return 1}")
    page.wait_for_timeout(800)
    page.evaluate(f"()=>{{window.__p=deleteTxn({tid},'income');return 1}}")
    d = dialog(page)
    txt = d.inner_text()
    check('Delete this income record' in txt and 'UI-' + tag in txt, 'finance: HIGH dialog names the amount/receipt')
    page.click('text=I want to delete this transaction')
    check('no undo' in dialog(page).inner_text(), 'finance: step 2 says there is no undo')
    page.click('text=I have read and understand these effects')
    check(page.locator('[data-dcx-act=final]').is_disabled(), 'finance: final disabled before typing')
    page.fill('.dcx-in', 'DELETE TRANSACTION')
    shot(page, 'finance_delete_step3')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1500)
    check(sql(f"SELECT COUNT(*) FROM finance_transactions WHERE id={tid}") == '0', 'finance: transaction deleted after the 3 steps')
    check(not native(errs), 'finance: no native popups ' + str(native(errs)))
    page.close()

    # ───────── Groups: delete group (HIGH, live counts) + remove leader (MEDIUM) ─────────
    sql(f"INSERT INTO wbws_groups (group_name) VALUES ('DC4 UI Group {tag}')")
    gid = sql(f"SELECT id FROM wbws_groups WHERE group_name='DC4 UI Group {tag}'")
    sql(f"INSERT INTO wbws_group_leaders (group_id, leader_full_name) VALUES ({gid}, 'L1')")
    sql(f"INSERT INTO wbws_group_members (group_id, full_name) VALUES ({gid}, 'M1'), ({gid}, 'M2')")
    page, errs = login(br, 'audit_hr', '/admin/groups.php')
    check(page.evaluate('!!window.DangerConfirm'), 'groups: component loaded')
    page.evaluate("()=>{window.__p=deleteLeader(1);return 1}")
    d = dialog(page)
    check('REMOVE LEADER' in d.inner_text() or 'I want to remove this leader' in d.inner_text(), 'groups: remove leader opens MEDIUM dialog')
    cancel(page)
    page.evaluate(f"()=>{{window.__p=confirmDel({gid}, 'DC4 UI Group {tag}');return 1}}")
    d = dialog(page)
    page.click('text=I want to delete this group')
    page.wait_for_function("/1 leader record|2 member record/.test((document.querySelector('[role=alertdialog]')||{}).innerText||'')", timeout=6000)
    txt = dialog(page).inner_text()
    check('1 leader record' in txt and '2 member record' in txt, 'groups: step 2 shows the live counts (1 leader, 2 members)')
    shot(page, 'groups_delete_step2')
    page.click('text=I have read and understand these effects')
    page.fill('.dcx-in', 'DELETE GROUP')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1500)
    check(sql(f"SELECT COUNT(*) FROM wbws_groups WHERE id={gid}") == '0' and sql(f"SELECT COUNT(*) FROM wbws_group_members WHERE group_id={gid}") == '0',
          'groups: group and its members deleted after the 3 steps')
    check(not native(errs), 'groups: no native popups ' + str(native(errs)))
    page.close()

    # ───────── Material: delete item (MEDIUM) + approve request (LOW) ─────────
    page, errs = login(br, 'audit_super', '/admin/dashboards/material_department.php')
    if page.evaluate("typeof delItem === 'function'"):
        check(page.evaluate('!!window.DangerConfirm'), 'material: component loaded')
        page.evaluate("()=>{window.__p=delItem(999999);return 1}")
        d = dialog(page)
        check('I want to delete this item' in d.inner_text(), 'material: delete item opens MEDIUM dialog')
        cancel(page)
        page.evaluate("()=>{window.__p=updateReq(999999,'approved');return 1}")
        d = dialog(page)
        check(page.locator('.dcx-in').count() == 0 and 'Approve' in d.inner_text(), 'material: approve request is a simple LOW dialog')
        cancel(page)
        check(not native(errs), 'material: no native popups ' + str(native(errs)))
    else:
        check(False, 'material: page opened for super admin')
    page.close()

    # ───────── Content editor: delete photo (MEDIUM) ─────────
    page, errs = login(br, 'audit_super', '/admin/dashboards/content_editor.php')
    if page.evaluate("typeof deletePhoto === 'function'"):
        page.evaluate("()=>{window.__p=deletePhoto(999999);return 1}")
        d = dialog(page)
        check('I want to delete this photo' in d.inner_text(), 'cms: delete photo opens MEDIUM dialog')
        shot(page, 'cms_delete_photo')
        cancel(page)
        page.evaluate("()=>{window.__p=deleteTeacher(999999);return 1}")
        d = dialog(page)
        check('NOT affected' in (page.click('text=I want to delete this profile') or dialog(page).inner_text()), 'cms: website teacher delete says the staff account is NOT affected')
        cancel(page)
        check(not native(errs), 'cms: no native popups ' + str(native(errs)))
    else:
        check(False, 'cms: content editor opened for super admin')
    page.close()

    # ───────── Mezmur: disable taker (MEDIUM), archive hymn (MEDIUM) ─────────
    sql(f"INSERT INTO users (username, full_name, role, password_hash, is_active) VALUES ('dc4mz_{tag}', 'DC4 Mezmur Taker', 'mezmur_attendance_taker', 'x', 1)")
    page, errs = login(br, 'audit_mez', '/frontend/pages/mezmur_dept.php')
    check(page.evaluate('!!(window.DangerConfirm && window.Mezmur)'), 'mezmur: component + Mezmur loaded')
    page.evaluate("()=>{window.__p=Mezmur.reloadTakers();return 1}")
    page.wait_for_timeout(1200)
    tk = sql(f"SELECT id FROM users WHERE username='dc4mz_{tag}'")
    page.evaluate(f"()=>{{Mezmur.toggleTaker({tk});return 1}}")
    d = dialog(page)
    check('I want to disable this taker' in d.inner_text(), 'mezmur: disable taker opens MEDIUM dialog')
    shot(page, 'mezmur_disable_taker')
    cancel(page)
    check(sql(f"SELECT is_active FROM users WHERE id={tk}") == '1', 'mezmur: cancel leaves the taker active')
    page.evaluate("()=>{Mezmur.setStatus(999999,'archived');return 1}")
    d = dialog(page)
    check('I want to archive this hymn' in d.inner_text(), 'mezmur: archive hymn opens MEDIUM dialog (was one-click)')
    cancel(page)
    check(not native(errs), 'mezmur: no native popups ' + str(native(errs)))
    sql(f"DELETE FROM users WHERE id={tk}")
    page.close()

    # ───────── Teacher: re-saving changed attendance (T1) ─────────
    page, errs = login(br, 'audit_teach', '/admin/dashboards/teacher.php')
    check(page.evaluate('!!(window.DangerConfirm && AttendanceSheet.confirmReplace)'), 'teacher: component + confirmReplace loaded')
    r = page.evaluate("()=>AttendanceSheet.confirmReplace([{member_id:1,status:'present'}],{1:'present'},()=> 'X').then(v=>v)")
    check(r is True and page.locator('[role=alertdialog]').count() == 0, 'teacher: unchanged / first save goes straight through (no dialog)')
    page.evaluate("()=>{window.__r=AttendanceSheet.confirmReplace([{member_id:5,status:'absent'},{member_id:6,status:'late'}],{5:'present'},(id)=>id==5?'Abebe Kebede':'');return 1}")
    d = dialog(page)
    page.click('text=I want to change saved attendance')
    txt = dialog(page).inner_text()
    check('Abebe Kebede: present → absent' in txt, 'teacher: dialog lists who changes (Abebe Kebede: present → absent)')
    shot(page, 'teacher_replace_attendance')
    cancel(page)
    check(page.evaluate('window.__r.then(v=>v)') is False, 'teacher: cancel means the sheet is not saved')
    check(not native(errs), 'teacher: no native popups ' + str(native(errs)))
    page.close()
    br.close()

print(f'\nDC-4 browser: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
