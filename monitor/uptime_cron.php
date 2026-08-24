<?php
/**
 * ============================================================
 * School UPTIME MONITOR — Cron Job
 * ============================================================
 * Checks if your sites are up every 5 minutes.
 * 
 * CRON SETUP (cPanel > Cron Jobs):
 *   Schedule: every five minutes (standard five-minute cron interval)
 *   Command:  php /home/{HOSTING_USER}/domains/{SITE_DOMAIN}/public_html/monitor/uptime_cron.php
 * ============================================================
 */

// Load credentials from main config (CLI-safe: skip session redirects)
define('WBWS_API_REQUEST', true);
require_once dirname(__DIR__) . '/config.php';
if (!feature_enabled('monitor')) {
    fwrite(STDOUT, "Monitor feature disabled; no uptime check was run.\n");
    exit(0);
}

$config = [
    'db_host' => DB_HOST,
    'db_name' => DB_NAME,
    'db_user' => DB_USER,
    'db_pass' => DB_PASS,
    
    // Telegram — set these up later
    'telegram_enabled' => false,  // Change to true after setup
    'telegram_bot_token' => 'YOUR_BOT_TOKEN_HERE',
    'telegram_chat_id' => 'YOUR_CHAT_ID_HERE',
    
    'timeout' => 15,
    'alert_after_fails' => 2,
];

// ── SITES TO MONITOR ──
$sites = [
    [
        'name' => defined('MONITOR_PROJECT_NAME') ? MONITOR_PROJECT_NAME : 'School',
        'url' => defined('SITE_URL') ? SITE_URL : 'https://example.com',
        'expected_code' => 200,
    ],
    // Uncomment and add your other Arkeon sites:
    // [
    //     'name' => 'Bulbula Amen FC',
    //     'url' => 'https://bulbulaamenfc.com',
    //     'expected_code' => 200,
    // ],
    // [
    //     'name' => 'Bulbula Registration',
    //     'url' => 'https://register.bulbulaamenfc.com',
    //     'expected_code' => 200,
    // ],
    // [
    //     'name' => 'Daron Restaurant',
    //     'url' => 'https://daron.arkeonagency.com',
    //     'expected_code' => 200,
    // ],
];

// ── DO NOT EDIT BELOW ──

$conn = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
if ($conn->connect_error) { die("DB failed: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');

$downSites = [];

foreach ($sites as $site) {
    $result = checkSite($site, $config);
    
    $stmt = $conn->prepare(
        "INSERT INTO arkeon_uptime_log (project_name, url_checked, status_code, response_time_ms, is_up, error_message, checked_at) 
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('ssiiss', $site['name'], $site['url'], $result['status_code'], $result['response_time'], $result['is_up'], $result['error']);
    $stmt->execute();
    $stmt->close();
    
    if (!$result['is_up']) {
        $cs = $conn->prepare("SELECT COUNT(*) as fails FROM arkeon_uptime_log WHERE project_name=? AND is_up=0 AND checked_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        $cs->bind_param('s', $site['name']);
        $cs->execute();
        $failCount = (int)$cs->get_result()->fetch_assoc()['fails'];
        $cs->close();
        
        if ($failCount >= $config['alert_after_fails']) {
            $downSites[] = ['name'=>$site['name'], 'url'=>$site['url'], 'error'=>$result['error'], 'status_code'=>$result['status_code'], 'consecutive_fails'=>$failCount];
        }
    }
    
    if ($result['is_up']) {
        $wd = $conn->prepare("SELECT id FROM arkeon_uptime_log WHERE project_name=? AND is_up=0 AND checked_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY checked_at DESC LIMIT 1");
        $wd->bind_param('s', $site['name']);
        $wd->execute();
        if ($wd->get_result()->num_rows > 0) {
            sendRecoveryAlert($site, $result, $config);
        }
        $wd->close();
    }
}

if (!empty($downSites) && $config['telegram_enabled']) {
    sendDownAlert($downSites, $config);
}

// Privacy retention is a deployment/cron concern, never request-time work.
// Bounded batches avoid long locks if a busy installation accumulates a backlog.
$conn->query("DELETE FROM arkeon_uptime_log WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 5000");
$conn->query("DELETE FROM arkeon_error_log WHERE is_resolved=1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 5000");
$conn->query("DELETE FROM arkeon_error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 5000");
$conn->close();

echo "Checked " . count($sites) . " sites. " . count($downSites) . " down.\n";

function checkSite($site, $config) {
    $start = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $site['url'], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['timeout'], CURLOPT_CONNECTTIMEOUT => $config['timeout'],
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Arkeon Uptime/1.0',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $ms = round((microtime(true) - $start) * 1000);
    return ['is_up' => ($code === ($site['expected_code'] ?? 200) && empty($err)) ? 1 : 0, 'status_code' => $code, 'response_time' => $ms, 'error' => $err ?: null];
}

function sendDownAlert($sites, $cfg) {
    $m = "🔴 *SITE DOWN ALERT*\n━━━━━━━━━━━━━━━━━━\n";
    foreach ($sites as $s) {
        $m .= "\n❌ *{$s['name']}*\n🌐 {$s['url']}\n📊 Status: {$s['status_code']}\n";
        if ($s['error']) $m .= "💬 " . mb_substr($s['error'], 0, 100) . "\n";
        $m .= "🔄 Failed: {$s['consecutive_fails']}x\n";
    }
    $m .= "\n⏱ " . date('H:i:s');
    sendTelegram($m, $cfg);
}

function sendRecoveryAlert($site, $result, $cfg) {
    sendTelegram("✅ *SITE RECOVERED*\n━━━━━━━━━━━━━━━━━━\n🌐 *{$site['name']}* is back!\n📊 Status: {$result['status_code']}\n⚡ {$result['response_time']}ms\n⏱ " . date('H:i:s'), $cfg);
}

function sendTelegram($msg, $cfg) {
    if (!$cfg['telegram_enabled'] || $cfg['telegram_bot_token'] === 'YOUR_BOT_TOKEN_HERE') return;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.telegram.org/bot{$cfg['telegram_bot_token']}/sendMessage",
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id'=>$cfg['telegram_chat_id'], 'text'=>$msg, 'parse_mode'=>'Markdown', 'disable_web_page_preview'=>true]),
    ]);
    curl_exec($ch); curl_close($ch);
}
