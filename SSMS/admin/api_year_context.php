<?php
/**
 * ============================================================
 * Academic Year VIEW context — time-travel (per-user, read-only)
 * ============================================================
 * Lets ANY logged-in user view a PAST academic year's data to prepare
 * reports, then return to the current year. This only changes what THIS
 * user sees — it never changes the system's active year and never touches
 * data. Writing while viewing the past is blocked by ay_require_writable().
 *
 *   POST action=set    year_id=<id>   → view that year (read-only)
 *   POST action=clear                 → return to the current (active) year
 *   GET/POST action=status            → current context (for the UI)
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$action = $_REQUEST['action'] ?? 'status';

// State-changing actions need CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['set', 'clear'], true)) {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($token)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

if ($action === 'set') {
    $yid = (int)($_POST['year_id'] ?? 0);
    $active = ay_active_year($conn);
    $activeId = $active ? (int)$active['id'] : 0;

    if ($yid <= 0)             { echo json_encode(['status' => 'error', 'message' => 'Year id required']); exit; }
    if ($yid === $activeId)    { unset($_SESSION['ay_view_year_id']); echo json_encode(['status' => 'success', 'message' => 'Now in the current year', 'is_readonly' => false]); exit; }

    $row = ay_year_by_id($conn, $yid);
    if (!$row) { echo json_encode(['status' => 'error', 'message' => 'That year does not exist']); exit; }

    $_SESSION['ay_view_year_id'] = $yid;
    echo json_encode([
        'status'      => 'success',
        'message'     => 'Viewing ' . ($row['year_name'] ?? 'past year') . ' (read-only)',
        'is_readonly' => true,
        'year_name'   => $row['year_name'] ?? ''
    ]);
    exit;
}

if ($action === 'clear') {
    unset($_SESSION['ay_view_year_id']);
    echo json_encode(['status' => 'success', 'message' => 'Returned to the current year', 'is_readonly' => false]);
    exit;
}

// status
$ctx = ay_resolve($conn);
echo json_encode([
    'status'      => 'success',
    'is_readonly' => $ctx['is_readonly'],
    'active_id'   => $ctx['active_id'],
    'viewing_id'  => $ctx['viewing_id'],
    'year_name'   => $ctx['year'] ? ($ctx['year']['year_name'] ?? '') : '',
    'years'       => ay_year_list($conn),
]);
