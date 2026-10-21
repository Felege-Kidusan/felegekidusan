"""Browser test for admin/js/danger_confirm.js (no server/DB needed).

Run:  python3 tests/e2e/danger_confirm_component_e2e.py [--shots DIR]
Requires: playwright + chromium.
"""
import os
import sys
import pathlib

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parents[2]
JS = (ROOT / 'admin/js/danger_confirm.js').read_text(encoding='utf-8')
SHOTS = None
if '--shots' in sys.argv:
    SHOTS = pathlib.Path(sys.argv[sys.argv.index('--shots') + 1])
    SHOTS.mkdir(parents=True, exist_ok=True)

HTML = """<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>dc</title></head><body style="font-family:sans-serif;padding:24px;background:#f1f5f9">
<button id="trigger">Remove day</button>
<script>%s</script>
<script>window.__res = 'pending';
function run(opts){ window.__res='pending'; DangerConfirm.ask(opts).then(function(r){ window.__res = r; }); }
</script></body></html>""" % JS

FAILS = []


def check(cond, msg):
    print(('PASS ' if cond else 'FAIL ') + msg)
    if not cond:
        FAILS.append(msg)


def shot(page, name):
    if SHOTS:
        page.screenshot(path=str(SHOTS / (name + '.png')))


HIGH = """{tier:'high', title:'Remove Monday from 3ኛ ክፍል?', object:'3ኛ ክፍል',
 summary:'3ኛ ክፍል will no longer meet on Monday.', intent:'I want to remove this day',
 consequences:['12 scheduled lessons on Monday will be deleted.','Teachers can no longer take attendance for this class on Monday (attendance is only allowed on working days).'],
 keeps:['Attendance already saved on past Monday stays in history.'], irreversible:true,
 phrase:'REMOVE DAY', confirmLabel:'Remove Monday'}"""


def main():
    with sync_playwright() as p:
        b = p.chromium.launch()
        for vp_name, vp in (('desktop', {'width': 1280, 'height': 860}), ('phone', {'width': 390, 'height': 800})):
            page = b.new_page(viewport=vp)
            page.set_content(HTML)
            page.focus('#trigger')

            # ── HIGH: 3 steps + typing ──────────────────────────────────
            page.evaluate('run(%s)' % HIGH)
            dlg = page.locator('[role=alertdialog]')
            check(dlg.count() == 1, f'[{vp_name}] dialog opens with role=alertdialog')
            check(dlg.get_attribute('aria-modal') == 'true', f'[{vp_name}] aria-modal=true')
            check('STEP 1 OF 3' in dlg.inner_text(), f'[{vp_name}] step 1 of 3 shown')
            page.wait_for_timeout(50)
            check(page.evaluate("document.activeElement.textContent") == 'Cancel', f'[{vp_name}] initial focus on Cancel')
            shot(page, f'{vp_name}_high_step1')
            page.click('text=I want to remove this day')
            check('STEP 2 OF 3' in dlg.inner_text(), f'[{vp_name}] step 2 shown')
            check('12 scheduled lessons on Monday will be deleted.' in dlg.inner_text(), f'[{vp_name}] consequences listed')
            check('This cannot be undone.' in dlg.inner_text(), f'[{vp_name}] irreversible sentence')
            shot(page, f'{vp_name}_high_step2')
            page.click('text=I have read and understand these effects')
            check('STEP 3 OF 3' in dlg.inner_text(), f'[{vp_name}] step 3 shown')
            final = page.locator('[data-dcx-act=final]')
            check(final.is_disabled(), f'[{vp_name}] final button disabled before typing')
            inp = page.locator('.dcx-in')
            check(inp.get_attribute('autocomplete') == 'off', f'[{vp_name}] autocomplete=off on phrase input')
            inp.fill('REMOVE DA')
            check(final.is_disabled(), f'[{vp_name}] partial phrase keeps button disabled')
            inp.fill('  remove   day ')
            check(not final.is_disabled(), f'[{vp_name}] phrase match (trim/case/space-insensitive) enables button')
            shot(page, f'{vp_name}_high_step3')
            inp.press('Enter')
            page.wait_for_timeout(50)
            check(page.evaluate('window.__res') == 'pending' and dlg.count() == 1, f'[{vp_name}] Enter in phrase box does NOT confirm')
            final.click()
            page.wait_for_timeout(50)
            res = page.evaluate('window.__res')
            check(isinstance(res, dict) and res.get('phrase') == 'REMOVE DAY', f'[{vp_name}] resolves with canonical phrase')
            check(dlg.count() == 0, f'[{vp_name}] dialog closed after confirm')
            check(page.evaluate("document.activeElement.id") == 'trigger', f'[{vp_name}] focus returned to trigger')

            # ── Esc cancels, backdrop cancels ────────────────────────────
            page.evaluate('run(%s)' % HIGH)
            page.keyboard.press('Escape')
            page.wait_for_timeout(50)
            check(page.evaluate('window.__res') is False and dlg.count() == 0, f'[{vp_name}] Esc cancels (resolves false)')
            page.evaluate('run(%s)' % HIGH)
            page.mouse.click(5, 5)
            page.wait_for_timeout(50)
            check(page.evaluate('window.__res') is False, f'[{vp_name}] backdrop click cancels')

            # ── never stacks ─────────────────────────────────────────────
            page.evaluate('run(%s)' % HIGH)
            second = page.evaluate("DangerConfirm.ask({tier:'low',title:'x'}).then(function(r){return r;})")
            check(second is False and dlg.count() == 1, f'[{vp_name}] second dialog refused while one is open')
            page.keyboard.press('Escape')

            # ── onConfirm error keeps dialog open ────────────────────────
            page.evaluate("""run({tier:'medium', title:'Delete holiday?', intent:'I want to delete this holiday',
                consequences:['x'], phrase:'DELETE HOLIDAY', confirmLabel:'Delete holiday',
                onConfirm:function(){ return new Promise(function(_,rej){ setTimeout(function(){ rej(new Error('Server said no')); }, 150); }); }})""")
            check('dcx-medium' in (page.locator('.dcx').get_attribute('class') or ''), f'[{vp_name}] medium tier styling class')
            page.click('text=I want to delete this holiday')
            page.click('text=I have read and understand these effects')
            page.fill('.dcx-in', 'DELETE HOLIDAY')
            page.click('[data-dcx-act=final]')
            check(page.locator('[data-dcx-act=final]').inner_text() == 'Working…', f'[{vp_name}] busy label while working (double-submit guard)')
            check(page.locator('[data-dcx-act=cancel]').is_disabled(), f'[{vp_name}] cancel disabled while working')
            page.keyboard.press('Escape')
            check(dlg.count() == 1, f'[{vp_name}] Esc ignored while working')
            page.wait_for_timeout(300)
            check('Server said no' in dlg.inner_text(), f'[{vp_name}] server error shown in dialog')
            check(dlg.count() == 1 and page.evaluate('window.__res') == 'pending', f'[{vp_name}] dialog stays open after error')
            check(not page.locator('[data-dcx-act=final]').is_disabled(), f'[{vp_name}] retry possible after error')
            shot(page, f'{vp_name}_medium_error')
            page.click('[data-dcx-act=cancel]')
            check(page.evaluate('window.__res') is False, f'[{vp_name}] cancel after error resolves false')

            # ── async consequences ───────────────────────────────────────
            page.evaluate("""run({tier:'high', title:'Delete class?', intent:'Go', phrase:'DELETE CLASS',
                consequences:function(){ return new Promise(function(r){ setTimeout(function(){ r(['3 subjects removed']); }, 250); }); }})""")
            page.click('text=Go')
            nxt = page.locator('text=I have read and understand these effects')
            check(nxt.is_disabled() and 'Checking what will be affected' in dlg.inner_text(), f'[{vp_name}] step-2 button disabled while counts load')
            page.wait_for_timeout(400)
            check(not nxt.is_disabled() and '3 subjects removed' in dlg.inner_text(), f'[{vp_name}] live counts appear')
            page.keyboard.press('Escape')

            # ── LOW: one step ────────────────────────────────────────────
            page.evaluate("run({tier:'low', title:'Reopen Tikimt 12?', summary:'Classes can hold again.', confirmLabel:'Reopen this day'})")
            check('STEP' not in dlg.inner_text() and page.locator('.dcx-in').count() == 0, f'[{vp_name}] low tier: single step, no typing')
            shot(page, f'{vp_name}_low')
            page.click('text=Reopen this day')
            page.wait_for_timeout(50)
            check(isinstance(page.evaluate('window.__res'), dict), f'[{vp_name}] low tier confirms')

            # ── required reason + choice ─────────────────────────────────
            page.evaluate("""run({tier:'medium', title:'Change status?', intent:'Go', phrase:'CHANGE STATUS', consequences:['x'],
                choice:{label:'New status', options:[{value:'active',label:'Active'},{value:'inactive',label:'Inactive'}], value:'active'},
                reason:{label:'Reason', required:true}})""")
            page.click('text=Go')
            page.click('text=I have read and understand these effects')
            page.select_option('.dcx-sel', 'inactive')
            page.fill('.dcx-in', 'CHANGE STATUS')
            check(page.locator('[data-dcx-act=final]').is_disabled(), f'[{vp_name}] required reason keeps button disabled')
            page.fill('.dcx-ta', 'Moved to another parish')
            check(not page.locator('[data-dcx-act=final]').is_disabled(), f'[{vp_name}] reason filled enables button')
            page.click('[data-dcx-act=final]')
            page.wait_for_timeout(50)
            r = page.evaluate('window.__res')
            check(isinstance(r, dict) and r.get('choice') == 'inactive' and r.get('reason') == 'Moved to another parish', f'[{vp_name}] choice + reason returned')

            # ── XSS: text is escaped ─────────────────────────────────────
            page.evaluate("""run({tier:'low', title:'<img src=x onerror="window.__xss=1">', summary:'<b>bold</b>', confirmLabel:'OK'})""")
            page.wait_for_timeout(50)
            check(page.evaluate('window.__xss === undefined') and page.locator('.dcx img').count() == 0, f'[{vp_name}] titles/summary are HTML-escaped')
            page.keyboard.press('Escape')
            page.close()
        b.close()
    print('\n%d failure(s)' % len(FAILS))
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
