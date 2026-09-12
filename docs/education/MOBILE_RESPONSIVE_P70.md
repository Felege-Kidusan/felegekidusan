# P70 — Education Department Dashboard: Native Mobile / Tablet Responsiveness

**Scope:** `admin/dashboards/edu_dept.php` ONLY (the live education dashboard — the
frontend `frontend/pages/edu_*.php` files are 302 redirects into it).
**Authorization (§37):** HTML structure, new screens, workflow/UX layout redesign
for a native mobile app look; education department only; backend/logic untouched.
**Status:** impact analysis (pre-implementation).

---

## 1. Where the education dashboard actually lives [FACT]

| Path | Role |
|---|---|
| `frontend/pages/dashboard.php` | frontend router maps `edu_dept` → `edu_dept.php` |
| `frontend/pages/edu_assignments.php`, `edu_teachers.php` | 1-line PHP files: 302 redirect → `/admin/dashboards/edu_dept.php?section=teachers` |
| `admin/dashboard.php` (router) | `case 'edu_dept': require dashboards/edu_dept.php` — no shared `<head>` |
| `admin/dashboards/edu_dept.php` | **THE dashboard** (2 144 lines, standalone: Tailwind Play CDN + Font Awesome + inline `<style>` + `admin/css/mobile.css` + `admin/css/report_card.css` + `admin/theme.php` include) |
| `admin/dashboards/education_department.php` | legacy sibling; kept reachable by access_control but not routed to — untouched |

Shared infrastructure this page depends on (**read-only** for P70 — used by many
departments): `admin/css/mobile.css` (789 lines), `admin/components/bottom_nav.php`,
`admin/theme.php`, `themes/design-system.css`, `admin/css/report_card.css`,
`admin/js/wbws-calendar.js`.

## 2. Root cause of the named defect (modals overlapped by bottom nav) [FACT]

`admin/css/mobile.css` and the shared bottom-nav component are **token-driven** —
they reference `var(--z-nav)`, `var(--z-overlay)`, `var(--nav-h)`, `var(--nav-total)`,
`var(--safe-top)`, `var(--space-4)`. Those tokens are defined **only in
`themes/design-system.css`**, which `edu_dept.php` never loads (no `<link>`, no
shared head — the admin router is a bare `require`).

Consequences on this page:

1. `.mo { z-index: var(--z-overlay) }` (page's own modal CSS) → declaration invalid
   at computed-value time → z-index falls back to `auto`.
2. `.wbws-bnav { z-index: var(--z-nav) }` (shared nav CSS) → also `auto`.
3. Both elements are positioned → painting order falls back to **DOM order** →
   the bottom nav (rendered after the modals) paints **above every modal** —
   exactly the reported "popup modals are overlapped by the bottom nav".
4. `.wbws-bnav { min-height: var(--nav-h) }` → `auto`; `main`'s app-shell padding
   `var(--space-4)` → 0; `.wbws-mob-header` z `var(--z-header)` → `auto`;
   `#impersonateBar` z `var(--z-impersonate)` → `auto`. The whole shared mobile
   app-shell degrades on this page.

`themes/design-system.css` documents the intended elevation order and states the
nav is deliberately below overlays ("this is the bug that caused buttons to be
hidden behind the nav") — the system exists; this page simply never receives it.

## 3. Defect inventory (mobile ≤768 unless noted)

| # | Defect | Cause | Fix (P70) |
|---|---|---|---|
| E1 | **Modals overlapped by bottom nav** (named) | undefined z-tokens (§2) | token bridge in page CSS (`:root` mirror of design-system tokens) |
| E2 | Nav height/safe-area not honoured | `--nav-h`, `--nav-safe-bottom` undefined | same token bridge |
| E3 | Modals are desktop centre-dialogs on phones | no sheet pattern | iOS bottom sheets ≤768 (align-items:flex-end + rounded top + grabber + slide-up) — same pattern approved for mezmur in P68 |
| E4 | Data tables = 15 dense wide tables, horizontal scroll panes | none of the 15 `.dt` tables has a phone layout | CSS-only card-row transform per table (thead hidden, tbody→cards, per-column labels via scoped nth-child rules; DOM untouched so all 173 JS DOM targets keep working) |
| E5 | Inputs `.inp` at 13.6px → iOS focus zooms page | page CSS `.85rem` (mobile.css already forces 16px globally — covered once nothing overrides; re-asserted for clarity) | `.page-edu .inp {font-size:16px}` ≤768 |
| E6 | Inline 2-col grids cramped on phones (forms in modals, dashboard cards) | inline `grid-template-columns:1fr 1fr` | stack to 1 col ≤768 (same technique mobile.css already uses) |
| E7 | Section switch keeps old scroll position | `nav()` never resets scroll | 1-line scroll reset in `nav()` (UX behaviour only) |
| E8 | Stat grid single huge column on small phones | `minmax(180px,1fr)` auto-fit | 2-col compact stats ≤768 |
| E9 | Sub-tab rows (`.tbn`) overflow/wrap oddly | inline flex row | native scrollable tab scroller (attribute selector on the existing inline border-bottom style) |
| E10 | Action buttons `.ab` 36px, `.btn-xs` tiny | small hit targets | ≥40px ≤768 |
| E11 | Toast bottom hardcoded 5.2rem | not token-driven | `calc(var(--nav-total) + .75rem)` |
| E12 | Sections pop with no motion / no reduced-motion guard | none | subtle section fade-in + `prefers-reduced-motion` guard |

Tablet (769–1024): sidebar stays (iPad pattern — standing decision), shared
mobile.css tablet block already narrows it to 200px; touch-target guard already
global (`hover:none`) — minimal delta by design (P68/P69 lesson).

## 4. Design decisions

1. **Token bridge, not a new `<link>`** — loading `themes/design-system.css` here
   would pull the whole frontend dark-theme surface system into a light Tailwind
   page (restyling risk). Instead the page's new style block re-declares the
   tokens verbatim in `:root`, citing design-system.css as source of truth.
   Shared files stay byte-identical → zero cross-department impact.
2. **CSS-only card tables** — the alternative (adding label attributes to ~12
   JS-rendered `<tr>` template strings) touches logic-bearing files; CSS
   nth-child labels keep the DOM byte-identical so every JS selector
   (`#enrollArea .dt tbody tr`, `.roster-cb:checked`, …) is guaranteed to work.
   Column-count contracts are pinned by tests so templates and labels cannot
   drift apart silently.
3. **Bottom sheets for all 11 modals** (10 static + JS-built `termModal`) — they
   already share the `.mo > .mc > gradient-header` pattern; `rcModal` (report
   card preview, transparent wrapper) gets its own inner-scroll variant.
4. **Scope discipline** — every new rule is prefixed `body.page-edu` (body gets
   the class; mirrors the `body.page-mezmur` convention) except `:root` tokens
   and `@keyframes`. The block loads **after** mobile.css/theme.php so the
   cascade wins without `!important` escalation wherever possible.
5. **Screen-only media guards** — card/sheet rules live in
   `@media screen and (max-width:768px)` so report-card printing (uses print
   media) is untouched.
6. **Kept as-is (existing architecture, out of scope):** Tailwind Play CDN
   delivery (system-wide pattern across all admin dashboards; swapping it is an
   architecture change, not UI/UX), `wbws-calendar.js` date pickers (shared with
   finance — same call as P69), the dead legacy `.bn` CSS rules (no matching
   element; zero runtime effect — removing them is cleanup, not defect fixing).

## 5. Change surface

| File | Change |
|---|---|
| `admin/dashboards/edu_dept.php` | `<body>` → `<body class="page-edu">`; one new `<style id="p70-edu-mobile">` after the theme.php include; 1-line scroll reset inside `nav()`. **No other markup/JS/PHP edits.** |
| `tests/security/test_edu_uiux.py` | NEW — static contract suite (tokens, z-order, sheets, card tables, label alignment pins, scoping, behaviour pins) |
| `docs/education/MOBILE_RESPONSIVE_P70.md` | this document + result log |
| workspace preview | `responsive-preview-p70.html` (device-frame demo, same pattern as P68/P69) |

All shared files remain byte-identical (verified in the final report).

## 6. Test plan

- New `tests/security/test_edu_uiux.py` (static contracts; details in §5 of file).
- Re-run `test_mezmur_uiux.py` (must stay 30/30) and the full suite matrix —
  zero new failures vs the 42-failure pre-existing baseline.
- Preview HTML for visual QA by the user on a real device (PHP can't run in the
  sandbox; preview is a hand-rendered static frame with the real CSS).

---

## 7. Result log (implementation)

**Changed files (exactly three):**

| File | Delta |
|---|---|
| `admin/dashboards/edu_dept.php` | +334/−1 — `<body class="page-edu">`; `<style id="p70-edu-mobile">` inserted after the theme.php include (token bridge `:root`, bottom sheets, card tables + per-table labels, forms/layout polish, tablet + 380px blocks, reduced-motion guard); 1-line scroll reset in `nav()` |
| `tests/security/test_edu_uiux.py` | NEW — 25 contract tests |
| `docs/education/MOBILE_RESPONSIVE_P70.md` | this document |

Shared files byte-identical: `admin/css/mobile.css`, `admin/components/bottom_nav.php`,
`admin/theme.php`, `themes/design-system.css`, `admin/css/report_card.css`,
`admin/js/wbws-calendar.js`. No other dashboard touched.

**Test results:**

- `tests.security.test_edu_uiux` — **25/25 OK** (token bridge parity with
  design-system.css, elevation order, scoping, sheet/card contracts, label
  alignment pins, thead column-set pins, screen guards, behaviour pins).
- `tests.security.test_mezmur_uiux` — **30/30 OK** (unchanged).
- Full matrix (security + audit + smoke): 684 tests, **42 failing — the exact
  pre-existing baseline** (missing php binary / Flutter sources /
  hr_register_member.php / apache header config). Zero new failures; none of
  the failing ids touch education files.

**How the named defect is fixed:** the page now defines the missing
`--z-*` / nav / safe-area tokens in its own `:root`, so `.mo` resolves to
`z-index: 1200` (overlay) while the bottom nav resolves to `900`, the mobile
header to `200`, toasts to `1100` and the impersonate bar to `1300` — the
exact elevation scale `themes/design-system.css` prescribes. Modals therefore
paint above the bottom nav on every department screen of this page, on phone,
tablet and desktop, and the nav regains its 64px height + gesture-bar inset.

**Verification status:** static contracts verified in-sandbox (no PHP runtime
available). Visual QA on a real device remains with the user — pull, hard
refresh, then check: any modal opens as a bottom sheet that fully covers the
bottom nav; tables render as labelled cards on the phone; no horizontal page
scroll; focusing an input does not zoom iOS; section switches start at the
top; report-card printing still prints the desktop table layout.
