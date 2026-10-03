<?php
/**
 * Test harness: exercise the incremental sync change feed against a real
 * MariaDB, through the real migration and the real service class.
 *
 * Nothing here reimplements the feed. The triggers come from running
 * sql/057_sync_change_feed.sql verbatim, and every answer is produced by
 * App\Services\SyncChangeFeedService talking to a live database. The
 * point is to prove behaviour (does a DELETE really become a tombstone?)
 * rather than to grep source text for a reassuring string.
 *
 * COMMAND LINE ONLY, and destructive: it creates and drops its own
 * tables. It therefore refuses to run without the audit interlock and
 * works inside a disposable database (ssms_e2e), never production.
 *
 * Usage:
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/sync_change_feed.php <scenario|all>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$envFile = $root . '/.fkss_env.php';
if (!is_file($envFile)) {
    fwrite(STDERR, "missing .fkss_env.php\n");
    exit(3);
}
require $envFile;
require __DIR__ . '/destructive_guard.php';
require $root . '/admin/backend/services/SyncChangeFeedService.php';

use App\Services\SyncChangeFeedService;

// A dedicated disposable database. Never DB_NAME: that may hold data
// other harnesses depend on, and this script drops what it touches.
$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';

// Finding S interlock: this harness DROPs tables, so it refuses to run
// unless the operator declared intent AND the target name is recognisably
// disposable. Unknown databases fail closed.
ssms_require_disposable_database($dbName, 'sync_change_feed');

$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
if ($conn->connect_errno) {
    fwrite(STDERR, "cannot connect to {$dbName}: {$conn->connect_error}\n");
    exit(4);
}
$conn->set_charset('utf8mb4');

$failures = [];
$checks = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    $ok = $expected === $actual;
    if (!$ok) {
        $failures[] = sprintf(
            "%s\n      expected: %s\n      actual:   %s",
            $label,
            json_encode($expected),
            json_encode($actual)
        );
        echo "  FAIL  {$label}\n";
    } else {
        echo "  ok    {$label}\n";
    }
}

/**
 * Rebuild a clean world: the attendance table in its production column
 * shape, a teacher_assignments table for scope tests, and the feed
 * objects created by the real migration.
 *
 * No foreign keys: this harness is about the feed, and the production
 * FK count is asserted elsewhere by scripts/restore_production_dump.sh.
 */
function resetWorld(\mysqli $conn, string $root): void
{
    // Order matters: triggers vanish with their table.
    $conn->query("DROP TABLE IF EXISTS attendance");
    $conn->query("DROP TABLE IF EXISTS teacher_assignments");
    $conn->query("DROP TABLE IF EXISTS sync_changes");
    $conn->query("DROP TABLE IF EXISTS sync_feed_state");

    $conn->query(
        "CREATE TABLE attendance (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id int(10) UNSIGNED NOT NULL,
            class_id int(10) UNSIGNED DEFAULT NULL,
            academic_year_id int(10) UNSIGNED DEFAULT NULL,
            attendance_date date NOT NULL,
            status enum('present','absent','late','excused','holiday') NOT NULL,
            check_in_time time DEFAULT NULL,
            check_out_time time DEFAULT NULL,
            notes varchar(255) DEFAULT NULL,
            recorded_by int(10) UNSIGNED DEFAULT NULL,
            recorded_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY unique_attendance (member_id, attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->query(
        "CREATE TABLE teacher_assignments (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_id int(10) UNSIGNED NOT NULL,
            class_id int(10) UNSIGNED DEFAULT NULL,
            academic_year_id int(10) UNSIGNED DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    applyMigration($conn, $root . '/sql/057_sync_change_feed.sql');
    applyMigration($conn, $root . '/sql/058_sync_feed_scope_date.sql');
}

/**
 * Run the migration file the way the mysql client would, honouring the
 * DELIMITER directives that the trigger bodies need.
 */
function applyMigration(\mysqli $conn, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "cannot read migration {$path}\n");
        exit(5);
    }

    $delimiter = ';';
    $buffer = '';
    foreach (preg_split("/\r\n|\n|\r/", $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
            $delimiter = $m[1];
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with($trimmed, $delimiter)) {
            $statement = trim(substr(trim($buffer), 0, -strlen($delimiter)));
            $buffer = '';
            if ($statement === '') {
                continue;
            }
            if (!$conn->query($statement)) {
                fwrite(STDERR, "migration statement failed: {$conn->error}\n{$statement}\n");
                exit(6);
            }
        }
    }
}

/** Insert one attendance row through plain SQL, as existing endpoints do. */
function addAttendance(\mysqli $conn, int $memberId, int $classId, string $date, string $status = 'present'): int
{
    $stmt = $conn->prepare(
        "INSERT INTO attendance (member_id, class_id, academic_year_id, attendance_date, status, recorded_by)
         VALUES (?, ?, 5, ?, ?, 1)"
    );
    $stmt->bind_param('iiss', $memberId, $classId, $date, $status);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

$scenario = $argv[1] ?? 'all';
$run = static fn(string $name): bool => $scenario === 'all' || $scenario === $name;

// ─────────────────────────────────────────────────────────────
// 1. An INSERT through ordinary SQL becomes a feed entry.
//    This is the property a service-layer recorder would not have:
//    nothing in the write path knows the feed exists.
// ─────────────────────────────────────────────────────────────
if ($run('insert_recorded')) {
    echo "\n[insert_recorded]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    $id = addAttendance($conn, 1, 10, '2026-03-01');
    $page = $feed->changesSince(0, 'attendance', null, 50);

    check('one change recorded', 1, count($page['items']));
    check('op is INSERT', 'INSERT', $page['items'][0]['op']);
    check('entity id matches the row', $id, $page['items'][0]['entity_id']);
    check('revision is positive', true, $page['items'][0]['revision'] > 0);
    check('cursor advanced to that revision', $page['items'][0]['revision'], $page['next_cursor']);
}

// ─────────────────────────────────────────────────────────────
// 2. An UPDATE is discoverable; a no-op UPDATE is not.
//    Saving an unchanged sheet must not hand every device work.
// ─────────────────────────────────────────────────────────────
if ($run('update_recorded')) {
    echo "\n[update_recorded]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    $id = addAttendance($conn, 1, 10, '2026-03-01', 'present');
    $after = $feed->changesSince(0, 'attendance', null, 50)['next_cursor'];

    $conn->query("UPDATE attendance SET status='absent' WHERE id={$id}");
    $page = $feed->changesSince($after, 'attendance', null, 50);
    check('status change is in the feed', 1, count($page['items']));
    check('op is UPDATE', 'UPDATE', $page['items'][0]['op']);

    $afterUpdate = $page['next_cursor'];
    $conn->query("UPDATE attendance SET status='absent' WHERE id={$id}");
    $noop = $feed->changesSince($afterUpdate, 'attendance', null, 50);
    check('no-op update burns no revision', 0, count($noop['items']));
}

// ─────────────────────────────────────────────────────────────
// 3. A DELETE arrives as a tombstone with no payload, and the row is
//    genuinely gone. A device must never learn of a deletion by
//    re-downloading the table and noticing an absence.
// ─────────────────────────────────────────────────────────────
if ($run('delete_tombstone')) {
    echo "\n[delete_tombstone]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    $id = addAttendance($conn, 1, 10, '2026-03-01');
    $after = $feed->changesSince(0, 'attendance', null, 50)['next_cursor'];

    $conn->query("DELETE FROM attendance WHERE id={$id}");
    $page = $feed->changesSince($after, 'attendance', null, 50);

    check('deletion produced a change', 1, count($page['items']));
    check('op is DELETE', 'DELETE', $page['items'][0]['op']);
    check('tombstone identifies the row', $id, $page['items'][0]['entity_id']);
    check('no canonical payload exists for it', [], $feed->hydrateAttendance([$id]));

    $gone = $conn->query("SELECT COUNT(*) c FROM attendance WHERE id={$id}")->fetch_assoc()['c'];
    check('business row was really deleted', '0', $gone);

    // A tombstone must be actionable on its own. The device has never
    // seen attendance.id, so class + date + member are what let it find
    // the cached sheet entry to clear.
    check('tombstone carries its class', 10, $page['items'][0]['class_id']);
    check('tombstone carries its member', 1, $page['items'][0]['member_id']);
    check('tombstone carries its date', '2026-03-01', $page['items'][0]['date']);
}

// ─────────────────────────────────────────────────────────────
// 4. A cursor returns only what happened after it, and replaying the
//    same cursor is idempotent.
// ─────────────────────────────────────────────────────────────
if ($run('cursor_semantics')) {
    echo "\n[cursor_semantics]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    addAttendance($conn, 1, 10, '2026-03-01');
    addAttendance($conn, 2, 10, '2026-03-01');
    $first = $feed->changesSince(0, 'attendance', null, 50);
    check('two changes so far', 2, count($first['items']));

    $cursor = $first['next_cursor'];
    addAttendance($conn, 3, 10, '2026-03-01');

    $second = $feed->changesSince($cursor, 'attendance', null, 50);
    check('cursor returns only later changes', 1, count($second['items']));

    $replay = $feed->changesSince($cursor, 'attendance', null, 50);
    check('replaying a cursor is idempotent', $second['items'], $replay['items']);

    $atHead = $feed->changesSince($second['next_cursor'], 'attendance', null, 50);
    check('a caught-up cursor returns nothing', 0, count($atHead['items']));
    check('caught-up cursor is unchanged', $second['next_cursor'], $atHead['next_cursor']);
}

// ─────────────────────────────────────────────────────────────
// 5. Pages are bounded and resumable. An interrupted sync re-asks from
//    its last applied cursor and loses nothing.
// ─────────────────────────────────────────────────────────────
if ($run('pagination')) {
    echo "\n[pagination]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    for ($i = 1; $i <= 7; $i++) {
        addAttendance($conn, $i, 10, '2026-03-01');
    }

    $page1 = $feed->changesSince(0, 'attendance', null, 3);
    check('page respects the limit', 3, count($page1['items']));
    check('more is advertised', true, $page1['has_more']);

    // Simulate a crash after page 1: resume from page 1's cursor only.
    $page2 = $feed->changesSince($page1['next_cursor'], 'attendance', null, 3);
    $page3 = $feed->changesSince($page2['next_cursor'], 'attendance', null, 3);
    check('final page is short', 1, count($page3['items']));
    check('no more advertised', false, $page3['has_more']);

    $seen = array_merge(
        array_column($page1['items'], 'entity_id'),
        array_column($page2['items'], 'entity_id'),
        array_column($page3['items'], 'entity_id')
    );
    check('every change delivered exactly once', 7, count(array_unique($seen)));

    $clamped = $feed->changesSince(0, 'attendance', null, 99999);
    check('limit is clamped, not honoured blindly', true, count($clamped['items']) <= SyncChangeFeedService::MAX_LIMIT);
}

// ─────────────────────────────────────────────────────────────
// 6. Bootstrap is demanded explicitly; a partial delta is never
//    returned in its place.
// ─────────────────────────────────────────────────────────────
if ($run('bootstrap_required')) {
    echo "\n[bootstrap_required]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    addAttendance($conn, 1, 10, '2026-03-01');
    addAttendance($conn, 2, 10, '2026-03-01');

    check('a fresh install cannot resume', false, $feed->cursorIsResumable(0));
    check('a live cursor can resume', true, $feed->cursorIsResumable(1));
    check('a cursor ahead of the server cannot resume', false, $feed->cursorIsResumable(9999));

    // Age every existing change past the retention window, then prune.
    $conn->query("UPDATE sync_changes SET changed_at = NOW() - INTERVAL 90 DAY");
    $head = $feed->headRevision();
    $result = $feed->prune(30);

    check('expired changes were pruned', 2, $result['pruned']);
    check('retention floor moved past them', $head + 1, $result['min_valid_revision']);
    check('a pruned cursor cannot resume', false, $feed->cursorIsResumable($head));

    // New activity after pruning is resumable again from the new floor.
    addAttendance($conn, 3, 10, '2026-03-01');
    check('post-prune cursor resumes', true, $feed->cursorIsResumable($feed->headRevision()));
}

// ─────────────────────────────────────────────────────────────
// 7. The feed is permission scoped. A teacher's cursor must not leak
//    another class's records — including its tombstones.
// ─────────────────────────────────────────────────────────────
if ($run('permission_scope')) {
    echo "\n[permission_scope]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    // Teacher 77 teaches class 10 only. Class 20 is someone else's.
    $conn->query("INSERT INTO teacher_assignments (teacher_id, class_id, academic_year_id, is_active) VALUES (77, 10, 5, 1)");

    $mine = addAttendance($conn, 1, 10, '2026-03-01');
    $theirs = addAttendance($conn, 2, 20, '2026-03-01');

    $visible = $feed->visibleClassIds(77, true, 5);
    check('teacher sees exactly their class', [10], $visible);

    $page = $feed->changesSince(0, 'attendance', $visible, 50);
    check('only the teacher\'s class is in the feed', 1, count($page['items']));
    check('and it is the right row', $mine, $page['items'][0]['entity_id']);

    // The other class's deletion must stay invisible too.
    $conn->query("DELETE FROM attendance WHERE id={$theirs}");
    $afterDelete = $feed->changesSince($page['next_cursor'], 'attendance', $visible, 50);
    check('another class\'s tombstone does not leak', 0, count($afterDelete['items']));

    // An admin (unrestricted) sees everything.
    check('admin scope is unrestricted', null, $feed->visibleClassIds(1, false, 5));
    $adminPage = $feed->changesSince(0, 'attendance', null, 50);
    check('admin sees all three changes', 3, count($adminPage['items']));

    // A teacher with no assignments gets an empty feed, never the table.
    $orphan = $feed->visibleClassIds(999, true, 5);
    check('unassigned teacher has no classes', [], $orphan);
    check('unassigned teacher sees nothing', 0, count($feed->changesSince(0, 'attendance', $orphan, 50)['items']));
}

// ─────────────────────────────────────────────────────────────
// 8. The canonical payload is what replaces stale local data, and the
//    feed itself stays small — ids and operations, no blobs.
// ─────────────────────────────────────────────────────────────
if ($run('canonical_payload')) {
    echo "\n[canonical_payload]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    $id = addAttendance($conn, 1, 10, '2026-03-01', 'present');
    $conn->query("UPDATE attendance SET status='excused', notes='doctor' WHERE id={$id}");

    $page = $feed->changesSince(0, 'attendance', null, 50);
    $records = $feed->hydrateAttendance(array_column($page['items'], 'entity_id'));

    check('canonical row returned', true, isset($records[$id]));
    check('server value wins, not the stale local one', 'excused', $records[$id]['status']);
    check('canonical note is present', 'doctor', $records[$id]['notes']);
    check('canonical row carries its class', 10, $records[$id]['class_id']);

    // The feed row itself must stay a pointer, never a payload.
    $feedKeys = array_keys($page['items'][0]);
    sort($feedKeys);
    check(
        'feed entries carry ids, ops and scope keys only',
        ['changed_at', 'class_id', 'date', 'entity_id', 'entity_type', 'member_id', 'op', 'revision'],
        $feedKeys
    );
}

// ─────────────────────────────────────────────────────────────
// 9. Two devices. A change made by device A reaches device B through
//    its own cursor — the case that is broken today.
// ─────────────────────────────────────────────────────────────
if ($run('two_devices')) {
    echo "\n[two_devices]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    // Both devices start in sync and already hold this row.
    $id = addAttendance($conn, 1, 10, '2026-03-01', 'present');
    $deviceB = $feed->changesSince(0, 'attendance', null, 50)['next_cursor'];

    // Device A corrects the record (through the ordinary write path).
    $conn->query("UPDATE attendance SET status='late' WHERE id={$id}");

    // Device B asks what changed since it last synced.
    $delta = $feed->changesSince($deviceB, 'attendance', null, 50);
    check('device B is told the row changed', 1, count($delta['items']));
    check('device B sees an UPDATE', 'UPDATE', $delta['items'][0]['op']);

    $records = $feed->hydrateAttendance([$delta['items'][0]['entity_id']]);
    check('device B receives the corrected value', 'late', $records[$id]['status']);

    // Having applied it, device B advances and is quiet again.
    $settled = $feed->changesSince($delta['next_cursor'], 'attendance', null, 50);
    check('device B is now up to date', 0, count($settled['items']));

    // Device A deletes it; device B learns via tombstone.
    $conn->query("DELETE FROM attendance WHERE id={$id}");
    $deletion = $feed->changesSince($settled['next_cursor'], 'attendance', null, 50);
    check('device B is told about the deletion', 'DELETE', $deletion['items'][0]['op']);
}

// ─────────────────────────────────────────────────────────────
// 10. A row changed and then deleted within one page must not be
//     applied as live data by a device reading that page.
// ─────────────────────────────────────────────────────────────
if ($run('change_then_delete')) {
    echo "\n[change_then_delete]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    $id = addAttendance($conn, 1, 10, '2026-03-01');
    $conn->query("UPDATE attendance SET status='absent' WHERE id={$id}");
    $conn->query("DELETE FROM attendance WHERE id={$id}");

    $page = $feed->changesSince(0, 'attendance', null, 50);
    check('all three operations are recorded', 3, count($page['items']));

    $records = $feed->hydrateAttendance(array_column($page['items'], 'entity_id'));
    check('no canonical payload survives the delete', [], $records);
    check('last operation is the tombstone', 'DELETE', $page['items'][2]['op']);
}

// ─────────────────────────────────────────────────────────────
// 11. Moving a record to another class or date must retire the entry
//     on the old sheet, not just add one to the new sheet.
// ─────────────────────────────────────────────────────────────
if ($run('record_moved')) {
    echo "\n[record_moved]\n";
    resetWorld($conn, $root);
    $feed = new SyncChangeFeedService($conn);

    $id = addAttendance($conn, 1, 10, '2026-03-01');
    $after = $feed->changesSince(0, 'attendance', null, 50)['next_cursor'];

    // Marked on the wrong day, then corrected.
    $conn->query("UPDATE attendance SET attendance_date='2026-03-02' WHERE id={$id}");
    $page = $feed->changesSince($after, 'attendance', null, 50);

    check('a move emits two entries', 2, count($page['items']));
    check('the old location is retired', 'DELETE', $page['items'][0]['op']);
    check('retirement names the old date', '2026-03-01', $page['items'][0]['date']);
    check('the new location is announced', 'UPDATE', $page['items'][1]['op']);
    check('announcement names the new date', '2026-03-02', $page['items'][1]['date']);

    // The row still exists, so the UPDATE half still hydrates.
    $records = $feed->hydrateAttendance([$id]);
    check('canonical row survives the move', '2026-03-02', $records[$id]['attendance_date']);
}

// ─────────────────────────────────────────────────────────────
// Clean up after ourselves so the shared test database is left tidy.
// ─────────────────────────────────────────────────────────────
$conn->query("DROP TABLE IF EXISTS attendance");
$conn->query("DROP TABLE IF EXISTS teacher_assignments");
$conn->query("DROP TABLE IF EXISTS sync_changes");
$conn->query("DROP TABLE IF EXISTS sync_feed_state");

echo "\n";
if ($failures === []) {
    echo "E2E-VERDICT: PASS ({$checks} checks)\n";
    exit(0);
}
echo "FAILURES (" . count($failures) . " of {$checks}):\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
echo "E2E-VERDICT: FAIL\n";
exit(1);
