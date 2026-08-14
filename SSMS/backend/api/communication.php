<?php
/**
 * Redirect shim: /backend/api/communication.php → /admin/api_communication.php
 * During migration, both paths work. Real logic stays in admin/.
 * After full migration, swap: move real file here, make admin/ the shim.
 */
require_once __DIR__ . '/../../admin/api_communication.php';
