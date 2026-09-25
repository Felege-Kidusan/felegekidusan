<?php
/**
 * Shared browser login handler, including /backend/auth/login.php.
 * HTML forms receive same-origin redirects; fetch clients receive JSON.
 */
require_once __DIR__ . '/../../backend/core/browser.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . ssms_app_url('admin/index.php'));
    exit;
}

// Start the session only through config's hardened bootstrap.
require_once __DIR__ . '/../config.php';

function loginResponse(bool $success, string $message, int $code = 200): void
{
    $target = ssms_app_url($success ? 'admin/dashboard.php' : 'admin/index.php');
    header('Cache-Control: no-store');
    if (_isAjaxRequest()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => $success ? 'success' : 'error',
            'message' => $message,
            'redirect' => $target,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Preserve the established form-post 302 contract.
        header('Location: ' . $target . ($success ? '' : '?error=' . rawurlencode($message)));
    }
    exit;
}

if (!($pdo instanceof PDO)) {
    error_log('Login: PDO connection not available from config.php');
    loginResponse(false, 'Database connection error. Please try again.', 503);
}

if (!validateCsrf($_POST['csrf_token'] ?? '')) {
    loginResponse(false, 'Security token expired. Please try again.', 403);
}

// Reject arrays before casts, trim(), hash_equals() or password_verify().
if (!is_string($_POST['username'] ?? '') || !is_string($_POST['password'] ?? '')) {
    loginResponse(false, 'Invalid username/email or password.', 422);
}
$usernameOrEmail = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
if ($usernameOrEmail === '' || $password === '') {
    loginResponse(false, 'Please fill in all fields.', 422);
}
if (strlen($usernameOrEmail) > 254 || strlen($password) > 4096) {
    loginResponse(false, 'Invalid username/email or password.', 422);
}

require_once __DIR__ . '/services/SecurityRateLimiter.php';
$rateLimiter = new \App\Services\SecurityRateLimiter($pdo, __DIR__ . '/../uploads/cache');
$ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$accountSubject = strtolower($usernameOrEmail);
$ipLimit = $rateLimiter->consume('admin-login-ip', $ipAddress, 20, 300);
$accountLimit = $rateLimiter->consume('admin-login-account', $accountSubject, 5, 300);
if (!$ipLimit['allowed'] || !$accountLimit['allowed']) {
    $retryAfter = max((int)$ipLimit['retry_after'], (int)$accountLimit['retry_after']);
    header('Retry-After: ' . max(1, $retryAfter));
    loginResponse(false, 'Too many login attempts. Please wait 5 minutes.', 429);
}

try {
    $stmt = $pdo->prepare('
        SELECT id, username, email, full_name, role, password_hash, is_active
        FROM users WHERE username = :ue1 OR email = :ue2 LIMIT 1
    ');
    $stmt->execute([':ue1' => $usernameOrEmail, ':ue2' => $usernameOrEmail]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        loginResponse(false, 'Invalid username/email or password.', 401);
    }
    if ((int)$user['is_active'] !== 1) {
        loginResponse(false, 'Your account is inactive. Contact the administrator.', 403);
    }

    $rateLimiter->clear('admin-login-account', $accountSubject);
    session_regenerate_id(true);
    // A fresh login must not inherit a previous user's impersonation or year
    // selection, even if another tab submitted the login form.
    $_SESSION = [];
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id']        = $user['id'];
    $_SESSION['admin_username']  = $user['username'];
    $_SESSION['admin_role']      = $user['role'];
    $_SESSION['admin_full_name'] = $user['full_name'];
    $_SESSION['LAST_ACTIVITY']   = time();
    $_SESSION['AUTH_STARTED_AT'] = time();
    $_SESSION['AUTH_REVALIDATED_AT'] = time();
    $_SESSION['AUTH_PASSWORD_VERSION'] = hash('sha256', (string)$user['password_hash']);
    // Random per-login browser context used only to reject stale account pages.
    // It never selects or authorizes an account; admin_id remains authoritative.
    $_SESSION['PROFILE_ACCOUNT_CONTEXT'] = bin2hex(random_bytes(32));
    generateCsrfToken();

    try {
        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'Login', 'Successful login', ?, ?)");
        $logStmt->execute([
            $user['id'], $user['username'], $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Exception $e) {
        // Audit-table downtime must not prevent login.
    }

    loginResponse(true, 'Signed in.');
} catch (PDOException $e) {
    reportInternalError('Login database failure', $e);
    loginResponse(false, 'Database connection error. Please try again.', 503);
} catch (Throwable $e) {
    reportInternalError('Login failure', $e);
    loginResponse(false, 'Something went wrong. Please try again.', 500);
}
