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
        $activeClass = $isActive ? ' act active' : '';
        $styleAttr = $extraStyles !== '' ? ' style="' . htmlspecialchars($extraStyles, ENT_QUOTES, 'UTF-8') . '"' : '';
        ?>
        <button type="button"
                class="<?= htmlspecialchars($buttonClass . $activeClass, ENT_QUOTES, 'UTF-8') ?>"
                data-sec="<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>"
                data-section="<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>"
                onclick="if(typeof switchSection==='function'){switchSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof navigateToSection==='function'){navigateToSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof showSection==='function'){showSection('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof nav==='function'){nav('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}else if(typeof switchTab==='function'){switchTab('<?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?>');}"
                <?= $styleAttr ?>
                title="My Profile">
            <i class="fa-solid fa-user-gear"></i> <span>My Profile</span>
        </button>
        <?php
    }
}

if (!function_exists('renderSidebarUserCard')) {
    /**
     * Renders a static bottom sidebar user info card displaying the logged-in user and role.
     *
     * @param string $fullName User's full name
     * @param string $roleTitle Display title for user's role
     * @param string $subtitle Secondary info (e.g. date)
     * @param string $initials Fallback initials
     * @param string $gradient Background gradient for fallback avatar
     */
    function renderSidebarUserCard(
        string $fullName,
        string $roleTitle = '',
        string $subtitle = '',
        string $initials = '',
        string $gradient = 'linear-gradient(135deg,#7c3aed,#6366f1)'
    ): void {
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
        <div class="ssms-sidebar-user-card" aria-label="Logged-in User Info">
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
        </div>
        <style>
        .ssms-sidebar-user-card {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .6rem .75rem;
            border-radius: 12px;
            background: rgba(255, 255, 255, .08);
            color: #fff !important;
            margin-top: auto;
            margin-bottom: .4rem;
            border: 1px solid rgba(255, 255, 255, .1);
            user-select: none;
            box-sizing: border-box;
            width: 100%;
        }
        .ssms-card-avatar-wrap {
            position: relative;
            width: 36px;
            height: 36px;
            min-width: 36px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
        }
        .ssms-card-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }
        .ssms-card-avatar-initials {
            width: 100%;
            height: 100%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            font-weight: 700;
            color: #fff;
            border-radius: 50%;
        }
        .ssms-card-info {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            flex: 1;
            min-width: 0;
        }
        .ssms-card-name {
            font-size: .82rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #fff;
        }
        .ssms-card-sub {
            font-size: .7rem;
            opacity: .75;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: rgba(255, 255, 255, .8);
        }
        </style>
        <?php
    }
}
