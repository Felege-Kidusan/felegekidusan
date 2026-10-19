<?php
/**
 * ============================================================
 * Monthly attendance report API (Attendance alerting — Phase 4)
 * ============================================================
 *   GET ?action=months&department=edu|mezmur            → EC months offered by the picker
 *   GET ?action=summary&department=&ec_year=&ec_month=  → band counts, below-threshold count, policy
 *   GET ?action=list&department=&ec_year=&ec_month=[&below=1&band=&group=&q=&limit=&offset=]
 *   GET ?action=groups&department=&ec_year=&ec_month=   → classes (Education) / sections (Mezmur) in the month
 *   GET ?action=member&department=&member_id=N          → the member's months (drill-down)
 *   GET ?action=export&department=&ec_year=&ec_month=[&below=1&band=&group=&q=&format=xlsx|csv]
 *
 * Data comes from the derived table `attendance_month_rollup` (sql/074) which
 * caches the SHARED risk formula (AttendanceRiskService — present + excused +
 * 0.5·late over eligible sessions, bands 90/75/50, policy threshold). The
 * month is recomputed lazily when stale (AttendanceMonthRollupService::ensureFresh)
 * — no cron on shared hosting. `refresh=1` forces a recompute.
 *
 * Role scoping (requirements §8, §9) — server side, same as the warnings API:
 *   edu_dept → Education only · mezmur_dept → Mezmur only ·
 *   hr_dept / info_dept / school_admin / super_admin → both. READ-ONLY for all.
 * Every user-facing date / month label is Ethiopian calendar; the export has
 * no Gregorian column (test_ec_only_exports).
 * Until FEATURE_ATTENDANCE_V2 is on and sql/074 is applied every action
 * answers {status:'success', available:false}.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/services/AttendanceWarningService.php';
require_once __DIR__ . '/backend/services/AttendanceMonthRollupService.php';
require_once __DIR__ . '/backend/reporting/ReportExportService.php';

use App\Services\AttendanceMonthRollupService;
use App\Services\AttendanceRiskService;
use App\Services\AttendanceWarningService;

$action = (string)($_GET['action'] ?? '');
$isExport = $action === 'export';
if (!$isExport) {
    header('Content-Type: application/json; charset=utf-8');
}
header('Cache-Control: no-store');

$fail = static function (int $code, string $message): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};
$ok = static function (array $data): never {
    echo json_encode(['status' => 'success'] + $data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    $fail(401, 'Unauthorized');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $fail(405, 'This report is read-only.');
}
$adminId = (int)$_SESSION['admin_id'];
$role = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($role, AttendanceWarningService::READ_ROLES, true)) {
    $fail(403, 'You do not have permission to use this report.');
}
$myDepartments = AttendanceWarningService::departmentsForRole($role);

if (!in_array($action, ['months', 'summary', 'list', 'groups', 'member', 'export'], true)) {
    $fail(400, 'Unknown action');
}

if (isset($pdo) && $pdo instanceof PDO) {
    $rl = new \App\Services\SecurityRateLimiter($pdo, sys_get_temp_dir() . '/ssms_ratelimit');
    $check = $rl->consume($isExport ? 'att_month_export' : 'att_month_read', 'user:' . $adminId, $isExport ? 20 : 240, 60);
    if (!$check['allowed']) {
        $fail(429, 'Too many requests. Please wait a moment and try again.');
    }
}

$available = AttendanceMonthRollupService::available($conn);
$meta = ['available' => $available, 'departments' => $myDepartments];

/** Requested department ∩ allowed (one department per call — reports are per department). */
$department = trim((string)($_GET['department'] ?? ''));
if ($department === '') {
    $department = $myDepartments[0] ?? '';
}
if (!in_array($department, AttendanceRiskService::DEPARTMENTS, true)) {
    $fail(400, 'department must be edu or mezmur.');
}
if (!in_array($department, $myDepartments, true)) {
    $fail(403, 'You cannot view ' . AttendanceRiskService::LABELS[$department] . ' attendance.');
}
$meta['department'] = $department;
$meta['department_label'] = AttendanceRiskService::LABELS[$department];

/** EC month from the query (defaults to the current EC month). @return array{0:int,1:int} */
$monthArg = static function () use ($fail): array {
    $now = AttendanceRiskService::ecOf(date('Y-m-d'));
    $y = isset($_GET['ec_year']) && $_GET['ec_year'] !== '' ? (int)$_GET['ec_year'] : (int)$now['year'];
    $m = isset($_GET['ec_month']) && $_GET['ec_month'] !== '' ? (int)$_GET['ec_month'] : (int)$now['month'];
    if ($y < 1990 || $y > (int)$now['year'] + 1 || $m < 1 || $m > 13) {
        $fail(400, 'ec_year / ec_month out of range (months are 1–13, 13 = Pagume).');
    }
    return [$y, $m];
};
$filtersArg = static function (): array {
    return [
        'below' => (string)($_GET['below'] ?? '') === '1',
        'band' => (string)($_GET['band'] ?? ''),
        'group' => (string)($_GET['group'] ?? ''),
        'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60),
        'limit' => (int)($_GET['limit'] ?? 100),
        'offset' => (int)($_GET['offset'] ?? 0),
    ];
};

switch ($action) {
    case 'months': {
        if (!$available) {
            $ok($meta + ['months' => []]);
        }
        $ok($meta + ['months' => AttendanceMonthRollupService::months($conn, $department)]);
    }
    // no fallthrough — $ok exits

    case 'summary': {
        [$y, $m] = $monthArg();
        if (!$available) {
            $ok($meta + ['summary' => null]);
        }
        $refreshed = AttendanceMonthRollupService::ensureFresh($conn, $department, $y, $m, (string)($_GET['refresh'] ?? '') === '1');
        $ok($meta + ['refreshed' => $refreshed, 'summary' => AttendanceMonthRollupService::summary($conn, $department, $y, $m)]);
    }

    case 'list': {
        [$y, $m] = $monthArg();
        if (!$available) {
            $ok($meta + ['items' => [], 'total' => 0, 'summary' => null]);
        }
        $refreshed = AttendanceMonthRollupService::ensureFresh($conn, $department, $y, $m, (string)($_GET['refresh'] ?? '') === '1');
        $page = AttendanceMonthRollupService::listMonth($conn, $department, $y, $m, $filtersArg());
        $ok($meta + $page + [
            'refreshed' => $refreshed,
            'summary' => AttendanceMonthRollupService::summary($conn, $department, $y, $m),
        ]);
    }

    case 'groups': {
        [$y, $m] = $monthArg();
        if (!$available) {
            $ok($meta + ['groups' => []]);
        }
        $ok($meta + ['groups' => AttendanceMonthRollupService::groupsForMonth($conn, $department, $y, $m)]);
    }

    case 'member': {
        $memberId = (int)($_GET['member_id'] ?? 0);
        if ($memberId <= 0) {
            $fail(400, 'member_id required');
        }
        if (!$available) {
            $ok($meta + ['member_id' => $memberId, 'months' => []]);
        }
        $ok($meta + ['member_id' => $memberId, 'months' => AttendanceMonthRollupService::memberMonths($conn, $department, $memberId, 13)]);
    }

    case 'export': {
        [$y, $m] = $monthArg();
        if (!$available) {
            $fail(409, 'Monthly attendance is not available yet (feature flag / sql/074).');
        }
        AttendanceMonthRollupService::ensureFresh($conn, $department, $y, $m);
        $f = $filtersArg();
        $f['limit'] = AttendanceMonthRollupService::PAGE_MAX;
        $f['offset'] = 0;
        $summary = AttendanceMonthRollupService::summary($conn, $department, $y, $m);
        $rows = [];
        // Page through everything (PAGE_MAX per query; a month never exceeds the membership).
        do {
            $page = AttendanceMonthRollupService::listMonth($conn, $department, $y, $m, $f);
            foreach ($page['items'] as $it) {
                $rows[] = $it;
            }
            $f['offset'] += $page['limit'];
        } while (count($page['items']) === $page['limit'] && $f['offset'] < 20000);

        $format = (string)($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $groupLabel = $department === 'edu' ? 'Class' : 'Section';
        $headers = ['#', 'Member code', 'Name', 'Gender', $groupLabel, 'Dept. status', 'Sessions', 'Present', 'Late', 'Excused', 'Absent',
            'Present (weighted)', 'Rate %', 'Band', 'Below ' . rtrim(rtrim(number_format((float)$summary['threshold'], 2, '.', ''), '0'), '.') . '%', 'Open warnings'];
        $bandLabels = ['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'low' => 'Low', 'no_data' => 'No data'];
        $lines = [];
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            $lines[] = [
                $n, $r['member_code'], $r['name'], $r['gender'] === '' ? '—' : ucfirst((string)$r['gender']), $r['group'],
                ucfirst((string)$r['department_status']), $r['sessions'], $r['present'], $r['late'], $r['excused'], $r['absent'],
                $r['present_eq'], $r['rate'] === null ? '—' : $r['rate'], $bandLabels[$r['band']] ?? $r['band'],
                $r['below_threshold'] ? 'Yes' : 'No', $r['open_warnings'],
            ];
        }
        $title = 'Monthly attendance — ' . AttendanceRiskService::LABELS[$department] . ' — ' . $summary['month_label'] . ' (' . $summary['month_label_am'] . ') EC';
        $stamp = 'Generated ' . ethio_export_datetime(new DateTime()) . ' EC · threshold ' . $summary['threshold'] . '% · min sessions ' . $summary['min_sessions']
            . ' · rate = (present + excused + 0.5 × late) ÷ sessions';
        $base = 'monthly_attendance_' . $department . '_' . $y . '_' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
        \App\Services\SecurityAuditService::record($conn, 'attendance_monthly_export', [
            'department' => $department, 'ec_year' => $y, 'ec_month' => $m, 'rows' => $n, 'format' => $format,
        ], 'attendance_month_rollup', null);

        if ($format === 'xlsx' && \App\Reporting\ReportExportService::xlsxAvailable()) {
            $filename = \App\Reporting\ReportExportService::safeFilename($base, 'xlsx');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $writer = new \OpenSpout\Writer\XLSX\Writer();
            $writer->openToBrowser($filename);
            $writer->getCurrentSheet()->setName(mb_substr(ethio_month_name($m) . ' ' . $y, 0, 31));
            $bold = (new \OpenSpout\Common\Entity\Style\Style())->setFontBold();
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([$title], $bold));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([$stamp]));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([]));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers, $bold));
            foreach ($lines as $line) {
                $cells = [];
                foreach ($line as $v) {
                    $cells[] = is_int($v) || is_float($v) ? $v : \App\Reporting\ReportExportService::spreadsheetSafe((string)$v);
                }
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($cells));
            }
            $writer->close();
            exit;
        }
        $filename = \App\Reporting\ReportExportService::safeFilename($base, 'csv');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            $fail(500, 'Could not open output.');
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$title], ',', '"', '');
        fputcsv($out, [$stamp], ',', '"', '');
        fputcsv($out, [], ',', '"', '');
        fputcsv($out, $headers, ',', '"', '');
        foreach ($lines as $line) {
            fputcsv($out, array_map(static fn($v) => is_int($v) || is_float($v) ? (string)$v : \App\Reporting\ReportExportService::spreadsheetSafe((string)$v), $line), ',', '"', '');
        }
        fclose($out);
        exit;
    }
}
$fail(400, 'Unknown action');
