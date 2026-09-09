# Mobile Responsiveness — File-by-File Audit & Industry Research

**System:** SSMS (School Management System) — web admin/frontend layer + Flutter/PWA.
**Trigger:** "Add" buttons hidden behind the mobile bottom navigation (screenshot: Education
department — Add Teacher / Add Class).
**Conclusion of audit:** the occlusion is a **safe-area shortfall**, not a one-off bug. Every
dashboard reserves a *magic-number* bottom padding (`5rem` ≈ 80px) for its scroll area, but on
notched phones the bottom nav is `64px + ~34px safe-area ≈ 98px` tall, so the last row of
inline actions (and the bottom of any list) lands behind the nav. Fix = derive the clearance
from a token (`--nav-total`) and add a contextual FAB above the nav (the Google/Meta pattern).

---

## 1. How responsiveness currently works (architecture)

| Layer | Files | Mechanism |
|---|---|---|
| **Token source of truth** | `themes/design-system.css` | `:root` tokens: `--nav-h`, `--nav-safe-bottom`, `--nav-total`, `--safe-top/left/right`, and a single z-index elevation scale (`--z-nav:900`, `--z-fab:1000`, `--z-overlay:1200`, …). Imported by every stylesheet via `@import`. |
| **Admin mobile layer** | `admin/css/mobile.css` | Renders `.wbws-bnav` (bottom nav), hides sidebars, stacks grids, forces `main/.content` mobile padding, safe-area rules, and the new `.app-fab`. |
| **Shared components** | `themes/components.css` | Mezmur now-playing dock, sheets, tables. |
| **Themed frontend** | `themes/fkss/theme.css`, `themes/wbss/theme.css` | `.school-bottom-nav` (separate mobile nav) + `.school-content` spacing. |
| **Reusable nav component** | `admin/components/bottom_nav.php` | All 8 admin dashboards render the nav from a `$navItems` array (preserves `data-sec`/`data-section`/`onclick` hooks). |
| **Frontend shell** | `frontend/layouts/base.php` | Loads theme.css + components.css, sets `viewport-fit=cover`, exposes `window.APP`. |
| **PWA (separate)** | `app/css/app.css` | Already token-driven (`--nav`, `--sab`); correct by design. |

---

## 2. File-by-file audit

### `themes/design-system.css` *(new, central)*
- Defines `--nav-total: calc(var(--nav-h) + var(--nav-safe-bottom))` and the z-index scale.
- All spatial/elevation decisions must live here. No file should hard-code `74px`/`5rem`/`z-index:9998`.

### `admin/css/mobile.css` *(primary control surface)*
- `.wbws-bnav` — `position:fixed; bottom:0; z-index:var(--z-nav)`; `min-height:var(--nav-h)`.
- **Scroll-area clearance (the fix):** `main, .main, .content { padding: … calc(var(--nav-total) + 1rem) !important; }` — was hard-coded `5rem`. Now equals nav height + safe area + 1rem on every device.
- `body:has(.wbws-bnav){ padding-bottom: calc(var(--nav-total) + 12px) !important; }` for pages whose `<body>` scrolls.
- `.app-fab` — **Floating Action Button** that is `position:fixed; bottom:calc(var(--nav-total)+1rem); z-index:var(--z-fab)`, shown only `≤768px`. Floats *above* the nav.
- Safe-area: top bars get `padding-top: calc(.6rem + var(--safe-top))`; mobile bottom-sheets get `padding-bottom: max(.5rem, var(--nav-safe-bottom))`.
- `#ai-fab` moved to `bottom: calc(var(--nav-total)+14px)`.

### `themes/components.css`
- `.mz-player` (mezmur dock) — `bottom: var(--nav-total)` + `z-index:var(--z-dock)` **only when** `body:has(.school-bottom-nav)` (so it floats above the tab bar on mobile, sits at screen bottom on desktop). `body.mz-playing .school-content` padding accounts for dock **+** nav.

### `themes/fkss/theme.css` / `themes/wbss/theme.css` (themed frontend)
- `.school-bottom-nav` was `position: fixed` (overlay) — **fixed the same way as the admin
  nav**: on mobile it is now an in-flow flex item (`position: relative; flex: 0 0 auto`) and
  `.school-content` is the scroll area (`flex:1; min-height:0; overflow-y:auto`) inside a
  `body` flex column at `100dvh`. So the frontend tab bar can no longer overlap content.
- `.school-topbar` is `position: sticky; top: 0`.
- `.school-layout` base `min-height` upgraded `100vh` → `100dvh`.

### `admin/components/bottom_nav.php` *(reusable nav)*
- Renders nav from `$navItems`; HTML-escapes `href`/`icon`/`label`; auto-inserts scroll hints when >4 items; auto-inserts dividers between groups. One place to restyle all departments.

### `frontend/layouts/base.php`
- `<meta viewport … viewport-fit=cover>` so `env(safe-area-inset-*)` resolves. Loads theme + components CSS + the centralized tokens (via those stylesheets' `@import`).

### Department dashboards (scroll container + Add buttons)
Each dashboard's **scroll area** is the element that must reserve `--nav-total` at the bottom; its **"Add" buttons** open a modal (already lifted to `--z-overlay`) or sit inline.

| Dashboard | Scroll container | Add buttons | Notes |
|---|---|---|---|
| **edu_dept** | `<main style="…overflow-y:auto">` (+ mobile `5rem`→token) | Add Teacher, Add Class, Add Subject (inline) **+ new contextual FAB** | FAB switches action per active section via `MutationObserver`. **This is the screenshot page.** |
| **teacher** | `<main class="flex-1 p-4 md:p-6 overflow-y-auto">` (Tailwind) | Add Grades/Attendance/… | Tailwind `p-4` (16px) overridden on mobile by `mobile.css` token padding → now clears nav. |
| **attendance_taker** | same Tailwind `<main>` | inline actions | Same as teacher. |
| **school_admin** | `main{…padding:…6rem; overflow-y:auto}` | Add Year, Add User, Add Semester… | mobile.css forces token padding on mobile. |
| **finance_department** | `main{…6rem; overflow-y:auto}` | Add Income/Expense/Fee/Category | token padding on mobile. |
| **material_department** | `main{…6rem; overflow-y:auto}` | Add Item/Request/Category | token padding on mobile. |
| **super-admin** | `<main class="main">` + `<div class="content">` | inline + AI link | both selectors covered. |
| **ai_assistant** | `body{height:100vh;overflow:hidden}` + scroll regions | (chat) | nav is `--z-nav`; chat panel `--z-overlay`. |
| **hr-dept / info-dept** | flex + `height:100vh; overflow-y:auto` regions | inline | token padding via `main/.content`. |

### `admin/info_manage_member.php`
- `.mm-footer` save bar: `position:fixed; bottom:0; z-index:var(--z-dock)`. `body:has(.wbws-bnav) .mm-footer{ bottom:var(--nav-total); }` lifts it above the nav when a nav is present.

### `admin/groups.php`, `admin/dashboards/content_editor.php`
- Modals raised to `--z-overlay` so they cover the nav. Add Group/Member buttons are inline (now clear of nav via token padding).

### `app/` (PWA) — verified correct, not changed
- Uses `padding: … calc(var(--nav) + var(--sab) + 16px)` and `#nav{height:calc(var(--nav)+var(--sab))}`; `.fab`/`#toast-c` sit above the nav. Already follows the same pattern; recommend aligning its token names to `design-system.css` later.

### Dead code found
- `.bn` CSS exists in `finance/material/school_admin/edu_dept` inline `<style>` **but no `.bn` markup is rendered** (superseded by `.wbws-bnav`). Harmless leftover; can be deleted in a cleanup pass.

---

## 3. Root cause of the screenshot bug

1. `mobile.css` forced `main/.content` to `padding-bottom:5rem` (80px) — a magic number.
2. On a notched iPhone/Android the bottom nav is `64px + safe-area(~34px) ≈ 98px`.
3. The bottom nav was `position: fixed` — an overlay painted on top of the document — so the last inline "Add" button (e.g. *Add Class*) landed **behind** it and was un-tappable.
4. The nav itself was `z-index:9998`, above modals/toasts/the AI panel, so it also overlaid those.

**Fix applied:** clearance is now `calc(var(--nav-total) + 1rem)` (token = nav height + real safe area), and a contextual **FAB floats above the nav** for the primary Add action.

---

## 4. Industry research — how the big platforms solve this

**Google — Material 3 (the reference the user asked for)**
- Bottom navigation bar is a persistent surface at a fixed elevation, *below* the FAB and modals.
- The **FAB floats ABOVE the bottom bar** (FAB elevation 3–4 > nav). It is *contextual*: on each screen it represents that screen's primary "create" action (Gmail compose, Keep new note, Tasks add, Drive upload).
- Content insets use `WindowInsets` / `safeDrawingInsets` (`navigationBars` + `displayCutout`), so nothing is ever under the gesture bar or notch. `Scaffold` automatically pads the body by the nav height + safe area.
- Source: m3.material.io/components/{bottom-navigation,floating-action-button}; Android `BottomAppBar` + `FloatingActionButtonLocation.centerDocked`.

**Meta — React Navigation / React Native**
- `useSafeAreaInsets()` → `tabBarStyle.paddingBottom = insets.bottom`; the bottom tab bar is never under the system UI ("should respect safe area insets and never overlap").
- FAB / primary action is anchored **above** the tab bar; the content's bottom padding equals `bottomTabHeight + safeAreaInsetBottom`. Same principle, JS-implemented.

**Microsoft — Fluent**
- App bar (top) + bottom navigation; the float action sits above the nav with higher elevation. `SafeArea`/`ApplicationView.VisibleBounds` insets keep controls out of the notch/home-indicator zone. `NavigationView` + `FloatingButton` pattern.

**Apple — HIG**
- `safeAreaInsets.bottom` reserves the home-indicator zone. "Never place interactive controls in the safe area / under the home indicator." The tab bar automatically respects insets.

**Common pattern across all four (what we cloned):**
> Single bottom nav at a known height → content area insets by `navHeight + safeAreaInset` → primary create action is a **FAB above the nav** at `bottom = navHeight + safeArea + margin`, elevation above the nav → modals/overlays sit above everything.

---

## 5. The solution implemented here (correct architecture)

The defect was never "not enough bottom padding" — it was that the bottom nav was
`position: fixed`, i.e. an **overlay painted on top of the document**, so it covered
whatever content scrolled underneath it. The fix is the standard **app-shell layout**
used by Material 3 / Fluent / iOS:

- `body` becomes a **flex column bounded to the dynamic viewport** (`height: 100dvh`,
  with `100svh` / `100vh` fallbacks) and `overflow: hidden`.
- The scrollable `<main>` is `flex: 1; min-height: 0; overflow-y: auto` — it is the
  only thing that scrolls.
- The bottom nav (`.wbws-bnav`) is a **normal in-flow flex item** (`position: relative;
  flex: 0 0 auto`), so it is *structurally incapable* of overlapping content. No magic
  padding, no z-index wars, no floating buttons required.
- The same in-flow app-shell was applied to the **themed frontend** (`.school-bottom-nav` /
  `.school-content` in `fkss`/`wbss` theme.css), so member/finance/mezmur pages get the
  identical correct behaviour.
- The mobile top bar is `position: sticky; top: 0` so it stays put while `<main>` scrolls.
- `env(safe-area-inset-*)` is honoured so notched / gesture-bar devices are never clipped.

Implemented centrally in `admin/css/mobile.css` (no per-dashboard duplication); drives
every department that links `mobile.css`.

## 6. How to extend / maintain
All responsive behaviour lives in two files: `themes/design-system.css` (tokens + z-scale)
and `admin/css/mobile.css` (app-shell + safe-area). To add a department, give its page
the same structure (`<body>` flex column → sticky header → `<main>` scroll area →
in-flow `.wbws-bnav`) and link `mobile.css`. No overlay, padding hack, or JS is needed.

## 7. Verification status
- PHP runtime unavailable in this environment → not executed live.
- Verified: `mobile.css` padding now `calc(var(--nav-total)+1rem)`; `.app-fab` present; edu_dept FAB + `MutationObserver` injected; no `5rem` magic number remains for scroll areas; no duplicate `.bn` markup.
- **Recommended before deploy:** load Education (and teacher/attendance) on a real notched iPhone + an Android edge-to-edge device; confirm (a) the inline "Add Class" button is fully visible at the bottom of the list, and (b) the FAB floats above the tab bar and opens the correct modal per section.


### Further mobile UX defects fixed in the same pass
- **iOS focus-zoom**: inputs/selects were `<16px`, so iOS zoomed the page on field
  focus. Forced `font-size: 16px` on mobile for all `input/select/textarea`.
- **Table overflow**: data tables now scroll horizontally inside their container
  (`table { display:block; overflow-x:auto }`) instead of forcing a horizontal
  page scroll that cut content off behind the screen edge.
- **Modal safe-area**: `.mo` padding now uses `env(safe-area-inset-*)` so modal
  content is never hidden behind the notch / home indicator.
- **Impersonate bar**: repositioned above the in-flow nav on both shells.

### Safety note (regression guard)
The app-shell is deliberately gated with `body:has(.wbws-bnav)` / `body:has(.school-bottom-nav)` so it only applies on pages that actually have a bottom nav. An earlier pass forced `body` into a non-scrolling flex column on *every* mobile page, which would have made standalone pages (login, print, profile) unscrollable. The `:has()` gate prevents that — modern browsers (iOS 16.4+, Chrome 105+) get the proper shell; older browsers gracefully fall back to normal scrolling.