<?php
/**
 * ============================================================
 * Super Admin — Mobile App Release Management API
 * ============================================================
 * Allows Super Admins to upload new APK releases directly from
 * the web dashboard, set version policies, release notes, and
 * configure in-app update banners without cPanel or FTP access.
 *
 * Security: Super Admin session required, CSRF validated,
 * strict APK MIME/extension checks, rate limiting on uploads.
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/AppReleaseManager.php';

use App\Services\AppReleaseManager;

// 1. Strict Authentication & Authorization
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Super Admin privileges required.']);
    exit;
}

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)$_REQUEST['action'] : 'get_release';

// 2. Validate CSRF for all POST / write requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'get_release': {
            $info = AppReleaseManager::getReleaseInfo(ROOT_PATH);
            echo json_encode([
                'status' => 'success',
                'data' => $info,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'save_config': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }

            $input = [
                'latest_version' => $_POST['latest_version'] ?? '',
                'latest_build' => $_POST['latest_build'] ?? '',
                'min_version' => $_POST['min_version'] ?? '',
                'min_build' => $_POST['min_build'] ?? '',
                'force_update' => !empty($_POST['force_update']),
                'background_drains_enabled' => isset($_POST['background_drains_enabled']) ? (bool)$_POST['background_drains_enabled'] : true,
                'release_notes' => $_POST['release_notes'] ?? '',
                'banner_text' => $_POST['banner_text'] ?? '',
                'banner_kind' => $_POST['banner_kind'] ?? 'info',
            ];

            $updated = AppReleaseManager::saveConfig(ROOT_PATH, $input);

            // Audit log
            if (isset($conn) && $conn instanceof mysqli) {
                $uid = (int)($_SESSION['admin_id'] ?? 0);
                $uname = (string)($_SESSION['admin_username'] ?? 'admin');
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                $details = "Updated app release policy: v{$updated['latest_version']} (Build {$updated['latest_build']})";
                $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, 'App Release Config Updated', ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param('isss', $uid, $uname, $details, $ip);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'App release configuration saved successfully.',
                'data' => $updated,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'upload_apk': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }

            if (empty($_FILES['apk_file'])) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'No APK file received.']);
                exit;
            }

            $abi = (string)($_POST['abi'] ?? 'universal');
            if (!in_array($abi, ['universal', 'arm64-v8a', 'armeabi-v7a'], true)) {
                $abi = 'universal';
            }

            $updated = AppReleaseManager::handleApkUpload(ROOT_PATH, $_FILES['apk_file'], $abi);

            // Audit log
            if (isset($conn) && $conn instanceof mysqli) {
                $uid = (int)($_SESSION['admin_id'] ?? 0);
                $uname = (string)($_SESSION['admin_username'] ?? 'admin');
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                $details = "Uploaded new APK ($abi): " . ($_FILES['apk_file']['name'] ?? 'fkss.apk') . " (" . number_format((int)$_FILES['apk_file']['size']) . " bytes)";
                $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, 'APK Uploaded', ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param('isss', $uid, $uname, $details, $ip);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'APK uploaded and published successfully!',
                'data' => $updated,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'delete_apk': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }

            $abi = (string)($_POST['abi'] ?? 'universal');
            if (!in_array($abi, ['universal', 'arm64-v8a', 'armeabi-v7a'], true)) {
                $abi = 'universal';
            }

            $updated = AppReleaseManager::deleteApk(ROOT_PATH, $abi);

            echo json_encode([
                'status' => 'success',
                'message' => 'APK artifact removed successfully.',
                'data' => $updated,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            exit;
    }
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $err]);
    exit;
} catch (Throwable $e) {
    $err = $e->getMessage();
    error_log('AppRelease API error: ' . $err);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to process app release request. Please try again.']);
    exit;
}
