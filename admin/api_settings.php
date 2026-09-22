<?php
/**
 * ============================================================
 * School Settings API
 * ============================================================
 * Handles:
 *   profile_get        - Get current user profile
 *   profile_update     - Update name, email, phone (email change requires
 *                        current password — step-up verification)
 *   password_change    - Change password (rate-limited, audits, revokes
 *                        mobile refresh families)
 *   account_activity   - THIS user's recent security events (audit trail)
 *   signout_devices    - Revoke all mobile refresh sessions for this user
 *   dept_get           - Get department settings
 *   dept_save          - Save department settings
 *   system_info        - System statistics
 *   clear_cache        - Clear upload cache
 *   member_code_format - Get/set member code format
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = $_SESSION['admin_role'] ?? '';
$action = $_REQUEST['action'] ?? '';

// CSRF protection for all POST requests
requireCsrfForPost();

// Credential APIs get the same fixed-window rate limiting as login
// (SecurityRateLimiter is NOT loaded by the admin bootstrap — api_mezmur.php
// documents this trap). Fails open for availability; the current-password
// proof still gates the underlying action.
if (!function_exists('wba_rate_limiter')) {
    function wba_rate_limiter(): \App\Services\SecurityRateLimiter
    {
        require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
        $pdo = $GLOBALS['pdo'] ?? null;
        $cacheDir = __DIR__ . '/uploads/cache';
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
        return new \App\Services\SecurityRateLimiter($pdo instanceof PDO ? $pdo : null, $cacheDir);
    }
}

/** True when users.phone exists (migration 047). Cached per request. */
if (!function_exists('wba_users_have_phone')) {
    function wba_users_have_phone(mysqli $conn): bool
    {
        static $hasPhone = null;
        if ($hasPhone !== null) { return $hasPhone; }
        try {
            $res = $conn->query("SELECT 1 FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE()
                                   AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone'");
            $hasPhone = (bool)($res && $res->num_rows > 0);
        } catch (Throwable $error) {
            $hasPhone = false;
        }
        return $hasPhone;
    }
}

/** Append one row to the audit trail; audit downtime never fails the action. */
if (!function_exists('wba_audit')) {
    function wba_audit(mysqli $conn, int $userId, string $username, string $action, string $details): void
    {
        try {
            $log = $conn->prepare(
                "INSERT INTO activity_logs (user_id, username, action, details, ip_address)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$log) { return; }
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $log->bind_param('issss', $userId, $username, $action, $details, $ip);
            $log->execute();
            $log->close();
        } catch (Throwable $error) {
            // Audit-table downtime must not prevent the account change.
        }
    }
}

// Settings schema is deployment-managed by migration 013.

try {
    switch ($action) {

        // ============================================================
        case 'profile_get':
        // ============================================================
            $phoneSelect = wba_users_have_phone($conn) ? ', phone' : '';
            $stmt = $conn->prepare("SELECT id, username, email, full_name, role, is_active, created_at, last_login{$phoneSelect} FROM users WHERE id = ?");
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'User not found']);
                break;
            }

            // Get login count from activity_logs
            $loginCount = 0;
            try {
                $logStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM activity_logs WHERE user_id = ? AND action = 'Login'");
                if ($logStmt) {
                    $logStmt->bind_param("i", $adminId);
                    $logStmt->execute();
                    $r = $logStmt->get_result();
                    if ($r) $loginCount = (int)$r->fetch_assoc()['cnt'];
                    $logStmt->close();
                }
            } catch (Exception $e) {}

            echo json_encode(['status' => 'success', 'user' => $user, 'login_count' => $loginCount]);
            break;

        // ============================================================
        case 'profile_update':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $fullName = trim($input['full_name'] ?? '');
            $email = trim($input['email'] ?? '');
            $phone = validatePhone($input['phone'] ?? null); // normalized or null
            $currentPwd = (string)($input['current_password'] ?? '');

            if (empty($fullName)) {
                echo json_encode(['status' => 'error', 'message' => 'Full name is required']);
                break;
            }

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
                break;
            }

            // Fetch the current row once — needed for the email step-up check
            // below and to detect which fields actually changed (audit).
            $stmt = $conn->prepare("SELECT username, email, full_name FROM users WHERE id = ?");
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$current) {
                echo json_encode(['status' => 'error', 'message' => 'User not found']);
                break;
            }

            // Step-up verification: the email address is the future recovery
            // channel, so changing it requires proving the current password
            // (same principle as Microsoft/Google sensitive-change re-auth).
            $emailChanging = !empty($email) && strcasecmp((string)($current['email'] ?? ''), $email) !== 0;
            if ($emailChanging) {
                if ($currentPwd === '') {
                    echo json_encode(['status' => 'error', 'message' => 'Enter your current password to change your email.']);
                    break;
                }
                $pwStmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
                $pwStmt->bind_param('i', $adminId);
                $pwStmt->execute();
                $pwRow = $pwStmt->get_result()->fetch_assoc();
                $pwStmt->close();
                if (!$pwRow || !password_verify($currentPwd, $pwRow['password_hash'])) {
                    // Generic message — no signal that the account exists.
                    echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect.']);
                    break;
                }
            }

            // Check email uniqueness (if changed)
            if (!empty($email)) {
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $chk->bind_param('si', $email, $adminId);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Email already in use by another account']);
                    $chk->close();
                    break;
                }
                $chk->close();
            }

            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
            $emailVal = !empty($email) ? $email : null;
            $stmt->bind_param('ssi', $fullName, $emailVal, $adminId);
            
            if ($stmt->execute()) {
                $_SESSION['admin_full_name'] = $fullName;

                // Optional phone column (migration 047) — feature-detected so
                // deployments without the migration keep working.
                if ($phone !== null && wba_users_have_phone($conn)) {
                    $phStmt = $conn->prepare("UPDATE users SET phone = ? WHERE id = ?");
                    $phStmt->bind_param('si', $phone, $adminId);
                    $phStmt->execute();
                    $phStmt->close();
                }

                // Audit which fields changed (names only — never values).
                $changed = [];
                if (strcasecmp((string)($current['full_name'] ?? ''), $fullName) !== 0) { $changed[] = 'full_name'; }
                if ($emailChanging) { $changed[] = 'email'; }
                if ($phone !== null) { $changed[] = 'phone'; }
                if ($changed !== []) {
                    wba_audit(
                        $conn,
                        $adminId,
                        (string)($_SESSION['admin_username'] ?? $current['username']),
                        'Profile Updated',
                        'Changed: ' . implode(', ', $changed) . ' (via settings)'
                    );
                }

                echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update profile']);
            }
            $stmt->close();
            break;

        // ============================================================
        case 'password_change':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $currentPwd = $input['current_password'] ?? '';
            $newPwd = $input['new_password'] ?? '';
            $confirmPwd = $input['confirm_password'] ?? '';

            // Same throttle profile as login: 5 attempts / 5 min per account
            // AND per IP, so neither spraying one account nor stuffing from
            // one machine slips through. Fails open for availability.
            try {
                $limiter = wba_rate_limiter();
                $ipLimit = $limiter->consume('pwd-change-ip', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 300);
                $acctLimit = $limiter->consume('pwd-change-account', 'user-' . $adminId, 5, 300);
                if (!$ipLimit['allowed'] || !$acctLimit['allowed']) {
                    $retryAfter = max((int)$ipLimit['retry_after'], (int)$acctLimit['retry_after']);
                    header('Retry-After: ' . max(1, $retryAfter));
                    http_response_code(429);
                    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Please wait a few minutes.']);
                    break;
                }
            } catch (Throwable $error) {
                // Limiter outage never blocks the (current-password-gated) change.
            }

            if (empty($currentPwd) || empty($newPwd) || empty($confirmPwd)) {
                echo json_encode(['status' => 'error', 'message' => 'All password fields are required']);
                break;
            }

            if ($newPwd !== $confirmPwd) {
                echo json_encode(['status' => 'error', 'message' => 'New passwords do not match']);
                break;
            }

            if (!is_string($currentPwd) || !is_string($newPwd) || !is_string($confirmPwd)
                || strlen($currentPwd) > 4096) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid password input']);
                break;
            }

            $passwordErrors = validatePassword($newPwd);
            if ($passwordErrors !== []) {
                echo json_encode(['status' => 'error', 'message' => implode(' ', $passwordErrors)]);
                break;
            }

            if ($currentPwd === $newPwd) {
                echo json_encode(['status' => 'error', 'message' => 'New password must be different from current']);
                break;
            }

            // Verify current password
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($currentPwd, $row['password_hash'])) {
                echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect']);
                break;
            }

            $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->bind_param('si', $newHash, $adminId);
            
            if ($stmt->execute()) {
                $_SESSION['AUTH_PASSWORD_VERSION'] = hash('sha256', $newHash);
                $_SESSION['AUTH_REVALIDATED_AT'] = time();
                session_regenerate_id(true);

                // Password changes revoke every mobile refresh family for this
                // account. Migration 010 may not be present during rollout.
                try {
                    $revoke = $conn->prepare(
                        'UPDATE api_refresh_sessions SET revoked_at=COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE user_id=?'
                    );
                    $revoke->bind_param('i', $adminId);
                    $revoke->execute();
                    $revoke->close();
                } catch (Throwable $error) {
                }

                try {
                    $log = $conn->prepare(
                        "INSERT INTO activity_logs (user_id, username, action, details, ip_address)
                         VALUES (?, ?, 'Password Change', 'Password changed via settings', ?)"
                    );
                    $username = (string)($_SESSION['admin_username'] ?? '');
                    $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
                    $log->bind_param('iss', $adminId, $username, $ipAddress);
                    $log->execute();
                    $log->close();
                } catch (Throwable $error) {
                }
                echo json_encode(['status' => 'success', 'message' => 'Password changed successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to change password']);
            }
            $stmt->close();
            break;

        // ============================================================
        case 'account_activity':
        // ============================================================
            // Security timeline: THIS user's own events only. Read-only.
            $stmt = $conn->prepare(
                "SELECT action, details, ip_address, created_at
                 FROM activity_logs WHERE user_id = ?
                 ORDER BY id DESC LIMIT 15"
            );
            if ($stmt) {
                $stmt->bind_param('i', $adminId);
                $stmt->execute();
                $res = $stmt->get_result();
                $events = [];
                while ($row = $res->fetch_assoc()) { $events[] = $row; }
                $stmt->close();
            } else {
                $events = [];
            }
            echo json_encode(['status' => 'success', 'events' => $events]);
            break;

        // ============================================================
        case 'signout_devices':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }
            // Revoke every mobile refresh-token family for this account.
            // Migration 010 may not be present during rollout — fail soft.
            $revoked = false;
            try {
                $revoke = $conn->prepare(
                    'UPDATE api_refresh_sessions
                     SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
                     WHERE user_id = ? AND revoked_at IS NULL'
                );
                $revoke->bind_param('i', $adminId);
                $revoke->execute();
                $revoked = ($revoke->affected_rows >= 0);
                $revoke->close();
            } catch (Throwable $error) {
                $revoked = false;
            }
            if ($revoked) {
                wba_audit($conn, $adminId, (string)($_SESSION['admin_username'] ?? ''), 'Device Signout', 'Revoked mobile sessions via account settings');
                echo json_encode(['status' => 'success', 'message' => 'All mobile devices signed out. They must sign in again.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Could not revoke device sessions.']);
            }
            break;

        // ============================================================
        case 'dept_get':
        // ============================================================
            $settings = [];
            $defaults = [
                'dept_name_en' => 'Information Department',
                'dept_name_am' => 'ማብራሪያ ክፍል',
                'church_name_en' => SCHOOL_TRANSLATION_EN . ' ' . SCHOOL_TYPE,
                'church_name_am' => SCHOOL_NAME_SHORT_AM . ' የ' . SCHOOL_TYPE_AM,
                'dept_description' => 'Manages member registration, ID cards, and member information.',
                'member_code_prefix' => '',
                'member_code_digits' => '4',
                'auto_generate_code' => '1',
                'default_age_group' => '',
                'default_member_type' => 'regular',
                'default_registration_type' => 'direct',
                'id_card_auto_generate' => '0',
                'phone_required' => '0',
                'guardian_required_under' => '14',
            ];

            try {
                $r = $conn->query("SELECT setting_key, setting_value FROM dept_settings");
                if ($r) {
                    while ($row = $r->fetch_assoc()) {
                        $settings[$row['setting_key']] = $row['setting_value'];
                    }
                }
            } catch (Exception $e) {}

            // Merge with defaults
            foreach ($defaults as $k => $v) {
                if (!isset($settings[$k])) $settings[$k] = $v;
            }

            echo json_encode(['status' => 'success', 'settings' => $settings]);
            break;

        // ============================================================
        case 'dept_save':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || empty($input)) {
                echo json_encode(['status' => 'error', 'message' => 'No settings provided']);
                break;
            }

            $allowed = [
                'dept_name_en', 'dept_name_am', 'church_name_en', 'church_name_am',
                'dept_description', 'member_code_prefix', 'member_code_digits',
                'auto_generate_code', 'default_age_group', 'default_member_type',
                'default_registration_type', 'id_card_auto_generate', 'phone_required',
                'guardian_required_under'
            ];

            $saved = 0;
            $stmt = $conn->prepare("INSERT INTO dept_settings (setting_key, setting_value, updated_by) 
                VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");

            foreach ($input as $key => $value) {
                if (!in_array($key, $allowed)) continue;
                $val = trim((string)$value);
                $stmt->bind_param('ssi', $key, $val, $adminId);
                $stmt->execute();
                $saved++;
            }
            $stmt->close();

            echo json_encode(['status' => 'success', 'message' => "$saved settings saved successfully"]);
            break;

        // ============================================================
        case 'system_info':
        // ============================================================
            $info = [];

            // Member counts
            $r = $conn->query("SELECT COUNT(*) as total, 
                COALESCE(SUM(status='active'),0) as active,
                COALESCE(SUM(status='archived'),0) as archived
                FROM members");
            $info['members'] = $r ? $r->fetch_assoc() : ['total' => 0, 'active' => 0, 'archived' => 0];

            // User accounts
            $r = $conn->query("SELECT COUNT(*) as total, COALESCE(SUM(is_active=1),0) as active FROM users");
            $info['users'] = $r ? $r->fetch_assoc() : ['total' => 0, 'active' => 0];

            // Database size estimate
            $r = $conn->query("SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb,
                SUM(TABLE_ROWS) as total_rows
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
            $info['database'] = $r ? $r->fetch_assoc() : ['size_mb' => '?', 'total_rows' => 0];

            // Tables count
            $r = $conn->query("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
            $info['tables'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

            // Cache size
            $cacheDir = __DIR__ . '/uploads/cache';
            $cacheSize = 0;
            $cacheFiles = 0;
            if (is_dir($cacheDir)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheDir)) as $file) {
                    if ($file->isFile()) { $cacheSize += $file->getSize(); $cacheFiles++; }
                }
            }
            $info['cache'] = ['files' => $cacheFiles, 'size_kb' => round($cacheSize / 1024, 1)];

            // Photos count
            $photoDir = __DIR__ . '/uploads/members/photos';
            $photoCount = 0;
            if (is_dir($photoDir)) {
                $photoCount = count(glob($photoDir . '/*.*'));
            }
            $info['photos'] = $photoCount;

            // PHP version
            $info['php_version'] = phpversion();
            $info['server'] = php_uname('s') . ' ' . php_uname('r');

            // Recent activity
            $recent = [];
            try {
                $r = $conn->query("SELECT action, username, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 5");
                if ($r) while ($row = $r->fetch_assoc()) $recent[] = $row;
            } catch (Exception $e) {}
            $info['recent_activity'] = $recent;

            echo json_encode(['status' => 'success', 'info' => $info]);
            break;

        // ============================================================
        case 'clear_cache':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }

            $cacheDir = __DIR__ . '/uploads/cache';
            $cleared = 0;
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '/*') as $file) {
                    if (is_file($file)) { @unlink($file); $cleared++; }
                }
            }

            echo json_encode(['status' => 'success', 'message' => "$cleared cache files cleared"]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

if (isset($conn) && $conn instanceof mysqli) $conn->close();
