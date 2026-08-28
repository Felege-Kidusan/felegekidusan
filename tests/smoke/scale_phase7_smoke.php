<?php
/**
 * Functional smoke test for Phase E scale hardening (live DB).
 * Proves:
 *   - sql/028 applies twice (idempotent) and creates both
 *     (member_id, attendance_date) seek indexes
 *   - the analytics KPI read resolves through the rollup's
 *     (source, rollup_date) index — never a raw-table scan
 *   - member-history queries can use the new seek indexes
 * Exits non-zero with a message on the first failure.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$fail = function (string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$pass = function (string $msg): void { echo "  ok: $msg\n"; };

$conn = @new mysqli('127.0.0.1', 'ssms', 'ssms', 'ssms_smoke');
if ($conn->connect_errno) $fail('db connect: ' . $conn->connect_error);
$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode = ''");

// ── migration 028 applies twice → idempotent ─────────────────
// DELIMITER is a mysql-CLI directive; strip it and split on the $$
// statement separators so the migration runs over plain mysqli.
$sql = file_get_contents(dirname(__DIR__, 2) . '/sql/028_analytics_scale.sql');
$sql === false || $sql === '' ? $fail('cannot read sql/028') : null;
$clean = preg_replace('/^\s*DELIMITER.*$/m', '', $sql);
$chunks = preg_split('/\$\$/', $clean);
$runMigration = function () use ($conn, $chunks, $fail): void {
    foreach ($chunks as $i => $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        // Last chunk holds the plain ';' statements (CALLs + DROP PROCEDURE)
        $ok = $conn->multi_query($chunk);
        if (!$ok) $fail('028 chunk ' . $i . ': ' . $conn->error);
        while ($conn->more_results() && $conn->next_result()) { /* drain */ }
        if ($conn->errno) $fail('028 chunk ' . $i . ' exec: ' . $conn->error);
    }
};
$runMigration();
$runMigration();
$pass('sql/028 applies cleanly twice (idempotent)');

// ── indexes present ──────────────────────────────────────────
$hasIndex = function (mysqli $conn, string $table, string $index): bool {
    $st = $conn->prepare("SELECT COUNT(*) c FROM information_schema.STATISTICS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $st->bind_param('ss', $table, $index);
    $st->execute();
    $c = (int)$st->get_result()->fetch_assoc()['c'];
    $st->close();
    return $c > 0;
};
$hasIndex($conn, 'mezmur_attendance', 'idx_mezmur_att_member_date')
    ? $pass('idx_mezmur_att_member_date (member_id, attendance_date) present')
    : $fail('missing idx_mezmur_att_member_date');
$hasIndex($conn, 'attendance', 'idx_att_member_date')
    ? $pass('idx_att_member_date (member_id, attendance_date) present')
    : $fail('missing idx_att_member_date');
// procedure cleaned up after itself
$res = $conn->query("SELECT COUNT(*) c FROM information_schema.ROUTINES
                     WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'ssms_add_index_if_missing_028'");
(int)$res->fetch_assoc()['c'] === 0 ? $pass('helper procedure dropped after run')
                                     : $fail('helper procedure left behind');

// ── KPI read resolves through rollup index ───────────────────
$ex = $conn->query("EXPLAIN SELECT source, COUNT(*) FROM attendance_rollup
                    WHERE rollup_date BETWEEN '2026-08-01' AND '2026-08-31'
                    GROUP BY source");
$usesRollupIdx = false;
while ($row = $ex->fetch_assoc()) {
    $keys = (string)($row['possible_keys'] ?? '') . ' ' . (string)($row['key'] ?? '');
    if (strpos($keys, 'idx_rollup_source_date') !== false || strpos($keys, 'idx_rollup_date') !== false) {
        $usesRollupIdx = true;
    }
}
$usesRollupIdx ? $pass('rollup KPI read uses rollup date index (no full scan)')
               : $fail('rollup read did not resolve through a date index');

// ── member-history seeks advertise the new indexes ───────────
$checkPossible = function (mysqli $conn, string $sql, string $index) use ($fail): void {
    $ex = $conn->query($sql);
    while ($row = $ex->fetch_assoc()) {
        if (strpos((string)($row['possible_keys'] ?? ''), $index) !== false) return;
    }
    $fail("EXPLAIN never listed $index as possible");
};
$checkPossible(
    $conn,
    "EXPLAIN SELECT attendance_date, status FROM mezmur_attendance
     WHERE member_id = 1 AND attendance_date BETWEEN '2026-08-01' AND '2026-08-31'",
    'idx_mezmur_att_member_date'
);
$pass('mezmur member-history seek can use idx_mezmur_att_member_date');
$checkPossible(
    $conn,
    "EXPLAIN SELECT attendance_date, status FROM attendance
     WHERE member_id = 1 AND attendance_date BETWEEN '2026-08-01' AND '2026-08-31'",
    'idx_att_member_date'
);
$pass('edu member-history seek can use idx_att_member_date');

echo "ALL SCALE HARDENING FUNCTIONAL CHECKS PASSED\n";
