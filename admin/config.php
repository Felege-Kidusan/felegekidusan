<?php
/**
 * ============================================================
 * ADMIN CONFIG - Includes Main Configuration
 * ============================================================
 * 
 * This file simply loads the main config.php
 * All database settings and functions are in /config.php
 * 
 * Why? So we have ONE place to change database credentials
 * instead of multiple files.
 * ============================================================
 */

// Load the main configuration file
require_once __DIR__ . '/../config.php';

// ============================================================
// ADMIN-SPECIFIC SETTINGS (if needed in future)
// ============================================================
// You can add admin-specific settings here that don't apply
// to the public pages.

// Example:
// define('ADMIN_ITEMS_PER_PAGE', 20);
// define('ADMIN_MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
