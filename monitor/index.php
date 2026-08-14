<?php
/**
 * ============================================================
 * Error Monitor — Dashboard
 * ============================================================
 * URL: {SITE_URL}/monitor/
 * 
 * SECURITY:
 *  - Custom login page that verifies against super_admin password
 *  - Only super_admin accounts can access
 *  - Session-based (stays logged in while browser is open)
 * ============================================================
 */

// Load config for DB connection + session
require_once dirname(__DIR__) . '/config.php';

// ── AUTHENTICATION ──
$loginError = '';
$isAuthenticated = !empty($_SESSION['monitor_authenticated']);

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout' && !isset($_GET['id'])) {
    unset($_SESSION['monitor_authenticated'], $_SESSION['monitor_admin_name']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Handle login POST
if (!$isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['monitor_password'])) {
    $password = $_POST['monitor_password'] ?? '';
    
    if ($password !== '') {
        try {
            $stmt = $pdo->prepare("SELECT id, username, full_name, password_hash FROM users WHERE role = 'super_admin' AND is_active = 1");
            $stmt->execute();
            $admins = $stmt->fetchAll();
            
            $matched = false;
            foreach ($admins as $admin) {
                if (password_verify($password, $admin['password_hash'])) {
                    $_SESSION['monitor_authenticated'] = true;
                    $_SESSION['monitor_admin_name'] = $admin['full_name'] ?: $admin['username'];
                    $matched = true;
                    break;
                }
            }
            
            if (!$matched) {
                $loginError = 'Wrong password. Only Super Admin passwords work here.';
            }
        } catch (Exception $e) {
            $loginError = 'Database error. Please try again.';
        }
        $isAuthenticated = !empty($_SESSION['monitor_authenticated']);
    } else {
        $loginError = 'Please enter your password.';
    }
}

// Show login page if not authenticated
if (!$isAuthenticated) {
    showMonitorLogin($loginError);
    exit;
}

// ── Authenticated from here ──
$monitorCsrf = generateCsrfToken();
$mconn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mconn->connect_error) { die("Monitor DB failed"); }
$mconn->set_charset('utf8mb4');
$adminName = $_SESSION['monitor_admin_name'] ?? 'Admin';

// ── AJAX actions ──
if (isset($_GET['action']) && $_GET['action'] !== 'logout') {
    header('Content-Type: application/json; charset=utf-8');
    
    // CSRF validation for all POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrf($csrfToken)) {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'Security token expired. Please refresh.']);
            exit;
        }
    }
    
    switch ($_GET['action']) {
        case 'resolve':
            $id = (int)($_POST['id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            $stmt = $mconn->prepare("UPDATE arkeon_error_log SET is_resolved=1, resolved_at=NOW(), resolved_note=? WHERE id=?");
            $stmt->bind_param('si', $note, $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success'=>true]); exit;
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $mconn->prepare("DELETE FROM arkeon_error_log WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success'=>true]); exit;
        case 'clear_resolved':
            $mconn->query("DELETE FROM arkeon_error_log WHERE is_resolved=1");
            echo json_encode(['success'=>true]); exit;
        case 'get_detail':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $mconn->prepare("SELECT * FROM arkeon_error_log WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $r = $stmt->get_result();
            echo json_encode($r ? $r->fetch_assoc() : null);
            $stmt->close();
            exit;
        case 'test_error':
            trigger_error("Monitor test — this is a test!", E_USER_WARNING);
            echo json_encode(['success'=>true,'message'=>'Test error triggered! Refresh to see it.']); exit;
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
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>⚡ <?= defined('MONITOR_PAGE_TITLE') ? MONITOR_PAGE_TITLE : 'Error Monitor' ?></title>
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
.st{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:16px;font-family:'JetBrains Mono',monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;margin-top:16px;max-height:300px;overflow-y:auto}
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
<button class="btn bs" onclick="testError()">🧪 Test</button>
<button class="btn" onclick="location.reload()">🔄 Refresh</button>
<button class="btn bd" onclick="clearResolved()">🗑 Clear Resolved</button>
<a href="/admin/dashboard.php" class="btn">← <?= SCHOOL_NAME_SHORT ?></a>
<a href="?action=logout" class="btn">Logout</a>
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
<?php foreach($errors as $e):?><tr class="<?=$e['is_resolved']?'res':''?>" onclick="showDetail(<?=$e['id']?>)" style="cursor:pointer">
<td><span class="sb sb-<?=htmlspecialchars($e['severity'])?>"><?=htmlspecialchars($e['severity'])?></span></td>
<td style="font-size:12px;white-space:nowrap"><?=htmlspecialchars($e['project_name'])?></td>
<td><div class="em" title="<?=htmlspecialchars($e['message'])?>"><?=htmlspecialchars(mb_substr($e['message'],0,100))?></div></td>
<td class="fi"><?=htmlspecialchars(basename($e['file_path']))?>:<?=$e['line_number']?></td>
<td><?=$e['auto_fix_applied']?'<span class="af">🔧 Fixed</span>':'<span style="color:var(--tm)">—</span>'?></td>
<td class="ta"><?=timeAgo($e['created_at'])?></td>
<td onclick="event.stopPropagation()"><div class="ra"><?php if(!$e['is_resolved']):?><button class="rb" onclick="resolveError(<?=$e['id']?>)">✓</button><?php endif;?><button class="db" onclick="deleteError(<?=$e['id']?>)">✕</button></div></td>
</tr><?php endforeach;?></tbody></table><?php endif;?></div>
<?php if($totalPages>1):?><div class="pg"><span><?=$offset+1?>–<?=min($offset+$perPage,$totalErrors)?> of <?=$totalErrors?></span><div class="ps">
<?php if($page>1):?><a href="?<?=http_build_query(array_merge($_GET,['page'=>$page-1]))?>">←</a><?php endif;?>
<?php for($i=max(1,$page-3);$i<=min($totalPages,$page+3);$i++):?><a href="?<?=http_build_query(array_merge($_GET,['page'=>$i]))?>" class="<?=$i===$page?'act':''?>"><?=$i?></a><?php endfor;?>
<?php if($page<$totalPages):?><a href="?<?=http_build_query(array_merge($_GET,['page'=>$page+1]))?>">→</a><?php endif;?></div></div><?php endif;?>
</div>
<div class="mo" id="dm"><div class="md"><button class="cb" onclick="closeModal()">×</button><h2>Error Detail <span id="di" style="color:var(--tm);font-size:14px"></span></h2><div class="dg" id="dc"></div><div class="st" id="ds"></div><div style="margin-top:16px;display:flex;gap:12px"><button class="btn bp" onclick="resolveFromDetail()">✓ Resolve</button><button class="btn bd" onclick="deleteFromDetail()">✕ Delete</button></div></div></div>
<script>
const CSRF='<?=$monitorCsrf?>';
let cid=null;
async function showDetail(id){cid=id;const r=await fetch(`?action=get_detail&id=${id}`);const d=await r.json();if(!d)return;
document.getElementById('di').textContent=`#${d.id}`;
const f=[['Project',d.project_name],['Type',d.error_type],['Severity',`<span class="sb sb-${d.severity}">${d.severity}</span>`],['Message',d.message],['File',`<code>${d.file_path}:${d.line_number}</code>`],['URL',d.url||'N/A'],['Method',d.http_method||'N/A'],['IP',d.ip_address||'N/A'],['User Agent',d.user_agent||'N/A'],['Memory',fB(d.memory_usage)],['Peak',fB(d.peak_memory)],['Time',d.execution_time+'s'],['PHP',d.php_version||'N/A'],['Auto-Fix',d.auto_fix_applied||'None'],['Status',d.is_resolved==1?'✅ Resolved':'❌ Open'],['When',d.created_at]];
if(d.request_data&&d.request_data!=='[]'&&d.request_data!=='{}')f.push(['Request',`<pre style="font-size:11px;margin:0">${fJ(d.request_data)}</pre>`]);
if(d.extra_data&&d.extra_data!=='[]'&&d.extra_data!=='{}')f.push(['Extra',`<pre style="font-size:11px;margin:0">${fJ(d.extra_data)}</pre>`]);
if(d.resolved_note)f.push(['Note',d.resolved_note]);
document.getElementById('dc').innerHTML=f.map(([l,v])=>`<div class="dl">${l}</div><div>${v}</div>`).join('');
document.getElementById('ds').textContent=d.stack_trace||'No stack trace';document.getElementById('dm').classList.add('active')}
function closeModal(){document.getElementById('dm').classList.remove('active');cid=null}
async function resolveError(id){const n=prompt('Note (optional):')||'';const r=await fetch('?action=resolve',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${id}&note=${encodeURIComponent(n)}&csrf_token=${CSRF}`});if((await r.json()).success)location.reload()}
async function deleteError(id){if(!confirm('Delete?'))return;const r=await fetch('?action=delete',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${id}&csrf_token=${CSRF}`});if((await r.json()).success)location.reload()}
async function resolveFromDetail(){if(cid)await resolveError(cid)}
async function deleteFromDetail(){if(cid)await deleteError(cid)}
async function clearResolved(){if(!confirm('Delete ALL resolved?'))return;const r=await fetch('?action=clear_resolved',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`csrf_token=${CSRF}`});if((await r.json()).success)location.reload()}
async function testError(){const r=await fetch('?action=test_error');const d=await r.json();alert(d.message||'Done!');setTimeout(()=>location.reload(),500)}
function fB(b){if(!b)return'0 B';const k=1024,s=['B','KB','MB','GB'],i=Math.floor(Math.log(b)/Math.log(k));return parseFloat((b/Math.pow(k,i)).toFixed(1))+' '+s[i]}
function fJ(s){try{return JSON.stringify(JSON.parse(s),null,2)}catch(e){return s}}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal()});
document.getElementById('dm').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal()});
</script></body></html>
<?php
function showMonitorLogin($error=''){
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= defined('MONITOR_PAGE_TITLE') ? MONITOR_PAGE_TITLE : 'Monitor' ?></title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#0d1117;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:20px}
.lc{background:#161b22;border:1px solid #30363d;border-radius:16px;padding:48px 40px;width:380px;text-align:center}
.logo{font-size:48px;margin-bottom:12px}h2{color:#58a6ff;font-size:20px;margin-bottom:6px}.sub{color:#8b949e;font-size:14px;margin-bottom:32px}
.ig{text-align:left;margin-bottom:20px}.ig label{display:block;color:#8b949e;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
input[type="password"]{width:100%;padding:14px 16px;background:#0d1117;border:1px solid #30363d;border-radius:8px;color:#e6edf3;font-size:15px;outline:none;transition:border-color .2s}
input[type="password"]:focus{border-color:#58a6ff}input[type="password"]::placeholder{color:#484f58}
button{width:100%;padding:14px;background:linear-gradient(135deg,#58a6ff,#3b82f6);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:opacity .2s}button:hover{opacity:.9}
.err{background:rgba(248,81,73,.1);border:1px solid rgba(248,81,73,.3);color:#f85149;padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:20px}
.hint{color:#484f58;font-size:12px;margin-top:24px;line-height:1.6}.hint a{color:#58a6ff;text-decoration:none}</style></head>
<body><div class="lc"><div class="logo">⚡</div><h2><?= defined('MONITOR_PAGE_TITLE') ? MONITOR_PAGE_TITLE : 'Error Monitor' ?></h2><p class="sub">Enter your Super Admin password</p>
<?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="POST"><div class="ig"><label>Super Admin Password</label><input type="password" name="monitor_password" placeholder="Enter password..." autofocus required></div>
<button type="submit">🔓 Access Monitor</button></form>
<p class="hint">Only Super Admin accounts can access.<br><a href="/admin/">← Back to <?= defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School' ?> Admin</a></p></div></body></html>
<?php } ?>
