<?php
/**
 * Login Processing Script
 * Handles admin login authentication with rate limiting
 */

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

// Load main config (DB credentials, helpers)
require_once __DIR__ . '/../config.php';

// Use the PDO connection from config.php
if (!isset($pdo) || $pdo === null) {
    error_log("Login: PDO connection not available from config.php");
    header('Location: ../index.php?error=' . urlencode('Database connection error. Please try again.'));
    exit;
}

// ============================================================
// CSRF VALIDATION (inline to avoid config dependency)
// ============================================================
$csrfToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
    header('Location: ../index.php?error=' . urlencode('Security token expired. Please try again.'));
    exit;
}

// Get and bound inputs before any password hashing work.
$usernameOrEmail = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
$password = (string)($_POST['password'] ?? '');
if ($usernameOrEmail === '' || $password === '') {
    header('Location: ../index.php?error=' . urlencode('Please fill in all fields.'));
    exit;
}
if (strlen($usernameOrEmail) > 254 || strlen($password) > 4096) {
    header('Location: ../index.php?error=' . urlencode('Invalid username/email or password.'));
    exit;
}

// Atomic IP + account throttles work across web nodes. The service uses a
// locked compatibility fallback while migration 008 is being deployed.
require_once __DIR__ . '/services/SecurityRateLimiter.php';
$rateLimiter = new \App\Services\SecurityRateLimiter($pdo, __DIR__ . '/../uploads/cache');
$ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$accountSubject = strtolower($usernameOrEmail);
$ipLimit = $rateLimiter->consume('admin-login-ip', $ipAddress, 20, 300);
$accountLimit = $rateLimiter->consume('admin-login-account', $accountSubject, 5, 300);
if (!$ipLimit['allowed'] || !$accountLimit['allowed']) {
    $retryAfter = max((int)$ipLimit['retry_after'], (int)$accountLimit['retry_after']);
    header('Retry-After: ' . max(1, $retryAfter));
    header('Location: ../index.php?error=' . urlencode('Too many login attempts. Please wait 5 minutes.'));
    exit;
}

try {
    // Find user by username OR email
    $stmt = $pdo->prepare("
        SELECT id, username, email, full_name, role, password_hash, is_active
        FROM users
        WHERE username = :ue1 OR email = :ue2
        LIMIT 1
    ");
    $stmt->execute([':ue1' => $usernameOrEmail, ':ue2' => $usernameOrEmail]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        header('Location: ../index.php?error=' . urlencode('Invalid username/email or password.'));
        exit;
    }

    if ((int)$user['is_active'] !== 1) {
        header('Location: ../index.php?error=' . urlencode('Your account is inactive. Contact the administrator.'));
        exit;
    }

    // Clear the account bucket after a valid login. The IP bucket remains so
    // one known credential cannot reset protection against credential stuffing.
    $rateLimiter->clear('admin-login-account', $accountSubject);
    
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id']        = $user['id'];
    $_SESSION['admin_username']  = $user['username'];
    $_SESSION['admin_role']      = $user['role'];
    $_SESSION['admin_full_name'] = $user['full_name'];
    $_SESSION['LAST_ACTIVITY']   = time();
    $_SESSION['AUTH_STARTED_AT'] = time();
    $_SESSION['AUTH_REVALIDATED_AT'] = time();
    $_SESSION['AUTH_PASSWORD_VERSION'] = hash('sha256', (string)$user['password_hash']);

    // Log successful login
    try {
        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'Login', 'Successful login', ?, ?)");
        $logStmt->execute([
            $user['id'], 
            $user['username'], 
            $_SERVER['REMOTE_ADDR'] ?? '', 
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    } catch (Exception $e) {
        // Ignore logging errors
    }

    // Go to dashboard
    header('Location: ../dashboard.php');
    exit;

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log("Login DB Error: " . $e->getMessage());
    header('Location: ../index.php?error=' . urlencode('Database connection error. Please try again.'));
    exit;
} catch (Exception $e) {
    // Log the actual error for debugging
    error_log("Login Error: " . $e->getMessage());
    header('Location: ../index.php?error=' . urlencode('Something went wrong. Please try again.'));
    exit;
}
