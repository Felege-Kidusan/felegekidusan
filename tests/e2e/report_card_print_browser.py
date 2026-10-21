"""Browser test: Report Cards "Print cards" must not leave the printed sheets
on screen, and the paper output must contain ONLY the cards.
LOCAL test server only.

usage: SSMS_AUDIT_TESTING=1 python3 tests/e2e/report_card_print_browser.py [base] [--shots DIR]
"""
import os, sys
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
PASS = FAIL = 0


def check(c, m):
    global PASS, FAIL
    if c:
        PASS += 1; print('PASS', m)
    else:
        FAIL += 1; print('FAIL', m)


VISIBLE = "(sel)=>{const e=document.querySelector(sel); if(!e) return false; const r=e.getBoundingClientRect(); const s=getComputedStyle(e);" \
          " return s.display!=='none' && s.visibility!=='hidden' && r.width>0 && r.height>0 && r.right>0 && r.bottom>0;}"

with sync_playwright() as p:
    br = p.chromium.launch()
    page = br.new_context(viewport={'width': 1366, 'height': 900}).new_page()
    errs = []
    page.on('pageerror', lambda e: errs.append(str(e)))
    page.goto(BASE + '/admin/index.php')
    page.fill('#username', 'audit_edu'); page.fill('#password', 'AuditTest#2026')
    page.click('button[type=submit]'); page.wait_for_load_state('networkidle')
    page.goto(BASE + '/admin/dashboards/edu_dept.php'); page.wait_for_load_state('networkidle')
    # window.print() would block a headless run; record calls and what the
    # paper would show at that moment (print media emulated by the test).
    page.evaluate("()=>{window.__prints=0; window.print=function(){window.__prints++;}; return 1}")
    page.evaluate("()=>{nav('reportcards');return 1}")
    page.wait_for_timeout(600)
    cid = page.evaluate("()=>{const s=document.getElementById('rcClass'); const o=[...s.options].find(x=>x.value); s.value=o?o.value:''; s.dispatchEvent(new Event('change')); return s.value}")
    page.wait_for_timeout(1500)
    check(bool(cid), 'a class is selectable (%s)' % cid)

    # Fetch the class cards exactly as the button does, then render them with
    # the print mode held ON so the paper layout can be inspected.
    page.evaluate("""()=>{window.__realDone=null; const add=window.addEventListener.bind(window);
        window.addEventListener=function(t,f,o){ if(t==='afterprint') window.__afterprint=f; return add(t,f,o); }; return 1}""")
    page.evaluate("()=>{window.__p=generateBulkReports(); return 1}")
    page.wait_for_function("window.__prints>0 || document.querySelector('.toast, #toast, .tst')", timeout=8000)
    page.wait_for_timeout(300)
    n_cards = page.evaluate("()=>document.querySelectorAll('#rcPrintRoot .rc-book').length")
    check(page.evaluate('window.__prints') == 1 and n_cards > 0, 'Print cards called print once with %d card(s)' % n_cards)

    # ── paper view, while print mode is on ──
    page.evaluate("()=>{document.body.classList.add('rc-print-mode'); return 1}")
    page.emulate_media(media='print')
    check(page.evaluate(VISIBLE, '#rcPrintRoot .rc-book-page'), 'paper: the card pages are printed')
    check(not page.evaluate(VISIBLE, '#sec-reportcards'), 'paper: the Report Cards dashboard is NOT printed')
    check(not page.evaluate(VISIBLE, '.sb') and not page.evaluate(VISIBLE, 'aside'), 'paper: sidebar is NOT printed')
    check(not page.evaluate(VISIBLE, '#rcPrintRoot .rc-sheet-label'), 'paper: on-screen sheet labels are not printed')
    if SHOTS:
        page.pdf(path=os.path.join(SHOTS, 'cards_print.pdf'), prefer_css_page_size=True, print_background=True)

    # ── after printing: back to screen ──
    page.emulate_media(media='screen')
    page.evaluate("()=>{ if(window.__afterprint) window.__afterprint(); document.body.classList.remove('rc-print-mode'); return 1}")
    page.wait_for_timeout(1700)  # past the 1.5 s fallback timer too
    check(not page.evaluate("()=>document.body.classList.contains('rc-print-mode')"), 'screen: print mode removed after printing')
    check(not page.evaluate(VISIBLE, '#rcPrintRoot'), 'screen: printed sheets are NOT left on the page')
    check(page.evaluate(VISIBLE, '#sec-reportcards'), 'screen: Report Cards dashboard is shown again')
    scroll_h = page.evaluate("()=>document.documentElement.scrollHeight")
    if SHOTS:
        page.screenshot(path=os.path.join(SHOTS, 'after_print_screen.png'), full_page=True)
    check(scroll_h < 4000, 'screen: page is not stretched by hidden sheets (scrollHeight %d)' % scroll_h)

    # ── the class-list print must not include old card sheets ──
    page.evaluate("()=>{printClassList(); return 1}")
    page.emulate_media(media='print')
    page.evaluate("()=>{document.body.classList.add('rc-print-list'); return 1}")
    check(not page.evaluate(VISIBLE, '#rcPrintRoot .rc-book-page'), 'class list print: old card sheets are NOT included')
    check(page.evaluate(VISIBLE, '#sec-reportcards'), 'class list print: the class list itself IS printed')
    page.emulate_media(media='screen')

    # ── single card from the view modal still prints ──
    page.evaluate("()=>{document.body.classList.remove('rc-print-list'); return 1}")
    one = page.evaluate("async ()=>{const r=await fetch('/admin/api_communication.php?action=get_class_cards&class_id=%s'+rcQs(),{credentials:'same-origin'}); "
                        "const d=await r.json(); window.__one=(d.cards||[])[0]; return !!window.__one}" % cid)
    check(one, 'single card: a real card was fetched')
    page.evaluate("()=>{const m=document.getElementById('rcModal'); if(m){m.classList.add('show'); m.style.display='flex';} "
                  "FKSSReportCard.fillModal(document.getElementById('rcModalBody'), window.__one); "
                  "document.querySelector('#rcModalBody [data-rc-print]').click(); return 1}")
    check(page.evaluate("()=>document.querySelectorAll('#rcPrintRoot .rc-book').length") == 1, 'single card: the view window Print button prints exactly 1 card')
    page.emulate_media(media='print')
    check(page.evaluate(VISIBLE, '#rcPrintRoot .rc-book-page'), 'single card: the card is printed')
    check(not page.evaluate(VISIBLE, '#rcModal'), 'single card: the view window itself is NOT printed')
    page.emulate_media(media='screen')

    # ── browser that never fires afterprint: mode must NOT drop on a timer ──
    page.wait_for_timeout(2200)
    check(page.evaluate("()=>document.body.classList.contains('rc-print-mode')"),
          'no afterprint: print mode is kept while the print dialog may still be open')
    page.mouse.click(5, 5)
    page.wait_for_timeout(200)
    check(not page.evaluate("()=>document.body.classList.contains('rc-print-mode')") and not page.evaluate(VISIBLE, '#rcPrintRoot'),
          'no afterprint: first click back on the page ends print mode and hides the sheets')
    check(not errs, 'no page errors ' + str(errs[:3]))
    br.close()

print('\nReport card print: %d passed, %d failed' % (PASS, FAIL))
sys.exit(1 if FAIL else 0)
