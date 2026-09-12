<?php
/**
 * Legacy shim (P72): the old notification bell is now the WBWS
 * Notification Center. renderNotificationBell() keeps working for
 * any page that still calls it — it renders the new component.
 */
require_once __DIR__ . '/notification_center.php';

if (!function_exists('renderNotificationBell')) {
    function renderNotificationBell()
    {
        return renderNotificationCenter();
    }
}
