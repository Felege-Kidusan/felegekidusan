<?php
/**
 * Notifications API
 * Handles fetching, marking as read, and other notification operations
 * 
 * GET /admin/api_notifications.php - Get notifications
 * POST /admin/api_notifications.php - Mark as read, etc.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/workflow.php';

// Check authentication
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_REQUEST['action'] ?? 'list';

// CSRF validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

switch ($action) {
    case 'list':
        // Get notifications for current user
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $notifications = getUnreadNotifications($conn, $limit);
        $count = getUnreadNotificationCount($conn);
        
        echo json_encode([
            'status' => 'success',
            'count' => $count,
            'notifications' => $notifications
        ]);
        break;
        
    case 'count':
        // Just get the count
        echo json_encode([
            'status' => 'success',
            'count' => getUnreadNotificationCount($conn)
        ]);
        break;
        
    case 'mark_read':
        // Mark single notification as read
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && markNotificationRead($conn, $id)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to mark as read']);
        }
        break;
        
    case 'mark_all_read':
        // Mark all notifications as read
        if (markAllNotificationsRead($conn)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to mark all as read']);
        }
        break;
        
    case 'tasks':
        // Get pending tasks for current user
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $tasks = getPendingTasks($conn, $limit);
        
        echo json_encode([
            'status' => 'success',
            'tasks' => $tasks
        ]);
        break;
        
    case 'task_update':
        // Update task status
        $taskId = (int)($_POST['task_id'] ?? 0);
        $taskStatus = $_POST['task_status'] ?? '';
        $taskNotes = $_POST['notes'] ?? '';
        
        if (!in_array($taskStatus, ['pending', 'in_progress', 'completed', 'cancelled'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid task status']);
            break;
        }
        
        if ($taskId > 0 && updateTaskStatus($conn, $taskId, $taskStatus, $taskNotes)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update task']);
        }
        break;
        
    case 'changes':
        // Get unsynced member changes
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $changes = getUnsyncedChanges($conn, null, $limit);
        
        echo json_encode([
            'status' => 'success',
            'changes' => $changes
        ]);
        break;
        
    case 'sync_change':
        // Mark a change as synced for current department
        $changeId = (int)($_POST['change_id'] ?? 0);
        if ($changeId > 0 && markChangeSynced($conn, $changeId)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to sync change']);
        }
        break;
        
    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}

$conn->close();
