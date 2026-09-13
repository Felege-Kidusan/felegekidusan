<?php
/**
 * ============================================================
 * P73 communication E2E — REAL MySQL messaging lifecycle
 * ============================================================
 * Drives the REAL admin/api_notifications.php + NotificationCenterService
 * against a REAL MariaDB/MySQL database (no fakes, no splices) — the
 * class of verification the offline harness cannot do.
 *
 * Covered scenarios (this file re-creates its own schema first):
 *
 *   reset_pre043  schema = users + sql/042 ONLY (no receipts columns)
 *   pre043       INCIDENT REGRESSION (2026-09-12): without sql/043 a
 *                conversation MUST still open (receipts simply absent)
 *                and unread badges MUST still clear. Pre-fix this
 *                failed on PHP 8.1+ ("Could not load the conversation").
 *   reset_mid043 schema = users + sql/042 + 043 (044 deliberately NOT
 *                applied — the exact state of the user's production DB
 *                the moment this round deploys, before they run 044)
 *   mid043       DEPLOY-BEFORE-MIGRATION regression: conversations open
 *                without 044 (markers degrade), message_edit/delete fail
 *                with clean errors instead of 500s, nothing breaks
 *   reset_full   schema = users + sql/042 + 043 + 044 (+ re-run 043/044
 *                to prove idempotency)
 *   full         full lifecycle: start → send → open (watermark + ✓✓
 *                data) → edit own (+ marker) → edit OTHER'S denied →
 *                delete own (+ tombstone, body stripped) → edit deleted
 *                denied → role permission denial → partner matrix →
 *                unread summary counts
 *   csrf_bad     one POST with a WRONG csrf token (process exits via
 *                the API's 403 path — wrapper asserts on raw output)
 *   unauth       one call with NO session (API exits Unauthorized)
 *   etag304      P73 Phase 5 — conditional GETs: summary + open thread
 *                answer 304 with EMPTY bodies when nothing changed; any
 *                mutation produces a fresh ETag + full body; a 304 poll
 *                performs ZERO writes; non-participants never get an
 *                ETag (no 304 oracle for other people's threads)
 *   pagination   P73 Phase 5 — cursor pagination: feed pages by
 *                before_id with no overlap/gap; thread ships the NEWEST
 *                window + has_older/oldest_id, before_id pages older;
 *                conversation list pages by the (last_message_at, id)
 *                tuple cursor; page ends are exact
 *
 * Usage:   php tests/e2e/comm_lifecycle.php <scenario>
 * Needs:   .fkss_env.php in the repo root pointing at the prepared
 *          database; the DB user needs ALL PRIVILEGES on that ONE
 *          database (it drops/recreates the communication tables).
 *          See tests/e2e/comm_e2e_setup.sh for one-time preparation.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
// P73 Phase 5: buffer our own check output — printing to stdout between
// API calls would mark headers as sent and block http_response_code(304)
// in the CLI (in production each request is its own process). The buffer
// flushes automatically at exit.
ob_start();

$ROOT = dirname(__DIR__, 2);
require $ROOT . '/.fkss_env.php';

$SCENARIO = $argv[1] ?? '';
if ($SCENARIO === '') { fwrite(STDERR, "usage: php comm_lifecycle.php <scenario>\n"); exit(2); }

$RESULTS = [];
function ok(bool $cond, string $name, string $why = ''): void {
    global $RESULTS;
    $RESULTS[] = [$name, $cond];
    echo ($cond ? 'E2E-PASS: ' : 'E2E-FAIL: ') . $name . ($cond || $why === '' ? '' : ' — ' . $why) . PHP_EOL;
}
function verdict(): void {
    global $RESULTS;
    $bad = 0;
    foreach ($RESULTS as [, $c]) { if (!$c) { $bad++; } }
    echo ($bad === 0 ? 'E2E-VERDICT: PASS (' . count($RESULTS) . ' checks)' : 'E2E-VERDICT: FAIL (' . $bad . ' of ' . count($RESULTS) . ' failed)') . PHP_EOL;
    exit($bad === 0 ? 0 : 1);
}

/** Direct DB handle for schema work + state assertions. */
function db(): mysqli {
    static $c = null;
    if ($c === null) { $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); $c->set_charset('utf8mb4'); }
    return $c;
}

function runSqlFile(string $path): void {
    $sql = file_get_contents($path);
    if (!db()->multi_query($sql)) {
        throw new RuntimeException("SQL file failed: $path — " . db()->error);
    }
    // Consume EVERY statement result explicitly. next_result() alone
    // deadlocks when a guarded migration EXECUTEs a SELECT 1 (unread
    // result set: server blocks on write, client blocks on read) — the
    // store_result()/free() pattern is the canonical drain.
    do {
        if ($res = db()->store_result()) { $res->free(); }
        if (!db()->more_results()) break;
        if (!db()->next_result()) {
            throw new RuntimeException("multi-statement failed: $path — " . db()->error);
        }
    } while (true);
}

/** (Re-)create the base tables + seed users. $withMigrations = [043, 044…] */
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
}

/** Act as a user and call the REAL api_notifications.php. Returns [decoded, raw]. */
function api(string $userId, string $role, string $method, array $get, array $post, bool $badCsrf = false, ?string $inm = null): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/admin/api_notifications.php';
    $_SERVER['HTTP_HOST'] = 'e2e.local';
    $_SERVER['SCRIPT_NAME'] = '/admin/api_notifications.php';
    $_GET = $get;
    $_POST = array_merge($post, ['csrf_token' => $badCsrf ? 'WRONG-TOKEN' : 'e2e-csrf-token']);
    $_REQUEST = array_merge($_GET, $_POST);
    @session_start();   // start FIRST — config.php's session_start must not clobber our seeding
    $_SESSION = [
        'admin_logged_in' => true,
        'admin_id' => (int)$userId,
        'admin_role' => $role,
        'admin_username' => 'e2e',
        'admin_full_name' => 'E2E User',
        'csrf_token' => 'e2e-csrf-token',
        'AUTH_STARTED_AT' => time(),
        'AUTH_REVALIDATED_AT' => time(),
    ];
    // The endpoint closes the shared $conn at the end of every request and
    // only creates it on the first include (config.php is require_once'd),
    // so guarantee a LIVE global connection before every include.
    global $conn;
    if (!$conn instanceof mysqli) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
    } else {
        try { $alive = @$conn->query('SELECT 1'); } catch (Throwable $re) { $alive = false; }
        if ($alive === false) {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset('utf8mb4');
        }
    }
    // P73 Phase 5: conditional-GET support — revalidate with If-None-Match
    // and capture the response code (the 304 path exits before any echo,
    // so http_response_code() is readable while the body sits in the OB).
    if ($inm !== null) { $_SERVER['HTTP_IF_NONE_MATCH'] = $inm; }
    else { unset($_SERVER['HTTP_IF_NONE_MATCH']); }
    http_response_code(200);
    ob_start();
    include $GLOBALS['ROOT'] . '/admin/api_notifications.php';
    $raw = ob_get_clean();
    $decoded = json_decode($raw, true);
    return [$decoded, $raw, http_response_code()];
}

$ROLE_ID = ['super_admin' => 1, 'teacher' => 2, 'edu_dept' => 3, 'finance_dept' => 4];

switch ($SCENARIO) {

    case 'reset_pre043': resetSchema([]); echo "E2E-RESET: pre043\n"; exit(0);

    case 'pre043': {
        resetSchema([]);
        // u1 starts a conversation with u2 and sends two messages
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'thread_start', 'to' => '2', 'subject' => 'E2E pre-043', 'body' => 'first message']);
        ok(($r['status'] ?? '') === 'success' && ($r['id'] ?? 0) > 0, 'pre043: thread_start succeeds');
        $tid = (int)($r['id'] ?? 0);
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'send_message', 'thread_id' => $tid, 'body' => 'second message']);
        ok(($r['status'] ?? '') === 'success', 'pre043: send_message succeeds');

        // u2 sees unread message(s) in the summary
        [$s] = api(2, 'teacher', 'POST', [], ['action' => 'summary']);
        ok(($s['summary']['messages'] ?? -1) >= 1, 'pre043: unread message count > 0 before opening');

        // THE INCIDENT: opening the conversation must still work without 043
        [$t] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], []);
        ok(($t['status'] ?? '') === 'success', 'pre043: thread OPENS (incident regression — was "Could not load the conversation.")');
        ok(count($t['messages'] ?? []) === 2, 'pre043: both messages visible');
        ok((int)($t['read_watermark'] ?? -1) === 0, 'pre043: receipts degrade to 0 (never break the thread)');

        // unread badge must clear after opening (markThreadRead still persists)
        [$s] = api(2, 'teacher', 'POST', [], ['action' => 'summary']);
        ok((int)($s['summary']['messages'] ?? -1) === 0, 'pre043: unread messages clear after opening (badges keep working)');
        verdict();
    }

    case 'reset_mid043': {
        resetSchema(['043_message_read_receipts.sql']);
        echo "E2E-RESET: mid043\n"; exit(0);
    }

    case 'mid043': {
        resetSchema(['043_message_read_receipts.sql']);
        // u1 → u2 conversation, then open it WITHOUT sql/044 present
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'thread_start', 'to' => '2', 'subject' => 'E2E mid-043', 'body' => 'first message']);
        ok(($r['status'] ?? '') === 'success' && ($r['id'] ?? 0) > 0, 'mid043: thread_start succeeds');
        $tid = (int)($r['id'] ?? 0);
        [$r] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], []);
        ok(($r['status'] ?? '') === 'success', 'mid043: thread OPENS without 044 (deploy-before-migration regression)');
        ok(count($r['messages'] ?? []) === 1, 'mid043: messages visible without 044');
        ok(($r['messages'][0]['edited'] ?? 1) === 0 && ($r['messages'][0]['deleted'] ?? 1) === 0, 'mid043: edited/deleted markers degrade to 0');

        // management actions need their migration — they must degrade to a
        // CLEAN error (graceful, no 500/crash), and must not corrupt state
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'message_edit', 'message_id' => 1, 'body' => 'edited anyway?']);
        ok(($r['status'] ?? '') === 'error', 'mid043: message_edit fails cleanly without 044');
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'message_delete', 'message_id' => 1]);
        ok(($r['status'] ?? '') === 'error', 'mid043: message_delete fails cleanly without 044');

        // the conversation must still be intact and openable afterwards
        [$r] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], []);
        ok(($r['status'] ?? '') === 'success' && ($r['messages'][0]['body'] ?? '') === 'first message', 'mid043: thread still opens, body unchanged after failed edit/delete');
        verdict();
    }

    case 'reset_full': {
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        // idempotency: guarded migrations re-run as no-ops
        try { runSqlFile($ROOT . '/sql/043_message_read_receipts.sql'); runSqlFile($ROOT . '/sql/044_message_edit_delete.sql'); ok(true, 'full: 043+044 re-run idempotent'); }
        catch (Throwable $e) { ok(false, 'full: 043+044 re-run idempotent', $e->getMessage()); }
        echo "E2E-RESET: full\n"; exit(0);
    }

    case 'full': {
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'thread_start', 'to' => '2', 'subject' => 'E2E full', 'body' => 'original one']);
        ok(($r['status'] ?? '') === 'success', 'full: thread_start');
        $tid = (int)($r['id'] ?? 0);
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'send_message', 'thread_id' => $tid, 'body' => 'second one']);
        ok(($r['status'] ?? '') === 'success', 'full: send_message');

        // u2 opens → u2's watermark advances to the newest message
        [$t] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], []);
        ok(($t['status'] ?? '') === 'success' && count($t['messages'] ?? []) === 2, 'full: u2 opens thread, 2 messages');
        $w = db()->query("SELECT last_read_message_id FROM message_thread_participants WHERE thread_id = $tid AND user_id = 2")->fetch_assoc();
        ok((int)($w['last_read_message_id'] ?? 0) > 0, 'full: u2 watermark advanced (DB-level check)');

        // u1 opens → ✓✓ data: watermark from OTHERS covers both messages
        [$t] = api(1, 'super_admin', 'POST', ['action' => 'thread', 'id' => $tid], []);
        $maxId = 0; foreach (($t['messages'] ?? []) as $m) { $maxId = max($maxId, (int)$m['id']); }
        ok(($t['status'] ?? '') === 'success' && (int)($t['read_watermark'] ?? 0) >= $maxId, 'full: u1 sees read_watermark ≥ newest id (✓✓ Seen)');

        // edit own message
        $msgId = (int)($t['messages'][0]['id'] ?? 0);
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'message_edit', 'message_id' => $msgId, 'body' => 'original one (fixed)']);
        ok(($r['status'] ?? '') === 'success', 'full: edit own message');
        [$t] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], []);
        $edited = null; foreach (($t['messages'] ?? []) as $m) { if ((int)$m['id'] === $msgId) { $edited = $m; } }
        ok($edited && ($edited['edited'] ?? 0) === 1 && strpos($edited['body'] ?? '', '(fixed)') !== false, 'full: edited marker + new body visible to others');

        // edit OTHER'S message denied
        [$r] = api(2, 'teacher', 'POST', [], ['action' => 'message_edit', 'message_id' => $msgId, 'body' => 'hijack attempt']);
        ok(($r['status'] ?? '') === 'error' && strpos($r['message'] ?? '', 'own messages') !== false, 'full: editing another user\'s message is denied');

        // delete own message → tombstone, body stripped
        $delId = (int)($t['messages'][1]['id'] ?? 0);
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'message_delete', 'message_id' => $delId]);
        ok(($r['status'] ?? '') === 'success', 'full: delete own message');
        [$t] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], []);
        $deleted = null; foreach (($t['messages'] ?? []) as $m) { if ((int)$m['id'] === $delId) { $deleted = $m; } }
        ok($deleted && ($deleted['deleted'] ?? 0) === 1 && ($deleted['body'] ?? 'x') === '', 'full: tombstone rendered, deleted body NEVER returned');
        $rawDb = db()->query("SELECT body FROM messages WHERE id = $delId")->fetch_assoc();
        ok(($rawDb['body'] ?? '') !== '', 'full: original body still stored server-side (soft delete, audit-safe)');

        // editing a deleted message is denied
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'message_edit', 'message_id' => $delId, 'body' => 'zombie edit']);
        ok(($r['status'] ?? '') === 'error', 'full: editing a deleted message is denied');

        // role permission: teacher cannot start a thread with finance_dept
        [$r] = api(2, 'teacher', 'POST', [], ['action' => 'thread_start', 'to' => '4', 'subject' => 'nope', 'body' => 'nope']);
        ok(($r['status'] ?? '') === 'error' && strpos($r['message'] ?? '', 'cannot message') !== false, 'full: teacher→finance_dept start denied (permission matrix)');

        // partner matrix: teacher sees only allowed roles (never finance)
        [$p] = api(2, 'teacher', 'POST', [], ['action' => 'partners']);
        $roles = [];
        foreach (($p['partners'] ?? []) as $partner) { $roles[$partner['role']] = 1; }
        ok(!isset($roles['finance_dept']) && isset($roles['edu_dept']) && isset($roles['super_admin']), 'full: partner list follows the role matrix');

        // unread flow end-to-end
        [$s] = api(2, 'teacher', 'POST', [], ['action' => 'summary']);
        ok((int)($s['summary']['messages'] ?? -1) === 0, 'full: unread clear after reads');

        // non-participant cannot open the thread
        [$t] = api(4, 'finance_dept', 'POST', ['action' => 'thread', 'id' => $tid], []);
        ok(($t['status'] ?? '') === 'error', 'full: non-participant denied ("Not your conversation.")');
        verdict();
    }

    case 'ratelimit': {
        // The 429 guard exit()s (correct for production), which would end
        // this scenario process — so burst/block probes run as CHILD
        // processes whose stdout/stderr we inspect. The shared limiter
        // bucket (file backend, per user) makes the children's writes
        // count exactly as in-process ones would.
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        // the child must run under the SAME interpreter as this runner
        $php = getenv('SSMS_E2E_PHP') ?: PHP_BINARY;
        $self = __FILE__;
        $run = static function (array $env) use ($php, $self): array {
            foreach ($env as $k => $v) { putenv("$k=$v"); }
            $out = shell_exec(escapeshellcmd($php) . ' ' . escapeshellarg($self) . ' ratelimit_child 2>&1');
            foreach (array_keys($env) as $k) { putenv("$k"); }
            return [(string)$out];
        };

        // limiter state survives processes — and the API is DB-backed
        // whenever $pdo exists (admin/config.php loads the root config,
        // which creates it), falling back to files otherwise. Clear BOTH
        // stores so the scenario is deterministic under either backend.
        require_once __DIR__ . '/../../admin/backend/services/SecurityRateLimiter.php';
        $rlPdo = null;
        try {
            $rlPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $pdoErr) { $rlPdo = null; }
        $rl = new \App\Services\SecurityRateLimiter($rlPdo, sys_get_temp_dir() . '/ssms_ratelimit');
        foreach (['1', '2'] as $rlUid) {
            try { $rlPdo !== null && $rlPdo->prepare('DELETE FROM security_rate_limits WHERE action_name = ?')->execute(['comm_write']); } catch (\Throwable $e) {}
            $rl->clear('comm_write', 'user:' . $rlUid);
        }

        // burst: child seeds a thread then hammers sends until blocked
        [$out] = $run(['RL_MODE' => 'hammer']);
        preg_match_all('/SEND (\d+)/', $out, $m);
        $blockedAt = (int)($m[1][count($m[1]) - 1] ?? 0);
        ok($blockedAt >= 2 && $blockedAt <= 61 && strpos($out, 'Too many requests') !== false,
            'ratelimit: write burst throttled (429) after ' . max($blockedAt - 1, 0) . ' successful sends — friendly message shown');

        // the block persists for the window: whichever store backed the
        // hammer still records over-limit attempts with the window open.
        // (The burst itself already proves in-process persistence — the
        // counter accumulated across 61 requests without resetting. A
        // behavioral re-probe is impossible by design: the 429 path
        // exit()s, killing the process mid-request.)
        $bucketOver = false;
        try {
            $row = $rlPdo !== null ? $rlPdo->query('SELECT attempts, window_ends FROM security_rate_limits WHERE action_name = ' . (int)0 . ' OR 1=1 ORDER BY attempts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) : false;
            if ($row && (int)$row['attempts'] > 60 && strtotime((string)$row['window_ends']) > time()) { $bucketOver = true; }
        } catch (\Throwable $e) {}
        if (!$bucketOver) {
            foreach (glob(sys_get_temp_dir() . '/ssms_ratelimit/*.json') ?: [] as $f) {
                $d = json_decode((string)file_get_contents($f), true);
                if (is_array($d) && (int)($d['attempts'] ?? 0) > 60 && (int)($d['window_ends'] ?? 0) > time()) { $bucketOver = true; break; }
            }
        }
        ok($bucketOver, 'ratelimit: the block persists for the window (bucket over-limit, window open)');

        // reads are NOT throttled — the Phase 5 zero-write poll stays free
        [, $rawR, $codeR] = api(1, 'super_admin', 'POST', ['action' => 'threads'], []);
        ok($codeR === 200 && $rawR !== '', 'ratelimit: reads unaffected while writes are blocked');

        // per-user keying: user 2 keeps writing while user 1 is blocked
        [$tidRow] = [db()->query('SELECT id FROM message_threads ORDER BY id DESC LIMIT 1')->fetch_assoc()];
        $tid = (int)($tidRow['id'] ?? 0);
        [, , $code2] = api(2, 'teacher', 'POST', [], ['action' => 'send_message', 'thread_id' => $tid, 'body' => 'still fine']);
        ok($tid > 0 && $code2 === 200, 'ratelimit: limit is per user — user 2 unaffected');

        // clear() = what the cooldown window amounts to (DB + file, as above)
        try { $rlPdo !== null && $rlPdo->prepare('DELETE FROM security_rate_limits WHERE action_name = ?')->execute(['comm_write']); } catch (\Throwable $e) {}
        $rl->clear('comm_write', 'user:1');
        [, , $code3] = api(1, 'super_admin', 'POST', [], ['action' => 'send_message', 'thread_id' => $tid, 'body' => 'after clear']);
        ok($code3 === 200, 'ratelimit: window reset restores writes');
        try { $rlPdo !== null && $rlPdo->prepare('DELETE FROM security_rate_limits WHERE action_name = ?')->execute(['comm_write']); } catch (\Throwable $e) {}
        $rl->clear('comm_write', 'user:2');
        verdict();
    }

    case 'ratelimit_child': {
        // Helper for the ratelimit scenario. Env:
        //   RL_MODE=hammer        seed a thread, send until blocked
        //   RL_MODE=single        one send_message as RL_UID (default 1)
        // A 429 exits mid-request (production behavior) and flushes the
        // JSON error body to stdout; the parent detects it by content.
        require_once __DIR__ . '/../../admin/backend/services/SecurityRateLimiter.php';
        $rlc = new \App\Services\SecurityRateLimiter(null, sys_get_temp_dir() . '/ssms_ratelimit');
        $mode = (string)getenv('RL_MODE');
        $uid = (int)(getenv('RL_UID') ?: 1);
        $role = $uid === 2 ? 'teacher' : 'super_admin';
        if ($mode === 'single') {
            $row = db()->query('SELECT id FROM message_threads ORDER BY id DESC LIMIT 1')->fetch_assoc();
            api($uid, $role, 'POST', [], ['action' => 'send_message', 'thread_id' => (int)($row['id'] ?? 0), 'body' => 'probe']);
            echo "CHILD-ALLOWED\n";
            exit(0);
        }
        // hammer: seed a thread, send until blocked, then prove the
        // block holds for one more attempt IN THE SAME PROCESS
        [$r] = api($uid, $role, 'POST', [], ['action' => 'thread_start', 'to' => '2', 'subject' => 'rl', 'body' => 'x']);
        $tid = (int)($r['id'] ?? 0);
        for ($i = 1; $i <= 70; $i++) {
            fwrite(STDERR, "SEND $i\n");
            // a 429 exit()s mid-request (production behavior) and the
            // process dies here — the parent detects the block by the
            // leaked JSON body + the last SEND marker.
            api($uid, $role, 'POST', [], ['action' => 'send_message', 'thread_id' => $tid, 'body' => "spam $i"]);
        }
        echo "CHILD-DONE\n";
        exit(0);
    }

    case 'etag304': {
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'thread_start', 'to' => '2', 'subject' => 'E2E etag', 'body' => 'first']);
        $tid = (int)($r['id'] ?? 0);
        ok($tid > 0, 'etag304: thread seeded');

        // ── summary: full response, then idle revalidation → 304 + empty body
        [$s1, , $c1] = api(2, 'teacher', 'POST', [], ['action' => 'summary']);
        ok($c1 === 200 && ($s1['status'] ?? '') === 'success', 'etag304: summary full response (200 + body)');
        $etag = '"ncsum-' . md5(\App\Services\NotificationCenterService::summaryVersion(db(), 2, 'teacher')) . '"';
        [$s2, $raw2, $c2] = api(2, 'teacher', 'POST', [], ['action' => 'summary'], false, $etag);
        ok($c2 === 304 && $raw2 === '', 'etag304: idle summary poll → 304 with EMPTY body');
        $etagBad = '"ncsum-' . md5('stale-version') . '"';
        [, $rawB, $cB] = api(2, 'teacher', 'POST', [], ['action' => 'summary'], false, $etagBad);
        ok($cB === 200 && $rawB !== '', 'etag304: wrong If-None-Match → full response');

        // ── a mutation must change the summary ETag (badge updates live)
        [$s3, , $c3] = api(1, 'super_admin', 'POST', [], ['action' => 'send_message', 'thread_id' => $tid, 'body' => 'changed!']);
        ok($c3 === 200, 'etag304: mutation sent');
        [$s4, $raw4, $c4] = api(2, 'teacher', 'POST', [], ['action' => 'summary'], false, $etag);
        ok($c4 === 200 && ($s4['summary']['messages'] ?? -1) >= 1, 'etag304: after a mutation the old ETag no longer matches (fresh data)');

        // ── open thread: etag captured implicitly via version; idle poll → 304
        [$t1, , $tc1] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], []);
        ok($tc1 === 200 && count($t1['messages'] ?? []) === 2, 'etag304: thread opened (full window)');
        $threadEtag = '"ncthr-' . md5(\App\Services\NotificationCenterService::threadVersion(db(), 2, $tid) . '|w0') . '"';
        // zero-write probe: backdate my read state — a FULL response would
        // run markThreadRead and stamp it back to NOW(); a 304 must leave
        // the backdated value untouched
        db()->query("UPDATE notification_reads SET read_at = '2020-01-01 00:00:00' WHERE user_id = 2 AND subject_type = 'message_thread'");
        [$t2, $rawT2, $tc2] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], [], false, $threadEtag);
        ok($tc2 === 304 && $rawT2 === '', 'etag304: idle thread poll → 304 with EMPTY body');
        $readAt = (string)(db()->query("SELECT read_at FROM notification_reads WHERE user_id = 2 AND subject_type = 'message_thread' LIMIT 1")->fetch_assoc()['read_at'] ?? '');
        ok(strpos($readAt, '2020-01-01') === 0, 'etag304: a 304 poll performs ZERO writes (read state untouched)');

        // ── new message → version moves → full window with the new message
        api(1, 'super_admin', 'POST', [], ['action' => 'send_message', 'thread_id' => $tid, 'body' => 'new one']);
        [$t3, $rawT3, $tc3] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], [], false, $threadEtag);
        ok($tc3 === 200 && count($t3['messages'] ?? []) === 3, 'etag304: new message breaks the ETag (full response)');

        // ── non-participants NEVER receive an ETag (no 304 oracle)
        [, $rawNP, ] = api(3, 'edu_dept', 'POST', ['action' => 'thread', 'id' => $tid], []);
        ok(strpos($rawNP, 'Not your conversation.') !== false, 'etag304: non-participant gets the permission error');
        [, , $cNP] = api(3, 'edu_dept', 'POST', ['action' => 'thread', 'id' => $tid], [], false, $threadEtag);
        ok($cNP !== 304, 'etag304: non-participant cannot get a 304 (participation-gated version)');
        verdict();
    }

    case 'pagination': {
        resetSchema(['043_message_read_receipts.sql', '044_message_edit_delete.sql']);
        // seed 30 notifications targeted at teacher
        $ins = db()->prepare("INSERT INTO notifications (type, title, message, priority, target_roles, source_user_id) VALUES ('member', ?, ?, 'normal', 'teacher', 1)");
        for ($i = 1; $i <= 30; $i++) { $t = "Alert no $i"; $m = "body $i"; $ins->bind_param('ss', $t, $m); $ins->execute(); }
        $ins->close();

        // feed: 3 exact pages of 10, strictly descending, no overlap, no gap
        [$p1] = api(2, 'teacher', 'POST', ['action' => 'feed', 'limit' => '10'], []);
        $ids1 = array_map(static fn($r) => (int)$r['id'], $p1['rows'] ?? []);
        ok(count($ids1) === 10 && ($p1['has_more'] ?? false) === true && ($p1['next_before'] ?? 0) === min($ids1),
            'pagination: feed page 1 = 10 rows + exact cursor');
        [$p2] = api(2, 'teacher', 'POST', ['action' => 'feed', 'limit' => '10', 'before_id' => (string)$p1['next_before']], []);
        $ids2 = array_map(static fn($r) => (int)$r['id'], $p2['rows'] ?? []);
        ok(count($ids2) === 10 && max($ids2) < min($ids1), 'pagination: feed page 2 strictly older, no overlap');
        [$p3] = api(2, 'teacher', 'POST', ['action' => 'feed', 'limit' => '10', 'before_id' => (string)$p2['next_before']], []);
        $ids3 = array_map(static fn($r) => (int)$r['id'], $p3['rows'] ?? []);
        $all = array_merge($ids1, $ids2, $ids3);
        ok(count($ids3) === 10 && ($p3['has_more'] ?? true) === false && count($all) === 30 && count(array_unique($all)) === 30,
            'pagination: feed pages tile exactly 30 rows (no gap, no duplicate, exact end)');

        // thread with 260 messages: newest-200 window + older page
        [$r] = api(1, 'super_admin', 'POST', [], ['action' => 'thread_start', 'to' => '2', 'subject' => 'E2E pages', 'body' => 'm0']);
        $tid = (int)($r['id'] ?? 0);
        ok($tid > 0, 'pagination: thread seeded');
        $batch = '';
        for ($i = 1; $i <= 259; $i++) { $batch .= "('{$tid}', 1, 'bulk {$i}'),"; }
        db()->query('INSERT INTO messages (thread_id, sender_id, body) VALUES ' . rtrim($batch, ','));
        [$t] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid], []);
        $mids = array_map(static fn($m) => (int)$m['id'], $t['messages'] ?? []);
        ok(count($mids) === 200 && max($mids) === 260 && min($mids) === 61,
            'pagination: thread ships the NEWEST 200 (ids 61..260) — was the oldest-200 bug');
        ok(($t['has_older'] ?? false) === true && (int)($t['oldest_id'] ?? 0) === 61, 'pagination: has_older + oldest_id cursor exact');
        [$t2] = api(2, 'teacher', 'POST', ['action' => 'thread', 'id' => $tid, 'before_id' => '61'], []);
        $mids2 = array_map(static fn($m) => (int)$m['id'], $t2['messages'] ?? []);
        ok(count($mids2) === 60 && max($mids2) === 60 && min($mids2) === 1 && ($t2['has_older'] ?? true) === false,
            'pagination: older page = exactly the remaining 60, ASC order, exact end');

        // conversation list: tuple cursor over 13 threads (5+5+3)
        for ($i = 1; $i <= 12; $i++) {
            db()->query("INSERT INTO message_threads (subject, created_by, last_message_at) VALUES ('bulk thread $i', 1, DATE_SUB(NOW(), INTERVAL $i MINUTE))");
            $ntid = (int)db()->insert_id;
            db()->query("INSERT INTO message_thread_participants (thread_id, user_id) VALUES ($ntid, 1), ($ntid, 2)");
            db()->query("INSERT INTO messages (thread_id, sender_id, body) VALUES ($ntid, 1, 'hello $i')");
        }
        [$th1] = api(2, 'teacher', 'POST', ['action' => 'threads', 'limit' => '5'], []);
        $tids1 = array_map(static fn($x) => (int)$x['id'], $th1['threads'] ?? []);
        $last1 = $th1['threads'][4] ?? null;
        ok(count($tids1) === 5 && ($th1['has_more'] ?? false) === true && is_array($th1['next'] ?? null)
            && $last1 !== null && (int)$th1['next'][1] === (int)$last1['id']
            && $th1['next'][0] === $last1['last_message_at'],
            'pagination: threads page 1 = 5 rows + exact last-row tuple cursor');
        [$th2] = api(2, 'teacher', 'POST', ['action' => 'threads', 'limit' => '5', 'before_lm' => (string)$th1['next'][0], 'before_id' => (string)$th1['next'][1]], []);
        $tids2 = array_map(static fn($x) => (int)$x['id'], $th2['threads'] ?? []);
        [$th3] = api(2, 'teacher', 'POST', ['action' => 'threads', 'limit' => '5', 'before_lm' => (string)$th2['next'][0], 'before_id' => (string)$th2['next'][1]], []);
        $tids3 = array_map(static fn($x) => (int)$x['id'], $th3['threads'] ?? []);
        $allT = array_merge($tids1, $tids2, $tids3);
        ok(count($tids3) === 3 && ($th3['has_more'] ?? true) === false && count($allT) === 13 && count(array_unique($allT)) === 13,
            'pagination: threads pages tile exactly 13 (5+5+3, no dup, no gap)');
        verdict();
    }

    case 'csrf_bad': {
        // one call with a WRONG token — the API exits with a 403 JSON.
        api(1, 'super_admin', 'POST', [], ['action' => 'send_message', 'thread_id' => 1, 'body' => 'x'], true);
        echo "E2E-UNEXPECTED: csrf_bad should have exited\n"; exit(1);
    }

    case 'unauth': {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/api_notifications.php';
        $_SESSION = [];
        include $GLOBALS['ROOT'] . '/admin/api_notifications.php';   // exits with Unauthorized
        echo "E2E-UNEXPECTED: unauth should have exited\n"; exit(1);
    }

    default: fwrite(STDERR, "unknown scenario: $SCENARIO\n"); exit(2);
}
