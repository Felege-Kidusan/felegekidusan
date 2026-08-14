<?php
/**
 * ============================================================
 * FULL SYSTEM THEME — Covers ALL dashboards, ALL elements
 * ============================================================
 * Overrides: Tailwind classes, CSS variables, inline styles
 * Covers: info-dept, edu_dept, finance, material, teacher,
 *         attendance_taker, school_admin, super-admin, login
 * ============================================================
 */

$_st  = defined('THEME_SIDEBAR_TOP')    ? THEME_SIDEBAR_TOP    : '#064e3b';
$_sm  = defined('THEME_SIDEBAR_MID')    ? THEME_SIDEBAR_MID    : '#047857';
$_sb  = defined('THEME_SIDEBAR_BOTTOM') ? THEME_SIDEBAR_BOTTOM : '#16a34a';
$_tp  = defined('THEME_PRIMARY')        ? THEME_PRIMARY        : '#047857';
$_tpl = defined('THEME_PRIMARY_LIGHT')  ? THEME_PRIMARY_LIGHT  : '#16a34a';
$_tpd = defined('THEME_PRIMARY_DARK')   ? THEME_PRIMARY_DARK   : '#064e3b';
$_ta  = defined('THEME_ACCENT')         ? THEME_ACCENT         : '#06b6d4';
$_ta2 = defined('THEME_ACCENT_2')       ? THEME_ACCENT_2       : '#8b5cf6';

function _hRgb($h){$h=ltrim($h,'#');return[hexdec(substr($h,0,2)),hexdec(substr($h,2,2)),hexdec(substr($h,4,2))];}
function _hLight($h,$p=0.92){$r=_hRgb($h);return sprintf('#%02x%02x%02x',(int)($r[0]+($p*(255-$r[0]))),(int)($r[1]+($p*(255-$r[1]))),(int)($r[2]+($p*(255-$r[2]))));}
$tpR=_hRgb($_tp);$tplR=_hRgb($_tpl);$taR=_hRgb($_ta);$ta2R=_hRgb($_ta2);
$tpBg=_hLight($_tp);$tpBg2=_hLight($_tp,0.85);
?>
<style id="school-theme">
/* ═══════════════════════════════════════════════════════════
   SCHOOL THEME — UNIVERSAL OVERRIDE (all dashboards)
   ═══════════════════════════════════════════════════════════ */

/* ──────────────────────────────────────
   1. CSS VARIABLE OVERRIDES
   Used by: school_admin, finance, material
   ────────────────────────────────────── */
:root {
    --ac: <?= $_ta ?> !important;
    --ac2: <?= $_ta2 ?> !important;
}

/* ──────────────────────────────────────
   2. SIDEBAR — ALL DASHBOARDS
   ────────────────────────────────────── */

/* Themed dashboards (class-based) */
.school-sidebar,
.sidebar-gradient,
aside.school-sidebar {
    background: linear-gradient(180deg, <?= $_st ?> 0%, <?= $_sm ?> 40%, <?= $_sb ?> 100%) !important;
}

/* ──────────────────────────────────────
   3. BRAND LOGO & AVATAR CIRCLES
   ────────────────────────────────────── */
.brand-logo, .bl, .ua, .user-avatar,
div[style*="background:linear-gradient(135deg,#7c3aed,#6366f1)"][style*="border-radius:50%"],
div[style*="background:linear-gradient(135deg,#7c3aed,#6366f1)"][style*="border-radius:12px"] {
    background: linear-gradient(135deg, <?= $_ta ?>, <?= $_ta2 ?>) !important;
    box-shadow: 0 8px 20px rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.35) !important;
}

/* ──────────────────────────────────────
   4. ACTIVE NAV ITEMS — ALL STYLES
   ────────────────────────────────────── */

/* school_admin style */
.np.active {
    background: linear-gradient(135deg, rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.12), rgba(<?= $ta2R[0] ?>,<?= $ta2R[1] ?>,<?= $ta2R[2] ?>,0.06)) !important;
    color: <?= $_ta ?> !important;
    border-color: rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.15) !important;
}
.np.active::before {
    background: linear-gradient(180deg, <?= $_ta ?>, <?= $_ta2 ?>) !important;
}

/* finance/material active nav (overrides hardcoded rgba) */
.np.active[style], .np.active {
    background: rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.15) !important;
    color: <?= $_ta ?> !important;
}

/* edu_dept .nl style */
.nl.act { background: rgba(255,255,255,.15) !important; }

/* info-dept nav pills */
.nav-pill-active, a[data-section].nav-pill-active,
a[data-section].bg-white\/20 {
    background: rgba(255,255,255,.2) !important;
}

/* super-admin nav */
.sb .nav-link.active {
    background: rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.1) !important;
    color: <?= $_ta ?> !important;
    border-left-color: <?= $_ta ?> !important;
}
.sb .nav-link.active i { color: <?= $_ta ?> !important; }

/* ──────────────────────────────────────
   5. STAT CARDS — EDU_DEPT (inline style override)
   ────────────────────────────────────── */

/* First stat card = primary brand color */
.sc[style*="background:linear-gradient(135deg,#7c3aed,#6366f1)"] {
    background: linear-gradient(135deg, <?= $_tp ?>, <?= $_tpl ?>) !important;
}

/* Second stat card = accent gradient */
.sc[style*="background:linear-gradient(135deg,#059669,#10b981)"] {
    background: linear-gradient(135deg, <?= $_ta ?>, <?= $_ta2 ?>) !important;
}

/* Third stat card = accent reversed */
.sc[style*="background:linear-gradient(135deg,#0ea5e9,#3b82f6)"] {
    background: linear-gradient(135deg, <?= $_ta2 ?>, <?= $_ta ?>) !important;
}

/* ──────────────────────────────────────
   6. MODAL HEADERS — EDU_DEPT (inline style override)
   ────────────────────────────────────── */
div[style*="background:linear-gradient(135deg,#7c3aed,#6366f1)"][style*="border-radius:20px"] {
    background: linear-gradient(135deg, <?= $_tp ?>, <?= $_tpl ?>) !important;
}

/* ──────────────────────────────────────
   7. BUTTONS — ALL DASHBOARDS
   ────────────────────────────────────── */

/* Primary buttons (class-based) */
.bp, .btn-p {
    background: linear-gradient(135deg, <?= $_ta ?>, <?= $_ta2 ?>) !important;
    box-shadow: 0 4px 14px rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.2) !important;
}

/* edu_dept action buttons (inline purple) */
button[style*="background:linear-gradient(135deg,#7c3aed"],
button[style*="background:#7c3aed"],
button[style*="background:linear-gradient(135deg,#059669"] {
    background: linear-gradient(135deg, <?= $_tp ?>, <?= $_tpl ?>) !important;
}

/* edu_dept green action buttons */
button[style*="background:#10b981"],
button[style*="background:#059669"],
a[style*="background:#10b981"],
a[style*="background:#059669"] {
    background: <?= $_tp ?> !important;
}

/* ──────────────────────────────────────
   8. MOBILE ELEMENTS
   ────────────────────────────────────── */
.wbws-bnav::before, .bn::before {
    background: linear-gradient(90deg, <?= $_tp ?>, <?= $_ta ?>) !important;
}
.wbws-bnav-btn.active, .bn button.active { color: <?= $_ta ?> !important; }
nav.fixed.bottom-0 {
    background: linear-gradient(180deg, <?= $_tp ?>, <?= $_tpd ?>) !important;
}
.mobile-sticky-header {
    background: linear-gradient(180deg, <?= $_tp ?>, <?= $_tpd ?>) !important;
}

/* ──────────────────────────────────────
   9. TAILWIND COLOR CLASS OVERRIDES
   Used by: info-dept (emerald), others
   ────────────────────────────────────── */

/* Backgrounds */
.bg-emerald-50,.bg-emerald-100,.bg-green-50,.bg-green-100,[class*="bg-emerald-50"],[class*="bg-emerald-100"]
{ background-color: <?= $tpBg ?> !important; }
.bg-emerald-200,.bg-emerald-300 { background-color: <?= $tpBg2 ?> !important; }
.bg-emerald-400,.bg-emerald-500,.bg-emerald-600,.bg-emerald-700,.bg-emerald-800
{ background-color: <?= $_tp ?> !important; }

/* Text */
.text-emerald-500,.text-emerald-600,.text-emerald-700,.text-emerald-800,.text-green-600,.text-green-700
{ color: <?= $_tp ?> !important; }
.text-emerald-100,.text-emerald-200 { color: <?= $tpBg ?> !important; }

/* Borders */
.border-emerald-100,.border-emerald-200,.border-emerald-300 { border-color: <?= $tpBg2 ?> !important; }
.border-emerald-500,.border-emerald-600 { border-color: <?= $_tp ?> !important; }

/* Gradients */
.from-emerald-400,.from-emerald-500,.from-emerald-600 { --tw-gradient-from: <?= $_tp ?> !important; }
.via-emerald-400,.via-emerald-500 { --tw-gradient-via: <?= $_tpl ?> !important; }
.to-emerald-400,.to-emerald-500,.to-emerald-600,.to-emerald-700 { --tw-gradient-to: <?= $_tpl ?> !important; }
.to-teal-500,.to-teal-600,.from-teal-400,.from-teal-500,.from-teal-600 { --tw-gradient-from: <?= $_tp ?> !important; }

/* Accent gradients (blue/indigo → school accent) */
.from-blue-600,.from-blue-500 { --tw-gradient-from: <?= $_ta ?> !important; }
.to-indigo-600,.to-indigo-500 { --tw-gradient-to: <?= $_ta2 ?> !important; }

/* Hover states */
.hover\:bg-emerald-50:hover,.hover\:bg-emerald-100:hover { background-color: <?= $tpBg ?> !important; }
.hover\:bg-emerald-600:hover,.hover\:bg-emerald-700:hover { background-color: <?= $_tpd ?> !important; }
.hover\:from-emerald-600:hover { --tw-gradient-from: <?= $_tpd ?> !important; }
.hover\:to-emerald-700:hover { --tw-gradient-to: <?= $_tpd ?> !important; }
.hover\:text-emerald-900:hover { color: <?= $_tpd ?> !important; }

/* Focus rings */
.focus\:ring-emerald-200:focus,.ring-emerald-200 { --tw-ring-color: rgba(<?= $tpR[0] ?>,<?= $tpR[1] ?>,<?= $tpR[2] ?>,0.3) !important; }
.focus\:border-emerald-400:focus,.focus\:border-green-400:focus { border-color: <?= $_tpl ?> !important; }

/* ──────────────────────────────────────
   10. TEACHER & ATTENDANCE SPECIFIC
   ────────────────────────────────────── */

/* Teacher buttons (blue) */
button.bg-sky-600, .bg-sky-600, .bg-sky-500 { background-color: <?= $_tp ?> !important; }
.hover\:bg-sky-700:hover { background-color: <?= $_tpd ?> !important; }
.text-sky-600, .text-sky-700 { color: <?= $_tp ?> !important; }
.bg-sky-50, .bg-sky-100 { background-color: <?= $tpBg ?> !important; }
.border-sky-200, .border-sky-300 { border-color: <?= $tpBg2 ?> !important; }

/* Attendance buttons (orange → theme) */
button.bg-orange-500, .bg-orange-500 { background-color: <?= $_tp ?> !important; }
.hover\:bg-orange-600:hover { background-color: <?= $_tpd ?> !important; }
.text-orange-500, .text-orange-600 { color: <?= $_tp ?> !important; }
.border-orange-200, .border-orange-300 { border-color: <?= $tpBg2 ?> !important; }

/* ──────────────────────────────────────
   11. TOAST / ALERTS
   ────────────────────────────────────── */
.toast-enter, #memberSuccessToast > div > div {
    background-color: <?= $_tp ?> !important;
}

/* ──────────────────────────────────────
   12. FORM INPUTS — ALL DASHBOARDS
   ────────────────────────────────────── */
input:focus, select:focus, textarea:focus {
    border-color: <?= $_ta ?> !important;
    box-shadow: 0 0 0 3px rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.1) !important;
}
input[type="checkbox"] { accent-color: <?= $_tp ?> !important; }

/* edu_dept .inp focus */
.inp:focus { border-color: <?= $_ta ?> !important; }

/* ──────────────────────────────────────
   13. TABLES
   ────────────────────────────────────── */
tr:hover td {
    background: rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.02) !important;
}

/* ──────────────────────────────────────
   14. LOGIN PAGE
   ────────────────────────────────────── */
.login-card::before { background: linear-gradient(135deg, <?= $_tp ?>, <?= $_tpl ?>) !important; }
.login-form .btn-primary { background: linear-gradient(135deg, <?= $_tp ?>, <?= $_tpl ?>) !important; }

/* ──────────────────────────────────────
   15. MISC UI ELEMENTS
   ────────────────────────────────────── */
::-webkit-scrollbar-thumb { background: <?= $_tp ?> !important; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: <?= $_tpl ?> !important; }
::selection { background: rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.3); color: #fff; }

/* Progress bars */
.progress-bar { background: linear-gradient(90deg, <?= $_tpl ?>, <?= $_ta ?>) !important; }

/* Badges */
.bg.bg-info, span[style*="background:var(--info)"] {
    background: rgba(<?= $taR[0] ?>,<?= $taR[1] ?>,<?= $taR[2] ?>,0.12) !important;
    color: <?= $_ta ?> !important;
}

/* Dept switch button */
.dept-switch {
    background: linear-gradient(135deg, <?= $_ta ?>, <?= $_ta2 ?>) !important;
}
</style>
