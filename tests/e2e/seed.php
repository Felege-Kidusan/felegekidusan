<?php
/**
 * Edu-audit end-to-end test seeder (STAGING/TEST ONLY).
 *
 * Idempotent: safe to run twice. Creates the exact world the audit
 * findings live in:
 *   - 4 staff logins (super, edu, teacher, finance)  [finance = authz probe]
 *   - academic year + current term
 *   - 3 classes; class 3 deliberately has NO class_subjects links
 *     (reproduces the "empty subject dropdown" symptom)
 *   - teacher assignment with subject (class 1) and without (class 2)
 *     (reproduces H1)
 *   - assessment + grades (reproduces C1 resave failure)
 *
 * Usage: php tests/e2e/seed.php
 */
require_once __DIR__ . '/../../config.php';
// config.php opens a gzip output buffer for web responses; in CLI it swallows
// all later output. Drop buffers so the seeder can print its result JSON.
while (ob_get_level() > 0) { @ob_end_clean(); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$TEST_PASS_HASH = password_hash('AuditTest#2026', PASSWORD_BCRYPT);

function seed_user(mysqli $conn, string $username, string $role, string $fullName, string $hash): void
{
    $stmt = $conn->prepare(
        "INSERT INTO users (username, email, full_name, role, password_hash, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), role = VALUES(role),
                                 password_hash = VALUES(password_hash), is_active = 1"
    );
    $email = $username . '@test.local';
    $stmt->bind_param('sssss', $username, $email, $fullName, $role, $hash);
    $stmt->execute();
    $stmt->close();
}

seed_user($conn, 'audit_super', 'super_admin', 'Audit Super', $TEST_PASS_HASH);
seed_user($conn, 'audit_edu',   'edu_dept',    'Audit Education', $TEST_PASS_HASH);
seed_user($conn, 'audit_teach', 'teacher',     'Audit Teacher', $TEST_PASS_HASH);
seed_user($conn, 'audit_fin',   'finance_dept','Audit Finance', $TEST_PASS_HASH);

// --- academic year + term ------------------------------------------------
$yearName = '2018 ዓ.ም.';
$conn->query("UPDATE academic_years SET is_current = 0");
$stmt = $conn->prepare(
    "INSERT INTO academic_years (year_name, ec_year, year_gc, start_date, end_date, is_current)
     VALUES (?, 2018, '2025/26', '2025-09-01', '2026-07-31', 1)
     ON DUPLICATE KEY UPDATE is_current = 1, start_date = VALUES(start_date), end_date = VALUES(end_date)");
$stmt->bind_param('s', $yearName);
$stmt->execute();
$stmt->close();
$yearId = (int)$conn->query("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1")->fetch_assoc()['id'];

$conn->query("UPDATE academic_terms SET is_current = 0");
$conn->query("DELETE FROM academic_terms WHERE academic_year_id = $yearId AND term_number = 1");
$conn->query("INSERT INTO academic_terms (academic_year_id, term_name, term_number, is_current)
              VALUES ($yearId, 'Semester 1', 1, 1)");
$termId = (int)$conn->query("SELECT id FROM academic_terms WHERE is_current = 1 LIMIT 1")->fetch_assoc()['id'];

// --- classes ---------------------------------------------------------------
// (classes has no natural unique key — clear the whole test set in FK order)
$conn->query("CREATE TEMPORARY TABLE _old_test_classes AS SELECT id FROM classes WHERE class_code IN ('grade_1','grade_2','grade_3')");
$conn->query("DELETE FROM academic_records WHERE assessment_id IN (SELECT id FROM assessments WHERE class_id IN (SELECT id FROM _old_test_classes)) OR member_id >= 900000");
$conn->query("DELETE FROM assessments WHERE class_id IN (SELECT id FROM _old_test_classes)");
$conn->query("DELETE FROM class_enrollments WHERE class_id IN (SELECT id FROM _old_test_classes) OR member_id >= 900000");
$conn->query("DELETE FROM teacher_assignments WHERE class_id IN (SELECT id FROM _old_test_classes)");
$conn->query("DELETE FROM class_subjects WHERE class_id IN (SELECT id FROM _old_test_classes)");
$conn->query("DROP TEMPORARY TABLE _old_test_classes");
$conn->query("DELETE FROM classes WHERE class_code IN ('grade_1','grade_2','grade_3')");
$classes = [
    ['1ኛ ክፍል', 'Grade 1', 'grade_1', 1],
    ['2ኛ ክፍል', 'Grade 2', 'grade_2', 2],
    ['3ኛ ክፍል', 'Grade 3', 'grade_3', 3],
];
foreach ($classes as [$am, $en, $code, $lvl]) {
    $stmt = $conn->prepare(
        "INSERT INTO classes (class_name, class_name_en, class_code, level_order, is_active)
         VALUES (?, ?, ?, ?, 1)");
    $stmt->bind_param('sssi', $am, $en, $code, $lvl);
    $stmt->execute();
    $stmt->close();
}
$classIds = [];
foreach ($conn->query("SELECT id, class_code FROM classes ORDER BY level_order") as $row) {
    $classIds[$row['class_code']] = (int)$row['id'];
}

// --- subjects + class_subjects (grade_3 intentionally unlinked) ------------
$subjects = [
    ['መጽሐፍ ቅዱስ', 'Holy Bible', 'bible'],
    ['ቅዳሴ', 'Liturgy', 'liturgy'],
    ['ታሪክ', 'Church History', 'history'],
];
foreach ($subjects as [$am, $en, $code]) {
    $stmt = $conn->prepare(
        "INSERT INTO subjects (subject_name, subject_name_en, subject_code, is_active)
         VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name)");
    $stmt->bind_param('sss', $am, $en, $code);
    $stmt->execute();
    $stmt->close();
}
$subjectIds = [];
foreach ($conn->query("SELECT id, subject_code FROM subjects") as $row) {
    $subjectIds[$row['subject_code']] = (int)$row['id'];
}

$conn->query("DELETE FROM class_subjects WHERE class_id IN (" . implode(',', $classIds) . ")");
$links = [
    ['grade_1', 'bible'], ['grade_1', 'liturgy'],
    ['grade_2', 'bible'], ['grade_2', 'history'],
    // grade_3: NO subjects linked (audit symptom #3)
];
$stmt = $conn->prepare("INSERT IGNORE INTO class_subjects (class_id, subject_id) VALUES (?, ?)");
foreach ($links as [$cc, $sc]) {
    $cid = $classIds[$cc]; $sid = $subjectIds[$sc];
    $stmt->bind_param('ii', $cid, $sid);
    $stmt->execute();
}
$stmt->close();

// --- members + enrollments -------------------------------------------------
$conn->query("DELETE FROM class_enrollments WHERE member_id >= 900000");
$conn->query("DELETE FROM members WHERE id >= 900000");
$names = [
    ['አበበ', 'ተስፋዬ', 'male',   'grade_1'],
    ['ብርሃኑ', 'አበበ', 'male',   'grade_1'],
    ['ጨረበት', 'ደስታ', 'female', 'grade_1'],
    ['ዳንኤል', 'ገብረ', 'male',   'grade_2'],
    ['ሕይወት', 'አለሙ', 'female', 'grade_2'],
    ['ሉቅስ', 'ወልደ', 'male',   'grade_2'],
    ['ማርያም', 'ስመርት', 'female', 'grade_1'],
];
$stmtM = $conn->prepare(
    "INSERT INTO members (id, member_code, full_name_am, student_name, father_name, gender, status, member_type, age_group, created_at)
     VALUES (?, ?, ?, ?, ?, ?, 'active', 'regular', '7_13', NOW())");
$stmtE = $conn->prepare(
    "INSERT INTO class_enrollments (member_id, class_id, academic_year_id, status, enrolled_at)
     VALUES (?, ?, ?, 'active', CURDATE())");
// H7 fixture: first test member carries a phone so PII masking is testable.
$stmtP = $conn->prepare("UPDATE members SET phone_number = ? WHERE id = ?");
$stmtP->bind_param('si', $probePhone, $probeMemberId);
$probePhone = '+251911000001';
$probeMemberId = 900000;
$stmtP->execute();
$stmtP->close();
$i = 0;
foreach ($names as [$sn, $fn, $g, $cc]) {
    $id = 900000 + $i;
    $code = sprintf('FK-T%04d', $i + 1);
    $fullAm = $sn . ' ' . $fn;
    $stmtM->bind_param('isssss', $id, $code, $fullAm, $sn, $fn, $g);
    $stmtM->execute();
    $cid = $classIds[$cc];
    $stmtE->bind_param('iii', $id, $cid, $yearId);
    $stmtE->execute();
    $i++;
}
$stmtM->close();
$stmtE->close();

// --- teacher assignments (with + without subject) ---------------------------
$teacherId = (int)$conn->query("SELECT id FROM users WHERE username = 'audit_teach' LIMIT 1")->fetch_assoc()['id'];
$conn->query("DELETE FROM teacher_assignments WHERE teacher_id = $teacherId");
$stmt = $conn->prepare(
    "INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, academic_year_id, is_class_teacher, is_primary, is_active, assigned_at)
     VALUES (?, ?, ?, ?, 0, 1, 1, NOW())");
$c1 = $classIds['grade_1']; $c2 = $classIds['grade_2']; $bible = $subjectIds['bible'];
$stmt->bind_param('iiii', $teacherId, $c1, $bible, $yearId); $stmt->execute();   // subject set
$null = null;
$stmt->bind_param('iiii', $teacherId, $c2, $null, $yearId); $stmt->execute();    // subject NULL (H1)
$stmt->close();

// --- assessment + grades (C1 resave case) -----------------------------------
$eduId = (int)$conn->query("SELECT id FROM users WHERE username = 'audit_edu' LIMIT 1")->fetch_assoc()['id'];
$stmt = $conn->prepare(
    "INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type,
                              weight_percentage, max_score, assessment_order, is_published, created_by)
     VALUES (?, ?, ?, ?, 'Quiz 1', 'quiz', 100.00, 100.00, 1, 0, ?)");
$stmt->bind_param('iiiii', $c1, $bible, $yearId, $termId, $eduId);
$stmt->execute();
$assessmentId = (int)$stmt->insert_id;
$stmt->close();

// one existing score row (member 900000) WITHOUT record_id known by the UI
// (NB: academic_records.assessment_type ENUM has no 'quiz' — use the default)
$stmt = $conn->prepare(
    "INSERT INTO academic_records (member_id, class_id, subject_id, academic_year_id, term_id, assessment_id,
                                   score, max_score, remarks, recorded_by)
     VALUES (900000, ?, ?, ?, ?, ?, 55.00, 100.00, 'first save', ?)
     ON DUPLICATE KEY UPDATE score = VALUES(score)");
$stmt->bind_param('iiiiii', $c1, $bible, $yearId, $termId, $assessmentId, $eduId);
$stmt->execute();
$stmt->close();

while (ob_get_level() > 0) { @ob_end_flush(); }
fwrite(STDERR, json_encode([
    'status' => 'success',
    'year_id' => $yearId,
    'term_id' => $termId,
    'class_ids' => $classIds,
    'subject_ids' => $subjectIds,
    'teacher_user_id' => $teacherId,
    'assessment_id' => $assessmentId,
    'password' => 'AuditTest#2026',
], JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
