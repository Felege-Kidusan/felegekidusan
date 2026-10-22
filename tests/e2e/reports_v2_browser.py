"""Browser E2E for the Reporting Centre V2 (Playwright, disposable local DB).

  SSMS_AUDIT_TESTING=1 python3 tests/e2e/reports_v2_browser.py [BASE] [role]

Logs in as audit_<role> (password AuditTest#2026), opens reports_v2.php,
exercises starters, chips, quick filters, chart drill, breakdown, list,
column picker, language toggle and export URLs, and fails on any console
error or failed request. Screenshots land in /tmp/rc_*.png.
"""
import json
import os
import re
import sys
import time
import urllib.parse

from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
ROLE = sys.argv[2] if len(sys.argv) > 2 else "hr"
PASSWORD = "AuditTest#2026"

if os.environ.get("SSMS_AUDIT_TESTING") != "1":
    print("Refusing: set SSMS_AUDIT_TESTING=1 (disposable local database only).", file=sys.stderr)
    sys.exit(2)

errors = []
failed_requests = []


def check(cond, msg):
    if not cond:
        errors.append(msg)
        print("  FAIL:", msg)
    else:
        print("  ok:", msg)


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1400, "height": 900})
    page = ctx.new_page()
    armed = {"on": False}
    page.on("console", lambda m: errors.append("console: " + m.text) if (armed["on"] and m.type == "error") else None)
    page.on("pageerror", lambda e: errors.append("pageerror: " + str(e)) if armed["on"] else None)
    page.on("response", lambda r: failed_requests.append(f"{r.status} {r.url}") if r.status >= 400 and "api_reports_v2" in r.url else None)

    # login
    page.goto(BASE + "/admin/index.php")
    page.fill("input[name=username]", "audit_" + ROLE)
    page.fill("input[name=password]", PASSWORD)
    page.click("button[type=submit], input[type=submit]")
    page.wait_for_load_state("networkidle")

    armed["on"] = True
    page.goto(BASE + "/admin/reports_v2.php")
    page.wait_for_selector(".rc-kpi", timeout=15000)
    page.wait_for_timeout(1200)
    page.screenshot(path="/tmp/rc_overview.png", full_page=True)
    kpis = page.locator(".rc-kpi").count()
    check(kpis == 6, f"6 KPI tiles rendered ({kpis})")
    check(page.locator(".rc-starter").count() >= 5, "starter buttons rendered")
    check(page.locator("#rc-charts canvas").count() >= 6, "overview charts drawn (ECharts canvases)")

    # starter → list tab, chips, table rows
    page.click(".rc-starter:has-text('Incomplete records')")
    page.wait_for_timeout(1500)
    check(page.locator(".rc-tabs [data-tab=list].is-on").count() == 1, "starter with columns switches to List")
    check(page.locator(".rc-chip").count() >= 2, "chips reflect starter filters")
    check(page.locator(".tabulator-row").count() > 0, "list rows rendered")
    check("completeness_score" in page.url or "q=" in page.url, "URL carries the report definition")
    page.screenshot(path="/tmp/rc_list.png", full_page=True)

    # column picker: add a PII column, verify header appears
    page.click("#rc-columns-btn")
    page.check("#rc-columns-pop input[value=guardian_phone]")
    page.wait_for_timeout(800)
    page.keyboard.press("Escape")
    page.click("body", position={"x": 5, "y": 5})
    check(page.locator(".tabulator-col[tabulator-field=guardian_phone]").count() == 1, "column picker adds a column")

    # remove a chip → KPIs refresh
    before = page.locator(".rc-chip").count()
    page.locator(".rc-chip button").first.click()
    page.wait_for_timeout(1200)
    check(page.locator(".rc-chip").count() == before - 1, "chip removal works")

    # quick filter checkbox
    page.click("#rc-quick-gender label:has-text('Female')")
    page.wait_for_timeout(1200)
    check(page.locator(".rc-chip:has-text('Gender')").count() == 1, "quick gender filter becomes a chip")

    # breakdown
    page.click(".rc-tabs [data-tab=breakdown]")
    page.wait_for_timeout(1200)
    page.select_option("#rc-groupby", "sub_city")
    page.wait_for_timeout(1200)
    check(page.locator("#rc-breakdown-table .tabulator-row").count() >= 1, "breakdown table rows")
    page.screenshot(path="/tmp/rc_breakdown.png", full_page=True)

    # overview chart drill (click a pie slice on Status)
    page.click(".rc-tabs [data-tab=overview]")
    page.wait_for_timeout(800)
    page.click("#rc-clear")
    page.wait_for_timeout(1200)
    box = page.locator("#ch-status").bounding_box()
    page.mouse.click(box["x"] + box["width"] * 0.5 + 72, box["y"] + box["height"] * 0.45)
    page.wait_for_timeout(1200)
    check(page.locator(".rc-chip:has-text('Status')").count() >= 1, "clicking a chart segment adds a filter")

    # add rule: Has photo = no
    page.click("#rc-add-rule")
    rule = page.locator(".rc-rule").last
    rule.locator("select").first.select_option("has_photo")
    rule.locator("select").nth(1).select_option("is_false")
    page.wait_for_timeout(1200)
    check(page.locator(".rc-chip:has-text('Has photo')").count() == 1, "custom rule adds a chip")

    # language toggle
    page.click(".rc-seg [data-lang=am]")
    page.wait_for_timeout(1200)
    check(re.search(r"[\u1200-\u137F]", page.locator(".rc-starter:not(.is-pinned)").first.inner_text()) is not None, "Amharic labels after toggle")
    page.screenshot(path="/tmp/rc_amharic.png", full_page=True)
    page.click(".rc-seg [data-lang=en]")
    page.wait_for_timeout(600)

    # export links (fetch through the page session, do not navigate)
    page.click("#rc-export-btn")
    page.wait_for_timeout(300)
    xlsx_disabled = page.locator("#rc-export-xlsx").is_disabled()
    page.keyboard.press("Escape")
    q = json.dumps({"filters": [{"field": "status", "op": "eq", "value": "active"}], "columns": ["member_code", "full_name_am", "guardian_phone"]})
    r = page.request.get(BASE + "/admin/api_reports_v2.php?action=export&format=csv&q=" + q)
    check(r.status == 200 and "text/csv" in r.headers.get("content-type", ""), "CSV export over the session")
    if not xlsx_disabled:
        r2 = page.request.get(BASE + "/admin/api_reports_v2.php?action=export&format=xlsx&q=" + q)
        check(r2.status == 200 and "spreadsheetml" in r2.headers.get("content-type", ""), "XLSX export over the session")

    # glossary
    page.click("#rc-glossary-btn")
    page.wait_for_timeout(300)
    check(page.locator("#rc-glossary-body .rc-def").count() >= 10, "glossary lists definitions")
    page.keyboard.press("Escape")

    # ── V2-2: cross-domain ──
    check(page.locator(".rc-starter-group").count() >= 3, "starters are grouped")
    check(page.locator("#rc-quick-edu_attendance_band input").count() >= 4, "attendance band quick filter rendered")
    page.click("button[data-tab=overview]")
    page.wait_for_timeout(1200)
    check(page.locator("#ch-edu_attendance_band canvas").count() == 1, "attendance band chart drawn")
    check(page.locator("#ch-progression canvas").count() == 1, "progression chart drawn")
    # starter: low attendance → list with cross-domain columns
    page.click("button.rc-starter:has-text('under 50%')")
    try:
        page.wait_for_selector("#rc-table .tabulator-row", timeout=10000)
    except Exception:
        pass
    page.wait_for_timeout(400)
    heads = page.locator(".tabulator-col-title").all_inner_texts()
    check(any("attendance" in h.lower() for h in heads), "cross-domain columns in list (%s)" % heads[:6])
    check(page.locator(".tabulator-row").count() >= 1, "low attendance starter returns rows")
    # column picker shows categories
    page.click("#rc-columns-btn")
    page.wait_for_timeout(300)
    check(page.locator("#rc-columns-pop .rc-columns-cat").count() >= 6, "column picker grouped by category")
    page.keyboard.press("Escape")
    page.click("body", position={"x": 5, "y": 5})
    # export log: admins only
    if ROLE in ("school", "super"):
        page.click("#rc-exportlog-btn")
        page.wait_for_timeout(1200)
        check(page.locator("#rc-exportlog-table .tabulator-row").count() >= 1, "export log lists events")
        page.keyboard.press("Escape")
    else:
        check(page.locator("#rc-exportlog-btn").count() == 0, "export log hidden for non-admins")

    # ── V2-3: saved views (server) ──
    page.set_viewport_size({"width": 1366, "height": 900})
    page.click("button.rc-starter:has-text('under 50%')")
    page.wait_for_timeout(800)
    check(not page.locator("#rc-save-view").is_disabled(), "save view enabled (table present)")
    page.click("#rc-save-view")
    page.wait_for_timeout(300)
    vname = "E2E %s %d" % (ROLE, int(time.time()))
    page.fill("#rc-saveview-form input[name=name]", vname)
    page.check("#rc-saveview-form input[name=scope][value=department]")
    page.click("#rc-saveview-submit")
    page.wait_for_timeout(1200)
    check(page.locator("#rc-views .rc-view button:has-text('%s')" % vname).count() == 1, "saved view listed under my views")
    check("%22v%22" in page.url or '"v"' in urllib.parse.unquote(page.url), "URL carries the view id")
    # reload the link → view marker restored and population applied
    page.reload()
    page.wait_for_selector(".rc-kpi", timeout=15000)
    page.wait_for_timeout(1500)
    check(page.locator("#rc-views .rc-view.is-on button:has-text('%s')" % vname).count() == 1, "view link reopens with marker")
    check(page.locator(".rc-chip").count() >= 2, "view link restores filters")
    if ROLE in ("school", "super"):
        # edit → pin as standing report → appears in starter row
        page.click("#rc-views .rc-view:has-text('%s') button[aria-label='Edit view']" % vname)
        page.wait_for_timeout(300)
        page.check("#rc-saveview-form input[name=pinned]")
        page.click("#rc-saveview-submit")
        page.wait_for_timeout(1200)
        check(page.locator(".rc-starter.is-pinned:has-text('%s')" % vname).count() == 1, "pinned view shows as standing report")
    # delete it again (keeps the test DB tidy)
    # DC-3: a shared (department) view is deleted through the in-page
    # danger dialog (MEDIUM: intent → read effects → type DELETE VIEW),
    # never a native browser confirm().
    page.click("#rc-views .rc-view:has-text('%s') button[aria-label='Delete view']" % vname)
    page.locator("[role=alertdialog]").wait_for(state="visible", timeout=6000)
    page.click("text=I want to delete this view")
    page.click("text=I have read and understand these effects")
    check(page.locator("[data-dcx-act=final]").is_disabled(), "delete view: final button locked until phrase typed")
    page.fill(".dcx-in", "DELETE VIEW")
    page.click("[data-dcx-act=final]")
    page.wait_for_timeout(1200)
    check(page.locator("#rc-views .rc-view button:has-text('%s')" % vname).count() == 0, "view deleted")
    page.screenshot(path="/tmp/rc_views.png", full_page=False)

    # mobile layout smoke
    page.set_viewport_size({"width": 390, "height": 800})
    page.wait_for_timeout(600)
    page.click("#rc-rail-toggle")
    page.wait_for_timeout(400)
    check(page.locator("#rc-rail.is-open").count() == 1, "mobile filter drawer opens")
    page.screenshot(path="/tmp/rc_mobile.png", full_page=False)

    browser.close()

for f in failed_requests:
    errors.append("request failed: " + f)
print(json.dumps({"errors": errors}, indent=2, ensure_ascii=False))
sys.exit(1 if errors else 0)
