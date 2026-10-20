<?php
/**
 * School API v1 — Teacher timetable (read-only)
 *
 *   GET /timetable/week    weekly grid of the signed-in teacher + upcoming makeup classes
 *   GET /timetable/today   today's lessons (recurring grid for today's weekday + makeups dated today)
 *
 * Education / School Admin / Super Admin may pass ?teacher_id= to look at a
 * teacher's schedule; teachers are always forced onto their own id.
 * Source of truth: TimetableService (same rules and tables as the web
 * builder — sql/007 + sql/077). Dates are Gregorian strings for storage
 * compatibility and carry Ethiopian labels (*_ec) for display.
 */

require_once dirname(__DIR__, 3) . '/admin/backend/services/TimetableService.php';
require_once dirname(__DIR__, 3) . '/admin/backend/ethiopian_date.php';
if (!class_exists('\\App\\Services\\TimetableService')) {
    err('Timetable is temporarily unavailable.', 503);
}

$auth = apiRequireAuth();
if (!apiRoleIs($auth, array_merge(apiRolesEducation(), ['teacher']))) {
    err('You cannot view the timetable.', 403);
}
if ($method !== 'GET') {
    err("No handler for {$method} /timetable", 405);
}
if (isApiRateLimited('timetable', 120)) {
    err('Too many requests. Please wait a moment.', 429);
}

$year = getCurrentAcademicYear();
if (!$year) {
    err('No active academic year. Set one in Settings.', 400);
}
$yearId = (int)$year['id'];

$teacherId = (int)$auth['uid'];
if (apiRoleIs($auth, apiRolesEducation()) && !empty($_GET['teacher_id'])) {
    $teacherId = (int)$_GET['teacher_id'];
}

$sub = $ROUTE['id'] ?? null; // 'week' | 'today'
if ($sub !== 'week' && $sub !== 'today') {
    err("No handler for GET /timetable/{$sub}. Use /timetable/week or /timetable/today.", 404);
}

$sched = \App\Services\TimetableService::teacherSchedule($conn, $teacherId, $yearId);
$lessons = $sched['lessons'];
$makeups = $sched['makeups'];
$todayIso = (int)$sched['today_iso'];
$todayDate = (string)$sched['today_date'];

$ecLabel = static function (string $ymd): string {
    try {
        $dow = (int)(new \DateTime($ymd))->format('N');
        return \App\Services\TimetableService::dayName($dow) . ', ' . ethio_date_format($ymd, 'F j, Y');
    } catch (\Throwable $e) {
        return $ymd;
    }
};
foreach ($makeups as &$m) {
    $m['lesson_date_ec'] = $ecLabel($m['lesson_date']);
}
unset($m);

$days = [];
foreach ($lessons as $l) {
    $days[$l['day_of_week']][] = $l;
}
ksort($days);

$payload = [
    'available'        => (bool)($sched['available'] ?? true),  // false before sql/077
    'teacher_id'       => $teacherId,
    'academic_year_id' => $yearId,
    'year_name'        => (string)($year['year_name'] ?? ''),
    'today_iso'        => $todayIso,
    'today_date'       => $todayDate,
    'today_date_ec'    => $ecLabel($todayDate),
    'working_days'     => array_values(array_map('intval', array_keys($days))),
];

if ($sub === 'today') {
    $payload['lessons'] = $days[$todayIso] ?? [];
    $payload['makeup_classes'] = array_values(array_filter($makeups, static fn($m) => $m['lesson_date'] === $todayDate));
    ok($payload);
}

$payload['days'] = (object)$days;      // {"6":[...], "7":[...]}
$payload['lessons'] = $lessons;
$payload['makeup_classes'] = $makeups;
ok($payload);
