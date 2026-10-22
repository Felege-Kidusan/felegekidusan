#!/usr/bin/env python3
"""Readiness audit 2026-10-22 — names inside inline onclick="…" handlers.

A member or class name containing ' or " used to end the JS string inside
an inline onclick handler: the button broke, and a crafted name could run
script in a staff session (pre-existing in a64bc93). This test plants
hostile names and checks, as the real roles:

  * HR          — Family Link drawer → Unlink child button
  * Information — Status search → quick status button
  * Education   — Classes → Edit class button

For each: no injected script runs, no JS error, and the button still works
with the exact (unaltered) name.

Usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/xss_attr_names_browser.py [base]
Local disposable test DB only. Restores the names it changed at the end.
"""
import os, subprocess, sys
from playwright.sync_api import sync_playwright

if os.environ.get('SSMS_AUDIT_TESTING') != '1':
    sys.exit('Refusing: set SSMS_AUDIT_TESTING=1 (local disposable test DB only).')

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080'
PW = 'AuditTest#2026'
# Breaks out of both '…' and "…" attribute/string contexts.
EVIL = "Ab'e\" onmouseover=\"window.__xss=1\" x=\"');window.__xss=1;//"
EVIL_CLASS = "Cl'ass\" onmouseover=\"window.__xss=1\" x=\"');window.__xss=1;//"
PASS = FAIL = 0
HOVER = "b=>b.dispatchEvent(new MouseEvent('mouseover',{bubbles:true}))"


def check(cond, label):
    global PASS, FAIL
    if cond:
        PASS += 1; print('PASS', label)
    else:
        FAIL += 1; print('FAIL', label)


def sql(q):
    out = subprocess.run(['mysql', '--default-character-set=utf8mb4', '-ussms', '-pssms', '-h127.0.0.1', '-N', '-B', 'ssms_test', '-e', q],
                         capture_output=True, text=True)
    if out.returncode:
        raise RuntimeError(out.stderr)
    return [l.split('\t') for l in out.stdout.splitlines() if l.strip()]


def esc_sql(s):
    return s.replace('\\', '\\\\').replace("'", "\\'")


# ── seed ──
mid, old_name, old_father = sql("SELECT id, student_name, father_name FROM members ORDER BY id LIMIT 1")[0]
cid, old_class = sql("SELECT id, class_name FROM classes ORDER BY id LIMIT 1")[0]
sql(f"UPDATE members SET student_name='{esc_sql(EVIL)}' WHERE id={mid}")
sql(f"UPDATE classes SET class_name='{esc_sql(EVIL_CLASS)}' WHERE id={cid}")
sql("DELETE FROM member_guardians WHERE guardian_id IN (SELECT id FROM guardians WHERE display_name='XSS Test Guardian')")
sql("DELETE FROM guardians WHERE display_name='XSS Test Guardian'")
sql("INSERT INTO guardians (display_name) VALUES ('XSS Test Guardian')")
gid = sql("SELECT id FROM guardians WHERE display_name='XSS Test Guardian'")[0][0]
sql(f"INSERT INTO member_guardians (member_id, guardian_id, is_primary) VALUES ({mid}, {gid}, 1)")


def session(br, user, url):
    page = br.new_context(viewport={'width': 1366, 'height': 900}).new_page()
    errs = []
    page.on('pageerror', lambda e: errs.append(str(e)))
    page.on('dialog', lambda d: (errs.append('NATIVE DIALOG ' + d.message), d.dismiss()))
    page.goto(BASE + '/admin/index.php')
    page.fill('#username', user)
    page.fill('#password', PW)
    page.click('button[type=submit]')
    page.wait_for_load_state('networkidle')
    page.goto(BASE + url)
    page.wait_for_load_state('networkidle')
    return page, errs


def dialog_title(page):
    d = page.locator('[role=alertdialog]')
    try:
        d.wait_for(state='visible', timeout=6000)
    except Exception:
        return '(dialog did not open)'
    t = page.locator('.dcx-tt').first.inner_text()
    page.click('[data-dcx-act=cancel]')
    return t


try:
    with sync_playwright() as p:
        br = p.chromium.launch()

        # ── HR: Family Link drawer → Unlink ──
        page, errs = session(br, 'audit_hr', '/admin/dashboard.php?section=family')
        page.wait_for_function('()=>!!window.FamilyLink', timeout=15000)
        page.evaluate(f'()=>{{FamilyLink.open({gid});return 1}}')
        btn = page.locator('#famDrawerBody table tbody tr button.fam-btn-danger').first
        btn.wait_for(state='visible', timeout=10000)
        btn.evaluate(HOVER)
        check(page.evaluate('()=>window.__xss') is None, 'HR family drawer: injected script did not run on render/hover')
        btn.click()
        t = dialog_title(page)
        check(EVIL in t, 'HR unlink: dialog shows the exact name (%r)' % t)
        check(page.evaluate('()=>window.__xss') is None, 'HR unlink: injected script did not run on click')
        check(not errs, 'HR: no JS errors / native popups %s' % errs)
        page.context.close()

        # ── Information: status search → quick status ──
        page, errs = session(br, 'audit_info', '/admin/dashboards/info-dept.php')
        page.wait_for_function("()=>typeof searchForStatus==='function'", timeout=15000)
        page.evaluate("()=>{document.getElementById('attStatusSearch').value='Ab';searchForStatus();return 1}")
        qbtn = page.locator('#attStatusResults button[onclick^=quickStatusChange]').first
        qbtn.wait_for(state='attached', timeout=10000)
        qbtn.evaluate(HOVER)
        check(page.evaluate('()=>window.__xss') is None, 'Info status search: injected script did not run on render/hover')
        qbtn.evaluate('b=>b.click()')
        t = dialog_title(page)
        check(EVIL in t, 'Info quick status: dialog shows the exact name (%r)' % t)
        check(page.evaluate('()=>window.__xss') is None, 'Info quick status: injected script did not run on click')
        check(not errs, 'Info: no JS errors / native popups %s' % errs)
        page.context.close()

        # ── Education: Classes → Edit class ──
        page, errs = session(br, 'audit_edu', '/admin/dashboards/edu_dept.php')
        page.wait_for_function("()=>typeof loadClasses==='function'", timeout=15000)
        page.evaluate('()=>{loadClasses();return 1}')
        ebtn = page.locator("button[onclick^='editClass(']").first
        ebtn.wait_for(state='attached', timeout=10000)
        ebtn.evaluate(HOVER)
        check(page.evaluate('()=>window.__xss') is None, 'Edu classes: injected script did not run on render/hover')
        ebtn.evaluate('b=>b.click()')
        page.wait_for_timeout(300)
        val = page.evaluate("()=>document.getElementById('className').value")
        check(val == EVIL_CLASS, 'Edu edit class: form gets the exact class name (%r)' % val)
        check(page.evaluate('()=>window.__xss') is None, 'Edu edit class: injected script did not run on click')
        check(not errs, 'Edu: no JS errors / native popups %s' % errs)
        page.context.close()
        br.close()
finally:
    sql(f"UPDATE members SET student_name='{esc_sql(old_name)}' WHERE id={mid}")
    sql(f"UPDATE classes SET class_name='{esc_sql(old_class)}' WHERE id={cid}")
    sql(f"DELETE FROM member_guardians WHERE guardian_id={gid}")
    sql(f"DELETE FROM guardians WHERE id={gid}")

print(f'\nXSS attribute names: {PASS} passed, {FAIL} failed')
sys.exit(1 if FAIL else 0)
