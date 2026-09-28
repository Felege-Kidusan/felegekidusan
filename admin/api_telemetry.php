<?php
/**
 * ============================================================
 * Super Admin — Mobile App Fleet Analytics & Telemetry API
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/AppTelemetryService.php';

use App\Services\AppTelemetryService;

// 1. Strict Authentication & Role Check
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['admin_role'] ?? '';
if (!in_array($role, ['super_admin', 'school_admin'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable.']);
    exit;
}

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)$_REQUEST['action'] : 'get_overview';

try {
    switch ($action) {
        case 'get_overview': {
            $filters = [
                'version' => !empty($_GET['version']) ? (string)$_GET['version'] : null,
                'range'   => !empty($_GET['range']) ? (string)$_GET['range'] : '7d',
                'brand'   => !empty($_GET['brand']) ? (string)$_GET['brand'] : null,
            ];
            $data = AppTelemetryService::getFleetMetrics($conn, $filters);
            echo json_encode([
                'status' => 'success',
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'get_installations': {
            $filters = [
                'version' => !empty($_GET['version']) ? (string)$_GET['version'] : null,
                'brand'   => !empty($_GET['brand']) ? (string)$_GET['brand'] : null,
                'search'  => !empty($_GET['search']) ? (string)$_GET['search'] : null,
            ];
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
            $data = AppTelemetryService::getInstallationsList($conn, $filters, $page, $limit);
            echo json_encode([
                'status' => 'success',
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'get_events': {
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));
            $events = AppTelemetryService::getRecentEvents($conn, $limit);
            echo json_encode([
                'status' => 'success',
                'data' => $events,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            exit;
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
    error_log('Telemetry API error: ' . $err);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to process telemetry request.']);
    exit;
}
