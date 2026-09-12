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
 *
 * COMPONENT CONTRACT (P73 Phase 2.1 — isolation hardening):
 *   A shared component must NEVER depend on the host page's load order
 *   and must NEVER be able to kill the page that hosts it.
 *     1. Self-bootstrapping: if config.php is not loaded yet (frontend
 *        shells buffer their body BEFORE requiring layouts/base.php),
 *        the component loads it itself — exactly like base.php does.
 *     2. Error boundary (the React error-boundary pattern): every
 *        public render function catches Throwable, reports to
 *        error_log (→ /monitor), and degrades to rendering nothing.
 *        A broken notification UI may never blank a whole dashboard —
 *        the exact regression that took the mezmur & finance shells
 *        down ("Call to undefined function e()").
 *     3. Zero host-helper calls: escaping uses the private ncEsc()
 *        instead of the host's e(); CSRF falls back to '' only when
 *        config could not be loaded (writes then fail loudly at the
 *        API instead of silently).
 * Legacy: renderNotificationBell() (notification_bell.php) still
 * delegates here, so old includes keep working.
 */

if (!function_exists('renderNotificationCenter')) {

/** Asset version — bump on every change to comm.css/comm.js so
 *  heuristic caches drop the old copy (proper cache headers: Phase 5). */
function ncAssetVersion(): string
{
    return '73.4';
}

/** Self-contained HTML escaper — never call the host's helpers. */
function ncEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Guarantee the component's real dependencies (config.php: session,
 * CSRF token factory, constants) regardless of the host page's include
 * order. Idempotent: a no-op once config.php has run (ROOT_PATH set).
 */
function ncEnsureBootstrapped(): void
{
    if (defined('ROOT_PATH')) { return; }
    $cfg = dirname(__DIR__, 2) . '/config.php';   // admin/components/ → root
    if (is_file($cfg)) { require_once $cfg; }
}

/** Emit the shared stylesheet, the ONE panel + scrim, and the runtime
 *  exactly once per page. */
function ncRenderPanel(): string
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
    <div class="nc-panel" data-nc-panel data-csrf="<?= ncEsc($ncCsrf) ?>" data-api="/admin/api_notifications.php"
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
            <a href="/admin/notifications.php" class="nc-foot-link" data-comm-open="inbox"><i class="fa-solid fa-inbox" aria-hidden="true"></i> Open inbox</a>
            <a href="/admin/messages.php" class="nc-foot-link nc-msg-link" data-comm-open="messages"><i class="fa-solid fa-comments" aria-hidden="true"></i> Messages <span class="nc-count" data-count="messages" hidden>0</span></a>
        </div>
    </div>
    <div class="nc-scrim" data-nc-scrim hidden></div>
    <script src="/admin/js/comm.js?v=<?= $v ?>" defer></script>
    <?php
    return ob_get_clean();
}

/** Public: assets only. Error boundary — may never kill the host page. */
function renderNotificationCenterAssets(): string
{
    try {
        ncEnsureBootstrapped();
        return ncRenderPanel();
    } catch (Throwable $t) {
        error_log('[notification_center] assets render failed: ' . $t->getMessage()
            . ' @ ' . $t->getFile() . ':' . $t->getLine());
        return '';
    }
}

/**
 * Render one bell placement. Every bell on the page shares the single
 * panel + poll emitted by the assets. Error boundary — may never kill
 * the host page (degrades to no bell instead).
 */
function renderNotificationCenter(): string
{
    try {
        ncEnsureBootstrapped();
        return ncRenderPanel()
            . '<div class="nc-root">'
            . '<button type="button" class="nc-bell" aria-haspopup="dialog" aria-expanded="false" aria-label="Notifications" title="Notifications">'
            . '<i class="fa-solid fa-bell" aria-hidden="true"></i>'
            . '<span class="nc-badge" hidden>0</span>'
            . '</button>'
            . '</div>';
    } catch (Throwable $t) {
        error_log('[notification_center] bell render failed: ' . $t->getMessage()
            . ' @ ' . $t->getFile() . ':' . $t->getLine());
        return '';
    }
}

} // end function_exists guard
