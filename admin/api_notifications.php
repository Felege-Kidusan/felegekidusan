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
require_once __DIR__ . '/backend/services/NotificationCenterService.php';

use App\Services\NotificationCenterService;

// Check authentication
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = is_string($_REQUEST['action'] ?? 'list') ? ($_REQUEST['action'] ?? 'list') : '';
requirePostActions($action, ['mark_read', 'mark_all_read', 'task_update', 'sync_change',
    'compose', 'announcement_read', 'thread_start', 'send_message', 'thread_read']);

// CSRF validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

if (in_array($action, ['changes', 'sync_change'], true)
    && !in_array($_SESSION['admin_role'] ?? '', ['super_admin','school_admin','info_dept','hr_dept'], true)) {
    jsonResponse(['status'=>'error','message'=>'Member change history is restricted to member-management staff.'], 403);
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
        // Mark all as read. Pinned default: the ALERTS stream for the
        // current user. P72: scope=announcements also supported.
        $scope = is_string($_POST['scope'] ?? '') ? (string)$_POST['scope'] : 'alerts';
        if ($scope === 'announcements') {
            $ok = NotificationCenterService::markAllAnnouncementsRead(
                $conn, (int)$_SESSION['admin_id'], (string)($_SESSION['admin_role'] ?? '')
            );
        } else {
            $ok = markAllNotificationsRead($conn);
        }
        if ($ok) {
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
        
    // ══════════════════════════════════════════════════════════
    // P72 — WBWS Communication Center (additive)
    // ══════════════════════════════════════════════════════════

    case 'summary': {
        // One poll = one query set: every unread count for the bell.
        $summary = NotificationCenterService::unreadSummary(
            $conn, (int)$_SESSION['admin_id'], (string)($_SESSION['admin_role'] ?? '')
        );
        $summary['can_announce'] = NotificationCenterService::canAnnounce($_SESSION['admin_role'] ?? '');
        $summary['can_message']  = NotificationCenterService::canMessage($_SESSION['admin_role'] ?? '');
        echo json_encode(['status' => 'success', 'summary' => $summary]);
        break;
    }

    case 'feed': {
        // Full alert history (read + unread) with pagination + filters.
        $limit  = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $unreadOnly = (($_GET['unread'] ?? '') === '1');
        $type   = is_string($_GET['type'] ?? '') ? (string)$_GET['type'] : '';
        $feed = NotificationCenterService::feed(
            $conn, (int)$_SESSION['admin_id'], (string)($_SESSION['admin_role'] ?? ''),
            $limit, $offset, $unreadOnly, $type
        );
        echo json_encode(['status' => 'success'] + $feed);
        break;
    }

    case 'announcements': {
        $rows = NotificationCenterService::listAnnouncements(
            $conn, (int)$_SESSION['admin_id'], (string)($_SESSION['admin_role'] ?? ''),
            min(100, max(1, (int)($_GET['limit'] ?? 30))), max(0, (int)($_GET['offset'] ?? 0))
        );
        echo json_encode(['status' => 'success', 'announcements' => $rows]);
        break;
    }

    case 'announcement_read': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && NotificationCenterService::markAnnouncementRead($conn, (int)$_SESSION['admin_id'], $id)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Could not mark the announcement read.']);
        }
        break;
    }

    case 'compose': {
        // Publish an announcement (permission + CSRF + POST enforced).
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));
        $priority = (string)($_POST['priority'] ?? 'normal');
        $audience = (string)($_POST['audience'] ?? 'roles');
        $roles = [];
        if (isset($_POST['roles']) && (is_array($_POST['roles']) || is_string($_POST['roles']))) {
            $raw = is_array($_POST['roles']) ? $_POST['roles'] : explode(',', (string)$_POST['roles']);
            foreach ($raw as $r) {
                $r = trim((string)$r);
                if ($r !== '') { $roles[] = $r; }
            }
        }
        $userIds = [];
        if (isset($_POST['user_ids']) && (is_array($_POST['user_ids']) || is_string($_POST['user_ids']))) {
            $raw = is_array($_POST['user_ids']) ? $_POST['user_ids'] : explode(',', (string)$_POST['user_ids']);
            foreach ($raw as $u) {
                $u = (int)trim((string)$u);
                if ($u > 0) { $userIds[] = $u; }
            }
        }
        $result = NotificationCenterService::postAnnouncement(
            $conn, (int)$_SESSION['admin_id'], (string)($_SESSION['admin_role'] ?? ''),
            $title, $body, $priority, $audience, $roles, $userIds
        );
        if ($result['ok']) {
            echo json_encode(['status' => 'success', 'id' => $result['id']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'Could not publish.']);
        }
        break;
    }

    case 'targets': {
        // Who may I address? (composer picker source)
        $targets = NotificationCenterService::announceTargets(
            $conn, (string)($_SESSION['admin_role'] ?? ''), (int)$_SESSION['admin_id']
        );
        echo json_encode(['status' => 'success'] + $targets);
        break;
    }

    case 'partners': {
        // Who may I message? (thread composer picker source)
        $partners = NotificationCenterService::messagePartners(
            $conn, (string)($_SESSION['admin_role'] ?? ''), (int)$_SESSION['admin_id']
        );
        echo json_encode(['status' => 'success', 'partners' => $partners]);
        break;
    }

    case 'threads': {
        $threads = NotificationCenterService::threadsFor($conn, (int)$_SESSION['admin_id']);
        echo json_encode(['status' => 'success', 'threads' => $threads]);
        break;
    }

    case 'thread': {
        $threadId = (int)($_GET['id'] ?? 0);
        $result = NotificationCenterService::threadMessages($conn, (int)$_SESSION['admin_id'], $threadId);
        if ($result['ok']) {
            // opening the conversation marks it read
            NotificationCenterService::markThreadRead($conn, (int)$_SESSION['admin_id'], $threadId);
            echo json_encode(['status' => 'success', 'messages' => $result['messages']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'Not found.']);
        }
        break;
    }

    case 'thread_start': {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $participants = [];
        if (isset($_POST['to']) && (is_array($_POST['to']) || is_string($_POST['to']))) {
            $raw = is_array($_POST['to']) ? $_POST['to'] : explode(',', (string)$_POST['to']);
            foreach ($raw as $u) {
                $u = (int)trim((string)$u);
                if ($u > 0) { $participants[] = $u; }
            }
        }
        $result = NotificationCenterService::startThread(
            $conn, (int)$_SESSION['admin_id'], (string)($_SESSION['admin_role'] ?? ''),
            $subject, $participants, $body
        );
        if ($result['ok']) {
            echo json_encode(['status' => 'success', 'id' => $result['id']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'Could not start the conversation.']);
        }
        break;
    }

    case 'send_message': {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $body = trim((string)($_POST['body'] ?? ''));
        $result = NotificationCenterService::sendMessage($conn, (int)$_SESSION['admin_id'], $threadId, $body);
        if ($result['ok']) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'Could not send.']);
        }
        break;
    }

    case 'thread_read': {
        $threadId = (int)($_POST['id'] ?? 0);
        if (NotificationCenterService::markThreadRead($conn, (int)$_SESSION['admin_id'], $threadId)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Not your conversation.']);
        }
        break;
    }

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}

$conn->close();
