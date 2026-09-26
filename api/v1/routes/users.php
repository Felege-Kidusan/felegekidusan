<?php
/**
 * School API v1 — authenticated self-service profile routes.
 *
 * GET    /users/me
 * PATCH  /users/me
 * GET    /users/me/profile-image
 * POST   /users/me/profile-image
 * DELETE /users/me/profile-image
 * POST   /users/change-password   (compatibility contract)
 */

require_once __DIR__ . '/../../../admin/backend/services/ProfileService.php';
require_once __DIR__ . '/../../../admin/backend/services/AccountCredentialService.php';
require_once __DIR__ . '/../../../admin/backend/services/ProfileImageService.php';
require_once __DIR__ . '/../../../admin/backend/services/SecurityAuditService.php';

$auth = apiRequireAuth();
$action = (string)($ROUTE['id'] ?? '');
$subAction = (string)($ROUTE['sub'] ?? '');
$userId = (int)($auth['uid'] ?? 0);
$identity = \App\Services\AuthenticatedProfileIdentity::fromTrustedUserId($userId);
$profileImageSchemaReady = \App\Services\MysqliProfileRepository::profileImageColumnAvailable($conn);
$profileService = new \App\Services\ProfileService(
    new \App\Services\MysqliProfileRepository($conn, $profileImageSchemaReady)
);
$auditActor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
    $userId,
    (string)($auth['usr'] ?? ''),
    'api',
    $_SERVER
);

/** @param array<string,mixed> $profile @return array<string,mixed> */
function usersApiDecorateProfileImage(array $profile): array
{
    if (!empty($profile['profile_image']['present'])) {
        $profile['profile_image']['url'] = function_exists('ssms_app_url')
            ? ssms_app_url('api/v1/users/me/profile-image')
            : '/api/v1/users/me/profile-image';
    }
    return $profile;
}

/** @param array<string,mixed> $profile @return array<string,mixed> */
function usersApiAttachAssignments(\mysqli $conn, int $userId, array $profile): array
{
    if (!in_array((string)($profile['role'] ?? ''), ['teacher', 'attendance_taker'], true)) {
        return $profile;
    }
    $year = getCurrentAcademicYear();
    $yearId = $year ? (int)$year['id'] : 0;
    $statement = $conn->prepare(
        'SELECT ta.class_id, c.class_name, ta.subject_id, s.subject_name, ta.is_class_teacher
           FROM teacher_assignments ta
           JOIN classes c ON ta.class_id = c.id
           LEFT JOIN subjects s ON ta.subject_id = s.id
          WHERE ta.teacher_id = ? AND ta.is_active = 1
            AND (ta.academic_year_id IS NULL OR ta.academic_year_id = ?)'
    );
    if (!$statement) {
        throw new \App\Services\ProfilePersistenceException('Could not prepare assignments.');
    }
    $statement->bind_param('ii', $userId, $yearId);
    if (!$statement->execute()) {
        $statement->close();
        throw new \App\Services\ProfilePersistenceException('Could not load assignments.');
    }
    $result = $statement->get_result();
    $assignments = [];
    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }
    $statement->close();
    $profile['assignments'] = $assignments;
    return $profile;
}

/** Reject every client-controlled ownership hint on own-image routes. */
function usersApiRejectOwnerParameters(): void
{
    foreach (['user_id', 'id', 'owner_id', 'username'] as $field) {
        if (array_key_exists($field, $_GET) || array_key_exists($field, $_POST)) {
            err('User ownership is derived from authentication.', 422, [
                'code' => 'VALIDATION_FAILED',
                'field' => $field,
            ]);
        }
    }
}

/** @param array<string,mixed> $input */
function usersApiRequireProfileVersion(array $input): string
{
    try {
        return \App\Services\ProfileService::requireProfileVersion(
            $input['profile_version'] ?? null
        );
    } catch (\App\Services\ProfileDomainException $error) {
        err(
            'A valid profile version is required. Reload profile data and try again.',
            422,
            ['code' => 'PROFILE_VERSION_REQUIRED', 'reason' => $error->reason()]
        );
    }
}

/** @return \App\Services\ProfileImageService */
function usersApiImageService(\mysqli $conn, bool $schemaReady): \App\Services\ProfileImageService
{
    if (!$schemaReady) {
        err('Profile image storage is not available yet.', 503, [
            'code' => 'STORAGE_UNAVAILABLE',
        ]);
    }
    return new \App\Services\ProfileImageService(
        new \App\Services\MysqliProfileImageRepository($conn),
        \App\Services\PrivateProfileImageStorage::configured()
    );
}

/** @param array<string,mixed> $before @param array<string,mixed> $after */
function usersApiAuditProfileChanges(
    \mysqli $conn,
    \App\Services\SecurityAuditActor $actor,
    int $userId,
    array $before,
    array $after
): void {
    $events = [];
    if (!hash_equals((string)$before['username'], (string)$after['username'])) {
        $events[] = ['PROFILE_USERNAME_CHANGED', [
            'old_username' => (string)$before['username'],
            'new_username' => (string)$after['username'],
        ]];
    }
    if (($before['email'] ?? null) !== ($after['email'] ?? null)) {
        $events[] = ['PROFILE_EMAIL_CHANGED', ['operation' => 'email_changed']];
    }
    if (!hash_equals((string)$before['full_name'], (string)$after['full_name'])) {
        $events[] = ['PROFILE_FULL_NAME_CHANGED', ['operation' => 'full_name_changed']];
    }
    foreach ($events as [$event, $details]) {
        if (!\App\Services\SecurityAuditService::recordTrusted(
            $conn,
            $actor,
            $event,
            $details,
            'user',
            $userId
        )) {
            error_log('Profile audit event could not be recorded.');
        }
    }
}

/** @param array<string,mixed> $result */
function usersApiAuditImageMutation(
    \mysqli $conn,
    \App\Services\SecurityAuditActor $actor,
    int $userId,
    array $result
): void {
    $eventByAction = [
        'Profile Image Uploaded' => 'PROFILE_IMAGE_UPLOADED',
        'Profile Image Replaced' => 'PROFILE_IMAGE_REPLACED',
        'Profile Image Removed' => 'PROFILE_IMAGE_REMOVED',
    ];
    $action = (string)($result['audit_action'] ?? '');
    $event = $eventByAction[$action] ?? 'PROFILE_IMAGE_UPDATED';
    if (!\App\Services\SecurityAuditService::recordTrusted(
        $conn,
        $actor,
        $event,
        ['operation' => strtolower(substr($event, strlen('PROFILE_IMAGE_')))],
        'user',
        $userId
    )) {
        error_log('Profile image audit event could not be recorded.');
    }
}

function usersApiProfileError(
    \Throwable $error,
    ?\App\Services\ProfileService $profiles = null,
    ?\App\Services\AuthenticatedProfileIdentity $identity = null
): void {
    if ($error instanceof \App\Services\ProfileDomainException) {
        $reason = $error->reason();
        $safeMessages = [
            'PROFILE_CONFLICT' => 'The profile changed. Reload and try again.',
            'USERNAME_TAKEN' => 'That username is already in use.',
            'EMAIL_TAKEN' => 'That email address is already in use.',
            'PROFILE_DUPLICATE' => 'That profile value is already in use.',
            'CURRENT_PASSWORD_INCORRECT' => 'Current password is incorrect.',
            'USER_NOT_FOUND' => 'User not found.',
        ];
        $message = $safeMessages[$reason] ?? 'Profile input was rejected.';
        if ($reason === 'PROFILE_CONFLICT') {
            $extra = ['code' => 'PROFILE_CONFLICT', 'reload_required' => true];
            if ($profiles !== null && $identity !== null) {
                try {
                    global $conn;
                    $current = $profiles->getOwnProfile($identity);
                    if ($conn instanceof \mysqli) {
                        $current = usersApiAttachAssignments(
                            $conn,
                            $identity->userId(),
                            $current
                        );
                    }
                    $extra['profile'] = usersApiDecorateProfileImage($current);
                } catch (\Throwable $ignored) {
                }
            }
            err($message, 409, $extra);
        }
        if ($reason === 'USERNAME_TAKEN' || $reason === 'EMAIL_TAKEN'
            || $reason === 'PROFILE_DUPLICATE') {
            err($message, 409, ['code' => $reason]);
        }
        if ($reason === 'CURRENT_PASSWORD_INCORRECT') {
            err($message, 422, ['code' => 'CURRENT_PASSWORD_INCORRECT']);
        }
        if ($reason === 'USER_NOT_FOUND') {
            err($message, 404, ['code' => 'USER_NOT_FOUND']);
        }
        err($message, 422, [
            'code' => 'VALIDATION_FAILED',
            'reason' => $reason,
        ]);
    }
    reportInternalError('Profile operation failed', $error);
    err('Profile service is temporarily unavailable.', 503, [
        'code' => 'PROFILE_SERVICE_UNAVAILABLE',
    ]);
}

function usersApiCredentialError(\Throwable $error): void
{
    if ($error instanceof \App\Services\CredentialDomainException) {
        $reason = $error->reason();
        $safeMessages = [
            'USER_NOT_FOUND' => 'User not found.',
            'CURRENT_PASSWORD_INCORRECT' => 'Current password is incorrect.',
            'PASSWORD_CONFIRMATION_MISMATCH' => 'New passwords do not match.',
            'NEW_PASSWORD_MUST_DIFFER' => 'New password must be different from the current password.',
            'PASSWORD_POLICY_FAILED' => 'The new password does not meet the password policy.',
        ];
        $message = $safeMessages[$reason] ?? 'Password input was rejected.';
        if ($reason === 'USER_NOT_FOUND') {
            err($message, 404, ['code' => 'USER_NOT_FOUND']);
        }
        $extra = ['code' => $reason];
        if ($reason === 'PASSWORD_POLICY_FAILED') {
            $extra['errors'] = $error->policyErrors();
        }
        err($message, 422, $extra);
    }
    reportInternalError('Credential operation failed', $error);
    err('Password could not be changed. Please try again.', 503, [
        'code' => 'CREDENTIAL_UPDATE_UNAVAILABLE',
    ]);
}

function usersApiImageError(\Throwable $error): void
{
    if ($error instanceof \App\Services\ProfileImageDomainException) {
        $reason = $error->reason();
        $map = [
            'IMAGE_SIZE_INVALID' => [413, 'IMAGE_TOO_LARGE', 'Image exceeds the allowed size.'],
            'IMAGE_TYPE_INVALID' => [415, 'UNSUPPORTED_IMAGE', 'Image type is not supported.'],
            'IMAGE_DECODE_INVALID' => [422, 'INVALID_IMAGE', 'Image could not be decoded.'],
            'IMAGE_DIMENSIONS_INVALID' => [422, 'INVALID_IMAGE', 'Image dimensions are not allowed.'],
            'IMAGE_TRANSFER_INVALID' => [422, 'INVALID_IMAGE', 'Image upload was not completed.'],
            'IMAGE_UNREADABLE' => [422, 'INVALID_IMAGE', 'Image could not be read.'],
            'IMAGE_UPLOAD_FAILED' => [422, 'INVALID_IMAGE', 'Image upload failed.'],
            'PROFILE_VERSION_REQUIRED' => [422, 'VALIDATION_FAILED', 'Profile version is required.'],
            'PROFILE_CONFLICT' => [409, 'PROFILE_CONFLICT', 'The profile changed. Reload and try again.'],
            'PROFILE_IMAGE_NOT_SET' => [404, 'PROFILE_IMAGE_NOT_SET', 'No profile image is set.'],
            'USER_NOT_FOUND' => [404, 'USER_NOT_FOUND', 'User not found.'],
        ];
        [$status, $code, $message] = $map[$reason]
            ?? [422, 'INVALID_IMAGE', 'Image input was rejected.'];
        $extra = ['code' => $code];
        if ($reason === 'PROFILE_CONFLICT') {
            $extra['reload_required'] = true;
        }
        err($message, $status, $extra);
    }
    reportInternalError('Profile image operation failed', $error);
    err('Profile image storage is temporarily unavailable.', 503, [
        'code' => 'STORAGE_UNAVAILABLE',
    ]);
}

// ============================================================
// /users/me/profile-image
// ============================================================
if (($action === 'me' && $subAction === 'profile-image')
    || ($action === 'profile-image' && $subAction === '')) {
    usersApiRejectOwnerParameters();
    $imageService = usersApiImageService($conn, $profileImageSchemaReady);

    if ($method === 'GET') {
        try {
            $image = $imageService->readOwnImage($identity);
            $etag = '"' . $image->opaqueVersion() . '"';
            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if ($ifNoneMatch !== '' && (trim($ifNoneMatch) === $etag || trim($ifNoneMatch, '"') === $image->opaqueVersion())) {
                http_response_code(304);
                header('ETag: ' . $etag);
                header('Cache-Control: private, no-store, max-age=0');
                exit;
            }
            $bytes = $image->jpegBytes();
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Content-Type: image/jpeg');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            header('ETag: ' . $etag);
            echo $bytes;
            exit;
        } catch (\Throwable $error) {
            usersApiImageError($error);
        }
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    apiEnforceRateLimits([
        ['action' => 'profile-image-user', 'subject' => 'user:' . $userId, 'limit' => 10, 'window' => 3600],
        ['action' => 'profile-image-ip', 'subject' => 'ip:' . $ip, 'limit' => 30, 'window' => 3600],
    ]);

    if ($method === 'POST') {
        foreach (array_keys($_POST) as $field) {
            if ($field !== 'profile_version') {
                err('Unsupported image-upload field.', 422, [
                    'code' => 'VALIDATION_FAILED',
                    'field' => $field,
                ]);
            }
        }
        $profileVersion = usersApiRequireProfileVersion($_POST);
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            err('Image is required.', 422, ['code' => 'INVALID_IMAGE']);
        }
        try {
            $artifact = \App\Services\ProfileImageService::prepareRequestUpload($_FILES['image']);
            $requestHash = hash(
                'sha256',
                $userId . "\0POST /users/me/profile-image\0"
                    . hash('sha256', $artifact->jpegBytes()) . "\0" . $profileVersion
            );
            apiIdempotencyBegin($userId, null, $requestHash);
            $result = $imageService->replaceOwnImage($identity, $artifact, $profileVersion);
            if (!empty($result['profile_image']['present'])) {
                $result['profile_image']['url'] = function_exists('ssms_app_url')
                    ? ssms_app_url('api/v1/users/me/profile-image')
                    : '/api/v1/users/me/profile-image';
            }
            usersApiAuditImageMutation($conn, $auditActor, $userId, $result);
            unset($result['audit_action'], $result['actor_user_id'], $result['target_user_id']);
            ok($result);
        } catch (\Throwable $error) {
            usersApiImageError($error);
        }
    }

    if ($method === 'DELETE') {
        $body = getBody();
        $profileVersion = usersApiRequireProfileVersion($body);
        foreach (array_keys($body) as $field) {
            if ($field !== 'profile_version') {
                err('Unsupported image-removal field.', 422, [
                    'code' => 'VALIDATION_FAILED',
                    'field' => $field,
                ]);
            }
        }
        apiIdempotencyBegin($userId);
        try {
            $result = $imageService->removeOwnImage($identity, $profileVersion);
            usersApiAuditImageMutation($conn, $auditActor, $userId, $result);
            unset($result['audit_action'], $result['actor_user_id'], $result['target_user_id']);
            ok($result);
        } catch (\Throwable $error) {
            usersApiImageError($error);
        }
    }

    err("No handler for {$method} /users/me/profile-image", 404);
}

// ============================================================
// GET /users/me — canonical current-user profile, preserving assignments.
// ============================================================
if ($action === 'me' && $subAction === '' && $method === 'GET') {
    try {
        $profile = $profileService->getOwnProfile($identity);
        $profile = usersApiAttachAssignments($conn, $userId, $profile);
        ok(usersApiDecorateProfileImage($profile));
    } catch (\Throwable $error) {
        usersApiProfileError($error);
    }
}

// ============================================================
// PATCH /users/me — optimistic self-service profile mutation.
// ============================================================
if ((($action === 'me' && $subAction === '') || $action === 'profile-update' || $action === 'update')
    && ($method === 'PATCH' || $method === 'PUT' || $method === 'POST')) {
    $body = getBody();
    $profileVersion = usersApiRequireProfileVersion($body);
    $profileIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rules = [
        ['action' => 'profile-update-user', 'subject' => 'user:' . $userId, 'limit' => 20, 'window' => 900],
        ['action' => 'profile-update-ip', 'subject' => 'ip:' . $profileIp, 'limit' => 60, 'window' => 900],
    ];
    if (array_key_exists('username', $body)) {
        $rules[] = ['action' => 'profile-username-user', 'subject' => 'user:' . $userId, 'limit' => 5, 'window' => 86400];
        $rules[] = ['action' => 'profile-username-ip', 'subject' => 'ip:' . $profileIp, 'limit' => 20, 'window' => 86400];
    }
    apiEnforceRateLimits($rules);

    try {
        $before = $profileService->getOwnProfile($identity);
        $updated = $profileService->updateOwnProfile($identity, $body, $profileVersion);
        usersApiAuditProfileChanges($conn, $auditActor, $userId, $before, $updated);
        $claimsRefresh = !hash_equals((string)$before['username'], (string)$updated['username'])
            || !hash_equals((string)$before['full_name'], (string)$updated['full_name']);
        $updated = usersApiDecorateProfileImage($updated);
        $updated['claims_refresh_required'] = $claimsRefresh;
        ok($updated);
    } catch (\Throwable $error) {
        usersApiProfileError($error, $profileService, $identity);
    }
}

// ============================================================
// POST /users/change-password — compatibility route using shared transaction.
// ============================================================
if ($action === 'change-password' && $subAction === '' && $method === 'POST') {
    $body = getBody();
    foreach (['current_password', 'new_password', 'confirm_password'] as $field) {
        if (!is_string($body[$field] ?? null)) {
            err('Invalid password input.', 422, ['code' => 'VALIDATION_FAILED']);
        }
    }
    apiEnforceRateLimits([
        ['action' => 'profile-password-user', 'subject' => 'user:' . $userId, 'limit' => 5, 'window' => 900],
        [
            'action' => 'profile-password-ip',
            'subject' => 'ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'limit' => 5,
            'window' => 900,
        ],
    ]);

    try {
        $credentials = new \App\Services\AccountCredentialService(
            new \App\Services\MysqliCredentialRepository($conn)
        );
        $credentials->changeOwnPassword(
            $identity,
            $body['current_password'],
            $body['new_password'],
            $body['confirm_password']
        );
        if (!\App\Services\SecurityAuditService::recordTrusted(
            $conn,
            $auditActor,
            'PASSWORD_CHANGED',
            ['operation' => 'self_service_password_change'],
            'user',
            $userId
        )) {
            error_log('Password-change audit event could not be recorded.');
        }
        ok(['message' => 'Password changed successfully']);
    } catch (\Throwable $error) {
        usersApiCredentialError($error);
    }
}

err("No handler for {$method} /users" . ($action ? "/{$action}" : '')
    . ($subAction ? "/{$subAction}" : ''), 404);
