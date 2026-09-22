<?php
/**
 * ============================================================
 * WBWS Account Settings — ONE shared "My Account" component
 * for every dashboard and shell. ("One identity, every
 * surface." See docs/ACCOUNT_SELF_SERVICE_SOLUTION.md and
 * docs/audits/ACCOUNT_SELF_SERVICE_RESEARCH.md.)
 * ============================================================
 * Usage (identical to the notification-center contract, P73):
 *   <?php include __DIR__ . '/../components/account_settings.php'; ?>
 *   <?= render_account_settings() ?>          ← once per placement
 *
 * COMPONENT CONTRACT (mirrors notification_center.php):
 *   A shared component must NEVER depend on the host page's load
 *   order and must NEVER be able to kill the page that hosts it.
 *     1. Self-bootstrapping: if config.php is not loaded yet
 *        (frontend shells buffer their body BEFORE requiring
 *        layouts/base.php), the component loads it itself —
 *        exactly like base.php does.
 *     2. Error boundary: the public render function catches
 *        Throwable, reports to error_log (→ /monitor), and
 *        degrades to rendering nothing. A broken account UI may
 *        never blank a whole dashboard.
 *     3. Self-contained CSRF token, carried via data-csrf on the
 *        panel — never read from host-page globals.
 *     4. Styling lives in admin/css/account-settings.css and
 *        behaviour in admin/js/account-settings.js — one runtime
 *        per page, namespaced under .wba- so it can never clash
 *        with a host dashboard's own styles.
 *     5. The panel is a position:fixed modal emitted once per
 *        page; it escapes every ancestor overflow/stacking
 *        context, same as the notification panel.
 *
 * The backend is the pre-existing role-agnostic
 * admin/api_settings.php (profile_get / profile_update /
 * password_change) plus the new account_activity /
 * signout_devices actions. Every signed-in role — super_admin,
 * school_admin, dept heads, teacher, takers, content editor —
 * gets the same self-service surface.
 * ============================================================
 */

if (!function_exists('render_account_settings')) {

/** Asset version — bump on every change to account-settings.css/js
 *  so heuristic caches drop the old copy. */
function wba_asset_version(): string
{
    return '1.3';
}

/** Self-contained HTML escaper — never call the host's helpers. */
function wba_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Guarantee the component's real dependencies (config.php: session,
 * CSRF token factory, constants) regardless of the host page's include
 * order. Idempotent: a no-op once config.php has run (ROOT_PATH set).
 */
function wba_ensure_bootstrapped(): void
{
    if (defined('ROOT_PATH')) {
        return;
    }
    $cfg = dirname(__DIR__, 2) . '/config.php';   // admin/components/ → root
    if (is_file($cfg)) {
        require_once $cfg;
    }
}

/** Read a session value safely, even if the host has not started the session. */
function wba_session(string $key, string $fallback = ''): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        try { session_start(); } catch (Throwable $t) { /* ignore */ }
    }
    return (string)($_SESSION[$key] ?? $fallback);
}

/** Multibyte helpers with graceful fallbacks (same defensive style as
 *  PasswordPolicy.php — deployments without ext-mbstring keep working). */
function wba_mb_upper(string $value): string
{
    return function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);
}

function wba_mb_initial(string $value): string
{
    $first = function_exists('mb_substr')
        ? mb_substr($value, 0, 1, 'UTF-8')
        : substr($value, 0, 1);
    return wba_mb_upper($first);
}

/** Role display: mezmur_attendance_taker → "MEZMUR ATTENDANCE TAKER". */
function wba_role_label(string $role): string
{
    return strtoupper(str_replace('_', ' ', trim($role) !== '' ? $role : 'user'));
}

/**
 * Emit the shared stylesheet + runtime exactly once per page, from
 * whichever render call (modal or section) runs first.
 */
function render_account_settings_assets(): string
{
    static $emitted = false;
    if ($emitted) { return ''; }
    $emitted = true;
    $v = wba_asset_version();
    return '<link rel="stylesheet" href="/admin/css/account-settings.css?v=' . $v . '">'
         . '<script src="/admin/js/account-settings.js?v=' . $v . '" defer></script>';
}

/**
 * Render the account trigger + the ONE modal panel + assets.
 * Every placement on a page shares a single panel (static guard).
 * Error boundary — may never kill the host page.
 */
function render_account_settings(): string
{
    try {
        wba_ensure_bootstrapped();

        static $emitted = false;
        $panel = '';
        if (!$emitted) {
            $emitted = true;

            // Self-contained CSRF — never depend on the host page.
            $csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';

            $userName  = wba_session('admin_full_name', wba_session('admin_username', 'User'));
            $initial   = wba_mb_initial(trim($userName) !== '' ? $userName : 'U');

            ob_start();
            echo render_account_settings_assets(); ?>
<div class="wba-panel" data-wba-panel data-csrf="<?= wba_esc($csrf) ?>" data-api="/admin/api_settings.php"
     role="dialog" aria-modal="true" aria-label="My Account" tabindex="-1" hidden>
    <div class="wba-card" role="document">
        <header class="wba-head">
            <span class="wba-title"><i class="fa-solid fa-user" aria-hidden="true"></i> My Account <span class="wba-am">የእኔ መገለጫ</span></span>
            <button type="button" class="wba-x" data-wba-close aria-label="Close">&times;</button>
        </header>

        <nav class="wba-tabs" role="tablist" aria-label="Account sections">
            <button type="button" class="wba-tab is-active" data-wba-tab="profile" role="tab" aria-selected="true">Profile</button>
            <button type="button" class="wba-tab" data-wba-tab="password" role="tab" aria-selected="false">Password</button>
            <button type="button" class="wba-tab" data-wba-tab="activity" role="tab" aria-selected="false">Security activity</button>
        </nav>

        <div class="wba-body">
            <!-- ===== PROFILE ===== -->
            <section class="wba-pane is-active" data-wba-pane="profile" role="tabpanel">
                <div class="wba-idcard">
                    <div class="wba-avatar" data-wba-avatar><?= wba_esc($initial) ?></div>
                    <div class="wba-idmeta">
                        <div class="wba-idname" data-wba-name><?= wba_esc($userName) ?></div>
                        <div class="wba-iduser">@<span data-wba-username><?= wba_esc(wba_session('admin_username')) ?></span></div>
                        <span class="wba-rolebadge" data-wba-role><?= wba_esc(wba_role_label(wba_session('admin_role'))) ?></span>
                    </div>
                </div>
                <dl class="wba-facts">
                    <div class="wba-fact"><dt>Email</dt><dd data-wba-email>—</dd></div>
                    <div class="wba-fact"><dt>Phone</dt><dd data-wba-phone>—</dd></div>
                    <div class="wba-fact"><dt>Member since</dt><dd data-wba-created>—</dd></div>
                    <div class="wba-fact"><dt>Last login</dt><dd data-wba-lastlogin>—</dd></div>
                    <div class="wba-fact"><dt>Total logins</dt><dd data-wba-logins>—</dd></div>
                </dl>

                <form class="wba-form" data-wba-form="profile" novalidate>
                    <label class="wba-field">
                        <span class="wba-label">Full name <b class="wba-req">*</b> <span class="wba-am">ሙሉ ስም</span></span>
                        <input type="text" name="full_name" data-wba-input="full_name" maxlength="100" autocomplete="name" required>
                    </label>
                    <label class="wba-field">
                        <span class="wba-label">Email <span class="wba-am">ኢሜይል</span></span>
                        <input type="email" name="email" data-wba-input="email" maxlength="100" autocomplete="email">
                        <small class="wba-hint" data-wba-email-hint hidden>Changing your email requires your current password.</small>
                    </label>
                    <label class="wba-field" data-wba-stepup hidden>
                        <span class="wba-label">Current password <span class="wba-am">የአሁኑ የይለፍ ቃል</span></span>
                        <div class="wba-pwdwrap">
                            <input type="password" name="current_password" data-wba-input="current_password" autocomplete="current-password">
                            <button type="button" class="wba-eye" data-wba-eye aria-label="Show password" tabindex="-1"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                        </div>
                    </label>
                    <label class="wba-field">
                        <span class="wba-label">Phone <span class="wba-am">ስልክ</span></span>
                        <input type="tel" name="phone" data-wba-input="phone" maxlength="20" placeholder="09xxxxxxxx" autocomplete="tel">
                    </label>
                    <div class="wba-actions">
                        <button type="submit" class="wba-btn wba-primary"><i class="fa-solid fa-check" aria-hidden="true"></i> Save changes</button>
                    </div>
                </form>
            </section>

            <!-- ===== PASSWORD ===== -->
            <section class="wba-pane" data-wba-pane="password" role="tabpanel" hidden>
                <form class="wba-form" data-wba-form="password" novalidate>
                    <label class="wba-field">
                        <span class="wba-label">Current password <b class="wba-req">*</b></span>
                        <div class="wba-pwdwrap">
                            <input type="password" name="current_password" data-wba-input="pwd_current" autocomplete="current-password" required>
                            <button type="button" class="wba-eye" data-wba-eye aria-label="Show password" tabindex="-1"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                        </div>
                    </label>
                    <label class="wba-field">
                        <span class="wba-label">New password <b class="wba-req">*</b> <span class="wba-am">አዲስ የይለፍ ቃል</span></span>
                        <div class="wba-pwdwrap">
                            <input type="password" name="new_password" data-wba-input="pwd_new" autocomplete="new-password" required>
                            <button type="button" class="wba-eye" data-wba-eye aria-label="Show password" tabindex="-1"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                        </div>
                        <div class="wba-meter" aria-hidden="true"><span data-wba-meter></span></div>
                        <ul class="wba-reqs" data-wba-reqs>
                            <li data-req="len">At least 12 characters</li>
                            <li data-req="bytes72">At most 72 bytes (bcrypt limit)</li>
                            <li data-req="common">Not a commonly used password</li>
                            <li data-req="different">Different from your current password</li>
                        </ul>
                    </label>
                    <label class="wba-field">
                        <span class="wba-label">Confirm new password <b class="wba-req">*</b></span>
                        <div class="wba-pwdwrap">
                            <input type="password" name="confirm_password" data-wba-input="pwd_confirm" autocomplete="new-password" required>
                            <button type="button" class="wba-eye" data-wba-eye aria-label="Show password" tabindex="-1"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                        </div>
                    </label>
                    <div class="wba-actions">
                        <button type="submit" class="wba-btn wba-primary"><i class="fa-solid fa-key" aria-hidden="true"></i> Change password</button>
                    </div>
                </form>
                <div class="wba-divider" role="separator"></div>
                <div class="wba-devices">
                    <div class="wba-devices-text">
                        <strong>Mobile devices</strong>
                        <p>Revoke every FKSS mobile-app session signed in with this account. You will need to sign in again on each device. Web sessions on other computers sign out the next time your password changes.</p>
                    </div>
                    <button type="button" class="wba-btn wba-danger" data-wba-signout><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> Sign out mobile devices</button>
                </div>
            </section>

            <!-- ===== ACTIVITY ===== -->
            <section class="wba-pane" data-wba-pane="activity" role="tabpanel" hidden>
                <p class="wba-activity-hint">Recent security events for your account only.</p>
                <ul class="wba-timeline" data-wba-timeline>
                    <li class="wba-tl-loading">Loading…</li>
                </ul>
            </section>
        </div>

        <div class="wba-toast" data-wba-toast hidden></div>
    </div>
</div>
<div class="wba-scrim" data-wba-scrim hidden></div>
<?php
            $panel = (string)ob_get_clean();
        }

        // The trigger renders at every placement; it drives the single panel.
        $userName = wba_session('admin_full_name', wba_session('admin_username', 'User'));
        return $panel
            . '<span class="wba-root">'
            . '<button type="button" class="wba-trigger" data-wba-open aria-haspopup="dialog" aria-expanded="false"'
            . ' aria-label="My Account" title="My Account">'
            . '<i class="fa-solid fa-user" aria-hidden="true"></i>'
            . '</button>'
            . '</span>';
    } catch (Throwable $t) {
        error_log('[account_settings] render failed: ' . $t->getMessage()
            . ' @ ' . $t->getFile() . ':' . $t->getLine());
        return '';
    }
}

/**
 * Render the inline "My Account" SECTION — the sidebar-driven, HR/Info-style
 * profile management page for every department dashboard (v1.2.0 sidebar
 * parity; see docs/audits/ACCOUNT_SELF_SERVICE_RESEARCH.md, Appendix C).
 *
 * The HOST dashboard owns routing: wrap this call in the dashboard's own
 * section container so its existing router reveals it, e.g.
 *   edu:      <div id="sec-account" class="sec"><?= render_account_section() ?></div>
 *   school:   <section id="section-account" class="cs"><?= render_account_section() ?></section>
 *   super:    <section id="section-account" class="section" hidden>…
 * Card pages without a router (content_editor) may render it bare with
 * data-wba-selfroute handling in the shared runtime.
 *
 * Shares the single runtime + stylesheet with the modal (emitted once per
 * page by whichever render call runs first). Error boundary — may never
 * kill the host page.
 */
function render_account_section(array $opts = []): string
{
    try {
        wba_ensure_bootstrapped();

        static $emitted = false;
        if ($emitted) { return ''; }
        $emitted = true;

        $csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';

        $userName  = wba_session('admin_full_name', wba_session('admin_username', 'User'));
        $initial   = wba_mb_initial(trim($userName) !== '' ? $userName : 'U');

        // selfroute: card pages with no host router (content_editor) — the
        // shared runtime toggles visibility itself on [data-wba-nav] clicks.
        // visible: pages that should show the section immediately (dept_taker).
        $attrs = ' data-wba-section';
        if (!empty($opts['selfroute'])) { $attrs .= ' data-wba-selfroute'; }
        if (empty($opts['visible'])) { $attrs .= ' hidden'; }

        ob_start();
        // Assets are shared with the modal renderer; emit them here if the
        // modal has not already (static guard inside the panel renderer).
        echo render_account_settings_assets();
        ?>
<section class="wba-sec"<?= $attrs ?> data-csrf="<?= wba_esc($csrf) ?>" data-api="/admin/api_settings.php">
    <header class="wba-sec-head">
        <div class="wba-avatar wba-sec-avatar" data-wba-avatar><?= wba_esc($initial) ?></div>
        <div class="wba-sec-id">
            <h2 class="wba-sec-title">My Account <span class="wba-am">የእኔ መገለጫ</span></h2>
            <div class="wba-sec-sub">
                <span class="wba-sec-name" data-wba-name><?= wba_esc($userName) ?></span>
                <span class="wba-sec-user">@<span data-wba-username><?= wba_esc(wba_session('admin_username')) ?></span></span>
                <span class="wba-rolebadge" data-wba-role><?= wba_esc(wba_role_label(wba_session('admin_role'))) ?></span>
            </div>
        </div>
        <dl class="wba-facts wba-sec-facts">
            <div class="wba-fact"><dt>Email</dt><dd data-wba-email>—</dd></div>
            <div class="wba-fact"><dt>Phone</dt><dd data-wba-phone>—</dd></div>
            <div class="wba-fact"><dt>Last login</dt><dd data-wba-lastlogin>—</dd></div>
            <div class="wba-fact"><dt>Total logins</dt><dd data-wba-logins>—</dd></div>
        </dl>
    </header>

    <div class="wba-sec-grid">
        <!-- Edit profile -->
        <div class="wba-card">
            <h3 class="wba-cardtitle"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit profile <span class="wba-am">መገለጫ ማስተካከያ</span></h3>
            <form class="wba-form" data-wba-form="profile" novalidate>
                <label class="wba-field">
                    <span class="wba-label">Full name <b class="wba-req">*</b></span>
                    <input type="text" name="full_name" data-wba-input="full_name" maxlength="100" autocomplete="name" required>
                </label>
                <label class="wba-field">
                    <span class="wba-label">Email</span>
                    <input type="email" name="email" data-wba-input="email" maxlength="100" autocomplete="email">
                    <small class="wba-hint" data-wba-email-hint hidden>Changing your email requires your current password.</small>
                </label>
                <label class="wba-field" data-wba-stepup hidden>
                    <span class="wba-label">Current password <span class="wba-am">(ለኢሜይል ለውጥ)</span></span>
                    <div class="wba-pwdwrap">
                        <input type="password" name="current_password" data-wba-input="current_password" autocomplete="current-password">
                        <button type="button" class="wba-eye" data-wba-eye aria-label="Show password" tabindex="-1"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                    </div>
                </label>
                <label class="wba-field">
                    <span class="wba-label">Phone</span>
                    <input type="tel" name="phone" data-wba-input="phone" maxlength="20" placeholder="09xxxxxxxx" autocomplete="tel">
                </label>
                <div class="wba-actions"><button type="submit" class="wba-btn wba-primary"><i class="fa-solid fa-check" aria-hidden="true"></i> Save changes</button></div>
            </form>
        </div>

        <!-- Password -->
        <div class="wba-card">
            <h3 class="wba-cardtitle"><i class="fa-solid fa-key" aria-hidden="true"></i> Change password <span class="wba-am">የይለፍ ቃል ለውጥ</span></h3>
            <form class="wba-form" data-wba-form="password" novalidate>
                <label class="wba-field">
                    <span class="wba-label">Current password <b class="wba-req">*</b></span>
                    <div class="wba-pwdwrap">
                        <input type="password" name="current_password" data-wba-input="pwd_current" autocomplete="current-password" required>
                        <button type="button" class="wba-eye" data-wba-eye aria-label="Show password" tabindex="-1"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                    </div>
                </label>
                <label class="wba-field">
                    <span class="wba-label">New password <b class="wba-req">*</b></span>
                    <div class="wba-pwdwrap">
                        <input type="password" name="new_password" data-wba-input="pwd_new" autocomplete="new-password" required>
                        <button type="button" class="wba-eye" data-wba-eye aria-label="Show password" tabindex="-1"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                    </div>
                    <div class="wba-meter" aria-hidden="true"><span data-wba-meter></span></div>
                    <ul class="wba-reqs" data-wba-reqs>
                        <li data-req="len">At least 12 characters</li>
                        <li data-req="bytes72">At most 72 bytes (bcrypt limit)</li>
                        <li data-req="common">Not a commonly used password</li>
                        <li data-req="different">Different from your current password</li>
                    </ul>
                </label>
                <label class="wba-field">
                    <span class="wba-label">Confirm new password <b class="wba-req">*</b></span>
                    <div class="wba-pwdwrap">
                        <input type="password" name="confirm_password" data-wba-input="pwd_confirm" autocomplete="new-password" required>
                        <button type="button" class="wba-eye" data-wba-eye aria-label="Show password" tabindex="-1"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                    </div>
                </label>
                <div class="wba-actions"><button type="submit" class="wba-btn wba-primary"><i class="fa-solid fa-key" aria-hidden="true"></i> Change password</button></div>
            </form>
        </div>

        <!-- Security activity -->
        <div class="wba-card">
            <h3 class="wba-cardtitle"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Security activity <span class="wba-am">የደህንነት እንቅስቃሴ</span></h3>
            <ul class="wba-timeline" data-wba-timeline>
                <li class="wba-tl-loading">Loading…</li>
            </ul>
        </div>

        <!-- Devices -->
        <div class="wba-card">
            <h3 class="wba-cardtitle"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> Mobile devices</h3>
            <p class="wba-cardtext">Revoke every FKSS mobile-app session signed in with this account. Each device must sign in again.</p>
            <button type="button" class="wba-btn wba-danger" data-wba-signout><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> Sign out mobile devices</button>
        </div>
    </div>

    <div class="wba-toast" data-wba-toast hidden></div>
</section>
<?php
        return (string)ob_get_clean();
    } catch (Throwable $t) {
        error_log('[account_settings] section render failed: ' . $t->getMessage()
            . ' @ ' . $t->getFile() . ':' . $t->getLine());
        return '';
    }
}

} // end function_exists guard
