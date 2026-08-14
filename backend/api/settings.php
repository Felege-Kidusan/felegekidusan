<?php
/**
 * Redirect shim: /backend/api/settings.php → /admin/api_settings.php
 * During migration, both paths work. Real logic stays in admin/.
 * After full migration, swap: move real file here, make admin/ the shim.
 */
require_once __DIR__ . '/../../admin/api_settings.php';
