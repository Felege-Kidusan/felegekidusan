<?php
/**
 * User role diagnostic & repair — audits the users table against the
 * canonical role catalogue and can reassign a single account's role.
 *
 * Why this exists (Fix 13): the dashboard routers (admin/dashboard.php and
 * frontend/pages/dashboard.php) dispatch strictly by users.role. If an
 * account is stored with the wrong role (e.g. a finance officer saved as
 * 'school_admin'), the user silently lands on the wrong dashboard with no
 * error anywhere. This tool makes the stored role visible and repairable
 * without hand-editing SQL.
 *
 * Usage (CLI ONLY, from the deployment root):
 *   php admin/tools/repair_user_role.php                       # audit table
 *   php admin/tools/repair_user_role.php --username=abebe \
 *       --role=finance_dept                                    # dry run
 *   php admin/tools/repair_user_role.php --username=abebe \
 *       --role=finance_dept --apply                            # execute
 *
 * Safety: CLI-only (HTTP 404 on the web), dry run by default, prepared
 * statements, canonical role whitelist identical to user-save.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../../config.php';

// Canonical catalogue — keep in sync with admin/backend/user-save.php.
$CANONICAL_ROLES = [
    'super_admin',
    'school_admin',
    'info_dept',
    'hr_dept',
    'edu_dept',
    'finance_dept',
    'material_dept',
    'mezmur_dept',
    'teacher',
    'attendance_taker',
];

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$usernameArg = null;
$roleArg = null;
foreach ($args as $a) {
    if (strpos($a, '--username=') === 0) $usernameArg = trim(substr($a, 11));
    if (strpos($a, '--role=') === 0)     $roleArg     = trim(substr($a, 7));
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, "FATAL: database connection failed.\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

// ── Mode 1: repair a single account ──────────────────────────────────
if ($usernameArg !== null || $roleArg !== null) {
    if ($usernameArg === null || $usernameArg === '' || $roleArg === null || $roleArg === '') {
        fwrite(STDERR, "Both --username=<name> and --role=<role> are required.\n");
        exit(1);
    }
    if (!in_array($roleArg, $CANONICAL_ROLES, true)) {
        fwrite(STDERR, "Invalid role '$roleArg'. Canonical roles:\n  " . implode(', ', $CANONICAL_ROLES) . "\n");
        exit(1);
    }

    $stmt = $conn->prepare("SELECT id, username, full_name, role, is_active FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $usernameArg);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user) {
        fwrite(STDERR, "No user with username '$usernameArg'.\n");
        exit(1);
    }

    echo "User:    {$user['username']} (id {$user['id']}, {$user['full_name']})\n";
    echo "Current: role = '{$user['role']}', is_active = {$user['is_active']}\n";

    if ($user['role'] === $roleArg) {
        echo "Nothing to do — role is already '$roleArg'.\n";
        exit(0);
    }

    echo "Target:  role = '$roleArg'\n";
    if (!$apply) {
        echo "\nDRY RUN — no changes made. Re-run with --apply to execute.\n";
        exit(0);
    }

    $upd = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    $upd->bind_param('si', $roleArg, $user['id']);
    if (!$upd->execute()) {
        fwrite(STDERR, "UPDATE failed: " . $upd->error . "\n");
        exit(1);
    }
    $upd->close();

    echo "\nAPPLIED — '{$user['username']}' now has role '$roleArg'.\n";
    echo "The user will land on the correct dashboard on their next login.\n";
    echo "(Active browser sessions are revalidated against the database and pick the new role up automatically.)\n";
    exit(0);
}

// ── Mode 2: audit every account ──────────────────────────────────────
echo "=== User role audit ===\n\n";

$stmt = $conn->query("SELECT id, username, full_name, role, is_active FROM users ORDER BY role, username");
if (!$stmt) {
    fwrite(STDERR, "QUERY failed: " . $conn->error . "\n");
    exit(1);
}

printf("%-4s %-24s %-18s %-9s %s\n", 'ID', 'USERNAME', 'ROLE', 'ACTIVE', 'FULL NAME');
echo str_repeat('-', 90) . "\n";

$counts = [];
$anomalies = 0;
while ($row = $stmt->fetch_assoc()) {
    $known = in_array($row['role'], $CANONICAL_ROLES, true);
    if (!$known) $anomalies++;
    $counts[$row['role']] = ($counts[$row['role']] ?? 0) + 1;
    printf(
        "%-4d %-24s %-18s %-9s %s%s\n",
        $row['id'],
        substr($row['username'], 0, 24),
        $row['role'],
        $row['is_active'] ? 'yes' : 'NO',
        $row['full_name'],
        $known ? '' : '   <-- UNKNOWN ROLE (will hit "Dashboard Under Construction")'
    );
}
$stmt->free();

echo "\nRole distribution:\n";
foreach ($CANONICAL_ROLES as $r) {
    if (isset($counts[$r])) printf("  %-18s %d\n", $r, $counts[$r]);
}
foreach ($counts as $r => $n) {
    if (!in_array($r, $CANONICAL_ROLES, true)) printf("  %-18s %d   <-- unknown\n", $r, $n);
}

echo "\n";
if ($anomalies > 0) {
    echo "Found $anomalies account(s) with a non-canonical role.\n";
} else {
    echo "All accounts carry canonical roles.\n";
}
echo "\nHint: if an account lands on the wrong dashboard, its stored role is the\n";
echo "cause. Repair with:\n";
echo "  php admin/tools/repair_user_role.php --username=<name> --role=<correct_role> --apply\n";
