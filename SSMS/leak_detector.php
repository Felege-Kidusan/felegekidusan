<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║           BRANDING LEAK DETECTOR                            ║
 * ║                                                             ║
 * ║  Run this after deploying to a new school to verify         ║
 * ║  ZERO old school names remain in the codebase.              ║
 * ║                                                             ║
 * ║  Usage:                                                     ║
 * ║    Browser: https://yoursite.com/admin/leak_detector.php    ║
 * ║    CLI:     php leak_detector.php                           ║
 * ║                                                             ║
 * ║  If it says "0 leaks found" — you're safe.                  ║
 * ║  If it finds anything — it tells you exactly where.         ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// Require admin login in browser mode
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    session_start();
    if (empty($_SESSION['admin_id'])) {
        die('Access denied. Log in as admin first.');
    }
    // Only super_admin can run this
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        die('Access denied. Super Admin only.');
    }
}

// Load config for the leak keywords
$configPath = __DIR__ . '/../school_config.php';
if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__) . '/school_config.php';
}
if (file_exists($configPath)) {
    require_once $configPath;
}

// ─── CONFIGURATION ───────────────────────────────────────────

// Keywords to search for (old school's data)
// These come from school_config.php LEAK_DETECT_KEYWORDS,
// or you can hardcode them here for the school you're replacing
$keywords = defined('LEAK_DETECT_KEYWORDS') 
    ? unserialize(LEAK_DETECT_KEYWORDS) 
    : [
        // ADD YOUR OLD SCHOOL KEYWORDS HERE
        'WBWS', 'wbws', 'Wulde Birhan', 'Wulud Birhan', 'Wlude Brhan',
        'ውሉደ ብርሃን', 'ውሉደ', 'Children of Light',
        'Gelana Gura', 'ገላን ጉራ', 'ፈለገግዮን', 'ቅዱስ ገብርኤል',
        'wbws.pro.et', 'wbwsprvr', 'Wolaita Bethel',
    ];

// Directories to scan (relative to public_html)
$scanDirs = [
    __DIR__ . '/..',  // public_html root
];

// File extensions to check
$extensions = ['php', 'js', 'html', 'css', 'json', 'txt'];

// Files/directories to SKIP (these are OK to have the old names)
$skipPatterns = [
    'leak_detector.php',        // this file itself
    'school_config.php',        // config file defines these values
    'phpqrcode/',               // QR library
    'chart.umd.min.js',         // chart library
    'node_modules/',            // dependencies
    'vendor/',                  // composer
    '/cache/',                  // cache files
    '.wbws_env.php',            // env file (has DB credentials)
];

// Internal code patterns that are OK (DB tables, CSS classes, function names)
$internalPatterns = [
    '/wbws_group/',             // DB table name — internal
    '/wbws_audit/',             // DB table name — internal
    '/\.wbws-bnav/',            // CSS class — internal
    '/\.wbws-mob/',             // CSS class — internal
    '/wbws_format_/',           // PHP function — internal
    '/wbws_get_/',              // PHP function — internal
    '/wbws_font_/',             // PHP function — internal
    '/wbws_calendar/',          // PHP function/session — internal
    '/WBWSCalendar/',           // JS object — internal
    '/WBWS_CALENDAR/',          // JS constant — internal
    '/WBWS_API_REQUEST/',       // PHP constant — internal
    '/wbws-calendar\.js/',      // filename reference — internal
    '/text-wbws-/',             // Tailwind class — internal
    '/bg-wbws-/',               // Tailwind class — internal
    '/wbws-theme/',             // localStorage key — internal
    '/arkeon_error_log/',       // monitor DB table — internal
    '/arkeon_uptime_log/',      // monitor DB table — internal
    '/ArkeonErrorMonitor/',     // PHP class name — internal
];

// ─── SCANNER ─────────────────────────────────────────────────

$leaks = [];
$filesScanned = 0;
$filesClean = 0;

function shouldSkip($path) {
    global $skipPatterns;
    foreach ($skipPatterns as $pattern) {
        if (strpos($path, $pattern) !== false) return true;
    }
    return false;
}

function isInternalCode($line) {
    global $internalPatterns;
    foreach ($internalPatterns as $pattern) {
        if (preg_match($pattern, $line)) return true;
    }
    return false;
}

function scanFile($filePath, $relativePath) {
    global $keywords, $leaks, $filesScanned, $filesClean;
    
    if (shouldSkip($relativePath)) return;
    
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    global $extensions;
    if (!in_array(strtolower($ext), $extensions)) return;
    
    $filesScanned++;
    $content = @file_get_contents($filePath);
    if ($content === false) return;
    
    $lines = explode("\n", $content);
    $fileLeaks = [];
    
    foreach ($lines as $lineNum => $line) {
        // Skip if this line is internal code
        if (isInternalCode($line)) continue;
        
        // Check each keyword
        foreach ($keywords as $keyword) {
            if (mb_strpos($line, $keyword) !== false) {
                // Check it's not just a comment header (line 1-5 with * or //)
                $trimmed = ltrim($line);
                $isComment = ($lineNum < 5 && (
                    strpos($trimmed, '*') === 0 || 
                    strpos($trimmed, '//') === 0 ||
                    strpos($trimmed, '#') === 0
                ));
                
                $fileLeaks[] = [
                    'line' => $lineNum + 1,
                    'keyword' => $keyword,
                    'text' => mb_substr(trim($line), 0, 120),
                    'is_comment' => $isComment,
                    'severity' => $isComment ? 'LOW' : 'HIGH',
                ];
                break; // One keyword match per line is enough
            }
        }
    }
    
    if (!empty($fileLeaks)) {
        $leaks[$relativePath] = $fileLeaks;
    } else {
        $filesClean++;
    }
}

function scanDirectory($dir, $baseDir = null) {
    if ($baseDir === null) $baseDir = $dir;
    
    $items = @scandir($dir);
    if (!$items) return;
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        $relativePath = str_replace($baseDir . '/', '', $path);
        
        if (is_dir($path)) {
            if (shouldSkip($relativePath)) continue;
            scanDirectory($path, $baseDir);
        } else {
            scanFile($path, $relativePath);
        }
    }
}

// Run the scan
foreach ($scanDirs as $dir) {
    $realDir = realpath($dir);
    if ($realDir) scanDirectory($realDir);
}

// Count totals
$totalLeaks = 0;
$highLeaks = 0;
$lowLeaks = 0;
foreach ($leaks as $file => $fileLeaks) {
    foreach ($fileLeaks as $leak) {
        $totalLeaks++;
        if ($leak['severity'] === 'HIGH') $highLeaks++;
        else $lowLeaks++;
    }
}

// ─── OUTPUT ──────────────────────────────────────────────────

if ($isCLI) {
    // CLI output
    echo "\n";
    echo "╔══════════════════════════════════════════════════════╗\n";
    echo "║           BRANDING LEAK DETECTOR RESULTS             ║\n";
    echo "╚══════════════════════════════════════════════════════╝\n\n";
    echo "Files scanned: {$filesScanned}\n";
    echo "Files clean:   {$filesClean}\n";
    echo "Files leaked:  " . count($leaks) . "\n";
    echo "Total leaks:   {$totalLeaks} (HIGH: {$highLeaks}, LOW: {$lowLeaks})\n\n";
    
    if ($totalLeaks === 0) {
        echo "✅ ZERO LEAKS FOUND — Safe to deploy!\n\n";
    } else {
        echo "🚨 LEAKS DETECTED — Fix these before going live:\n\n";
        foreach ($leaks as $file => $fileLeaks) {
            echo "─── {$file} (" . count($fileLeaks) . " leaks) ───\n";
            foreach ($fileLeaks as $leak) {
                $icon = $leak['severity'] === 'HIGH' ? '🔴' : '🟡';
                echo "  {$icon} Line {$leak['line']}: [{$leak['keyword']}]\n";
                echo "     {$leak['text']}\n";
            }
            echo "\n";
        }
    }
} else {
    // Browser output
    $statusColor = $totalLeaks === 0 ? '#059669' : '#dc2626';
    $statusIcon = $totalLeaks === 0 ? '✅' : '🚨';
    $statusText = $totalLeaks === 0 ? 'ZERO LEAKS — Safe to deploy!' : "LEAKS DETECTED — Fix before going live!";
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Leak Detector — <?= defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School' ?></title>
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;color:#1e293b;padding:20px}
            .card{background:#fff;border-radius:12px;padding:24px;margin:12px 0;box-shadow:0 1px 3px rgba(0,0,0,.1)}
            .status{text-align:center;padding:32px;border-radius:12px;color:#fff;font-size:18px;font-weight:700}
            .stats{display:flex;gap:12px;flex-wrap:wrap}
            .stat{flex:1;min-width:120px;text-align:center;padding:16px;border-radius:8px;background:#f1f5f9}
            .stat .num{font-size:28px;font-weight:700}
            .stat .label{font-size:12px;color:#64748b;margin-top:4px}
            .file{margin:16px 0;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden}
            .file-header{background:#f8fafc;padding:10px 16px;font-weight:600;font-size:14px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between}
            .leak{padding:8px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;font-family:monospace}
            .leak:last-child{border-bottom:none}
            .high{border-left:3px solid #dc2626}
            .low{border-left:3px solid #f59e0b}
            .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
            .badge-high{background:#fef2f2;color:#991b1b}
            .badge-low{background:#fefce8;color:#854d0e}
            .keyword{background:#e0e7ff;color:#3730a3;padding:1px 6px;border-radius:3px;font-size:12px}
        </style>
    </head>
    <body>
        <div class="status" style="background:<?= $statusColor ?>">
            <div style="font-size:48px;margin-bottom:8px"><?= $statusIcon ?></div>
            <?= $statusText ?>
        </div>
        
        <div class="stats" style="margin:16px 0">
            <div class="stat"><div class="num"><?= $filesScanned ?></div><div class="label">Files Scanned</div></div>
            <div class="stat"><div class="num"><?= $filesClean ?></div><div class="label">Clean Files</div></div>
            <div class="stat"><div class="num" style="color:<?= $totalLeaks > 0 ? '#dc2626' : '#059669' ?>"><?= $totalLeaks ?></div><div class="label">Total Leaks</div></div>
            <div class="stat"><div class="num" style="color:#dc2626"><?= $highLeaks ?></div><div class="label">High (User-Visible)</div></div>
            <div class="stat"><div class="num" style="color:#f59e0b"><?= $lowLeaks ?></div><div class="label">Low (Comments)</div></div>
        </div>
        
        <div class="card">
            <h3 style="margin-bottom:8px">Keywords searched:</h3>
            <div><?php foreach($keywords as $kw): ?><span class="keyword"><?= htmlspecialchars($kw) ?></span> <?php endforeach; ?></div>
        </div>
        
        <?php if ($totalLeaks > 0): ?>
        <div class="card">
            <h3 style="margin-bottom:16px">Leaked Files (<?= count($leaks) ?>)</h3>
            <?php foreach ($leaks as $file => $fileLeaks): ?>
            <div class="file">
                <div class="file-header">
                    <span><?= htmlspecialchars($file) ?></span>
                    <span><?= count($fileLeaks) ?> leak<?= count($fileLeaks) > 1 ? 's' : '' ?></span>
                </div>
                <?php foreach ($fileLeaks as $leak): ?>
                <div class="leak <?= strtolower($leak['severity']) ?>">
                    <span class="badge badge-<?= strtolower($leak['severity']) ?>"><?= $leak['severity'] ?></span>
                    Line <?= $leak['line'] ?>: 
                    <span class="keyword"><?= htmlspecialchars($leak['keyword']) ?></span>
                    <br><code><?= htmlspecialchars($leak['text']) ?></code>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="card" style="text-align:center;color:#64748b;font-size:13px">
            Scan completed at <?= date('Y-m-d H:i:s') ?> — 
            Searching <?= count($keywords) ?> keywords across <?= $filesScanned ?> files
        </div>
    </body>
    </html>
    <?php
}
