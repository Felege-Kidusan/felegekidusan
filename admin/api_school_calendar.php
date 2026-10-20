<?php
/**
 * admin/api_school_calendar.php — School calendar ("Day Off" + holidays).
 *
 * Class-day-only attendance (owner decisions 2026-10-20; plan
 * docs/planning/ATTENDANCE_CLASS_DAY_ENFORCEMENT_PLAN_2026-10-20.md).
 *
 * GET  ?action=date_status&class_id=&date=       one date verdict for UIs
 * GET  ?action=class_days&class_id=&ec_year=&ec_month=   month grid statuses
 * GET  ?action=overview&ec_year=&ec_month=       holidays + day offs + unconfigured classes
 * GET  ?action=holidays&from=&to=                planned holidays in a range
 * GET  ?action=day_offs&from=&to=                effective Day Off days in a range
 * POST action=save_holiday    id?, title, title_am?, date_from, date_to?, type?
 * POST action=delete_holiday  id
 * POST action=mark_day_off    date, cancel_makeup (0/1), reason
 * POST action=reopen_day_off  date, reason?
 *
 * WRITES: Education dept ONLY (owner decision 5). Reads: everyone who works
 * with attendance. Until sql/078 is applied the read actions report
 * available=false and writes refuse with a clear message.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/academic_year.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/SchoolCalendarService.php';

use App\Services\SchoolCalendarService;

header('Content-Type: application/json; charset=utf-8');

$fail = static function (int $code, string $message, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
};
$ok = static function (array $data): never {
    echo json_encode(['status' => 'success'] + $data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    $fail(401, 'Unauthorized');
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, SchoolCalendarService::READ_ROLES, true)) {
    $fail(403, 'You do not have permission to view the school calendar.');
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$input = $_POST;
if ($method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($json)) {
        $input = $json + $_POST;
        $action = (string)($json['action'] ?? $action);
    }
}

$readActions = ['date_status', 'class_days', 'overview', 'holidays', 'day_offs', 'classes'];
$writeActions = ['save_holiday', 'delete_holiday', 'mark_day_off', 'reopen_day_off'];
if (!in_array($action, array_merge($readActions, $writeActions), true)) {
    $fail(400, 'Unknown action');
}
if (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        $fail(405, 'Use POST for changes.');
    }
    if (!in_array($role, SchoolCalendarService::WRITE_ROLES, true)) {
        $fail(403, 'Only the Education department can change the school calendar.');
    }
    $token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!validateCsrf($token)) {
        $fail(403, 'Security token expired. Please refresh the page.');
    }
}
if (isset($pdo) && $pdo instanceof PDO) {
    $rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
    $check = $rl->consume($method === 'POST' ? 'sc_write' : 'sc_read', 'user:' . $adminId, $method === 'POST' ? 60 : 240, 60);
    if (!$check['allowed']) {
        $fail(429, 'Too many requests. Please wait a moment and try again.');
    }
}

$available = SchoolCalendarService::tablesReady($conn);
$meta = ['available' => $available, 'role' => $role, 'backdate_days' => SchoolCalendarService::BACKDATE_DAYS];
$currentYear = function_exists('ay_resolve') ? ay_resolve($conn)['year'] : null;

$dateParam = static function (string $raw) use ($fail): string {
    $d = trim($raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) {
        $fail(400, 'A valid date is required.');
    }
    return $d;
};
$monthWindow = static function (array $q) use ($fail): array {
    $ecYear = (int)($q['ec_year'] ?? 0);
    $ecMonth = (int)($q['ec_month'] ?? 0);
    if ($ecYear <= 0 || $ecMonth < 1 || $ecMonth > 13) {
        $fail(400, 'ec_year and ec_month are required.');
    }
    if (!class_exists('\App\Services\AttendanceRiskService')) {
        require_once __DIR__ . '/backend/services/AttendanceRiskService.php';
    }
    $w = \App\Services\AttendanceRiskService::ecMonthWindow($ecYear, $ecMonth);
    if (empty($w['from']) || empty($w['to'])) {
        $fail(400, 'That Ethiopian month could not be resolved.');
    }
    return [substr((string)$w['from'], 0, 10), substr((string)$w['to'], 0, 10)];
};

$auditActor = \App\Services\SecurityAuditActor::fromAuthenticatedContext(
    $adminId, (string)($_SESSION['admin_username'] ?? ''), 'admin', $_SERVER
);

switch ($action) {
    case 'date_status':
        $classId = (int)($_GET['class_id'] ?? 0);
        $date = $dateParam((string)($_GET['date'] ?? SchoolCalendarService::today()));
        if ($classId <= 0) {
            $fail(400, 'class_id is required.');
        }
        $ok($meta + ['date_status' => SchoolCalendarService::classDayStatus($conn, $classId, $date)]);

    case 'class_days':
        $classId = (int)($_GET['class_id'] ?? 0);
        if ($classId <= 0) {
            $fail(400, 'class_id is required.');
        }
        if (isset($_GET['from']) && isset($_GET['to'])) {
            $from = $dateParam((string)$_GET['from']);
            $to = $dateParam((string)$_GET['to']);
        } else {
            [$from, $to] = $monthWindow($_GET);
        }
        $ok($meta + ['from' => $from, 'to' => $to,
            'days' => SchoolCalendarService::classDaysForMonth($conn, $classId, $from, $to)]);

    case 'overview':
        if (isset($_GET['from']) && isset($_GET['to'])) {
            $from = $dateParam((string)$_GET['from']);
            $to = $dateParam((string)$_GET['to']);
        } else {
            [$from, $to] = $monthWindow($_GET);
        }
        $todayEc = gregorian_to_ethiopian(SchoolCalendarService::today());
        $ok($meta + [
            'from' => $from, 'to' => $to,
            'school_days' => SchoolCalendarService::schoolDaysForRange($conn, $from, $to),
            'weekday_class_counts' => SchoolCalendarService::weekdayClassCounts($conn),
            'holidays' => SchoolCalendarService::holidays($conn, $from, $to),
            'day_offs' => SchoolCalendarService::dayOffs($conn, $from, $to),
            'unconfigured_classes' => SchoolCalendarService::unconfiguredClasses($conn),
            'today' => SchoolCalendarService::today(),
            'today_ec' => SchoolCalendarService::ecLabel(SchoolCalendarService::today()),
            'today_ec_year' => (int)$todayEc['year'],
            'today_ec_month' => (int)$todayEc['month'],
            'today_ec_day' => (int)$todayEc['day'],
        ]);

    case 'classes':
        $res = $conn->query(
            "SELECT id, class_name, class_name_en FROM classes WHERE is_active = 1 ORDER BY level_order, class_name"
        );
        $classes = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $classes[] = ['id' => (int)$row['id'], 'name' => (string)($row['class_name_en'] ?: $row['class_name'])];
            }
            $res->free();
        }
        $ok($meta + ['classes' => $classes]);

    case 'holidays':
        $from = $dateParam((string)($_GET['from'] ?? SchoolCalendarService::today()));
        $to = $dateParam((string)($_GET['to'] ?? $from));
        $ok($meta + ['holidays' => SchoolCalendarService::holidays($conn, $from, $to)]);

    case 'day_offs':
        $from = $dateParam((string)($_GET['from'] ?? SchoolCalendarService::today()));
        $to = $dateParam((string)($_GET['to'] ?? $from));
        $ok($meta + ['day_offs' => SchoolCalendarService::dayOffs($conn, $from, $to)]);

    case 'save_holiday':
        $res = SchoolCalendarService::saveHoliday($conn, [
            'id'        => (int)($input['id'] ?? 0),
            'title'     => (string)($input['title'] ?? ''),
            'title_am'  => (string)($input['title_am'] ?? ''),
            'date_from' => (string)($input['date_from'] ?? ''),
            'date_to'   => (string)($input['date_to'] ?? ''),
            'type'      => (string)($input['type'] ?? 'holiday'),
        ], $auditActor);
        if (empty($res['ok'])) {
            $fail(422, (string)($res['message'] ?? 'Could not save the holiday.'));
        }
        $ok(['message' => $res['message'], 'id' => $res['id'] ?? 0]);

    case 'delete_holiday':
        $res = SchoolCalendarService::deleteHoliday($conn, (int)($input['id'] ?? 0), $auditActor);
        if (empty($res['ok'])) {
            $fail(422, (string)($res['message'] ?? 'Could not delete the holiday.'));
        }
        $ok(['message' => $res['message']]);

    case 'mark_day_off':
        $date = $dateParam((string)($input['date'] ?? ''));
        $cancelMakeup = !in_array((string)($input['cancel_makeup'] ?? '1'), ['0', 'false', 'no'], true);
        $reason = (string)($input['reason'] ?? '');
        $res = SchoolCalendarService::markDayOff($conn, $date, $cancelMakeup, $reason, $auditActor);
        if (empty($res['ok'])) {
            $fail(422, (string)($res['message'] ?? 'Could not mark the day off.'), ['code' => $res['code'] ?? 'REJECTED']);
        }
        $ok(['message' => $res['message'], 'date' => $date]);

    case 'reopen_day_off':
        $date = $dateParam((string)($input['date'] ?? ''));
        $reason = (string)($input['reason'] ?? '');
        $res = SchoolCalendarService::reopenDayOff($conn, $date, $reason, $auditActor);
        if (empty($res['ok'])) {
            $fail(422, (string)($res['message'] ?? 'Could not reopen the day.'));
        }
        $ok(['message' => $res['message'], 'date' => $date]);
}
