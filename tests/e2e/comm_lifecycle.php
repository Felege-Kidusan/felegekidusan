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
 *
 * Usage:   php tests/e2e/comm_lifecycle.php <scenario>
 * Needs:   .fkss_env.php in the repo root pointing at the prepared
 *          database; the DB user needs ALL PRIVILEGES on that ONE
 *          database (it drops/recreates the communication tables).
 *          See tests/e2e/comm_e2e_setup.sh for one-time preparation.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

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
function api(string $userId, string $role, string $method, array $get, array $post, bool $badCsrf = false): array {
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
    ob_start();
    include $GLOBALS['ROOT'] . '/admin/api_notifications.php';
    $raw = ob_get_clean();
    $decoded = json_decode($raw, true);
    return [$decoded, $raw];
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
