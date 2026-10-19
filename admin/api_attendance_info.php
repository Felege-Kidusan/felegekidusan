<?php
/**
 * ============================================================
 * School Info Dept Attendance & Status API
 * ============================================================
 * For the Information Department to MONITOR attendance and
 * MANAGE member status. Does NOT take attendance (that's
 * the attendance_taker role via api_attendance.php).
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = is_string($_REQUEST['action'] ?? '') ? ($_REQUEST['action'] ?? '') : '';
requirePostActions($action, ['update_status', 'bulk_status']);
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// CSRF protection for all POST requests
requireCsrfForPost();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// ── Member-status writes: HR and Information ONLY (owner decision 2026-10-19,
// ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS §2 / §7). Education, teachers and
// attendance takers keep the read-only monitoring actions of this API; admins
// do not change member status through the attendance workflow. Enforced here,
// server-side, independent of which dashboard rendered the button.
const ATTENDANCE_STATUS_WRITER_ROLES = ['hr_dept', 'info_dept'];
// Manual values allowed through this workflow. 'warning' is no longer set by
// hand or by automation here; 'archived' has its own archive/restore flow.
const ATTENDANCE_MANUAL_STATUSES = ['active', 'inactive'];
if (in_array($action, ['update_status', 'bulk_status'], true)) {
    $actorRole = (string)($_SESSION['admin_role'] ?? '');
    if (!in_array($actorRole, ATTENDANCE_STATUS_WRITER_ROLES, true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only HR and Information may change member status.']);
        exit;
    }
    require_once __DIR__ . '/backend/services/SecurityAuditService.php';
}

// Ensure attendance table exists (safe check)
try { $conn->query("SELECT 1 FROM attendance LIMIT 0"); } catch (Exception $e) {
    // Table doesn't exist - return empty data gracefully
    echo json_encode(['status' => 'success', 'message' => 'Attendance tables not set up yet. Run migration 002 first.', 'data' => []]);
    exit;
}

try {
switch ($action) {

// ============================================================
case 'overview':
// ============================================================
    $data = [];
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $monthAgo = date('Y-m-d', strtotime('-30 days'));

    // Today's stats
    $r = $conn->query("SELECT 
        COUNT(DISTINCT member_id) as recorded,
        COALESCE(SUM(status='present'),0) as present_cnt,
        COALESCE(SUM(status='absent'),0) as absent_cnt,
        COALESCE(SUM(status='late'),0) as late_cnt,
        COALESCE(SUM(status='excused'),0) as excused_cnt
        FROM attendance WHERE attendance_date = '$today'");
    $data['today'] = $r ? $r->fetch_assoc() : ['recorded'=>0,'present_cnt'=>0,'absent_cnt'=>0,'late_cnt'=>0,'excused_cnt'=>0];

    // Total active members (for attendance rate calc)
    $r = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE status IN ('active','warning')");
    $data['total_active'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Member status breakdown
    $r = $conn->query("SELECT 
        COALESCE(SUM(status='active'),0) as active_cnt,
        COALESCE(SUM(status='warning'),0) as warning_cnt,
        COALESCE(SUM(status='inactive'),0) as inactive_cnt,
        COALESCE(SUM(status='archived'),0) as archived_cnt
        FROM members");
    $data['member_status'] = $r ? $r->fetch_assoc() : [];

    // Last 7 days attendance trend
    $r = $conn->query("SELECT attendance_date as day,
        COUNT(DISTINCT member_id) as total,
        COALESCE(SUM(status='present'),0) as present_cnt,
        COALESCE(SUM(status='absent'),0) as absent_cnt
        FROM attendance WHERE attendance_date >= '$weekAgo'
        GROUP BY attendance_date ORDER BY attendance_date ASC");
    $data['week_trend'] = [];
    if ($r) while ($row = $r->fetch_assoc()) $data['week_trend'][] = $row;

    // Last 4 weeks summary
    $r = $conn->query("SELECT 
        YEARWEEK(attendance_date,1) as wk,
        MIN(attendance_date) as week_start,
        COUNT(DISTINCT member_id) as unique_members,
        COUNT(*) as total_records,
        COALESCE(SUM(status='present'),0) as present_cnt,
        COALESCE(SUM(status='absent'),0) as absent_cnt
        FROM attendance WHERE attendance_date >= '$monthAgo'
        GROUP BY YEARWEEK(attendance_date,1) ORDER BY wk ASC");
    $data['monthly_weeks'] = [];
    if ($r) while ($row = $r->fetch_assoc()) $data['monthly_weeks'][] = $row;

    // Top absentees (last 30 days)
    $r = $conn->query("SELECT a.member_id, m.student_name, m.father_name, m.member_code, m.status as member_status, m.age_group,
        SUM(a.status='absent') as absent_days, SUM(a.status='present') as present_days, COUNT(*) as total_days
        FROM attendance a JOIN members m ON a.member_id = m.id
        WHERE a.attendance_date >= '$monthAgo' AND m.status != 'archived'
        GROUP BY a.member_id HAVING absent_days >= 2
        ORDER BY absent_days DESC LIMIT 20");
    $data['top_absentees'] = [];
    if ($r) while ($row = $r->fetch_assoc()) {
        $row['rate'] = $row['total_days'] > 0 ? round(($row['present_days']/$row['total_days'])*100,1) : 0;
        $data['top_absentees'][] = $row;
    }

    // Never attended (registered but never in attendance table)
    $r = $conn->query("SELECT COUNT(*) as cnt FROM members m 
        WHERE m.status IN ('active','warning') 
        AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.member_id = m.id)");
    $data['never_attended'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;

    // Recent attendance records (last 5 days)
    $r = $conn->query("SELECT DISTINCT attendance_date FROM attendance ORDER BY attendance_date DESC LIMIT 5");
    $data['recent_dates'] = [];
    if ($r) while ($row = $r->fetch_assoc()) $data['recent_dates'][] = $row['attendance_date'];

    echo json_encode(['status' => 'success', 'data' => $data]);
    break;

// ============================================================
case 'member_attendance':
// ============================================================
    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) { echo json_encode(['status'=>'error','message'=>'Member ID required']); break; }

    // Get member info
    $stmt = $conn->prepare("SELECT id, member_code, student_name, father_name, grandfather_name, gender, age_group, status, phone_number FROM members WHERE id = ?");
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$member) { echo json_encode(['status'=>'error','message'=>'Member not found']); break; }

    // Get attendance records (last 90 days)
    $stmt = $conn->prepare("SELECT attendance_date, status, notes, check_in_time FROM attendance WHERE member_id = ? AND attendance_date >= DATE_SUB(NOW(), INTERVAL 90 DAY) ORDER BY attendance_date DESC");
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $records = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $records[] = $row;
    $stmt->close();

    // Stats
    $total = count($records);
    $present = count(array_filter($records, fn($r) => $r['status'] === 'present'));
    $absent = count(array_filter($records, fn($r) => $r['status'] === 'absent'));
    $late = count(array_filter($records, fn($r) => $r['status'] === 'late'));
    $rate = $total > 0 ? round(($present / $total) * 100, 1) : 0;

    echo json_encode(['status'=>'success', 'member'=>$member, 'records'=>$records, 'stats'=>['total'=>$total,'present'=>$present,'absent'=>$absent,'late'=>$late,'rate'=>$rate]]);
    break;

// ============================================================
case 'search_members':
// ============================================================
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $conn->prepare("SELECT id, member_code, student_name, father_name, gender, age_group, status, phone_number 
        FROM members WHERE status != 'archived' AND (student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ? OR phone_number LIKE ?)
        ORDER BY student_name LIMIT 30");
    $stmt->bind_param('ssss', $q, $q, $q, $q);
    $stmt->execute();
    $members = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $members[] = $row;
    $stmt->close();
    echo json_encode(['status'=>'success', 'members'=>$members]);
    break;

// ============================================================
case 'update_status':
// ============================================================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required']); break; }
    $input = json_decode(file_get_contents('php://input'), true);
    $memberId = (int)($input['member_id'] ?? 0);
    $newStatus = $input['new_status'] ?? '';
    $reason = trim($input['reason'] ?? '');

    if (!$memberId || !is_string($newStatus) || !in_array($newStatus, ATTENDANCE_MANUAL_STATUSES, true)) {
        echo json_encode(['status'=>'error','message'=>'Valid member_id and status (active or inactive) required']);
        break;
    }
    if ($newStatus === 'inactive' && mb_strlen($reason) < 3) {
        echo json_encode(['status'=>'error','message'=>'A reason is required to deactivate a member.']);
        break;
    }

    // Get old status
    $stmt = $conn->prepare("SELECT status, student_name, father_name FROM members WHERE id = ?");
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old) { echo json_encode(['status'=>'error','message'=>'Member not found']); break; }

    if ($old['status'] === 'archived') {
        echo json_encode(['status'=>'error','message'=>'Archived members are restored through the archive workflow.']);
        break;
    }

    $stmt = $conn->prepare("UPDATE members SET status = ? WHERE id = ? AND status != 'archived'");
    $stmt->bind_param('si', $newStatus, $memberId);
    if ($stmt->execute()) {
        // Log the change
        $details = "Status changed from '{$old['status']}' to '$newStatus'" . ($reason ? " — Reason: $reason" : '');
        $memberName = $old['student_name'] . ' ' . $old['father_name'];
        \App\Services\SecurityAuditService::record($conn, 'Member Status Changed', [
            'member_id' => $memberId,
            'from' => (string)$old['status'],
            'to' => $newStatus,
            'reason' => mb_substr($reason, 0, 200),
            'via' => 'attendance_info',
        ], 'member', $memberId);
        try {
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, entity_type, entity_id, ip_address) VALUES (?, ?, 'Status Change', ?, 'member', ?, ?)");
            $un = $_SESSION['admin_username'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $log->bind_param('issis', $adminId, $un, $details, $memberId, $ip);
            $log->execute();
            $log->close();
        } catch (Exception $e) {}
        echo json_encode(['status'=>'success','message'=>"$memberName status updated to $newStatus"]);
    } else {
        echo json_encode(['status'=>'error','message'=>'Failed to update status']);
    }
    $stmt->close();
    break;

// ============================================================
case 'bulk_status':
// ============================================================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required']); break; }
    $input = json_decode(file_get_contents('php://input'), true);
    $memberIds = $input['member_ids'] ?? [];
    $newStatus = $input['new_status'] ?? '';
    $reason = trim($input['reason'] ?? '');

    if (!is_array($memberIds) || empty($memberIds) || !is_string($newStatus) || !in_array($newStatus, ATTENDANCE_MANUAL_STATUSES, true)) {
        echo json_encode(['status'=>'error','message'=>'member_ids array and valid status (active or inactive) required']);
        break;
    }
    if (count($memberIds) > 200) {
        echo json_encode(['status'=>'error','message'=>'At most 200 members per bulk change.']);
        break;
    }
    if ($newStatus === 'inactive' && mb_strlen($reason) < 3) {
        echo json_encode(['status'=>'error','message'=>'A reason is required to deactivate members.']);
        break;
    }

    $updated = 0;
    $changedIds = [];
    $stmt = $conn->prepare("UPDATE members SET status = ? WHERE id = ? AND status != 'archived'");
    foreach ($memberIds as $mid) {
        $mid = (int)$mid;
        if ($mid <= 0) continue;
        $stmt->bind_param('si', $newStatus, $mid);
        $stmt->execute();
        if ($stmt->affected_rows > 0) { $updated++; $changedIds[] = $mid; }
    }
    $stmt->close();
    \App\Services\SecurityAuditService::record($conn, 'Member Status Bulk Changed', [
        'to' => $newStatus,
        'count' => $updated,
        'member_ids' => array_slice($changedIds, 0, 50),
        'reason' => mb_substr($reason, 0, 200),
        'via' => 'attendance_info',
    ], 'member', null);

    // Log bulk change
    try {
        $det = "Bulk status change to '$newStatus' for $updated members" . ($reason ? " — $reason" : '');
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, 'Bulk Status Change', ?, ?)");
        if ($logStmt) {
            $logUsername = $_SESSION['admin_username'] ?? '';
            $logIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $logStmt->bind_param("isss", $adminId, $logUsername, $det, $logIp);
            $logStmt->execute();
            $logStmt->close();
        }
    } catch (Exception $e) {}

    echo json_encode(['status'=>'success','message'=>"$updated members updated to $newStatus"]);
    break;

// ============================================================
case 'at_risk_members':
// ============================================================
    // Attendance alerting Phase 4: the SAME source as the Monthly attendance
    // report / warnings engine — attendance_month_rollup (shared formula
    // present + excused + 0.5·late, policy low_rate_threshold + min_sessions,
    // Ethiopian month, per department). No local rate/threshold maths here.
    require_once __DIR__ . '/backend/services/AttendanceMonthRollupService.php';
    $riskDept = (string)($_GET['department'] ?? 'edu');
    if (!in_array($riskDept, \App\Services\AttendanceRiskService::DEPARTMENTS, true)) {
        $riskDept = 'edu';
    }
    $nowEc = \App\Services\AttendanceRiskService::ecOf(date('Y-m-d'));
    $riskYear = isset($_GET['ec_year']) && $_GET['ec_year'] !== '' ? (int)$_GET['ec_year'] : (int)$nowEc['year'];
    $riskMonth = isset($_GET['ec_month']) && $_GET['ec_month'] !== '' ? (int)$_GET['ec_month'] : (int)$nowEc['month'];
    if ($riskYear < 1990 || $riskYear > (int)$nowEc['year'] + 1 || $riskMonth < 1 || $riskMonth > 13) {
        $riskYear = (int)$nowEc['year'];
        $riskMonth = (int)$nowEc['month'];
    }
    $riskOut = [
        'status' => 'success',
        'available' => \App\Services\AttendanceMonthRollupService::available($conn),
        'department' => $riskDept,
        'department_label' => \App\Services\AttendanceRiskService::LABELS[$riskDept],
        'ec_year' => $riskYear, 'ec_month' => $riskMonth,
        'month_label' => ethio_month_name($riskMonth) . ' ' . $riskYear,
        'month_label_am' => \App\Services\AttendanceRiskService::monthLabelAm($riskYear, $riskMonth),
        'report_url' => '/admin/attendance_monthly.php?department=' . $riskDept . '&ec_year=' . $riskYear . '&ec_month=' . $riskMonth . '&below=1',
        'members' => [], 'threshold' => null, 'min_sessions' => null, 'total' => 0,
    ];
    if ($riskOut['available']) {
        \App\Services\AttendanceMonthRollupService::ensureFresh($conn, $riskDept, $riskYear, $riskMonth);
        $riskSummary = \App\Services\AttendanceMonthRollupService::summary($conn, $riskDept, $riskYear, $riskMonth);
        $riskPage = \App\Services\AttendanceMonthRollupService::listMonth($conn, $riskDept, $riskYear, $riskMonth, ['below' => true, 'limit' => 500]);
        $riskOut['threshold'] = $riskSummary['threshold'];
        $riskOut['min_sessions'] = $riskSummary['min_sessions'];
        $riskOut['total'] = $riskPage['total'];
        foreach ($riskPage['items'] as $it) {
            $riskOut['members'][] = [
                'id' => $it['member_id'],
                'student_name' => $it['student_name'],
                'father_name' => $it['father_name'],
                'member_code' => $it['member_code'],
                'gender' => $it['gender'],
                'group' => $it['group'],
                'status' => $it['member_status'],
                'department_status' => $it['department_status'],
                'sessions' => $it['sessions'],
                'present_days' => $it['present'],
                'absent_days' => $it['absent'],
                'late' => $it['late'],
                'excused' => $it['excused'],
                'rate' => $it['rate'],
                'band' => $it['band'],
                'open_warnings' => $it['open_warnings'],
            ];
        }
    }
    echo json_encode($riskOut, JSON_UNESCAPED_UNICODE);
    break;

// ============================================================
case 'daily_report':
// ============================================================
    $date = $_GET['date'] ?? date('Y-m-d');
    $stmt = $conn->prepare("SELECT a.member_id, a.status, a.notes, a.check_in_time,
        m.student_name, m.father_name, m.member_code, m.gender, m.age_group, m.status as member_status
        FROM attendance a JOIN members m ON a.member_id = m.id
        WHERE a.attendance_date = ? ORDER BY m.student_name ASC");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $records = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $records[] = $row;
    $stmt->close();

    $p = count(array_filter($records, fn($r) => $r['status'] === 'present'));
    $ab = count(array_filter($records, fn($r) => $r['status'] === 'absent'));
    $lt = count(array_filter($records, fn($r) => $r['status'] === 'late'));

    echo json_encode(['status'=>'success','date'=>$date,'records'=>$records,'summary'=>['total'=>count($records),'present'=>$p,'absent'=>$ab,'late'=>$lt]]);
    break;

default:
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error']);
}
if (isset($conn) && $conn instanceof mysqli) $conn->close();
