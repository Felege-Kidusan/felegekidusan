<?php
/**
 * Independently authenticated operational health check.
 *
 * Supply HEALTH_KEY through HTTP Basic password or X-Health-Key. Query-string
 * secrets are intentionally rejected because URLs are commonly retained in
 * browser history, proxy logs, analytics, and referrer data.
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");

$envNames = ['.fkss_env.php', '.wbws_env.php'];
$envDirs = [dirname(__DIR__, 2), dirname(__DIR__, 3), dirname(__DIR__, 4)];
$envLoaded = false;
foreach ($envDirs as $directory) {
    foreach ($envNames as $name) {
        if (is_file($directory . '/' . $name)) {
            require_once $directory . '/' . $name;
            $envLoaded = true;
            break 2;
        }
    }
}

function hcPage(string $title, string $bodyHtml): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>'
        . 'body{font-family:system-ui,Segoe UI,sans-serif;background:#0f172a;color:#e2e8f0;max-width:820px;margin:0 auto;padding:2rem;line-height:1.5}'
        . 'h1{color:#f8fafc}table{width:100%;border-collapse:collapse;margin-top:1rem}'
        . 'td{padding:.6rem .8rem;border-bottom:1px solid #334155;vertical-align:top}'
        . '.k{color:#94a3b8;width:230px}.ok{color:#4ade80;font-weight:700}.bad{color:#f87171;font-weight:700}'
        . '.warn{color:#fbbf24;font-weight:700}code{background:#1e293b;padding:.1rem .4rem;border-radius:4px}'
        . '</style></head><body>' . $bodyHtml . '</body></html>';
}

$expectedKey = $envLoaded && defined('HEALTH_KEY') ? (string)HEALTH_KEY : '';
$providedKey = (string)($_SERVER['HTTP_X_HEALTH_KEY'] ?? ($_SERVER['PHP_AUTH_PW'] ?? ''));
$keyConfigured = $expectedKey !== '' && strpos($expectedKey, 'REPLACE_WITH') !== 0;
$keyOk = $keyConfigured && $providedKey !== '' && hash_equals($expectedKey, $providedKey);

if (!$keyOk) {
    if (!$keyConfigured) {
        http_response_code(503);
    } else {
        header('WWW-Authenticate: Basic realm="SSMS Health", charset="UTF-8"');
        http_response_code(401);
    }
    hcPage('Health Check', '<h1>Health check unavailable</h1><p>Valid operational credentials are required.</p>');
    exit;
}

$rows = '';
function hcRow(string &$rows, string $label, string $value, string $state = ''): void
{
    $class = in_array($state, ['ok', 'bad', 'warn'], true) ? $state : '';
    $rows .= '<tr><td class="k">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td'
        . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $value . '</td></tr>';
}

$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
hcRow($rows, 'PHP version', htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'), $phpOk ? 'ok' : 'warn');

$requiredSecrets = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET', 'BACKUP_KEY'];
$missingSecrets = array_filter($requiredSecrets, static fn(string $name): bool => !defined($name) || constant($name) === '');
hcRow(
    $rows,
    'Secret configuration',
    $missingSecrets ? 'required values are missing' : 'required values are present',
    $missingSecrets ? 'bad' : 'ok'
);

$projectRoot = dirname(__DIR__, 2);
$schoolConfig = $projectRoot . '/school_config.php';
if (is_file($schoolConfig)) {
    @require_once $schoolConfig;
}
$brandingOk = defined('SCHOOL_NAME') && defined('SITE_URL') && defined('ACTIVE_THEME');
hcRow($rows, 'Branding configuration', $brandingOk ? 'present' : 'incomplete', $brandingOk ? 'ok' : 'warn');

$dbOk = false;
$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!$missingSecrets) {
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    }
    if ($conn instanceof mysqli && !$conn->connect_error) {
        $dbOk = true;
        $conn->set_charset('utf8mb4');
        hcRow($rows, 'Database connection', 'available', 'ok');
    } else {
        hcRow($rows, 'Database connection', 'unavailable', 'bad');
    }
} catch (Throwable $error) {
    hcRow($rows, 'Database connection', 'unavailable', 'bad');
}

if ($dbOk) {
    $counts = [
        'Staff login rows' => 'users',
        'Member rows' => 'members',
        'Attendance rows' => 'attendance',
        'Academic year rows' => 'academic_years',
    ];
    foreach ($counts as $label => $table) {
        try {
            $result = $conn->query("SELECT COUNT(*) AS c FROM `$table`");
            if (!$result) {
                throw new RuntimeException('count unavailable');
            }
            $count = (int)($result->fetch_assoc()['c'] ?? 0);
            $result->free();
            hcRow($rows, $label, number_format($count));
        } catch (Throwable $error) {
            hcRow($rows, $label, 'unavailable', 'warn');
        }
    }
    try {
        $result = $conn->query('SELECT COUNT(*) AS c FROM academic_years WHERE is_current = 1');
        $currentCount = $result ? (int)($result->fetch_assoc()['c'] ?? 0) : -1;
        if ($result) {
            $result->free();
        }
        hcRow(
            $rows,
            'Current academic year',
            $currentCount === 1 ? 'exactly one' : 'configuration needs attention',
            $currentCount === 1 ? 'ok' : 'bad'
        );
    } catch (Throwable $error) {
        hcRow($rows, 'Current academic year', 'unavailable', 'warn');
    }
}

try {
    $free = @disk_free_space($projectRoot);
    $total = @disk_total_space($projectRoot);
    if ($free !== false && $total !== false && $free > 0 && $total > 0) {
        $percent = round($free / $total * 100, 1);
        hcRow($rows, 'Disk free', round($free / 1073741824, 2) . ' GB (' . $percent . '% free)', $percent < 10 ? 'bad' : 'ok');
    } else {
        hcRow($rows, 'Disk free', 'unavailable', 'warn');
    }
} catch (Throwable $error) {
    hcRow($rows, 'Disk free', 'unavailable', 'warn');
}

// Report log metadata, never raw log content (which may contain PII or secrets).
$logPath = $projectRoot . '/error.log';
if (is_file($logPath)) {
    $modified = @filemtime($logPath);
    $size = @filesize($logPath);
    hcRow(
        $rows,
        'Application error log',
        'present; updated ' . ($modified ? htmlspecialchars(date('Y-m-d H:i:s', $modified), ENT_QUOTES, 'UTF-8') : 'at an unknown time')
            . '; ' . number_format((int)$size) . ' bytes',
        ((int)$size > 0) ? 'warn' : 'ok'
    );
} else {
    hcRow($rows, 'Application error log', 'no file present', 'ok');
}

try {
    require_once $projectRoot . '/admin/backend/services/BackupService.php';
    $backups = \App\Services\BackupService::listBackups($projectRoot, 1);
    if ($backups) {
        $latest = $backups[0];
        $ageHours = round((time() - (int)$latest['modified']) / 3600, 1);
        hcRow(
            $rows,
            'Last backup',
            ($latest['encrypted'] ? 'encrypted' : 'legacy plaintext') . '; ' . $ageHours . ' hours ago; '
                . round((int)$latest['size'] / 1048576, 2) . ' MB',
            $ageHours > 48 || !$latest['encrypted'] ? 'warn' : 'ok'
        );
    } else {
        hcRow($rows, 'Last backup', 'none found', 'bad');
    }
} catch (Throwable $error) {
    hcRow($rows, 'Last backup', 'unavailable', 'warn');
}

if ($conn instanceof mysqli) {
    $conn->close();
}

hcPage(
    'System Health Check',
    '<h1>System Health Check</h1><p style="color:#94a3b8">Generated '
        . htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8')
        . '</p><table>' . $rows . '</table>'
);
