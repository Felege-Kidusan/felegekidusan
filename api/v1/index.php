<?php
/**
 * ============================================================
 * REST API v1 — Main Router
 * ============================================================
 * All requests to /api/v1/* come through here.
 * 
 * URL pattern: /api/v1/{resource}/{id?}/{action?}
 * Examples:
 *   GET  /api/v1/members           → routes/members.php
 *   GET  /api/v1/members/42        → routes/members.php (with $resourceId=42)
 *   POST /api/v1/auth/login        → routes/auth.php
 *   GET  /api/v1/dashboard/stats   → routes/dashboard.php
 * ============================================================
 */

// Smaller JSON on 2G/3G. Harmless if zlib is missing.
if (!headers_sent() && extension_loaded('zlib')) {
    ini_set('zlib.output_compression', 'On');
    ini_set('zlib.output_compression_level', '5');
}

// Load core modules
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/acl.php';
require_once __DIR__ . '/core/middleware.php';

// CORS + headers
handleCors();

// Parse the route — supports multiple approaches:
// 1. .htaccess rewrite: /api/v1/members → index.php?_route=members
// 2. Query param: /api/v1/index.php?path=members
// 3. PATH_INFO: /api/v1/index.php/members
$route = '';
if (!empty($_GET['_route'])) {
    $route = $_GET['_route'];
} elseif (!empty($_GET['path'])) {
    $route = $_GET['path'];
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $route = $_SERVER['PATH_INFO'];
}
$route = trim($route, '/');
$parts = explode('/', $route);
$resource = $parts[0] ?? '';
$resourceId = $parts[1] ?? null;
$subAction = $parts[2] ?? null;
$method = getMethod();

// Numeric IDs should be integers
if ($resourceId !== null && ctype_digit($resourceId)) {
    $resourceId = (int)$resourceId;
}

// API info endpoint
if ($route === '' || $route === 'ping') {
    ok([
        'api' => defined('API_NAME') ? API_NAME : 'School Management System',
        'version' => '1.0',
        'status' => 'running',
        'time' => date('c'),
        'database' => isset($conn) && !$conn->connect_error ? 'connected' : 'error'
    ]);
}

// ============================================================
// Route Map — each resource dispatches to its own file
// ============================================================
$routeMap = [
    'auth'          => 'auth.php',
    'members'       => 'members.php',
    'attendance'    => 'attendance.php',
    'classes'       => 'classes.php',
    'dashboard'     => 'dashboard.php',
    'teachers'      => 'teachers.php',
    'enrollment'    => 'enrollment.php',
    'subjects'      => 'subjects.php',
    'users'         => 'users.php',
    'grades'        => 'grades.php',
    'app'           => 'app.php',
];

// Check if resource exists
if (!isset($routeMap[$resource])) {
    err("Unknown endpoint: /{$resource}. Available: " . implode(', ', array_keys($routeMap)), 404);
}

// Load the route file
$routeFile = __DIR__ . '/routes/' . $routeMap[$resource];
if (!file_exists($routeFile)) {
    err("Endpoint /{$resource} is not yet implemented.", 501);
}

// Make route context available to route files
$ROUTE = [
    'resource'   => $resource,
    'id'         => $resourceId,
    'sub'        => $subAction,
    'method'     => $method,
    'parts'      => $parts,
    'full_route' => $route,
];

// Dispatch to route handler
require $routeFile;

// If the route file doesn't call ok() or err(), return 404
err("No handler matched for {$method} /{$route}", 404);
