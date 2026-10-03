<?php
/**
 * Test harness: the four Academic Intelligence perspectives, executed
 * against a real MariaDB through the real services.
 *
 * Nothing here reimplements an academic calculation. The harness seeds a
 * small but realistic school, then asks the real
 * App\Services\ReportCardService and App\Services\AcademicIntelligenceService
 * what they see. The central claim of the feature -- that the four
 * perspectives are four projections of ONE calculation engine, not four
 * engines -- is checked by comparing the perspective output against
 * ReportCardService's own authoritative answer for the same cell.
 *
 * COMMAND LINE ONLY, and destructive: it creates and drops its own tables.
 * It therefore refuses to run without the audit interlock and works inside
 * a disposable database (ssms_e2e), never production.
 *
 * Usage:
 *   SSMS_AUDIT_TESTING=1 php tests/e2e/academic_intelligence.php <scenario|all>
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
require $root . '/admin/backend/services/ReportCardService.php';
require $root . '/admin/backend/services/AcademicIntelligenceService.php';

use App\Services\AcademicIntelligenceService;
use App\Services\ReportCardService;

$dbName = getenv('SSMS_SYNC_DB') ?: 'ssms_e2e';
ssms_require_disposable_database($dbName, 'academic_intelligence');

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
    if ($expected !== $actual) {
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

function check_true(string $label, $actual): void
{
    check($label, true, (bool)$actual);
}

// ── The seeded world ────────────────────────────────────────────────────
// Deliberately NOT symmetric, so a perspective that quietly returns
// "everything" instead of "the relevant slice" fails:
//
//   Year 2017 EC, weights 40/60 (never the 50/50 default, so a hard-coded
//   weight is visible), terms T1 and T2.
//
//   Class C1 (4th grade): subjects GEEZ (FULL_YEAR) + MUSIC (SEMESTER_ONLY,
//                         term 1). 4 students.
//   Class C2 (5th grade): subjects GEEZ (FULL_YEAR) + HISTORY (unclassified).
//                         3 students.
//   Class C3 (6th grade): no subjects, no students -- the empty case.
//
//   Teacher BEKELE teaches GEEZ in C1 and C2.
//   Teacher ALMAZ  teaches MUSIC in C1 only.
//   Teacher KEBEDE teaches nothing (no assignments).
//
// So: GEEZ spans two classes, MUSIC one, HISTORY one; BEKELE spans two
// classes, ALMAZ one, KEBEDE none.
const Y1 = 1;
const T1 = 1;
const T2 = 2;
const C1 = 1;
const C2 = 2;
const C3 = 3;
const S_GEEZ = 1;
const S_MUSIC = 2;
const S_HIST = 3;
const U_BEKELE = 11;
const U_ALMAZ = 12;
const U_KEBEDE = 13;
// `members` rows for the two teachers who have one. Numbered well clear of
// the student block (101-203) so a mix-up is obvious rather than subtle.
const M_BEKELE = 901;
const M_ALMAZ = 902;

function rebuild(mysqli $conn): void
{
    $drop = [
        'academic_records', 'assessments', 'attendance', 'class_enrollments',
        'class_subjects', 'teacher_assignments', 'grade_submissions',
        'academic_terms', 'academic_years', 'classes', 'subjects', 'members', 'users',
    ];
    foreach ($drop as $t) {
        $conn->query("DROP TABLE IF EXISTS `{$t}`");
    }

    $conn->query("CREATE TABLE `academic_years` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `year_name` VARCHAR(100) NOT NULL,
        `year_gc` VARCHAR(20) DEFAULT NULL,
        `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
        `start_date` DATE DEFAULT NULL,
        `end_date` DATE DEFAULT NULL,
        `is_current` TINYINT(1) NOT NULL DEFAULT 0,
        `status` VARCHAR(20) DEFAULT 'active',
        `s1_weight_pct` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
        `s2_weight_pct` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `academic_terms` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `academic_year_id` INT UNSIGNED NOT NULL,
        `term_name` VARCHAR(50) NOT NULL,
        `term_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `start_date` DATE DEFAULT NULL,
        `end_date` DATE DEFAULT NULL,
        `is_current` TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`), KEY (`academic_year_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `classes` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `class_name` VARCHAR(150) NOT NULL,
        `class_name_en` VARCHAR(150) DEFAULT NULL,
        `class_code` VARCHAR(30) NOT NULL,
        `level_order` INT NOT NULL DEFAULT 0,
        `section` VARCHAR(50) DEFAULT NULL,
        `age_group` VARCHAR(20) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `subjects` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `subject_name` VARCHAR(150) NOT NULL,
        `subject_name_en` VARCHAR(150) DEFAULT NULL,
        `subject_code` VARCHAR(30) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // duration_type / term_id are the migration 056 columns.
    $conn->query("CREATE TABLE `class_subjects` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `class_id` INT UNSIGNED NOT NULL,
        `subject_id` INT UNSIGNED NOT NULL,
        `duration_type` VARCHAR(20) DEFAULT NULL,
        `term_id` INT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY (`class_id`,`subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `members` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `member_code` VARCHAR(50) DEFAULT NULL,
        `student_name` VARCHAR(150) NOT NULL,
        `father_name` VARCHAR(150) DEFAULT NULL,
        `grandfather_name` VARCHAR(150) DEFAULT NULL,
        `baptismal_name` VARCHAR(150) DEFAULT NULL,
        `gender` VARCHAR(10) DEFAULT NULL,
        `age_group` VARCHAR(20) DEFAULT NULL,
        `date_of_birth` DATE DEFAULT NULL,
        `age` INT DEFAULT NULL,
        `education_level` VARCHAR(50) DEFAULT NULL,
        `member_type` VARCHAR(30) DEFAULT 'regular',
        `phone_number` VARCHAR(30) DEFAULT NULL,
        `is_teacher` TINYINT(1) DEFAULT 0,
        `is_staff` TINYINT(1) DEFAULT 0,
        `is_committee` TINYINT(1) DEFAULT 0,
        `is_volunteer` TINYINT(1) DEFAULT 0,
        `student_photo_path` VARCHAR(255) DEFAULT NULL,
        `status` VARCHAR(20) DEFAULT 'active',
        `current_section` VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `class_enrollments` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `member_id` INT UNSIGNED NOT NULL,
        `class_id` INT UNSIGNED NOT NULL,
        `academic_year_id` INT UNSIGNED NOT NULL,
        `enrolled_at` DATE DEFAULT NULL,
        `status` ENUM('active','withdrawn','completed','transferred') NOT NULL DEFAULT 'active',
        `notes` TEXT DEFAULT NULL,
        PRIMARY KEY (`id`), KEY (`class_id`), KEY (`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(100) NOT NULL,
        `full_name` VARCHAR(150) DEFAULT NULL,
        `email` VARCHAR(150) DEFAULT NULL,
        `role` VARCHAR(40) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        -- Staff identity is split across two tables in production: `users` is
        -- the login, `members` is the person. sql/012_runtime_schema_baseline
        -- adds this nullable link AFTER `is_active`, and list_teachers reads
        -- the teacher's member_code and phone through it. It is NULLABLE on
        -- purpose: a login need not correspond to a registered member, and
        -- the LEFT JOIN has to survive that.
        `member_id` INT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (`id`), KEY (`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `teacher_assignments` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `teacher_id` INT UNSIGNED NOT NULL,
        `class_id` INT UNSIGNED NOT NULL,
        `subject_id` INT UNSIGNED DEFAULT NULL,
        `academic_year_id` INT UNSIGNED DEFAULT NULL,
        `is_class_teacher` TINYINT(1) NOT NULL DEFAULT 0,
        `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `assignment_role` ENUM('primary','assistant','homeroom') NOT NULL DEFAULT 'primary',
        PRIMARY KEY (`id`), KEY (`teacher_id`), KEY (`class_id`), KEY (`subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `assessments` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `class_id` INT UNSIGNED NOT NULL,
        `subject_id` INT UNSIGNED NOT NULL,
        `academic_year_id` INT UNSIGNED NOT NULL,
        `term_id` INT UNSIGNED DEFAULT NULL,
        `assessment_name` VARCHAR(100) NOT NULL,
        `assessment_type` VARCHAR(30) NOT NULL DEFAULT 'test',
        `max_score` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
        `weight_percentage` DECIMAL(5,2) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`), KEY (`class_id`), KEY (`subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `academic_records` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `member_id` INT UNSIGNED NOT NULL,
        `class_id` INT UNSIGNED DEFAULT NULL,
        `subject_id` INT UNSIGNED DEFAULT NULL,
        `assessment_id` INT UNSIGNED DEFAULT NULL,
        `academic_year_id` INT UNSIGNED DEFAULT NULL,
        `term_id` INT UNSIGNED DEFAULT NULL,
        `score` DECIMAL(6,2) DEFAULT NULL,
        `max_score` DECIMAL(6,2) DEFAULT NULL,
        `remarks` VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (`id`), KEY (`member_id`), KEY (`class_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `attendance` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `member_id` INT UNSIGNED NOT NULL,
        `class_id` INT UNSIGNED NOT NULL,
        `academic_year_id` INT UNSIGNED DEFAULT NULL,
        `term_id` INT UNSIGNED DEFAULT NULL,
        `date` DATE DEFAULT NULL,
        `status` ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
        `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY (`class_id`), KEY (`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE `grade_submissions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `teacher_id` INT UNSIGNED NOT NULL,
        `class_id` INT UNSIGNED NOT NULL,
        `subject_id` INT UNSIGNED DEFAULT 0,
        `academic_year_id` INT UNSIGNED DEFAULT NULL,
        `term_id` INT UNSIGNED DEFAULT NULL,
        `assessment_id` INT UNSIGNED DEFAULT NULL,
        `submission_type` VARCHAR(20) NOT NULL DEFAULT 'marklist',
        `status` VARCHAR(20) NOT NULL DEFAULT 'incomplete',
        `student_count` INT UNSIGNED DEFAULT 0,
        `average_score` DECIMAL(5,2) DEFAULT NULL,
        `submitted_at` TIMESTAMP NULL DEFAULT NULL,
        `reviewed_by` INT UNSIGNED DEFAULT NULL,
        `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
        `review_notes` TEXT DEFAULT NULL,
        PRIMARY KEY (`id`), KEY (`class_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seed(mysqli $conn): void
{
    // 40/60 weights: a full-year annual score is NOT the mean of S1 and S2.
    $conn->query("INSERT INTO academic_years (id, year_name, ec_year, is_current, s1_weight_pct, s2_weight_pct)
                  VALUES (" . Y1 . ", '2017 E.C.', 2017, 1, 40.00, 60.00)");
    $conn->query("INSERT INTO academic_terms (id, academic_year_id, term_name, term_number, is_current) VALUES
                  (" . T1 . ", " . Y1 . ", 'Semester 1', 1, 0),
                  (" . T2 . ", " . Y1 . ", 'Semester 2', 2, 1)");

    $conn->query("INSERT INTO classes (id, class_name, class_name_en, class_code, level_order, is_active) VALUES
                  (" . C1 . ", '4ኛ ክፍል', 'Grade 4', 'G4', 4, 1),
                  (" . C2 . ", '5ኛ ክፍል', 'Grade 5', 'G5', 5, 1),
                  (" . C3 . ", '6ኛ ክፍል', 'Grade 6', 'G6', 6, 1)");

    $conn->query("INSERT INTO subjects (id, subject_name, subject_name_en, is_active) VALUES
                  (" . S_GEEZ . ", 'ግዕዝ', 'Geez', 1),
                  (" . S_MUSIC . ", 'ዝማሬ', 'Music', 1),
                  (" . S_HIST . ", 'ታሪክ', 'History', 1)");

    // GEEZ is full-year in both classes. MUSIC is semester-1-only in C1.
    // HISTORY in C2 is deliberately left unclassified (NULL duration).
    $conn->query("INSERT INTO class_subjects (class_id, subject_id, duration_type, term_id) VALUES
                  (" . C1 . ", " . S_GEEZ . ", 'FULL_YEAR', NULL),
                  (" . C1 . ", " . S_MUSIC . ", 'SEMESTER_ONLY', " . T1 . "),
                  (" . C2 . ", " . S_GEEZ . ", 'FULL_YEAR', NULL),
                  (" . C2 . ", " . S_HIST . ", NULL, NULL)");

    // Teachers are people before they are logins, so two of the three get a
    // `members` row and are linked through users.member_id -- the same shape
    // production has. KEBEDE is deliberately left unlinked (member_id NULL):
    // that is legal in production and it is the case that proves the
    // LEFT JOIN in list_teachers does not silently drop a teacher.
    $conn->query("INSERT INTO members (id, member_code, student_name, father_name, gender, status, is_teacher, phone_number) VALUES
                  (" . M_BEKELE . ", 'T-901', 'Bekele', 'Tadesse', 'male',   'active', 1, '0911000901'),
                  (" . M_ALMAZ . ",  'T-902', 'Almaz',  'Girma',   'female', 'active', 1, '0911000902')");

    $conn->query("INSERT INTO users (id, username, full_name, email, role, is_active, member_id) VALUES
                  (" . U_BEKELE . ", 'bekele', 'Bekele Tadesse', 'bekele@example.org', 'teacher', 1, " . M_BEKELE . "),
                  (" . U_ALMAZ . ", 'almaz', 'Almaz Girma', 'almaz@example.org', 'teacher', 1, " . M_ALMAZ . "),
                  (" . U_KEBEDE . ", 'kebede', 'Kebede Haile', 'kebede@example.org', 'teacher', 1, NULL)");

    $conn->query("INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, academic_year_id, is_active) VALUES
                  (" . U_BEKELE . ", " . C1 . ", " . S_GEEZ . ", " . Y1 . ", 1),
                  (" . U_BEKELE . ", " . C2 . ", " . S_GEEZ . ", " . Y1 . ", 1),
                  (" . U_ALMAZ . ", " . C1 . ", " . S_MUSIC . ", " . Y1 . ", 1)");

    // Students 101-104 in C1, 201-203 in C2.
    $members = [
        [101, 'M-101', 'Abebe', 'Kebede', 'male'],
        [102, 'M-102', 'Bethlehem', 'Girma', 'female'],
        [103, 'M-103', 'Chala', 'Negash', 'male'],
        [104, 'M-104', 'Dagmawit', 'Solomon', 'female'],
        [201, 'M-201', 'Eyob', 'Haile', 'male'],
        [202, 'M-202', 'Frehiwot', 'Bekele', 'female'],
        [203, 'M-203', 'Getachew', 'Alemu', 'male'],
    ];
    foreach ($members as $m) {
        $stmt = $conn->prepare("INSERT INTO members (id, member_code, student_name, father_name, baptismal_name, gender, status)
                                VALUES (?,?,?,?,?,?,'active')");
        $bap = 'Gebre' . $m[0];
        $stmt->bind_param('isssss', $m[0], $m[1], $m[2], $m[3], $bap, $m[4]);
        $stmt->execute();
        $stmt->close();
    }
    foreach ([101, 102, 103, 104] as $mid) {
        $conn->query("INSERT INTO class_enrollments (member_id, class_id, academic_year_id, status)
                      VALUES ({$mid}, " . C1 . ", " . Y1 . ", 'active')");
    }
    foreach ([201, 202, 203] as $mid) {
        $conn->query("INSERT INTO class_enrollments (member_id, class_id, academic_year_id, status)
                      VALUES ({$mid}, " . C2 . ", " . Y1 . ", 'active')");
    }

    // Assessments: GEEZ has one per semester in each class, MUSIC one in S1,
    // HISTORY one in S1. All out of 100, unweighted, so a subject's semester
    // score is simply the mark.
    $assess = [
        [1, C1, S_GEEZ, T1, 'Geez Midterm'],
        [2, C1, S_GEEZ, T2, 'Geez Final'],
        [3, C1, S_MUSIC, T1, 'Music Test'],
        [4, C2, S_GEEZ, T1, 'Geez Midterm'],
        [5, C2, S_GEEZ, T2, 'Geez Final'],
        [6, C2, S_HIST, T1, 'History Test'],
    ];
    foreach ($assess as $a) {
        $conn->query("INSERT INTO assessments (id, class_id, subject_id, academic_year_id, term_id, assessment_name, max_score)
                      VALUES ({$a[0]}, {$a[1]}, {$a[2]}, " . Y1 . ", {$a[3]}, '{$a[4]}', 100.00)");
    }

    // Marks. Student 104 has NO Geez semester-2 mark, so that student's
    // full-year Geez stays PENDING and must be excluded from the average
    // rather than counted as zero.
    $records = [
        // member, assessment, class, subject, term, score
        [101, 1, C1, S_GEEZ, T1, 80.0],
        [101, 2, C1, S_GEEZ, T2, 90.0],
        [101, 3, C1, S_MUSIC, T1, 70.0],
        [102, 1, C1, S_GEEZ, T1, 60.0],
        [102, 2, C1, S_GEEZ, T2, 50.0],
        [102, 3, C1, S_MUSIC, T1, 40.0],
        [103, 1, C1, S_GEEZ, T1, 30.0],
        [103, 2, C1, S_GEEZ, T2, 45.0],
        [103, 3, C1, S_MUSIC, T1, 95.0],
        [104, 1, C1, S_GEEZ, T1, 75.0],
        // 104: no Geez S2 mark on purpose
        [104, 3, C1, S_MUSIC, T1, 85.0],

        [201, 4, C2, S_GEEZ, T1, 55.0],
        [201, 5, C2, S_GEEZ, T2, 65.0],
        [201, 6, C2, S_HIST, T1, 88.0],
        [202, 4, C2, S_GEEZ, T1, 90.0],
        [202, 5, C2, S_GEEZ, T2, 95.0],
        [202, 6, C2, S_HIST, T1, 72.0],
        [203, 4, C2, S_GEEZ, T1, 40.0],
        [203, 5, C2, S_GEEZ, T2, 35.0],
        [203, 6, C2, S_HIST, T1, 51.0],
    ];
    foreach ($records as $r) {
        $conn->query("INSERT INTO academic_records
            (member_id, assessment_id, class_id, subject_id, academic_year_id, term_id, score, max_score)
            VALUES ({$r[0]}, {$r[1]}, {$r[2]}, {$r[3]}, " . Y1 . ", {$r[4]}, {$r[5]}, 100.00)");
    }

    // Attendance: 101 perfect (4/4), 102 half (2 present 2 absent),
    // 103 none recorded at all, 104 one late (counts as attended).
    $att = [
        [101, C1, ['present', 'present', 'present', 'present']],
        [102, C1, ['present', 'absent', 'present', 'absent']],
        [104, C1, ['late', 'present']],
        [201, C2, ['present', 'present']],
    ];
    foreach ($att as $a) {
        foreach ($a[2] as $i => $st) {
            $d = sprintf('2025-01-%02d', $i + 1);
            $conn->query("INSERT INTO attendance (member_id, class_id, academic_year_id, date, status)
                          VALUES ({$a[0]}, {$a[1]}, " . Y1 . ", '{$d}', '{$st}')");
        }
    }

    // Submission governance: BEKELE approved the C1 Geez midterm, submitted
    // the C1 Geez final, and never submitted anything for C2.
    $conn->query("INSERT INTO grade_submissions
        (teacher_id, class_id, subject_id, academic_year_id, term_id, assessment_id, status, student_count, average_score, submitted_at)
        VALUES
        (" . U_BEKELE . ", " . C1 . ", " . S_GEEZ . ", " . Y1 . ", " . T1 . ", 1, 'approved', 4, 61.25, '2025-02-01 10:00:00'),
        (" . U_BEKELE . ", " . C1 . ", " . S_GEEZ . ", " . Y1 . ", " . T2 . ", 2, 'submitted', 3, 61.67, '2025-06-01 10:00:00')");
}

// ════════════════════════════════════════════════════════════════════════
// SCENARIOS
// ════════════════════════════════════════════════════════════════════════

/**
 * THE CENTRAL CLAIM. For every subject of every class, the number the
 * perspective layer reports must equal what ReportCardService independently
 * reports when asked for that one subject. If these ever diverge, the
 * feature has grown a second calculation engine, which is exactly what it
 * must not do.
 */
function scenario_breakdown_matches_subject_report(mysqli $conn): void
{
    foreach ([[C1, 'C1'], [C2, 'C2']] as [$cid, $label]) {
        foreach ([[0, 'annual'], [T1, 'S1'], [T2, 'S2']] as [$term, $tlabel]) {
            AcademicIntelligenceService::resetCache();
            $view = AcademicIntelligenceService::classView($conn, $cid, Y1, $term);
            check("{$label}/{$tlabel}: class view builds", 'success', $view['status'] ?? '');

            foreach ($view['rows'] as $sub) {
                $sid = (int)$sub['id'];
                $authoritative = ReportCardService::getClassReport($conn, $cid, $sid, Y1, $term);
                check(
                    "{$label}/{$tlabel}/subject {$sid}: authoritative report builds",
                    'success',
                    $authoritative['status'] ?? ''
                );
                $stats = $authoritative['stats'];

                check(
                    "{$label}/{$tlabel}/subject {$sid}: average equals ReportCardService",
                    $stats['class_average'],
                    $sub['average']
                );
                check(
                    "{$label}/{$tlabel}/subject {$sid}: graded count equals ReportCardService",
                    (int)$stats['graded_students'],
                    (int)$sub['graded_students']
                );
                check(
                    "{$label}/{$tlabel}/subject {$sid}: pass rate equals ReportCardService",
                    $stats['pass_rate'],
                    $sub['pass_rate']
                );
                check(
                    "{$label}/{$tlabel}/subject {$sid}: grade distribution equals ReportCardService",
                    $stats['grade_distribution'],
                    $sub['grade_distribution']
                );
                check(
                    "{$label}/{$tlabel}/subject {$sid}: highest equals ReportCardService",
                    $stats['highest'],
                    $sub['highest']
                );
                check(
                    "{$label}/{$tlabel}/subject {$sid}: lowest equals ReportCardService",
                    $stats['lowest'],
                    $sub['lowest']
                );
            }
        }
    }
}

/**
 * A whole-class view must cost ONE class pack, not one per subject. Proven
 * by counting the queries the connection actually issues.
 */
function scenario_breakdown_is_one_pass(mysqli $conn): void
{
    AcademicIntelligenceService::resetCache();
    $before = (int)$conn->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
    AcademicIntelligenceService::classView($conn, C1, Y1, 0);
    $mid = (int)$conn->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
    $viewCost = $mid - $before - 1;

    // The naive alternative: ask the engine for the class, then once more
    // per subject.
    $before2 = $mid;
    ReportCardService::getClassReport($conn, C1, 0, Y1, 0);
    foreach ([S_GEEZ, S_MUSIC] as $sid) {
        ReportCardService::getClassReport($conn, C1, $sid, Y1, 0);
    }
    $after2 = (int)$conn->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
    $naiveCost = $after2 - $before2 - 1;

    echo "        (class view {$viewCost} queries vs naive per-subject {$naiveCost})\n";
    check_true(
        'class view costs strictly fewer queries than one report per subject',
        $viewCost < $naiveCost
    );

    // And a teacher spanning two subjects of the SAME class must reuse that
    // class pack rather than building it twice.
    AcademicIntelligenceService::resetCache();
    $b3 = (int)$conn->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
    AcademicIntelligenceService::classView($conn, C1, Y1, 0);
    $m3 = (int)$conn->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
    AcademicIntelligenceService::classView($conn, C1, Y1, 0);
    $a3 = (int)$conn->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
    $first = $m3 - $b3 - 1;
    $second = $a3 - $m3 - 1;
    echo "        (first build {$first} queries, memoised repeat {$second})\n";
    check('the second look at the same class issues no further queries', 0, $second);
}

/** Duration policy must survive the projection unchanged. */
function scenario_duration_policy_preserved(mysqli $conn): void
{
    AcademicIntelligenceService::resetCache();
    $annual = AcademicIntelligenceService::classView($conn, C1, Y1, 0);
    $byId = [];
    foreach ($annual['rows'] as $s) {
        $byId[(int)$s['id']] = $s;
    }

    check('C1 annual: Geez is FULL_YEAR', 'FULL_YEAR', $byId[S_GEEZ]['duration_type']);
    check('C1 annual: Music is SEMESTER_ONLY', 'SEMESTER_ONLY', $byId[S_MUSIC]['duration_type']);

    // 104 has no Geez S2 mark -> PENDING -> excluded from the average, never
    // counted as a zero. 4 students enrolled, only 3 have a final.
    check('C1 annual Geez: 3 graded + 1 pending = 4 carrying the subject', 4,
        (int)$byId[S_GEEZ]['graded_students'] + (int)$byId[S_GEEZ]['pending_students']);
    check('C1 annual Geez: only 3 have a final score', 3, (int)$byId[S_GEEZ]['graded_students']);
    check('C1 annual Geez: 1 still pending', 1, (int)$byId[S_GEEZ]['pending_students']);
    // A full-year subject that has Semester 1 but not yet Semester 2 is
    // CONTINUING (SubjectDurationPolicy::finalScore). The point to protect is
    // that it carries no final and is therefore excluded from the average,
    // never folded in as a zero.
    check_true(
        'C1 annual Geez: the unfinished student is CONTINUING',
        ($byId[S_GEEZ]['subject_status_counts']['CONTINUING'] ?? 0) === 1
    );

    // 40/60 weights, not 50/50: student 101 is 80 (S1) and 90 (S2).
    //   40/60 -> 0.4*80 + 0.6*90 = 86.0     (50/50 would be 85.0)
    //   102   -> 0.4*60 + 0.6*50 = 54.0
    //   103   -> 0.4*30 + 0.6*45 = 39.0
    // mean(86, 54, 39) = 59.666... -> 59.7
    check('C1 annual Geez average uses the configured 40/60 weights', 59.7, $byId[S_GEEZ]['average']);

    // Music is semester-1 only: its annual final IS the S1 score, and the
    // semester it did not run in stays absent.
    check('C1 annual Music average is its Semester 1 result', 72.5, $byId[S_MUSIC]['average']);
    check('C1 annual Music has no Semester 2 average', null, $byId[S_MUSIC]['semester_2_average']);
}

/** Student perspective: identity, subjects, rank, attendance. */
function scenario_student_perspective(mysqli $conn): void
{
    $out = AcademicIntelligenceService::student($conn, 101, 0, Y1, 0);
    check('student: builds', 'success', $out['status'] ?? '');
    check('student: perspective tag', 'student', $out['perspective']);
    check('student: resolves the enrolled class without being told', C1, (int)$out['summary']['class_id']);
    check('student: name', 'Abebe', $out['summary']['student_name']);
    check('student: father name', 'Kebede', $out['summary']['father_name']);
    check('student: member code', 'M-101', $out['summary']['member_code']);
    check('student: class size', 4, (int)$out['summary']['total_in_class']);

    // Rank comes from the engine, and demonstrates that an unfinished
    // subject is EXCLUDED rather than scored zero: Dagmawit has only her
    // Music result (85.0) counted because her full-year Geez is still
    // running, which puts her above Abebe's 78.0. Were the missing Geez
    // treated as a zero she would rank last instead of first.
    check('student: rank comes from the report engine', 2, (int)$out['summary']['rank']);
    check('student: attendance rate', 100.0, $out['summary']['attendance_rate']);

    // Subject rows must carry the semester split and the duration status.
    $rows = [];
    foreach ($out['rows'] as $r) {
        $rows[(int)$r['id']] = $r;
    }
    check('student: Geez semester 1', 80.0, $rows[S_GEEZ]['semester_1_score']);
    check('student: Geez semester 2', 90.0, $rows[S_GEEZ]['semester_2_score']);
    check('student: Geez final uses 40/60', 86.0, $rows[S_GEEZ]['final_percentage']);
    check('student: Geez is closed', 'CLOSED', $rows[S_GEEZ]['subject_status']);

    // Cross-check against the authoritative card for the same student.
    $card = ReportCardService::getCard($conn, 101, C1, Y1, 0);
    check('student: overall average equals the report card', $card['overall_average'], $out['summary']['overall_average']);
    check('student: grade letter equals the report card', $card['overall_grade'], $out['summary']['grade_letter']);
    check('student: rank equals the report card', (int)$card['rank'], (int)$out['summary']['rank']);

    // The pending student must surface as pending, not as a zero.
    $pending = AcademicIntelligenceService::student($conn, 104, 0, Y1, 0);
    $prows = [];
    foreach ($pending['rows'] as $r) {
        $prows[(int)$r['id']] = $r;
    }
    check('student 104: Geez has no final yet', null, $prows[S_GEEZ]['final_percentage']);
    check('student 104: Geez is still running', 'CONTINUING', $prows[S_GEEZ]['subject_status']);
    check('student 104: Geez semester 2 is absent, not zero', null, $prows[S_GEEZ]['semester_2_score']);
    check('student 104: Geez semester 1 is intact', 75.0, $prows[S_GEEZ]['semester_1_score']);
    // 85.0 is the Music score alone. If the missing Geez had been scored 0
    // this would be 42.5, and if S1 alone had been used as the annual Geez
    // result it would be 80.0.
    check('student 104: average counts only the finished subject', 85.0, $pending['summary']['overall_average']);
    check('student 104: ranks first on one finished subject', 1, (int)$pending['summary']['rank']);
    check('student 104: one subject still pending', 1, (int)$pending['summary']['pending_subjects']);
}

/** Teacher perspective: only that teacher's assignments, with real outcomes. */
function scenario_teacher_perspective(mysqli $conn): void
{
    $out = AcademicIntelligenceService::teacher($conn, U_BEKELE, Y1, 0);
    check('teacher: builds', 'success', $out['status'] ?? '');
    check('teacher: name', 'Bekele Tadesse', $out['summary']['teacher_name']);
    check('teacher: has 2 assignments', 2, count($out['rows']));

    $seen = [];
    foreach ($out['rows'] as $r) {
        $seen[] = (int)$r['class_id'] . ':' . (int)$r['subject_id'];
    }
    sort($seen);
    check('teacher: exactly the assigned class/subject pairs', ['1:1', '2:1'], $seen);

    // Every cell's academic numbers must equal the authoritative report.
    foreach ($out['rows'] as $r) {
        $auth = ReportCardService::getClassReport($conn, (int)$r['class_id'], (int)$r['subject_id'], Y1, 0);
        check(
            "teacher cell {$r['class_id']}/{$r['subject_id']}: average equals ReportCardService",
            $auth['stats']['class_average'],
            $r['average']
        );
        check(
            "teacher cell {$r['class_id']}/{$r['subject_id']}: pass rate equals ReportCardService",
            $auth['stats']['pass_rate'],
            $r['pass_rate']
        );
    }

    // Submission governance must be carried through, per assessment.
    $c1 = null;
    foreach ($out['rows'] as $r) {
        if ((int)$r['class_id'] === C1) {
            $c1 = $r;
        }
    }
    check('teacher: C1 Geez has 2 planned assessments', 2, (int)$c1['assessments_planned']);
    check('teacher: C1 Geez has 1 approved', 1, (int)$c1['submissions']['approved']);
    check('teacher: C1 Geez has 1 submitted', 1, (int)$c1['submissions']['submitted']);
    check('teacher: C1 Geez has 0 missing', 0, (int)$c1['submissions']['missing']);

    $c2 = null;
    foreach ($out['rows'] as $r) {
        if ((int)$r['class_id'] === C2) {
            $c2 = $r;
        }
    }
    check('teacher: C2 Geez has 2 planned assessments', 2, (int)$c2['assessments_planned']);
    check('teacher: C2 Geez submitted nothing, so 2 missing', 2, (int)$c2['submissions']['missing']);

    // A teacher with no assignments is an empty result, not an error and not
    // somebody else's data.
    $none = AcademicIntelligenceService::teacher($conn, U_KEBEDE, Y1, 0);
    check('teacher with no assignments: still success', 'success', $none['status'] ?? '');
    check('teacher with no assignments: no rows', 0, count($none['rows']));
    check('teacher with no assignments: no students claimed', 0, (int)$none['summary']['total_students']);

    // ALMAZ must never see BEKELE's classes.
    $almaz = AcademicIntelligenceService::teacher($conn, U_ALMAZ, Y1, 0);
    check('teacher ALMAZ: exactly 1 assignment', 1, count($almaz['rows']));
    check('teacher ALMAZ: it is the Music class', S_MUSIC, (int)$almaz['rows'][0]['subject_id']);
}

/** Subject perspective: every class that offers it, and only those. */
function scenario_subject_perspective(mysqli $conn): void
{
    $out = AcademicIntelligenceService::subject($conn, S_GEEZ, Y1, 0);
    check('subject: builds', 'success', $out['status'] ?? '');
    check('subject: name', 'ግዕዝ', $out['summary']['subject_name']);
    check('subject: taught in 2 classes', 2, count($out['rows']));

    $classIds = array_map(static fn($r) => (int)$r['class_id'], $out['rows']);
    sort($classIds);
    check('subject: exactly the classes that offer it', [C1, C2], $classIds);

    // Teacher relationship must come from the assignment table.
    foreach ($out['rows'] as $r) {
        check(
            "subject: class {$r['class_id']} lists Bekele as the teacher",
            ['Bekele Tadesse'],
            array_map(static fn($t) => $t['teacher_name'], $r['teachers'])
        );
        $auth = ReportCardService::getClassReport($conn, (int)$r['class_id'], S_GEEZ, Y1, 0);
        check(
            "subject: class {$r['class_id']} average equals ReportCardService",
            $auth['stats']['class_average'],
            $r['average']
        );
    }

    // MUSIC is offered by C1 only -- a subject view must not invent classes.
    $music = AcademicIntelligenceService::subject($conn, S_MUSIC, Y1, 0);
    check('subject Music: only 1 class offers it', 1, count($music['rows']));
    check('subject Music: that class is C1', C1, (int)$music['rows'][0]['class_id']);
    check(
        'subject Music: taught by Almaz',
        ['Almaz Girma'],
        array_map(static fn($t) => $t['teacher_name'], $music['rows'][0]['teachers'])
    );

    // HISTORY has no teacher assigned at all: report that honestly.
    $hist = AcademicIntelligenceService::subject($conn, S_HIST, Y1, 0);
    check('subject History: 1 class', 1, count($hist['rows']));
    check('subject History: no teacher assigned', [], $hist['rows'][0]['teachers']);
}

/** Class perspective: students, subjects, completion. */
function scenario_class_perspective(mysqli $conn): void
{
    $out = AcademicIntelligenceService::classView($conn, C1, Y1, 0);
    check('class: builds', 'success', $out['status'] ?? '');
    check('class: name', '4ኛ ክፍል', $out['summary']['class_name']);
    check('class: 4 students', 4, (int)$out['summary']['total_students']);
    check('class: 2 subjects', 2, count($out['rows']));

    $auth = ReportCardService::getClassReport($conn, C1, 0, Y1, 0);
    check('class: average equals ReportCardService', $auth['stats']['class_average'], $out['summary']['class_average']);
    check('class: pass rate equals ReportCardService', $auth['stats']['pass_rate'], $out['summary']['pass_rate']);
    check('class: median equals ReportCardService', $auth['stats']['median'], $out['summary']['median']);
    check('class: highest equals ReportCardService', $auth['stats']['highest'], $out['summary']['highest']);
    check('class: lowest equals ReportCardService', $auth['stats']['lowest'], $out['summary']['lowest']);
    check(
        'class: grade distribution equals ReportCardService',
        $auth['stats']['grade_distribution'],
        $out['charts']['grade_distribution']
    );

    // Drill-down list of students must be the real roster.
    check('class: student drilldown has 4 rows', 4, count($out['drilldown']['students']));

    // An empty class must be a clean empty state, not an error.
    $empty = AcademicIntelligenceService::classView($conn, C3, Y1, 0);
    check('empty class: still success', 'success', $empty['status'] ?? '');
    check('empty class: zero students', 0, (int)$empty['summary']['total_students']);
    check('empty class: no subjects', 0, count($empty['rows']));
    check('empty class: average is null, not zero', null, $empty['summary']['class_average']);
}

/** Bad input must be refused without leaking anything. */
function scenario_invalid_input(mysqli $conn): void
{
    $cases = [
        ['student', AcademicIntelligenceService::student($conn, 999999, 0, Y1, 0)],
        ['teacher', AcademicIntelligenceService::teacher($conn, 999999, Y1, 0)],
        ['subject', AcademicIntelligenceService::subject($conn, 999999, Y1, 0)],
        ['class', AcademicIntelligenceService::classView($conn, 999999, Y1, 0)],
    ];
    foreach ($cases as [$label, $res]) {
        check_true(
            "{$label}: unknown id is refused or empty, never another record's data",
            ($res['status'] ?? '') === 'error'
                || empty($res['rows'])
        );
        check_true(
            "{$label}: unknown id leaks no student names",
            !str_contains(json_encode($res), 'Abebe')
        );
    }

    foreach ([0, -5] as $bad) {
        check(
            "class id {$bad} is rejected",
            'error',
            AcademicIntelligenceService::classView($conn, $bad, Y1, 0)['status'] ?? ''
        );
        check(
            "subject id {$bad} is rejected",
            'error',
            AcademicIntelligenceService::subject($conn, $bad, Y1, 0)['status'] ?? ''
        );
        check(
            "teacher id {$bad} is rejected",
            'error',
            AcademicIntelligenceService::teacher($conn, $bad, Y1, 0)['status'] ?? ''
        );
    }
}

/** Filters must move the summary numbers, not just the table. */
function scenario_filters_apply_to_summary(mysqli $conn): void
{
    $all = AcademicIntelligenceService::classView($conn, C1, Y1, 0);
    $girls = AcademicIntelligenceService::classView($conn, C1, Y1, 0, ['gender' => 'female']);

    check('filter: unfiltered class has 4 students', 4, (int)$all['summary']['total_students']);
    check('filter: female filter leaves 2 students', 2, (int)$girls['summary']['total_students']);
    check('filter: the drilldown shrinks too', 2, count($girls['drilldown']['students']));
    check_true(
        'filter: the summary average changes with the filter, not only the table',
        $all['summary']['class_average'] !== $girls['summary']['class_average']
    );
    check(
        'filter: the chart is rebuilt from the filtered set',
        2,
        array_sum($girls['charts']['grade_distribution'])
    );
    check('filter: filters are echoed back', 'female', $girls['filters']['gender']);
}

/** The envelope every perspective returns must be the same shape. */
function scenario_contract_shape(mysqli $conn): void
{
    $views = [
        'student' => AcademicIntelligenceService::student($conn, 101, 0, Y1, 0),
        'teacher' => AcademicIntelligenceService::teacher($conn, U_BEKELE, Y1, 0),
        'subject' => AcademicIntelligenceService::subject($conn, S_GEEZ, Y1, 0),
        'class' => AcademicIntelligenceService::classView($conn, C1, Y1, 0),
    ];
    foreach ($views as $name => $v) {
        foreach (['status', 'perspective', 'academic_year', 'term', 'filters', 'summary', 'rows', 'charts', 'drilldown'] as $key) {
            check_true("{$name}: envelope has '{$key}'", array_key_exists($key, $v));
        }
        check("{$name}: perspective is tagged", $name === 'class' ? 'class' : $name, $v['perspective']);
        check_true("{$name}: year is reported", (int)($v['academic_year']['id'] ?? 0) === Y1);
        check_true("{$name}: term is annual", ($v['term']['id'] ?? null) === null);
        check_true("{$name}: rows is a list", is_array($v['rows']));
        check_true("{$name}: json encodes cleanly", json_encode($v) !== false);
    }
}

/** A term-scoped request must differ from the annual one. */
function scenario_term_scoping(mysqli $conn): void
{
    $annual = AcademicIntelligenceService::classView($conn, C1, Y1, 0);
    $s1 = AcademicIntelligenceService::classView($conn, C1, Y1, T1);
    $s2 = AcademicIntelligenceService::classView($conn, C1, Y1, T2);

    check('term: annual reports no term', null, $annual['term']['id'] ?? null);
    check('term: S1 reports term 1', T1, (int)$s1['term']['id']);
    check('term: S1 term number', 1, (int)$s1['term']['term_number']);

    // Music is semester-1 only, so it must not appear on the S2 report at all.
    $s1Subjects = array_map(static fn($r) => (int)$r['id'], $s1['rows']);
    $s2Subjects = array_map(static fn($r) => (int)$r['id'], $s2['rows']);
    check_true('term: Music appears in Semester 1', in_array(S_MUSIC, $s1Subjects, true));
    check_true('term: Music is absent from Semester 2', !in_array(S_MUSIC, $s2Subjects, true));
    check_true('term: Geez appears in both semesters',
        in_array(S_GEEZ, $s1Subjects, true) && in_array(S_GEEZ, $s2Subjects, true));
}

/**
 * Fixture-only scenario for the Academic Tracking root lists.
 *
 * The base fixture is deliberately tiny -- three classes, seven students,
 * three teachers -- because the calculation scenarios need numbers a human
 * can verify by hand. That size cannot prove anything about pagination: the
 * roster and list_teachers endpoints both floor per_page at 10, so a nine-row
 * table always fits on page one and a broken LIMIT/OFFSET would look fine.
 *
 * So this scenario adds a bulk cohort on top of the base seed: 30 extra
 * members enrolled in C3 and 12 extra teachers. Names are zero-padded
 * ("Bulk Student 01") so that ORDER BY student_name is a total order with no
 * ties -- which is what makes "page 2 contains exactly these ten names" a
 * real assertion rather than a coin flip.
 *
 * It asserts only the shape of the fixture it just built. The endpoints
 * themselves are exercised over HTTP by tests/security/test_academic_tracking.py
 * through education_config_api.php, because that is the harness that can
 * supply a session role.
 */
function scenario_tracking_list_fixture(mysqli $conn): void
{
    // 30 students, ids 301-330, all in the otherwise-empty C3.
    for ($i = 1; $i <= 30; $i++) {
        $id = 300 + $i;
        $name = sprintf('Bulk Student %02d', $i);
        $code = sprintf('B-%03d', $i);
        $gender = ($i % 2 === 0) ? 'female' : 'male';
        $stmt = $conn->prepare("INSERT INTO members (id, member_code, student_name, father_name, gender, status)
                                VALUES (?,?,?,'Bulk',?, 'active')");
        $stmt->bind_param('isss', $id, $code, $name, $gender);
        $stmt->execute();
        $stmt->close();
        $conn->query("INSERT INTO class_enrollments (member_id, class_id, academic_year_id, status)
                      VALUES ({$id}, " . C3 . ", " . Y1 . ", 'active')");
    }

    // 12 extra teachers, ids 21-32. Half are linked to a member row and half
    // are not, so a paged teacher list has to cope with both on every page.
    for ($i = 1; $i <= 12; $i++) {
        $uid = 20 + $i;
        $name = sprintf('Bulk Teacher %02d', $i);
        $user = sprintf('bulkteacher%02d', $i);
        $memberId = null;
        if ($i % 2 === 1) {
            $memberId = 900 + 10 + $i;
            $mcode = sprintf('T-%03d', 910 + $i);
            $stmt = $conn->prepare("INSERT INTO members (id, member_code, student_name, father_name, gender, status, is_teacher)
                                    VALUES (?,?,?,'Bulk','male','active',1)");
            $stmt->bind_param('iss', $memberId, $mcode, $name);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("INSERT INTO users (id, username, full_name, email, role, is_active, member_id)
                                VALUES (?,?,?,?, 'teacher', 1, ?)");
        $email = $user . '@example.org';
        $stmt->bind_param('isssi', $uid, $user, $name, $email, $memberId);
        $stmt->execute();
        $stmt->close();
    }

    $members = (int)$conn->query("SELECT COUNT(*) c FROM members WHERE status='active'")->fetch_assoc()['c'];
    $teachers = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='teacher' AND is_active=1")->fetch_assoc()['c'];
    $linked = (int)$conn->query("SELECT COUNT(*) c FROM users u JOIN members m ON u.member_id = m.id WHERE u.role='teacher'")->fetch_assoc()['c'];
    $unlinked = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='teacher' AND member_id IS NULL")->fetch_assoc()['c'];
    $c3 = (int)$conn->query("SELECT COUNT(*) c FROM class_enrollments WHERE class_id=" . C3 . " AND status='active'")->fetch_assoc()['c'];

    // 7 base students + 2 teacher-members + 30 bulk students + 6 bulk
    // teacher-members = 45 active member rows.
    check('tracking fixture: active members', 45, $members);
    check('tracking fixture: active teachers', 15, $teachers);
    check('tracking fixture: teachers linked to a member', 8, $linked);
    check('tracking fixture: teachers with no member link', 7, $unlinked);
    check('tracking fixture: C3 is no longer empty', 30, $c3);
}

/**
 * Extra rows the Student Tracking (Phase 2) tests need, on top of seed().
 *
 * The base seed already gives us a student with perfect attendance (101),
 * one with none at all (103) and one whose full-year subject stays PENDING
 * because a semester mark is missing (104). What it does not give us is:
 *
 *   - an assessment that exists but has never been marked by anyone, which
 *     is the only way to reach the "Not started" workflow state;
 *   - weighted assessments, so planned-vs-recorded weight is exercised;
 *   - a mark list handed back for revision;
 *   - a student enrolled but with nothing recorded at all;
 *   - a member who is in no class, which is the no_enrolment state.
 */
function scenario_student_tracking_fixture(mysqli $conn): void
{
    // Weight the two C1 Music assessments so the subject plans 100% of its
    // weight across one marked and one unmarked assessment.
    $conn->query("UPDATE assessments SET weight_percentage = 50.00 WHERE id = 3");
    $conn->query("INSERT INTO assessments
        (id, class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type, max_score, weight_percentage, is_active)
        VALUES (7, " . C1 . ", " . S_MUSIC . ", " . Y1 . ", " . T1 . ", 'Music Project', 'project', 100.00, 50.00, 1)");

    // A mark list Education handed back to the teacher.
    $conn->query("INSERT INTO grade_submissions
        (teacher_id, class_id, subject_id, academic_year_id, term_id, assessment_id, status, student_count, average_score, submitted_at)
        VALUES (" . U_BEKELE . ", " . C2 . ", " . S_HIST . ", " . Y1 . ", " . T1 . ", 6, 'revision_needed', 3, 70.33, '2025-03-01 10:00:00')");

    // 105: enrolled in C1, but not one mark and not one attendance row.
    $conn->query("INSERT INTO members (id, member_code, student_name, father_name, gender, status)
                  VALUES (105, 'M-105', 'Tsion', 'Mekonnen', 'female', 'active')");
    $conn->query("INSERT INTO class_enrollments (member_id, class_id, academic_year_id, status)
                  VALUES (105, " . C1 . ", " . Y1 . ", 'active')");

    // 150: a real member in no class at all.
    $conn->query("INSERT INTO members (id, member_code, student_name, father_name, gender, status)
                  VALUES (150, 'M-150', 'Yonas', 'Girma', 'male', 'active')");

    $unmarked = (int)$conn->query("SELECT COUNT(*) c FROM academic_records WHERE assessment_id = 7")->fetch_assoc()['c'];
    $noClass  = (int)$conn->query("SELECT COUNT(*) c FROM class_enrollments WHERE member_id = 150")->fetch_assoc()['c'];
    $blank    = (int)$conn->query("SELECT COUNT(*) c FROM academic_records WHERE member_id = 105")->fetch_assoc()['c'];
    $noAtt    = (int)$conn->query("SELECT COUNT(*) c FROM attendance WHERE member_id = 103")->fetch_assoc()['c'];

    check('tracking fixture: assessment 7 is unmarked by anyone', 0, $unmarked);
    check('tracking fixture: member 150 is in no class', 0, $noClass);
    check('tracking fixture: student 105 has no marks', 0, $blank);
    check('tracking fixture: student 103 has no attendance', 0, $noAtt);
}

$scenarios = [
    'breakdown_matches_subject_report' => 'scenario_breakdown_matches_subject_report',
    'breakdown_is_one_pass' => 'scenario_breakdown_is_one_pass',
    'duration_policy_preserved' => 'scenario_duration_policy_preserved',
    'student_perspective' => 'scenario_student_perspective',
    'teacher_perspective' => 'scenario_teacher_perspective',
    'subject_perspective' => 'scenario_subject_perspective',
    'class_perspective' => 'scenario_class_perspective',
    'invalid_input' => 'scenario_invalid_input',
    'filters_apply_to_summary' => 'scenario_filters_apply_to_summary',
    'contract_shape' => 'scenario_contract_shape',
    'term_scoping' => 'scenario_term_scoping',
    'tracking_list_fixture' => 'scenario_tracking_list_fixture',
    'student_tracking_fixture' => 'scenario_student_tracking_fixture',
];

$want = $argv[1] ?? 'all';
$run = $want === 'all' ? array_keys($scenarios) : [$want];
foreach ($run as $name) {
    if (!isset($scenarios[$name])) {
        fwrite(STDERR, "unknown scenario: {$name}\n");
        exit(2);
    }
    echo "── {$name}\n";
    rebuild($conn);
    seed($conn);
    $scenarios[$name]($conn);
}

echo "\n{$checks} checks, " . count($failures) . " failed\n";
if ($failures) {
    echo "\nFAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
exit(0);
