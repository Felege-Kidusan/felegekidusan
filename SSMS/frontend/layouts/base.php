<?php
/**
 * ============================================================
 * Base Layout — Master template for ALL dashboard pages
 * ============================================================
 * 
 * Every dashboard page includes this. It provides:
 *   1. Theme CSS loading (from themes/[ACTIVE_THEME]/)
 *   2. window.APP JavaScript bootstrap (CSRF, user, school info)
 *   3. Core libraries (Font Awesome)
 *   4. Page script loading
 * 
 * Usage in a dashboard page:
 *   <?php
 *   $pageTitle = 'Super Admin';
 *   $pageScript = 'super_admin';  // loads frontend/js/super_admin.js
 *   $bodyClass = 'page-super-admin';
 *   ob_start();
 *   ?>
 *   <!-- Your HTML content here -->
 *   <?php
 *   $bodyContent = ob_get_clean();
 *   require __DIR__ . '/../layouts/base.php';
 *   ?>
 * ============================================================
 */

// Ensure config is loaded
if (!defined('ROOT_PATH')) {
    // Walk up from frontend/layouts/ to find config.php
    $cfgPaths = [
        __DIR__ . '/../../config.php',        // from frontend/layouts/
        $_SERVER['DOCUMENT_ROOT'] . '/config.php',
    ];
    foreach ($cfgPaths as $p) {
        if (file_exists($p)) { require_once $p; break; }
    }
}

// Ethiopian date picker for ALL frontend pages (finance etc.) — same engine
// the admin dashboards use. Converts every <input type="date"> to Ethiopian
// while still storing/sending the Gregorian value.
if (defined('ROOT_PATH') && is_file(ROOT_PATH . '/admin/backend/calendar_system.php')) {
    require_once ROOT_PATH . '/admin/backend/calendar_system.php';
}

// Auth check — all dashboard pages require login
if (function_exists('requireAuth')) {
    requireAuth();
}

// Optional per-page ROLE restriction. A page sets $requiredRoles (an array
// of allowed roles) BEFORE including this layout, and we enforce it here.
// This gives frontend pages the same role protection that the central admin
// guard (admin/access_control.php) gives to /admin/ pages — important because
// the central guard does not cover the /frontend/ folder.
if (!empty($requiredRoles) && is_array($requiredRoles)) {
    $__role = $_SESSION['admin_role'] ?? '';
    if (!in_array($__role, $requiredRoles, true)) {
        if (function_exists('_isAjaxRequest') && _isAjaxRequest()) {
            if (!headers_sent()) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['status' => 'error', 'message' => 'You do not have permission to view this page.']);
        } else {
            if (!headers_sent()) { header('Location: /admin/dashboard.php?error=access_denied'); }
        }
        exit;
    }
}

// Resolve theme
$_activeTheme = defined('ACTIVE_THEME') ? ACTIVE_THEME : 'wbss';
$_themeBase = '/themes/' . $_activeTheme;
$_themeCssPath = ROOT_PATH . $_themeBase . '/theme.css';

// Fallback if theme CSS doesn't exist
if (!file_exists($_themeCssPath)) {
    $_activeTheme = 'wbss';
    $_themeBase = '/themes/wbss';
}

// Page defaults
$pageTitle   = $pageTitle   ?? (defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'Dashboard');
$pageScript  = $pageScript  ?? null;
$bodyClass   = $bodyClass   ?? '';
$bodyContent = $bodyContent ?? '';
$extraHead   = $extraHead   ?? '';

// CSRF
$_csrfToken = function_exists('generateCsrfToken') ? generateCsrfToken() : '';

// User info
$_userId      = (int)($_SESSION['admin_id'] ?? 0);
$_userRole    = $_SESSION['admin_role'] ?? '';
$_userName    = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? '';
$_userUsername = $_SESSION['admin_username'] ?? '';

// Ethiopian date
$_todayFormatted = date('F j, Y');
if (file_exists(ROOT_PATH . '/admin/backend/ethiopian_date.php')) {
    require_once ROOT_PATH . '/admin/backend/ethiopian_date.php';
    if (function_exists('ethio_date_format')) {
        $_today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
        $_todayFormatted = ethio_date_format($_today, 'F j, Y');
    }
}

// Impersonation check
$_isImpersonating = !empty($_SESSION['original_admin_role']);
?>
<!DOCTYPE html>
<html lang="<?= defined('SITE_LANG') ? SITE_LANG : 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?= $_csrfToken ?>">
    <title><?= e($pageTitle) ?> — <?= e(defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School') ?></title>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'><?= defined('ADMIN_LOGO_ICON') ? ADMIN_LOGO_ICON : '⛪' ?></text></svg>">
    
    <!-- Theme CSS — this single file controls the entire visual design -->
    <link rel="stylesheet" href="<?= $_themeBase ?>/theme.css?v=<?= filemtime($_themeCssPath) ?>">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- window.APP — the ONLY PHP-to-JS bridge. Everything the frontend needs. -->
    <script>
    window.APP = {
        csrf: '<?= $_csrfToken ?>',
        role: '<?= e($_userRole) ?>',
        user: {
            id: <?= $_userId ?>,
            name: '<?= addslashes($_userName) ?>',
            username: '<?= addslashes($_userUsername) ?>',
            initials: '<?= strtoupper(mb_substr($_userName, 0, 1, 'UTF-8')) ?>'
        },
        school: {
            name: '<?= addslashes(defined('SCHOOL_NAME') ? SCHOOL_NAME : '') ?>',
            nameShort: '<?= addslashes(defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : '') ?>',
            nameAm: '<?= addslashes(defined('SCHOOL_NAME_AMHARIC') ? SCHOOL_NAME_AMHARIC : '') ?>',
            nameShortAm: '<?= addslashes(defined('SCHOOL_NAME_SHORT_AM') ? SCHOOL_NAME_SHORT_AM : '') ?>',
            tagline: '<?= addslashes(defined('SCHOOL_TAGLINE') ? SCHOOL_TAGLINE : '') ?>',
            memberPrefix: '<?= defined('MEMBER_CODE_PREFIX') ? MEMBER_CODE_PREFIX : 'WB' ?>',
            adminTitle: '<?= addslashes(defined('ADMIN_PANEL_TITLE') ? ADMIN_PANEL_TITLE : '') ?>',
            icon: '<?= defined('ADMIN_LOGO_ICON') ? ADMIN_LOGO_ICON : '⛪' ?>',
            logo: '<?= $_themeBase ?>/assets/logos/school_logo.png',
            seal: '<?= $_themeBase ?>/assets/seals/school_seal.png',
            features: {
                ai: <?= defined('FEATURE_AI_CHATBOT') && FEATURE_AI_CHATBOT ? 'true' : 'false' ?>,
                finance: <?= defined('FEATURE_FINANCE') && FEATURE_FINANCE ? 'true' : 'false' ?>,
                material: <?= defined('FEATURE_MATERIAL') && FEATURE_MATERIAL ? 'true' : 'false' ?>,
                groups: <?= defined('FEATURE_GROUPS') && FEATURE_GROUPS ? 'true' : 'false' ?>,
                idcards: <?= defined('FEATURE_ID_CARDS') && FEATURE_ID_CARDS ? 'true' : 'false' ?>,
                attendance: <?= defined('FEATURE_ATTENDANCE') && FEATURE_ATTENDANCE ? 'true' : 'false' ?>,
                grades: <?= defined('FEATURE_GRADES') && FEATURE_GRADES ? 'true' : 'false' ?>,
                reports: <?= defined('FEATURE_REPORTS') && FEATURE_REPORTS ? 'true' : 'false' ?>,
                pdf: <?= defined('FEATURE_EXPORT_PDF') && FEATURE_EXPORT_PDF ? 'true' : 'false' ?>
            },
            depts: {
                info:     { am: '<?= addslashes(defined('DEPT_INFO_NAME') ? DEPT_INFO_NAME : '') ?>', en: '<?= addslashes(defined('DEPT_INFO_NAME_EN') ? DEPT_INFO_NAME_EN : '') ?>' },
                edu:      { am: '<?= addslashes(defined('DEPT_EDU_NAME') ? DEPT_EDU_NAME : '') ?>', en: '<?= addslashes(defined('DEPT_EDU_NAME_EN') ? DEPT_EDU_NAME_EN : '') ?>' },
                finance:  { am: '<?= addslashes(defined('DEPT_FINANCE_NAME') ? DEPT_FINANCE_NAME : '') ?>', en: '<?= addslashes(defined('DEPT_FINANCE_NAME_EN') ? DEPT_FINANCE_NAME_EN : '') ?>' },
                material: { am: '<?= addslashes(defined('DEPT_MATERIAL_NAME') ? DEPT_MATERIAL_NAME : '') ?>', en: '<?= addslashes(defined('DEPT_MATERIAL_NAME_EN') ? DEPT_MATERIAL_NAME_EN : '') ?>' }
            }
        },
        theme: '<?= $_activeTheme ?>',
        themeBase: '<?= $_themeBase ?>',
        today: '<?= addslashes($_todayFormatted) ?>',
        impersonating: <?= $_isImpersonating ? 'true' : 'false' ?>,
        api: '/backend/api/',
        apiLegacy: '/admin/'
    };
    </script>
    
    <?= $extraHead ?>
    
    <!-- Ethiopian date picker (converts all date inputs; stores Gregorian) -->
    <?= function_exists('wbws_calendar_scripts') ? wbws_calendar_scripts($conn ?? null) : '' ?>

    <!-- Prevent flash of wrong theme mode -->
    <script>try{if(localStorage.getItem('school_theme_mode')==='light')document.documentElement.style.colorScheme='light'}catch(e){}</script>
</head>
<body class="<?= e($bodyClass) ?>">
    <script>try{if(localStorage.getItem('school_theme_mode')==='light')document.body.classList.add('light-mode')}catch(e){}</script>
    <?= $bodyContent ?>
    
    <!-- Core JS — shared utilities for ALL dashboards -->
    <script src="/frontend/js/core.js"></script>
    
    <?php if ($pageScript): ?>
    <script src="/frontend/js/<?= e($pageScript) ?>.js"></script>
    <?php endif; ?>
    
    <?php if ($_isImpersonating): ?>
    <!-- Impersonation restore button -->
    <div id="impersonateBar" style="position:fixed;bottom:16px;right:16px;z-index:9999;display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#000;padding:8px 16px;border-radius:12px;box-shadow:0 8px 24px rgba(245,158,11,0.4);font-size:12px;font-weight:600;cursor:pointer" onclick="window.api.post('impersonate.php',{action:'restore'}).then(function(d){if(d.status==='success')location.href='/frontend/pages/dashboard.php';})">
        <span style="font-size:16px">🔙</span>
        <span>Back to School Admin</span>
        <span style="font-size:10px;opacity:0.7">(Viewing as <?= e(str_replace('_', ' ', $_userRole)) ?>)</span>
    </div>
    <?php endif; ?>
</body>
</html>
