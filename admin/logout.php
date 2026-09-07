<?php
// Do not load the database/config guard here: logout must work even when the
// database is unavailable or this session has already expired.
require_once __DIR__ . '/../backend/core/browser.php';
ssms_start_browser_session();
ssms_destroy_browser_session();

header('Cache-Control: no-store, no-cache, must-revalidate');
// Root-anchored and same-origin: includes through /backend/auth/logout.php
// must not resolve to the nonexistent /backend/auth/index.php.
header('Location: ' . ssms_app_url('admin/index.php') . '?success=' . rawurlencode('You have been logged out.'));
exit;
