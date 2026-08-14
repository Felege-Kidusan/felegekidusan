<?php
// Education Department dashboard — wrapper that reuses info-dept.php UI
// This file is pure PHP (no closing to avoid accidental trailing HTML and parse errors.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Turn on full error reporting for diagnostics when this wrapper is used.
// (Remove or reduce in production after debugging.)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Buffer the HTML output of the Information Department dashboard
$info_path = __DIR__ . '/info-dept.php';
if (!file_exists($info_path)) {
    echo "<!-- DEBUG: info-dept.php not found at: $info_path -->\n";
}
ob_start();
include $info_path;
$html = ob_get_clean();

// Replace visible department labels to show "Education Department"
$search = [
    'Information Department',
    SCHOOL_NAME_SHORT_AM . ' ' . DEPT_INFO_NAME,
    'Information Dept',
    'Information Department Overview',
];
$replace = [
    'Education Department',
    'የትምህርት መምሪያ ክፍል',
    'Education Dept',
    'Education Department Overview',
];

$html = str_replace($search, $replace, $html);

// If buffering produced no output, include the file directly so any errors/warnings appear.
if (!is_string($html) || strlen(trim($html)) === 0) {
    // Minimal diagnostic to help debugging on the server
    echo "<!-- DEBUG: buffered output empty (length=" . strlen((string)$html) . ") -->\n";
    // Extra diagnostics: show whether $conn is available and session keys
    echo "<!-- DEBUG: conn set? " . (isset($conn) ? 'yes' : 'no') . " -->\n";
    echo "<!-- DEBUG: SESSION keys: " . (isset($_SESSION) ? e(json_encode(array_keys($_SESSION ?? []))) : 'none') . " -->\n";
    // Turn off output buffering and include directly to show runtime errors
    while (ob_get_level()) { ob_end_flush(); }
    include __DIR__ . '/info-dept.php';
    exit;
}

// Output and finish
echo $html;
exit;
 ?>