<?php
/**
 * P74 — API v1 Communication parity, end-to-end.
 *
 * Exercises the REAL api/v1 (router + middleware + JWT auth + rate
 * limiter + NotificationCenterService) against a REAL MariaDB with
 * static PHP 8.3 — the class of verification static analysis cannot
 * do, for the surface the Flutter app actually consumes.
 *
 * Usage:  php tests/e2e/comm_v1_lifecycle.php <scenario>
 *   parity  — P74 surface: cursor pages, thread window, edit/delete,
 *             thread-read, permission denials
 *   etag304 — conditional GETs: 304 + empty body + zero writes;
 *             mutation invalidation; non-participant never 304s
 *   legacy  — installed-build compatibility: old params/fields intact
 *   unauth  — bad / wrong-type tokens fail closed (401)
 *
 * ARCHITECTURE: the v1 core exit()s after every response (apiSendJson),
 * so every request runs as its OWN child process. The parent mints
 * access tokens with the test JWT_SECRET (same HMAC scheme as
 * api/v1/core/auth.php) and parses "body + ==HTTP==<code>" from the
 * child's stdout.
 *
 * Requires tests/e2e/comm_e2e_setup.sh-style env (.fkss_env.php at the
 * repo root). NEVER point this at a production database — the runner
 * DROPs and re-CREATEs the communication tables on every scenario.
 */

$ROOT = dirname(__DIR__, 2);
require $ROOT . '/.fkss_env.php';

/* ── CHILD MODE — must come before any parent function definitions
 *    (api/v1/core/response.php declares check()/err() too). ─────────── */
if (getenv('COMM_V1_CHILD') !== false) {
    $cfg = json_decode((string)getenv('COMM_V1_CHILD'), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = (string)($cfg['method'] ?? 'GET');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['SCRIPT_NAME'] = '/api/v1/index.php';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)($cfg['token'] ?? '');
    if (!empty($cfg['inm'])) { $_SERVER['HTTP_IF_NONE_MATCH'] = (string)$cfg['inm']; }
    // php://input is empty in this child (stdin closed) → getBody()
    // falls back to $_POST, which is exactly what we seed.
    $_GET = array_merge(['_route' => (string)($cfg['route'] ?? '')], $cfg['get'] ?? []);
    $_POST = $cfg['post'] ?? [];
    register_shutdown_function(static function (): void {
        // apiSendJson has already echoed the body (or the 304 path
        // echoed nothing); append the status-code marker for the parent.
        fwrite(STDOUT, "\n==HTTP==" . http_response_code());
    });
    require $ROOT . '/api/v1/index.php';
    exit(0);
}

/* ── PARENT — orchestration ───────────────────────────────────────── */
require_once $ROOT . '/admin/backend/services/NotificationCenterService.php';

$SCENARIO = $argv[1] ?? '';
$passed = 0; $failed = 0;
function check(bool $cond, string $name, string $why = ''): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "E2E-PASS: $name\n"; }
    else { $failed++; echo "E2E-FAIL: $name" . ($why !== '' ? " — $why" : '') . "\n"; }
}
function verdict(): void {
    global $passed, $failed, $SCENARIO;
    echo "E2E-VERDICT: " . ($failed === 0 ? 'PASS' : 'FAIL') . " ($SCENARIO: $passed checks)\n";
    exit($failed === 0 ? 0 : 1);
}
function db(): mysqli {
    static $c = null;
    if ($c === null) {
        $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $c->set_charset('utf8mb4');
    }
    return $c;
}
function runSqlFile(string $path): void {
    $sql = file_get_contents($path);
    if (!db()->multi_query($sql)) {
        throw new RuntimeException("SQL file failed: $path — " . db()->error);
    }
    do {
        if ($res = db()->store_result()) { $res->free(); }
        if (!db()->more_results()) break;
        if (!db()->next_result()) {
            throw new RuntimeException("multi-statement failed: $path — " . db()->error);
        }
    } while (true);
}
function resetSchema(array $migrations): void {
    global $ROOT;
    $c = db();
    foreach (['message_threads', 'message_thread_participants', 'messages', 'notification_reads'] as $t) {
        $c->query("DROP TABLE IF EXISTS `$t`");
    }
    $c->query("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        full_name VARCHAR(200) NOT NULL,
        role VARCHAR(50) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        password VARCHAR(255) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'active',
        email VARCHAR(200) DEFAULT NULL,
        department VARCHAR(100) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        data JSON DEFAULT NULL,
        priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
        source_dept VARCHAR(50) DEFAULT NULL,
        source_user_id INT UNSIGNED DEFAULT NULL,
        target_roles VARCHAR(255) DEFAULT NULL,
        target_user_id INT UNSIGNED DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c->query("CREATE TABLE IF NOT EXISTS department_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        to_dept VARCHAR(50) DEFAULT NULL,
        to_user_id INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c->query("TRUNCATE users"); $c->query("TRUNCATE notifications"); $c->query("TRUNCATE department_tasks");
    foreach ([
        [1, 'e2e1', 'Super Admin', 'super_admin'],
        [2, 'e2e2', 'Test Teacher', 'teacher'],
        [3, 'e2e3', 'Edu Officer', 'edu_dept'],
        [4, 'e2e4', 'Finance Officer', 'finance_dept'],
    ] as $u) {
        $stmt = $c->prepare("INSERT INTO users (id, username, full_name, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isss', $u[0], $u[1], $u[2], $u[3]);
        $stmt->execute(); $stmt->close();
    }
    runSqlFile($ROOT . '/sql/042_notification_center.sql');
    foreach ($migrations as $mig) { runSqlFile($ROOT . '/sql/' . $mig); }
    // the v1 middleware rate limiter is DB-backed when the table exists:
    // apply 008 and reset its buckets so scenarios are repeatable
    runSqlFile($ROOT . '/sql/008_security_rate_limits.sql');
    $c->query("TRUNCATE security_rate_limits");
}

/** Mint an access token with the same scheme as api/v1/core/auth.php. */
function mintToken(int $uid, string $role, string $typ = 'access'): string {
    $payload = [
        'uid' => $uid, 'usr' => 'e2e', 'rol' => $role, 'nam' => 'E2E User',
        'iat' => time(), 'exp' => time() + 900, 'typ' => $typ,
        'jti' => bin2hex(random_bytes(8)),
    ];
    $b64 = base64_encode(json_encode($payload));
    return $b64 . '.' . hash_hmac('sha256', $b64, JWT_SECRET);
}

/** One request = one child process (the v1 core exit()s per response).
 *  Returns [decoded, rawBody, httpCode]. */
function request(int $uid, string $role, string $method, string $route, array $get = [], array $post = [], ?string $inm = null, ?string $tokenOverride = null): array {
    $spec = json_encode([
        'method' => $method, 'route' => $route,
        'token' => $tokenOverride ?? mintToken($uid, $role),
        'get' => $get, 'post' => $post, 'inm' => $inm,
    ], JSON_UNESCAPED_SLASHES);
    putenv('COMM_V1_CHILD=' . $spec);
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child 2>>' . escapeshellarg(sys_get_temp_dir() . '/comm_v1_stderr.log');
    $out = (string)shell_exec($cmd);
    putenv('COMM_V1_CHILD');
    $code = 0;
    $body = $out;
    $mpos = strpos($out, "\n==HTTP==");
    if ($mpos !== false) {
        $body = substr($out, 0, $mpos);
        if (preg_match('/==HTTP==(\d+)\s*$/', $out, $m2)) { $code = (int)$m2[1]; }
    }
    return [json_decode($body, true), $body, $code];
}

switch ($SCENARIO) {
    case 'parity': {
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        // 30 notifications targeted at the teacher
        $ins = db()->prepare("INSERT INTO notifications (type, title, message, priority, target_roles, source_user_id) VALUES ('member', ?, ?, 'normal', 'teacher', 1)");
        for ($i = 1; $i <= 30; $i++) { $t = "Alert no $i"; $m = "body $i"; $ins->bind_param('ss', $t, $m); $ins->execute(); }
        $ins->close();

        // feed cursor pages tile exactly
        [$p1, , $c1] = request(2, 'teacher', 'GET', 'notifications/feed', ['limit' => 10]);
        $ids1 = array_map(static fn($r) => (int)$r['id'], $p1['data']['rows'] ?? []);
        check($c1 === 200 && count($ids1) === 10 && ($p1['data']['has_more'] ?? false) === true
            && (int)($p1['data']['next_before'] ?? 0) === min($ids1),
            'parity: feed page 1 = 10 rows + exact cursor (v1 envelope)');
        [$p2] = request(2, 'teacher', 'GET', 'notifications/feed', ['limit' => 10, 'before_id' => (string)$p1['data']['next_before']]);
        $ids2 = array_map(static fn($r) => (int)$r['id'], $p2['data']['rows'] ?? []);
        [$p3] = request(2, 'teacher', 'GET', 'notifications/feed', ['limit' => 10, 'before_id' => (string)$p2['data']['next_before']]);
        $ids3 = array_map(static fn($r) => (int)$r['id'], $p3['data']['rows'] ?? []);
        $all = array_merge($ids1, $ids2, $ids3);
        check(count($ids3) === 10 && ($p3['data']['has_more'] ?? true) === false
            && count($all) === 30 && count(array_unique($all)) === 30,
            'parity: feed cursor pages tile exactly 30 (no gap, no duplicate)');

        // thread with 260 messages → newest-200 window + older page
        [$r] = request(1, 'super_admin', 'POST', 'notifications/thread-start', [], ['to' => [2], 'subject' => 'E2E pages', 'body' => 'm0']);
        $tid = (int)($r['data']['id'] ?? 0);
        check($tid > 0 && ($r['status'] ?? '') === 'success', 'parity: thread-start via v1 (JWT user 1)');
        $batch = '';
        for ($i = 1; $i <= 259; $i++) { $batch .= "($tid, 2, 'bulk $i'),"; }
        db()->query('INSERT INTO messages (thread_id, sender_id, body) VALUES ' . rtrim($batch, ','));
        [$t1] = request(2, 'teacher', 'GET', 'notifications/thread', ['id' => (string)$tid]);
        $mids = array_map(static fn($m) => (int)$m['id'], $t1['data']['messages'] ?? []);
        check(count($mids) === 200 && max($mids) === 260 && min($mids) === 61,
            'parity: thread ships the NEWEST 200 (ids 61..260) via v1');
        check(($t1['data']['has_older'] ?? false) === true && (int)($t1['data']['oldest_id'] ?? 0) === 61
            && (int)($t1['data']['read_watermark'] ?? -1) >= 0,
            'parity: window metadata (has_older, oldest_id, read_watermark) present');
        [$t2] = request(2, 'teacher', 'GET', 'notifications/thread', ['id' => (string)$tid, 'before_id' => '61']);
        $mids2 = array_map(static fn($m) => (int)$m['id'], $t2['data']['messages'] ?? []);
        check(count($mids2) === 60 && max($mids2) === 60 && min($mids2) === 1 && ($t2['data']['has_older'] ?? true) === false,
            'parity: older page = exactly the remaining 60 via before_id');

        // conversation list tuple cursor (13 threads → 5/5/3)
        for ($i = 1; $i <= 12; $i++) {
            db()->query("INSERT INTO message_threads (subject, created_by, last_message_at) VALUES ('bulk $i', 1, DATE_SUB(NOW(), INTERVAL $i MINUTE))");
            $ntid = (int)db()->insert_id;
            db()->query("INSERT INTO message_thread_participants (thread_id, user_id) VALUES ($ntid, 1), ($ntid, 2)");
            db()->query("INSERT INTO messages (thread_id, sender_id, body) VALUES ($ntid, 1, 'hello $i')");
        }
        [$th1] = request(2, 'teacher', 'GET', 'notifications/threads', ['limit' => 5]);
        $tids1 = array_map(static fn($x) => (int)$x['id'], $th1['data']['threads'] ?? []);
        check(count($tids1) === 5 && ($th1['data']['has_more'] ?? false) === true && is_array($th1['data']['next'] ?? null),
            'parity: threads page 1 = 5 rows + tuple cursor');
        [$th2] = request(2, 'teacher', 'GET', 'notifications/threads', ['limit' => 5, 'before_lm' => (string)$th1['data']['next'][0], 'before_id' => (string)$th1['data']['next'][1]]);
        [$th3] = request(2, 'teacher', 'GET', 'notifications/threads', ['limit' => 5, 'before_lm' => (string)$th2['data']['next'][0], 'before_id' => (string)$th2['data']['next'][1]]);
        $allT = array_merge($tids1, array_map(static fn($x) => (int)$x['id'], $th2['data']['threads'] ?? []), array_map(static fn($x) => (int)$x['id'], $th3['data']['threads'] ?? []));
        check(count($allT) === 13 && count(array_unique($allT)) === 13 && ($th3['data']['has_more'] ?? true) === false,
            'parity: threads pages tile exactly 13 (5+5+3) via (before_lm, before_id)');

        // message-edit: own ✓, other's ✗
        [$r2, , $c2] = request(2, 'teacher', 'POST', 'notifications/message-edit', [], ['message_id' => 100, 'body' => 'edited via v1']);
        check($c2 === 200 && ($r2['status'] ?? '') === 'success', 'parity: own message edited via v1');
        [$r3, , $c3] = request(1, 'super_admin', 'POST', 'notifications/message-edit', [], ['message_id' => 100, 'body' => 'hijack']);
        check($c3 !== 200 || ($r3['status'] ?? '') === 'error', 'parity: editing ANOTHER user\'s message denied (ownership in service)');

        // message-delete: own → thread shows the tombstone, never the body
        [$r4, , $c4] = request(2, 'teacher', 'POST', 'notifications/message-delete', [], ['message_id' => 101]);
        check($c4 === 200, 'parity: own message deleted via v1');
        [$t3] = request(2, 'teacher', 'GET', 'notifications/thread', ['id' => (string)$tid]);
        $del = null;
        foreach (($t3['data']['messages'] ?? []) as $m) { if ((int)$m['id'] === 101) { $del = $m; } }
        check($del !== null && (int)($del['deleted'] ?? 0) === 1 && strpos((string)($del['body'] ?? ''), 'bulk') === false,
            'parity: deleted message returns the tombstone flag, body never returned');

        // thread-read: explicit mark without refetch
        [$r5, , $c5] = request(2, 'teacher', 'POST', 'notifications/thread-read', [], ['id' => $tid]);
        check($c5 === 200 && ($r5['status'] ?? '') === 'success', 'parity: explicit thread-read via v1');
        [$r6, , $c6] = request(3, 'edu_dept', 'POST', 'notifications/thread-read', [], ['id' => $tid]);
        check($c6 === 404, 'parity: thread-read on a non-participant thread → 404');

        // permission matrix is server-side: teacher cannot start a thread with finance_dept
        [$r7, , $c7] = request(2, 'teacher', 'POST', 'notifications/thread-start', [], ['to' => [4], 'subject' => 'x', 'body' => 'y']);
        check($c7 === 400 && strpos((string)($r7['message'] ?? ''), 'cannot message') !== false,
            'parity: teacher→finance_dept thread-start denied (role matrix)');
        verdict();
    }

    case 'etag304': {
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        [$r] = request(1, 'super_admin', 'POST', 'notifications/thread-start', [], ['to' => [2], 'subject' => 'E2E etag', 'body' => 'first']);
        $tid = (int)($r['data']['id'] ?? 0);
        check($tid > 0, 'etag304: thread seeded via v1');

        // summary: full response, then idle revalidation → 304 + EMPTY body
        [$s1, $raw1, $c1] = request(2, 'teacher', 'GET', 'notifications/summary');
        check($c1 === 200 && is_array($s1['data']['summary'] ?? null), 'etag304: summary full response (v1 envelope)');
        $etag = '"ncsum-' . md5(\App\Services\NotificationCenterService::summaryVersion(db(), 2, 'teacher')) . '"';
        [$s2, $raw2, $c2] = request(2, 'teacher', 'GET', 'notifications/summary', [], [], $etag);
        check($c2 === 304 && trim($raw2) === '', 'etag304: idle summary poll → 304 with EMPTY body');
        [, $rawB, $cB] = request(2, 'teacher', 'GET', 'notifications/summary', [], [], '"ncsum-' . md5('stale') . '"');
        check($cB === 200 && trim($rawB) !== '', 'etag304: wrong If-None-Match → full response');

        // a mutation breaks the etag
        request(1, 'super_admin', 'POST', 'notifications/send-message', [], ['thread_id' => $tid, 'body' => 'changed!']);
        [$s4, , $c4] = request(2, 'teacher', 'GET', 'notifications/summary', [], [], $etag);
        check($c4 === 200 && (int)($s4['data']['summary']['messages'] ?? -1) >= 1, 'etag304: after a mutation the old etag no longer matches');

        // thread: idle poll → 304 + zero writes (backdated read-state probe)
        [$t1] = request(2, 'teacher', 'GET', 'notifications/thread', ['id' => (string)$tid]);
        check(count($t1['data']['messages'] ?? []) === 2, 'etag304: thread opened (full window)');
        $threadEtag = '"ncthr-' . md5(\App\Services\NotificationCenterService::threadVersion(db(), 2, $tid) . '|w0') . '"';
        db()->query("UPDATE notification_reads SET read_at = '2020-01-01 00:00:00' WHERE user_id = 2 AND subject_type = 'message_thread'");
        [$t2, $rawT2, $cT2] = request(2, 'teacher', 'GET', 'notifications/thread', ['id' => (string)$tid], [], $threadEtag);
        check($cT2 === 304 && trim($rawT2) === '', 'etag304: idle thread poll → 304 with EMPTY body');
        $readAt = (string)(db()->query("SELECT read_at FROM notification_reads WHERE user_id = 2 AND subject_type = 'message_thread' LIMIT 1")->fetch_assoc()['read_at'] ?? '');
        check(strpos($readAt, '2020-01-01') === 0, 'etag304: a 304 poll performs ZERO writes (read state untouched)');

        // new message → version moves → full window with the new message
        request(1, 'super_admin', 'POST', 'notifications/send-message', [], ['thread_id' => $tid, 'body' => 'new one']);
        [$t3, , $cT3] = request(2, 'teacher', 'GET', 'notifications/thread', ['id' => (string)$tid], [], $threadEtag);
        check($cT3 === 200 && count($t3['data']['messages'] ?? []) === 3, 'etag304: new message breaks the etag (full response)');

        // non-participants NEVER get a 304 oracle
        [, , $cNP] = request(3, 'edu_dept', 'GET', 'notifications/thread', ['id' => (string)$tid], [], $threadEtag);
        check($cNP === 404, 'etag304: non-participant with a GUESSED etag → 404, never 304');
        verdict();
    }

    case 'legacy': {
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        // installed builds (P72) must keep working: same params, same
        // fields — P74 only ADDS optional params and extra fields.
        $ins = db()->prepare("INSERT INTO notifications (type, title, message, priority, target_roles, source_user_id) VALUES ('member', ?, ?, 'normal', 'teacher', 1)");
        for ($i = 1; $i <= 12; $i++) { $t = "Alert no $i"; $m = "body $i"; $ins->bind_param('ss', $t, $m); $ins->execute(); }
        $ins->close();
        [$f1, , $c1] = request(2, 'teacher', 'GET', 'notifications/feed', ['limit' => 5]);
        check($c1 === 200 && count($f1['data']['rows'] ?? []) === 5 && (int)($f1['data']['total'] ?? 0) === 12,
            'legacy: feed (offset path, no cursor params) unchanged');
        [$f2] = request(2, 'teacher', 'GET', 'notifications/feed', ['limit' => 5, 'offset' => 5]);
        $ids2 = array_map(static fn($r) => (int)$r['id'], $f2['data']['rows'] ?? []);
        check(count($ids2) === 5 && max($ids2) < (int)$f1['data']['rows'][0]['id'],
            'legacy: offset paging still works (installed-build path)');
        [$s1] = request(2, 'teacher', 'GET', 'notifications/summary');
        check(isset($s1['data']['summary']['alerts'], $s1['data']['summary']['announcements'], $s1['data']['summary']['can_message']),
            'legacy: summary shape intact (counts + permissions)');
        [$a1] = request(2, 'teacher', 'GET', 'notifications/announcements');
        check(is_array($a1['data']['announcements'] ?? null) && array_key_exists('has_more', $a1['data']),
            'legacy: announcements keeps its field; has_more is additive');
        [$r] = request(1, 'super_admin', 'POST', 'notifications/thread-start', [], ['to' => [2], 'subject' => 'legacy', 'body' => 'hi']);
        $tid = (int)($r['data']['id'] ?? 0);
        [$th1] = request(2, 'teacher', 'GET', 'notifications/threads');
        check(count($th1['data']['threads'] ?? []) === 1 && isset($th1['data']['threads'][0]['subject'], $th1['data']['threads'][0]['unread_count']),
            'legacy: threads list fields intact');
        [$t1] = request(2, 'teacher', 'GET', 'notifications/thread', ['id' => (string)$tid]);
        check(count($t1['data']['messages'] ?? []) === 1 && isset($t1['data']['messages'][0]['body'], $t1['data']['messages'][0]['mine']),
            'legacy: thread response fields intact');
        verdict();
    }

    case 'unauth': {
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        [$r1, $raw1, $c1] = request(2, 'teacher', 'GET', 'notifications/summary', [], [], null, 'forged.payload');
        check($c1 === 401 && ($r1['status'] ?? '') === 'error', 'unauth: forged token → 401');
        $refresh = mintToken(2, 'teacher', 'refresh');
        [$r2, , $c2] = request(2, 'teacher', 'GET', 'notifications/summary', [], [], null, $refresh);
        check($c2 === 401, 'unauth: refresh token cannot read (access tokens only)');
        [$r3, $raw3, $c3] = request(2, 'teacher', 'GET', 'notifications/summary', [], [], null, '');
        check($c3 === 401 && strpos($raw3, 'Bearer') !== false, 'unauth: missing token → 401 with the Bearer hint');
        verdict();
    }

    default: fwrite(STDERR, "unknown scenario: $SCENARIO\n"); exit(2);
}
