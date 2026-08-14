<?php
/**
 * Redirect shim: /backend/api/members_check.php → /admin/api_check_duplicate.php
 * During migration, both paths work. Real logic stays in admin/.
 * After full migration, swap: move real file here, make admin/ the shim.
 */
require_once __DIR__ . '/../../admin/api_check_duplicate.php';
