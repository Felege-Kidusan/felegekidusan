<?php
/**
 * ============================================================
 * School Settings API
 * ============================================================
 * Handles:
 *   profile_get        - Get current user profile
 *   profile_update     - Update name, email
 *   password_change    - Change password
 *   dept_get           - Get department settings
 *   dept_save          - Save department settings
 *   system_info        - System statistics
 *   clear_cache        - Clear upload cache
 *   member_code_format - Get/set member code format
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/ProfileService.php';
require_once __DIR__ . '/backend/services/AccountCredentialService.php';
require_once __DIR__ . '/backend/services/ProfileImageService.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = $_SESSION['admin_role'] ?? '';
$action = is_string($_REQUEST['action'] ?? null) ? (string)$_REQUEST['action'] : '';

// Opaque per-login context. This is a stale-page precondition only: ownership
// always remains derived from the authenticated PHP session.
$settingsAccountContext = $_SESSION['PROFILE_ACCOUNT_CONTEXT'] ?? '';
if (!is_string($settingsAccountContext)
    || preg_match('/^[a-f0-9]{64}$/D', $settingsAccountContext) !== 1) {
    $settingsAccountContext = bin2hex(random_bytes(32));
    $_SESSION['PROFILE_ACCOUNT_CONTEXT'] = $settingsAccountContext;
}

// Reject a mutation dispatched by a page from an older login before evaluating
// that page's now-stale CSRF token. The context cannot select an account.
$profileMutationActions = [
    'profile_update', 'password_change', 'profile_image_upload', 'profile_image_remove',
];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array($action, $profileMutationActions, true)) {
    $submittedContext = $_SERVER['HTTP_X_ACCOUNT_CONTEXT'] ?? '';
    if (!is_string($submittedContext)
        || !hash_equals($settingsAccountContext, $submittedContext)) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'The authenticated account context changed. Reload account data.',
            'code' => 'ACCOUNT_CONTEXT_CHANGED',
        ]);
        exit;
    }
}

// CSRF protection for all POST requests
requireCsrfForPost();

$settingsIdentity = \App\Services\AuthenticatedProfileIdentity::fromTrustedUserId($adminId);
$settingsImageSchemaReady = \App\Services\MysqliProfileRepository::profileImageColumnAvailable($conn);
$settingsProfileService = new \App\Services\ProfileService(
    new \App\Services\MysqliProfileRepository($conn, $settingsImageSchemaReady)
);
$settingsAuditActor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
    $adminId,
    (string)($_SESSION['admin_username'] ?? ''),
    'web',
    $_SERVER
);

/** @return array<string,mixed> */
function settingsJsonBody(): array
{
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : $_POST;
}

/** @param array<string,mixed> $profile @return array<string,mixed> */
function settingsDecorateProfile(array $profile): array
{
    if (!empty($profile['profile_image']['present'])) {
        $profile['profile_image']['url'] = function_exists('ssms_app_url')
            ? ssms_app_url('admin/profile_image.php')
            : '/admin/profile_image.php';
    }
    return $profile;
}

function settingsFail(string $message, int $status, string $code, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'status' => 'error',
        'message' => $message,
        'code' => $code,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/** @param array<int,array{action:string,subject:string,limit:int,window:int}> $rules */
function settingsEnforceRateLimits(array $rules): void
{
    global $pdo;
    $limiter = new \App\Services\SecurityRateLimiter(
        $pdo instanceof \PDO ? $pdo : null,
        ROOT_PATH . '/admin/uploads/cache'
    );
    $blocked = false;
    $retryAfter = 1;
    foreach ($rules as $rule) {
        $result = $limiter->consume(
            $rule['action'],
            $rule['subject'],
            $rule['limit'],
            $rule['window']
        );
        if (!$result['allowed']) {
            $blocked = true;
            $retryAfter = max($retryAfter, (int)$result['retry_after']);
        }
    }
    if ($blocked) {
        header('Retry-After: ' . $retryAfter);
        settingsFail('Too many requests. Please try again later.', 429, 'RATE_LIMITED', [
            'retry_after' => $retryAfter,
        ]);
    }
}

/** @param array<string,mixed> $before @param array<string,mixed> $after */
function settingsAuditProfileChanges(
    \mysqli $conn,
    \App\Services\SecurityAuditActor $actor,
    int $userId,
    array $before,
    array $after
): void {
    if (!hash_equals((string)$before['username'], (string)$after['username'])) {
        \App\Services\SecurityAuditService::recordTrusted(
            $conn,
            $actor,
            'PROFILE_USERNAME_CHANGED',
            [
                'old_username' => (string)$before['username'],
                'new_username' => (string)$after['username'],
            ],
            'user',
            $userId
        );
    }
    if (($before['email'] ?? null) !== ($after['email'] ?? null)) {
        \App\Services\SecurityAuditService::recordTrusted(
            $conn,
            $actor,
            'PROFILE_EMAIL_CHANGED',
            ['operation' => 'email_changed'],
            'user',
            $userId
        );
    }
    if (!hash_equals((string)$before['full_name'], (string)$after['full_name'])) {
        \App\Services\SecurityAuditService::recordTrusted(
            $conn,
            $actor,
            'PROFILE_FULL_NAME_CHANGED',
            ['operation' => 'full_name_changed'],
            'user',
            $userId
        );
    }
}

function settingsProfileError(\Throwable $error): void
{
    if ($error instanceof \App\Services\ProfileDomainException) {
        $reason = $error->reason();
        $status = in_array($reason, ['PROFILE_CONFLICT', 'USERNAME_TAKEN', 'EMAIL_TAKEN', 'PROFILE_DUPLICATE'], true)
            ? 409
            : ($reason === 'USER_NOT_FOUND' ? 404 : 422);
        $code = in_array($reason, ['PROFILE_CONFLICT', 'USERNAME_TAKEN', 'EMAIL_TAKEN', 'CURRENT_PASSWORD_INCORRECT'], true)
            ? $reason
            : 'VALIDATION_FAILED';
        $messages = [
            'PROFILE_CONFLICT' => 'The profile changed. Reload and try again.',
            'USERNAME_TAKEN' => 'That username is already in use.',
            'EMAIL_TAKEN' => 'That email address is already in use.',
            'CURRENT_PASSWORD_INCORRECT' => 'Current password is incorrect.',
            'USER_NOT_FOUND' => 'User not found.',
        ];
        settingsFail(
            $messages[$reason] ?? 'Profile input was rejected.',
            $status,
            $code,
            ['reason' => $reason]
        );
    }
    reportInternalError('Web profile operation failed', $error);
    settingsFail('Profile service is temporarily unavailable.', 503, 'PROFILE_SERVICE_UNAVAILABLE');
}

function settingsCredentialError(\Throwable $error): void
{
    if ($error instanceof \App\Services\CredentialDomainException) {
        $reason = $error->reason();
        $extra = $reason === 'PASSWORD_POLICY_FAILED'
            ? ['errors' => $error->policyErrors()]
            : [];
        $messages = [
            'CURRENT_PASSWORD_INCORRECT' => 'Current password is incorrect.',
            'PASSWORD_CONFIRMATION_MISMATCH' => 'New passwords do not match.',
            'NEW_PASSWORD_MUST_DIFFER' => 'New password must be different from the current password.',
            'PASSWORD_POLICY_FAILED' => 'The new password does not meet the password policy.',
            'USER_NOT_FOUND' => 'User not found.',
        ];
        settingsFail(
            $messages[$reason] ?? 'Password input was rejected.',
            422,
            $reason,
            $extra
        );
    }
    reportInternalError('Web credential operation failed', $error);
    settingsFail('Password could not be changed. Please try again.', 503, 'CREDENTIAL_UPDATE_UNAVAILABLE');
}

function settingsImageError(\Throwable $error): void
{
    if ($error instanceof \App\Services\ProfileImageDomainException) {
        $reason = $error->reason();
        $map = [
            'IMAGE_SIZE_INVALID' => [413, 'IMAGE_TOO_LARGE', 'Image exceeds the allowed size.'],
            'IMAGE_TYPE_INVALID' => [415, 'UNSUPPORTED_IMAGE', 'Image type is not supported.'],
            'PROFILE_CONFLICT' => [409, 'PROFILE_CONFLICT', 'The profile changed. Reload and try again.'],
            'PROFILE_IMAGE_NOT_SET' => [404, 'PROFILE_IMAGE_NOT_SET', 'No profile image is set.'],
        ];
        [$status, $code, $message] = $map[$reason]
            ?? [422, 'INVALID_IMAGE', 'Image input was rejected.'];
        settingsFail(
            $message,
            $status,
            $code,
            $reason === 'PROFILE_CONFLICT' ? ['reload_required' => true] : []
        );
    }
    reportInternalError('Web profile image operation failed', $error);
    settingsFail('Profile image storage is temporarily unavailable.', 503, 'STORAGE_UNAVAILABLE');
}

function settingsImageService(\mysqli $conn, bool $schemaReady): \App\Services\ProfileImageService
{
    if (!$schemaReady) {
        settingsFail('Profile image storage is not available yet.', 503, 'STORAGE_UNAVAILABLE');
    }
    return new \App\Services\ProfileImageService(
        new \App\Services\MysqliProfileImageRepository($conn),
        \App\Services\PrivateProfileImageStorage::configured()
    );
}

// Settings schema is deployment-managed by migration 013.

try {
    switch ($action) {

        // ============================================================
        case 'profile_get':
        // ============================================================
            try {
                $user = settingsDecorateProfile(
                    $settingsProfileService->getOwnProfile($settingsIdentity)
                );
            } catch (\Throwable $error) {
                settingsProfileError($error);
            }

            // Preserve the established settings response extension.
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

            echo json_encode([
                'status' => 'success',
                'user' => $user,
                'login_count' => $loginCount,
                'account_context' => $settingsAccountContext,
                'csrf_token' => generateCsrfToken(),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================================
        case 'profile_update':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                settingsFail('POST required', 405, 'METHOD_NOT_ALLOWED');
            }
            $input = settingsJsonBody();
            $profileIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            settingsEnforceRateLimits([
                ['action' => 'web-profile-update-user', 'subject' => 'user:' . $adminId, 'limit' => 20, 'window' => 900],
                ['action' => 'web-profile-update-ip', 'subject' => 'ip:' . $profileIp, 'limit' => 60, 'window' => 900],
            ]);
            if (array_key_exists('username', $input)) {
                settingsEnforceRateLimits([
                    ['action' => 'web-profile-username-user', 'subject' => 'user:' . $adminId, 'limit' => 5, 'window' => 86400],
                    ['action' => 'web-profile-username-ip', 'subject' => 'ip:' . $profileIp, 'limit' => 20, 'window' => 86400],
                ]);
            }
            try {
                $before = $settingsProfileService->getOwnProfile($settingsIdentity);
                // Existing web UI predates optimistic versions. It is allowed
                // to use the just-read version until Phase 3 sends it directly.
                $expectedVersion = is_string($input['profile_version'] ?? null)
                    && $input['profile_version'] !== ''
                    ? $input['profile_version']
                    : $before['profile_version'];
                $updated = $settingsProfileService->updateOwnProfile(
                    $settingsIdentity,
                    $input,
                    $expectedVersion
                );
                settingsAuditProfileChanges(
                    $conn,
                    $settingsAuditActor,
                    $adminId,
                    $before,
                    $updated
                );
                $usernameChanged = !hash_equals(
                    (string)$before['username'],
                    (string)$updated['username']
                );
                $_SESSION['admin_username'] = (string)$updated['username'];
                $_SESSION['admin_full_name'] = (string)$updated['full_name'];
                $_SESSION['AUTH_REVALIDATED_AT'] = time();
                if ($usernameChanged) {
                    session_regenerate_id(true);
                }
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Profile updated successfully',
                    'user' => settingsDecorateProfile($updated),
                    'claims_refresh_required' => false,
                    'account_context' => $settingsAccountContext,
                ], JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $error) {
                settingsProfileError($error);
            }
            break;

        // ============================================================
        case 'password_change':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                settingsFail('POST required', 405, 'METHOD_NOT_ALLOWED');
            }
            $input = settingsJsonBody();
            foreach (['current_password', 'new_password', 'confirm_password'] as $field) {
                if (!is_string($input[$field] ?? null)) {
                    settingsFail('Invalid password input', 422, 'VALIDATION_FAILED');
                }
            }
            settingsEnforceRateLimits([
                ['action' => 'web-profile-password-user', 'subject' => 'user:' . $adminId, 'limit' => 5, 'window' => 900],
                [
                    'action' => 'web-profile-password-ip',
                    'subject' => 'ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    'limit' => 5,
                    'window' => 900,
                ],
            ]);
            try {
                $credentialService = new \App\Services\AccountCredentialService(
                    new \App\Services\MysqliCredentialRepository($conn)
                );
                $result = $credentialService->changeOwnPassword(
                    $settingsIdentity,
                    $input['current_password'],
                    $input['new_password'],
                    $input['confirm_password']
                );
                $_SESSION['AUTH_PASSWORD_VERSION'] = $result->passwordVersion();
                $_SESSION['AUTH_REVALIDATED_AT'] = time();
                session_regenerate_id(true);
                \App\Services\SecurityAuditService::recordTrusted(
                    $conn,
                    $settingsAuditActor,
                    'PASSWORD_CHANGED',
                    ['operation' => 'self_service_password_change'],
                    'user',
                    $adminId
                );
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Password changed successfully',
                    'account_context' => $settingsAccountContext,
                ]);
            } catch (\Throwable $error) {
                settingsCredentialError($error);
            }
            break;

        // ============================================================
        case 'profile_image_upload':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                settingsFail('POST required', 405, 'METHOD_NOT_ALLOWED');
            }
            foreach (['user_id', 'id', 'owner_id', 'username'] as $ownerField) {
                if (array_key_exists($ownerField, $_REQUEST)) {
                    settingsFail('User ownership is derived from the session.', 422, 'VALIDATION_FAILED');
                }
            }
            foreach (array_keys($_POST) as $field) {
                if (!in_array($field, ['action', 'csrf_token', 'profile_version'], true)) {
                    settingsFail('Unsupported image-upload field.', 422, 'VALIDATION_FAILED');
                }
            }
            settingsEnforceRateLimits([
                ['action' => 'web-profile-image-user', 'subject' => 'user:' . $adminId, 'limit' => 10, 'window' => 3600],
                [
                    'action' => 'web-profile-image-ip',
                    'subject' => 'ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    'limit' => 30,
                    'window' => 3600,
                ],
            ]);
            $profileVersion = $_POST['profile_version'] ?? '';
            if (!is_string($profileVersion) || $profileVersion === '') {
                settingsFail('Profile version is required.', 422, 'VALIDATION_FAILED');
            }
            if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
                settingsFail('Image is required.', 422, 'INVALID_IMAGE');
            }
            try {
                $imageService = settingsImageService($conn, $settingsImageSchemaReady);
                $artifact = \App\Services\ProfileImageService::prepareRequestUpload($_FILES['image']);
                $result = $imageService->replaceOwnImage(
                    $settingsIdentity,
                    $artifact,
                    $profileVersion
                );
                $event = ($result['audit_action'] ?? '') === 'Profile Image Uploaded'
                    ? 'PROFILE_IMAGE_UPLOADED'
                    : 'PROFILE_IMAGE_REPLACED';
                \App\Services\SecurityAuditService::recordTrusted(
                    $conn,
                    $settingsAuditActor,
                    $event,
                    ['operation' => strtolower(substr($event, strlen('PROFILE_IMAGE_')))],
                    'user',
                    $adminId
                );
                $result['profile_image']['url'] = function_exists('ssms_app_url')
                    ? ssms_app_url('admin/profile_image.php')
                    : '/admin/profile_image.php';
                unset($result['audit_action'], $result['actor_user_id'], $result['target_user_id']);
                echo json_encode([
                    'status' => 'success',
                    'data' => $result,
                    'account_context' => $settingsAccountContext,
                ], JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $error) {
                settingsImageError($error);
            }
            break;

        // ============================================================
        case 'profile_image_remove':
        // ============================================================
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                settingsFail('POST required', 405, 'METHOD_NOT_ALLOWED');
            }
            $input = settingsJsonBody();
            foreach (['user_id', 'id', 'owner_id', 'username'] as $ownerField) {
                if (array_key_exists($ownerField, $input)) {
                    settingsFail('User ownership is derived from the session.', 422, 'VALIDATION_FAILED');
                }
            }
            foreach (array_keys($input) as $field) {
                if (!in_array($field, ['action', 'csrf_token', 'profile_version'], true)) {
                    settingsFail('Unsupported image-removal field.', 422, 'VALIDATION_FAILED');
                }
            }
            $profileVersion = $input['profile_version'] ?? '';
            if (!is_string($profileVersion) || $profileVersion === '') {
                settingsFail('Profile version is required.', 422, 'VALIDATION_FAILED');
            }
            settingsEnforceRateLimits([
                ['action' => 'web-profile-image-user', 'subject' => 'user:' . $adminId, 'limit' => 10, 'window' => 3600],
                [
                    'action' => 'web-profile-image-ip',
                    'subject' => 'ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    'limit' => 30,
                    'window' => 3600,
                ],
            ]);
            try {
                $imageService = settingsImageService($conn, $settingsImageSchemaReady);
                $result = $imageService->removeOwnImage(
                    $settingsIdentity,
                    $profileVersion
                );
                \App\Services\SecurityAuditService::recordTrusted(
                    $conn,
                    $settingsAuditActor,
                    'PROFILE_IMAGE_REMOVED',
                    ['operation' => 'removed'],
                    'user',
                    $adminId
                );
                unset($result['audit_action'], $result['actor_user_id'], $result['target_user_id']);
                echo json_encode([
                    'status' => 'success',
                    'data' => $result,
                    'account_context' => $settingsAccountContext,
                ], JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $error) {
                settingsImageError($error);
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
