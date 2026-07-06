<?php
/**
 * ============================================================
 * Mobile API — Token-Based Auth + Full Data Access
 * ============================================================
 * FIXED: CORS headers set AFTER config.php load
 * FIXED: Session redirect bypassed for API calls
 * FIXED: Function names prefixed to avoid conflicts
 * FIXED: Apache Authorization header fallback
 * ============================================================
 */

// CRITICAL: Flag this as API request BEFORE config loads
// This prevents session timeout redirect in config.php
define('WBWS_API_REQUEST', true);

// Load config (starts session, connects DB, sets security headers)
require_once __DIR__ . '/config.php';

// CRITICAL: Override headers AFTER config.php
// config.php sets X-Frame-Options and Content-Type headers that break API
header('Content-Type: application/json; charset=utf-8');

// ── CORS: Smart origin handling ──
// Mobile apps (Flutter) don't send Origin, so they pass through with JWT auth.
// Browsers send Origin — restrict to our domains only.
$_mobileAllowedOrigins = [
    SITE_URL,
    'http://' . SITE_DOMAIN,
    'https://www.' . SITE_DOMAIN,
];
$_mobileOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($_mobileOrigin, $_mobileAllowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $_mobileOrigin);
    header('Vary: Origin');
} elseif ($_mobileOrigin === '') {
    // No Origin = mobile app or server-side request, allow (JWT protects it)
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header_remove('X-Frame-Options');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Safety: Check DB connection
if (!isset($conn) || !$conn || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// ============================================================
// TOKEN HELPERS (prefixed to avoid name collisions)
// ============================================================
if (!defined('TOKEN_SECRET')) define('TOKEN_SECRET', defined('JWT_SECRET') ? JWT_SECRET : EXPORT_PREFIX . '_mobile_' . DB_NAME . '_' . DB_HOST);
if (!defined('TOKEN_EXPIRY')) define('TOKEN_EXPIRY', 86400 * 30);

function _mCreateToken($userId, $username, $role, $fullName) {
    $payload = ['uid'=>$userId,'usr'=>$username,'rol'=>$role,'nam'=>$fullName,'exp'=>time()+TOKEN_EXPIRY,'iat'=>time()];
    $b64 = base64_encode(json_encode($payload));
    return $b64 . '.' . hash_hmac('sha256', $b64, TOKEN_SECRET);
}

function _mVerifyToken($token) {
    if (!$token || strpos($token, '.') === false) return null;
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    if (!hash_equals(hash_hmac('sha256', $parts[0], TOKEN_SECRET), $parts[1])) return null;
    $payload = json_decode(base64_decode($parts[0]), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return null;
    return $payload;
}

function _mGetAuth() {
    $header = '';
    // Try multiple ways to get Authorization header (Apache strips it)
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $header = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    $token = (strpos($header, 'Bearer ') === 0) ? substr($header, 7) : ($_GET['token'] ?? $_POST['token'] ?? '');
    return _mVerifyToken($token);
}

function _ok($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function _err($msg, $code = 400) { http_response_code($code); echo json_encode(['status'=>'error','message'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// ============================================================
// ROUTING
// ============================================================
$path = $_GET['path'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) $input = $_POST;
}

// ============================================================
// PUBLIC ENDPOINTS (no token needed)
// ============================================================
if ($path === 'auth/login') {
    // Brute-force protection: the mobile login had NONE, which let an
    // attacker try unlimited passwords. Reuse the same file-based limiter as
    // the web login (fail-safe: if the cache dir can't be written it simply
    // doesn't block, so a legitimate user is never locked out by an error).
    // Allow 10 tries per 5 minutes per IP.
    if (function_exists('isRateLimited') && isRateLimited('mobile_login', 10, 300)) {
        _err('Too many login attempts. Please wait 5 minutes and try again.', 429);
    }

    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    if (empty($username) || empty($password)) _err('Username and password required');

    $stmt = $conn->prepare("SELECT id, username, email, full_name, role, password_hash, is_active FROM users WHERE username = ? OR email = ? LIMIT 1");
    if (!$stmt) _err('Database error', 500);
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) { if (function_exists('recordAttempt')) recordAttempt('mobile_login'); _err('Invalid username or password', 401); }
    if ((int)$user['is_active'] !== 1) _err('Account is inactive', 403);
    if (!password_verify($password, $user['password_hash'])) { if (function_exists('recordAttempt')) recordAttempt('mobile_login'); _err('Invalid username or password', 401); }

    // Successful login → clear the failed-attempt counter for this IP.
    if (function_exists('clearRateLimit')) clearRateLimit('mobile_login');

    $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . (int)$user['id']);
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ls = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, 'Mobile Login', SCHOOL_NAME_SHORT . ' Mobile App', ?)");
        if ($ls) { $ls->bind_param('iss', $user['id'], $user['username'], $ip); $ls->execute(); $ls->close(); }
    } catch (Exception $e) {}

    _ok([
        'status' => 'success',
        'token' => _mCreateToken($user['id'], $user['username'], $user['role'], $user['full_name']),
        'user' => ['id'=>(int)$user['id'],'username'=>$user['username'],'full_name'=>$user['full_name'],'email'=>$user['email']??'','role'=>$user['role']]
    ]);
}

if ($path === 'auth/verify') {
    $u = _mGetAuth();
    if (!$u) _err('Invalid or expired token', 401);
    _ok(['status'=>'success','user'=>$u]);
}

if ($path === 'debug/ping') {
    _ok(['status'=>'success','message'=>'API is working','php'=>PHP_VERSION,'db'=>!$conn->connect_error,'time'=>date('c'),'method'=>$method]);
}

// ============================================================
// ALL BELOW REQUIRE AUTH
// ============================================================
$auth = _mGetAuth();
if (!$auth) _err('Authentication required', 401);
$userId = (int)$auth['uid'];
$userRole = $auth['rol'];

switch ($path) {

case 'data/dashboard':
    $data = []; $today = date('Y-m-d');
    $r = $conn->query("SELECT COUNT(*) as total,COALESCE(SUM(status='active'),0) as active,COALESCE(SUM(status='warning'),0) as warning,COALESCE(SUM(status='inactive'),0) as inactive,COALESCE(SUM(status='archived'),0) as archived,COALESCE(SUM(gender='male'),0) as male,COALESCE(SUM(gender='female'),0) as female FROM members");
    $data['members'] = $r ? $r->fetch_assoc() : [];
    try {
        $r = $conn->query("SELECT COUNT(DISTINCT member_id) as recorded,COALESCE(SUM(status='present'),0) as present_cnt,COALESCE(SUM(status='absent'),0) as absent_cnt,COALESCE(SUM(status='late'),0) as late_cnt FROM attendance WHERE attendance_date='$today'");
        $data['today_attendance'] = $r ? $r->fetch_assoc() : ['recorded'=>0,'present_cnt'=>0,'absent_cnt'=>0,'late_cnt'=>0];
    } catch (Exception $e) { $data['today_attendance'] = ['recorded'=>0,'present_cnt'=>0,'absent_cnt'=>0,'late_cnt'=>0]; }
    try { $r = $conn->query("SELECT COUNT(*) as cnt FROM classes WHERE is_active=1"); $data['classes_count'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0; } catch (Exception $e) { $data['classes_count']=0; }
    $r = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE is_active=1"); $data['users_count'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
    try { $r = $conn->query("SELECT year_name,year_gc FROM academic_years WHERE is_current=1 LIMIT 1"); $data['academic_year']=$r?$r->fetch_assoc():null; } catch(Exception $e){$data['academic_year']=null;}
    _ok(['status'=>'success','data'=>$data,'server_time'=>date('c')]);
    break;

case 'data/classes':
    $classes = [];
    try { $r=$conn->query("SELECT c.id,c.class_name,c.class_name_en,c.class_code,c.level_order,c.section,c.age_group,(SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id=c.id AND ce.status='active') as student_count FROM classes c WHERE c.is_active=1 ORDER BY c.level_order"); if($r) while($row=$r->fetch_assoc())$classes[]=$row; } catch(Exception $e){}
    if($userRole==='teacher'){try{$a=[];$st=$conn->prepare("SELECT DISTINCT class_id FROM teacher_assignments WHERE teacher_id=? AND status='active'");if($st){$st->bind_param('i',$userId);$st->execute();$ar=$st->get_result();while($row=$ar->fetch_assoc())$a[]=(int)$row['class_id'];$st->close();$classes=array_values(array_filter($classes,fn($c)=>in_array((int)$c['id'],$a)));}}catch(Exception $e){}}
    _ok(['status'=>'success','classes'=>$classes,'updated_at'=>date('c')]);
    break;

case 'data/members':
    $page=max(1,(int)($_GET['page']??1));$limit=min(200,max(10,(int)($_GET['limit']??50)));$offset=($page-1)*$limit;$search=$_GET['search']??'';$sf=$_GET['status']??'';
    $where="WHERE status!='archived'";$params=[];$types='';
    if($search){$where.=" AND (student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR phone_number LIKE ?)";$s="%$search%";$params=[$s,$s,$s,$s];$types='ssss';}
    if($sf&&in_array($sf,['active','warning','inactive'])){$where.=" AND status=?";$params[]=$sf;$types.='s';}
    $stmt=$conn->prepare("SELECT COUNT(*) as total FROM members $where");if($types)$stmt->bind_param($types,...$params);$stmt->execute();$total=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
    $stmt=$conn->prepare("SELECT id,member_code,student_name,father_name,grandfather_name,gender,age_group,status,member_type,phone_number,created_at FROM members $where ORDER BY id DESC LIMIT $limit OFFSET $offset");if($types)$stmt->bind_param($types,...$params);$stmt->execute();$members=[];$r=$stmt->get_result();while($row=$r->fetch_assoc())$members[]=$row;$stmt->close();
    _ok(['status'=>'success','members'=>$members,'total'=>$total,'page'=>$page,'pages'=>ceil($total/max($limit,1))]);
    break;

case 'data/class_students':
    $classId=(int)($_GET['class_id']??0);if(!$classId)_err('class_id required');
    $yf="";$yfParam=null;try{$r=$conn->query("SELECT id FROM academic_years WHERE is_current=1 LIMIT 1");if($r&&$yr=$r->fetch_assoc()){$yfParam=(int)$yr['id'];$yf=" AND ce.academic_year_id=?";}}catch(Exception $e){}
    $students=[];try{if($yfParam){$stmt=$conn->prepare("SELECT m.id,m.member_code,m.student_name,m.father_name,m.gender,m.age_group,m.phone_number FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.class_id=? AND ce.status='active'".$yf." ORDER BY m.student_name");$stmt->bind_param('ii',$classId,$yfParam);}else{$stmt=$conn->prepare("SELECT m.id,m.member_code,m.student_name,m.father_name,m.gender,m.age_group,m.phone_number FROM class_enrollments ce JOIN members m ON ce.member_id=m.id WHERE ce.class_id=? AND ce.status='active' ORDER BY m.student_name");$stmt->bind_param('i',$classId);}$stmt->execute();$r=$stmt->get_result();while($row=$r->fetch_assoc())$students[]=$row;$stmt->close();}catch(Exception $e){}
    _ok(['status'=>'success','students'=>$students,'class_id'=>$classId]);
    break;

case 'attendance/get':
    $classId=(int)($_GET['class_id']??0);$date=$_GET['date']??date('Y-m-d');if(!$classId)_err('class_id required');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))_err('Invalid date');
    $yf="";try{$r=$conn->query("SELECT id FROM academic_years WHERE is_current=1 LIMIT 1");if($r&&$yr=$r->fetch_assoc())$yf=" AND ce.academic_year_id=".(int)$yr['id'];}catch(Exception $e){}
    $students=[];try{$stmt=$conn->prepare("SELECT ce.member_id,m.student_name,m.father_name,m.member_code,m.gender,a.id as attendance_id,a.status as att_status,a.notes,a.check_in_time FROM class_enrollments ce JOIN members m ON ce.member_id=m.id LEFT JOIN attendance a ON a.member_id=ce.member_id AND a.attendance_date=? WHERE ce.class_id=? AND ce.status='active' $yf ORDER BY m.student_name");if($stmt){$stmt->bind_param('si',$date,$classId);$stmt->execute();$r=$stmt->get_result();while($row=$r->fetch_assoc())$students[]=$row;$stmt->close();}}catch(Exception $e){}
    $cls=['class_name'=>'','class_name_en'=>''];try{$stmt=$conn->prepare("SELECT class_name,class_name_en FROM classes WHERE id=?");if($stmt){$stmt->bind_param('i',$classId);$stmt->execute();$cls=$stmt->get_result()->fetch_assoc()?:$cls;$stmt->close();}}catch(Exception $e){}
    _ok(['status'=>'success','students'=>$students,'class'=>$cls,'date'=>$date]);
    break;

case 'attendance/save':
    if($method!=='POST')_err('POST required');$classId=(int)($input['class_id']??0);$date=$input['date']??date('Y-m-d');$records=$input['records']??[];
    if(!$classId||empty($records))_err('class_id and records required');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))_err('Invalid date');
    $yearId=null;try{$r=$conn->query("SELECT id FROM academic_years WHERE is_current=1 LIMIT 1");if($r&&$yr=$r->fetch_assoc())$yearId=(int)$yr['id'];}catch(Exception $e){}
    $stmt=$conn->prepare("DELETE FROM attendance WHERE class_id=? AND attendance_date=?");if($stmt){$stmt->bind_param('is',$classId,$date);$stmt->execute();$stmt->close();}
    $ins=$conn->prepare("INSERT INTO attendance (member_id,class_id,academic_year_id,attendance_date,status,notes,recorded_by) VALUES (?,?,?,?,?,?,?)");$saved=0;
    if(!$ins)_err('Database error: '.$conn->error,500);
    foreach($records as $rec){$mid=(int)($rec['member_id']??0);$st=$rec['status']??'present';$note=trim($rec['note']??$rec['notes']??'');if(!$mid||!in_array($st,['present','absent','late','excused']))continue;$ins->bind_param('iiisssi',$mid,$classId,$yearId,$date,$st,$note,$userId);try{$ins->execute();$saved++;}catch(Exception $e){}}
    $ins->close();_ok(['status'=>'success','message'=>"$saved records saved",'saved'=>$saved]);
    break;

case 'sync/push':
    if($method!=='POST')_err('POST required');$items=$input['items']??[];if(empty($items))_err('No items');
    $results=[];foreach($items as $item){$type=$item['type']??'';$data=$item['data']??[];$lid=$item['local_id']??'';
    try{if($type==='attendance'){$cid=(int)($data['class_id']??0);$dt=$data['date']??'';$recs=$data['records']??[];if($cid&&$dt&&!empty($recs)){$d=$conn->prepare("DELETE FROM attendance WHERE class_id=? AND attendance_date=?");$d->bind_param('is',$cid,$dt);$d->execute();$d->close();$yid=null;try{$r=$conn->query("SELECT id FROM academic_years WHERE is_current=1 LIMIT 1");if($r&&$yr=$r->fetch_assoc())$yid=(int)$yr['id'];}catch(Exception $e){}$ins=$conn->prepare("INSERT INTO attendance (member_id,class_id,academic_year_id,attendance_date,status,notes,recorded_by) VALUES (?,?,?,?,?,?,?)");$c=0;foreach($recs as $rec){$mid=(int)($rec['member_id']??0);$st=$rec['status']??'present';$n=trim($rec['note']??'');if(!$mid)continue;$ins->bind_param('iiisssi',$mid,$cid,$yid,$dt,$st,$n,$userId);$ins->execute();$c++;}$ins->close();$results[]=['local_id'=>$lid,'status'=>'success','saved'=>$c];}else $results[]=['local_id'=>$lid,'status'=>'error','message'=>'Missing data'];}else $results[]=['local_id'=>$lid,'status'=>'error','message'=>'Unknown type'];}catch(Exception $e){$results[]=['local_id'=>$lid,'status'=>'error','message'=>'Error'];}}
    _ok(['status'=>'success','results'=>$results]);
    break;

case 'sync/pull':
    $since=$_GET['since']??'';$data=[];
    if($since && !preg_match('/^\d{4}-\d{2}-\d{2}/', $since)) { $since = ''; }
    if($since){$stmt=$conn->prepare("SELECT id,member_code,student_name,father_name,gender,status,age_group FROM members WHERE status!='archived' AND updated_at>=? LIMIT 500");$stmt->bind_param('s',$since);$stmt->execute();$r=$stmt->get_result();$stmt->close();}else{$r=$conn->query("SELECT id,member_code,student_name,father_name,gender,status,age_group FROM members WHERE status!='archived' LIMIT 500");}
    $data['members']=[];if($r)while($row=$r->fetch_assoc())$data['members'][]=$row;
    try{$r=$conn->query("SELECT id,class_name,class_name_en,class_code,level_order,section,age_group FROM classes WHERE is_active=1 ORDER BY level_order");$data['classes']=[];if($r)while($row=$r->fetch_assoc())$data['classes'][]=$row;}catch(Exception $e){$data['classes']=[];}
    _ok(['status'=>'success','data'=>$data,'server_time'=>date('c')]);
    break;

case 'profile/get':
    $stmt=$conn->prepare("SELECT id,username,email,full_name,role,created_at,last_login FROM users WHERE id=?");$stmt->bind_param('i',$userId);$stmt->execute();$user=$stmt->get_result()->fetch_assoc();$stmt->close();
    _ok(['status'=>'success','user'=>$user]);
    break;

case 'profile/update':
    if($method!=='POST')_err('POST required');$name=trim($input['full_name']??'');$email=trim($input['email']??'');if(empty($name))_err('Name required');
    $stmt=$conn->prepare("UPDATE users SET full_name=?,email=? WHERE id=?");$e=$email?:null;$stmt->bind_param('ssi',$name,$e,$userId);$stmt->execute();$stmt->close();
    _ok(['status'=>'success','message'=>'Profile updated']);
    break;

default: _err('Unknown endpoint: '.$path, 404);
}

if(isset($conn)&&$conn instanceof mysqli&&!$conn->connect_error)$conn->close();
