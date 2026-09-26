<?php
/**
 * Minimal common account-page launcher for authenticated web dashboards.
 * Suppressed on rich in-dashboard profile tab interfaces.
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
.ssms-account-launcher{display:none!important}
</style>
<a class="ssms-account-launcher" href="<?= e(ssms_app_url('admin/account.php')) ?>" aria-label="Open my profile and account settings" style="display:none!important">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5C21 16.5 17 14 12 14Z"/></svg>
    <span>My Profile</span>
</a>
