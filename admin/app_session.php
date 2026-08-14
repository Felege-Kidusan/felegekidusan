<?php
/**
 * School App Session Bridge
 * Accepts a mobile API token and creates a PHP session
 * so web dashboard pages work inside the mobile app
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
// CORS: Mobile apps don't send Origin, browsers do — restrict browser access
$_sessOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_sessAllowed = get_cors_origins();
if (in_array($_sessOrigin, $_sessAllowed)) {
    header('Access-Control-Allow-Origin: ' . $_sessOrigin);
    header('Vary: Origin');
} elseif ($_sessOrigin === '') {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if (!$token || strpos($token, '.') === false) {
    echo json_encode(['status' => 'error', 'message' => 'Token required']);
    exit;
}

// Verify token (same secret as api_mobile.php)
$secret = defined('JWT_SECRET') ? JWT_SECRET : EXPORT_PREFIX . '_mobile_' . DB_NAME . '_' . DB_HOST;
$parts = explode('.', $token, 2);
if (count($parts) !== 2) { echo json_encode(['status' => 'error']); exit; }
if (!hash_equals(hash_hmac('sha256', $parts[0], $secret), $parts[1])) { echo json_encode(['status' => 'error', 'message' => 'Invalid token']); exit; }
$payload = json_decode(base64_decode($parts[0]), true);
if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) { echo json_encode(['status' => 'error', 'message' => 'Token expired']); exit; }

// Look up user
$userId = (int)($payload['uid'] ?? 0);
$stmt = $conn->prepare("SELECT id, username, full_name, role, is_active FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { echo json_encode(['status' => 'error', 'message' => 'User not found']); exit; }

// Create web session
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = (int)$user['id'];
$_SESSION['admin_username'] = $user['username'];
$_SESSION['admin_role'] = $user['role'];
$_SESSION['admin_full_name'] = $user['full_name'];
$_SESSION['LAST_ACTIVITY'] = time();

echo json_encode(['status' => 'success', 'message' => 'Session created', 'role' => $user['role']]);
