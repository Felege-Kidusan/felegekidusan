#!/usr/bin/env python3
"""
Browser walk-through of the Family Link UX (Playwright/Chromium).

    python3 tests/e2e/family_link_browser.py [base_url] [shots_dir]

Logs in as the HR user, visits HR → Family Link, exercises: KPI strip, tabs,
search, drawer (edit/save, relation, primary, add child, unlink), review
queue (compare + merge/keep), Not-linked-yet (Link / Link all), Activity,
member profile Family card (sibling navigation inside the sheet + Back,
Manage family deep link), and the standalone-URL bounce. Fails on any JS
console error or failed assertion. Writes screenshots for visual review.
"""
import os
import re
import sys
import time

from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
SHOTS = sys.argv[2] if len(sys.argv) > 2 else "/tmp/fam_shots"
os.makedirs(SHOTS, exist_ok=True)
USER = os.environ.get("FAM_USER", "hruser")
PASS = os.environ.get("FAM_PASS", "Test@12345")

errors = []
checks = []


def check(cond, label):
    checks.append((bool(cond), label))
    print(("  ok   " if cond else "  FAIL ") + label)


def shot(page, name):
    page.wait_for_timeout(450)  # let slide-in animations finish
    page.screenshot(path=os.path.join(SHOTS, name + ".png"), full_page=False)


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1440, "height": 900})
    page = ctx.new_page()
    # JS errors fail the run; missing optional resources (e.g. profile_image.php 404 for members without a photo) do not.
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" and not m.text.startswith("Failed to load resource") else None)
    page.on("pageerror", lambda e: errors.append(str(e)))
    # External CDN assets (Tailwind, FontAwesome) load for real so the layout matches production.

    # login
    page.goto(BASE + "/admin/index.php")
    page.fill("input[name=username]", USER)
    page.fill("input[name=password]", PASS)
    page.click("button[type=submit], input[type=submit]")
    page.wait_for_load_state("networkidle")

    print("== Family Link page")
    page.goto(BASE + "/admin/dashboard.php?section=family")
    page.wait_for_selector("#famStats .fam-kpi", timeout=15000)
    page.wait_for_selector("#famList table, #famList .fam-empty:not(.fam-loading)", timeout=15000)
    kpis = page.locator("#famStats .fam-kpi").count()
    check(kpis == 5, f"KPI strip renders 5 cards ({kpis})")
    head = page.locator("#section-family h3").first
    color = head.evaluate("e => getComputedStyle(e).color")
    check(color not in ("rgb(255, 255, 255)",), f"heading is readable on white ({color})")
    rows = page.locator("#famList tr.fam-row").count()
    check(rows >= 1, f"families table lists guardians ({rows})")
    shot(page, "01_family_overview")

    # search
    page.fill("#famSearch", "Aster")
    page.wait_for_timeout(700)
    check(page.locator("#famList tr.fam-row").count() >= 1 and "Aster" in page.locator("#famList").inner_text(), "search narrows the list")
    page.fill("#famSearch", "")
    page.wait_for_timeout(600)
    page.click("#famFilters [data-filter=multi]")
    page.wait_for_timeout(600)
    check(all(int(t) >= 2 for t in page.locator("#famList .fam-count").all_inner_texts()), "2+ children filter works")
    page.click("#famFilters [data-filter=all]")
    page.wait_for_timeout(600)

    print("== Guardian drawer")
    page.locator("#famList tr.fam-row").filter(has_text="Aster").first.click()
    page.wait_for_selector("#famDrawer.open #famEditForm", timeout=10000)
    check("guardian=" in page.url, "deep-link URL updated with guardian id")
    kids_before = page.locator("#famDrawerBody table tbody tr").count()
    check(kids_before >= 2, f"drawer shows children table ({kids_before})")
    shot(page, "02_guardian_drawer")
    # inline edit → save
    page.fill("#famEditForm input[name=phone_alt]", "0911000099")
    page.click("#famEditForm button[type=submit]")
    page.wait_for_timeout(900)
    check(page.locator("#famDrawerBody").inner_text().count("0911000099") >= 1, "inline edit saved and re-rendered")
    # relation change
    sel = page.locator("#famDrawerBody table tbody tr").first.locator("select")
    sel.select_option("mother")
    page.wait_for_timeout(900)
    check(page.locator("#famDrawerBody table tbody tr").first.locator("select").input_value() == "mother", "relation dropdown persists")
    # add child via typeahead
    page.fill("#famKidQ", "Feven")
    page.wait_for_selector("#famKidL .fam-dd-item", timeout=8000)
    page.locator("#famKidL .fam-dd-item").first.click()
    page.wait_for_timeout(900)
    kids_after = page.locator("#famDrawerBody table tbody tr").count()
    check(kids_after == kids_before + 1, f"add child via typeahead ({kids_before}->{kids_after})")
    # unlink that child (last row = Feven alphabetically? find by text)
    # DC-3: unlink is MEDIUM — in-page dialog, type UNLINK CHILD (no native confirm()).
    page.locator("#famDrawerBody table tbody tr").filter(has_text="Feven").first.locator("button.fam-btn-danger").click()
    page.locator("[role=alertdialog]").wait_for(state="visible", timeout=6000)
    page.click("text=I want to remove this link")
    page.click("text=I have read and understand these effects")
    check(page.locator("[data-dcx-act=final]").is_disabled(), "unlink: final button locked until UNLINK CHILD typed")
    page.fill(".dcx-in", "UNLINK CHILD")
    page.click("[data-dcx-act=final]")
    page.wait_for_timeout(1200)
    check(page.locator("#famDrawerBody table tbody tr").count() == kids_before, "unlink child restores count")
    # open child profile from drawer → member sheet on top
    page.locator("#famDrawerBody table tbody tr").first.locator("a").first.click()
    page.wait_for_selector("#manageSheet.open #view-preview", timeout=10000)
    check(page.locator("#manageSheet.open").count() == 1, "child profile opens in the member sheet above the drawer")
    shot(page, "03_child_profile_from_drawer")
    page.click("#manageSheet button[onclick='closeManageSheet()']")
    page.wait_for_timeout(300)
    page.click("#famDrawer .fam-drawer-hd button[title='Close']")
    page.wait_for_timeout(300)
    check(page.locator("#famDrawer.open").count() == 0 and "guardian=" not in page.url, "drawer closes and URL cleaned")

    print("== Review queue")
    page.click("#famTab-review")
    page.wait_for_selector("#famQueue .fam-sug, #famQueue .fam-empty:not(.fam-loading)", timeout=10000)
    # DC-3: re-match scan is LOW — simple in-page dialog, one click.
    page.click("#famRematchBtn")
    page.locator("[role=alertdialog]").wait_for(state="visible", timeout=6000)
    page.click("[data-dcx-act=final]")
    page.wait_for_timeout(300)
    page.wait_for_function("!document.getElementById('famRematchBtn').disabled", timeout=60000)
    page.wait_for_timeout(500)
    page.wait_for_selector("#famQueue .fam-sug, #famQueue .fam-empty:not(.fam-loading)", timeout=10000)
    sugs = page.locator("#famQueue .fam-sug").count()
    check(sugs >= 1 or "No pairs" in page.locator("#famQueue").inner_text() or page.locator("#famQueue .fam-empty").count() == 1, f"review queue renders pairs or a clear empty state ({sugs})")
    check(page.locator("#famQueue .fam-cmp").count() == sugs * 2, "each pair has two compare columns")
    check(page.locator("#famLastRematch").inner_text().strip() != "", "last re-match stamp shown")
    shot(page, "04_review_queue")
    if sugs:
        page.once("dialog", lambda d: d.accept())
        page.locator("#famQueue .fam-sug").first.locator("button", has_text="keep both").click()
        page.wait_for_timeout(900)
        check(page.locator("#famQueue .fam-sug").count() == sugs - 1, "keep both resolves the pair")

    print("== Not linked yet")
    page.click("#famTab-unlinked")
    page.wait_for_selector("#famUnlinked table, #famUnlinked .fam-empty:not(.fam-loading)", timeout=10000)
    shot(page, "05_not_linked")
    txt = page.locator("#famPane-unlinked").inner_text()
    check("Link" in txt or "Everything is linked" in txt, "not-linked tab explains and offers Link / shows all-linked state")

    print("== Activity")
    page.click("#famTab-activity")
    page.wait_for_selector("#famActivity li, #famActivity .fam-empty:not(.fam-loading)", timeout=10000)
    check(page.locator("#famActivity li").count() >= 1, "activity lists audit entries")
    shot(page, "06_activity")

    print("== Member profile → siblings → back → manage family")
    # pick a member that actually has siblings (ids differ per test DB)
    multi = page.request.get(BASE + "/admin/api_guardians.php?action=list_guardians&filter=multi").json()
    fam = page.request.get(BASE + "/admin/api_guardians.php?action=get&guardian_id=%d" % int(multi["rows"][0]["id"])).json()
    member_id = int(os.environ.get("FAM_MEMBER") or fam["guardian"]["children"][0]["id"])
    page.goto(BASE + "/admin/dashboard.php?section=manage")
    page.wait_for_load_state("networkidle")
    page.evaluate("openManageSheet(%d)" % member_id)
    page.wait_for_selector("#manageSheet.open .mm-fam-sib", timeout=10000)
    shot(page, "07_profile_family_card")
    first_name = page.locator("#manageSheet .mm-hdr-name").inner_text()
    page.locator("#manageSheet .mm-fam-sib").first.click()
    page.wait_for_timeout(1200)
    second_name = page.locator("#manageSheet .mm-hdr-name").inner_text()
    check(page.locator("#manageSheet.open").count() == 1 and second_name != first_name, f"sibling opens inside the sheet ({first_name} -> {second_name})")
    check(page.locator("#mmBackBtn").is_visible(), "Back button visible after sibling navigation")
    shot(page, "08_sibling_in_sheet_with_back")
    page.click("#mmBackBtn")
    page.wait_for_timeout(1200)
    check(page.locator("#manageSheet .mm-hdr-name").inner_text() == first_name, "Back returns to the previous member")
    page.locator("#manageSheet .mm-fam-btn", has_text="Manage family").first.click()
    page.wait_for_selector("#famDrawer.open #famEditForm", timeout=10000)
    check(page.locator("#section-family.active").count() == 1, "Manage family jumps to Family Link with the guardian open")
    shot(page, "09_manage_family_deeplink")

    print("== Standalone URL bounce")
    page.goto(BASE + "/admin/info_manage_member.php?id=%d" % member_id)
    page.wait_for_timeout(1500)
    check("dashboard.php" in page.url and page.locator("#manageSheet.open").count() == 1, "direct profile URL bounces into the dashboard with the sheet open")
    shot(page, "10_standalone_bounce")

    print("== Registration picker")
    page.goto(BASE + "/admin/dashboard.php?section=members")
    page.wait_for_load_state("networkidle")
    if page.locator("#regGuardianPick").count():
        page.evaluate("toggleMemberRegistrationForm(true, 'permanent')")
        page.wait_for_selector("#regGuardianPick", state="visible", timeout=8000)
        page.locator("#regGuardianPick").scroll_into_view_if_needed()
        page.fill("#regGuardianPick", "Aster")
        page.wait_for_selector("#regGuardianPickList button[data-gid]", timeout=8000)
        bg = page.locator("#regGuardianPickList").evaluate("e => getComputedStyle(e).backgroundColor")
        check(bg == "rgb(255, 255, 255)", f"picker dropdown is light-themed ({bg})")
        page.locator("#regGuardianPickList button[data-gid]").first.click()
        page.wait_for_timeout(300)
        check(page.locator("#regGuardianPicked").is_visible() and int(page.input_value("#regGuardianLinkId")) > 0, "picking a guardian sets the hidden link id + visible chip")
        shot(page, "11_registration_picker")
        page.click("#regGuardianPicked a")
        check(page.input_value("#regGuardianLinkId") == "0", "clear link resets the pick")

    print("== Mobile viewport")
    ctx2 = browser.new_context(viewport={"width": 390, "height": 844}, storage_state=ctx.storage_state())
    m = ctx2.new_page()
    m.goto(BASE + "/admin/dashboard.php?section=family")
    m.wait_for_selector("#famStats .fam-kpi", timeout=15000)
    m.wait_for_selector("#famList table, #famList .fam-empty:not(.fam-loading)", timeout=15000)
    sw = m.evaluate("document.documentElement.scrollWidth")
    check(sw <= 400, f"no horizontal overflow on phone ({sw}px)")
    shot(m, "12_mobile_overview")
    m.locator("#famList tr.fam-row").first.click()
    m.wait_for_selector("#famDrawer.open #famEditForm", timeout=10000)
    shot(m, "13_mobile_drawer")
    ctx2.close()
    browser.close()

failed = [l for ok, l in checks if not ok]
print(f"\n{len(checks) - len(failed)} passed, {len(failed)} failed; console errors: {len(errors)}")
for e in errors[:10]:
    print("  console:", e[:200])
sys.exit(1 if failed or errors else 0)
