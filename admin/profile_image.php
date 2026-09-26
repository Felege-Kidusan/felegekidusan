<?php
/**
 * Authenticated private profile-image response for the current web session.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/ProfileService.php';
require_once __DIR__ . '/backend/services/ProfileImageService.php';

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    echo json_encode([
        'status' => 'error',
        'code' => 'AUTHENTICATION_REQUIRED',
        'message' => 'Authentication required.',
    ]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'GET required']);
    exit;
}

foreach (['user_id', 'id', 'owner_id', 'username'] as $field) {
    if (array_key_exists($field, $_GET)) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'code' => 'VALIDATION_FAILED',
            'message' => 'User ownership is derived from the session.',
        ]);
        exit;
    }
}

$userId = (int)($_SESSION['admin_id'] ?? 0);
try {
    if (!\App\Services\MysqliProfileRepository::profileImageColumnAvailable($conn)) {
        throw new \App\Services\ProfileImagePersistenceException('Profile image schema is unavailable.');
    }
    $identity = \App\Services\AuthenticatedProfileIdentity::fromTrustedUserId($userId);
    $service = new \App\Services\ProfileImageService(
        new \App\Services\MysqliProfileImageRepository($conn),
        \App\Services\PrivateProfileImageStorage::configured()
    );
    $image = $service->readOwnImage($identity);
    $etag = '"' . $image->opaqueVersion() . '"';
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch !== '' && (trim($ifNoneMatch) === $etag || trim($ifNoneMatch, '"') === $image->opaqueVersion())) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=300, must-revalidate');
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
} catch (\App\Services\ProfileImageDomainException $error) {
    $reason = $error->reason();
    http_response_code($reason === 'PROFILE_IMAGE_NOT_SET' ? 404 : 422);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'error',
        'code' => $reason,
        'message' => $reason === 'PROFILE_IMAGE_NOT_SET'
            ? 'No profile image is set.'
            : 'Profile image request was rejected.',
    ]);
    exit;
} catch (\Throwable $error) {
    reportInternalError('Private web profile image read failed', $error);
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'error',
        'code' => 'STORAGE_UNAVAILABLE',
        'message' => 'Profile image storage is temporarily unavailable.',
    ]);
    exit;
}
