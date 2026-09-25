<?php
/**
 * Minimal common account-page launcher for authenticated web dashboards.
 * The account page itself omits the launcher to avoid duplicate navigation.
 */
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    return;
}
$currentScript = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
if ($currentScript === 'account.php') {
    return;
}
$responseStatus = http_response_code();
if (is_int($responseStatus) && $responseStatus >= 300 && $responseStatus < 400) {
    return;
}
?>
<style>
.ssms-account-launcher{position:fixed;top:max(12px,env(safe-area-inset-top));right:max(12px,env(safe-area-inset-right));z-index:1100;display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:8px 13px;border:1px solid rgba(255,255,255,.2);border-radius:999px;background:linear-gradient(135deg,#059669,#0f766e);box-shadow:0 8px 24px rgba(2,6,23,.3);color:#fff!important;font:700 12px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;text-decoration:none!important;transition:transform .18s,box-shadow .18s}
.ssms-account-launcher:hover{transform:translateY(-1px);box-shadow:0 11px 28px rgba(2,6,23,.38)}
.ssms-account-launcher:focus-visible{outline:3px solid rgba(52,211,153,.45);outline-offset:3px}
.ssms-account-launcher svg{width:17px;height:17px;fill:currentColor}
@media(max-width:640px){.ssms-account-launcher{top:auto;right:max(12px,env(safe-area-inset-right));bottom:calc(76px + env(safe-area-inset-bottom));width:46px;height:46px;min-height:46px;padding:0;justify-content:center}.ssms-account-launcher span{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}}
</style>
<a class="ssms-account-launcher" href="<?= e(ssms_app_url('admin/account.php')) ?>" aria-label="Open my profile and account settings">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5C21 16.5 17 14 12 14Z"/></svg>
    <span>My Profile</span>
</a>
