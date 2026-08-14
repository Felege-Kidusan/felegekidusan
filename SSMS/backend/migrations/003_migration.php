<?php
/**
 * Migration 003 - Add Assessments and Class-Subject Assignment Tables
 * Run: {SITE_URL}/admin/migrations/003_add_assessments.php
 */

require_once __DIR__ . '/../config.php';

// Session already started by config.php
$allowedRoles = ['super_admin', 'school_admin'];
$hasAccess = (!empty($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], $allowedRoles));

if (!$hasAccess) {
    die('Access denied. Login as Super Admin or School Admin to run migrations.');
}

$results = [];
$errors = [];

// ============================================================
// 1. CLASS-SUBJECTS ASSIGNMENT TABLE
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `class_subjects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_class_subject` (`class_id`, `subject_id`),
    KEY `class_id` (`class_id`),
    KEY `subject_id` (`subject_id`),
    CONSTRAINT `fk_cs_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cs_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: class_subjects";
} else {
    $errors[] = "❌ Failed: class_subjects - " . $conn->error;
}

// ============================================================
// 2. ASSESSMENTS TABLE (Tests, Finals, Assignments with weights)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `assessments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `term_id` INT UNSIGNED DEFAULT NULL,
    `assessment_name` VARCHAR(100) NOT NULL COMMENT 'e.g., Quiz 1, Midterm, Final Exam',
    `assessment_type` ENUM('test', 'quiz', 'midterm', 'final', 'assignment', 'project', 'participation', 'other') NOT NULL DEFAULT 'test',
    `weight_percentage` DECIMAL(5,2) NOT NULL COMMENT 'Weight in total grade, e.g., 10.00 for 10%',
    `max_score` DECIMAL(6,2) NOT NULL DEFAULT 100.00 COMMENT 'Maximum possible score',
    `description` TEXT DEFAULT NULL,
    `due_date` DATE DEFAULT NULL,
    `assessment_order` TINYINT UNSIGNED DEFAULT 1 COMMENT 'Display order',
    `is_published` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether grades are published to students',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `class_id` (`class_id`),
    KEY `subject_id` (`subject_id`),
    KEY `academic_year_id` (`academic_year_id`),
    KEY `term_id` (`term_id`),
    CONSTRAINT `fk_assess_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assess_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assess_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assess_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: assessments";
} else {
    $errors[] = "❌ Failed: assessments - " . $conn->error;
}

// ============================================================
// 3. ADD assessment_id TO academic_records
// ============================================================
$checkCol = $conn->query("SHOW COLUMNS FROM academic_records LIKE 'assessment_id'");
if ($checkCol->num_rows === 0) {
    $sql = "ALTER TABLE academic_records ADD COLUMN `assessment_id` INT UNSIGNED DEFAULT NULL AFTER `term_id`,
            ADD KEY `assessment_id` (`assessment_id`)";
    if ($conn->query($sql)) {
        $results[] = "✅ Added column: academic_records.assessment_id";
    } else {
        $errors[] = "❌ Failed adding assessment_id: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ Column already exists: assessment_id";
}

// ============================================================
// 4. ADD teacher_user_id TO users (for teacher accounts)
// ============================================================
$checkCol = $conn->query("SHOW COLUMNS FROM users LIKE 'member_id'");
if ($checkCol->num_rows === 0) {
    $sql = "ALTER TABLE users ADD COLUMN `member_id` INT UNSIGNED DEFAULT NULL COMMENT 'Link to member if user is a teacher',
            ADD KEY `member_id` (`member_id`)";
    if ($conn->query($sql)) {
        $results[] = "✅ Added column: users.member_id";
    } else {
        $errors[] = "❌ Failed adding member_id: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ Column already exists: users.member_id";
}

// ============================================================
// 5. ADD 'teacher' and 'attendance_taker' TO users.role ENUM
// ============================================================
// Check current enum values
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
$row = $result->fetch_assoc();
$currentType = $row['Type'];

if (strpos($currentType, 'teacher') === false) {
    $sql = "ALTER TABLE users MODIFY COLUMN `role` ENUM('super_admin', 'school_admin', 'info_dept', 'edu_dept', 'finance_dept', 'material_dept', 'teacher', 'attendance_taker') NOT NULL DEFAULT 'info_dept'";
    if ($conn->query($sql)) {
        $results[] = "✅ Updated users.role to include 'teacher' and 'attendance_taker'";
    } else {
        $errors[] = "❌ Failed updating role enum: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ Role enum already has teacher/attendance_taker";
}

// ============================================================
// 6. ASSIGN ALL SUBJECTS TO ALL CLASSES (Default setup)
// ============================================================
$classResult = $conn->query("SELECT id FROM classes WHERE is_active = 1");
$subjectResult = $conn->query("SELECT id FROM subjects WHERE is_active = 1");

$classes = [];
$subjects = [];
while ($c = $classResult->fetch_assoc()) $classes[] = $c['id'];
while ($s = $subjectResult->fetch_assoc()) $subjects[] = $s['id'];

$inserted = 0;
$stmt = $conn->prepare("INSERT IGNORE INTO class_subjects (class_id, subject_id) VALUES (?, ?)");
foreach ($classes as $cid) {
    foreach ($subjects as $sid) {
        $stmt->bind_param("ii", $cid, $sid);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $inserted++;
        }
    }
}
if ($inserted > 0) {
    $results[] = "✅ Assigned $inserted subject-class combinations";
}

// ============================================================
// OUTPUT
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Migration 003</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #7c3aed; margin-bottom: 8px; }
        .subtitle { color: #666; margin-bottom: 24px; }
        .result { padding: 8px 12px; margin: 4px 0; border-radius: 6px; font-family: monospace; font-size: 14px; }
        .success { background: #ecfdf5; color: #065f46; }
        .error { background: #fef2f2; color: #991b1b; }
        .info { background: #eff6ff; color: #1e40af; }
        .summary { margin-top: 24px; padding: 16px; background: #f0fdf4; border-radius: 8px; border: 1px solid #86efac; }
        .summary.has-errors { background: #fef2f2; border-color: #fca5a5; }
        a { color: #7c3aed; }
    </style>
</head>
<body>
    <div class="card">
        <h1>📊 Migration 003 Complete</h1>
        <p class="subtitle">Assessment System & Teacher Roles</p>
        
        <h3>Results:</h3>
        <?php foreach ($results as $r): ?>
            <div class="result <?= strpos($r, '✅') !== false ? 'success' : 'info' ?>"><?= $r ?></div>
        <?php endforeach; ?>
        
        <?php foreach ($errors as $e): ?>
            <div class="result error"><?= $e ?></div>
        <?php endforeach; ?>
        
        <div class="summary <?= count($errors) > 0 ? 'has-errors' : '' ?>">
            <?php if (count($errors) === 0): ?>
                <strong>✅ Migration successful!</strong><br>
                <p>New features available:</p>
                <ul>
                    <li>Subject-Class assignments</li>
                    <li>Assessment configuration (tests, finals, etc. with % weights)</li>
                    <li>Teacher user role</li>
                    <li>Attendance taker user role</li>
                </ul>
            <?php else: ?>
                <strong>⚠️ Some errors occurred.</strong>
            <?php endif; ?>
        </div>
        
        <p style="margin-top: 20px;"><a href="../dashboard.php">← Back to Dashboard</a></p>
    </div>
</body>
</html>
<?php $conn->close(); ?>
