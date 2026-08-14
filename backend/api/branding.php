<?php
/**
 * Redirect shim: /backend/api/branding.php → /admin/api_branding.php
 * During migration, both paths work. Real logic stays in admin/.
 * After full migration, swap: move real file here, make admin/ the shim.
 */
require_once __DIR__ . '/../../admin/api_branding.php';
