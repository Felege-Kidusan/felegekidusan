<?php
/**
 * ============================================================================
 * Monthly attendance — shared report page (Attendance alerting — Phase 4)
 * ============================================================================
 * One report for Education, Mezmur, HR and Information (department-scoped on
 * the server by admin/api_attendance_monthly.php; HR and Information see both
 * departments, School Admin / Super Admin read-only like everyone else).
 * Shows every member's attendance for one ETHIOPIAN month from the derived
 * table attendance_month_rollup (shared risk formula: present + excused +
 * 0.5 × late over eligible sessions; bands 90/75/50; policy threshold).
 * Ethiopian calendar only: every month label / date comes from the API.
 * No data, no SQL, no CDN assets on this shell.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/AttendanceWarningService.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}
$userRole = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($userRole, \App\Services\AttendanceWarningService::READ_ROLES, true)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
$departments = \App\Services\AttendanceWarningService::departmentsForRole($userRole);
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$roleLabels = [
    'super_admin' => 'Super Admin', 'school_admin' => 'School Admin', 'info_dept' => 'Information Department',
    'hr_dept' => 'HR Department', 'edu_dept' => 'Education Department', 'mezmur_dept' => 'Mezmur Department',
];
$reqDept = (string)($_GET['department'] ?? '');
$cfg = [
    'api' => '/admin/api_attendance_monthly.php',
    'warningsUrl' => '/admin/attendance_warnings.php',
    'memberUrl' => '/admin/info_manage_member.php?id=',
    'role' => $userRole,
    'departments' => $departments,
    'initial' => [
        'department' => in_array($reqDept, $departments, true) ? $reqDept : ($departments[0] ?? 'edu'),
        'ec_year' => (int)($_GET['ec_year'] ?? 0),
        'ec_month' => (int)($_GET['ec_month'] ?? 0),
        'below' => (string)($_GET['below'] ?? '') === '1',
        'member_id' => (int)($_GET['member_id'] ?? 0),
    ],
];
$assetVer = '20261019p4';
$schoolName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'SSMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monthly Attendance · <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/admin/css/reports_v2.css?v=<?= $assetVer ?>">
<style>
.am-wrap{padding:16px 20px 40px;max-width:1320px;margin:0 auto}
.am-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
.am-card{background:#fff;border:1px solid var(--rc-line);border-radius:var(--rc-radius);box-shadow:var(--rc-shadow);padding:14px;cursor:pointer}
.am-card.active{outline:2px solid var(--rc-primary)}
.am-card h3{margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted)}
.am-card .big{font-size:26px;font-weight:700}
.am-card .sub{font-size:12px;color:var(--rc-muted)}
.am-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px}
.am-filters label{display:flex;flex-direction:column;font-size:11px;font-weight:600;color:var(--rc-muted);text-transform:uppercase;letter-spacing:.05em;gap:4px}
.am-filters select,.am-filters input{padding:7px 9px;border:1px solid var(--rc-line);border-radius:8px;background:#fff;font:inherit;font-size:13px;min-width:140px}
.am-check{display:inline-flex!important;flex-direction:row!important;align-items:center;gap:6px;text-transform:none!important;font-size:13px!important;padding:8px 0}
.am-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--rc-line);border-radius:var(--rc-radius);overflow:hidden}
.am-table th,.am-table td{padding:8px 10px;border-bottom:1px solid var(--rc-line);text-align:left;vertical-align:middle;font-size:13px;white-space:nowrap}
.am-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted)}
.am-table td.num,.am-table th.num{text-align:right;font-variant-numeric:tabular-nums}
.am-table tr:hover td{background:#f9fbfd}
.am-table tr.below td{background:#fff7f7}
.pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.pill-excellent{background:#dcfce7;color:#166534}.pill-good{background:#dbeafe;color:#1e40af}.pill-fair{background:#fef3c7;color:#92400e}
.pill-low{background:#fee2e2;color:#991b1b}.pill-no_data{background:#e5e7eb;color:#374151}
.pill-inactive{background:#f1f5f9;color:#475569;border:1px dashed #94a3b8}.pill-warn{background:#ffedd5;color:#9a3412}
.am-bar{display:inline-block;width:70px;height:6px;background:#e5e7eb;border-radius:3px;vertical-align:middle;margin-left:6px;overflow:hidden}
.am-bar i{display:block;height:100%;background:var(--rc-primary)}
.am-empty{padding:30px;text-align:center;color:var(--rc-muted);background:#fff;border:1px dashed var(--rc-line);border-radius:var(--rc-radius)}
.am-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;font-size:12px;color:var(--rc-muted);flex-wrap:wrap}
.am-pager{display:flex;gap:6px}
.am-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px;z-index:50}
.am-dialog{background:#fff;border-radius:14px;max-width:760px;width:100%;max-height:92vh;overflow:auto;padding:18px 20px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.am-dialog h2{margin:0 0 4px;font-size:17px}
.am-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.am-note{font-size:12px;color:var(--rc-muted)}
.nc-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;z-index:60}
.nc-toast.nc-err{background:#b91c1c}.nc-toast.nc-ok{background:#166534}
@media (max-width:820px){.am-table th.opt,.am-table td.opt{display:none}}
</style>
</head>
<body>
<div class="rc" data-role="<?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>">
  <header class="rc-header">
    <div class="rc-header-left">
      <a class="rc-iconbtn" href="/admin/dashboard.php" title="Back to dashboard" aria-label="Back">
        <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
      </a>
      <div>
        <h1 class="rc-title">Monthly Attendance <span class="rc-badge amh">ወርሃዊ የተገኝነት ሪፖርት</span></h1>
        <div class="rc-subtitle"><?= htmlspecialchars($roleLabels[$userRole] ?? $userRole, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ethio_date_format($today, 'F j, Y'), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="rc-header-right">
      <a class="rc-btn rc-btn-ghost rc-btn-sm" id="am-calendar-link" href="/admin/school_calendar.php">School Calendar</a>
      <a class="rc-btn rc-btn-ghost rc-btn-sm" id="am-warnings-link" href="/admin/attendance_warnings.php">Warnings</a>
      <button class="rc-btn rc-btn-ghost rc-btn-sm" id="am-refresh" type="button" title="Recompute this month now">Refresh</button>
      <button class="rc-btn rc-btn-sm" id="am-export" type="button">Export Excel</button>
    </div>
  </header>

  <main class="am-wrap">
    <div id="am-unavailable" class="am-empty" hidden>
      Monthly attendance is not available yet (feature flag off or <code>sql/074_attendance_month_rollup.sql</code> not applied).
    </div>

    <div class="am-filters">
      <label>Department
        <select id="am-department">
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d ?>"><?= $d === 'edu' ? 'Education' : 'Mezmur' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Month (EC)
        <select id="am-month"></select>
      </label>
      <label id="am-group-label"><span id="am-group-caption">Class</span>
        <select id="am-group"><option value="">All</option></select>
      </label>
      <label>Band
        <select id="am-band">
          <option value="">Any</option>
          <option value="excellent">Excellent (≥ 90%)</option>
          <option value="good">Good (75–89%)</option>
          <option value="fair">Fair (50–74%)</option>
          <option value="low">Low (&lt; 50%)</option>
          <option value="no_data">No data</option>
        </select>
      </label>
      <label>Search
        <input type="search" id="am-q" placeholder="name or code">
      </label>
      <label class="am-check"><input type="checkbox" id="am-below"> <span>Below threshold only</span></label>
      <span class="rc-muted" id="am-total"></span>
    </div>

    <section class="am-cards" id="am-cards" aria-label="Month summary"></section>

    <div id="am-list"></div>
    <div class="am-foot">
      <span id="am-meta"></span>
      <div class="am-pager">
        <button class="rc-btn rc-btn-ghost rc-btn-sm" id="am-prev" type="button">‹ Prev</button>
        <button class="rc-btn rc-btn-ghost rc-btn-sm" id="am-next" type="button">Next ›</button>
      </div>
    </div>
    <p class="am-note">Rate = (present + excused + 0.5 × late) ÷ eligible sessions. Only submitted/approved sheets count; holidays and months inside an inactive period are excluded. “Below threshold” uses the department policy and needs the minimum number of sessions.</p>
  </main>
</div>

<div id="am-modal-root"></div>
<script>window.AM_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/admin/js/attendance_monthly.js?v=<?= $assetVer ?>"></script>
</body>
</html>
