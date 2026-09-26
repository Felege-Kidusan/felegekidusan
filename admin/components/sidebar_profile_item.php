<?php
/**
 * Universal Sidebar Profile & Account Component
 * Sunday School Management System (FKSS / WBWS)
 *
 * Provides standardized, rock-solid profile navigation across all 12 web dashboards
 * in both Desktop sidebar and Mobile responsive layouts.
 */

if (!function_exists('renderSidebarProfileNavItem')) {
    /**
     * Renders a first-class sidebar navigation item.
     *
     * @param string $buttonClass CSS class matching the dashboard's design system (e.g. 'nl', 'np', 'nav-link', 'school-nav-link')
     * @param bool $isActive Whether this page is the active profile page
     * @param string $extraStyles Inline styles if required
     * @param string $secName The target tab/section name (default: 'profile')
     */
    function renderSidebarProfileNavItem(string $buttonClass = 'nl', bool $isActive = false, string $extraStyles = '', string $secName = 'profile'): void {
        $href = function_exists('ssms_app_url') ? ssms_app_url('admin/account.php') : '/admin/account.php';
        $activeClass = $isActive ? ' act active' : '';
        $styleAttr = $extraStyles !== '' ? ' style="' . htmlspecialchars($extraStyles, ENT_QUOTES, 'UTF-8') . '"' : '';
        ?>
        <button type="button"
                class="<?= htmlspecialchars($buttonClass . $activeClass, ENT_QUOTES, 'UTF-8') ?>"
                data-sec="<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>"
                data-section="<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>"
                onclick="if(typeof switchSection==='function'){switchSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof navigateToSection==='function'){navigateToSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof showSection==='function'){showSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof nav==='function'){nav('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof switchTab==='function'){switchTab('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else{window.location.href='<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>';}"
                <?= $styleAttr ?>
                title="My Profile & Account Settings">
            <i class="fa-solid fa-user-gear"></i> <span>My Profile</span>
        </button>
        <?php
    }
}

if (!function_exists('renderSidebarUserCard')) {
    /**
     * Renders an interactive bottom sidebar user card with live avatar and fallback initials.
     *
     * @param string $fullName User's full name
     * @param string $roleTitle Display title for user's role
     * @param string $subtitle Secondary info (e.g. date)
     * @param string $initials Fallback initials
     * @param string $gradient Background gradient for fallback avatar
     * @param string $secName The target tab/section name (default: 'profile')
     */
    function renderSidebarUserCard(
        string $fullName,
        string $roleTitle = '',
        string $subtitle = '',
        string $initials = '',
        string $gradient = 'linear-gradient(135deg,#7c3aed,#6366f1)',
        string $secName = 'profile'
    ): void {
        $accountUrl = function_exists('ssms_app_url') ? ssms_app_url('admin/account.php') : '/admin/account.php';
        $imageUrl = function_exists('ssms_app_url') ? ssms_app_url('admin/profile_image.php') : '/admin/profile_image.php';
        if ($initials === '') {
            $words = explode(' ', trim($fullName));
            foreach ($words as $w) {
                if ($w !== '') {
                    $initials .= mb_substr($w, 0, 1);
                }
            }
            $initials = strtoupper(mb_substr($initials, 0, 2)) ?: 'U';
        }
        $subText = $roleTitle !== '' ? $roleTitle : '';
        if ($subtitle !== '') {
            $subText = $subText !== '' ? $subText . ' • ' . $subtitle : $subtitle;
        }
        ?>
        <div class="ssms-sidebar-user-card"
             data-sec="<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>"
             data-section="<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>"
             onclick="if(typeof switchSection==='function'){switchSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof navigateToSection==='function'){navigateToSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof showSection==='function'){showSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof nav==='function'){nav('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof switchTab==='function'){switchTab('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else{window.location.href='<?= htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8') ?>';}"
             title="View & Edit Profile (<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>)">
            <div class="ssms-card-avatar-wrap">
                <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>"
                     alt=""
                     class="ssms-card-avatar-img"
                     onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='flex';">
                <div class="ssms-card-avatar-initials" style="background: <?= htmlspecialchars($gradient, ENT_QUOTES, 'UTF-8') ?>;">
                    <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <div class="ssms-card-info">
                <span class="ssms-card-name"><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($subText !== ''): ?>
                    <span class="ssms-card-sub"><?= htmlspecialchars($subText, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <i class="fa-solid fa-gear ssms-card-icon" aria-hidden="true"></i>
        </div>
        <style>
        .ssms-sidebar-user-card {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .6rem .75rem;
            border-radius: 12px;
            background: rgba(255, 255, 255, .1);
            color: #fff !important;
            text-decoration: none !important;
            cursor: pointer;
            transition: all .2s ease;
            margin-top: auto;
            margin-bottom: .4rem;
            border: 1px solid rgba(255, 255, 255, .12);
        }
        .ssms-sidebar-user-card:hover {
            background: rgba(255, 255, 255, .18);
            transform: translateY(-1px);
            border-color: rgba(255, 255, 255, .25);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
        }
        .ssms-card-avatar-wrap {
            position: relative;
            width: 36px;
            height: 36px;
            min-width: 36px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(0,0,0,.2);
        }
        .ssms-card-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .ssms-card-avatar-initials {
            display: none;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: .8rem;
            letter-spacing: .5px;
        }
        .ssms-card-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .ssms-card-name {
            font-size: .78rem;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ssms-card-sub {
            font-size: .62rem;
            color: rgba(255, 255, 255, .68);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ssms-card-icon {
            color: rgba(255, 255, 255, .55);
            font-size: .75rem;
            margin-left: auto;
            transition: transform .2s ease;
        }
        .ssms-sidebar-user-card:hover .ssms-card-icon {
            color: #fff;
            transform: rotate(45deg);
        }
        </style>
        <?php
    }
}
