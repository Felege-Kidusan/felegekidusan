<?php
/**
 * Attendance API
 * Handles attendance recording and retrieval
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/EnrollmentService.php';
require_once __DIR__ . '/backend/services/AttendanceRecordService.php';

use App\Services\AttendanceRecordService;
use App\Services\EnrollmentService;

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
        
        $preferYear = $currentYear ? (int)$currentYear['id'] : null;
        $scope = EnrollmentService::resolveRosterYear($conn, $classId, $preferYear);
        $roster = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null);

        $attByMember = [];
        $attStmt = $conn->prepare(
            "SELECT id, member_id, status, notes FROM attendance WHERE class_id = ? AND attendance_date = ?"
        );
        if ($attStmt) {
            $attStmt->bind_param('is', $classId, $date);
            $attStmt->execute();
            $ar = $attStmt->get_result();
            while ($row = $ar->fetch_assoc()) {
                $attByMember[(int)$row['member_id']] = $row;
            }
            $attStmt->close();
        }

        $students = [];
        foreach ($roster as $row) {
            $mid = (int)($row['member_id'] ?? $row['id'] ?? 0);
            if ($mid <= 0) continue;
            $att = $attByMember[$mid] ?? null;
            $students[] = [
                'member_id' => $mid,
                'student_name' => $row['student_name'] ?? '',
                'father_name' => $row['father_name'] ?? '',
                'member_code' => $row['member_code'] ?? '',
                'gender' => $row['gender'] ?? '',
                'attendance_id' => $att && !empty($att['id']) ? (int)$att['id'] : null,
                'status' => $att['status'] ?? null,
                'note' => $att['notes'] ?? '',
            ];
        }
        
        echo json_encode([
            'status' => 'success',
            'class_name' => $className,
            'date' => $date,
            'students' => $students,
            'count' => count($students),
            'roster_year_id' => $scope['year_id'] ?? null,
            'roster_year_name' => $scope['year_name'] ?? null,
            'roster_fallback' => !empty($scope['fallback']),
        ], JSON_UNESCAPED_UNICODE);
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
        
        $academicYearId = $currentYear ? (int)$currentYear['id'] : null;

        // Resolve the roster again at save time. A stale or partial browser
        // sheet must never erase students or silently mark them present.
        $scope = EnrollmentService::resolveRosterYear($conn, $classId, $academicYearId);
        $roster = EnrollmentService::fetchRoster($conn, $classId, $scope['year_id'] ?? null);
        try {
            $records = AttendanceRecordService::normalizeCompleteSheet($records, $roster);
        } catch (DomainException $error) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => $error->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Replace the whole class/day and commit only if every row succeeds.
        $successCount = 0;
        $errors = [];
        $conn->begin_transaction();
        try {
            $successCount = AttendanceRecordService::replaceSheet(
                $conn,
                $classId,
                $date,
                $academicYearId,
                (int)$userId,
                $records
            );
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            error_log("save_attendance failed (class $classId, $date): " . $error->getMessage());
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Attendance was NOT saved. Your previous data is unchanged. Please try again.'
            ]);
            exit;
        }

        // Summary is a derived cache — safe to update after the commit.
        updateAttendanceSummary($conn, $classId, $date, $academicYearId, null);
        
        // AI Logic for 3 Consecutive Absences
        $absentMembers = [];
        foreach ($records as $record) {
            if (($record['status'] ?? '') === 'absent') {
                $absentMembers[] = (int)($record['member_id'] ?? 0);
            }
        }
        
        if (!empty($absentMembers)) {
            $checkStmt = $conn->prepare("
                SELECT member_id, COUNT(*) as absent_count
                FROM (
                    SELECT member_id, status
                    FROM attendance
                    WHERE member_id = ? AND class_id = ?
                    ORDER BY attendance_date DESC
                    LIMIT 3
                ) as last_three
                WHERE status = 'absent'
            ");
            
            $alertStmt = $conn->prepare("
                INSERT INTO notifications (type, title, message, data, priority, target_roles, source_dept)
                VALUES ('attendance_alert', ?, ?, ?, 'high', 'hr_dept,info_dept', 'attendance_taker')
            ");
            
            foreach ($absentMembers as $mId) {
                if (!$mId) continue;
                $checkStmt->bind_param("ii", $mId, $classId);
                $checkStmt->execute();
                $res = $checkStmt->get_result()->fetch_assoc();
                
                if ($res && $res['absent_count'] == 3) {
                    // Prevent duplicate notifications in the last 7 days
                    $likeData = '%"member_id":' . $mId . '%';
                    $recentCheck = $conn->prepare("SELECT id FROM notifications WHERE type = 'attendance_alert' AND data LIKE ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                    $recentCheck->bind_param("s", $likeData);
                    $recentCheck->execute();
                    if ($recentCheck->get_result()->num_rows === 0) {
                        $mDetails = $conn->query("SELECT student_name, father_name FROM members WHERE id = $mId")->fetch_assoc();
                        $mName = $mDetails['student_name'] . ' ' . $mDetails['father_name'];
                        
                        $title = "Consecutive Absence Alert";
                        $msg = "$mName has been absent for 3 consecutive classes.";
                        $dataJson = json_encode(['member_id' => $mId, 'class_id' => $classId]);
                        
                        $alertStmt->bind_param("sss", $title, $msg, $dataJson);
                        $alertStmt->execute();
                        
                        // Automatically flag as warning
                        $conn->query("UPDATE members SET status = 'warning' WHERE id = $mId AND status = 'active'");
                    }
                }
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => "$successCount attendance record(s) saved",
            'errors' => $errors
        ]);
        break;
        
    case 'scan_member':
        $memberCode = trim($_POST['member_code'] ?? '');
        $classId = (int)($_POST['class_id'] ?? 0);
        $date = validateDate($_POST['date'] ?? '', date('Y-m-d'));
        
        if (empty($memberCode) || !$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Member Code and Class ID required.']);
            exit;
        }

        // Find the member by code and ensure they are enrolled in this class
        $stmt = $conn->prepare("
            SELECT m.id, m.student_name, m.father_name, m.status as member_status, ce.status as enrollment_status 
            FROM members m 
            JOIN class_enrollments ce ON m.id = ce.member_id 
            WHERE m.member_code = ? AND ce.class_id = ? AND ce.status = 'active'
        ");
        $stmt->bind_param("si", $memberCode, $classId);
        $stmt->execute();
        $memberResult = $stmt->get_result()->fetch_assoc();
        
        if (!$memberResult) {
            echo json_encode(['status' => 'error', 'message' => "Member code '$memberCode' not found or not enrolled in this class."]);
            exit;
        }
        
        $memberId = $memberResult['id'];
        $memberName = $memberResult['student_name'] . ' ' . $memberResult['father_name'];
        $academicYearId = $currentYear ? $currentYear['id'] : null;
        $checkInTime = date('H:i:s');
        
        // Insert or update attendance using ON DUPLICATE KEY UPDATE
        $stmt = $conn->prepare("
            INSERT INTO attendance (member_id, class_id, academic_year_id, attendance_date, status, check_in_time, recorded_by)
            VALUES (?, ?, ?, ?, 'present', ?, ?)
            ON DUPLICATE KEY UPDATE status = 'present', check_in_time = IF(check_in_time IS NULL, VALUES(check_in_time), check_in_time), recorded_by = VALUES(recorded_by)
        ");
        
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error during scan.']);
            exit;
        }
        
        $stmt->bind_param("iiissi", $memberId, $classId, $academicYearId, $date, $checkInTime, $userId);
        
        if ($stmt->execute()) {
            updateAttendanceSummary($conn, $classId, $date, $academicYearId, null);
            echo json_encode([
                'status' => 'success', 
                'message' => "Scan successful!",
                'member_name' => $memberName,
                'member_id' => $memberId,
                'check_in_time' => $checkInTime
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to record scan.']);
        }
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
        
    case 'get_class_history':
        $classId = (int)($_GET['class_id'] ?? 0);
        if (!$classId) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID required']);
            exit;
        }
        
        $stmt = $conn->prepare("
            SELECT 
                attendance_date,
                COUNT(DISTINCT member_id) as total_recorded,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count
            FROM attendance
            WHERE class_id = ?
            GROUP BY attendance_date
            ORDER BY attendance_date DESC
            LIMIT 30
        ");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        echo json_encode([
            'status' => 'success',
            'history' => $history
        ]);
        break;
        
    case 'export_history':
        $classId = (int)($_GET['class_id'] ?? 0);
        if (!$classId) {
            die("Class ID required.");
        }
        
        // Fetch class info
        $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $classInfo = $stmt->get_result()->fetch_assoc();
        $className = $classInfo ? $classInfo['class_name'] : 'Unknown_Class';
        
        require_once __DIR__ . '/../vendor/autoload.php';
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr("Attendance " . preg_replace('/[^a-zA-Z0-9\s]/', '', $className), 0, 31));
        
        $columns = ['Date', 'Total Recorded', 'Present', 'Absent', 'Late'];
        $colIndex = 'A';
        foreach ($columns as $colName) {
            $sheet->setCellValue($colIndex . '1', $colName);
            $sheet->getColumnDimension($colIndex)->setAutoSize(true);
            $colIndex++;
        }
        
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFEAB308'] // Yellow-500
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ]
        ];
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
        
        // Data
        $stmt = $conn->prepare("
            SELECT 
                attendance_date,
                COUNT(DISTINCT member_id) as total_recorded,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count
            FROM attendance
            WHERE class_id = ?
            GROUP BY attendance_date
            ORDER BY attendance_date DESC
        ");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $rowIdx = 2;
        while ($row = $result->fetch_assoc()) {
            $sheet->setCellValue("A$rowIdx", $row['attendance_date']);
            $sheet->setCellValue("B$rowIdx", $row['total_recorded']);
            $sheet->setCellValue("C$rowIdx", $row['present_count']);
            $sheet->setCellValue("D$rowIdx", $row['absent_count']);
            $sheet->setCellValue("E$rowIdx", $row['late_count']);
            $rowIdx++;
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Attendance_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $className) . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    
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
        // Summary storage is deployment-managed by migration 013.
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
