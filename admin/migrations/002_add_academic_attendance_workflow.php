<?php
/**
 * ============================================================
 * School Management School Management System - Migration 002
 * ============================================================
 * 
 * This migration adds tables for:
 * 1. Academic Years & Terms
 * 2. Classes/Grades (Spiritual Education Levels)
 * 3. Academic Records (Grades/Scores)
 * 4. Attendance Tracking
 * 5. System Notifications
 * 6. Department Activity Log (for cross-dept updates)
 * 7. Tasks & Assignments
 * 8. Shared Documents
 * 
 * Run this file once in your browser:
 * {SITE_URL}/admin/migrations/002_add_academic_attendance_workflow.php
 * ============================================================
 */

require_once __DIR__ . '/../config.php';

// Only super_admin can run migrations (session already started by config.php)
if (empty($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'super_admin') {
    die('Access denied. Only Super Admin can run migrations.');
}

$results = [];
$errors = [];

// ============================================================
// 1. ACADEMIC YEARS TABLE
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `academic_years` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `year_name` VARCHAR(50) NOT NULL COMMENT 'e.g., 2017 ዓ.ም.',
    `year_gc` VARCHAR(20) DEFAULT NULL COMMENT 'Gregorian equivalent, e.g., 2024/2025',
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('upcoming', 'active', 'closed') NOT NULL DEFAULT 'upcoming',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `year_name` (`year_name`),
    KEY `is_current` (`is_current`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: academic_years";
} else {
    $errors[] = "❌ Failed to create academic_years: " . $conn->error;
}

// ============================================================
// 2. TERMS/SEMESTERS TABLE
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `academic_terms` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `term_name` VARCHAR(50) NOT NULL COMMENT 'e.g., 1ኛ ሴሚስተር, 2ኛ ሴሚስተር',
    `term_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `academic_year_id` (`academic_year_id`),
    KEY `is_current` (`is_current`),
    CONSTRAINT `fk_term_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: academic_terms";
} else {
    $errors[] = "❌ Failed to create academic_terms: " . $conn->error;
}

// ============================================================
// 3. CLASSES/GRADES TABLE (Spiritual Education Levels)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `classes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_name` VARCHAR(100) NOT NULL COMMENT 'e.g., 1ኛ ክፍል, 2ኛ ክፍል',
    `class_name_en` VARCHAR(100) DEFAULT NULL COMMENT 'e.g., Grade 1, Grade 2',
    `class_code` VARCHAR(20) NOT NULL COMMENT 'e.g., grade_1, grade_2',
    `level_order` TINYINT UNSIGNED NOT NULL COMMENT 'Ordering: 1, 2, 3...',
    `section` VARCHAR(50) DEFAULT NULL COMMENT 'Which section this belongs to',
    `age_group` ENUM('under6', '7_13', '14_17', '18_plus') DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `class_code` (`class_code`),
    KEY `level_order` (`level_order`),
    KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: classes";
    
    // Insert default classes
    $defaultClasses = [
        ['1ኛ ክፍል', 'Grade 1', 'grade_1', 1, 'ህጻናት', '7_13'],
        ['2ኛ ክፍል', 'Grade 2', 'grade_2', 2, 'ህጻናት', '7_13'],
        ['3ኛ ክፍል', 'Grade 3', 'grade_3', 3, 'ህጻናት', '7_13'],
        ['4ኛ ክፍል', 'Grade 4', 'grade_4', 4, 'ህጻናት', '7_13'],
        ['5ኛ ክፍል', 'Grade 5', 'grade_5', 5, 'ማዕከላዊያን', '14_17'],
        ['6ኛ ክፍል', 'Grade 6', 'grade_6', 6, 'ማዕከላዊያን', '14_17'],
        ['7ኛ ክፍል', 'Grade 7', 'grade_7', 7, 'ማዕከላዊያን', '14_17'],
        ['8ኛ ክፍል', 'Grade 8', 'grade_8', 8, 'ወጣቶች', '18_plus'],
        ['9ኛ ክፍል', 'Grade 9', 'grade_9', 9, 'ወጣቶች', '18_plus'],
        ['10ኛ ክፍል', 'Grade 10', 'grade_10', 10, 'ወጣቶች', '18_plus'],
        ['11ኛ ክፍል', 'Grade 11', 'grade_11', 11, 'ወጣቶች', '18_plus'],
        ['12ኛ ክፍል', 'Grade 12', 'grade_12', 12, 'ወጣቶች', '18_plus'],
        ['ዲፕሎማ', 'Diploma', 'diploma', 13, 'ወጣቶች', '18_plus'],
        ['ዲግሪ', 'Degree', 'degree', 14, 'ወጣቶች', '18_plus'],
    ];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO classes (class_name, class_name_en, class_code, level_order, section, age_group) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($defaultClasses as $c) {
        $stmt->bind_param("sssiss", $c[0], $c[1], $c[2], $c[3], $c[4], $c[5]);
        $stmt->execute();
    }
    $results[] = "✅ Inserted default classes";
} else {
    $errors[] = "❌ Failed to create classes: " . $conn->error;
}

// ============================================================
// 4. SUBJECTS TABLE
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_name` VARCHAR(100) NOT NULL COMMENT 'e.g., መጽሐፍ ቅዱስ, ቅዳሴ',
    `subject_name_en` VARCHAR(100) DEFAULT NULL,
    `subject_code` VARCHAR(20) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `subject_code` (`subject_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: subjects";
    
    // Insert default subjects
    $defaultSubjects = [
        ['መጽሐፍ ቅዱስ', 'Holy Bible', 'bible'],
        ['ቅዳሴ', 'Liturgy', 'liturgy'],
        ['ታሪክ', 'Church History', 'history'],
        ['ስነ ምግባር', 'Ethics', 'ethics'],
        ['ዜማ', 'Church Music', 'music'],
        ['ቋንቋ ግዕዝ', 'Geez Language', 'geez'],
        ['ትርጓሜ', 'Interpretation', 'interpretation'],
    ];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO subjects (subject_name, subject_name_en, subject_code) VALUES (?, ?, ?)");
    foreach ($defaultSubjects as $s) {
        $stmt->bind_param("sss", $s[0], $s[1], $s[2]);
        $stmt->execute();
    }
    $results[] = "✅ Inserted default subjects";
} else {
    $errors[] = "❌ Failed to create subjects: " . $conn->error;
}

// ============================================================
// 5. CLASS ENROLLMENTS (Which member is in which class)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `class_enrollments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `class_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `enrolled_at` DATE NOT NULL,
    `status` ENUM('active', 'completed', 'dropped', 'transferred') NOT NULL DEFAULT 'active',
    `promoted_from` INT UNSIGNED DEFAULT NULL COMMENT 'Previous class ID if promoted',
    `notes` TEXT DEFAULT NULL,
    `enrolled_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_enrollment` (`member_id`, `class_id`, `academic_year_id`),
    KEY `member_id` (`member_id`),
    KEY `class_id` (`class_id`),
    KEY `academic_year_id` (`academic_year_id`),
    KEY `status` (`status`),
    CONSTRAINT `fk_enroll_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_enroll_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_enroll_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: class_enrollments";
} else {
    $errors[] = "❌ Failed to create class_enrollments: " . $conn->error;
}

// ============================================================
// 6. TEACHER ASSIGNMENTS (Which teacher teaches which class/subject)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `teacher_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id` INT UNSIGNED NOT NULL COMMENT 'Member ID who is teaching',
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED DEFAULT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `is_class_teacher` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Main teacher for this class',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `assigned_by` INT UNSIGNED DEFAULT NULL,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `teacher_id` (`teacher_id`),
    KEY `class_id` (`class_id`),
    KEY `subject_id` (`subject_id`),
    KEY `academic_year_id` (`academic_year_id`),
    CONSTRAINT `fk_ta_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ta_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ta_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ta_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: teacher_assignments";
} else {
    $errors[] = "❌ Failed to create teacher_assignments: " . $conn->error;
}

// ============================================================
// 7. ACADEMIC RECORDS (Grades/Scores)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `academic_records` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `term_id` INT UNSIGNED DEFAULT NULL,
    `assessment_type` ENUM('test', 'midterm', 'final', 'assignment', 'participation', 'project') NOT NULL DEFAULT 'test',
    `score` DECIMAL(5,2) DEFAULT NULL COMMENT 'Score out of max_score',
    `max_score` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    `grade_letter` VARCHAR(5) DEFAULT NULL COMMENT 'A, B, C, D, F',
    `remarks` TEXT DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `member_id` (`member_id`),
    KEY `class_id` (`class_id`),
    KEY `subject_id` (`subject_id`),
    KEY `academic_year_id` (`academic_year_id`),
    KEY `term_id` (`term_id`),
    CONSTRAINT `fk_ar_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ar_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ar_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ar_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ar_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: academic_records";
} else {
    $errors[] = "❌ Failed to create academic_records: " . $conn->error;
}

// ============================================================
// 8. ATTENDANCE TABLE
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `class_id` INT UNSIGNED DEFAULT NULL,
    `academic_year_id` INT UNSIGNED DEFAULT NULL,
    `attendance_date` DATE NOT NULL,
    `status` ENUM('present', 'absent', 'late', 'excused', 'holiday') NOT NULL DEFAULT 'present',
    `check_in_time` TIME DEFAULT NULL,
    `check_out_time` TIME DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_attendance` (`member_id`, `attendance_date`),
    KEY `attendance_date` (`attendance_date`),
    KEY `status` (`status`),
    KEY `class_id` (`class_id`),
    KEY `academic_year_id` (`academic_year_id`),
    CONSTRAINT `fk_att_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_att_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_att_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: attendance";
} else {
    $errors[] = "❌ Failed to create attendance: " . $conn->error;
}

// ============================================================
// 9. ATTENDANCE SUMMARY (Monthly/Yearly aggregates for fast queries)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `attendance_summary` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED DEFAULT NULL,
    `month` TINYINT UNSIGNED DEFAULT NULL COMMENT 'Ethiopian month 1-13',
    `year` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Ethiopian year',
    `total_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `present_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `absent_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `late_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `excused_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `attendance_rate` DECIMAL(5,2) DEFAULT NULL COMMENT 'Percentage',
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_summary` (`member_id`, `academic_year_id`, `month`, `year`),
    KEY `member_id` (`member_id`),
    KEY `academic_year_id` (`academic_year_id`),
    CONSTRAINT `fk_as_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_as_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: attendance_summary";
} else {
    $errors[] = "❌ Failed to create attendance_summary: " . $conn->error;
}

// ============================================================
// 10. SYSTEM NOTIFICATIONS (Cross-department alerts)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(50) NOT NULL COMMENT 'member_registered, teacher_assigned, grade_updated, etc.',
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `data` JSON DEFAULT NULL COMMENT 'Additional data like member_id, changes made',
    `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    `source_dept` VARCHAR(50) DEFAULT NULL COMMENT 'Department that triggered this',
    `source_user_id` INT UNSIGNED DEFAULT NULL,
    `target_roles` VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated roles: super_admin,school_admin,info_dept',
    `target_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Specific user if not for roles',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `type` (`type`),
    KEY `target_roles` (`target_roles`),
    KEY `target_user_id` (`target_user_id`),
    KEY `is_read` (`is_read`),
    KEY `created_at` (`created_at`),
    KEY `source_user_id` (`source_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: notifications";
} else {
    $errors[] = "❌ Failed to create notifications: " . $conn->error;
}

// ============================================================
// 11. DEPARTMENT TASKS (Cross-department assignments)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `department_tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `task_type` VARCHAR(50) DEFAULT NULL COMMENT 'approval, review, action, info',
    `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `from_dept` VARCHAR(50) NOT NULL COMMENT 'Originating department',
    `from_user_id` INT UNSIGNED DEFAULT NULL,
    `to_dept` VARCHAR(50) DEFAULT NULL COMMENT 'Target department',
    `to_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Specific assignee',
    `related_member_id` INT UNSIGNED DEFAULT NULL COMMENT 'If task is about a member',
    `related_data` JSON DEFAULT NULL COMMENT 'Additional context',
    `due_date` DATE DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `completed_by` INT UNSIGNED DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `status` (`status`),
    KEY `from_dept` (`from_dept`),
    KEY `to_dept` (`to_dept`),
    KEY `to_user_id` (`to_user_id`),
    KEY `related_member_id` (`related_member_id`),
    KEY `due_date` (`due_date`),
    CONSTRAINT `fk_task_member` FOREIGN KEY (`related_member_id`) REFERENCES `members`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: department_tasks";
} else {
    $errors[] = "❌ Failed to create department_tasks: " . $conn->error;
}

// ============================================================
// 12. SHARED DOCUMENTS
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `shared_documents` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_type` VARCHAR(50) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT NULL COMMENT 'Size in bytes',
    `category` VARCHAR(50) DEFAULT NULL COMMENT 'report, form, announcement, policy',
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `uploaded_dept` VARCHAR(50) DEFAULT NULL,
    `visibility` ENUM('all', 'departments_only', 'specific') NOT NULL DEFAULT 'all',
    `visible_to` VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated dept codes if specific',
    `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `category` (`category`),
    KEY `uploaded_dept` (`uploaded_dept`),
    KEY `visibility` (`visibility`),
    KEY `is_pinned` (`is_pinned`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: shared_documents";
} else {
    $errors[] = "❌ Failed to create shared_documents: " . $conn->error;
}

// ============================================================
// 13. MEMBER CHANGE LOG (Track all changes for department sync)
// ============================================================
$sql = "CREATE TABLE IF NOT EXISTS `member_changes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `change_type` VARCHAR(50) NOT NULL COMMENT 'registered, updated, archived, role_changed, class_enrolled, promoted',
    `field_changed` VARCHAR(100) DEFAULT NULL COMMENT 'Specific field that changed',
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `change_summary` VARCHAR(255) DEFAULT NULL COMMENT 'Human readable summary',
    `changed_by_dept` VARCHAR(50) DEFAULT NULL,
    `changed_by_user` INT UNSIGNED DEFAULT NULL,
    `requires_sync` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Does this need other depts to be notified',
    `synced_to` VARCHAR(255) DEFAULT NULL COMMENT 'Which depts have acknowledged',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `member_id` (`member_id`),
    KEY `change_type` (`change_type`),
    KEY `changed_by_dept` (`changed_by_dept`),
    KEY `requires_sync` (`requires_sync`),
    KEY `created_at` (`created_at`),
    CONSTRAINT `fk_mc_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $results[] = "✅ Created table: member_changes";
} else {
    $errors[] = "❌ Failed to create member_changes: " . $conn->error;
}

// ============================================================
// 14. ADD NEW COLUMNS TO MEMBERS TABLE
// ============================================================
$memberColumns = [
    ['current_class_id', 'INT UNSIGNED DEFAULT NULL COMMENT "Current enrolled class"'],
    ['promoted_at', 'DATE DEFAULT NULL COMMENT "Last promotion date"'],
    ['spiritual_level_id', 'INT UNSIGNED DEFAULT NULL COMMENT "Current spiritual education level"'],
    ['total_attendance_rate', 'DECIMAL(5,2) DEFAULT NULL COMMENT "Overall attendance percentage"'],
    ['last_attendance_date', 'DATE DEFAULT NULL'],
    ['academic_status', "ENUM('active', 'on_hold', 'graduated', 'dropped') DEFAULT 'active'"],
];

foreach ($memberColumns as $col) {
    $checkSql = "SHOW COLUMNS FROM members LIKE '{$col[0]}'";
    $result = $conn->query($checkSql);
    if ($result->num_rows === 0) {
        $alterSql = "ALTER TABLE members ADD COLUMN {$col[0]} {$col[1]}";
        if ($conn->query($alterSql)) {
            $results[] = "✅ Added column to members: {$col[0]}";
        } else {
            $errors[] = "❌ Failed to add column {$col[0]}: " . $conn->error;
        }
    } else {
        $results[] = "ℹ️ Column already exists: {$col[0]}";
    }
}

// ============================================================
// 15. INSERT DEFAULT ACADEMIC YEAR
// ============================================================
$checkYear = $conn->query("SELECT id FROM academic_years WHERE year_name = '2017 ዓ.ም.'");
if ($checkYear->num_rows === 0) {
    $stmt = $conn->prepare("INSERT INTO academic_years (year_name, year_gc, is_current, status, created_by) VALUES (?, ?, 1, 'active', ?)");
    $yearName = '2017 ዓ.ም.';
    $yearGc = '2024/2025';
    $userId = $_SESSION['admin_id'] ?? 1;
    $stmt->bind_param("ssi", $yearName, $yearGc, $userId);
    if ($stmt->execute()) {
        $results[] = "✅ Created default academic year: 2017 ዓ.ም.";
        
        // Add terms
        $yearId = $conn->insert_id;
        $terms = [
            ['1ኛ ሴሚስተር', 1],
            ['2ኛ ሴሚስተር', 2],
        ];
        $termStmt = $conn->prepare("INSERT INTO academic_terms (academic_year_id, term_name, term_number, is_current) VALUES (?, ?, ?, ?)");
        foreach ($terms as $i => $t) {
            $isCurrent = ($i === 0) ? 1 : 0;
            $termStmt->bind_param("isii", $yearId, $t[0], $t[1], $isCurrent);
            $termStmt->execute();
        }
        $results[] = "✅ Created default terms for academic year";
    }
} else {
    $results[] = "ℹ️ Default academic year already exists";
}

// ============================================================
// DISPLAY RESULTS
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Migration 002</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #059669; margin-bottom: 8px; }
        .subtitle { color: #666; margin-bottom: 24px; }
        .result { padding: 8px 12px; margin: 4px 0; border-radius: 6px; font-family: monospace; font-size: 14px; }
        .success { background: #ecfdf5; color: #065f46; }
        .error { background: #fef2f2; color: #991b1b; }
        .info { background: #eff6ff; color: #1e40af; }
        .summary { margin-top: 24px; padding: 16px; background: #f0fdf4; border-radius: 8px; border: 1px solid #86efac; }
        .summary.has-errors { background: #fef2f2; border-color: #fca5a5; }
        a { color: #059669; }
    </style>
</head>
<body>
    <div class="card">
        <h1>📊 Migration 002 Complete</h1>
        <p class="subtitle">Academic Records, Attendance & Department Workflow Tables</p>
        
        <h3>Results:</h3>
        <?php foreach ($results as $r): ?>
            <div class="result <?= strpos($r, '✅') !== false ? 'success' : 'info' ?>"><?= $r ?></div>
        <?php endforeach; ?>
        
        <?php foreach ($errors as $e): ?>
            <div class="result error"><?= $e ?></div>
        <?php endforeach; ?>
        
        <div class="summary <?= count($errors) > 0 ? 'has-errors' : '' ?>">
            <?php if (count($errors) === 0): ?>
                <strong>✅ All tables created successfully!</strong><br>
                <p>New features available:</p>
                <ul>
                    <li>Academic Years & Terms management</li>
                    <li>Class enrollments & promotions</li>
                    <li>Teacher assignments</li>
                    <li>Grade/Score recording</li>
                    <li>Attendance tracking</li>
                    <li>Cross-department notifications</li>
                    <li>Task assignments between departments</li>
                    <li>Document sharing</li>
                    <li>Member change tracking for department sync</li>
                </ul>
            <?php else: ?>
                <strong>⚠️ Some errors occurred. Please check above.</strong>
            <?php endif; ?>
        </div>
        
        <p style="margin-top: 20px;"><a href="../dashboard.php">← Back to Dashboard</a></p>
    </div>
</body>
</html>
<?php
$conn->close();
?>
