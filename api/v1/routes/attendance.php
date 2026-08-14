<?php
/**
 * School API v1 — Attendance Routes
 * GET  /attendance?class_id=5&date=2026-03-20  — Get attendance for class+date
 * POST /attendance                              — Save attendance records
 * GET  /attendance/daily-stats?date=2026-03-20  — Per-class daily breakdown
 * GET  /attendance/summary?class_id=5&month=2026-03 — Monthly summary
 */

$auth = apiRequireAuth();
$action = $ROUTE['id'] ?? '';

// ============================================================
// GET /attendance?class_id=X&date=Y — Get attendance sheet
// ============================================================
if ($method === 'GET' && ($action === '' || $action === null)) {
    $classId = (int)($_GET['class_id'] ?? 0);
    $date = validateDate($_GET['date'] ?? '', date('Y-m-d'));
    
    if (!$classId) err('class_id is required');
    
    $year = getCurrentAcademicYear();
    
    // Get class info
    $stmt = $conn->prepare("SELECT class_name, class_name_en FROM classes WHERE id = ?");
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$class) err('Class not found', 404);
    
    // Get enrolled students with any existing attendance for this date
    if ($year) {
        $stmt = $conn->prepare("SELECT ce.member_id, m.student_name, m.father_name, m.member_code, m.gender,
                                       a.id as attendance_id, a.status as att_status, a.notes, a.check_in_time
                                FROM class_enrollments ce 
                                JOIN members m ON ce.member_id = m.id
                                LEFT JOIN attendance a ON a.member_id = ce.member_id AND a.attendance_date = ?
                                WHERE ce.class_id = ? AND ce.academic_year_id = ? AND ce.status = 'active'
                                ORDER BY m.student_name");
        $stmt->bind_param('sii', $date, $classId, $year['id']);
    } else {
        $stmt = $conn->prepare("SELECT ce.member_id, m.student_name, m.father_name, m.member_code, m.gender,
                                       a.id as attendance_id, a.status as att_status, a.notes, a.check_in_time
                                FROM class_enrollments ce 
                                JOIN members m ON ce.member_id = m.id
                                LEFT JOIN attendance a ON a.member_id = ce.member_id AND a.attendance_date = ?
                                WHERE ce.class_id = ? AND ce.status = 'active'
                                ORDER BY m.student_name");
        $stmt->bind_param('si', $date, $classId);
    }
    
    $stmt->execute();
    $students = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $students[] = $row;
    $stmt->close();
    
    ok([
        'class' => $class,
        'date' => $date,
        'students' => $students,
        'count' => count($students)
    ]);
}

// ============================================================
// POST /attendance — Save attendance records
// ============================================================
if ($method === 'POST' && ($action === '' || $action === null)) {
    $input = getBody();
    $classId = (int)($input['class_id'] ?? 0);
    $date = validateDate($input['date'] ?? '', date('Y-m-d'));
    $records = $input['records'] ?? [];
    
    if (!$classId || empty($records)) err('class_id and records array are required');
    if (!is_array($records)) err('records must be an array');
    
    $year = getCurrentAcademicYear();
    $yearId = $year ? $year['id'] : null;
    
    // Delete existing records for this class+date (replace pattern)
    $stmt = $conn->prepare("DELETE FROM attendance WHERE class_id = ? AND attendance_date = ?");
    if (!$stmt) err('Database error: ' . $conn->error, 500);
    $stmt->bind_param('is', $classId, $date);
    $stmt->execute();
    $stmt->close();
    
    // Insert new records
    $ins = $conn->prepare("INSERT INTO attendance 
        (member_id, class_id, academic_year_id, attendance_date, status, notes, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$ins) err('Database error: ' . $conn->error, 500);
    
    $saved = 0;
    $errors = [];
    $userId = $auth['uid'];
    
    foreach ($records as $rec) {
        $memberId = (int)($rec['member_id'] ?? 0);
        $status = validateEnum($rec['status'] ?? '', ['present', 'absent', 'late', 'excused'], 'present');
        $note = trim($rec['note'] ?? $rec['notes'] ?? '');
        
        if (!$memberId) continue;
        
        try {
            $ins->bind_param('iiisssi', $memberId, $classId, $yearId, $date, $status, $note, $userId);
            $ins->execute();
            $saved++;
        } catch (Exception $e) {
            $errors[] = "Member {$memberId}: " . $e->getMessage();
        }
    }
    $ins->close();
    
    logApiAction($auth['uid'], $auth['usr'], 'Attendance Saved', "Class: {$classId}, Date: {$date}, Records: {$saved}");
    
    ok([
        'message' => "{$saved} attendance records saved",
        'saved' => $saved,
        'errors' => $errors,
        'class_id' => $classId,
        'date' => $date
    ], 201);
}

// ============================================================
// GET /attendance/daily-stats?date=Y — Per-class daily breakdown
// ============================================================
if ($action === 'daily-stats' && $method === 'GET') {
    $date = validateDate($_GET['date'] ?? '', date('Y-m-d'));
    
    $stmt = $conn->prepare("SELECT c.id as class_id, c.class_name,
                                   COUNT(DISTINCT a.member_id) as recorded,
                                   COALESCE(SUM(a.status='present'),0) as present,
                                   COALESCE(SUM(a.status='absent'),0) as absent,
                                   COALESCE(SUM(a.status='late'),0) as late
                            FROM classes c
                            LEFT JOIN attendance a ON a.class_id = c.id AND a.attendance_date = ?
                            WHERE c.is_active = 1
                            GROUP BY c.id ORDER BY c.level_order");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stats = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['recorded'] = (int)$row['recorded'];
        $row['present'] = (int)$row['present'];
        $row['absent'] = (int)$row['absent'];
        $row['late'] = (int)$row['late'];
        $stats[] = $row;
    }
    $stmt->close();
    
    ok(['date' => $date, 'classes' => $stats]);
}

// ============================================================
// GET /attendance/summary?class_id=X&month=YYYY-MM
// ============================================================
if ($action === 'summary' && $method === 'GET') {
    $classId = (int)($_GET['class_id'] ?? 0);
    $month = validateMonth($_GET['month'] ?? '', date('Y-m'));
    
    if (!$classId) err('class_id is required');
    
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
    
    $stmt = $conn->prepare("SELECT a.member_id, m.student_name, m.father_name,
                                   COUNT(*) as total_days,
                                   SUM(a.status='present') as present,
                                   SUM(a.status='absent') as absent,
                                   SUM(a.status='late') as late
                            FROM attendance a JOIN members m ON a.member_id = m.id
                            WHERE a.class_id = ? AND a.attendance_date BETWEEN ? AND ?
                            GROUP BY a.member_id ORDER BY m.student_name");
    $stmt->bind_param('iss', $classId, $startDate, $endDate);
    $stmt->execute();
    $summary = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['total_days'] = (int)$row['total_days'];
        $row['present'] = (int)$row['present'];
        $row['absent'] = (int)$row['absent'];
        $row['late'] = (int)$row['late'];
        $row['rate'] = $row['total_days'] > 0 ? round($row['present'] / $row['total_days'] * 100, 1) : 0;
        $summary[] = $row;
    }
    $stmt->close();
    
    ok(['class_id' => $classId, 'month' => $month, 'summary' => $summary]);
}

err("No handler for {$method} /attendance" . ($action ? "/{$action}" : ''), 404);
