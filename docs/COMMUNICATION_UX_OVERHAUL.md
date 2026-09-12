# Communication Center UX Overhaul — Master Plan (P73)

**Status:** Phase 2 complete + Phase 2.1 isolation hotfix (shipped) — the Communication section lives on every dashboard + the two thin-shell pages, and the shared component is isolation-hardened (self-bootstrapping + error boundary). Phase 3 (Telegram-grade messaging redesign) is next. This document is the single source of truth for
the overhaul: the request contract, the verified defect trace, research
conclusions, the target architecture, and the phase plan. Each phase ships
independently, keeps the full test matrix green, and confirms the five
mandatory rules (§6).

---

## 1. Request Contract (verbatim understanding)

The user's requirements, restated precisely. Items marked INFERENCE are
interpretations that later phases validate against feedback.

1. **Mobile UX is broken today**: elements overlap (including inside the
   notification panel itself), some buttons do not work, the layout is bad.
2. **Desktop is untestable**: the bell icon "and other things are not
   accessible easily."
3. **Make it like other system sections**:
   - Desktop: a **sidebar button** opens the communication section — same
     navigation model as Members, Submissions, etc.
   - Mobile: **no full-screen takeover** — the section is reached through the
     **bottom navigation**, like every other section.
4. **The bell notifies only**: the bell popover shows recent notifications
   with a badge; tapping Messages / Announcements / similar **navigates to the
   dedicated section**. The bell is an *action surface*, not a destination.
5. **Messaging = Telegram-style**:
   - A **list of the system's users** (contacts) so staff can chat directly —
     not a "Hold Ctrl to multi-select" form.
   - Professional UI/UX: animations, clean typing area, attention to detail.
   - The **message-writing box has no visible scroller** — an arena.ai-style
     composer (grows with content to a ceiling; beyond the ceiling it scrolls
     internally without a visible scrollbar; Enter sends, Shift+Enter breaks
     a line). [INFERENCE: Enter-to-send is the Telegram/arena.ai convention.]
6. **Not a separate page**: the feature must feel like **part of the system**
   — same shell, same navigation, same design language — not a standalone
   app-looking page. [INFERENCE: dedicated URLs may still exist for deep
   links and the mobile app, but they render inside the system shell.]
7. **Consistent everywhere**: identical UI/UX, workflow, and placement on all
   dashboards — it is one shared feature.
8. **Professional state handling**: proper error, success, and loading states
   ("currently too confusing").
9. **Boost performance as much as possible** (shared hosting — see §4.4).
10. **Process**: understand fully → split into phases → execute one phase at
    a time, each confirming the mandatory rules (§6).

## 2. Current-State Trace (verified in code, line-precise)

### 2.1 Desktop defects

| # | Defect | Root cause (verified) |
|---|---|---|
| D1 | **Bell unreachable / panel off-screen on edu_dept + material_department** | P72 placed the bell at the *sidebar bottom* (`margin-top:auto` slot above the user card). The panel is `position:absolute; right:0` relative to the bell → a 396px panel right-anchored at the sidebar's right edge extends **leftward off the viewport** on any screen where the sidebar is narrower than the panel. On all other dashboards the bell sits in a topbar and the panel works — which is why the complaint centers on the worst two dashboards. |
| D2 | **Panel can be clipped/trapped** | `position:absolute` inside `.nc-root` inside dashboard chrome (sidebars with `overflow`, stacking contexts from gradients/transforms) — the exact class of bug `themes/design-system.css` was created to end (see its header comment). Any ancestor with `overflow` or a lower stacking context clips or buries the panel. |
| D3 | **Hard-coded z-index + colors** | The component uses `z-index: 1200` and its own hex palette instead of the Design OS elevation tokens (`--z-overlay` etc.) — the same "magic numbers" anti-pattern that caused the historical bottom-nav overlay bug (docs/RESPONSIVE_BOTTOM_NAV_FIX.md, root causes B/C). |

### 2.2 Mobile defects

| # | Defect | Root cause (verified) |
|---|---|---|
| D4 | **Full-screen takeover** | `@media (max-width:768px)` makes the panel a fixed sheet with `max-height: 82vh` — nearly the whole screen, covering the bottom nav (z 1200 > nav 900), **with no scrim**, so the dashboard visibly "overlaps" beneath it. The user wants sections via the bottom nav instead. |
| D5 | **Overlaps inside the panel** | No safe-area handling, no viewport clamping, and the sheet's content (`max-height:none; flex:1`) lets lists grow to 82vh under the grab handle. |

### 2.3 Broken / confusing interactions

| # | Defect | Root cause (verified) |
|---|---|---|
| D6 | **"Tap to retry" does nothing** | `loadTab()` renders *"Could not load. Tap to retry."* on failure — but **no click handler is attached** to retry. A dead button by construction. |
| D7 | **No busy/feedback states on writes** | Mark-all-read / mark-read / task buttons fire fetches with no in-flight state, no success feedback, no failure recovery (a failed `mark_read` is silent — the old bell's exact defect, back via the UI layer). |
| D8 | **Standalone pages feel alien** | `admin/messages.php` + `admin/notifications.php` ship their own `:root` token set, their own topbar, own CSS — visually and structurally a separate app. Violates "part of the system." |
| D9 | **Composer is a fixed-height box with an internal scrollbar** | `height:44px; max-height:140px; resize:none` with **no auto-grow logic** — typing multi-line text scrolls inside a 44px box: exactly the "scroller on the message writing box" the user rejects. |
| D10 | **Recipient picker is a native `<select multiple>`** | "Hold Ctrl / Cmd to select several people" — a form control, not a contact list. Not Telegram-like; unusable on touch. |
| D11 | **No live updates / no read receipts / no optimistic send** in messaging; thread list error state has no retry; toasts overlap chrome (fixed `bottom:20px`, z 999, ignores bottom nav + safe areas). | |
| D12 | **API actions exist but UX never surfaces failures** — e.g. the Tasks tab works only if its DB objects exist; any 500 renders a dead-end message (see D6). | |

### 2.4 Performance defects

| # | Defect | Root cause |
|---|---|---|
| P1 | **~30KB of CSS+JS inlined into every page render** (component emits full `<style>`+`<script>` inline, once per page) | No browser caching, re-downloaded on every navigation, defeats PHP opcode/static-file caching on shared hosting. |
| P2 | **Polling re-downloads full payloads** | `get('summary')` every 30s per tab; no ETag/304, no backoff on failure, no request de-duplication (fetch storms when several instances refresh). |

## 3. Research — what the big companies do (sources consulted)

- **Google Material Design 3 — navigation**: bottom navigation is for **3–5
  top-level destinations of similar importance**; secondary destinations nest
  within sections. A bell popover is an **action**, not a destination. →
  Communication becomes a *destination* (sidebar button / bottom-nav entry);
  the bell stays an *action surface* with recent items + links.
- **Telegram UI/UX deep dive** (createbytes.com analysis + hubo.dev
  Telegram-iOS walkthrough): minimal palette + generous whitespace; chat list
  with avatar / name / last-message preview / time / unread badge; bubbles
  with clear left/right alignment, distinct colors, timestamps and **read
  receipts**; **inverted list** (newest at bottom, auto-scroll);
  **progressive disclosure** (power features revealed in context, e.g.
  long-press send options); consistent design language across platforms.
- **Chat UI essentials** (UXPin 2026 guide): input + send button + bubbles
  with sender identity + timestamps + avatars + typing indicator + read
  receipts + **explicit error handling for failed messages**; WCAG: ≥44px
  touch targets, ARIA on dynamic regions, 4.5:1 contrast, full keyboard
  operation.
- **Composer without a visible scrollbar** (talkingtech.io + StackOverflow):
  modern answer is CSS `field-sizing: content` with `min-height`/`max-height`
  bounds — the browser sizes natively (compositor thread, zero JS). Legacy
  fallback: the classic `height=''; height=min(scrollHeight, max)` resize
  trick. Hide the residual scrollbar (`scrollbar-width:none`,
  `::-webkit-scrollbar{display:none}`) past the ceiling. This is the
  arena.ai-style composer behavior.
- **Polling on a budget host** (no WebSockets/SSE viable on shared PHP
  hosting): **conditional requests** (ETag/`If-None-Match` → 304 responses
  cost almost nothing — GitHub's own API pattern), **visibility-aware
  pausing** (already partially present), **exponential backoff with a cap on
  consecutive failures**, **immediate poll on tab focus**, **request
  de-duplication**, staggered resume to avoid thundering herds.

## 4. Target Architecture

### 4.1 Static, cacheable assets — one runtime (fixes P1, D3)

```
admin/css/comm.css        ← ALL communication styling, token-driven
admin/js/comm.js          ← ONE runtime: API client, states, bell, sections
admin/components/notification_center.php  ← thin markup + asset <link>/<script>
                                           (assets-once guard, CSRF via data-attr)
admin/components/comm/…   ← section partials (Phase 2+)
```

- The PHP component keeps its public API (`renderNotificationCenter()`,
  `renderNotificationBell()` shims, `nc-root` class, multi-instance contract)
  but emits **`<link>`/`<script src>` once per page** instead of inlining
  ~30KB per request. Browsers cache; the PHP cost per page drops to ~1 line.
- **Zero magic numbers**: every elevation from `themes/design-system.css`
  z-scale; every color/spacing from CSS custom properties mapped to the
  existing dashboard palette (and the mezmur theme constraint — classes only,
  no inline styles in themed shells — is preserved).

### 4.2 Navigation model (fixes D1, D4, D8; one workflow everywhere)

- **Desktop (≥769px)**: bell in the topbar (or sidebar header where the
  dashboard has no topbar — edu/material get the bell moved out of the
  sidebar-bottom slot into their header row). Sidebar gains a Communication
  button that opens the in-dashboard section.
- **Mobile (≤768px)**: the bell opens a **compact sheet (≤60vh, scrimmed,
  safe-area aware, closes on scrim tap/outside/Escape)** — recent
  notifications only + "Open Messages / Announcements" links that activate
  the section. The **bottom nav gains the Communication destination**; the
  section renders inside the dashboard shell — never a full-screen takeover.
- The section partial is **one shared include** consumed by all dashboards —
  identical UI/UX by construction, not by copy-paste.

### 4.3 State system (fixes D6, D7, D11)

One state machine per surface, tokens for every state:

| State | Presentation |
|---|---|
| Loading | Skeleton shimmer (never spinners-as-text) |
| Empty | Icon + one-line explanation + primary action |
| Error | Icon + explanation + **working Retry button** (re-runs the failed request) |
| Success | Optimistic UI + confirm (e.g. item dims/unread-dot fades; toast for sends) |
| Busy | Buttons show in-flight state and are disabled — no double submits |

Toasts use the Design OS elevation (`--z-toast`), sit above the bottom nav,
and respect safe-area insets.

### 4.4 Performance & shared-hosting constraints (fixes P1, P2)

- Static assets with far-future cache headers (via `.htaccess` — shared-host
  compatible).
- Poll client: **ETag/If-None-Match** (server support lands in Phase 5),
  visibility pause, focus refresh, exponential backoff (cap ×8), request
  de-dup.
- All queries indexed and paginated (cursor-based) — audited for
  hundreds-of-thousands scale in Phase 5.

## 5. Phase Plan (one phase per work unit; each ships green)

| Phase | Scope | Acceptance |
|---|---|---|
| **1 — Foundation & Bell (current)** | Static asset architecture (`comm.css`, `comm.js`); API client (dedup, backoff, visibility, ETag-ready); state primitives; **bell popover rewrite**: JS-measured `position:fixed` anchoring (clamped to viewport — kills the off-screen/clip class of bugs D1/D2), token z-indexes, mobile compact scrimmed sheet (≤60vh) instead of 82vh takeover, working Retry, busy states, notification-only role with links to the dedicated pages (section integration in Phase 2); bell placement fixes on edu/material (header row, not sidebar-bottom). | All 12 dashboards render the new bell; harness fatal-free; matrix green; new pins: no inline asset blob, tokens used, retry handler exists, no 82vh rule, fixed-position anchoring. |
| **2 — Section integration** | `comm_section.php` shared partial (Inbox + Messages in one section shell); sidebar buttons on all dashboards; bottom-nav entry per role; in-dashboard section switching (reusing each dashboard's `data-sec` pattern); `notifications.php`/`messages.php` become thin shells rendering the same partial (deep links preserved). | Identical section on all dashboards; zero full-screen takeovers; pages hold no logic; matrix green. |
| **3 — Messages, Telegram-style** | Contact list from `partners` (search, role grouping, avatars/initials, last message, unread badges); chat view (bubbles, day separators, receipts, inverted list); **arena-style composer** (`field-sizing:content` + JS fallback, no visible scrollbar, Enter-to-send); optimistic send with retry; live thread polling via the shared client; micro-animations (respecting `prefers-reduced-motion`). | The D9/D10/D11 defects are gone; keyboard + touch both first-class; WCAG touch targets. |
| **4 — Inbox feed UX** | Unified feed in-section: tabs, filters, mark-read (single + all) with optimistic UI, announcement composer (professional multi-step form replacing the modal), skeletons everywhere, action routing (task buttons work with feedback). | Every interaction has visible state; no dead controls. |
| **5 — Performance & scale** | Server-side ETag/304 on `summary` (+ slim payload); `.htaccess` cache headers; DB index + query-plan audit for 100k+ members; cursor pagination on all feeds/threads. | Verified budget: poll cost ~304 bytes when idle; EXPLAIN-approved queries. |
| **6 — Security & final verification** | AuthZ/CSRF/XSS audit of every new surface; rate-limit checks; full harness matrix (all roles, both breakpoints); full pytest matrix; docs; ship report. | Five mandatory rules each explicitly confirmed (§6). |

## 6. Mandatory Rules — how every phase confirms them

1. **Professional front/back separation** — pages/components hold **zero**
   business logic; all logic in `NotificationCenterService` + `comm.js`;
   all styling in `comm.css` tokens. Verified by pins (`assertNotIn` on
   `$conn->prepare` in pages; token-only CSS review).
2. **Scale to hundreds of thousands** — every query indexed + paginated;
   poll traffic minimized (ETag); no N+1; Phase 5 audit is the explicit gate.
3. **100% security** — CSRF on every write (component-supplied token), XSS
   via `esc()`-only rendering, authZ via the service permission matrix (never
   client), rate limiting via the existing API guards; Phase 6 audit signs
   off each surface.
4. **No breakage** — public PHP API (`renderNotificationCenter()`, shims,
   `nc-root` contract) preserved; pinned audit tests keep passing; every
   phase runs the full matrix + the offline runtime harness before shipping.
5. **Maintain/extend/integrate** — one CSS file, one JS runtime, one partial
   per surface; adding a surface = include the partial + one nav entry.

## 7. Non-goals (unchanged from P72 §1)

Email/SMS/push delivery; real-time WebSockets/SSE (shared hosting — polling
with conditional requests instead); message media attachments; typing
indicators requiring server push (Phase 3 may add a polling-based indicator
only if it stays cheap).


## §9 Phase 2.1 — Component isolation hotfix (mezmur & finance white pages)

**Incident.** After Phase 1/2, the Mezmur and Finance frontend shells rendered
as unstyled text (white page): the output truncated at the sidebar's
`school-bell-slot`, so no `<head>` (theme.css), no main content, no logout,
and no bell. The notification center component called the host's `e()` helper
unguarded, but frontend shells buffer their body BEFORE requiring
`layouts/base.php` (which loads `config.php`, where `e()` lives) →
`Call to undefined function e()` → fatal mid-page. Admin pages always load
config first, which is why only the two frontend shells broke. Even without
the fatal, the CSRF token would have been empty on those pages (silent
write-failures — the exact P72 bug class returning).

**Fix — three industry-standard layers (researched, not invented):**

1. **Self-bootstrapping component** (design-system doctrine: a shared
   component owns its dependencies): `ncEnsureBootstrapped()` requires
   `config.php` when `ROOT_PATH` is undefined — the identical pattern
   `layouts/base.php` itself uses. Guarantees `generateCsrfToken()`,
   session and constants regardless of host include order.
2. **Error boundary** (the React error-boundary pattern used at scale by
   Meta/Google): both public render functions catch `Throwable`, report via
   `error_log` (feeds /monitor), and degrade to rendering nothing. A broken
   notification UI can never again blank a whole dashboard. Verified by
   sabotage test: a throwing component now costs only the bell.
3. **Zero host-helper calls**: escaping uses the private `ncEsc()`
   (`htmlspecialchars`) instead of the host's `e()`; CSRF keeps its
   `function_exists` guard and now receives a REAL token on every page.

**Regression pins** (tests/security/test_notification_center.py §10):
self-bootstrap block, two `catch (Throwable)` boundaries, `ncEsc` usage
(`e($ncCsrf)` must never return), and the include-order fact
(bell before base.php) that makes the contract necessary.

**Verification protocol fix.** The Phase-1 smoke check read only the last
output line (`===DONE`), which masked the `===HARNESS-UNCAUGHT` marker
printed just before it. The harness protocol now greps the FULL output for
any failure marker on every page (31 runtime checks clean: 10 dashboards,
5 pages, 13 roles, login POST, 2 frontend pages).


## §9 Phase 2.2 — Dead bell + Communication buttons (JS strict-mode scoping incident)

**Incident.** After Phase 2 shipped, every bell button and every
`data-comm-open` control on every page was dead — clicks did nothing.

**Root cause.** `comm.js` runs under `'use strict'`. A bundled helper
declared functions *inside if/else blocks* and referenced them from
outside those blocks. In strict mode, function declarations are
block-scoped (not hoisted to function scope) — the outer references
threw `ReferenceError` at load time, killing the entire runtime.

**Why both gates missed it.** `node --check` proves syntax only; the
PHP harness proves the HTML renders only. Neither *executes* the JS.
The bug class (load-time scoping/evaluation errors) is invisible to
both — proven twice now.

**Fix + new standing gate.** Restructured to properly hoisted
declarations, and added `tests/js/comm_runtime_test.js`: it executes
the REAL `comm.js` in a Node `vm` with a DOM shim and simulates the
exact interactions that were dead (bell open/close, section open via
`data-comm-open`, view switching, Escape, sheet open/close, page-mode
boot). **Standing rule: every JS change ships through this runtime
gate** — green exit + `PASS` line + zero handler errors.

## §9 Phase 3 — Telegram-grade messaging (D9 / D10 / D11) — SHIPPED

**Scope delivered** (per §5 row 3; ETag/304 explicitly stays Phase 5):

- **D9 composer** — auto-grows via native `field-sizing: content`
  (`@supports` block, compositor-only, zero JS on the typing path)
  with a `scrollHeight` JS fallback for legacy engines; growth capped
  at 140px; residual scrollbar hidden in both engines; Enter sends,
  Shift+Enter newlines, IME-safe (`!e.isComposing`).
- **D10 contact-list picker** — the new-conversation recipients picker
  is now a real contact list: search box, role-grouped rows, initials
  avatars, whole-row tap, keyboard operable (Enter/Space), selection
  counter, no-match empty state; 48px touch rows (WCAG). The
  announcement-targets picker keeps its Phase-2 checkbox list.
- **D11 receipts + optimistic send** — one additive guarded column
  (`sql/043_message_read_receipts.sql`: `message_thread_participants.
  last_read_message_id`, information_schema-guarded like 040, manual
  idempotent convention). A message is ✓✓ Seen when its id ≤
  MAX(other participants' watermarks), else ✓ Sent — no per-message
  rows. Sends are optimistic: instant pending bubble, success confirmed
  by a background refresh, failure keeps the bubble with an inline
  Retry that refills the composer and resends. The composer never
  blocks. `markThreadRead` advances the reader's watermark.
- **mezmur + finance integration** — both frontend departments render
  the shared section in place (sidebar Communication entry +
  `comm_section.php` into the page buffer before `base.php`); their
  bottom-nav buttons close the section (covered by the Phase-3
  close-on-nav + `--nav-total` handling).
- **Micro-animations** — bubble entry keyframe, guarded by the global
  `prefers-reduced-motion` block (extended to `.nc-msg`).

**Verification.**

- Runtime gate extended 19 → **43 checks** (picker, receipts, composer,
  optimistic-send suites). The DOM shim gained a mini HTML parser so
  the runtime *queries and clicks its own rendered output* (thread
  rows, contact rows, pending bubbles, Retry buttons) — plus POST-body
  action capture and a `failNextSend` hook proving the failure→Retry
  path end-to-end.
- PHP harness (rebuilt from the working tree): mezmur + finance render
  the section + Communication nav + picker markup, `===DONE`, zero
  fatals; dashboards, thin shells and the API regression-clean.
- pytest: **55 green** in the comm suites (new `CommPhase3Tests` class:
  D9/D10/D11 structural pins, migration guard, service watermark
  SELECT/UPDATE, API passthrough, frontend integration, reduced-motion
  guard, and a pin that the runtime gate keeps its Phase-3 suites).
- Full matrix: **byte-identical failing-ID diff** vs `9e0c1f9` — 40
  pre-existing environment failures, unchanged; zero regressions.
