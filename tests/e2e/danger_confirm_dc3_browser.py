"""Browser test for danger-confirmation DC-3 on the real Super Admin, HR and
Information dashboards (LOCAL test server only).

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/danger_confirm_dc3_browser.py [base] [--shots DIR]
"""
import io, os, sys, time, subprocess
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


def login(ctx, user, page_url):
    # fresh browser context per role so sessions never leak between logins
    page = ctx.browser.new_context(viewport={'width': 1366, 'height': 900}).new_page()
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


def gone(page):
    page.wait_for_function("!document.querySelector('[role=alertdialog]') || document.querySelector('[role=alertdialog]').offsetParent===null", timeout=6000)
    return True


def walk(page, intent, phrase, final_click=True):
    dialog(page)
    page.click('text=' + intent)
    page.click('text=I have read and understand these effects')
    final = page.locator('[data-dcx-act=final]')
    was_disabled = final.is_disabled()
    page.fill('.dcx-in', phrase)
    if final_click:
        final.click()
    return was_disabled


def cancel(page):
    page.click('[data-dcx-act=cancel]')
    return gone(page)


def native(errs):
    return [e for e in errs if e.startswith('NATIVE DIALOG')]


tag = str(int(time.time()))[-6:]
with sync_playwright() as p:
    br = p.chromium.launch()
    ctx = br.new_context(viewport={'width': 1366, 'height': 900})

    # ───────── Super Admin: users ─────────
    sql(f"INSERT INTO users (username, full_name, role, password_hash, is_active) VALUES ('dc3ui_{tag}', 'DC3 UI target', 'teacher', 'x', 1)")
    uid = sql(f"SELECT id FROM users WHERE username='dc3ui_{tag}'")
    page, errs = login(ctx, 'audit_super', f'/admin/users.php?edit_id={uid}')
    check(page.evaluate('!!window.DangerConfirm'), 'super: component loaded on users.php')
    page.select_option('#dcUserSaveForm [name=role]', 'finance_dept')
    page.locator('#dcUserSaveForm [type=submit]').first.click()
    d = dialog(page)
    check('Change the role' in d.inner_text() and 'I want to change this role' in d.inner_text(), 'super: role change opens MEDIUM "Change the role" dialog')
    shot(page, 'super_change_role')
    cancel(page)
    check(sql(f"SELECT role FROM users WHERE id={uid}") == 'teacher', 'super: cancelling the role dialog changes nothing')

    page.goto(BASE + f'/admin/users.php?delete_id={uid}')
    page.wait_for_load_state('networkidle')
    page.fill('#superadmin_password', 'AuditTest#2026')
    page.locator('#dcDeleteUserForm [type=submit]').first.click()
    d = dialog(page)
    check('Delete' in d.inner_text() and 'I want to delete this user' in d.inner_text(), 'super: delete user opens HIGH dialog (step 1 names the user)')
    dis = walk(page, 'I want to delete this user', 'DELETE USER', final_click=False)
    check(dis, 'super: delete final button disabled before typing')
    check('DELETE USER' in dialog(page).inner_text(), 'super: step 3 asks for DELETE USER')
    shot(page, 'super_delete_user_step3')
    with page.expect_navigation():
        page.click('[data-dcx-act=final]')
    page.wait_for_load_state('networkidle')
    check(sql(f"SELECT COUNT(*) FROM users WHERE id={uid}") == '0', 'super: user deleted after the 3 steps (F9 CSRF fixed)')

    # system health LOW form
    page.goto(BASE + '/admin/system_health.php')
    page.wait_for_load_state('networkidle')
    f = page.locator('form[data-dc-low] button').first
    if f.count():
        f.click()
        d = dialog(page)
        check('Clear' in d.inner_text() and page.locator('.dcx-in').count() == 0, 'super: system health clear opens a simple LOW dialog (no typing)')
        shot(page, 'super_system_health_low')
        cancel(page)
    else:
        check(False, 'super: system health clear form found')

    # identity renumber + app release on the dashboard
    page.goto(BASE + '/admin/dashboards/super-admin.php')
    page.wait_for_load_state('networkidle')
    has_mig = page.evaluate("!!document.getElementById('idcExecBtn')")
    if has_mig:
        page.evaluate("()=>{document.getElementById('idcExecBtn').click();return 1}")
        d = dialog(page)
        page.locator('.dcx-btn-go').first.click()
        page.click('text=I have read and understand these effects')
        check('RENUMBER CODES' in dialog(page).inner_text(), 'super: identity renumber opens HIGH dialog asking for RENUMBER CODES')
        shot(page, 'super_identity_renumber')
        cancel(page)
    else:
        check(True, 'super: identity section not on this page (skipped)')
    has_rel = page.evaluate("typeof AppReleaseUI !== 'undefined' && !!document.getElementById('cfg-force-update')")
    if has_rel:
        page.evaluate("()=>{AppReleaseUI._cfg={min_version:'1.0.0',min_build:1,force_update:false};document.getElementById('cfg-force-update').checked=true;"
                      "var a=document.getElementById('cfg-min-version');if(a)a.value='1.0.0';var b=document.getElementById('cfg-min-build');if(b)b.value='1';"
                      "AppReleaseUI.saveConfig(null);return 1}")
        d = dialog(page)
        txt = d.inner_text()
        check('Change which app versions' in txt, 'super: switching Force update ON opens HIGH dialog')
        page.locator('.dcx-btn-go').first.click()
        check('BLOCKED' in dialog(page).inner_text(), 'super: force-update dialog explains old apps are BLOCKED')
        shot(page, 'super_force_update')
        cancel(page)
    else:
        check(True, 'super: app release section not on this page (skipped)')
    check(not native(errs), 'super: no native confirm/prompt popups ' + str(native(errs)))
    page.close()

    # ───────── HR ─────────
    mid, mname = sql("SELECT id, student_name FROM members WHERE status='active' ORDER BY id DESC LIMIT 1").split('\t')
    page, errs = login(ctx, 'audit_hr', '/admin/dashboards/hr-dept.php')
    check(page.evaluate('!!(window.DangerConfirm && window.dcAsk)'), 'hr: component + dcAsk loaded')
    page.evaluate(f"()=>{{archiveMember({mid}, {mname!r});return 1}}")
    page.wait_for_timeout(300)
    page.evaluate("()=>{var s=document.getElementById('archiveReason');if(s&&s.options.length>1){s.selectedIndex=1;}return 1}")
    page.evaluate("()=>{window.__p=confirmArchive();return 1}")
    d = dialog(page)
    check('Archive' in d.inner_text() and 'I want to archive this member' in d.inner_text(), 'hr: archive opens MEDIUM dialog')
    page.click('text=I want to archive this member')
    check('All history' in dialog(page).inner_text(), 'hr: archive step 2 lists what is kept')
    page.click('text=I have read and understand these effects')
    page.fill('.dcx-in', 'ARCHIVE MEMBER')
    shot(page, 'hr_archive_step3')
    with page.expect_navigation(timeout=8000):
        page.click('[data-dcx-act=final]')
    page.wait_for_load_state('networkidle')
    check(sql(f"SELECT status FROM members WHERE id={mid}") == 'archived', 'hr: member archived after the 3 steps')
    page.evaluate(f"()=>{{window.__p=restoreMember({mid}, {mname!r});return 1}}")
    d = dialog(page)
    check(page.locator('.dcx-in').count() == 0, 'hr: restore is a simple LOW dialog')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(2500)
    page.wait_for_load_state('networkidle')
    check(sql(f"SELECT status FROM members WHERE id={mid}") != 'archived', 'hr: member restored')

    # Link all — every batch carries the phrase
    posted = []
    page.on('request', lambda r: posted.append(r.post_data or '') if 'api_guardians.php' in r.url and r.method == 'POST' else None)
    if page.evaluate("!!(window.FamilyLink && FamilyLink.linkAll)"):
        page.evaluate("()=>{window.__p=FamilyLink.linkAll();return 1}")   # same call as the button onclick
        d = dialog(page)
        check(d.is_visible(), 'hr: Link all opens HIGH dialog')
        shot(page, 'hr_link_all')
        walk(page, 'I want to link all members', 'LINK ALL')
        page.wait_for_timeout(2500)
        batches = [x for x in posted if 'attach_batch' in x]
        check(batches and all(('confirm_phrase=LINK+ALL' in x) or ('"confirm_phrase":"LINK ALL"' in x) or ('name="confirm_phrase"\r\n\r\nLINK ALL' in x) for x in batches),
              f'hr: every attach_batch request carries the phrase ({len(batches)} batch(es))')
    else:
        check(False, 'hr: linkAll() present')

    # Import: dry run first, real counts in the dialog
    import openpyxl
    wb = openpyxl.Workbook(); ws = wb.active; ws.append(['N_o', 'Full Name']); ws.append([1, 'DC3 Browser'])
    path = '/tmp/dc3_import.xlsx'; wb.save(path)
    if page.locator('#import_file_perm').count():
        page.set_input_files('#import_file_perm', path)
        page.evaluate("()=>{document.getElementById('formSyncPermanent').requestSubmit();return 1}")
        d = dialog(page)
        page.wait_for_timeout(300)
        page.locator('.dcx-btn-go').first.click()   # step 1 -> consequences (loaded from the dry run)
        page.wait_for_function("/REFUSED|new|updated/i.test((document.querySelector('[role=alertdialog]')||{}).innerText||'')", timeout=8000)
        txt = dialog(page).inner_text()
        check('IMPORT MEMBERS' in txt or 'REFUSED' in txt or 'new' in txt, 'hr: import dialog shows the dry-run result before importing')
        shot(page, 'hr_import_impact')
        cancel(page)
    else:
        check(False, 'hr: import form present')
    check(not native(errs), 'hr: no native confirm/prompt popups ' + str(native(errs)))
    page.close()

    # ───────── Information ─────────
    page, errs = login(ctx, 'audit_info', '/admin/dashboards/info-dept.php')
    check(page.evaluate('!!(window.DangerConfirm && window.dcAsk)'), 'info: component + dcAsk loaded')
    page.evaluate(f"()=>{{window.__p=quickStatusChange({mid}, {mname!r}, 'active');return 1}}")
    d = dialog(page)
    check('Deactivate' in d.inner_text() and 'I want to deactivate this member' in d.inner_text(), 'info: deactivate opens MEDIUM dialog')
    page.click('text=I want to deactivate this member')
    page.click('text=I have read and understand these effects')
    page.fill('.dcx-in', 'DEACTIVATE MEMBER')
    check(page.locator('[data-dcx-act=final]').is_disabled(), 'info: final disabled while the required reason is empty')
    page.fill('.dcx-ta', 'DC3 browser test')
    shot(page, 'info_deactivate_step3')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1500)
    check(sql(f"SELECT status FROM members WHERE id={mid}") == 'inactive', 'info: member deactivated with reason')
    page.evaluate(f"()=>{{window.__p=quickStatusChange({mid}, {mname!r}, 'inactive');return 1}}")
    d = dialog(page)
    check(page.locator('.dcx-in').count() == 0, 'info: make active is a simple LOW dialog')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1500)
    check(sql(f"SELECT status FROM members WHERE id={mid}") == 'active', 'info: member active again')
    check(not native(errs), 'info: no native confirm/prompt popups ' + str(native(errs)))
    page.close()
    br.close()

print(f'\nDC-3 browser: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
