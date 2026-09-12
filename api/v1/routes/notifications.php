<?php
/**
 * School API v1 — Notifications (P72 Communication Center)
 *
 *   GET  /notifications/summary            — badge counts + permissions
 *   GET  /notifications/feed?limit&offset  — alert history (+&unread=1)
 *   GET  /notifications/announcements      — announcements for me
 *   GET  /notifications/threads            — my message threads
 *   GET  /notifications/thread?id=N        — one conversation (marks read)
 *   GET  /notifications/targets            — who may I announce to
 *   GET  /notifications/partners           — who may I message
 *   POST /notifications/mark-read          — {id}
 *   POST /notifications/mark-all-read      — {scope: alerts|announcements}
 *   POST /notifications/announcement-read  — {id}
 *   POST /notifications/compose            — announcement (permission-checked)
 *   POST /notifications/thread-start       — {to[], subject, body}
 *   POST /notifications/send-message       — {thread_id, body}
 *
 * Every staff role may read its own communication; writes go through
 * the same NotificationCenterService the web dashboards use — one
 * writer, one permission matrix, one read-state pivot.
 */

$auth = apiRequireAuth();
$userId = (int)$auth['uid'];
$role = apiRoleOf($auth);

require_once __DIR__ . '/../../../admin/backend/services/NotificationCenterService.php';
use App\Services\NotificationCenterService;

$action = $ROUTE['id'] ?? '';
$method = $ROUTE['method'] ?? 'GET';

// ── GET endpoints ────────────────────────────────────────────
if ($method === 'GET') {
    if (isApiRateLimited('notifications_read', 480)) {
        err('Too many requests. Please wait a moment.', 429);
    }

    if ($action === 'summary') {
        $summary = NotificationCenterService::unreadSummary($conn, $userId, $role);
        $summary['can_announce'] = NotificationCenterService::canAnnounce($role);
        $summary['can_message'] = NotificationCenterService::canMessage($role);
        ok(['status' => 'success', 'summary' => $summary]);
    }

    if ($action === 'feed') {
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $unreadOnly = (($_GET['unread'] ?? '') === '1');
        $type = is_string($_GET['type'] ?? '') ? (string)$_GET['type'] : '';
        $feed = NotificationCenterService::feed($conn, $userId, $role, $limit, $offset, $unreadOnly, $type);
        ok(['status' => 'success'] + $feed);
    }

    if ($action === 'announcements') {
        $rows = NotificationCenterService::listAnnouncements(
            $conn, $userId, $role,
            min(100, max(1, (int)($_GET['limit'] ?? 30))), max(0, (int)($_GET['offset'] ?? 0))
        );
        ok(['status' => 'success', 'announcements' => $rows]);
    }

    if ($action === 'threads') {
        ok(['status' => 'success', 'threads' => NotificationCenterService::threadsFor($conn, $userId)]);
    }

    if ($action === 'thread') {
        $threadId = (int)($_GET['id'] ?? 0);
        $result = NotificationCenterService::threadMessages($conn, $userId, $threadId);
        if (!$result['ok']) {
            err($result['error'] ?? 'Not found.', 404);
        }
        NotificationCenterService::markThreadRead($conn, $userId, $threadId);
        ok(['status' => 'success', 'messages' => $result['messages']]);
    }

    if ($action === 'targets') {
        ok(['status' => 'success'] + NotificationCenterService::announceTargets($conn, $role, $userId));
    }

    if ($action === 'partners') {
        ok(['status' => 'success', 'partners' => NotificationCenterService::messagePartners($conn, $role, $userId)]);
    }

    err('Unknown notifications action.', 404);
}

// ── POST endpoints ───────────────────────────────────────────
if ($method === 'POST') {
    if (isApiRateLimited('notifications_write', 120)) {
        err('Too many requests. Please wait a moment.', 429);
    }
    $body = getBody();

    if ($action === 'mark-read') {
        $id = (int)($body['id'] ?? 0);
        if ($id > 0 && NotificationCenterService::markRead($conn, $userId, $role, $id)) {
            ok(['status' => 'success']);
        }
        err('Could not mark as read.');
    }

    if ($action === 'mark-all-read') {
        $scope = (string)($body['scope'] ?? 'alerts');
        $ok = $scope === 'announcements'
            ? NotificationCenterService::markAllAnnouncementsRead($conn, $userId, $role)
            : NotificationCenterService::markAllRead($conn, $userId, $role);
        if ($ok) {
            ok(['status' => 'success']);
        }
        err('Could not mark all as read.');
    }

    if ($action === 'announcement-read') {
        $id = (int)($body['id'] ?? 0);
        if ($id > 0 && NotificationCenterService::markAnnouncementRead($conn, $userId, $id)) {
            ok(['status' => 'success']);
        }
        err('Could not mark the announcement read.');
    }

    if ($action === 'compose') {
        $roles = [];
        foreach ((array)($body['roles'] ?? []) as $r) {
            $r = trim((string)$r);
            if ($r !== '') { $roles[] = $r; }
        }
        $userIds = [];
        foreach ((array)($body['user_ids'] ?? []) as $u) {
            $u = (int)$u;
            if ($u > 0) { $userIds[] = $u; }
        }
        $result = NotificationCenterService::postAnnouncement(
            $conn, $userId, $role,
            (string)($body['title'] ?? ''),
            (string)($body['body'] ?? ''),
            (string)($body['priority'] ?? 'normal'),
            (string)($body['audience'] ?? 'roles'),
            $roles, $userIds
        );
        if ($result['ok']) {
            ok(['status' => 'success', 'id' => $result['id']]);
        }
        err($result['error'] ?? 'Could not publish.');
    }

    if ($action === 'thread-start') {
        $to = [];
        foreach ((array)($body['to'] ?? []) as $u) {
            $u = (int)$u;
            if ($u > 0) { $to[] = $u; }
        }
        $result = NotificationCenterService::startThread(
            $conn, $userId, $role,
            (string)($body['subject'] ?? ''),
            $to,
            (string)($body['body'] ?? '')
        );
        if ($result['ok']) {
            ok(['status' => 'success', 'id' => $result['id']]);
        }
        err($result['error'] ?? 'Could not start the conversation.');
    }

    if ($action === 'send-message') {
        $result = NotificationCenterService::sendMessage(
            $conn, $userId, (int)($body['thread_id'] ?? 0), (string)($body['body'] ?? '')
        );
        if ($result['ok']) {
            ok(['status' => 'success']);
        }
        err($result['error'] ?? 'Could not send.');
    }

    err('Unknown notifications action.', 404);
}

err('Method not allowed.', 405);
