<?php
/**
 * ============================================================
 * Backend Bootstrap API — /backend/api/init.php
 * ============================================================
 * 
 * Returns all data needed to initialize a dashboard page.
 * Called by JavaScript on page load instead of embedding
 * PHP data in the HTML.
 * 
 * GET  /backend/api/init.php              → user + school info
 * GET  /backend/api/init.php?stats=1      → + dashboard stats
 * GET  /backend/api/init.php?members=1    → + member count
 * ============================================================
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');

// Must be logged in
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$theme = defined('ACTIVE_THEME') ? ACTIVE_THEME : 'wbss';
$themeBase = '/themes/' . $theme;

$response = [
    'status' => 'success',
    'user' => [
        'id'       => (int)($_SESSION['admin_id'] ?? 0),
        'name'     => $_SESSION['admin_full_name'] ?? '',
        'username' => $_SESSION['admin_username'] ?? '',
        'role'     => $_SESSION['admin_role'] ?? '',
    ],
    'school' => [
        'name'        => defined('SCHOOL_NAME') ? SCHOOL_NAME : '',
        'nameShort'   => defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : '',
        'nameAm'      => defined('SCHOOL_NAME_AMHARIC') ? SCHOOL_NAME_AMHARIC : '',
        'nameShortAm' => defined('SCHOOL_NAME_SHORT_AM') ? SCHOOL_NAME_SHORT_AM : '',
        'tagline'     => defined('SCHOOL_TAGLINE') ? SCHOOL_TAGLINE : '',
        'memberPrefix' => defined('MEMBER_CODE_PREFIX') ? MEMBER_CODE_PREFIX : 'WB',
        'adminTitle'  => defined('ADMIN_PANEL_TITLE') ? ADMIN_PANEL_TITLE : '',
        'logo'        => $themeBase . '/assets/logos/school_logo.png',
        'seal'        => $themeBase . '/assets/seals/school_seal.png',
    ],
    'theme'  => $theme,
    'csrf'   => function_exists('generateCsrfToken') ? generateCsrfToken() : '',
    'impersonating' => !empty($_SESSION['original_admin_role']),
];

// Ethiopian date
if (file_exists(ROOT_PATH . '/admin/backend/ethiopian_date.php')) {
    require_once ROOT_PATH . '/admin/backend/ethiopian_date.php';
    if (function_exists('ethio_date_format')) {
        $today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
        $response['today_eth'] = ethio_date_format($today, 'F j, Y');
    }
}

// Optional: include quick stats
if (isset($_GET['stats']) && isset($conn) && !$conn->connect_error) {
    $stats = ['members' => 0, 'active' => 0, 'users' => 0];
    try {
        $r = $conn->query("SELECT COUNT(*) as t, SUM(status='active') as a FROM members WHERE status!='archived'");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['members'] = (int)($row['t'] ?? 0);
            $stats['active']  = (int)($row['a'] ?? 0);
        }
        $r = $conn->query("SELECT COUNT(*) as t FROM users WHERE is_active=1");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['users'] = (int)($row['t'] ?? 0);
        }
    } catch (Exception $e) {}
    $response['stats'] = $stats;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
