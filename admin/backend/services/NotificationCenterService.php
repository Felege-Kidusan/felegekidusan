<?php
/**
 * ============================================================
 * WBWS Communication Center — the single source of truth (P72)
 * ============================================================
 * One service behind every notification surface in the system:
 * web dashboards, the Flutter app (api/v1), and every department.
 *
 *   notifications            — system event stream (schema: sql/012)
 *   notification_reads       — per-user read state for ALL subjects
 *                              (notification | announcement | message_thread)
 *   announcements            — dept → audience broadcasts
 *   message_threads/messages — two-way messaging (dept ↔ teachers …)
 *   department_tasks         — cross-department tasks (unchanged)
 *
 * Doctrine (same as MemberCategory in P71): every role list, label
 * and permission matrix lives HERE — no dashboard, API route or app
 * screen may hardcode them. Unknown/forbidden targets are rejected,
 * never guessed. All methods take ($conn, $userId, $role) params —
 * no session access inside, so the api/v1 bearer flow and the web
 * session flow share exactly one writer.
 *
 * Portability note: announcements.target_user_ids stores a padded
 * CSV list (",7,9,") so membership tests work on every MySQL /
 * MariaDB version (LIKE '%,<id>,%') with no JSON functions.
 */
namespace App\Services;

final class NotificationCenterService
{

    /** O4 (046) — per-request cache of the messages.client_tag probe. */
    private static ?bool $messagesClientTag = null;

    /** All live roles (single label source — replaces the stale $DEPT_ROLES). */
    public const ROLE_LABELS = [
        'super_admin'             => 'Super Admin',
        'school_admin'            => 'School Admin',
        'info_dept'               => 'Information Dept',
        'edu_dept'                => 'Education Dept',
        'finance_dept'            => 'Finance Dept',
        'material_dept'           => 'Material Dept',
        'mezmur_dept'             => 'Mezmur Dept',
        'hr_dept'                 => 'HR Dept',
        'teacher'                 => 'Teacher',
        'attendance_taker'        => 'Attendance Taker',
        'mezmur_attendance_taker' => 'Mezmur Attendance Taker',
        'hr_attendance_taker'     => 'HR Attendance Taker',
        'content_editor'          => 'Content Editor',
    ];

    /**
     * Who may SEND announcements, and to which roles.
     * Managers announce to everyone; a department announces to the
     * staff it coordinates (its takers / teachers). Recipients-only
     * departments (finance, material, info) do not broadcast.
     */
    private const ANNOUNCE_SEND = [
        'super_admin'  => ['*'],
        'school_admin' => ['*'],
        'edu_dept'     => ['teacher', 'attendance_taker', 'edu_dept'],
        'hr_dept'      => ['hr_attendance_taker', 'hr_dept'],
        'mezmur_dept'  => ['mezmur_attendance_taker', 'mezmur_dept'],
    ];

    /**
     * Two-way messaging partners per role (symmetric — enforced both
     * directions). Teachers reach the education department and the
     * management; departments reach their staff + management; nobody
     * spams unrelated departments.
     */
    private const MESSAGE_PARTNERS = [
        'super_admin'             => ['*'],
        'school_admin'            => ['*'],
        'edu_dept'                => ['teacher', 'attendance_taker', 'edu_dept', 'school_admin', 'super_admin'],
        'teacher'                 => ['edu_dept', 'school_admin', 'super_admin'],
        'attendance_taker'        => ['edu_dept', 'school_admin', 'super_admin'],
        'hr_dept'                 => ['hr_attendance_taker', 'hr_dept', 'school_admin', 'super_admin'],
        'hr_attendance_taker'     => ['hr_dept', 'school_admin', 'super_admin'],
        'mezmur_dept'             => ['mezmur_attendance_taker', 'mezmur_dept', 'school_admin', 'super_admin'],
        'mezmur_attendance_taker' => ['mezmur_dept', 'school_admin', 'super_admin'],
        'info_dept'               => ['info_dept', 'school_admin', 'super_admin'],
        'finance_dept'            => ['finance_dept', 'school_admin', 'super_admin'],
        'material_dept'           => ['material_dept', 'school_admin', 'super_admin'],
        'content_editor'          => ['school_admin', 'super_admin'],
    ];

    // ────────────────────────────────────────────────────────────
    //  Permissions
    // ────────────────────────────────────────────────────────────

    public static function canAnnounce(string $role): bool
    {
        return isset(self::ANNOUNCE_SEND[$role]);
    }

    public static function canMessage(string $role): bool
    {
        return isset(self::MESSAGE_PARTNERS[$role]);
    }

    /** May $fromRole message a user with $toRole? (self always allowed) */
    public static function mayMessageRole(string $fromRole, string $toRole): bool
    {
        if ($fromRole === $toRole) {
            return true;
        }
        $allowed = self::MESSAGE_PARTNERS[$fromRole] ?? [];
        return in_array('*', $allowed, true) || in_array($toRole, $allowed, true);
    }

    /** Roles the given role may address in announcements. */
    public static function announceAudience(string $role): array
    {
        $allowed = self::ANNOUNCE_SEND[$role] ?? [];
        if (in_array('*', $allowed, true)) {
            return array_keys(self::ROLE_LABELS);
        }
        return $allowed;
    }

    /** Is $role a known system role? */
    public static function isKnownRole(string $role): bool
    {
        return isset(self::ROLE_LABELS[$role]);
    }

    // ────────────────────────────────────────────────────────────
    //  ALERTS (notifications stream + per-user read state)
    // ────────────────────────────────────────────────────────────

    /**
     * Feed for the current user — full history (read AND unread),
     * optional unread-only and type filters.
     * @return array{rows:array,total:int,unread:int}
     */
    public static function feed(\mysqli $conn, int $userId, string $role, int $limit = 30, int $offset = 0, bool $unreadOnly = false, string $typeFilter = '', ?int $beforeId = null): array
    {
        $empty = ['rows' => [], 'total' => 0, 'unread' => 0, 'next_before' => null, 'has_more' => false];
        try {
            $typeOk = $typeFilter !== '' && preg_match('/^[a-z_]{1,50}$/', $typeFilter);

            $where = "(FIND_IN_SET(?, n.target_roles) > 0"
                . " OR n.target_user_id = ?"
                . " OR (n.target_roles IS NULL AND n.target_user_id IS NULL))";
            if ($typeOk) {
                $where .= " AND n.type = ?";
            }

            // counts (total + unread for this user)
            $sql = "SELECT COUNT(*) AS c,
                           SUM(CASE WHEN nr.id IS NULL THEN 1 ELSE 0 END) AS u
                    FROM notifications n
                    LEFT JOIN notification_reads nr
                      ON nr.subject_type = 'notification' AND nr.subject_id = n.id AND nr.user_id = ?
                    WHERE {$where}";
            $types = 'i' . 'si' . ($typeOk ? 's' : '');
            $params = [$userId, $role, $userId];
            if ($typeOk) { $params[] = $typeFilter; }
            $stmt = $conn->prepare($sql);
            if (!$stmt) { return $empty; }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $agg = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // page (P73 Phase 5): cursor pagination. `before_id` pages by
            // n.id DESC (stable, PK-backed — the old OFFSET path skips rows
            // when anything is inserted/deleted mid-browse). The legacy
            // limit/offset path is kept for older clients.
            $cursor = ($beforeId !== null && $beforeId > 0);
            $fetch = $limit + 1;   // +1 = cheap has_more probe on every page
            $sql = "SELECT n.*, nr.read_at AS my_read_at
                    FROM notifications n
                    LEFT JOIN notification_reads nr
                      ON nr.subject_type = 'notification' AND nr.subject_id = n.id AND nr.user_id = ?
                    WHERE {$where}"
                . ($unreadOnly ? " AND nr.id IS NULL" : "")
                . ($cursor ? " AND n.id < ?" : "")
                // one ordering for both paths — id DESC (stable, PK-backed)
                . " ORDER BY n.id DESC LIMIT ?"
                . ($cursor ? "" : " OFFSET ?");
            $types = 'i' . 'si' . ($typeOk ? 's' : '') . 'ii';   // + beforeId/limit (cursor) or limit/offset (legacy)
            $params = [$userId, $role, $userId];
            if ($typeOk) { $params[] = $typeFilter; }
            if ($cursor) { $params[] = $beforeId; }
            $params[] = $fetch;
            if (!$cursor) { $params[] = $offset; }
            $stmt = $conn->prepare($sql);
            if (!$stmt) { return $empty; }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['data'] = $row['data'] ? json_decode($row['data'], true) : null;
                $row['is_unread'] = ($row['my_read_at'] === null) ? 1 : 0;
                // P1 (mobile UX audit C1): additive routing target — what
                // the alert is ABOUT, so clients can deep-link. Purely
                // additive: older clients and the web ignore the field.
                $row['target'] = self::targetFor($row);
                $rows[] = $row;
            }
            $stmt->close();
            $hasMore = count($rows) > $limit;
            if ($hasMore) { array_pop($rows); }   // drop the probe row
            return [
                'rows'   => $rows,
                'total'  => (int)($agg['c'] ?? 0),
                'unread' => (int)($agg['u'] ?? 0),
                // cursor for the next older page (null = no more pages)
                'next_before' => ($hasMore && isset($rows[$limit - 1]['id']))
                    ? (int)$rows[$limit - 1]['id'] : null,
                'has_more'    => $hasMore,
            ];
        } catch (\Exception $e) {
            return $empty;
        }
    }

    /**
     * P1 (mobile UX audit C1) — routing target for a feed row: what
     * the alert is about, derived from type + data payload. Today's
     * producers all carry either member_id (member_registered /
     * member_archived / role_changed / class_enrolled /
     * attendance_alert / attendance_issue) or task_id +
     * related_member_id (task_assigned — mobile has no tasks surface
     * by scope, so a task routes to the member it references when
     * one exists). Rows without a usable reference get null —
     * clients simply mark those read, as before. No schema change.
     */
    private static function targetFor(array $row): ?array
    {
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        $memberId = isset($data['member_id']) ? (int)$data['member_id'] : 0;
        if ($memberId > 0) {
            return ['kind' => 'member', 'id' => $memberId];
        }
        $relatedId = isset($data['related_member_id']) ? (int)$data['related_member_id'] : 0;
        if ($relatedId > 0) {
            return ['kind' => 'member', 'id' => $relatedId];
        }
        return null;
    }

    /** Mark one notification read for THIS user (recipient-checked). */
    public static function markRead(\mysqli $conn, int $userId, string $role, int $notificationId): bool
    {
        try {
            if ($userId <= 0 || $notificationId <= 0) { return false; }
            $find = $conn->prepare(
                "SELECT id FROM notifications WHERE id = ?
                 AND (FIND_IN_SET(?, target_roles) > 0
                      OR target_user_id = ?
                      OR (target_roles IS NULL AND target_user_id IS NULL))"
            );
            $find->bind_param('isi', $notificationId, $role, $userId);
            $find->execute();
            if (!$find->get_result()->fetch_assoc()) { $find->close(); return false; }
            $find->close();

            // Legacy global flag — still written for backward
            // compatibility (pinned audit test reads this column).
            $conn->query("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = " . (int)$notificationId);

            $up = $conn->prepare(
                "INSERT INTO notification_reads (user_id, subject_type, subject_id, read_at)
                 VALUES (?, 'notification', ?, NOW())
                 ON DUPLICATE KEY UPDATE read_at = NOW()"
            );
            $up->bind_param('ii', $userId, $notificationId);
            $ok = $up->execute();
            $up->close();
            return $ok;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Mark ALL notifications addressed to me read — for ME only. */
    public static function markAllRead(\mysqli $conn, int $userId, string $role): bool
    {
        try {
            if ($userId <= 0) { return false; }
            $stmt = $conn->prepare(
                "INSERT INTO notification_reads (user_id, subject_type, subject_id, read_at)
                 SELECT ?, 'notification', n.id, NOW()
                 FROM notifications n
                 WHERE (FIND_IN_SET(?, n.target_roles) > 0
                        OR n.target_user_id = ?
                        OR (n.target_roles IS NULL AND n.target_user_id IS NULL))
                   AND NOT EXISTS (
                        SELECT 1 FROM notification_reads nr2
                        WHERE nr2.subject_type = 'notification'
                          AND nr2.subject_id = n.id AND nr2.user_id = ?
                   )
                 ON DUPLICATE KEY UPDATE read_at = NOW()"
            );
            $stmt->bind_param('isii', $userId, $role, $userId, $userId);
            $ok = $stmt->execute();
            $stmt->close();

            // Legacy global flag: everything addressed to me is read by
            // at least one recipient (me) — the meaning it always had.
            $legacy = $conn->prepare(
                "UPDATE notifications SET is_read = 1, read_at = NOW()
                 WHERE is_read = 0
                   AND (FIND_IN_SET(?, target_roles) > 0
                        OR target_user_id = ?
                        OR (target_roles IS NULL AND target_user_id IS NULL))"
            );
            $legacy->bind_param('si', $role, $userId);
            $legacy->execute();
            $legacy->close();
            return $ok;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ────────────────────────────────────────────────────────────
    //  ANNOUNCEMENTS
    // ────────────────────────────────────────────────────────────

    /**
     * Publish an announcement (permission-checked here — the API never
     * decides). Returns ['ok'=>bool,'id'=>?int,'error'=>?string].
     */
    public static function postAnnouncement(\mysqli $conn, int $userId, string $role, string $title, string $body, string $priority, string $audienceType, array $targetRoles, array $targetUserIds): array
    {
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            return ['ok' => false, 'error' => 'Title and message are required.'];
        }
        if (mb_strlen($title) > 200) {
            return ['ok' => false, 'error' => 'Title is too long (max 200 characters).'];
        }
        if (!in_array($priority, ['normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        if (!in_array($audienceType, ['roles', 'users'], true)) {
            return ['ok' => false, 'error' => 'Invalid audience.'];
        }
        if (!self::canAnnounce($role)) {
            return ['ok' => false, 'error' => 'Your role cannot publish announcements.'];
        }
        $allowedRoles = self::announceAudience($role);
        $wildcard = in_array('*', $allowedRoles, true);

        if ($audienceType === 'roles') {
            if (empty($targetRoles)) {
                return ['ok' => false, 'error' => 'Choose at least one audience group.'];
            }
            foreach ($targetRoles as $r) {
                if (!self::isKnownRole($r) || (!$wildcard && !in_array($r, $allowedRoles, true))) {
                    return ['ok' => false, 'error' => 'You cannot announce to ' . (self::ROLE_LABELS[$r] ?? 'that group') . '.'];
                }
            }
            $rolesStr = implode(',', array_values(array_unique($targetRoles)));
            $userIdsCsv = null;
        } else {
            $ids = array_values(array_unique(array_map('intval', $targetUserIds)));
            $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
            if (empty($ids)) {
                return ['ok' => false, 'error' => 'Choose at least one recipient.'];
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $chk = $conn->prepare("SELECT role FROM users WHERE id IN ($ph) AND is_active = 1");
            $chk->bind_param(str_repeat('i', count($ids)), ...$ids);
            $chk->execute();
            $res = $chk->get_result();
            $foundRoles = [];
            while ($row = $res->fetch_assoc()) {
                $foundRoles[] = $row['role'];
            }
            $chk->close();
            if (count($foundRoles) !== count($ids)) {
                return ['ok' => false, 'error' => 'One or more recipients do not exist.'];
            }
            foreach ($foundRoles as $fr) {
                if (!$wildcard && !in_array($fr, $allowedRoles, true)) {
                    return ['ok' => false, 'error' => 'You cannot announce to ' . (self::ROLE_LABELS[$fr] ?? 'that user') . '.'];
                }
            }
            $rolesStr = null;
            $userIdsCsv = ',' . implode(',', $ids) . ',';
        }

        try {
            $stmt = $conn->prepare(
                "INSERT INTO announcements (title, body, priority, audience_type, target_roles, target_user_ids, created_by, source_dept, is_pinned)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
            );
            $stmt->bind_param('ssssssis', $title, $body, $priority, $audienceType, $rolesStr, $userIdsCsv, $userId, $role);
            $ok = $stmt->execute();
            $id = (int)$conn->insert_id;
            $stmt->close();
            if (!$ok) { return ['ok' => false, 'error' => 'Could not publish the announcement.']; }
            return ['ok' => true, 'id' => $id];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Could not publish the announcement.'];
        }
    }

    /**
     * Announcements addressed to me, pinned first then newest, with
     * my read state. target_user_ids is a padded CSV (",7,9,").
     */
    /**
     * Cursor paging by the exact sort key (is_pinned, id) DESC — stable,
     * PK-backed, immune to the offset skip problem. The cursor is the
     * LAST row's own (is_pinned, id) tuple: `$beforePin`+`$beforeId`.
     * Legacy limit/offset path kept for older clients.
     *
     * @return array{rows:array, next_pin:int|null, next_before:int|null, has_more:bool}
     */
    public static function listAnnouncements(\mysqli $conn, int $userId, string $role, int $limit = 30, int $offset = 0, ?int $beforeId = null, ?int $beforePin = null): array
    {
        $out = ['rows' => [], 'next_pin' => null, 'next_before' => null, 'has_more' => false];
        try {
            $cursor = ($beforeId !== null && $beforeId > 0 && $beforePin !== null);
            $fetch = $limit + 1;   // +1 = cheap has_more probe on every page
            $sql =
                "SELECT a.*, u.full_name AS author_name,
                        (nr.id IS NULL) AS is_unread
                 FROM announcements a
                 JOIN users u ON u.id = a.created_by
                 LEFT JOIN notification_reads nr
                   ON nr.subject_type = 'announcement' AND nr.subject_id = a.id AND nr.user_id = ?
                 WHERE (a.expires_at IS NULL OR a.expires_at > NOW())
                   AND (
                        (a.audience_type = 'roles'
                            AND (FIND_IN_SET(?, a.target_roles) > 0 OR a.target_roles IS NULL OR a.target_roles = ''))
                     OR (a.audience_type = 'users'
                            AND a.target_user_ids LIKE CONCAT('%,', ?, ',%'))
                   )"
                . ($cursor ? " AND (a.is_pinned, a.id) < (?, ?)" : "")
                // one ordering for both paths — (is_pinned, id) DESC
                . " ORDER BY a.is_pinned DESC, a.id DESC LIMIT ?"
                . ($cursor ? "" : " OFFSET ?");
            $types = 'iss' . ($cursor ? 'ii' : '') . 'i' . ($cursor ? '' : 'i');
            $params = [$userId, $role, $userId];
            if ($cursor) { $params[] = (int)$beforePin; $params[] = $beforeId; }   // last row's own tuple
            $params[] = $fetch;
            if (!$cursor) { $params[] = $offset; }
            $stmt = $conn->prepare($sql);
            if (!$stmt) { return $out; }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['is_unread'] = (int)$row['is_unread'];
                $row['author_label'] = self::ROLE_LABELS[$row['source_dept']] ?? $row['source_dept'];
                $rows[] = $row;
            }
            $stmt->close();
            $hasMore = count($rows) > $limit;
            if ($hasMore) { array_pop($rows); }
            $out['rows'] = $rows;
            $out['has_more'] = $hasMore;
            $last = $rows ? $rows[count($rows) - 1] : null;
            if ($last && ($hasMore || count($rows) === $limit)) {
                $out['next_pin'] = (int)($last['is_pinned'] ?? 0);
                $out['next_before'] = (int)$last['id'];
            }
            return $out;
        } catch (\Exception $e) {
            return $out;
        }
    }

    public static function markAnnouncementRead(\mysqli $conn, int $userId, int $announcementId): bool
    {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO notification_reads (user_id, subject_type, subject_id, read_at)
                 VALUES (?, 'announcement', ?, NOW())
                 ON DUPLICATE KEY UPDATE read_at = NOW()"
            );
            $stmt->bind_param('ii', $userId, $announcementId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Mark ALL announcements addressed to me read (for me only). */
    public static function markAllAnnouncementsRead(\mysqli $conn, int $userId, string $role): bool
    {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO notification_reads (user_id, subject_type, subject_id, read_at)
                 SELECT ?, 'announcement', a.id, NOW()
                 FROM announcements a
                 WHERE (a.expires_at IS NULL OR a.expires_at > NOW())
                   AND (
                        (a.audience_type = 'roles'
                            AND (FIND_IN_SET(?, a.target_roles) > 0 OR a.target_roles IS NULL OR a.target_roles = ''))
                     OR (a.audience_type = 'users'
                            AND a.target_user_ids LIKE CONCAT('%,', ?, ',%'))
                   )
                   AND NOT EXISTS (
                        SELECT 1 FROM notification_reads nr2
                        WHERE nr2.subject_type = 'announcement'
                          AND nr2.subject_id = a.id AND nr2.user_id = ?
                   )
                 ON DUPLICATE KEY UPDATE read_at = NOW()"
            );
            $stmt->bind_param('issi', $userId, $role, $userId, $userId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Who may I address? Roles + concrete users (for the picker).
     * @return array{roles:array<string,string>,users:array<int,array{id:int,name:string,role:string,label:string}>}
     */
    public static function announceTargets(\mysqli $conn, string $role, int $userId): array
    {
        $allowedRoles = self::announceAudience($role);
        $roles = [];
        foreach ($allowedRoles as $r) {
            if (self::isKnownRole($r)) {
                $roles[$r] = self::ROLE_LABELS[$r];
            }
        }
        $users = [];
        if ($roles !== []) {
            try {
                $ph = implode(',', array_fill(0, count($roles), '?'));
                $stmt = $conn->prepare(
                    "SELECT id, full_name, role FROM users
                     WHERE is_active = 1 AND role IN ($ph) AND id <> ?
                     ORDER BY role, full_name LIMIT 500"
                );
                $params = array_keys($roles);
                $params[] = $userId;
                $stmt->bind_param(str_repeat('s', count($roles)) . 'i', ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $row['id'] = (int)$row['id'];
                    $row['label'] = $row['full_name'] . ' — ' . (self::ROLE_LABELS[$row['role']] ?? $row['role']);
                    $users[] = $row;
                }
                $stmt->close();
            } catch (\Exception $e) {
                /* users list optional — roles still returned */
            }
        }
        return ['roles' => $roles, 'users' => $users];
    }

    // ────────────────────────────────────────────────────────────
    //  MESSAGING (two-way threads)
    // ────────────────────────────────────────────────────────────

    /**
     * Start a thread: subject + participants + first message. Every
     * participant's role must be messageable by the creator.
     */
    public static function startThread(\mysqli $conn, int $userId, string $role, string $subject, array $participantIds, string $body): array
    {
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            return ['ok' => false, 'error' => 'Subject and message are required.'];
        }
        if (mb_strlen($subject) > 200) {
            return ['ok' => false, 'error' => 'Subject is too long (max 200 characters).'];
        }
        if (!self::canMessage($role)) {
            return ['ok' => false, 'error' => 'Messaging is not available for your role.'];
        }
        $ids = array_values(array_unique(array_map('intval', array_merge($participantIds, [$userId]))));
        $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
        if (count($ids) < 2) {
            return ['ok' => false, 'error' => 'Choose at least one recipient.'];
        }
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $chk = $conn->prepare("SELECT id, role FROM users WHERE id IN ($ph) AND is_active = 1");
            $chk->bind_param(str_repeat('i', count($ids)), ...$ids);
            $chk->execute();
            $foundRoles = [];
            $res = $chk->get_result();
            while ($row = $res->fetch_assoc()) {
                $foundRoles[(int)$row['id']] = $row['role'];
            }
            $chk->close();
            foreach ($ids as $id) {
                if (!isset($foundRoles[$id])) {
                    return ['ok' => false, 'error' => 'One or more recipients do not exist.'];
                }
                if ($id !== $userId && !self::mayMessageRole($role, $foundRoles[$id])) {
                    return ['ok' => false, 'error' => 'You cannot message ' . (self::ROLE_LABELS[$foundRoles[$id]] ?? 'that user') . '.'];
                }
            }

            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO message_threads (subject, created_by, last_message_at) VALUES (?, ?, NOW())");
            $stmt->bind_param('si', $subject, $userId);
            $stmt->execute();
            $threadId = (int)$conn->insert_id;
            $stmt->close();

            foreach ($ids as $id) {
                $stmt = $conn->prepare("INSERT IGNORE INTO message_thread_participants (thread_id, user_id, added_by) VALUES (?, ?, ?)");
                $stmt->bind_param('iii', $threadId, $id, $userId);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare("INSERT INTO messages (thread_id, sender_id, body) VALUES (?, ?, ?)");
            $stmt->bind_param('iis', $threadId, $userId, $body);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            return ['ok' => true, 'id' => $threadId];
        } catch (\Exception $e) {
            try { $conn->rollback(); } catch (\Exception $e2) {}
            return ['ok' => false, 'error' => 'Could not start the conversation.'];
        }
    }

    /** My threads: last message, unread count, participant labels. */
    /**
     * Cursor paging by the exact sort key (last_message_at, id) DESC.
     * `$before = [last_message_at, id]` = the last row's own tuple.
     * Threads with NULL last_message_at (no messages yet — practically
     * impossible since startThread always writes a first message) are
     * excluded so the sort key is never NULL.
     *
     * @return array{threads:array, next:array|null, has_more:bool}
     */
    public static function threadsFor(\mysqli $conn, int $userId, int $limit = 50, ?array $before = null): array
    {
        $out = ['threads' => [], 'next' => null, 'has_more' => false];
        try {
            $cursor = (is_array($before) && count($before) === 2
                && $before[0] !== null && (int)$before[1] > 0);
            $fetch = $limit + 1;   // +1 = cheap has_more probe
            $sql =
                "SELECT t.id, t.subject, t.created_by, t.last_message_at, t.created_at,
                        (SELECT COUNT(*) FROM messages m WHERE m.thread_id = t.id) AS message_count,
                        (SELECT m2.body FROM messages m2 WHERE m2.thread_id = t.id ORDER BY m2.id DESC LIMIT 1) AS last_body,
                        (SELECT m3.sender_id FROM messages m3 WHERE m3.thread_id = t.id ORDER BY m3.id DESC LIMIT 1) AS last_sender_id,
                        (nr.read_at IS NULL) AS has_unread,
                        (SELECT COUNT(*) FROM messages m4
                          WHERE m4.thread_id = t.id
                            AND m4.created_at > COALESCE(nr.read_at, '1970-01-01')
                            AND m4.sender_id <> ?) AS unread_count,
                        (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ')
                           FROM message_thread_participants p2 JOIN users u ON u.id = p2.user_id
                          WHERE p2.thread_id = t.id AND p2.user_id <> ?) AS participants_label
                 FROM message_threads t
                 JOIN message_thread_participants p ON p.thread_id = t.id AND p.user_id = ?
                 LEFT JOIN notification_reads nr
                   ON nr.subject_type = 'message_thread' AND nr.subject_id = t.id AND nr.user_id = ?
                 WHERE t.last_message_at IS NOT NULL"
                . ($cursor ? " AND (t.last_message_at, t.id) < (?, ?)" : "")
                . " ORDER BY t.last_message_at DESC, t.id DESC
                 LIMIT ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) { return $out; }
            if ($cursor) {
                // bind_param takes args BY REFERENCE — inline expressions
                // (casts) fatal; bind locals instead
                $bLm = (string)$before[0];
                $bId = (int)$before[1];
                $stmt->bind_param('iiiisii', $userId, $userId, $userId, $userId, $bLm, $bId, $fetch);
            } else {
                $stmt->bind_param('iiiii', $userId, $userId, $userId, $userId, $fetch);
            }
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['unread_count'] = (int)$row['unread_count'];
                $row['has_unread'] = (int)$row['has_unread'];
                $rows[] = $row;
            }
            $stmt->close();
            $hasMore = count($rows) > $limit;
            if ($hasMore) { array_pop($rows); }
            $out['threads'] = $rows;
            $out['has_more'] = $hasMore;
            $last = $rows ? $rows[count($rows) - 1] : null;
            if ($last && ($hasMore || count($rows) === $limit) && $last['last_message_at'] !== null) {
                $out['next'] = [$last['last_message_at'], (int)$last['id']];
            }
            return $out;
        } catch (\Exception $e) {
            return $out;
        }
    }

    /** All messages of a thread I belong to. */
    public static function threadMessages(\mysqli $conn, int $userId, int $threadId, int $limit = 200, ?int $beforeId = null): array
    {
        try {
            $chk = $conn->prepare("SELECT 1 FROM message_thread_participants WHERE thread_id = ? AND user_id = ?");
            $chk->bind_param('ii', $threadId, $userId);
            $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) { $chk->close(); return ['ok' => false, 'error' => 'Not your conversation.']; }
            $chk->close();

            // Telegram-grade management (P73): edited marker + soft-delete
            // tombstone. A deleted message NEVER ships its body.
            // HARDENING (P73 incident 2026-09-12): edited_at/deleted_at come
            // from sql/044 and PHP 8.1+ mysqli THROWS on unknown columns
            // (older stacks return a false prepare instead), so the fetch
            // gets its OWN guard and an automatic fallback without the
            // management columns — a missing migration degrades to "no
            // markers", NEVER to a broken conversation. Mirrors the
            // read-receipt guard further down.
            // P73 Phase 5: window = the NEWEST $limit messages (id DESC,
            // then reversed for delivery). `$beforeId` pages older — the
            // old ASC+LIMIT returned the OLDEST messages of long threads,
            // which was wrong for chat. The +1 probe reports has_older.
            $page = self::fetchThreadRows($conn, $userId, $threadId, $limit, true, $beforeId);
            if ($page === null) {
                $page = self::fetchThreadRows($conn, $userId, $threadId, $limit, false, $beforeId);
            }
            if ($page === null) {
                return ['ok' => false, 'error' => 'Could not load the conversation.'];
            }
            $hasOlder = count($page) > $limit;
            if ($hasOlder) { array_pop($page); }
            $rows = array_reverse($page);               // id DESC → ASC for render
            $oldestId = $rows ? (int)$rows[0]['id'] : 0;

            // Read receipts (P73 Phase 3): the highest message id that every
            // OTHER participant has read. My message shows ✓✓ once its id is
            // <= this watermark. NULL watermarks count as 0 (never opened).
            // HARDENING (P73 incident 2026-09-12): this column comes from
            // sql/043 and PHP 8.1+ mysqli THROWS on unknown columns, so the
            // whole receipt lookup gets its OWN try/catch — a missing
            // migration degrades to "no receipts", NEVER to a broken
            // conversation.
            $wm = 0;
            try {
                $wstmt = $conn->prepare(
                    "SELECT MAX(last_read_message_id) AS wm
                     FROM message_thread_participants
                     WHERE thread_id = ? AND user_id <> ?"
                );
                if ($wstmt) {
                    $wstmt->bind_param('ii', $threadId, $userId);
                    $wstmt->execute();
                    $wrow = $wstmt->get_result()->fetch_assoc();
                    $wstmt->close();
                    $wm = (int)($wrow['wm'] ?? 0);
                }
            } catch (\Exception $wmEx) {
                $wm = 0;   // sql/043 not applied yet — receipts unavailable
            }
            return ['ok' => true, 'messages' => $rows, 'read_watermark' => $wm,
                'has_older' => $hasOlder, 'oldest_id' => $oldestId];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Could not load the conversation.'];
        }
    }

    /** Fetch a thread's message rows, NEWEST FIRST (id DESC), limit+1 rows
     *  (the extra row is the has_older probe). $withMeta = include the
     *  sql/044 edited_at/deleted_at columns; without them edited/deleted
     *  degrade to 0. $beforeId = page older than that message id (null =
     *  newest window). Returns null when the query cannot run (missing
     *  migration columns, false prepare in non-throw mysqli mode, or DB
     *  error). */
    private static function fetchThreadRows(\mysqli $conn, int $userId, int $threadId, int $limit, bool $withMeta, ?int $beforeId = null): ?array
    {
        try {
            $meta = $withMeta
                ? 'm.edited_at, m.deleted_at,'
                : 'NULL AS edited_at, NULL AS deleted_at,';
            $cursor = ($beforeId !== null && $beforeId > 0);
            $stmt = $conn->prepare(
                "SELECT m.id, m.sender_id, m.body, m.created_at, $meta
                        u.full_name AS sender_name, u.role AS sender_role
                 FROM messages m JOIN users u ON u.id = m.sender_id
                 WHERE m.thread_id = ?"
                . ($cursor ? " AND m.id < ?" : "")
                . " ORDER BY m.id DESC LIMIT ?"
            );
            if (!$stmt) { return null; }   // non-throw mysqli error mode
            $fetchN = $limit + 1;           // +1 probe row (locals: bind_param is by-ref)
            if ($cursor) {
                $stmt->bind_param('iii', $threadId, $beforeId, $fetchN);
            } else {
                $stmt->bind_param('ii', $threadId, $fetchN);
            }
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['sender_label'] = self::ROLE_LABELS[$row['sender_role']] ?? $row['sender_role'];
                $row['mine'] = ((int)$row['sender_id'] === $userId) ? 1 : 0;
                $row['edited'] = ($row['edited_at'] !== null && $row['deleted_at'] === null) ? 1 : 0;
                $row['deleted'] = ($row['deleted_at'] !== null) ? 1 : 0;
                if ($row['deleted'] === 1) { $row['body'] = ''; }
                unset($row['edited_at'], $row['deleted_at']);
                $rows[] = $row;
            }
            $stmt->close();
            return $rows;
        } catch (\Exception $e) {
            return null;   // throw mode — e.g. sql/044 columns not applied yet
        }
    }

    /**
     * Reply to a thread I belong to.
     *
     * O4 (046) — client_tag makes sends exactly-once. A retried drain
     * (crash mid-POST, timeout after commit, double-drain) carries the
     * SAME tag; the unique index uk_client_tag is the arbiter and a
     * duplicate becomes a replay SUCCESS so the phone deletes its
     * outbox row. Pre-046 servers (column absent) transparently fall
     * back to today's tagless behavior — deploy order is free.
     */
    public static function sendMessage(\mysqli $conn, int $userId, int $threadId, string $body, ?string $clientTag = null): array
    {
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Message cannot be empty.'];
        }
        if (mb_strlen($body) > 5000) {
            return ['ok' => false, 'error' => 'Message is too long (max 5000 characters).'];
        }
        $clientTag = self::normalizeClientTag($clientTag);
        try {
            $chk = $conn->prepare("SELECT 1 FROM message_thread_participants WHERE thread_id = ? AND user_id = ?");
            $chk->bind_param('ii', $threadId, $userId);
            $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) { $chk->close(); return ['ok' => false, 'error' => 'Not your conversation.']; }
            $chk->close();

            if ($clientTag !== null && self::messagesHaveClientTag($conn)) {
                // Fast path: the common replay (retry after timeout)
                // finds its row without touching last_message_at.
                $dup = $conn->prepare("SELECT id FROM messages WHERE client_tag = ? LIMIT 1");
                $dup->bind_param('s', $clientTag);
                $dup->execute();
                $existing = $dup->get_result()->fetch_assoc();
                $dup->close();
                if ($existing) {
                    return ['ok' => true, 'replayed' => true, 'id' => (int)$existing['id']];
                }
                try {
                    $stmt = $conn->prepare("INSERT INTO messages (thread_id, sender_id, body, client_tag) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('iiss', $threadId, $userId, $body, $clientTag);
                    $stmt->execute();
                    $ok = $stmt->affected_rows > 0;
                    $stmt->close();
                } catch (\Exception $race) {
                    // 1062 = the tiny check-then-insert race window: a
                    // concurrent identical POST won the index. The
                    // message EXISTS — exactly-once says that is success.
                    $errno = (int)$race->getCode();
                    if ($errno === 1062 || (int)$conn->errno === 1062) {
                        return ['ok' => true, 'replayed' => true];
                    }
                    throw $race;
                }
                if (!$ok) { return ['ok' => false, 'error' => 'Could not send the message.']; }
            } else {
                // Web sends, pre-1.4.0 clients, or a pre-046 schema:
                // exactly today's behavior, byte for byte.
                $stmt = $conn->prepare("INSERT INTO messages (thread_id, sender_id, body) VALUES (?, ?, ?)");
                $stmt->bind_param('iis', $threadId, $userId, $body);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) { return ['ok' => false, 'error' => 'Could not send the message.']; }
            }
            $conn->query("UPDATE message_threads SET last_message_at = NOW() WHERE id = " . (int)$threadId);
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Could not send the message.'];
        }
    }

    /**
     * O4 (046) — a client_tag must be a short, URL-safe token (the app
     * sends a 36-char v4 UUID). Anything else is IGNORED, never a hard
     * failure: a weird tag degrades to a tagless send, not a lost one.
     */
    private static function normalizeClientTag(?string $tag): ?string
    {
        if ($tag === null) { return null; }
        $tag = trim($tag);
        if ($tag === '' || strlen($tag) > 64 || !preg_match('/^[A-Za-z0-9._-]+$/', $tag)) {
            return null;
        }
        return $tag;
    }

    /**
     * O4 (046) — does the schema have messages.client_tag yet? Probed
     * once per request (static), only when a tag is present; a missing
     * column means the tag rides along unstored — pre-046 servers keep
     * working exactly as before.
     */
    private static function messagesHaveClientTag(\mysqli $conn): bool
    {
        if (self::$messagesClientTag !== null) { return self::$messagesClientTag; }
        try {
            $res = $conn->query("SHOW COLUMNS FROM `messages` LIKE 'client_tag'");
            self::$messagesClientTag = ($res !== false && $res->num_rows > 0);
            if ($res !== false) { $res->free(); }
        } catch (\Exception $e) {
            self::$messagesClientTag = false;
        }
        return self::$messagesClientTag;
    }

    public static function markThreadRead(\mysqli $conn, int $userId, int $threadId): bool
    {
        try {
            $chk = $conn->prepare("SELECT 1 FROM message_thread_participants WHERE thread_id = ? AND user_id = ?");
            $chk->bind_param('ii', $threadId, $userId);
            $chk->execute();
            $mine = (bool)$chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$mine) { return false; }
            $stmt = $conn->prepare(
                "INSERT INTO notification_reads (user_id, subject_type, subject_id, read_at)
                 VALUES (?, 'message_thread', ?, NOW())
                 ON DUPLICATE KEY UPDATE read_at = NOW()"
            );
            $stmt->bind_param('ii', $userId, $threadId);
            // Read receipts (P73 Phase 3): advance my per-participant watermark
            // to the newest message so the SENDER sees ✓✓. HARDENING (P73
            // incident 2026-09-12): PHP 8.1+ mysqli throws on the unknown
            // column pre-043 — the update gets its OWN try/catch so the
            // read-state INSERT below always runs (unread badges keep
            // clearing even without receipts).
            try {
                $wmStmt = $conn->prepare(
                    "UPDATE message_thread_participants p
                     SET p.last_read_message_id = (
                         SELECT MAX(m.id) FROM messages m WHERE m.thread_id = p.thread_id
                     )
                     WHERE p.thread_id = ? AND p.user_id = ?"
                );
                if ($wmStmt) {
                    $wmStmt->bind_param('ii', $threadId, $userId);
                    $wmStmt->execute();
                    $wmStmt->close();
                }
            } catch (\Exception $wmEx) {
                // sql/043 not applied yet — receipts unavailable, read state below still persists
            }
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Users I may start a conversation with (picker source).
     * @return array<int,array{id:int,name:string,role:string,label:string}>
     */
    /** Edit one of MY messages (Telegram-grade management, P73).
     *  Ownership is enforced in SQL (sender_id = ?) AND the message must
     *  belong to a thread I participate in. Deleted messages are frozen. */
    public static function editMessage(\mysqli $conn, int $userId, int $messageId, string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'Message cannot be empty.'];
        }
        if (mb_strlen($body) > 5000) {
            return ['ok' => false, 'error' => 'Message is too long (max 5000 characters).'];
        }
        try {
            $stmt = $conn->prepare(
                "UPDATE messages m
                 JOIN message_thread_participants p ON p.thread_id = m.thread_id AND p.user_id = ?
                 SET m.body = ?, m.edited_at = NOW()
                 WHERE m.id = ? AND m.sender_id = ? AND m.deleted_at IS NULL"
            );
            $stmt->bind_param('isii', $userId, $body, $messageId, $userId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed === 0) {
                return ['ok' => false, 'error' => 'You can only edit your own messages.'];
            }
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Could not edit the message.'];
        }
    }

    /** Soft-delete one of MY messages — participants see a tombstone
     *  ("This message was deleted"), the body is never returned again. */
    public static function deleteMessage(\mysqli $conn, int $userId, int $messageId): array
    {
        try {
            $stmt = $conn->prepare(
                "UPDATE messages m
                 JOIN message_thread_participants p ON p.thread_id = m.thread_id AND p.user_id = ?
                 SET m.deleted_at = NOW()
                 WHERE m.id = ? AND m.sender_id = ? AND m.deleted_at IS NULL"
            );
            $stmt->bind_param('iii', $userId, $messageId, $userId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed === 0) {
                return ['ok' => false, 'error' => 'You can only delete your own messages.'];
            }
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Could not delete the message.'];
        }
    }

    public static function messagePartners(\mysqli $conn, string $role, int $userId): array
    {
        $allowed = self::MESSAGE_PARTNERS[$role] ?? [];
        if ($allowed === []) {
            return [];
        }
        $all = in_array('*', $allowed, true) ? array_keys(self::ROLE_LABELS) : $allowed;
        $out = [];
        try {
            $ph = implode(',', array_fill(0, count($all), '?'));
            $stmt = $conn->prepare(
                "SELECT id, full_name, role FROM users
                 WHERE is_active = 1 AND role IN ($ph) AND id <> ?
                 ORDER BY role, full_name LIMIT 500"
            );
            $params = $all;
            $params[] = $userId;
            $stmt->bind_param(str_repeat('s', count($all)) . 'i', ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['label'] = $row['full_name'] . ' — ' . (self::ROLE_LABELS[$row['role']] ?? $row['role']);
                $out[] = $row;
            }
            $stmt->close();
        } catch (\Exception $e) {
            /* empty list on failure */
        }
        return $out;
    }

    // ────────────────────────────────────────────────────────────
    //  AGGREGATE BADGE (one poll = one query set)
    // ────────────────────────────────────────────────────────────

    /**
     * Unread counts for the bell: alerts, announcements, message
     * threads with new messages, pending tasks, and the total.
     */
    /**
     * P73 Phase 5 — cheap version string for the summary poll (ETag seed).
     * Every signal that can change ANY number in unreadSummary() is folded
     * in, so a matching version guarantees an unchanged summary:
     *   - MAX(notifications.id)        any new notification (any target)
     *   - my MAX(notification_reads.read_at)  anything I read / marked
     *   - MAX(announcements.id)        any new announcement
     *   - MAX(messages.id)             any new message (any thread)
     *   - task counts for my dept/user status transitions (department_tasks)
     *   - a 60-second bucket           announcements EXPIRE with NOW()
     * Returns null when any component cannot be read — the caller must
     * then SKIP the 304 shortcut and answer with a full recomputed
     * summary (never serve a possibly-stale 304).
     */
    public static function summaryVersion(\mysqli $conn, int $userId, string $role): ?string
    {
        if ($userId <= 0) { return null; }
        $parts = [];
        $q = function (string $sql, array $params, string $types) use ($conn): ?string {
            try {
                $stmt = $conn->prepare($sql);
                if (!$stmt) { return null; }          // non-throw mysqli mode
                if ($params) { $stmt->bind_param($types, ...$params); }   // no params → no bind (empty $types is a ValueError on PHP 8)
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                return ($row === null) ? null : implode('|', array_map(
                    static function ($v) { return ($v === null || $v === '') ? '0' : (string)$v; },
                    array_values($row)
                ));
            } catch (\Throwable $e) {                 // version failure must NEVER serve a stale 304
                return null;
            }
        };
        $parts[] = $q("SELECT COALESCE(MAX(id), 0) v FROM notifications", [], '');
        $parts[] = $q("SELECT COALESCE(MAX(read_at), 0) v FROM notification_reads WHERE user_id = ?", [$userId], 'i');
        $parts[] = $q("SELECT COALESCE(MAX(id), 0) v FROM announcements", [], '');
        $parts[] = $q("SELECT COALESCE(MAX(id), 0) v FROM messages", [], '');
        // task badge: count of open tasks + how far along they are — every
        // status transition changes at least one of these numbers
        $parts[] = $q(
            "SELECT COUNT(*) c, SUM(status = 'pending') p, SUM(status = 'in_progress') i
             FROM department_tasks
             WHERE status IN ('pending','in_progress')
               AND (to_dept = ? OR to_user_id = ?)",
            [$role, $userId], 'si'
        );
        foreach ($parts as $p) { if ($p === null) { return null; } }
        // expiry bucket: an announcement can expire without any row
        // changing; the badge self-heals on the next full poll (≤ 60 s)
        $parts[] = (string)intdiv(time(), 60);
        return implode('~', $parts);
    }

    /**
     * P73 Phase 5 — cheap version string for ONE thread (ETag seed for
     * the open-conversation poll). Covers: new messages, edits, deletes,
     * other participants' read watermarks (✓✓), and my own watermark.
     * sql/043/044 columns are OPTIONAL (incident-round hardening): when
     * absent the component is skipped, not fatal. Returns null when a
     * REQUIRED component cannot be read → caller skips the 304 shortcut.
     */
    public static function threadVersion(\mysqli $conn, int $userId, int $threadId): ?string
    {
        if ($threadId <= 0) { return null; }
        // Participation-gated: a non-participant must NEVER receive an
        // ETag for someone else's thread (a replayable If-None-Match
        // would become a 304 activity oracle). null = no conditional
        // path → the caller answers with the normal permission error.
        try {
            $chk = $conn->prepare("SELECT 1 FROM message_thread_participants WHERE thread_id = ? AND user_id = ?");
            if (!$chk) { return null; }
            $chk->bind_param('ii', $threadId, $userId);
            $chk->execute();
            $isMember = (bool)$chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$isMember) { return null; }
        } catch (\Throwable $e) {
            return null;
        }
        try {
            $stmt = $conn->prepare("SELECT MAX(id) v FROM messages WHERE thread_id = ?");
            if (!$stmt) { return null; }
            $stmt->bind_param('i', $threadId);
            $stmt->execute();
            $maxId = $stmt->get_result()->fetch_assoc()['v'] ?? null;
            $stmt->close();
            if ($maxId === null) { $maxId = 0; }   // thread has no messages yet
        } catch (\Throwable $e) {
            return null;
        }
        $optional = [
            // sql/044 — edits/deletes change content without new ids
            "SELECT COALESCE(MAX(edited_at), '0') e, COALESCE(MAX(deleted_at), '0') d
             FROM messages WHERE thread_id = ?",
            // sql/043 — others' read watermarks drive the ✓✓ marks
            "SELECT COALESCE(MAX(last_read_message_id), 0) w
             FROM message_thread_participants WHERE thread_id = ? AND user_id <> ?",
        ];
        foreach ($optional as $i => $sql) {
            try {
                $stmt = $conn->prepare($sql);
                if (!$stmt) { continue; }
                if ($i === 0) { $stmt->bind_param('i', $threadId); }
                else { $stmt->bind_param('ii', $threadId, $userId); }
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $maxId .= '|' . implode('|', array_map(
                    static function ($v) { return ($v === null || $v === '') ? '0' : (string)$v; },
                    array_values($row ?: [])
                ));
            } catch (\Throwable $e) {
                $maxId .= '|skip' . $i;   // migration not applied — degrade, never break
            }
        }
        return (string)$maxId;
    }

    public static function unreadSummary(\mysqli $conn, int $userId, string $role): array
    {
        $out = ['alerts' => 0, 'announcements' => 0, 'messages' => 0, 'tasks' => 0, 'total' => 0];
        if ($userId <= 0) { return $out; }
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) c FROM notifications n
                 LEFT JOIN notification_reads nr
                   ON nr.subject_type = 'notification' AND nr.subject_id = n.id AND nr.user_id = ?
                 WHERE (FIND_IN_SET(?, n.target_roles) > 0
                        OR n.target_user_id = ?
                        OR (n.target_roles IS NULL AND n.target_user_id IS NULL))
                   AND nr.id IS NULL"
            );
            $stmt->bind_param('isi', $userId, $role, $userId);
            $stmt->execute();
            $out['alerts'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        } catch (\Exception $e) {}
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) c FROM announcements a
                 LEFT JOIN notification_reads nr
                   ON nr.subject_type = 'announcement' AND nr.subject_id = a.id AND nr.user_id = ?
                 WHERE (a.expires_at IS NULL OR a.expires_at > NOW())
                   AND (
                        (a.audience_type = 'roles'
                            AND (FIND_IN_SET(?, a.target_roles) > 0 OR a.target_roles IS NULL OR a.target_roles = ''))
                     OR (a.audience_type = 'users'
                            AND a.target_user_ids LIKE CONCAT('%,', ?, ',%'))
                   )
                   AND nr.id IS NULL"
            );
            $stmt->bind_param('iss', $userId, $role, $userId);
            $stmt->execute();
            $out['announcements'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        } catch (\Exception $e) {}
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) c FROM message_threads t
                 JOIN message_thread_participants p ON p.thread_id = t.id AND p.user_id = ?
                 LEFT JOIN notification_reads nr
                   ON nr.subject_type = 'message_thread' AND nr.subject_id = t.id AND nr.user_id = ?
                 WHERE EXISTS (
                     SELECT 1 FROM messages m
                     WHERE m.thread_id = t.id AND m.sender_id <> ?
                       AND m.created_at > COALESCE(nr.read_at, '1970-01-01')
                 )"
            );
            $stmt->bind_param('iii', $userId, $userId, $userId);
            $stmt->execute();
            $out['messages'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        } catch (\Exception $e) {}
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) c FROM department_tasks
                 WHERE status IN ('pending','in_progress')
                   AND (to_dept = ? OR to_user_id = ?)"
            );
            $stmt->bind_param('si', $role, $userId);
            $stmt->execute();
            $out['tasks'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        } catch (\Exception $e) {}
        $out['total'] = $out['alerts'] + $out['announcements'] + $out['messages'] + $out['tasks'];
        return $out;
    }
}
