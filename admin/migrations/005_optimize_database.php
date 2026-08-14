<?php
/**
 * ============================================================
 * School Management Migration 005 — Database Optimization
 * ============================================================
 * Phase 1.2: Add missing indexes, foreign keys, and cleanup
 * 
 * SAFE TO RUN MULTIPLE TIMES — all statements use IF NOT EXISTS
 * or check before adding.
 * 
 * Run from: Super Admin → System Health → or visit directly
 * URL: /admin/migrations/005_optimize_database.php
 * ============================================================
 */

session_start();
require_once __DIR__ . '/../backend/config.php';

// Auth check
if (empty($_SESSION['admin_logged_in'])) {
    die('Unauthorized. Please log in first.');
}
if (!in_array($_SESSION['admin_role'] ?? '', ['super_admin', 'school_admin'])) {
    die('Only Super Admin or School Admin can run migrations.');
}

header('Content-Type: text/html; charset=utf-8');
echo "<html><head><title>Migration 005 — Database Optimization</title>";
echo "<style>body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:2rem;max-width:800px;margin:0 auto}";
echo ".ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.info{color:#60a5fa}";
echo "h1{color:#f8fafc}h2{color:#94a3b8;margin-top:2rem}pre{background:#1e293b;padding:1rem;border-radius:8px;overflow-x:auto}</style></head><body>";
echo "<h1>⚙️ Migration 005 — Database Optimization</h1>";
echo "<p>Adding missing indexes, foreign keys, and performance improvements.</p><hr>";

$results = [];
$errors = [];

// Helper: Check if index exists
function indexExists($conn, $table, $indexName) {
    try {
        $r = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
        return $r && $r->num_rows > 0;
    } catch (Exception $e) { return false; }
}

// Helper: Check if column exists
function colExists($conn, $table, $column) {
    try {
        $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $r && $r->num_rows > 0;
    } catch (Exception $e) { return false; }
}

// Helper: Check if table exists
function tableExists($conn, $table) {
    try {
        $r = $conn->query("SHOW TABLES LIKE '$table'");
        return $r && $r->num_rows > 0;
    } catch (Exception $e) { return false; }
}

// Helper: Safe query
function safeExec($conn, $sql, $desc) {
    global $results, $errors;
    try {
        if ($conn->query($sql)) {
            $results[] = "✅ $desc";
        } else {
            $errors[] = "❌ $desc: " . $conn->error;
        }
    } catch (Exception $e) {
        // Check if it's a "duplicate" error (index already exists)
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            $results[] = "⏭️ $desc (already exists)";
        } else {
            $errors[] = "❌ $desc: " . $e->getMessage();
        }
    }
}

// ============================================================
echo "<h2>1. Members Table — Indexes</h2>";
// ============================================================
if (tableExists($conn, 'members')) {
    if (!indexExists($conn, 'members', 'idx_members_status'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_status` (`status`)", "Index on members.status");
    
    if (!indexExists($conn, 'members', 'idx_members_gender'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_gender` (`gender`)", "Index on members.gender");
    
    if (!indexExists($conn, 'members', 'idx_members_age_group'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_age_group` (`age_group`)", "Index on members.age_group");
    
    if (!indexExists($conn, 'members', 'idx_members_current_section'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_current_section` (`current_section`)", "Index on members.current_section");
    
    if (!indexExists($conn, 'members', 'idx_members_created_at'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_created_at` (`created_at`)", "Index on members.created_at");
    
    if (!indexExists($conn, 'members', 'idx_members_is_teacher'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_is_teacher` (`is_teacher`)", "Index on members.is_teacher");
    
    if (!indexExists($conn, 'members', 'idx_members_registration_type'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_registration_type` (`registration_type`)", "Index on members.registration_type");
    
    if (colExists($conn, 'members', 'current_class_id') && !indexExists($conn, 'members', 'idx_members_current_class'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_current_class` (`current_class_id`)", "Index on members.current_class_id");
    
    // Composite index for the most common query: active members search
    if (!indexExists($conn, 'members', 'idx_members_status_gender'))
        safeExec($conn, "ALTER TABLE `members` ADD INDEX `idx_members_status_gender` (`status`, `gender`)", "Composite index on members(status, gender)");
    
    $results[] = "<span class='info'>ℹ️ Members table optimized</span>";
} else {
    $results[] = "<span class='warn'>⚠️ Members table not found — skip</span>";
}

// ============================================================
echo "<h2>2. Users Table — Indexes</h2>";
// ============================================================
if (tableExists($conn, 'users')) {
    if (!indexExists($conn, 'users', 'idx_users_role'))
        safeExec($conn, "ALTER TABLE `users` ADD INDEX `idx_users_role` (`role`(50))", "Index on users.role");
    
    if (!indexExists($conn, 'users', 'idx_users_is_active'))
        safeExec($conn, "ALTER TABLE `users` ADD INDEX `idx_users_is_active` (`is_active`)", "Index on users.is_active");
    
    $results[] = "<span class='info'>ℹ️ Users table optimized</span>";
}

// ============================================================
echo "<h2>3. Attendance Table — Indexes</h2>";
// ============================================================
if (tableExists($conn, 'attendance')) {
    // Composite index for the most common query: class + date
    if (!indexExists($conn, 'attendance', 'idx_att_class_date'))
        safeExec($conn, "ALTER TABLE `attendance` ADD INDEX `idx_att_class_date` (`class_id`, `attendance_date`)", "Composite index on attendance(class_id, date)");
    
    // Already has: unique(member_id, attendance_date), attendance_date, status, class_id, academic_year_id
    $results[] = "<span class='info'>ℹ️ Attendance table checked</span>";
}

// ============================================================
echo "<h2>4. Finance Tables — Indexes</h2>";
// ============================================================
if (tableExists($conn, 'finance_transactions')) {
    if (!indexExists($conn, 'finance_transactions', 'idx_fin_type_status'))
        safeExec($conn, "ALTER TABLE `finance_transactions` ADD INDEX `idx_fin_type_status` (`type`, `status`)", "Composite index on finance(type, status)");
    
    if (!indexExists($conn, 'finance_transactions', 'idx_fin_recorded_by'))
        safeExec($conn, "ALTER TABLE `finance_transactions` ADD INDEX `idx_fin_recorded_by` (`recorded_by`)", "Index on finance.recorded_by");
    
    $results[] = "<span class='info'>ℹ️ Finance tables optimized</span>";
}

// ============================================================
echo "<h2>5. Activity Logs — Indexes</h2>";
// ============================================================
if (tableExists($conn, 'activity_logs')) {
    if (!indexExists($conn, 'activity_logs', 'idx_logs_created_at'))
        safeExec($conn, "ALTER TABLE `activity_logs` ADD INDEX `idx_logs_created_at` (`created_at`)", "Index on activity_logs.created_at");
    
    $results[] = "<span class='info'>ℹ️ Activity logs optimized</span>";
}

// ============================================================
echo "<h2>6. Notifications — Indexes</h2>";
// ============================================================
if (tableExists($conn, 'notifications')) {
    if (!indexExists($conn, 'notifications', 'idx_notif_created_read'))
        safeExec($conn, "ALTER TABLE `notifications` ADD INDEX `idx_notif_created_read` (`created_at`, `is_read`)", "Composite index on notifications(created_at, is_read)");
    
    $results[] = "<span class='info'>ℹ️ Notifications table optimized</span>";
}

// ============================================================
echo "<h2>7. Groups — Indexes</h2>";
// ============================================================
if (tableExists($conn, 'wbws_group_members')) {
    if (!indexExists($conn, 'wbws_group_members', 'idx_gm_member'))
        safeExec($conn, "ALTER TABLE `wbws_group_members` ADD INDEX `idx_gm_member` (`member_id`)", "Index on wbws_group_members.member_id");
    
    if (!indexExists($conn, 'wbws_group_members', 'idx_gm_group'))
        safeExec($conn, "ALTER TABLE `wbws_group_members` ADD INDEX `idx_gm_group` (`group_id`)", "Index on wbws_group_members.group_id");
}

// ============================================================
echo "<h2>8. Teacher Assignments — Composite Index</h2>";
// ============================================================
if (tableExists($conn, 'teacher_assignments')) {
    if (!indexExists($conn, 'teacher_assignments', 'idx_ta_active'))
        safeExec($conn, "ALTER TABLE `teacher_assignments` ADD INDEX `idx_ta_active` (`is_active`)", "Index on teacher_assignments.is_active");
    
    if (!indexExists($conn, 'teacher_assignments', 'idx_ta_teacher_active'))
        safeExec($conn, "ALTER TABLE `teacher_assignments` ADD INDEX `idx_ta_teacher_active` (`teacher_id`, `is_active`)", "Composite index on teacher_assignments(teacher_id, is_active)");
}

// ============================================================
echo "<h2>9. Class Enrollments — Composite Index</h2>";
// ============================================================
if (tableExists($conn, 'class_enrollments')) {
    if (!indexExists($conn, 'class_enrollments', 'idx_ce_class_status'))
        safeExec($conn, "ALTER TABLE `class_enrollments` ADD INDEX `idx_ce_class_status` (`class_id`, `status`)", "Composite index on class_enrollments(class_id, status)");
    
    if (!indexExists($conn, 'class_enrollments', 'idx_ce_year_status'))
        safeExec($conn, "ALTER TABLE `class_enrollments` ADD INDEX `idx_ce_year_status` (`academic_year_id`, `status`)", "Composite index on class_enrollments(year, status)");
}

// ============================================================
echo "<h2>10. Cleanup — Remove Orphaned Data</h2>";
// ============================================================

// Clean up expired rate limit files
$cacheDir = ROOT_PATH . '/admin/uploads/cache';
if (is_dir($cacheDir)) {
    $cleaned = 0;
    foreach (glob($cacheDir . '/rate_*.json') as $file) {
        if (filemtime($file) < time() - 3600) { // older than 1 hour
            @unlink($file);
            $cleaned++;
        }
    }
    if ($cleaned > 0) $results[] = "🧹 Cleaned $cleaned expired rate limit files";
}

// ============================================================
echo "<h2>Results</h2>";
// ============================================================
echo "<pre>";
foreach ($results as $r) echo "$r\n";
if (!empty($errors)) {
    echo "\n<span class='err'>ERRORS:</span>\n";
    foreach ($errors as $e) echo "$e\n";
}
echo "</pre>";

$total = count($results);
$errCount = count($errors);
echo "<p style='margin-top:1rem;font-size:1.1rem'>";
echo "<span class='ok'>$total operations completed</span>";
if ($errCount > 0) echo " · <span class='err'>$errCount errors</span>";
echo "</p>";

echo "<p><a href='/admin/dashboard.php' style='color:#60a5fa'>← Back to Dashboard</a></p>";
echo "</body></html>";
