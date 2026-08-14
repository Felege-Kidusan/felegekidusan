<?php
/**
 * ============================================================
 * SYSTEM HEALTH CHECK   —   admin/tools/health_check.php
 * ============================================================
 * A fast "is the system alive?" page for the developer.
 *
 * IMPORTANT DESIGN CHOICE: this file does NOT load the main config.php
 * and does NOT use the normal login. That is on purpose — a health check
 * must still work when the main app or its login is broken (that is
 * exactly when you need it most). It has its own password instead.
 *
 * HOW TO OPEN IT:
 *   https://your-site/admin/tools/health_check.php?key=YOUR_HEALTH_KEY
 *   (YOUR_HEALTH_KEY is the HEALTH_KEY value from your .fkss_env.php file.)
 *
 * It checks: database connection, record counts, disk space, PHP version,
 * whether the secret + branding config is present, the most recent error
 * log line, and the most recent backup file.
 * ============================================================
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

// ── Locate and load ONLY the secrets file (not the whole app) ──
$envNames = ['.fkss_env.php', '.wbws_env.php'];
$envDirs  = [dirname(__DIR__, 2), dirname(__DIR__, 3), dirname(__DIR__, 4)];
$envLoaded = false;
foreach ($envDirs as $d) {
    foreach ($envNames as $n) {
        if (is_file($d . '/' . $n)) { require_once $d . '/' . $n; $envLoaded = true; break 2; }
    }
}

function hc_page($title, $bodyHtml) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . '</title><style>'
       . 'body{font-family:system-ui,Segoe UI,sans-serif;background:#0f172a;color:#e2e8f0;max-width:820px;margin:0 auto;padding:2rem;line-height:1.5}'
       . 'h1{color:#f8fafc} table{width:100%;border-collapse:collapse;margin-top:1rem}'
       . 'td{padding:.6rem .8rem;border-bottom:1px solid #334155;vertical-align:top}'
       . '.k{color:#94a3b8;width:230px} .ok{color:#4ade80;font-weight:700} .bad{color:#f87171;font-weight:700}'
       . '.warn{color:#fbbf24;font-weight:700} code{background:#1e293b;padding:.1rem .4rem;border-radius:4px}'
       . '</style></head><body>' . $bodyHtml . '</body></html>';
}

// ── If secrets are missing, that IS the headline health problem ──
if (!$envLoaded) {
    hc_page('Health Check — Setup Required',
        '<h1>⚠️ Setup required</h1><p class="bad">The secrets file (<code>.fkss_env.php</code>) was not found '
        . 'above the web root. The site cannot run until it exists. See <code>env.example.php</code>.</p>');
    exit;
}

// ── Password check (uses HEALTH_KEY from the secrets file) ──
$providedKey = $_GET['key'] ?? ($_SERVER['PHP_AUTH_PW'] ?? '');
$expectedKey = defined('HEALTH_KEY') ? HEALTH_KEY : '';
$keyOk = ($expectedKey !== '' && is_string($providedKey) && hash_equals($expectedKey, $providedKey));

if (!$keyOk) {
    header('WWW-Authenticate: Basic realm="Health Check"');
    http_response_code(401);
    hc_page('Health Check — Locked',
        '<h1>🔒 Health Check</h1><p>Add <code>?key=YOUR_HEALTH_KEY</code> to the URL '
        . '(the <code>HEALTH_KEY</code> from your secrets file), or enter it when the browser asks for a password.</p>');
    exit;
}

// ── Helper to render a status row ──
$rows = '';
function row(&$rows, $label, $value, $state = '') {
    $cls = $state === 'ok' ? 'ok' : ($state === 'bad' ? 'bad' : ($state === 'warn' ? 'warn' : ''));
    $rows .= '<tr><td class="k">' . htmlspecialchars($label) . '</td><td' . ($cls ? ' class="' . $cls . '"' : '') . '>' . $value . '</td></tr>';
}

// 1. PHP version
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
row($rows, 'PHP version', htmlspecialchars(PHP_VERSION), $phpOk ? 'ok' : 'warn');

// 2. Critical secret constants present
$needSecrets = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET'];
$missingSecrets = array_filter($needSecrets, fn($c) => !defined($c) || constant($c) === '' );
row($rows, 'Secret config constants', $missingSecrets ? 'MISSING: ' . htmlspecialchars(implode(', ', $missingSecrets)) : 'all present',
    $missingSecrets ? 'bad' : 'ok');

// 3. Branding config present (load school_config.php — pure constants, safe)
$schoolCfg = dirname(__DIR__, 2) . '/school_config.php';
if (is_file($schoolCfg)) { @require_once $schoolCfg; }
$needBrand = ['SCHOOL_NAME', 'SITE_URL', 'ACTIVE_THEME'];
$missingBrand = array_filter($needBrand, fn($c) => !defined($c));
row($rows, 'Branding config constants', $missingBrand ? 'MISSING: ' . htmlspecialchars(implode(', ', $missingBrand)) : 'all present',
    $missingBrand ? 'warn' : 'ok');

// 4. Database connection + counts
$dbOk = false; $conn = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn && !$conn->connect_error) {
        $dbOk = true;
        $conn->set_charset('utf8mb4');
        row($rows, 'Database connection', 'connected to <code>' . htmlspecialchars(DB_NAME) . '</code>', 'ok');
    } else {
        row($rows, 'Database connection', 'FAILED: ' . htmlspecialchars($conn->connect_error ?? 'unknown'), 'bad');
    }
} catch (Throwable $e) {
    row($rows, 'Database connection', 'FAILED: ' . htmlspecialchars($e->getMessage()), 'bad');
}

if ($dbOk) {
    $counts = [
        'Users (staff logins)' => 'users',
        'Members (students)'   => 'members',
        'Attendance rows'      => 'attendance',
        'Academic years'       => 'academic_years',
    ];
    foreach ($counts as $label => $table) {
        try {
            $r = $conn->query("SELECT COUNT(*) AS c FROM `$table`");
            $c = $r ? (int)$r->fetch_assoc()['c'] : 0;
            row($rows, $label, number_format($c));
        } catch (Throwable $e) {
            row($rows, $label, 'table missing / error', 'warn');
        }
    }
    // Is there exactly one current academic year?
    try {
        $r = $conn->query("SELECT COUNT(*) AS c FROM academic_years WHERE is_current = 1");
        $cy = $r ? (int)$r->fetch_assoc()['c'] : 0;
        row($rows, 'Current academic year', $cy === 1 ? 'exactly one (good)' : ($cy . ' — should be exactly 1'),
            $cy === 1 ? 'ok' : 'bad');
    } catch (Throwable $e) { /* table may not exist yet */ }
}

// 5. Disk space
try {
    $free = @disk_free_space(__DIR__);
    $total = @disk_total_space(__DIR__);
    if ($free && $total) {
        $pct = round($free / $total * 100, 1);
        row($rows, 'Disk free', round($free / 1073741824, 2) . ' GB (' . $pct . '% free)', $pct < 10 ? 'bad' : 'ok');
    } else {
        row($rows, 'Disk free', 'unavailable on this host', 'warn');
    }
} catch (Throwable $e) { row($rows, 'Disk free', 'unavailable', 'warn'); }

// 6. Most recent error-log line
$logCandidates = [dirname(__DIR__, 2) . '/error.log', dirname(__DIR__, 2) . '/error_log'];
$logShown = false;
foreach ($logCandidates as $lg) {
    if (is_file($lg) && filesize($lg) > 0) {
        $when = date('Y-m-d H:i:s', filemtime($lg));
        $lines = @file($lg, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last = $lines ? htmlspecialchars(substr(end($lines), 0, 300)) : '(empty)';
        row($rows, 'Last error log entry', '<div style="color:#94a3b8">' . $when . '</div>' . $last, 'warn');
        $logShown = true;
        break;
    }
}
if (!$logShown) { row($rows, 'Last error log entry', 'no errors logged', 'ok'); }

// 7. Most recent backup
$backupDir = dirname(__DIR__, 2) . '/admin/uploads/backups';
$backups = is_dir($backupDir) ? glob($backupDir . '/*.sql') : [];
if ($backups) {
    usort($backups, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $newest = $backups[0];
    $ageHrs = round((time() - filemtime($newest)) / 3600, 1);
    $sizeMb = round(filesize($newest) / 1048576, 2);
    row($rows, 'Last backup',
        htmlspecialchars(basename($newest)) . ' — ' . $sizeMb . ' MB, ' . $ageHrs . ' hours ago (' . count($backups) . ' kept)',
        $ageHrs > 48 ? 'warn' : 'ok');
} else {
    row($rows, 'Last backup', 'NONE FOUND — set up admin/tools/backup.php', 'bad');
}

if ($conn) { $conn->close(); }

hc_page('System Health Check',
    '<h1>🩺 System Health Check</h1><p style="color:#94a3b8">Generated ' . date('Y-m-d H:i:s') . '</p><table>' . $rows . '</table>');
