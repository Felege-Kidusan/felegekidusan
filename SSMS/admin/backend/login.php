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

// ============================================================
// RATE LIMITING (inline simple implementation)
// ============================================================
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$cacheDir = __DIR__ . '/../uploads/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

$rateFile = $cacheDir . '/rate_' . md5('login_' . $ip) . '.json';
$rateData = ['attempts' => 0, 'first_attempt' => time()];

if (file_exists($rateFile)) {
    $rateData = json_decode(file_get_contents($rateFile), true) ?: $rateData;
    // Reset if 5 minutes have passed
    if (time() - $rateData['first_attempt'] > 300) {
        $rateData = ['attempts' => 0, 'first_attempt' => time()];
    }
}

// Check if rate limited (5 attempts per 5 minutes)
if ($rateData['attempts'] >= 5) {
    header('Location: ../index.php?error=' . urlencode('Too many login attempts. Please wait 5 minutes.'));
    exit;
}

// Get inputs
$usernameOrEmail = isset($_POST['username']) ? trim($_POST['username']) : '';
$password        = isset($_POST['password']) ? $_POST['password'] : '';

// Basic validation
if ($usernameOrEmail === '' || $password === '') {
    header('Location: ../index.php?error=' . urlencode('Please fill in all fields.'));
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

    if (!$user) {
        // Record failed attempt
        $rateData['attempts']++;
        file_put_contents($rateFile, json_encode($rateData));
        header('Location: ../index.php?error=' . urlencode('Invalid username/email or password.'));
        exit;
    }

    if ((int)$user['is_active'] !== 1) {
        header('Location: ../index.php?error=' . urlencode('Your account is inactive. Contact the administrator.'));
        exit;
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        // Record failed attempt
        $rateData['attempts']++;
        file_put_contents($rateFile, json_encode($rateData));
        header('Location: ../index.php?error=' . urlencode('Invalid username/email or password.'));
        exit;
    }

    // Login success → clear rate limit and save info in session
    if (file_exists($rateFile)) @unlink($rateFile);
    
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id']        = $user['id'];
    $_SESSION['admin_username']  = $user['username'];
    $_SESSION['admin_role']      = $user['role'];
    $_SESSION['admin_full_name'] = $user['full_name'];
    $_SESSION['LAST_ACTIVITY']   = time();

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
