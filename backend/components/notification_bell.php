<?php
/**
 * Notification Bell Component
 * Include this in any dashboard to show notification bell with dropdown
 * 
 * Usage:
 * <?php include __DIR__ . '/components/notification_bell.php'; ?>
 * 
 * Then in your header, add:
 * <?php echo renderNotificationBell(); ?>
 */

// Ensure workflow is loaded
if (!function_exists('getUnreadNotificationCount')) {
    require_once __DIR__ . '/../backend/workflow.php';
}

/**
 * Render the notification bell with dropdown
 */
function renderNotificationBell() {
    global $conn;
    
    $count = 0;
    $tasks = [];
    
    try {
        $count = getUnreadNotificationCount($conn);
        $tasks = getPendingTasks($conn, 5);
    } catch (Exception $e) {
        // Tables might not exist yet
    }
    
    $taskCount = count($tasks);
    $totalBadge = $count + $taskCount;
    
    ob_start();
    ?>
    <!-- Notification Bell -->
    <div class="notif-bell-container" id="notifBellContainer">
        <button class="notif-bell-btn" onclick="toggleNotifDropdown()" title="Notifications">
            <i class="fa-solid fa-bell"></i>
            <?php if ($totalBadge > 0): ?>
                <span class="notif-badge" id="notifBadge"><?= $totalBadge > 99 ? '99+' : $totalBadge ?></span>
            <?php endif; ?>
        </button>
        
        <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-header">
                <span>Notifications</span>
                <button onclick="markAllRead()" class="notif-mark-all">Mark all read</button>
            </div>
            
            <!-- Tabs -->
            <div class="notif-tabs">
                <button class="notif-tab active" onclick="switchNotifTab('alerts')">
                    Alerts <span class="notif-tab-count" id="alertsCount"><?= $count ?></span>
                </button>
                <button class="notif-tab" onclick="switchNotifTab('tasks')">
                    Tasks <span class="notif-tab-count" id="tasksCount"><?= $taskCount ?></span>
                </button>
            </div>
            
            <!-- Alerts Tab -->
            <div class="notif-content" id="alertsTab">
                <div class="notif-list" id="notifList">
                    <div class="notif-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>
            
            <!-- Tasks Tab -->
            <div class="notif-content hidden" id="tasksTab">
                <div class="notif-list" id="taskList">
                    <?php if (empty($tasks)): ?>
                        <div class="notif-empty"><i class="fa-solid fa-check-circle"></i> No pending tasks</div>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                            <div class="notif-item task-item" data-task-id="<?= $task['id'] ?>">
                                <div class="notif-icon task-<?= $task['priority'] ?>">
                                    <i class="fa-solid fa-tasks"></i>
                                </div>
                                <div class="notif-body">
                                    <div class="notif-title"><?= e($task['title']) ?></div>
                                    <div class="notif-meta">
                                        From: <?= e($task['from_user_name'] ?? getDeptDisplayName($task['from_dept'])) ?>
                                        <?php if ($task['related_member_id']): ?>
                                            • <?= e($task['student_name'] . ' ' . $task['father_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notif-actions">
                                        <button onclick="updateTask(<?= $task['id'] ?>, 'completed')" class="btn-task-done">
                                            <i class="fa-solid fa-check"></i> Done
                                        </button>
                                        <button onclick="updateTask(<?= $task['id'] ?>, 'in_progress')" class="btn-task-progress">
                                            <i class="fa-solid fa-clock"></i> In Progress
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .notif-bell-container {
            position: relative;
            display: inline-block;
        }
        
        .notif-bell-btn {
            background: rgba(255,255,255,0.1);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            color: white;
            cursor: pointer;
            position: relative;
            transition: all 0.2s;
        }
        
        .notif-bell-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: scale(1.05);
        }
        
        .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: white;
            font-size: 10px;
            font-weight: 600;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            animation: pulse-badge 2s infinite;
        }
        
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 360px;
            max-width: calc(100vw - 32px);
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            overflow: hidden;
        }
        
        .notif-dropdown.show {
            display: block;
            animation: slideDown 0.2s ease-out;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .notif-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        
        .notif-header span {
            font-weight: 600;
            color: #1e293b;
        }
        
        .notif-mark-all {
            font-size: 12px;
            color: #059669;
            background: none;
            border: none;
            cursor: pointer;
        }
        
        .notif-mark-all:hover {
            text-decoration: underline;
        }
        
        .notif-tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .notif-tab {
            flex: 1;
            padding: 12px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 13px;
            color: #64748b;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .notif-tab:hover {
            background: #f8fafc;
        }
        
        .notif-tab.active {
            color: #059669;
            border-bottom-color: #059669;
        }
        
        .notif-tab-count {
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 4px;
        }
        
        .notif-tab.active .notif-tab-count {
            background: #d1fae5;
            color: #065f46;
        }
        
        .notif-content {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .notif-content.hidden {
            display: none;
        }
        
        .notif-list {
            padding: 8px;
        }
        
        .notif-item {
            display: flex;
            gap: 12px;
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .notif-item:hover {
            background: #f1f5f9;
        }
        
        .notif-item.unread {
            background: #f0fdf4;
        }
        
        .notif-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .notif-icon.info { background: #dbeafe; color: #2563eb; }
        .notif-icon.success { background: #d1fae5; color: #059669; }
        .notif-icon.warning { background: #fef3c7; color: #d97706; }
        .notif-icon.error { background: #fee2e2; color: #dc2626; }
        .notif-icon.task-low { background: #e2e8f0; color: #64748b; }
        .notif-icon.task-normal { background: #dbeafe; color: #2563eb; }
        .notif-icon.task-high { background: #fef3c7; color: #d97706; }
        .notif-icon.task-urgent { background: #fee2e2; color: #dc2626; }
        
        .notif-body {
            flex: 1;
            min-width: 0;
        }
        
        .notif-title {
            font-size: 13px;
            font-weight: 500;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .notif-meta {
            font-size: 11px;
            color: #64748b;
        }
        
        .notif-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        
        .notif-actions button {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-task-done {
            background: #d1fae5;
            color: #065f46;
        }
        
        .btn-task-progress {
            background: #e2e8f0;
            color: #475569;
        }
        
        .notif-empty, .notif-loading {
            text-align: center;
            padding: 32px 16px;
            color: #94a3b8;
        }
        
        .notif-empty i, .notif-loading i {
            font-size: 24px;
            margin-bottom: 8px;
            display: block;
        }
    </style>
    
    <script>
        // Notification dropdown toggle
        function toggleNotifDropdown() {
            const dropdown = document.getElementById('notifDropdown');
            const isOpen = dropdown.classList.contains('show');
            
            if (!isOpen) {
                dropdown.classList.add('show');
                loadNotifications();
            } else {
                dropdown.classList.remove('show');
            }
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const container = document.getElementById('notifBellContainer');
            const dropdown = document.getElementById('notifDropdown');
            if (container && dropdown && !container.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
        
        // Switch tabs
        function switchNotifTab(tab) {
            document.querySelectorAll('.notif-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.notif-content').forEach(c => c.classList.add('hidden'));
            
            event.target.classList.add('active');
            document.getElementById(tab + 'Tab').classList.remove('hidden');
        }
        
        // Load notifications via AJAX
        function loadNotifications() {
            const list = document.getElementById('notifList');
            
            fetch('/admin/api_notifications.php?action=list')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (data.notifications.length === 0) {
                            list.innerHTML = '<div class="notif-empty"><i class="fa-solid fa-bell-slash"></i> No new notifications</div>';
                        } else {
                            list.innerHTML = data.notifications.map(n => renderNotifItem(n)).join('');
                        }
                        document.getElementById('alertsCount').textContent = data.count;
                        updateBadge();
                    }
                })
                .catch(() => {
                    list.innerHTML = '<div class="notif-empty">Failed to load notifications</div>';
                });
        }
        
        // Render single notification item
        function renderNotifItem(n) {
            const iconClass = n.priority === 'urgent' ? 'error' : 
                              n.priority === 'high' ? 'warning' : 
                              n.type.includes('success') ? 'success' : 'info';
            
            const icon = n.type.includes('member') ? 'fa-user' :
                         n.type.includes('task') ? 'fa-tasks' :
                         n.type.includes('grade') ? 'fa-graduation-cap' : 'fa-bell';
            
            const time = new Date(n.created_at).toLocaleString('en-US', {
                month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
            });
            
            return `
                <div class="notif-item unread" onclick="markRead(${n.id}, this)" data-id="${n.id}">
                    <div class="notif-icon ${iconClass}">
                        <i class="fa-solid ${icon}"></i>
                    </div>
                    <div class="notif-body">
                        <div class="notif-title">${escapeHtml(n.title)}</div>
                        <div class="notif-meta">${escapeHtml(n.message.substring(0, 100))} • ${time}</div>
                    </div>
                </div>
            `;
        }
        
        // Mark single notification as read
        function markRead(id, el) {
            fetch('/admin/api_notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mark_read&id=' + id
            }).then(() => {
                if (el) el.classList.remove('unread');
                updateBadge();
            });
        }
        
        // Mark all as read
        function markAllRead() {
            fetch('/admin/api_notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mark_all_read'
            }).then(() => {
                document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
                document.getElementById('alertsCount').textContent = '0';
                updateBadge();
            });
        }
        
        // Update task status
        function updateTask(taskId, status) {
            event.stopPropagation();
            
            fetch('/admin/api_notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=task_update&task_id=${taskId}&task_status=${status}`
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') {
                    const item = document.querySelector(`.task-item[data-task-id="${taskId}"]`);
                    if (item) {
                        if (status === 'completed') {
                            item.style.opacity = '0.5';
                            item.innerHTML = '<div class="notif-empty" style="padding:8px"><i class="fa-solid fa-check"></i> Completed</div>';
                            setTimeout(() => item.remove(), 2000);
                        } else {
                            item.querySelector('.btn-task-progress').textContent = 'In Progress';
                        }
                    }
                    updateBadge();
                }
            });
        }
        
        // Update badge count
        function updateBadge() {
            fetch('/admin/api_notifications.php?action=count')
                .then(r => r.json())
                .then(data => {
                    const badge = document.getElementById('notifBadge');
                    const tasksCount = parseInt(document.getElementById('tasksCount')?.textContent || 0);
                    const total = data.count + tasksCount;
                    
                    if (badge) {
                        if (total > 0) {
                            badge.textContent = total > 99 ? '99+' : total;
                            badge.style.display = 'flex';
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                    
                    document.getElementById('alertsCount').textContent = data.count;
                });
        }
        
        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Poll for new notifications every 60 seconds
        setInterval(updateBadge, 60000);
    </script>
    <?php
    return ob_get_clean();
}
