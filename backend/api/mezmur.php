<?php
/**
 * Redirect shim: /backend/api/mezmur.php → /admin/api_mezmur.php
 * Same pattern as the finance module during the front/back
 * separation migration: the front end calls /backend/api/*, the
 * real logic stays in admin/ until the migration completes.
 */
require_once __DIR__ . '/../../admin/api_mezmur.php';
