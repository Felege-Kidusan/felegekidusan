<?php
/**
 * ============================================================
 * Error Monitor — Dashboard
 * ============================================================
 * URL: {SITE_URL}/monitor/
 * 
 * SECURITY:
 *  - Requires an active, database-revalidated Super Admin session
 *  - Requires short-lived, throttled password step-up authentication
 *  - All state changes require POST and CSRF validation
 * ============================================================
 */

// Load config for DB connection + session
require_once dirname(__DIR__) . '/config.php';
if (!feature_enabled('monitor')) {
    http_response_code(404);
    exit('Monitor is not enabled for this deployment.');
}
if (!headers_sent()) {
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
}

// ── AUTHENTICATION ──
// The monitor is a privileged extension of the admin session, never an
// alternate password-only login surface.
require_once dirname(__DIR__) . '/admin/backend/services/MonitorAccessService.php';

use App\Services\MonitorAccessService;
use App\Services\SecurityRateLimiter;

// Retire the legacy password-only authorization flags. They are deliberately
// ignored and removed so an older session cannot survive this access upgrade.
unset($_SESSION['monitor_authenticated'], $_SESSION['monitor_admin_name']);

if (!isLoggedIn() || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    unset($_SESSION['monitor_admin_id'], $_SESSION['monitor_authenticated_at']);
    denyMonitorAccess(401, 'A Super Admin session is required.');
}

if (!$pdo instanceof PDO) {
    monitorUnavailable();
}

try {
    $rateLimiter = new SecurityRateLimiter($pdo, ROOT_PATH . '/admin/uploads/cache');
    $monitorAccess = new MonitorAccessService($pdo, $rateLimiter);
    $admin = $monitorAccess->findActiveSuperAdmin((int)($_SESSION['admin_id'] ?? 0));
} catch (Throwable $error) {
    error_log('Monitor authorization lookup failed.');
    monitorUnavailable();
}

// Revalidate role and active status from the database on every monitor request.
// A stale or disabled admin session must fail closed for this sensitive area.
if (!$admin) {
    unset(
        $_SESSION['admin_logged_in'],
        $_SESSION['admin_id'],
        $_SESSION['admin_username'],
        $_SESSION['admin_role'],
        $_SESSION['admin_full_name'],
        $_SESSION['monitor_admin_id'],
        $_SESSION['monitor_authenticated_at']
    );
    denyMonitorAccess(403, 'Your account is not authorized for the monitor.');
}

$monitorCsrf = generateCsrfToken();
$adminName = $admin['full_name'] !== '' ? $admin['full_name'] : $admin['username'];

// Monitor-only logout removes step-up authorization but keeps the normal admin
// session. State changes are POST + CSRF protected.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['monitor_action'] ?? '') === 'logout') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Security token expired. Please go back and refresh.');
    }
    recordMonitorAccess($pdo, $admin, 'Monitor logout', 'Monitor step-up authorization cleared');
    unset($_SESSION['monitor_admin_id'], $_SESSION['monitor_authenticated_at']);
    header('Location: /monitor/');
    exit;
}

$isAuthenticated = $monitorAccess->hasValidStepUp($_SESSION, (int)$admin['id']);
$loginError = '';

if (!$isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['monitor_password'])) {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $loginError = 'Security token expired. Please refresh and try again.';
    } else {
        $result = $monitorAccess->verifyStepUp(
            $admin,
            (string)($_POST['monitor_password'] ?? ''),
            (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        );
        if ($result['success']) {
            session_regenerate_id(true);
            $_SESSION['monitor_admin_id'] = (int)$admin['id'];
            $_SESSION['monitor_authenticated_at'] = time();
            recordMonitorAccess($pdo, $admin, 'Monitor access', 'Step-up authentication succeeded');
            header('Location: /monitor/');
            exit;
        }
        if ($result['limited']) {
            $waitMinutes = max(1, (int)ceil($result['retry_after'] / 60));
            $loginError = "Too many attempts. Please wait {$waitMinutes} minute(s).";
            header('Retry-After: ' . max(1, (int)$result['retry_after']));
        } else {
            recordMonitorAccess($pdo, $admin, 'Monitor access denied', 'Step-up authentication failed');
            $loginError = 'Unable to verify your password.';
        }
    }
}

if (!$isAuthenticated) {
    // Fetch/API callers receive a machine-readable expiry instead of the login
    // page, while normal navigation receives the step-up form.
    if (isset($_GET['action'])) {
        denyMonitorAccess(401, 'Monitor authorization expired. Re-open the monitor to continue.');
    }
    showMonitorLogin($loginError, $monitorCsrf, $adminName);
    exit;
}

// ── Authenticated from here ──
$mconn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mconn->connect_error) {
    monitorUnavailable();
}
$mconn->set_charset('utf8mb4');

// ── AJAX actions ──
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)$_GET['action'];
    $readActions = ['get_detail'];
    $writeActions = ['resolve', 'delete', 'clear_resolved', 'test_error'];

    if (!in_array($action, array_merge($readActions, $writeActions), true)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Unknown monitor action.']);
        exit;
    }
    if (in_array($action, $readActions, true) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    if (in_array($action, $writeActions, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
            exit;
        }
        if (!validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh.']);
            exit;
        }
    }

    switch ($action) {
        case 'resolve':
            $id = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($id <= 0 || mb_strlen($note) > 2000) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid error or note.']);
                exit;
            }
            $stmt = $mconn->prepare("UPDATE arkeon_error_log SET is_resolved=1, resolved_at=NOW(), resolved_note=? WHERE id=?");
            $stmt->bind_param('si', $note, $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            exit;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid error.']);
                exit;
            }
            $stmt = $mconn->prepare("DELETE FROM arkeon_error_log WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            exit;

        case 'clear_resolved':
            $mconn->query("DELETE FROM arkeon_error_log WHERE is_resolved=1");
            echo json_encode(['success' => true]);
            exit;

        case 'get_detail':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid error.']);
                exit;
            }
            $stmt = $mconn->prepare(
                "SELECT id, project_name, error_type, severity, message, file_path,
                        line_number, url, http_method, ip_address, user_agent,
                        request_data, extra_data, stack_trace, memory_usage,
                        peak_memory, execution_time, auto_fix_applied, php_version,
                        is_resolved, resolved_note, created_at
                 FROM arkeon_error_log WHERE id=?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $detail = $result ? $result->fetch_assoc() : null;
            // Historical rows could contain raw request/custom values. Only
            // privacy-versioned metadata produced by the hardened collector is
            // ever released through the dashboard.
            if (is_array($detail)) {
                foreach (['request_data', 'extra_data'] as $field) {
                    $decoded = json_decode((string)($detail[$field] ?? ''), true);
                    if (!is_array($decoded) || (int)($decoded['privacy_version'] ?? 0) !== 2) {
                        $detail[$field] = null;
                    }
                }
            }
            echo json_encode($detail, JSON_INVALID_UTF8_SUBSTITUTE);
            $stmt->close();
            exit;

        case 'test_error':
            trigger_error("Monitor test — this is a test!", E_USER_WARNING);
            echo json_encode(['success' => true, 'message' => 'Test error triggered! Refresh to see it.']);
            exit;
    }
}

// ── Filters ──
$fSev=$_GET['severity']??''; $fProj=$_GET['project']??''; $fRes=$_GET['resolved']??'0'; $fSearch=$_GET['search']??'';
$page=max(1,(int)($_GET['page']??1)); $perPage=50; $offset=($page-1)*$perPage;
$where=['1=1'];$params=[];$types='';
if($fSev){$where[]="severity=?";$params[]=$fSev;$types.='s';}
if($fProj){$where[]="project_name=?";$params[]=$fProj;$types.='s';}
if($fRes!==''){$where[]="is_resolved=?";$params[]=(int)$fRes;$types.='i';}
if($fSearch){$where[]="(message LIKE ? OR file_path LIKE ? OR url LIKE ?)";$st="%{$fSearch}%";$params[]=$st;$params[]=$st;$params[]=$st;$types.='sss';}
$wc=implode(' AND ',$where);
$cs=$mconn->prepare("SELECT COUNT(*) as total FROM arkeon_error_log WHERE {$wc}");
if($types)$cs->bind_param($types,...$params); $cs->execute();
$totalErrors=(int)$cs->get_result()->fetch_assoc()['total']; $totalPages=max(1,ceil($totalErrors/$perPage));
$stmt=$mconn->prepare("SELECT id,project_name,error_type,severity,message,file_path,line_number,url,auto_fix_applied,is_resolved,created_at FROM arkeon_error_log WHERE {$wc} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
if($types)$stmt->bind_param($types,...$params); $stmt->execute();
$errors=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$projects=[];try{$r=$mconn->query("SELECT DISTINCT project_name FROM arkeon_error_log ORDER BY project_name");if($r)$projects=$r->fetch_all(MYSQLI_ASSOC);}catch(Exception $e){}
$stats=getMonitorStats($mconn);

function getMonitorStats($c){
    $s=['severity_24h'=>[],'unresolved'=>0,'auto_fixes'=>0,'top_error_file'=>'None','uptime'=>[]];
    try{
        $r=$c->query("SELECT severity,COUNT(*) as cnt FROM arkeon_error_log WHERE created_at>DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY severity");
        if($r)while($row=$r->fetch_assoc())$s['severity_24h'][$row['severity']]=$row['cnt'];
        $r=$c->query("SELECT COUNT(*) as cnt FROM arkeon_error_log WHERE is_resolved=0");if($r)$s['unresolved']=(int)$r->fetch_assoc()['cnt'];
        $r=$c->query("SELECT COUNT(*) as cnt FROM arkeon_error_log WHERE auto_fix_applied IS NOT NULL AND created_at>DATE_SUB(NOW(),INTERVAL 7 DAY)");if($r)$s['auto_fixes']=(int)$r->fetch_assoc()['cnt'];
        $r=$c->query("SELECT file_path,COUNT(*) as cnt FROM arkeon_error_log WHERE created_at>DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY file_path ORDER BY cnt DESC LIMIT 1");
        if($r&&$row=$r->fetch_assoc())$s['top_error_file']=basename($row['file_path'])." ({$row['cnt']})";
        $r=$c->query("SELECT project_name,ROUND(AVG(is_up)*100,2) as uptime_pct,ROUND(AVG(response_time_ms)) as avg_response FROM arkeon_uptime_log WHERE checked_at>DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY project_name");
        if($r)while($row=$r->fetch_assoc())$s['uptime'][]=$row;
    }catch(Exception $e){}return $s;
}
function timeAgo($dt){$d=(new DateTime())->diff(new DateTime($dt));if($d->y>0)return $d->y.'y ago';if($d->m>0)return $d->m.'mo ago';if($d->d>0)return $d->d.'d ago';if($d->h>0)return $d->h.'h ago';if($d->i>0)return $d->i.'m ago';return 'Just now';}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><meta name="csrf-token" content="<?= htmlspecialchars($monitorCsrf, ENT_QUOTES, 'UTF-8') ?>"><title>⚡ <?= defined('MONITOR_PAGE_TITLE') ? MONITOR_PAGE_TITLE : 'Error Monitor' ?></title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d1117;--surface:#161b22;--sh:#1c2129;--border:#30363d;--text:#e6edf3;--tm:#8b949e;--crit:#f85149;--err:#f0883e;--warn:#d29922;--info:#58a6ff;--ok:#3fb950;--acc:#58a6ff}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);line-height:1.5}
.header{background:var(--surface);border-bottom:1px solid var(--border);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.header h1{font-family:'JetBrains Mono',monospace;font-size:18px;color:var(--acc)}.header h1 span{color:var(--tm);font-weight:400}
.ha{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.ab{background:rgba(63,185,80,.15);color:var(--ok);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}
.btn{padding:8px 16px;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:13px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s;font-family:inherit}.btn:hover{background:var(--sh);border-color:var(--tm)}
.bd{border-color:var(--crit);color:var(--crit)}.bd:hover{background:rgba(248,81,73,.1)}
.bp{background:var(--acc);color:#fff;border-color:var(--acc)}.bp:hover{opacity:.9}
.bs{background:var(--ok);color:#fff;border-color:var(--ok)}.bs:hover{opacity:.9}
.content{max-width:1400px;margin:0 auto;padding:24px}
.sg{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:20px}
.sc .l{font-size:12px;color:var(--tm);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.sc .v{font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700}
.cc{color:var(--crit)}.ce{color:var(--err)}.cw{color:var(--warn)}.ci{color:var(--info)}
.fl{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:24px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.fl select,.fl input{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:6px;font-size:13px;font-family:inherit}
.fl input[type="text"]{min-width:200px}.fl select:focus,.fl input:focus{outline:none;border-color:var(--acc)}
.et{width:100%;background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.et table{width:100%;border-collapse:collapse}
.et th{text-align:left;padding:12px 16px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--tm);border-bottom:1px solid var(--border);background:rgba(0,0,0,.2)}
.et td{padding:12px 16px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:top}
.et tr:hover td{background:var(--sh)}.et tr:last-child td{border-bottom:none}.et tr.res td{opacity:.5}
.sb{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase}
.sb-critical{background:rgba(248,81,73,.15);color:var(--crit)}.sb-error{background:rgba(240,136,62,.15);color:var(--err)}
.sb-warning{background:rgba(210,153,34,.15);color:var(--warn)}.sb-info{background:rgba(88,166,255,.15);color:var(--info)}
.em{max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}.em:hover{white-space:normal;color:var(--acc)}
.fi{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--tm)}
.af{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;background:rgba(63,185,80,.15);color:var(--ok)}
.ta{color:var(--tm);font-size:12px;white-space:nowrap}
.ra{display:flex;gap:6px}.ra button{padding:4px 8px;border-radius:4px;border:1px solid var(--border);background:transparent;color:var(--tm);cursor:pointer;font-size:12px}
.ra .rb:hover{color:var(--ok);border-color:var(--ok)}.ra .db:hover{color:var(--crit);border-color:var(--crit)}
.mo{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:20px}.mo.active{display:flex}
.md{background:var(--surface);border:1px solid var(--border);border-radius:12px;max-width:800px;width:100%;max-height:90vh;overflow-y:auto;padding:24px}
.md h2{margin-bottom:16px;font-size:18px}
.dg{display:grid;grid-template-columns:140px 1fr;gap:8px 16px;font-size:13px}.dl{color:var(--tm);font-weight:600}
.st{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:16px;font-family:'JetBrains Mono',monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;margin-top:16px;max-height:300px;overflow-y:auto}.detail-json{font-size:11px;margin:0;white-space:pre-wrap;word-break:break-word}
.cb{float:right;background:none;border:none;color:var(--tm);font-size:24px;cursor:pointer}
.pg{display:flex;justify-content:space-between;align-items:center;margin-top:16px;font-size:13px;color:var(--tm)}
.pg .ps{display:flex;gap:4px}.pg a{padding:6px 12px;border:1px solid var(--border);border-radius:4px;color:var(--text);text-decoration:none;font-size:13px}
.pg a:hover{border-color:var(--acc)}.pg a.act{background:var(--acc);border-color:var(--acc)}
.es{text-align:center;padding:60px 20px;color:var(--tm)}.es .ei{font-size:48px;margin-bottom:16px}
.ug{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:24px}
.uc{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:20px}.uc .sn{font-weight:600;margin-bottom:8px}
.ub{height:8px;background:var(--bg);border-radius:4px;overflow:hidden;margin:8px 0}.ub .uf{height:100%;border-radius:4px}
@media(max-width:768px){.content{padding:12px}.sg{grid-template-columns:repeat(2,1fr)}.fl{flex-direction:column}.header{flex-direction:column;gap:12px}}
</style></head><body>
<div class="header">
<h1>⚡ <?= defined('MONITOR_PAGE_TITLE') ? MONITOR_PAGE_TITLE : 'Monitor' ?> <span>/ Error Dashboard</span></h1>
<div class="ha">
<span class="ab">🔒 <?=htmlspecialchars($adminName)?></span>
<button type="button" class="btn bs" data-monitor-action="test">🧪 Test</button>
<button type="button" class="btn" data-monitor-action="refresh">🔄 Refresh</button>
<button type="button" class="btn bd" data-monitor-action="clear-resolved">🗑 Clear Resolved</button>
<a href="/admin/dashboard.php" class="btn">← <?= e(SCHOOL_NAME_SHORT) ?></a>
<form method="POST" style="display:inline"><input type="hidden" name="monitor_action" value="logout"><input type="hidden" name="csrf_token" value="<?= e($monitorCsrf) ?>"><button type="submit" class="btn">Logout</button></form>
</div></div>
<div class="content">
<div class="sg">
<div class="sc"><div class="l">🔴 Critical (24h)</div><div class="v cc"><?=$stats['severity_24h']['critical']??0?></div></div>
<div class="sc"><div class="l">🟠 Errors (24h)</div><div class="v ce"><?=$stats['severity_24h']['error']??0?></div></div>
<div class="sc"><div class="l">🟡 Warnings (24h)</div><div class="v cw"><?=$stats['severity_24h']['warning']??0?></div></div>
<div class="sc"><div class="l">📋 Unresolved</div><div class="v"><?=$stats['unresolved']??0?></div></div>
<div class="sc"><div class="l">🔧 Auto-Fixes (7d)</div><div class="v" style="color:var(--ok)"><?=$stats['auto_fixes']??0?></div></div>
<div class="sc"><div class="l">🔥 Top Error File</div><div class="v" style="font-size:14px"><?=htmlspecialchars($stats['top_error_file']??'None')?></div></div>
</div>
<?php if(!empty($stats['uptime'])):?><div class="ug"><?php foreach($stats['uptime'] as $up):?>
<div class="uc"><div class="sn"><?=htmlspecialchars($up['project_name'])?></div>
<div class="ub"><div class="uf" style="width:<?=$up['uptime_pct']?>%;background:<?=$up['uptime_pct']>=99?'var(--ok)':($up['uptime_pct']>=95?'var(--warn)':'var(--crit)')?>"></div></div>
<div style="display:flex;justify-content:space-between;font-size:12px;color:var(--tm)"><span>Uptime: <?=$up['uptime_pct']?>%</span><span>Avg: <?=$up['avg_response']?>ms</span></div></div>
<?php endforeach;?></div><?php endif;?>
<div class="fl" style="margin-top:24px"><form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;width:100%">
<select name="severity"><option value="">All Severities</option><option value="critical" <?=$fSev==='critical'?'selected':''?>>🔴 Critical</option><option value="error" <?=$fSev==='error'?'selected':''?>>🟠 Error</option><option value="warning" <?=$fSev==='warning'?'selected':''?>>🟡 Warning</option><option value="info" <?=$fSev==='info'?'selected':''?>>🔵 Info</option></select>
<select name="project"><option value="">All Projects</option><?php foreach($projects as $p):?><option value="<?=htmlspecialchars($p['project_name'])?>" <?=$fProj===$p['project_name']?'selected':''?>><?=htmlspecialchars($p['project_name'])?></option><?php endforeach;?></select>
<select name="resolved"><option value="0" <?=$fRes==='0'?'selected':''?>>Unresolved</option><option value="1" <?=$fRes==='1'?'selected':''?>>Resolved</option><option value="" <?=$fRes===''?'selected':''?>>All</option></select>
<input type="text" name="search" placeholder="Search errors..." value="<?=htmlspecialchars($fSearch)?>">
<button type="submit" class="btn bp">🔍 Filter</button><a href="." class="btn">Reset</a></form></div>
<div class="et"><?php if(empty($errors)):?><div class="es"><div class="ei">✅</div><h3>No errors found</h3><p><?= defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'System' ?> is running smoothly!</p></div>
<?php else:?><table><thead><tr><th>Severity</th><th>Project</th><th>Error</th><th>File</th><th>Fix</th><th>Time</th><th>Actions</th></tr></thead><tbody>
<?php foreach($errors as $e):?><tr class="<?=$e['is_resolved']?'res':''?>" data-error-row data-error-id="<?= (int)$e['id'] ?>" style="cursor:pointer">
<td><span class="sb sb-<?=htmlspecialchars($e['severity'])?>"><?=htmlspecialchars($e['severity'])?></span></td>
<td style="font-size:12px;white-space:nowrap"><?=htmlspecialchars($e['project_name'])?></td>
<td><div class="em" title="<?=htmlspecialchars($e['message'])?>"><?=htmlspecialchars(mb_substr($e['message'],0,100))?></div></td>
<td class="fi"><?=htmlspecialchars(basename($e['file_path']))?>:<?=$e['line_number']?></td>
<td><?=$e['auto_fix_applied']?'<span class="af">🔧 Fixed</span>':'<span style="color:var(--tm)">—</span>'?></td>
<td class="ta"><?=timeAgo($e['created_at'])?></td>
<td><div class="ra"><?php if(!$e['is_resolved']):?><button type="button" class="rb" data-monitor-action="resolve" data-error-id="<?= (int)$e['id'] ?>">✓</button><?php endif;?><button type="button" class="db" data-monitor-action="delete" data-error-id="<?= (int)$e['id'] ?>">✕</button></div></td>
</tr><?php endforeach;?></tbody></table><?php endif;?></div>
<?php if($totalPages>1):?><div class="pg"><span><?=$offset+1?>–<?=min($offset+$perPage,$totalErrors)?> of <?=$totalErrors?></span><div class="ps">
<?php if($page>1):?><a href="?<?=http_build_query(array_merge($_GET,['page'=>$page-1]))?>">←</a><?php endif;?>
<?php for($i=max(1,$page-3);$i<=min($totalPages,$page+3);$i++):?><a href="?<?=http_build_query(array_merge($_GET,['page'=>$i]))?>" class="<?=$i===$page?'act':''?>"><?=$i?></a><?php endfor;?>
<?php if($page<$totalPages):?><a href="?<?=http_build_query(array_merge($_GET,['page'=>$page+1]))?>">→</a><?php endif;?></div></div><?php endif;?>
</div>
<div class="mo" id="dm"><div class="md"><button type="button" class="cb" data-monitor-action="close-detail">×</button><h2>Error Detail <span id="di" style="color:var(--tm);font-size:14px"></span></h2><div class="dg" id="dc"></div><div class="st" id="ds"></div><div style="margin-top:16px;display:flex;gap:12px"><button type="button" class="btn bp" data-monitor-action="resolve">✓ Resolve</button><button type="button" class="btn bd" data-monitor-action="delete">✕ Delete</button></div></div></div>
<script src="/monitor/dashboard.js?v=<?= (int) filemtime(__DIR__ . '/dashboard.js') ?>"></script></body></html>
<?php
function denyMonitorAccess($status, $message) {
    $expectsJson = isset($_GET['action'])
        || strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    if ($expectsJson) {
        http_response_code((int)$status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => (string)$message]);
        exit;
    }

    header('Location: /admin/index.php?error=' . rawurlencode('Please sign in with an authorized Super Admin account.'));
    exit;
}

function monitorUnavailable() {
    $expectsJson = isset($_GET['action'])
        || strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    http_response_code(503);
    header('Retry-After: 60');
    if ($expectsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Monitor temporarily unavailable.']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Monitor temporarily unavailable. Please try again later.';
    }
    exit;
}

/** @param array{id:int,username:string} $admin */
function recordMonitorAccess(PDO $database, array $admin, $action, $details) {
    try {
        $statement = $database->prepare(
            'INSERT INTO activity_logs (user_id, username, action, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            (int)$admin['id'],
            (string)$admin['username'],
            substr((string)$action, 0, 100),
            substr((string)$details, 0, 500),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $error) {
        // Authorization must not depend on optional audit-log availability.
    }
}

function showMonitorLogin($error, $csrfToken, $adminName) {
    $pageTitle = defined('MONITOR_PAGE_TITLE') ? MONITOR_PAGE_TITLE : 'Error Monitor';
    $schoolName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School';
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= e($pageTitle) ?></title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#0d1117;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:20px}
.lc{background:#161b22;border:1px solid #30363d;border-radius:16px;padding:48px 40px;width:380px;text-align:center}
.logo{font-size:48px;margin-bottom:12px}h2{color:#58a6ff;font-size:20px;margin-bottom:6px}.sub{color:#8b949e;font-size:14px;margin-bottom:32px}.account{color:#e6edf3;font-weight:600}
.ig{text-align:left;margin-bottom:20px}.ig label{display:block;color:#8b949e;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
input[type="password"]{width:100%;padding:14px 16px;background:#0d1117;border:1px solid #30363d;border-radius:8px;color:#e6edf3;font-size:15px;outline:none;transition:border-color .2s}
input[type="password"]:focus{border-color:#58a6ff}input[type="password"]::placeholder{color:#484f58}
button{width:100%;padding:14px;background:linear-gradient(135deg,#58a6ff,#3b82f6);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:opacity .2s}button:hover{opacity:.9}
.err{background:rgba(248,81,73,.1);border:1px solid rgba(248,81,73,.3);color:#f85149;padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:20px}
.hint{color:#8b949e;font-size:12px;margin-top:24px;line-height:1.6}.hint a{color:#58a6ff;text-decoration:none}</style></head>
<body><div class="lc"><div class="logo">⚡</div><h2><?= e($pageTitle) ?></h2><p class="sub">Confirm the password for<br><span class="account"><?= e($adminName) ?></span></p>
<?php if ($error !== ''): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><div class="ig"><label>Current password</label><input type="password" name="monitor_password" placeholder="Enter your current password" autocomplete="current-password" autofocus required maxlength="4096"></div>
<button type="submit">🔓 Access Monitor</button></form>
<p class="hint">This additional verification expires after 15 minutes.<br><a href="/admin/">← Back to <?= e($schoolName) ?> Admin</a></p></div></body></html>
<?php }
