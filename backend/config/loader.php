<?php
/**
 * Backend Config Loader
 * 
 * All backend/ files require this instead of guessing relative paths.
 * It finds the root config.php regardless of how deep the file is.
 * 
 * Usage: require_once __DIR__ . '/../config/loader.php';
 *   or:  require_once __DIR__ . '/../../backend/config/loader.php';
 */

// Walk up to find config.php
$_configFound = false;
$_searchDir = __DIR__;

for ($i = 0; $i < 5; $i++) {
    $_searchDir = dirname($_searchDir);
    if (file_exists($_searchDir . '/config.php')) {
        require_once $_searchDir . '/config.php';
        $_configFound = true;
        break;
    }
}

if (!$_configFound) {
    // Fallback: try document root
    if (isset($_SERVER['DOCUMENT_ROOT']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/config.php')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
    } else {
        die('FATAL: config.php not found. Backend loader searched up 5 levels from ' . __DIR__);
    }
}
