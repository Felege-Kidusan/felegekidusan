"""Browser test for danger-confirmation DC-2 on the real Education and School
Admin dashboards (LOCAL test server only).

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/danger_confirm_dc2_browser.py [base] [--shots DIR]
"""
import os, sys, time
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


def shot(page, name):
    if SHOTS:
        page.screenshot(path=os.path.join(SHOTS, name + '.png'))


def login(ctx, user, page_url):
    page = ctx.new_page()
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


def walk(page, intent, phrase, final_click=True):
    dlg = page.locator('[role=alertdialog]')
    dlg.wait_for(state='visible', timeout=5000)
    page.click('text=' + intent)
    page.click('text=I have read and understand these effects')
    final = page.locator('[data-dcx-act=final]')
    ok_disabled = final.is_disabled()
    page.fill('.dcx-in', phrase)
    if final_click:
        final.click()
    return ok_disabled


with sync_playwright() as p:
    br = p.chromium.launch()
    ctx = br.new_context(viewport={'width': 1366, 'height': 900})

    # ───────── Education dashboard ─────────
    page, errs = login(ctx, 'audit_edu', '/admin/dashboards/edu_dept.php')
    check(page.evaluate('!!(window.DangerConfirm && window.dcAsk)'), 'edu: component + dcAsk loaded')
    tag = str(int(time.time()))[-6:]
    cid = page.evaluate("""async (tag)=>{const fd=new FormData();fd.append('action','save_class');fd.append('class_name','DC2 UI '+tag);
        fd.append('class_name_en','DC2 UI '+tag);fd.append('class_code','UI'+tag);fd.append('level_order','98');fd.append('is_active','1');fd.append('csrf_token',CSRF_TOKEN);
        await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});
        const d=await (await fetch('/admin/api_education.php?action=get_classes',{credentials:'same-origin'})).json();
        const c=(d.classes||d.data||[]).find(x=>x.class_name==='DC2 UI '+tag);return c?c.id:null;}""", tag)
    check(bool(cid), 'edu: throwaway class created')

    # delete class: impact shown, button disabled until phrase, server succeeds
    page.evaluate(f"()=>{{window.__p=deleteClass({cid}, {{dataset:{{name:'DC2 UI {tag}'}}}});return 1}}")
    dlg = page.locator('[role=alertdialog]')
    dlg.wait_for(state='visible', timeout=5000)
    check('Delete class DC2 UI' in dlg.inner_text(), 'edu: delete-class title names the class')
    page.click('text=I want to delete this class')
    page.wait_for_function("document.querySelector('[role=alertdialog]') && /deleted permanently/.test(document.querySelector('[role=alertdialog]').innerText)", timeout=5000)
    check(True, 'edu: live impact preview shows "deleted permanently" for an empty class')
    shot(page, 'edu_delete_class_step2')
    page.click('text=I have read and understand these effects')
    check(page.locator('[data-dcx-act=final]').is_disabled(), 'edu: final button disabled before typing')
    page.fill('.dcx-in', 'DELETE CLASS')
    shot(page, 'edu_delete_class_step3')
    page.click('[data-dcx-act=final]')
    page.wait_for_timeout(1200)
    gone = page.evaluate(f"""async ()=>{{const d=await (await fetch('/admin/api_education.php?action=get_classes',{{credentials:'same-origin'}})).json();
        return !(d.classes||d.data||[]).some(x=>x.id=={cid});}}""")
    check(gone, 'edu: class actually deleted after the 3 steps')

    # delete semester with history: preview says REFUSE, server refuses with term_in_use
    page.evaluate("()=>{window.__p=doDeleteTerm(1);return 1}")
    page.locator('[role=alertdialog]').wait_for(state='visible', timeout=5000)
    page.click('text=I want to delete this semester')
    page.wait_for_function("/REFUSE/.test(document.querySelector('[role=alertdialog]').innerText)", timeout=5000)
    check(True, 'edu: delete-semester preview warns it will be refused (F1)')
    shot(page, 'edu_delete_term_blocked')
    page.click('[data-dcx-act=cancel]')
    page.wait_for_timeout(300)
    check(page.locator('[role=alertdialog]').count() == 0 or not page.locator('[role=alertdialog]').is_visible(), 'edu: cancel closes the dialog')

    # medium: deactivate teacher -> cancel leaves nothing changed
    page.evaluate("()=>{window.__p=toggleTeacher(3,1);return 1}")
    page.locator('[role=alertdialog]').wait_for(state='visible', timeout=5000)
    check('dcx-medium' in (page.locator('.dcx').get_attribute('class') or ''), 'edu: deactivate teacher uses MEDIUM tier')
    page.click('[data-dcx-act=cancel]')
    check(page.evaluate('window.__p.then(()=>1)') == 1, 'edu: cancelled medium action resolves without error')
    check(not [e for e in errs if 'NATIVE DIALOG' in e], 'edu: no native confirm()/prompt() appeared')
    check(not [e for e in errs if 'NATIVE' not in e], 'edu: no JS errors on page: ' + '; '.join(errs)[:200])

    # ───────── School Admin dashboard ─────────
    page2, errs2 = login(br.new_context(viewport={'width': 1366, 'height': 900}), 'audit_school', '/admin/dashboards/school_admin.php')
    check(page2.evaluate('!!(window.DangerConfirm && window.dcAsk)'), 'school: component + dcAsk loaded')
    page2.evaluate("()=>{window.__p=deleteTerm(1);return 1}")
    page2.locator('[role=alertdialog]').wait_for(state='visible', timeout=5000)
    page2.click('text=I want to delete this semester')
    page2.wait_for_function("/REFUSE/.test(document.querySelector('[role=alertdialog]').innerText)", timeout=5000)
    check(True, 'school: delete-semester preview warns it will be refused')
    page2.click('text=I have read and understand these effects')
    page2.fill('.dcx-in', 'DELETE SEMESTER')
    page2.click('[data-dcx-act=final]')
    page2.wait_for_timeout(1200)
    still = page2.evaluate("""async ()=>{const d=await (await fetch('/admin/api_education.php?action=impact_delete_term&term_id=1',{credentials:'same-origin'})).json();return d.status==='success';}""")
    check(still, 'school: used semester still exists after confirmed delete (server refused)')

    page2.evaluate("()=>{window.__p=switchRole('edu_dept');return 1}")
    page2.locator('[role=alertdialog]').wait_for(state='visible', timeout=5000)
    check(page2.locator('.dcx-in').count() == 0, 'school: switch dashboard is LOW (no typing)')
    page2.click('[data-dcx-act=cancel]')

    yname = 'DC2UI-' + tag
    page2.evaluate("""async (yn)=>{const fd=new FormData();for(const [k,v] of Object.entries({action:'save_academic_year',year_name:yn,ec_year:'2091',year_gc:'2098/99',start_date:'2098-09-11',end_date:'2099-07-30',is_current:'0',csrf_token:CSRF}))fd.append(k,v);
        await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});return 1;}""", yname)
    page2.evaluate("openRolloverModal()")
    page2.wait_for_timeout(800)
    check(page2.locator('#roConfirm').count() == 0, 'school: old inline ROLLOVER field removed from modal')
    sel_ok = page2.evaluate("""()=>{const o=document.getElementById('roOld'),n=document.getElementById('roNew');
        if(!o||!n||o.options.length<1||n.options.length<1)return false;return true;}""")
    if sel_ok:
        page2.evaluate("""()=>{const o=document.getElementById('roOld'),n=document.getElementById('roNew');
            for(const op of n.options){if(op.value&&op.value!==o.value){n.value=op.value;break;}}}""")
        page2.evaluate("()=>{window.__p=runRollover();return 1}")
        try:
            page2.locator('[role=alertdialog]').wait_for(state='visible', timeout=3000)
            page2.wait_for_timeout(500)
            top = page2.evaluate("()=>{const e=document.elementFromPoint(683,450);return !!(e&&e.closest('.dcx'));}")
            check('ROLLOVER' in page2.locator('.dcx').inner_text().upper() and top, 'school: rollover 3-step dialog is visible ON TOP of the rollover modal')
            page2.click('text=I want to run the year rollover')
            page2.wait_for_timeout(400)
            shot(page2, 'school_rollover_dialog')
            page2.click('[data-dcx-act=cancel]')
        except Exception:
            check(False, 'school: rollover did not open the dialog')
    page2.evaluate("""async (yn)=>{const d=await (await fetch('/admin/api_education.php?action=get_academic_years',{credentials:'same-origin'})).json();
        const y=(d.years||d.data||[]).find(x=>x.year_name===yn); if(!y) return 0; const fd=new FormData();fd.append('action','delete_year');fd.append('year_id',y.id);fd.append('csrf_token',CSRF);
        await fetch('/admin/api_education.php',{method:'POST',body:fd,credentials:'same-origin'});return 1;}""", yname)
    check(not [e for e in errs2 if 'NATIVE DIALOG' in e], 'school: no native confirm()/prompt() appeared')
    check(not [e for e in errs2 if 'NATIVE' not in e], 'school: no JS errors on page: ' + '; '.join(errs2)[:200])
    br.close()

print(f'\nRESULT: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
