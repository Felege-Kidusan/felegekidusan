<?php
/**
 * ============================================================
 * System Health & Analytics Dashboard
 * ============================================================
 * Professional error monitoring, performance analytics, and
 * system status overview for <?= SCHOOL_NAME ?>.
 * 
 * Access: super_admin, school_admin only
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';

// Auth & role check
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}
if (!hasRole(['super_admin', 'school_admin'])) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$fullName = $_SESSION['admin_full_name'] ?? 'Admin';
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');

// ============================================================
// GATHER SYSTEM HEALTH DATA
// ============================================================
$health = [
    'db_status' => 'error',
    'db_version' => 'Unknown',
    'db_size' => '0 KB',
    'db_tables' => 0,
    'php_version' => phpversion(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
    'memory_limit' => ini_get('memory_limit'),
    'max_upload' => ini_get('upload_max_filesize'),
    'max_post' => ini_get('post_max_size'),
    'session_timeout' => SESSION_TIMEOUT . 's (' . round(SESSION_TIMEOUT / 60) . ' min)',
    'timezone' => date_default_timezone_get(),
    'disk_free' => @disk_free_space('/') ? round(disk_free_space('/') / 1024 / 1024 / 1024, 2) . ' GB' : 'N/A',
    'error_log_size' => '0 KB',
    'error_log_exists' => false,
];

// Database health
$tableStats = [];
$recentErrors = [];
$recentActivity = [];
$securityEvents = [];
$performanceData = [];

if (isset($conn) && !$conn->connect_error) {
    $health['db_status'] = 'connected';
    
    // DB version
    $r = $conn->query("SELECT VERSION() as v");
    if ($r) $health['db_version'] = $r->fetch_assoc()['v'];
    
    // DB size
    $r = $conn->query("SELECT 
        COUNT(*) as table_count,
        ROUND(SUM(data_length + index_length) / 1024, 2) AS size_kb,
        ROUND(SUM(data_length) / 1024, 2) AS data_kb,
        ROUND(SUM(index_length) / 1024, 2) AS index_kb
        FROM information_schema.tables WHERE table_schema = DATABASE()");
    if ($r) {
        $dbInfo = $r->fetch_assoc();
        $health['db_tables'] = (int)$dbInfo['table_count'];
        $sizeKb = (float)$dbInfo['size_kb'];
        $health['db_size'] = $sizeKb > 1024 ? round($sizeKb / 1024, 2) . ' MB' : $sizeKb . ' KB';
    }
    
    // Per-table stats
    $r = $conn->query("SELECT 
        table_name,
        table_rows,
        ROUND((data_length + index_length) / 1024, 2) AS size_kb,
        auto_increment,
        update_time
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
        ORDER BY (data_length + index_length) DESC");
    if ($r) {
        while ($row = $r->fetch_assoc()) $tableStats[] = $row;
    }
    
    // Recent activity logs
    try {
        $r = $conn->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 30");
        if ($r) while ($row = $r->fetch_assoc()) $recentActivity[] = $row;
    } catch (Exception $e) {}
    
    // Security events (failed logins, rate limits)
    try {
        $r = $conn->query("SELECT * FROM activity_logs WHERE action LIKE '%Login%' OR action LIKE '%fail%' OR action LIKE '%block%' ORDER BY created_at DESC LIMIT 20");
        if ($r) while ($row = $r->fetch_assoc()) $securityEvents[] = $row;
    } catch (Exception $e) {}
    
    // Member stats for performance chart
    try {
        $r = $conn->query("SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
            FROM members 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC");
        if ($r) while ($row = $r->fetch_assoc()) $performanceData[] = $row;
    } catch (Exception $e) {}
}

// Error log analysis
$errorLogPath = ROOT_PATH . '/error.log';
if (file_exists($errorLogPath)) {
    $health['error_log_exists'] = true;
    $logSize = filesize($errorLogPath);
    $health['error_log_size'] = $logSize > 1024 * 1024 
        ? round($logSize / 1024 / 1024, 2) . ' MB' 
        : round($logSize / 1024, 2) . ' KB';
    
    // Read last 50 lines of error log
    $logLines = [];
    if ($logSize > 0) {
        $fp = fopen($errorLogPath, 'r');
        if ($fp) {
            // Read last 50KB max
            $readSize = min($logSize, 50 * 1024);
            fseek($fp, -$readSize, SEEK_END);
            $content = fread($fp, $readSize);
            fclose($fp);
            $allLines = explode("\n", trim($content));
            $logLines = array_slice($allLines, -50);
        }
    }
    $recentErrors = $logLines;
}

// File system checks
$uploadDir = UPLOADS_PATH;
$uploadsWritable = is_writable($uploadDir);
$cacheDir = $uploadDir . '/cache';
$cacheWritable = is_dir($cacheDir) && is_writable($cacheDir);

// Integrity checks
$criticalFiles = [
    'config.php' => ROOT_PATH . '/config.php',
    'admin/config.php' => ADMIN_PATH . '/config.php',
    'admin/index.php' => ADMIN_PATH . '/index.php',
    'admin/dashboard.php' => ADMIN_PATH . '/dashboard.php',
    'backend/login.php' => ADMIN_PATH . '/backend/login.php',
    'backend/groups_api.php' => ADMIN_PATH . '/backend/groups_api.php',
    'backend/workflow.php' => ADMIN_PATH . '/backend/workflow.php',
    '.htaccess' => ROOT_PATH . '/.htaccess',
];

$fileChecks = [];
foreach ($criticalFiles as $label => $path) {
    $fileChecks[$label] = [
        'exists' => file_exists($path),
        'readable' => is_readable($path),
        'size' => file_exists($path) ? filesize($path) : 0,
        'modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : 'N/A',
    ];
}

// Handle clear error log action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrf()) {
        header('Location: system_health.php?error=csrf');
        exit;
    }
    
    if ($_POST['action'] === 'clear_error_log' && hasRole('super_admin')) {
        if (file_exists($errorLogPath)) {
            file_put_contents($errorLogPath, "--- Log cleared by {$fullName} on " . date('Y-m-d H:i:s') . " ---\n");
        }
        header('Location: system_health.php?success=Error+log+cleared');
        exit;
    }
    
    if ($_POST['action'] === 'clear_rate_limits' && hasRole('super_admin')) {
        $cacheDir = ROOT_PATH . '/admin/uploads/cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/rate_*.json');
            foreach ($files as $f) @unlink($f);
        }
        header('Location: system_health.php?success=Rate+limits+cleared');
        exit;
    }
    
    if ($_POST['action'] === 'clear_session_cache' && hasRole('super_admin')) {
        // Just clear the db_checked flags in current session
        unset($_SESSION['_db_checked']);
        unset($_SESSION['groups_tables_checked']);
        header('Location: system_health.php?success=Session+cache+cleared');
        exit;
    }
}

// Calculate overall health score
$healthScore = 100;
$healthIssues = [];

if ($health['db_status'] !== 'connected') { $healthScore -= 40; $healthIssues[] = 'Database disconnected'; }
if (!$uploadsWritable) { $healthScore -= 15; $healthIssues[] = 'Uploads directory not writable'; }
if (!$cacheWritable) { $healthScore -= 10; $healthIssues[] = 'Cache directory not writable'; }
if ($health['error_log_exists'] && $health['error_log_size'] !== '0 KB') { 
    $logSizeBytes = file_exists($errorLogPath) ? filesize($errorLogPath) : 0;
    if ($logSizeBytes > 1024 * 1024) { $healthScore -= 15; $healthIssues[] = 'Error log is large (' . $health['error_log_size'] . ')'; }
    elseif ($logSizeBytes > 100 * 1024) { $healthScore -= 5; $healthIssues[] = 'Error log has entries'; }
}
foreach ($fileChecks as $label => $check) {
    if (!$check['exists']) { $healthScore -= 10; $healthIssues[] = "Missing file: $label"; }
}

$healthScore = max(0, $healthScore);
$healthColor = $healthScore >= 80 ? '#10b981' : ($healthScore >= 60 ? '#f59e0b' : '#ef4444');
$healthLabel = $healthScore >= 80 ? 'Healthy' : ($healthScore >= 60 ? 'Warning' : 'Critical');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health - <?= SCHOOL_NAME_SHORT ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔧</text></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: #0f172a; color: #e2e8f0; min-height: 100vh; }
        .health-score-ring { width: 160px; height: 160px; position: relative; margin: 0 auto; }
        .health-score-ring svg { transform: rotate(-90deg); }
        .health-score-ring .score-text { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .health-score-ring .score-number { font-size: 2.5rem; font-weight: 800; }
        .health-score-ring .score-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.7; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; overflow: hidden; }
        .card-header { padding: 16px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid #334155; display: flex; align-items: center; gap: 12px; }
        .card-header i { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .card-body { padding: 20px; }
        .stat-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #1e293b; }
        .stat-row:last-child { border-bottom: none; }
        .stat-label { color: #94a3b8; font-size: 13px; }
        .stat-value { font-weight: 600; font-size: 13px; }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .badge-green { background: #064e3b; color: #6ee7b7; }
        .badge-red { background: #450a0a; color: #fca5a5; }
        .badge-yellow { background: #451a03; color: #fcd34d; }
        .badge-blue { background: #1e3a5f; color: #93c5fd; }
        .log-line { font-family: 'Courier New', monospace; font-size: 11px; padding: 4px 8px; border-bottom: 1px solid #1e293b; word-break: break-all; color: #cbd5e1; }
        .log-line:nth-child(even) { background: rgba(255,255,255,0.02); }
        .log-error { color: #fca5a5; }
        .log-warning { color: #fcd34d; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
        .btn-green { background: #059669; color: white; }
        .btn-green:hover { background: #047857; }
        .btn-red { background: #dc2626; color: white; }
        .btn-red:hover { background: #b91c1c; }
        .btn-blue { background: #2563eb; color: white; }
        .btn-blue:hover { background: #1d4ed8; }
        .btn-gray { background: #334155; color: #e2e8f0; }
        .btn-gray:hover { background: #475569; }
        .table-mini { width: 100%; border-collapse: collapse; font-size: 12px; }
        .table-mini th { text-align: left; padding: 8px; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; border-bottom: 1px solid #334155; }
        .table-mini td { padding: 8px; border-bottom: 1px solid #1e293b; }
        .nav-back { display: inline-flex; align-items: center; gap: 8px; color: #94a3b8; text-decoration: none; font-size: 13px; padding: 8px 0; transition: color 0.15s; }
        .nav-back:hover { color: #e2e8f0; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr !important; } }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css">
</head>
<body>
    <div style="max-width: 1200px; margin: 0 auto; padding: 24px;">
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
            <div>
                <a href="dashboard.php" class="nav-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <h1 style="font-size: 1.75rem; font-weight: 800; color: #f1f5f9; margin-top: 8px;">
                    <i class="fas fa-heartbeat" style="color: <?= $healthColor ?>"></i> System Health & Analytics
                </h1>
                <p style="color: #64748b; font-size: 13px; margin-top: 4px;"><?= $todayFormatted ?> • <?= ADMIN_FOOTER_TEXT ?> System</p>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <button onclick="location.reload()" class="btn btn-gray"><i class="fas fa-sync-alt"></i> Refresh</button>
                <a href="dashboard.php" class="btn btn-green"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div style="background: #064e3b; border: 1px solid #10b981; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; color: #6ee7b7; font-size: 13px;">
                <i class="fas fa-check-circle"></i> <?= e($_GET['success']) ?>
            </div>
        <?php endif; ?>

        <!-- Health Score + Quick Stats -->
        <div style="display: grid; grid-template-columns: 300px 1fr; gap: 20px; margin-bottom: 24px;" class="grid-2">
            <!-- Score Ring -->
            <div class="card" style="text-align: center;">
                <div class="card-body" style="padding: 30px 20px;">
                    <div class="health-score-ring">
                        <svg viewBox="0 0 120 120" width="160" height="160">
                            <circle cx="60" cy="60" r="50" fill="none" stroke="#1e293b" stroke-width="10"/>
                            <circle cx="60" cy="60" r="50" fill="none" stroke="<?= $healthColor ?>" stroke-width="10" 
                                stroke-dasharray="<?= 314 * $healthScore / 100 ?> 314" stroke-linecap="round"/>
                        </svg>
                        <div class="score-text">
                            <span class="score-number" style="color: <?= $healthColor ?>"><?= $healthScore ?></span>
                            <span class="score-label"><?= $healthLabel ?></span>
                        </div>
                    </div>
                    <?php if (!empty($healthIssues)): ?>
                        <div style="margin-top: 16px; text-align: left;">
                            <?php foreach ($healthIssues as $issue): ?>
                                <div style="font-size: 12px; color: #fca5a5; padding: 4px 0;">
                                    <i class="fas fa-exclamation-triangle" style="width: 16px;"></i> <?= e($issue) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;">
                <div class="card"><div class="card-body" style="padding: 16px;">
                    <div style="color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Database</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin: 4px 0;"><?= $health['db_size'] ?></div>
                    <span class="badge <?= $health['db_status'] === 'connected' ? 'badge-green' : 'badge-red' ?>">
                        <i class="fas fa-<?= $health['db_status'] === 'connected' ? 'check' : 'times' ?>"></i>
                        <?= ucfirst($health['db_status']) ?>
                    </span>
                </div></div>
                
                <div class="card"><div class="card-body" style="padding: 16px;">
                    <div style="color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Tables</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin: 4px 0;"><?= $health['db_tables'] ?></div>
                    <span class="badge badge-blue"><?= $health['db_version'] ?></span>
                </div></div>
                
                <div class="card"><div class="card-body" style="padding: 16px;">
                    <div style="color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">PHP Version</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin: 4px 0;"><?= $health['php_version'] ?></div>
                    <span class="badge badge-green"><i class="fas fa-check"></i> Running</span>
                </div></div>
                
                <div class="card"><div class="card-body" style="padding: 16px;">
                    <div style="color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Memory</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin: 4px 0;"><?= $health['memory_usage'] ?></div>
                    <span class="badge badge-blue">Limit: <?= $health['memory_limit'] ?></span>
                </div></div>
                
                <div class="card"><div class="card-body" style="padding: 16px;">
                    <div style="color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Error Log</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin: 4px 0;"><?= $health['error_log_size'] ?></div>
                    <span class="badge <?= $health['error_log_exists'] ? 'badge-yellow' : 'badge-green' ?>">
                        <?= $health['error_log_exists'] ? 'Has entries' : 'Clean' ?>
                    </span>
                </div></div>
                
                <div class="card"><div class="card-body" style="padding: 16px;">
                    <div style="color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Uploads</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin: 4px 0;"><?= $health['max_upload'] ?></div>
                    <span class="badge <?= $uploadsWritable ? 'badge-green' : 'badge-red' ?>">
                        <i class="fas fa-<?= $uploadsWritable ? 'check' : 'times' ?>"></i>
                        <?= $uploadsWritable ? 'Writable' : 'Not writable' ?>
                    </span>
                </div></div>
            </div>
        </div>

        <!-- Server & File System Info -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;" class="grid-2">
            <div class="card">
                <div class="card-header">
                    <i style="background: #1e3a5f; color: #60a5fa;"><i class="fas fa-server"></i></i>
                    <span style="font-weight: 700;">Server Configuration</span>
                </div>
                <div class="card-body">
                    <div class="stat-row"><span class="stat-label">Server</span><span class="stat-value"><?= e($health['server_software']) ?></span></div>
                    <div class="stat-row"><span class="stat-label">PHP Version</span><span class="stat-value"><?= $health['php_version'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Timezone</span><span class="stat-value"><?= $health['timezone'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Session Timeout</span><span class="stat-value"><?= $health['session_timeout'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Max Upload Size</span><span class="stat-value"><?= $health['max_upload'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Max POST Size</span><span class="stat-value"><?= $health['max_post'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Memory Limit</span><span class="stat-value"><?= $health['memory_limit'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Disk Free</span><span class="stat-value"><?= $health['disk_free'] ?></span></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i style="background: #1a2e05; color: #86efac;"><i class="fas fa-file-code"></i></i>
                    <span style="font-weight: 700;">Critical File Integrity</span>
                </div>
                <div class="card-body" style="padding: 0;">
                    <table class="table-mini">
                        <thead><tr><th>File</th><th>Status</th><th>Size</th><th>Modified</th></tr></thead>
                        <tbody>
                        <?php foreach ($fileChecks as $label => $check): ?>
                            <tr>
                                <td style="font-family: monospace; font-size: 11px;"><?= e($label) ?></td>
                                <td>
                                    <?php if ($check['exists'] && $check['readable']): ?>
                                        <span class="badge badge-green"><i class="fas fa-check"></i> OK</span>
                                    <?php elseif ($check['exists']): ?>
                                        <span class="badge badge-yellow"><i class="fas fa-lock"></i> No read</span>
                                    <?php else: ?>
                                        <span class="badge badge-red"><i class="fas fa-times"></i> Missing</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 11px;"><?= $check['size'] > 0 ? round($check['size'] / 1024, 1) . 'KB' : '-' ?></td>
                                <td style="font-size: 11px; color: #64748b;"><?= $check['modified'] !== 'N/A' ? date('M j, H:i', strtotime($check['modified'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Database Tables -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <i style="background: #3b0764; color: #c084fc;"><i class="fas fa-database"></i></i>
                <span style="font-weight: 700;">Database Tables (<?= count($tableStats) ?>)</span>
            </div>
            <div class="card-body" style="padding: 0; overflow-x: auto;">
                <table class="table-mini">
                    <thead><tr><th>Table</th><th>Rows</th><th>Size</th><th>Auto Increment</th><th>Last Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($tableStats as $t): ?>
                        <tr>
                            <td style="font-family: monospace; font-size: 11px; font-weight: 600;"><?= e($t['table_name']) ?></td>
                            <td><?= number_format((int)$t['table_rows']) ?></td>
                            <td><?= (float)$t['size_kb'] > 1024 ? round((float)$t['size_kb'] / 1024, 2) . ' MB' : $t['size_kb'] . ' KB' ?></td>
                            <td style="color: #64748b;"><?= $t['auto_increment'] ?? '-' ?></td>
                            <td style="font-size: 11px; color: #64748b;"><?= $t['update_time'] ? date('M j, H:i', strtotime($t['update_time'])) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Error Log + Activity Log -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;" class="grid-2">
            <!-- Error Log -->
            <div class="card">
                <div class="card-header" style="justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i style="background: #450a0a; color: #fca5a5;"><i class="fas fa-bug"></i></i>
                        <span style="font-weight: 700;">Error Log (Last 50 lines)</span>
                    </div>
                    <?php if (hasRole('super_admin') && $health['error_log_exists']): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Clear error log?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="clear_error_log">
                            <button class="btn btn-red" style="font-size: 11px; padding: 4px 10px;"><i class="fas fa-trash"></i> Clear</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-body" style="padding: 0; max-height: 400px; overflow-y: auto;">
                    <?php if (empty($recentErrors)): ?>
                        <div style="padding: 40px 20px; text-align: center; color: #64748b;">
                            <i class="fas fa-check-circle" style="font-size: 2rem; color: #10b981; margin-bottom: 8px; display: block;"></i>
                            No errors found. System is running clean!
                        </div>
                    <?php else: ?>
                        <?php foreach (array_reverse($recentErrors) as $line): 
                            $lineClass = '';
                            if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) $lineClass = 'log-error';
                            elseif (stripos($line, 'warning') !== false || stripos($line, 'notice') !== false) $lineClass = 'log-warning';
                        ?>
                            <div class="log-line <?= $lineClass ?>"><?= e($line) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="card">
                <div class="card-header">
                    <i style="background: #1a2e05; color: #86efac;"><i class="fas fa-history"></i></i>
                    <span style="font-weight: 700;">Recent Activity (Last 30)</span>
                </div>
                <div class="card-body" style="padding: 0; max-height: 400px; overflow-y: auto;">
                    <?php if (empty($recentActivity)): ?>
                        <div style="padding: 40px 20px; text-align: center; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 8px; display: block;"></i>
                            No activity logs yet
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentActivity as $log): ?>
                            <div style="padding: 10px 16px; border-bottom: 1px solid #1e293b; font-size: 12px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                                    <span style="font-weight: 600; color: #f1f5f9;">
                                        <?= e($log['username'] ?? 'system') ?>
                                    </span>
                                    <span style="color: #475569; font-size: 11px;">
                                        <?= $log['created_at'] ? date('M j H:i', strtotime($log['created_at'])) : '' ?>
                                    </span>
                                </div>
                                <div style="color: #94a3b8;"><?= e($log['action'] ?? '') ?></div>
                                <?php if (!empty($log['details'])): ?>
                                    <div style="color: #64748b; font-size: 11px; margin-top: 2px;"><?= e(mb_substr($log['details'], 0, 100)) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($log['ip_address'])): ?>
                                    <div style="color: #475569; font-size: 10px; margin-top: 2px;">IP: <?= e($log['ip_address']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Admin Actions -->
        <?php if (hasRole('super_admin')): ?>
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <i style="background: #451a03; color: #fbbf24;"><i class="fas fa-tools"></i></i>
                <span style="font-weight: 700;">Admin Actions</span>
            </div>
            <div class="card-body">
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Clear all rate limits?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="clear_rate_limits">
                        <button class="btn btn-blue"><i class="fas fa-unlock"></i> Clear Rate Limits</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="clear_session_cache">
                        <button class="btn btn-gray"><i class="fas fa-broom"></i> Clear Session Cache</button>
                    </form>
                    <a href="migrations/auto_fix_database.php" class="btn btn-gray" onclick="return confirm('Run database auto-fix?')">
                        <i class="fas fa-wrench"></i> Run DB Auto-Fix
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div style="text-align: center; padding: 20px; color: #475569; font-size: 12px;">
            <?= SCHOOL_NAME ?> • System Health Dashboard • <?= DEVELOPER_SHOW_CREDIT ? 'Built by ' . DEVELOPER_NAME : '' ?>
        </div>
    </div>
</body>
</html>
