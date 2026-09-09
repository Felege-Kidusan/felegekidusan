# P67 — Web Hymn Player & Lyrics Stage Redesign

**Scope:** presentation only (CSS + player markup wrappers). Zero changes to
`frontend/js/mezmur_player.js`, `frontend/js/mezmur.js`, any PHP logic, API,
SQL or the mobile app. Governed by the Universal Change Protocol + AI Design
OS constitution (§29 understand-before-redesign, §30 never remove
functionality, §2-L2 extend the existing design system, §36 decision records).

## 1. Defects fixed (all verified in source before editing)

| # | Defect | Root cause |
|---|--------|-----------|
| D1 | Dock covered the sidebar bottom (z 30 vs 950) | `.mz-player` was `left:0; right:0` — the whole viewport |
| D2 | Desktop dock floated ~64px above the viewport bottom | `body:has(.school-bottom-nav) .mz-player { bottom: var(--nav-total) }` ran at every width: the nav markup exists even when `display:none` on desktop |
| D3 | Lyrics viewer was a 420px right drawer | user requirement: full content-region stage |
| D4 | Phones had **no touch-reachable** lyrics/queue/close | `.mz-player-right { display:none }` (≤768px) hid the whole group incl. `#mzPClose` |
| D5 | Art fallback letter (`#fff` on transparent) invisible in light mode | no `background-color` behind the JS-painted image |
| D6 | `.mz-np` mobile `bottom:138px` hardcoded; phantom `--nav-total` on desktop | pre-token-era geometry |
| D7 | Stale test pins: dialog count 9 (real 10), `z-index: 45` (removed by z-token migration) | pins drifted before this task — both FAILed on upstream main |

## 2. Geometry contract (single source of truth: `--mz-dock-h`)

```
Desktop (>768px)                        Mobile (≤768px app-shell)
┌────────┬───────────────────────┐      ┌───────────────────────┐
│        │ top: 0                │      │ top: 0        stage   │
│sidebar │ left: --school-       │      │ left: 0; right: 0     │
│ 260px  │   sidebar-width       │      │ bottom: nav-total     │
│        │ .mz-np  bottom: dock-h│      │         + dock-h      │
│        ├───────────────────────┤      ├───────────────────────┤
│        │ dock: left: sidebar-w │      │ dock: bottom:         │
│        │       bottom: 0, z 950│      │   var(--nav-total)    │
└────────┴───────────────────────┘      ├───────────────────────┤
                                         │ bottom nav (in-flow   │
  content clearance: dock-h + 2rem       │ flex footer, z 900)   │
                                         └───────────────────────┘
```

- Both chrome layers use `left: var(--school-sidebar-width, 260px)` on
  desktop → they can never cover the sidebar (both themes define 260px).
- The nav-aware dock offset lives **inside** the ≤768px media query (fixes D2).
- `body.mz-playing` clearances: desktop `calc(dock-h + 2rem)` (phantom
  `--nav-total` removed); mobile `calc(dock-h + 1.25rem) !important` (the
  app-shell sets content padding with `!important`; file order alone was the
  old, fragile guarantee).
- Mobile `--mz-dock-h: 116px` matches the real two-row dock.

## 3. Stage design (`#mzNowPlaying`)

Wrappers added in `mezmur_dept.php` — `.mz-np-stage`, `.mz-np-body`,
`.mz-np-now`, `.mz-np-meta`, `.mz-np-view` — are pure CSS hooks. Every JS
bound id/class is unchanged, so `mezmur_player.js` binds exactly as before
(verified by the id-presence pin in `test_player_geometry_p67`).

- **≥1101px:** two columns — hero (art ≤340px, title, segmented tabs) +
  lyrics/queue stage with a centered 44rem measure.
- **769–1100px:** hero becomes a header row (150px art + meta + tabs), stage
  takes the rest.
- **≤768px:** hero column again, art `min(68vw, 300px)`, stage above the dock.
- Ambient wash: token-driven radial gradients (`.mz-np::before`), no
  hardcoded colors → dark/light both correct.
- Lyrics progression (was inverted): upcoming `--school-text-muted`, sung
  `.past` dimmed, `.active` bright 800 + accent bar. Amharic rhythm: 1.22rem
  / 1.9 line-height (1.1rem mobile).
- Queue rows: active = success tint + inset bar; tabular numerals.
- Empty states carry a `♪` glyph via CSS `::before` (JS text untouched).

## 4. Decision records (Design OS §36)

1. **CSS-only geometry** (`left: sidebar-width`) instead of moving the dock
   into `.school-main`: keeps HTML churn minimal, no stacking-context risk,
   no test-contract change beyond pins. Rejected in-flow dock — a persistent
   player must survive content scroll.
2. **`accent-color` sliders kept** (no custom webkit tracks): custom tracks
   lose the native progress fill, which needs JS to restore — JS is frozen.
3. **Mobile hides only rate/mute/volume**, never the group: restores touch
   access to lyrics/queue/close (§30 — add reachability, remove nothing).
4. **Glass dock** (`color-mix` + `backdrop-filter`) layered over a solid
   token fallback — progressive enhancement; `:has()` was already required,
   so the browser floor is unchanged.
5. **Section headers use `--school-accent`** instead of `--school-primary`
   (near-invisible dark red on the dark theme).
6. **Stale pins updated, not deleted** (D7): 9→10 dialogs (mzPacketModal is
   the 10th), `z-index: 45` → `z-index: var(--z-dock)`, plus new P67 pins.
7. **Known limitation:** `scrollIntoView({behavior:'smooth'})` is JS-driven;
   `prefers-reduced-motion` cannot suppress it without a JS edit (out of
   scope). CSS transitions are disabled under reduced-motion.

## 5. Test contract

`tests/security/test_mezmur_uiux.py`: 25 → 27 tests.
- `test_player_geometry_p67` (new): sidebar constraint, no global `:has()`
  dock rule, stage hooks present, **all 34 player ids exactly once**.
- `test_player_mobile_keeps_essential_controls` (new): right group never
  fully hidden; only `#mzPRate, #mzPMute, #mzPVol` may hide; lyrics
  progression classes pinned.
- 3 drifted pins re-locked (§1 D7).

## 6. Deployment & verification

No server steps — CSS/markup only; `base.php` cache-busts via `filemtime`.
1. Deploy files: `themes/components.css`, `frontend/pages/mezmur_dept.php`,
   `tests/security/test_mezmur_uiux.py`.
2. `python3 -m unittest discover -s tests/security` — uiux suite must be 27/27.
3. Visual pass (desktop >1100px, 769–1100px, ≤768px): dock flush to the
   viewport bottom right of the sidebar; stage fills the content region;
   lyrics karaoke emphasis; queue tab; light mode art fallback.
4. Regression gate: full `tests/{audit,e2e,security,smoke}` failure set must
   equal upstream's minus the 3 fixed uiux pins (42 of 45 baseline entries).
