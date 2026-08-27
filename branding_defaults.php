<?php
/**
 * Fail-soft branding defaults.
 *
 * WHY THIS FILE EXISTS (production incident, ID-card fatal)
 * ---------------------------------------------------------
 * The UI layer (ID card template, login page, dashboards, printable
 * reports) reads branding constants such as RELIGIOUS_INVOCATION or
 * ID_CARD_TITLE_EN *un guarded*. On PHP >= 8 an undefined constant is an
 * uncatchable-at-template-level Error, which crashed whole pages with the
 * generic "Something went wrong" screen whenever a deployment's
 * school_config.php drifted behind the codebase (older copy, partial
 * merge, hand edit).
 *
 * PATTERN (industry-standard fail-soft configuration):
 * school_config.php remains the single source of truth. This file is
 * loaded immediately after it and supplies guarded fallbacks ONLY for
 * constants the deployment did not define — so a complete config behaves
 * exactly as before, while an incomplete one degrades gracefully instead
 * of throwing Error. Every fallback is generic (no church identity baked
 * in) and derived from sibling constants where that keeps the UI coherent.
 *
 * Maintain together with school_config.php: when a new branding constant
 * is introduced, add its guarded default here in the same commit.
 */

// ── Core identity ─────────────────────────────────────────────
if (!defined('ACTIVE_THEME'))           define('ACTIVE_THEME', 'fkss');
if (!defined('SCHOOL_NAME'))            define('SCHOOL_NAME', 'Sunday School');
if (!defined('SCHOOL_NAME_SHORT'))      define('SCHOOL_NAME_SHORT', SCHOOL_NAME);
if (!defined('SCHOOL_NAME_AMHARIC'))    define('SCHOOL_NAME_AMHARIC', SCHOOL_NAME);
if (!defined('SCHOOL_NAME_SHORT_AM'))   define('SCHOOL_NAME_SHORT_AM', SCHOOL_NAME_AMHARIC);
if (!defined('SCHOOL_TRANSLATION_EN'))  define('SCHOOL_TRANSLATION_EN', SCHOOL_NAME_SHORT);
if (!defined('SCHOOL_TAGLINE'))         define('SCHOOL_TAGLINE', '');
if (!defined('SCHOOL_TAGLINE_AM'))      define('SCHOOL_TAGLINE_AM', '');
if (!defined('SCHOOL_TYPE'))            define('SCHOOL_TYPE', 'Sunday School');
if (!defined('SCHOOL_TYPE_AM'))         define('SCHOOL_TYPE_AM', 'ሰንበት ት/ቤት');

// ── Parish / religious texts ──────────────────────────────────
if (!defined('PARISH_NAME_AM'))         define('PARISH_NAME_AM', SCHOOL_NAME_AMHARIC);
if (!defined('PARISH_NAME_EN'))         define('PARISH_NAME_EN', SCHOOL_TRANSLATION_EN);
if (!defined('DENOMINATION_AM'))        define('DENOMINATION_AM', '');
if (!defined('DENOMINATION_EN'))        define('DENOMINATION_EN', '');
if (!defined('RELIGIOUS_INVOCATION'))   define('RELIGIOUS_INVOCATION', '');

// ── ID card texts ─────────────────────────────────────────────
if (!defined('ID_CARD_TITLE_AM'))       define('ID_CARD_TITLE_AM', SCHOOL_NAME_SHORT_AM . ' ' . SCHOOL_TYPE_AM . ' አባል መታወቂያ ካርድ');
if (!defined('ID_CARD_TITLE_EN'))       define('ID_CARD_TITLE_EN', SCHOOL_TRANSLATION_EN . ' ' . SCHOOL_TYPE . ' Member ID Card');
if (!defined('ID_CARD_FOOTER_AM'))      define('ID_CARD_FOOTER_AM', '');
if (!defined('ID_CARD_SIG_HEAD_AM'))    define('ID_CARD_SIG_HEAD_AM', 'የሰንበት ት/ቤትቱ ሃላፊ ስምና ፊርማ');
if (!defined('ID_CARD_SIG_ADMIN_AM'))   define('ID_CARD_SIG_ADMIN_AM', 'የደብሩ አስተዳደር ስምና ፊርማ');
if (!defined('ID_CARD_DISCLAIMER_AM'))  define('ID_CARD_DISCLAIMER_AM', '');
if (!defined('ID_CARD_BACKGROUND'))     define('ID_CARD_BACKGROUND', '/admin/id_cards/assets/backgrounds/id_card_bg.jpg');

// ── Member codes ──────────────────────────────────────────────
if (!defined('MEMBER_CODE_PREFIX'))     define('MEMBER_CODE_PREFIX', '');
if (!defined('MEMBER_CODE_FORMAT'))     define('MEMBER_CODE_FORMAT', '');

// ── URLs (emergency derivation from the request host) ─────────
if (!defined('SITE_DOMAIN')) {
    define('SITE_DOMAIN', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
}
if (!defined('SITE_URL')) {
    $__proto = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    define('SITE_URL', $__proto . '://' . SITE_DOMAIN);
    unset($__proto);
}
if (!defined('ADMIN_URL'))              define('ADMIN_URL', SITE_URL . '/admin');
if (!defined('CORS_ORIGINS'))           define('CORS_ORIGINS', serialize([SITE_URL]));

// ── Admin panel chrome ────────────────────────────────────────
if (!defined('ADMIN_PANEL_TITLE'))      define('ADMIN_PANEL_TITLE', 'Admin Panel');
if (!defined('ADMIN_LOGO_ICON'))        define('ADMIN_LOGO_ICON', '🛡️');
if (!defined('ADMIN_FOOTER_TEXT'))      define('ADMIN_FOOTER_TEXT', '');

// ── Department names ──────────────────────────────────────────
if (!defined('DEPT_INFO_NAME'))         define('DEPT_INFO_NAME', 'መረጃ ክፍል');
if (!defined('DEPT_INFO_NAME_EN'))      define('DEPT_INFO_NAME_EN', 'Information Department');
if (!defined('DEPT_EDU_NAME'))          define('DEPT_EDU_NAME', 'ትምህርት ክፍል');
if (!defined('DEPT_EDU_NAME_EN'))       define('DEPT_EDU_NAME_EN', 'Education Department');
if (!defined('DEPT_FINANCE_NAME'))      define('DEPT_FINANCE_NAME', 'ፋይናንስ ክል');
if (!defined('DEPT_FINANCE_NAME_EN'))   define('DEPT_FINANCE_NAME_EN', 'Finance Department');
if (!defined('DEPT_MATERIAL_NAME'))     define('DEPT_MATERIAL_NAME', 'ንብረት ክፍል');
if (!defined('DEPT_MATERIAL_NAME_EN'))  define('DEPT_MATERIAL_NAME_EN', 'Material Department');
if (!defined('DEPT_GROUPS_NAME'))       define('DEPT_GROUPS_NAME', 'ቡድኖች');
if (!defined('DEPT_GROUPS_NAME_EN'))    define('DEPT_GROUPS_NAME_EN', 'Groups');

// ── Theme colours (member.php & landing rely on these) ────────
if (!defined('THEME_PRIMARY'))          define('THEME_PRIMARY', '#600000');
if (!defined('THEME_PRIMARY_LIGHT'))    define('THEME_PRIMARY_LIGHT', '#7a1010');
if (!defined('THEME_PRIMARY_DARK'))     define('THEME_PRIMARY_DARK', '#400000');
if (!defined('THEME_ACCENT'))           define('THEME_ACCENT', '#F0C000');
if (!defined('THEME_ACCENT_2'))         define('THEME_ACCENT_2', '#B8860B');
if (!defined('THEME_SIDEBAR_TOP'))      define('THEME_SIDEBAR_TOP', THEME_PRIMARY_DARK);
if (!defined('THEME_SIDEBAR_MID'))      define('THEME_SIDEBAR_MID', THEME_PRIMARY);
if (!defined('THEME_SIDEBAR_BOTTOM'))   define('THEME_SIDEBAR_BOTTOM', THEME_PRIMARY_DARK);
if (!defined('THEME_BASE_PATH'))        define('THEME_BASE_PATH', '/themes/' . ACTIVE_THEME);

// ── Artwork paths ─────────────────────────────────────────────
if (!defined('SCHOOL_LOGO_PATH'))       define('SCHOOL_LOGO_PATH', '/admin/id_cards/assets/logos/school_logo.png');
if (!defined('SCHOOL_SEAL_PATH'))       define('SCHOOL_SEAL_PATH', '');
if (!defined('SCHOOL_LOGO_PATH_LEGACY')) define('SCHOOL_LOGO_PATH_LEGACY', SCHOOL_LOGO_PATH);
if (!defined('SCHOOL_SEAL_PATH_LEGACY')) define('SCHOOL_SEAL_PATH_LEGACY', SCHOOL_SEAL_PATH);

// ── Exports / backups / PWA / landing / misc ──────────────────
if (!defined('EXPORT_PREFIX'))          define('EXPORT_PREFIX', 'ssms');
if (!defined('BACKUP_HEADER'))          define('BACKUP_HEADER', 'SSMS BACKUP');
if (!defined('PWA_CACHE_NAME'))         define('PWA_CACHE_NAME', 'ssms-cache');
if (!defined('PWA_CACHE_PREFIX'))       define('PWA_CACHE_PREFIX', 'ssms');
if (!defined('PWA_NAME'))               define('PWA_NAME', SCHOOL_NAME);
if (!defined('PWA_SHORT_NAME'))         define('PWA_SHORT_NAME', SCHOOL_NAME_SHORT);
if (!defined('PWA_DESCRIPTION'))        define('PWA_DESCRIPTION', SCHOOL_TAGLINE);
if (!defined('LANDING_MISSION_EN'))     define('LANDING_MISSION_EN', '');
if (!defined('LANDING_MISSION_AM'))     define('LANDING_MISSION_AM', '');
if (!defined('COPYRIGHT_YEAR'))         define('COPYRIGHT_YEAR', (string)date('Y'));
if (!defined('COPYRIGHT_TEXT'))         define('COPYRIGHT_TEXT', SCHOOL_NAME);
if (!defined('MONITOR_PAGE_TITLE'))     define('MONITOR_PAGE_TITLE', 'System Monitor');
if (!defined('DEVELOPER_NAME'))         define('DEVELOPER_NAME', '');
if (!defined('DEVELOPER_SHOW_CREDIT'))  define('DEVELOPER_SHOW_CREDIT', false);
if (!defined('API_NAME'))               define('API_NAME', 'SSMS API');
if (!defined('API_MEMBER_PREFIX'))      define('API_MEMBER_PREFIX', 'M');
if (!defined('GROUP_CAT_SS'))           define('GROUP_CAT_SS', 'Sunday School');
if (!defined('GROUP_CAT_SS_AM'))        define('GROUP_CAT_SS_AM', 'ሰንበት ት/ቤት');
if (!defined('GROUP_CAT_PARISH'))       define('GROUP_CAT_PARISH', 'Parish');
if (!defined('GROUP_CAT_PARISH_AM'))    define('GROUP_CAT_PARISH_AM', 'ግቢ');
if (!defined('GROUP_UNDER_SS_LABEL'))   define('GROUP_UNDER_SS_LABEL', 'Under Sunday School');
if (!defined('GROUP_UNDER_SS_LABEL_AM')) define('GROUP_UNDER_SS_LABEL_AM', 'በሰንበት ት/ቤት ስር');
if (!defined('LEAK_DETECT_KEYWORDS'))   define('LEAK_DETECT_KEYWORDS', serialize(['password', 'secret', 'token']));
