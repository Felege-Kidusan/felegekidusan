<?php
/**
 * Legacy shim (P72): /backend/ path wrapper — real logic lives in
 * admin/components/notification_center.php (same as api shims).
 */
require_once __DIR__ . '/../../admin/components/notification_center.php';

if (!function_exists('renderNotificationBell')) {
    function renderNotificationBell()
    {
        return renderNotificationCenter();
    }
}
