<?php
/**
 * ============================================================
 * WBWS Notification Center — ONE shared component for every
 * dashboard. P73 Phase 1 architecture (see
 * docs/COMMUNICATION_UX_OVERHAUL.md).
 * ============================================================
 * Usage (unchanged since P72):
 *   <?php include __DIR__ . '/components/notification_center.php'; ?>
 *   <?= renderNotificationCenter() ?>          ← once per placement
 *
 * Phase 1 architecture:
 *   • Styling lives in  admin/css/comm.css  (cacheable static file,
 *     token-driven — no more ~20KB inlined into every page render).
 *   • Behaviour lives in  admin/js/comm.js  (one runtime: de-duplicated
 *     API client, visibility/backoff polling, explicit loading/empty/
 *     error+Retry states, toast service).
 *   • The panel is emitted ONCE per page (position:fixed, coordinates
 *     measured at open time) — this escapes every ancestor
 *     overflow/stacking-context trap that made the old absolute panel
 *     clip off-screen, and no dashboards' scroll containers affect it.
 *   • The bell itself is notification-only: recent items + links to the
 *     dedicated sections. Each placement renders just the button; every
 *     bell on the page drives the same single panel and shares one poll.
 *   • Mobile (≤768px): compact scrimmed bottom sheet (≤60vh) — never a
 *     full-screen takeover; safe-area aware; closes on scrim/Escape.
 *   • Self-contained CSRF token (fixes the legacy bell's silent-write
 *     bug; carried via data-csrf on the panel).
 * Legacy: renderNotificationBell() (notification_bell.php) still
 * delegates here, so old includes keep working.
 */

if (!function_exists('renderNotificationCenter')) {

/** Asset version — bump on every change to comm.css/comm.js so
 *  heuristic caches drop the old copy (proper cache headers: Phase 5). */
function ncAssetVersion(): string
{
    return '73.1';
}

/** Emit the shared stylesheet, the ONE panel + scrim, and the runtime
 *  exactly once per page. */
function renderNotificationCenterAssets(): string
{
    static $emitted = false;
    if ($emitted) { return ''; }
    $emitted = true;

    // Self-contained CSRF — the component must never depend on the
    // host page having defined a token.
    $ncCsrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
    $v = ncAssetVersion();
    ob_start();
    ?>
    <link rel="stylesheet" href="/admin/css/comm.css?v=<?= $v ?>">
    <div class="nc-panel" data-nc-panel data-csrf="<?= e($ncCsrf) ?>" data-api="/admin/api_notifications.php"
         role="dialog" aria-label="Notifications" tabindex="-1" hidden>
        <div class="nc-head">
            <span class="nc-title"><i class="fa-solid fa-bell" aria-hidden="true"></i> Notifications</span>
            <span class="nc-head-actions">
                <button type="button" class="nc-link nc-mark-all" hidden><i class="fa-solid fa-check-double" aria-hidden="true"></i> Mark all read</button>
                <button type="button" class="nc-x" aria-label="Close">&times;</button>
            </span>
        </div>
        <div class="nc-tabs" role="tablist">
            <button type="button" class="nc-tab is-active" data-tab="alerts" role="tab" aria-selected="true">Alerts <span class="nc-count" data-count="alerts" hidden>0</span></button>
            <button type="button" class="nc-tab" data-tab="announcements" role="tab" aria-selected="false">Announcements <span class="nc-count" data-count="announcements" hidden>0</span></button>
            <button type="button" class="nc-tab" data-tab="tasks" role="tab" aria-selected="false">Tasks <span class="nc-count" data-count="tasks" hidden>0</span></button>
        </div>
        <div class="nc-body">
            <div class="nc-list" data-list="alerts" role="tabpanel"><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
            <div class="nc-list" data-list="announcements" role="tabpanel" hidden><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
            <div class="nc-list" data-list="tasks" role="tabpanel" hidden><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
        </div>
        <div class="nc-foot">
            <a href="/admin/notifications.php" class="nc-foot-link"><i class="fa-solid fa-inbox" aria-hidden="true"></i> All notifications</a>
            <a href="/admin/messages.php" class="nc-foot-link nc-msg-link"><i class="fa-solid fa-comments" aria-hidden="true"></i> Messages <span class="nc-count" data-count="messages" hidden>0</span></a>
        </div>
    </div>
    <div class="nc-scrim" data-nc-scrim hidden></div>
    <script src="/admin/js/comm.js?v=<?= $v ?>" defer></script>
    <?php
    return ob_get_clean();
}

/**
 * Render one bell placement. Every bell on the page shares the single
 * panel + poll emitted by renderNotificationCenterAssets().
 */
function renderNotificationCenter(): string
{
    return renderNotificationCenterAssets()
        . '<div class="nc-root">'
        . '<button type="button" class="nc-bell" aria-haspopup="dialog" aria-expanded="false" aria-label="Notifications" title="Notifications">'
        . '<i class="fa-solid fa-bell" aria-hidden="true"></i>'
        . '<span class="nc-badge" hidden>0</span>'
        . '</button>'
        . '</div>';
}

} // end function_exists guard
