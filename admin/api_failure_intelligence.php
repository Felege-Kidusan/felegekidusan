<?php
/**
 * ============================================================
 * Super Admin — Failure Intelligence API
 * ============================================================
 * The unified failure surface: issues across all three channels
 * (sync attempts, server errors, crashes), remediation editing, and
 * recorded failure reports.
 *
 * Security: super_admin only (fleet-wide scope), session auth like the
 * other admin APIs, CSRF via validateCsrf() on every POST. Reads are
 * GET-only. This endpoint never writes the raw telemetry/monitor tables;
 * the only writes are to failure_issues (status/remediation) and
 * failure_reports (recorded reports).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/FailureIssueService.php';

use App\Services\FailureIssueService;

// 1. Strict authentication & role check — fleet-wide data is super_admin.
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable.']);
    exit;
}

// 2. Validate CSRF for all POST / write requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
        exit;
    }
}

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)$_REQUEST['action'] : '';

try {
    switch ($action) {
        case 'get_failure_overview': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);
                header('Allow: GET');
                echo json_encode(['status' => 'error', 'message' => 'Read-only endpoint.']);
                exit;
            }
            $data = FailureIssueService::getOverview($conn);
            echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'get_failure_issues': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);
                header('Allow: GET');
                echo json_encode(['status' => 'error', 'message' => 'Read-only endpoint.']);
                exit;
            }
            $data = FailureIssueService::getIssues($conn, [
                'source' => is_scalar($_GET['source'] ?? '') ? (string)$_GET['source'] : '',
                'status' => is_scalar($_GET['status'] ?? '') ? (string)$_GET['status'] : '',
                'window' => is_scalar($_GET['window'] ?? '') ? (string)$_GET['window'] : '7d',
            ], (int)($_GET['page'] ?? 1), (int)($_GET['limit'] ?? 25));
            echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'get_failure_issue': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);
                header('Allow: GET');
                echo json_encode(['status' => 'error', 'message' => 'Read-only endpoint.']);
                exit;
            }
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id === null || $id <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'A valid issue id is required.']);
                exit;
            }
            $data = FailureIssueService::getIssue($conn, (int)$id);
            if ($data === null) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Issue not found.']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'update_failure_issue': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
                http_response_code(405);
                header('Allow: POST');
                echo json_encode(['status' => 'error', 'message' => 'Write action.']);
                exit;
            }
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id === null || $id <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'A valid issue id is required.']);
                exit;
            }
            $actor = (string)($_SESSION['admin_username'] ?? 'admin');
            $data = FailureIssueService::updateIssue($conn, (int)$id, [
                'status' => is_scalar($_POST['status'] ?? '') ? (string)$_POST['status'] : '',
                'resolved_note' => is_scalar($_POST['resolved_note'] ?? '') ? (string)$_POST['resolved_note'] : '',
                'remediation_cause' => is_scalar($_POST['remediation_cause'] ?? '') ? (string)$_POST['remediation_cause'] : null,
                'remediation_steps' => is_scalar($_POST['remediation_steps'] ?? '') ? (string)$_POST['remediation_steps'] : null,
                'remediation_link' => is_scalar($_POST['remediation_link'] ?? '') ? (string)$_POST['remediation_link'] : null,
            ], $actor);
            if ($data === null) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Nothing to update or invalid fields.']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'generate_failure_report': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
                http_response_code(405);
                header('Allow: POST');
                echo json_encode(['status' => 'error', 'message' => 'Write action.']);
                exit;
            }
            $issueId = filter_var($_POST['issue_id'] ?? null, FILTER_VALIDATE_INT);
            if ($issueId === false || $issueId === null || $issueId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'A valid issue id is required.']);
                exit;
            }
            $actor = (string)($_SESSION['admin_username'] ?? 'admin');
            $data = FailureIssueService::generateReport($conn, (int)$issueId, 'manual', $actor);
            if ($data === null) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Issue not found or report could not be recorded.']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'get_failure_reports': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);
                header('Allow: GET');
                echo json_encode(['status' => 'error', 'message' => 'Read-only endpoint.']);
                exit;
            }
            $data = FailureIssueService::getReports($conn, (int)($_GET['page'] ?? 1), (int)($_GET['limit'] ?? 20));
            echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'get_failure_report': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);
                header('Allow: GET');
                echo json_encode(['status' => 'error', 'message' => 'Read-only endpoint.']);
                exit;
            }
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id === null || $id <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'A valid report id is required.']);
                exit;
            }
            $data = FailureIssueService::getReport($conn, (int)$id);
            if ($data === null) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Report not found.']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            exit;
    }
} catch (Throwable $e) {
    error_log('Failure intelligence API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to process failure intelligence request.']);
    exit;
}
