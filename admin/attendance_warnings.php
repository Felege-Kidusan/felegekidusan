<?php
/**
 * ============================================================================
 * Attendance Warnings — shared page (Attendance alerting — Phase 2)
 * ============================================================================
 * One list for Education, Mezmur, HR and Information (department-scoped on the
 * server by admin/api_attendance_warnings.php). School Admin and Super Admin
 * see a read-only list (no close button; the API answers 403 as well).
 * Ethiopian calendar only: every date shown comes pre-formatted from the API.
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
$canClose = [];
foreach (['edu', 'mezmur'] as $d) {
    $canClose[$d] = \App\Services\AttendanceWarningService::roleMayClose($userRole, $d);
}
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$roleLabels = [
    'super_admin' => 'Super Admin', 'school_admin' => 'School Admin', 'info_dept' => 'Information Department',
    'hr_dept' => 'HR Department', 'edu_dept' => 'Education Department', 'mezmur_dept' => 'Mezmur Department',
];
$cfg = [
    'api' => '/admin/api_attendance_warnings.php',
    'role' => $userRole,
    'departments' => $departments,
    'canClose' => $canClose,
    'memberUrl' => '/admin/info_manage_member.php?id=',
    'csrf' => generateCsrfToken(),
    'initial' => [
        'department' => in_array((string)($_GET['department'] ?? ''), $departments, true) ? (string)$_GET['department'] : 'all',
        'status' => in_array((string)($_GET['status'] ?? ''), ['open', 'closed', 'all'], true) ? (string)$_GET['status'] : 'open',
        'id' => (int)($_GET['id'] ?? 0),
        'member_id' => (int)($_GET['member_id'] ?? 0),
    ],
];
$assetVer = '20261022p';
$schoolName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'SSMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Attendance Warnings · <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/admin/css/reports_v2.css?v=<?= $assetVer ?>">
<style>
.aw-wrap{padding:16px 20px 40px;max-width:1280px;margin:0 auto}
.aw-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px}
.aw-card{background:#fff;border:1px solid var(--rc-line);border-radius:var(--rc-radius);box-shadow:var(--rc-shadow);padding:14px}
.aw-card h3{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted)}
.aw-card .big{font-size:28px;font-weight:700}
.aw-card .sub{font-size:12px;color:var(--rc-muted)}
.aw-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px}
.aw-filters label{display:flex;flex-direction:column;font-size:11px;font-weight:600;color:var(--rc-muted);text-transform:uppercase;letter-spacing:.05em;gap:4px}
.aw-filters select,.aw-filters input{padding:7px 9px;border:1px solid var(--rc-line);border-radius:8px;background:#fff;font:inherit;font-size:13px;min-width:140px}
.aw-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--rc-line);border-radius:var(--rc-radius);overflow:hidden}
.aw-table th,.aw-table td{padding:9px 10px;border-bottom:1px solid var(--rc-line);text-align:left;vertical-align:top;font-size:13px}
.aw-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted)}
.aw-table tr:hover td{background:#f9fbfd}
.pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.pill-edu{background:#dbeafe;color:#1e40af}.pill-mezmur{background:#ede9fe;color:#5b21b6}
.pill-standard{background:#fef3c7;color:#92400e}.pill-prolonged{background:#ffedd5;color:#9a3412}.pill-critical{background:#fee2e2;color:#991b1b}
.pill-low_month{background:#e0f2fe;color:#075985}.pill-open{background:#dcfce7;color:#166534}.pill-closed{background:#e5e7eb;color:#374151}
.pill-rollout{background:#f1f5f9;color:#475569;border:1px dashed #94a3b8}.pill-flag{background:#f0fdf4;color:#15803d;border:1px solid #86efac}
.aw-empty{padding:30px;text-align:center;color:var(--rc-muted);background:#fff;border:1px dashed var(--rc-line);border-radius:var(--rc-radius)}
.aw-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px;z-index:50}
.aw-dialog{background:#fff;border-radius:14px;max-width:720px;width:100%;max-height:92vh;overflow:auto;padding:18px 20px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.aw-dialog h2{margin:0 0 4px;font-size:17px}
.aw-kv{display:grid;grid-template-columns:150px 1fr;gap:4px 10px;font-size:13px;margin:10px 0}
.aw-kv dt{color:var(--rc-muted)}.aw-kv dd{margin:0}
.aw-events{list-style:none;padding:0;margin:8px 0;font-size:12px}
.aw-events li{padding:5px 0;border-bottom:1px dashed var(--rc-line);display:flex;gap:8px}
.aw-events .ev{font-weight:600;min-width:110px;text-transform:capitalize}
.aw-radio{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}
.aw-radio label{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--rc-line);border-radius:999px;padding:6px 12px;cursor:pointer;font-size:13px}
.aw-radio input:checked+span{font-weight:700;color:var(--rc-primary)}
.aw-dialog textarea{width:100%;min-height:70px;padding:8px;border:1px solid var(--rc-line);border-radius:8px;font:inherit}
.aw-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.aw-note{font-size:12px;color:var(--rc-muted)}
.aw-risk{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:8px 0}
.aw-risk div{background:#f8fafc;border:1px solid var(--rc-line);border-radius:10px;padding:8px;font-size:12px}
.aw-risk b{display:block;font-size:16px}
.nc-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;z-index:60}
.nc-toast.nc-err{background:#b91c1c}.nc-toast.nc-ok{background:#166534}
@media (max-width:720px){.aw-kv{grid-template-columns:1fr}.aw-table th:nth-child(5),.aw-table td:nth-child(5){display:none}}
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
        <h1 class="rc-title">Attendance Warnings <span class="rc-badge amh">የተገኝነት ማስጠንቀቂያዎች</span></h1>
        <div class="rc-subtitle"><?= htmlspecialchars($roleLabels[$userRole] ?? $userRole, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ethio_date_format($today, 'F j, Y'), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="rc-header-right">
      <button class="rc-btn rc-btn-ghost rc-btn-sm" id="aw-refresh" type="button">Refresh</button>
    </div>
  </header>

  <main class="aw-wrap">
    <div id="aw-unavailable" class="aw-empty" hidden>
      Attendance warnings are not available yet (feature flag off or <code>sql/072_attendance_warnings.sql</code> not applied).
    </div>

    <section class="aw-cards" id="aw-cards" aria-label="Open attendance warnings"></section>

    <div class="aw-filters">
      <label>Department
        <select id="aw-department">
          <option value="all">All I can see</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d ?>"><?= $d === 'edu' ? 'Education' : 'Mezmur' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Status
        <select id="aw-status">
          <option value="open">Open</option>
          <option value="closed">Closed</option>
          <option value="all">All</option>
        </select>
      </label>
      <label>Kind
        <select id="aw-kind">
          <option value="">Any</option>
          <option value="consecutive">Consecutive absences</option>
          <option value="low_month">Low month</option>
        </select>
      </label>
      <label>Stage
        <select id="aw-stage">
          <option value="">Any</option>
          <option value="standard">Standard</option>
          <option value="prolonged">Prolonged</option>
          <option value="critical">Critical</option>
        </select>
      </label>
      <label>Search
        <input type="search" id="aw-q" placeholder="name or code">
      </label>
      <span class="rc-muted" id="aw-total"></span>
    </div>

    <div id="aw-list"></div>
    <div id="aw-pager"></div>
  </main>
</div>

<div id="aw-modal-root"></div>
<script>window.AW_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/admin/js/list_pager.js?v=20261022p"></script>
<script src="/admin/js/attendance_warnings.js?v=<?= $assetVer ?>"></script>
</body>
</html>
