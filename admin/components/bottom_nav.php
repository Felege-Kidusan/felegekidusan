<?php
/**
 * ============================================================================
 * Reusable Bottom Navigation Bar — SINGLE SOURCE OF TRUTH
 * ============================================================================
 * Every admin dashboard renders its mobile tab bar through this component
 * instead of copy-pasting the markup. This is what makes the UI/UX trivial
 * to maintain: styling lives in admin/css/mobile.css (driven by the tokens in
 * themes/design-system.css) and the markup contract lives here. To change the
 * nav for ALL departments, edit ONE file.
 *
 * USAGE (from a dashboard, near the end of <body>):
 *   <?php
 *   $navItems = [
 *     [ // group 1 (a divider is auto-inserted between groups)
 *       ['icon'=>'fa-solid fa-gauge-high','label'=>'Home','attrs'=>'data-section="dashboard"','active'=>true],
 *       ['icon'=>'fa-solid fa-users','label'=>'Members','attrs'=>'data-section="members"'],
 *     ],
 *     [
 *       ['icon'=>'fa-solid fa-right-from-bracket','label'=>'Logout','href'=>'/admin/logout.php','exit'=>true],
 *     ],
 *   ];
 *   require __DIR__ . '/../components/bottom_nav.php';
 *   ?>
 *
 * Item keys:
 *   icon  : Font Awesome classes (trusted literal)
 *   label : visible text (escaped)
 *   href  : if set -> renders <a>, otherwise <button>  (escaped)
 *   active: bool -> adds .active
 *   exit  : bool -> adds .bnav-exit (visual "danger" styling)
 *   attrs : extra raw HTML attributes (e.g. data-section / data-sec / onclick).
 *           TRUSTED developer literal only — never user input.
 *
 * The DOM hooks (ids wbwsBottomNav / bnScroll / bnScrollL / bnScrollR and the
 * .wbws-bnav-btn class) are preserved so the existing nav JS keeps working.
 * ==========================================================================*/
if (!isset($navItems) || !is_array($navItems)) {
    return;
}

// Flatten once to decide whether scroll hints are needed (matches prior behavior:
// short navs (<=4) never scrolled, longer ones show the edge-fade hints).
$flat = [];
foreach ($navItems as $grp) {
    if (!is_array($grp)) { continue; }
    foreach ($grp as $it) { $flat[] = $it; }
}
$scrollable = count($flat) > 4;
$groupCount = count($navItems);
?>
<nav class="wbws-bnav" id="wbwsBottomNav">
<?php if ($scrollable): ?>
    <div class="wbws-bnav-scroll-hint-left" id="bnScrollL"></div>
    <div class="wbws-bnav-scroll-hint-right visible" id="bnScrollR"></div>
<?php endif; ?>
    <div class="wbws-bnav-inner" id="bnScroll">
<?php
$g = 0;
foreach ($navItems as $grp):
    if (!is_array($grp)) { continue; }
    $g++;
    foreach ($grp as $it):
        $icon   = $it['icon']  ?? '';
        $label  = $it['label'] ?? '';
        $active = !empty($it['active']);
        $exit   = !empty($it['exit']);
        $href   = $it['href']  ?? null;
        $attrs  = isset($it['attrs']) ? ' ' . trim((string) $it['attrs']) : '';
        $cls    = 'wbws-bnav-btn' . ($active ? ' active' : '') . ($exit ? ' bnav-exit' : '');
        $iconE  = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
        $labelE = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if ($href !== null):
            $hrefE = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
?>
        <a href="<?= $hrefE ?>" class="<?= $cls ?>"<?= $attrs ?>><i class="<?= $iconE ?>"></i><span><?= $labelE ?></span></a>
<?php else: ?>
        <button class="<?= $cls ?>"<?= $attrs ?>><i class="<?= $iconE ?>"></i><span><?= $labelE ?></span></button>
<?php
        endif;
    endforeach;
    if ($g < $groupCount):
?>
        <div class="wbws-bnav-divider"></div>
<?php
    endif;
endforeach;
?>
    </div>
</nav>
