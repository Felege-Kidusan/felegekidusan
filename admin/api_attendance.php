<?php
/**
 * Attendance API
 * Handles attendance recording and retrieval
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Check authentication
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Validate CSRF for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
        exit;
    }
}

$action = $_REQUEST['action'] ?? '';
$userId = $_SESSION['admin_id'];

// Effective academic year — single source of truth (resolver, time-travel aware)
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

// ── STEP 3: write-protection ────────────────────────────────────────────────
// Year-scoped writes are refused while time-travelling (viewing a past year)
// and always stamp the ACTIVE year — never the year being viewed.
if (function_exists('ay_require_writable') && in_array($action, ['save_attendance'], true)) {
    ay_require_writable($conn); // exits 403 (read-only) / 409 (no active year) as needed
}

try {
switch ($action) {

    case 'get_class_attendance':
        $classId = (int)($_GET['class_id'] ?? 0);
        $date = validateDate($_GET['date'] ?? '', date('Y-m-d'));
        
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID required']);
            exit;
        }
        
        // Get class name
        $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $classRow = $stmt->get_result()->fetch_assoc();
        $className = $classRow ? $classRow['class_name'] : 'Unknown';
        
        // Get enrolled students with any existing attendance for this date
        if ($currentYear) {
            $stmt = $conn->prepare("
                SELECT 
                    ce.member_id,
                    m.student_name, m.father_name, m.member_code, m.gender,
                    a.id as attendance_id, a.status, a.notes as note
                FROM class_enrollments ce
                JOIN members m ON ce.member_id = m.id
                LEFT JOIN attendance a ON a.member_id = ce.member_id 
                    AND a.class_id = ce.class_id 
                    AND a.attendance_date = ?
                WHERE ce.class_id = ? 
                    AND ce.academic_year_id = ?
                    AND ce.status = 'active'
                ORDER BY m.student_name
            ");
            $stmt->bind_param("sii", $date, $classId, $currentYear['id']);
        } else {
            // Fallback without academic year
            $stmt = $conn->prepare("
                SELECT 
                    ce.member_id,
                    m.student_name, m.father_name, m.member_code, m.gender,
                    a.id as attendance_id, a.status, a.notes as note
                FROM class_enrollments ce
                JOIN members m ON ce.member_id = m.id
                LEFT JOIN attendance a ON a.member_id = ce.member_id 
                    AND a.class_id = ce.class_id 
                    AND a.attendance_date = ?
                WHERE ce.class_id = ? 
                    AND ce.status = 'active'
                ORDER BY m.student_name
            ");
            $stmt->bind_param("si", $date, $classId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $students = [];
        while ($row = $result->fetch_assoc()) {
            // Default to present if no record exists
            if (!$row['status']) {
                $row['status'] = 'present';
            }
            $students[] = $row;
        }
        
        echo json_encode([
            'status' => 'success',
            'class_name' => $className,
            'date' => $date,
            'students' => $students
        ]);
        break;
    
    case 'save_attendance':
        $classId = (int)($_POST['class_id'] ?? 0);
        $date = validateDate($_POST['date'] ?? '', date('Y-m-d'));
        $records = $_POST['records'] ?? [];
        
        if (!$classId || empty($records)) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID and records required']);
            exit;
        }
        
        if (!is_array($records)) {
            $records = json_decode($records, true) ?: [];
        }
        
        $academicYearId = $currentYear ? $currentYear['id'] : null;
        
        $successCount = 0;
        $errors = [];

        // ── Save the whole class's attendance as ONE all-or-nothing unit ──
        // We delete the old rows for this class/date and insert the new ones.
        // Without a transaction, an interruption between the delete and the
        // inserts would WIPE the class's attendance for that day. The
        // transaction guarantees we either fully replace it or leave the old
        // data untouched — a teacher can never end up with an empty day.
        $conn->begin_transaction();
        try {
            // Delete existing attendance for this class/date first
            $stmt = $conn->prepare("DELETE FROM attendance WHERE class_id = ? AND attendance_date = ?");
            if (!$stmt) {
                throw new Exception('Prepare failed (delete): ' . $conn->error);
            }
            $stmt->bind_param("is", $classId, $date);
            $stmt->execute();

            // Insert new records (attendance table has no term_id column)
            $insertStmt = $conn->prepare("
                INSERT INTO attendance
                (member_id, class_id, academic_year_id, attendance_date, status, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$insertStmt) {
                throw new Exception('Prepare failed (insert): ' . $conn->error);
            }

            foreach ($records as $record) {
                $memberId = (int)($record['member_id'] ?? 0);
                $status = $record['status'] ?? 'present';
                $note = trim($record['note'] ?? '');

                if (!$memberId) continue;

                // Validate status
                if (!in_array($status, ['present', 'absent', 'late', 'excused'])) {
                    $status = 'present';
                }

                // A bad row must fail the whole save, not silently vanish.
                $insertStmt->bind_param(
                    "iiisssi",
                    $memberId, $classId, $academicYearId, $date, $status, $note, $userId
                );
                $insertStmt->execute();
                $successCount++;
            }

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("save_attendance failed (class $classId, $date): " . $e->getMessage());
            echo json_encode([
                'status'  => 'error',
                'message' => 'Attendance was NOT saved. Your previous data is unchanged. Please try again.'
            ]);
            exit;
        }

        // Summary is a derived cache — safe to update after the commit.
        updateAttendanceSummary($conn, $classId, $date, $academicYearId, null);

        echo json_encode([
            'status' => 'success',
            'message' => "$successCount attendance record(s) saved",
            'errors' => $errors
        ]);
        break;
    
    case 'get_attendance_summary':
        $classId = (int)($_GET['class_id'] ?? 0);
        $month = validateMonth($_GET['month'] ?? '', date('Y-m'));
        
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID required']);
            exit;
        }
        
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $stmt = $conn->prepare("
            SELECT 
                a.member_id,
                m.student_name, m.father_name,
                COUNT(*) as total_days,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_days
            FROM attendance a
            JOIN members m ON a.member_id = m.id
            WHERE a.class_id = ? AND a.attendance_date BETWEEN ? AND ?
            GROUP BY a.member_id
            ORDER BY m.student_name
        ");
        $stmt->bind_param("iss", $classId, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $summary = [];
        while ($row = $result->fetch_assoc()) {
            $row['attendance_rate'] = $row['total_days'] > 0 
                ? round(($row['present_days'] + $row['late_days'] * 0.5) / $row['total_days'] * 100, 1)
                : 0;
            $summary[] = $row;
        }
        
        echo json_encode([
            'status' => 'success',
            'month' => $month,
            'summary' => $summary
        ]);
        break;
    
    case 'get_daily_stats':
        $date = validateDate($_GET['date'] ?? '', date('Y-m-d'));
        
        $stmt = $conn->prepare("
            SELECT 
                c.id as class_id, c.class_name,
                COUNT(DISTINCT a.member_id) as total_recorded,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count
            FROM classes c
            LEFT JOIN attendance a ON a.class_id = c.id AND a.attendance_date = ?
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.level_order
        ");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }
        
        echo json_encode([
            'status' => 'success',
            'date' => $date,
            'stats' => $stats
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
} catch (Exception $e) {
    error_log("api_attendance error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}

/**
 * Update monthly attendance summary
 * Note: attendance_summary table uses (member_id, academic_year_id, month, year) as unique key
 * month = Ethiopian month (1-13), year = Ethiopian year
 * For now we store Gregorian month/year since Ethiopian calendar conversion is separate
 */
function updateAttendanceSummary($conn, $classId, $date, $academicYearId, $termId) {
    try {
        // Check if attendance_summary table exists
        $check = $conn->query("SHOW TABLES LIKE 'attendance_summary'");
        if (!$check || $check->num_rows === 0) return;
        
        $gcMonth = (int)date('m', strtotime($date));
        $gcYear = (int)date('Y', strtotime($date));
        $startDate = date('Y-m-01', strtotime($date));
        $endDate = date('Y-m-t', strtotime($date));
        
        // Get all members in this class with their attendance for the month
        $stmt = $conn->prepare("
            SELECT 
                a.member_id,
                COUNT(*) as total_days,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_days
            FROM attendance a
            WHERE a.class_id = ? AND a.attendance_date BETWEEN ? AND ?
            GROUP BY a.member_id
        ");
        if (!$stmt) return;
        $stmt->bind_param("iss", $classId, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $memberId = $row['member_id'];
            $totalDays = (int)$row['total_days'];
            $presentDays = (int)$row['present_days'];
            $absentDays = (int)$row['absent_days'];
            $lateDays = (int)$row['late_days'];
            $attendanceRate = $totalDays > 0 ? round(($presentDays + $lateDays * 0.5) / $totalDays * 100, 2) : 0;
            
            // Upsert summary (table unique key: member_id, academic_year_id, month, year)
            $upsert = $conn->prepare("
                INSERT INTO attendance_summary 
                (member_id, academic_year_id, month, year, total_days, present_days, absent_days, late_days, attendance_rate)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                total_days = VALUES(total_days),
                present_days = VALUES(present_days),
                absent_days = VALUES(absent_days),
                late_days = VALUES(late_days),
                attendance_rate = VALUES(attendance_rate)
            ");
            if (!$upsert) continue;
            $upsert->bind_param(
                "iiiiiiiid",
                $memberId, $academicYearId, $gcMonth, $gcYear,
                $totalDays, $presentDays, $absentDays, $lateDays, $attendanceRate
            );
            try { $upsert->execute(); } catch (Exception $e) { /* skip individual errors */ }
        }
    } catch (Exception $e) {
        error_log("updateAttendanceSummary error: " . $e->getMessage());
    }
}

$conn->close();
