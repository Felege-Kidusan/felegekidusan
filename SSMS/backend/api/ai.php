<?php
/**
 * Redirect shim: /backend/api/ai.php → /admin/api_ai.php
 * During migration, both paths work. Real logic stays in admin/.
 * After full migration, swap: move real file here, make admin/ the shim.
 */
require_once __DIR__ . '/../../admin/api_ai.php';
