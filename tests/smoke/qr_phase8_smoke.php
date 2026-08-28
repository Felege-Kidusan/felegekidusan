<?php
/**
 * Phase 8 QR roster smoke — self-driving.
 *
 * With no argv it seeds fixtures, then re-executes itself once per
 * case (the endpoint `exit`s after streaming, so one include per
 * process). Each child includes admin/api_qr_roster.php exactly once
 * and the parent asserts on the captured stdout.
 */

$root = dirname(__DIR__, 2);
$self = __FILE__;

if ($argc > 1) {
    // ── child mode: one endpoint include ──────────────────────
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require $root . '/admin/config.php';
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = 900004;
    $_SESSION['admin_role'] = $argv[2];
    $_SESSION['last_activity'] = time();
    $_GET['dept'] = $argv[3];
    if (isset($argv[4])) {
        if ($argv[3] === 'edu') { $_GET['class_id'] = $argv[4]; }
        else { $_GET['section'] = $argv[4]; }
    }
    include $root . '/admin/api_qr_roster.php';
    exit; // unreachable; endpoint exits
}

// ── parent mode ────────────────────────────────────────────────
require $root . '/admin/config.php';
$q = function (string $sql) use ($conn) { $conn->query($sql); };

$q("SET SESSION sql_mode=''");
$q("DELETE FROM users WHERE id IN (900004,900005,900006)");
$q("DELETE FROM class_enrollments WHERE class_id = 777 AND academic_year_id = 888");
$q("DELETE FROM members WHERE id IN (5001,5002,5003)");
$q("DELETE FROM classes WHERE id = 777");
$q("DELETE FROM academic_years WHERE id = 888");

$q("INSERT INTO users (id,username,password_hash,role,full_name,is_active) VALUES
   (900004,'qr_edu','x','edu_dept','QR Edu',1),
   (900005,'qr_mez','x','mezmur_dept','QR Mez',1),
   (900006,'qr_hr','x','hr_dept','QR HR',1)");
$q("INSERT INTO classes (id,class_name) VALUES (777,'QR Class')");
$q("INSERT INTO academic_years (id,year_name,is_current) VALUES (888,'QR Year',1)");
$q("INSERT INTO members (id,student_name,member_code,status,current_section) VALUES
   (5001,'Member One','FKSS-5001','active','ሀ'),
   (5002,'Member Two','FKSS-5002','active','ለ'),
   (5003,'Member Three','FKSS-5003','archived','ሀ')");
$q("INSERT INTO class_enrollments (member_id,class_id,academic_year_id,status) VALUES
   (5001,777,888,'active'),(5002,777,888,'active')");

function run_case(string $self, string $role, string $dept, string $arg = ''): string
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 '
        . escapeshellarg($self) . ' case ' . escapeshellarg($role) . ' '
        . escapeshellarg($dept) . ($arg !== '' ? ' ' . escapeshellarg($arg) : '');
    return (string)shell_exec($cmd . ' 2>/dev/null');
}

$fails = 0;
$check = function (string $name, bool $ok) use (&$fails) {
    echo ($ok ? "  ok  " : " FAIL ") . $name . "\n";
    if (!$ok) $fails++;
};

$out = run_case($self, 'edu_dept', 'edu', '777');
$check('edu roster streams PDF', str_starts_with($out, '%PDF'));

$out = run_case($self, 'mezmur_dept', 'mezmur', 'ሀ');
$check('mezmur roster streams PDF', str_starts_with($out, '%PDF'));

$out = run_case($self, 'hr_dept', 'hr', 'ሀ');
$check('hr roster streams PDF', str_starts_with($out, '%PDF'));

$out = run_case($self, 'edu_dept', 'hr', 'ሀ');
$check('cross-department print denied',
    str_contains($out, 'do not have permission') && !str_starts_with($out, '%PDF'));

$out = run_case($self, 'school_admin', 'nope');
$check('unknown department rejected', str_contains($out, 'Unknown department'));

$out = run_case($self, 'edu_dept', 'edu', '999');
$check('empty class roster rejected', str_contains($out, 'No active members'));

// cleanup
$q("DELETE FROM users WHERE id IN (900004,900005,900006)");
$q("DELETE FROM class_enrollments WHERE class_id = 777 AND academic_year_id = 888");
$q("DELETE FROM members WHERE id IN (5001,5002,5003)");
$q("DELETE FROM classes WHERE id = 777");
$q("DELETE FROM academic_years WHERE id = 888");

if ($fails === 0) {
    echo "ALL QR ROSTER FUNCTIONAL CHECKS PASSED\n";
    exit(0);
}
echo "$fails QR ROSTER CHECK(S) FAILED\n";
exit(1);
