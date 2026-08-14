<?php
/**
 * Redirect shim: /backend/api/groups.php → /admin/backend/groups_api.php
 * During migration, both paths work. Real logic stays in admin/.
 * After full migration, swap: move real file here, make admin/ the shim.
 */
require_once __DIR__ . '/../../admin/backend/groups_api.php';
