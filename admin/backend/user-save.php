<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Detect if this is an AJAX request or a regular form submission
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$isAjax) {
    // Also detect fetch() calls — they send Accept: application/json or have no Referer form pattern
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false) {
        $isAjax = true;
    }
}

// For AJAX: return JSON. For form POST: redirect back with flash message.
if ($isAjax) {
    header('Content-Type: application/json');
}

/**
 * Respond to the client — handles both AJAX (JSON) and form (redirect) requests
 */
function respond($status, $message, $extra = []) {
    global $isAjax;
    $data = array_merge(['status' => $status, 'message' => $message], $extra);
    
    if ($isAjax) {
        echo json_encode($data);
        exit;
    }
    
    // Form submission — redirect back with message in query string
    // super-admin.php and users.php both read $_GET['success'] and $_GET['error']
    $referer = $_SERVER['HTTP_REFERER'] ?? '/admin/dashboards/super-admin.php?section=users';
    
    // Strip any existing success/error params from referer
    $referer = preg_replace('/[&?](success|error)=[^&]*/', '', $referer);
    
    // Add the appropriate param
    $separator = (strpos($referer, '?') !== false) ? '&' : '?';
    $param = ($status === 'success') ? 'success' : 'error';
    $redirect = $referer . $separator . $param . '=' . urlencode($message);
    
    // Force users section to be visible
    if (strpos($redirect, 'section=') === false) {
        $redirect .= '&section=users';
    }
    
    header('Location: ' . $redirect);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'Invalid request method');
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    respond('error', 'Unauthorized');
}

$currentRole = $_SESSION['admin_role'] ?? '';

require __DIR__ . '/config.php';

// CSRF protection — validate token from form or AJAX
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCsrf($csrfToken)) {
    respond('error', 'Security token expired. Please refresh the page and try again.');
}

// Collect inputs
$userId           = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$fullName         = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$username         = isset($_POST['username']) ? trim($_POST['username']) : '';
$email            = isset($_POST['email']) ? trim($_POST['email']) : '';
$role             = isset($_POST['role']) ? trim($_POST['role']) : '';
$password         = isset($_POST['password']) ? $_POST['password'] : '';
$confirmPassword  = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
$isActive         = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$memberId         = isset($_POST['member_id']) && $_POST['member_id'] !== '' ? (int)$_POST['member_id'] : null;

// Valid roles and who can create them
$validRoles = [
    'super_admin',
    'school_admin',
    'info_dept',
    'edu_dept',
    'finance_dept',
    'material_dept',
    'teacher',
    'attendance_taker',
];

// Role-based permissions for creating users
$rolePermissions = [
    'super_admin' => $validRoles, // Can create all
    'school_admin' => $validRoles, // Can create all
    'edu_dept' => ['teacher'], // Can only create teacher accounts
    'info_dept' => ['attendance_taker'], // Can only create attendance taker accounts
];

// Check if current user can create this role
$allowedRolesToCreate = $rolePermissions[$currentRole] ?? [];

if (!in_array($role, $allowedRolesToCreate)) {
    respond('error', 'You do not have permission to create this type of user account.');
}

// Basic validation
if ($fullName === '' || $username === '' || $role === '') {
    respond('error', 'Full name, username and role are required.');
}

// Username format validation
$usernameError = validateUsername($username);
if ($usernameError) {
    respond('error', $usernameError);
}

if (!in_array($role, $validRoles, true)) {
    respond('error', 'Invalid role selected.');
}

// Password rules
$isCreating = ($userId === 0);

if ($isCreating) {
    if ($password === '') {
        respond('error', 'Password is required for new users.');
    }
}

// Password strength validation
if ($password !== '') {
    $pwErrors = validatePassword($password);
    if (!empty($pwErrors)) {
        respond('error', implode(' ', $pwErrors));
    }
}

if ($password !== '' && $confirmPassword !== '' && $password !== $confirmPassword) {
    respond('error', 'Passwords do not match.');
}

if ($password !== '' && $confirmPassword !== '' && $password !== $confirmPassword) {
    
}

// Email: empty -> NULL
$emailDb = $email !== '' ? $email : null;

try {
    // Check uniqueness of username/email
    if ($emailDb !== null) {
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE (username = :username OR email = :email)
            " . ($userId > 0 ? "AND id != :id" : "") . "
            LIMIT 1
        ");
        $params = [':username' => $username, ':email' => $emailDb];
        if ($userId > 0) $params[':id'] = $userId;
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE username = :username
            " . ($userId > 0 ? "AND id != :id" : "") . "
            LIMIT 1
        ");
        $params = [':username' => $username];
        if ($userId > 0) $params[':id'] = $userId;
        $stmt->execute($params);
    }

    $existing = $stmt->fetch();
    if ($existing) {
        respond('error', 'Username or email already exists.');
    }

    if ($isCreating) {
        // Create new user
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, full_name, role, password_hash, is_active, member_id)
            VALUES (:username, :email, :full_name, :role, :password_hash, :is_active, :member_id)
        ");

        $stmt->execute([
            ':username'      => $username,
            ':email'         => $emailDb,
            ':full_name'     => $fullName,
            ':role'          => $role,
            ':password_hash' => $passwordHash,
            ':is_active'     => $isActive,
            ':member_id'     => $memberId,
        ]);

        $newUserId = $pdo->lastInsertId();

        respond('success', 'User created successfully.', ['user_id' => $newUserId]);

    } else {
        // Update existing user
        $fieldsSql = "
            full_name = :full_name,
            username  = :username,
            email     = :email,
            role      = :role,
            is_active = :is_active,
            member_id = :member_id
        ";

        $params = [
            ':full_name' => $fullName,
            ':username'  => $username,
            ':email'     => $emailDb,
            ':role'      => $role,
            ':is_active' => $isActive,
            ':member_id' => $memberId,
            ':id'        => $userId,
        ];

        if ($password !== '') {
            $fieldsSql .= ", password_hash = :password_hash";
            $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql = "UPDATE users SET {$fieldsSql} WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        respond('success', 'User updated successfully.');
    }

} catch (Exception $e) {
    error_log("User save error: " . $e->getMessage());
    respond('error', 'Error saving user. Please try again.');
}
