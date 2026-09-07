<?php
// Deliberately unavailable over HTTP, before config is loaded.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('SSMS_AUDIT_TESTING') !== '1') {
    fwrite(STDERR, "Set SSMS_AUDIT_TESTING=1 only for the isolated synthetic database.\n"); exit(2);
}
require_once dirname(__DIR__, 2) . '/config.php';
while (ob_get_level() > 0) { ob_end_clean(); }
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true) || DB_NAME !== 'ssms') {
    fwrite(STDERR, "Refusing to run outside the local ssms test database.\n"); exit(2);
}
$request = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
if (($request['op'] ?? '') === 'seed_roles') {
    $roles = ['super_admin','school_admin','info_dept','hr_dept','edu_dept','finance_dept',
        'material_dept','mezmur_dept','teacher','attendance_taker','mezmur_attendance_taker','hr_attendance_taker','content_editor'];
    $hash = password_hash('AuditTest#2026', PASSWORD_BCRYPT);
    foreach ($roles as $role) {
        $name = 'audit_' . $role;
        $stmt = $pdo->prepare('INSERT INTO users (username,email,full_name,role,password_hash,is_active)
            VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE role=VALUES(role),password_hash=VALUES(password_hash),is_active=1');
        $stmt->execute([$name, $name . '@test.local', 'Audit ' . $role, $role, $hash]);
    }
    echo json_encode(['roles' => $roles]);
} elseif (($request['op'] ?? '') === 'backup_list') {
    require_once dirname(__DIR__, 2) . '/admin/backend/services/BackupService.php';
    $files = \App\Services\BackupService::listBackups(ROOT_PATH);
    foreach ($files as &$file) $file['path'] = \App\Services\BackupService::resolveForDownload($file['name'], ROOT_PATH);
    unset($file);
    echo json_encode(['files'=>$files]);
} elseif (($request['op'] ?? '') === 'clone_member') {
    $cols = $pdo->query('SHOW COLUMNS FROM members')->fetchAll();
    $names = []; $select = [];
    foreach ($cols as $col) {
        if (str_contains($col['Extra'], 'GENERATED')) continue;
        $name = $col['Field'];
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Unexpected column.');
        $names[] = '`' . $name . '`';
        $select[] = in_array($name, ['id','member_code'], true) ? 'NULL' : '`' . $name . '`';
    }
    $pdo->exec('INSERT INTO members (' . implode(',', $names) . ') SELECT ' . implode(',', $select) . ' FROM members WHERE id=900000');
    echo json_encode(['id'=>(int)$pdo->lastInsertId()]);
} elseif (($request['op'] ?? '') === 'enrollment_scope') {
    require_once dirname(__DIR__, 2) . '/admin/backend/workflow.php';
    require_once dirname(__DIR__, 2) . '/admin/backend/services/EnrollmentService.php';
    $member = (int)$request['member']; $class = (int)$request['class']; $year = (int)$request['year'];
    $actor = (int)$pdo->query("SELECT id FROM users WHERE username='audit_super_admin'")->fetchColumn();
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE members SET father_name='AUDIT OUTER PENDING' WHERE id=?");
    $stmt->bind_param('i', $member); $stmt->execute();
    $result = \App\Services\EnrollmentService::enroll($conn, $member, $class, $year, $actor, true);
    $pending = $conn->query('SELECT father_name FROM members WHERE id=' . $member)->fetch_assoc()['father_name'];
    $conn->rollback();
    $persisted = $conn->query('SELECT father_name FROM members WHERE id=' . $member)->fetch_assoc()['father_name'];
    echo json_encode(['result'=>$result,'pending'=>$pending,'persisted'=>$persisted]);
} elseif (($request['op'] ?? '') === 'session') {
    // Session data from local test HTTP logins only, for timeout/revocation probes.
    session_write_close();
    session_id($request['id']);
    session_start();
    foreach ($request['values'] as $key => $value) { $_SESSION[$key] = $value; }
    $csrf = $_SESSION['csrf_token'] ?? '';
    session_write_close();
    echo json_encode(['ok' => true, 'csrf' => $csrf]);
} elseif (($request['op'] ?? '') === 'sql') {
    $stmt = $pdo->prepare($request['sql']);
    $stmt->execute($request['params'] ?? []);
    echo json_encode(['rows' => $stmt->columnCount() > 0 ? $stmt->fetchAll() : [],
        'affected' => $stmt->rowCount(), 'id' => $pdo->lastInsertId()], JSON_INVALID_UTF8_SUBSTITUTE);
} else {
    fwrite(STDERR, "Unknown fixture operation.\n"); exit(2);
}
