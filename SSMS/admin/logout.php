<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Clear all session variables
$_SESSION = [];

// If using cookies, delete the session cookie too
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Redirect back to login with a success message
header('Location: index.php?success=' . urlencode('You have been logged out.'));
exit;
