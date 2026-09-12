# P68 — Mezmur Dashboard Native Mobile/Tablet Responsiveness

**Scope:** presentation only. ONE file changed (`themes/components.css`, one
appended section) + tests. Zero changes to `mezmur_dept.php`, `mezmur.js`,
`mezmur_player.js`, `core.js`, `theme.css`, backend, SQL, or the mobile app.
User decisions: tablet keeps the sidebar (iPad pattern); phone dialogs become
iOS bottom sheets.

## 1. Why one appended CSS section is the whole change

Every rule is gated `body.page-mezmur` (the shell sets that class in
`mezmur_dept.php` line 23) or uses mezmur-only `.mz-*` selectors.
`components.css` is shared with the finance dashboard (the only other live
`base.php` consumer) — `test_p68_rules_are_mezmur_scoped` mechanically proves
every P68 selector is gated, so finance/login cannot shift by one pixel.

## 2. Verified defects fixed

| # | Symptom | Fix |
|---|---------|-----|
| R1 | Page zooms on input focus (iOS/Android) | `font-size: 16px` inputs ≤1200px (0.85rem was below the 16px zoom threshold) |
| R2 | Buttons too small to tap | ≥40px `.btn-sm` ≤1200px; 42–44px action/tab/pager buttons ≤768px |
| R3 | 5–8 column tables crawl sideways | column-priority card rows (§3) |
| R4 | Filter rows wrap into chaos | full-width search + 2-column filter grid; action rows → 2-column button grid |
| R5 | Dialog opened UNDER the player dock | `.school-modal` z 100 → `var(--z-overlay)` 1200 (scoped; finance has no dock) |
| R6 | Centered desktop dialogs on phone | bottom sheets: slide-up, grabber, top-only radius, `min(86dvh, 86vh)`, safe-area padding |
| R7 | Twin ~200px tables 641–1000px | `.grid-2` stacks ≤1000px |
| R8 | Hover states stick after tap | `@media (hover: none)` neutralizes row/card hover |
| R9 | Sub-tabs felt desktop-y | segmented control, consistent with the P67 stage tabs |
| R10 | Toast sat on the bottom nav / under the dock | full-width toast above nav; `body.page-mezmur.mz-playing` lifts it above the dock |

## 3. Table → card-row contract (≤768px)

Rows are JS-generated (`innerHTML`) — data-label card markup would need JS
edits (frozen by constraint), so each list is transformed by a CSS
column-priority grid. Thead hidden; kept cells re-flow via `order` /
`grid-column`; the row itself carries the divider.

| List (tbody id) | Phone layout | Hidden on phone |
|---|---|---|
| `#mzTbody` library | `[art │ title+snippet]` / `[actions wrap]` | category, updated |
| `#mzSubTbody` submissions | `[section │ status]` / `[date │ result]` / `[actions]` | taker, members, updated |
| `#mzAnTbody` ranking | `[member │ rate-bar]` / `[rank · attended]` | section, absent, last |
| `#mzTakerTbody` | `[name │ status]` / `[username │ actions]` | type, created |
| `#mzMgrCatRows` catalog | `[thumb │ name │ actions]` / `[hymns │ sort]` | — |
| `#mzMgrZemRows` singers | `[thumb │ name │ actions]` / `[hymns]` | — |
| `#mzOvQueue` overview | `[section │ status]` / `[date │ marked]` | updated |

- `td[colspan]` (skeleton / empty / error / inline-edit rows) is always
  full-width: `grid-column: 1 / -1 !important` — the single documented
  `!important`, pinned by test.
- The 3-column overview tables stay real tables (they fit).
- Analytics column **sorting is unavailable on phone** (its sortable thead is
  hidden in card mode). Filters + pagination remain; tablet/desktop keep
  sorting. Documented trade-off, not a regression elsewhere.
- `tr.mz-is-playing` keeps its green tint on the row itself in card mode.

## 4. Decision records (Design OS §36)

1. **CSS column-priority over JS card markup** — the constraint freezes JS;
   hiding is reversible, data stays one tap away in each row's dialog.
2. **16px inputs at ≤1200px, not just ≤768px** — iPad Safari auto-zooms too;
   tablets are touch devices.
3. **Bottom sheets via overriding `.school-modal` alignment** — the open/close
   mechanics (`.show` class, Escape handler, backdrop click) are JS-owned and
   untouched; `display:none→flex` re-triggers the slide-up animation natively.
4. **`dvh` with `vh` fallback** — no new browser floor (`:has()` was already
   required by the app-shell).
5. **Dock blur 16→12px on phone** — full-width fixed backdrop-filter is the
   most expensive effect in the file; 12px keeps the glass feel at lower cost.
6. **No `content-visibility`/virtualization added** — lists are server-
   paginated; the DOM is small (§20: no imaginary optimizations).
7. **Toast gets `body.page-mezmur.mz-playing` rule** — equal-specificity tie
   with P67's `.school-toast` offset would otherwise resolve by file order.

## 5. Test contract

`tests/security/test_mezmur_uiux.py`: 27 → **29 tests**.
- `test_responsive_contract_p68`: 16px inputs, modal z-fix, sheet animation +
  radius, every card-row transform, colspan safety, hover-none guard,
  reduced-motion, playing-state toast.
- `test_p68_rules_are_mezmur_scoped`: extracts the P68 section, strips
  comments, and asserts **every selector** contains `page-mezmur`/`.mz-*`
  (finance provably untouched).

## 6. Deployment & verification

No server steps — one CSS file, cache-busted by `filemtime` in base.php.
1. Deploy `themes/components.css` + `tests/security/test_mezmur_uiux.py`.
2. `python3 -m unittest discover -s tests/security` → uiux 29/29.
3. Phone (≤768): tables read as card lists; Add Hymn opens as a bottom sheet
   (slide-up + grabber); no input zoom; Drafts/Submitted/Insights segmented;
   toast never touches the nav.
4. Tablet (769–1200): sidebar intact, comfortable targets, no zoom.
5. Desktop (≥1201): pixel-identical to before (no rule applies).
6. Finance dashboard + login: pixel-identical (scoping test guarantees it).
