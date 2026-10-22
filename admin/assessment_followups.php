<?php
/**
 * ============================================================================
 * Assessment follow-ups — make-up queue (Attendance alerting — Phase 5)
 * ============================================================================
 * Opened when a member is reactivated in Education after an inactive period:
 * every assessment that fell inside that period with no score becomes a
 * `pending` follow-up. The responsible teacher (class + subject assignment)
 * or the Education department decides: make-up required / exempt / not
 * applicable, and records a make-up result SEPARATELY from the original
 * assessment row (never overwritten; no automatic zero).
 * Server-scoped by admin/api_assessment_followups.php: teachers see only
 * their own assignments; HR / Information / admins read-only.
 * Ethiopian calendar only: every date on this page comes from the API.
 * No data, no SQL, no CDN assets on this shell.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/AssessmentFollowupService.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}
$userRole = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($userRole, \App\Services\AssessmentFollowupService::READ_ROLES, true)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$roleLabels = [
    'super_admin' => 'Super Admin', 'school_admin' => 'School Admin', 'info_dept' => 'Information Department',
    'hr_dept' => 'HR Department', 'edu_dept' => 'Education Department', 'teacher' => 'Teacher',
];
$cfg = [
    'api' => '/admin/api_assessment_followups.php',
    'csrf' => generateCsrfToken(),
    'memberUrl' => '/admin/info_manage_member.php?id=',
    'role' => $userRole,
    'canWrite' => \App\Services\AssessmentFollowupService::roleMayWrite($userRole),
    'initial' => [
        'decision' => (string)($_GET['decision'] ?? ''),
        'member_id' => (int)($_GET['member_id'] ?? 0),
        'class_id' => (int)($_GET['class_id'] ?? 0),
        'id' => (int)($_GET['id'] ?? 0),
    ],
];
$assetVer = '20261022s';
$schoolName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'SSMS';
$backUrl = $userRole === 'teacher' ? '/admin/dashboards/teacher.php' : '/admin/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Assessment Follow-ups · <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/admin/css/reports_v2.css?v=<?= $assetVer ?>">
<style>
.af-wrap{padding:16px 20px 40px;max-width:1320px;margin:0 auto}
.af-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
.af-card{background:#fff;border:1px solid var(--rc-line);border-radius:var(--rc-radius);box-shadow:var(--rc-shadow);padding:14px;cursor:pointer}
.af-card.active{outline:2px solid var(--rc-primary)}
.af-card h3{margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted)}
.af-card .big{font-size:26px;font-weight:700}
.af-card .sub{font-size:12px;color:var(--rc-muted)}
.af-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px}
.af-filters label{display:flex;flex-direction:column;font-size:11px;font-weight:600;color:var(--rc-muted);text-transform:uppercase;letter-spacing:.05em;gap:4px}
.af-filters select,.af-filters input{padding:7px 9px;border:1px solid var(--rc-line);border-radius:8px;background:#fff;font:inherit;font-size:13px;min-width:140px}
.af-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--rc-line);border-radius:var(--rc-radius);overflow:hidden}
.af-table th,.af-table td{padding:8px 10px;border-bottom:1px solid var(--rc-line);text-align:left;vertical-align:middle;font-size:13px}
.af-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted);white-space:nowrap}
.af-table tr:hover td{background:#f9fbfd}
.pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.pill-pending{background:#fef3c7;color:#92400e}.pill-makeup_required{background:#dbeafe;color:#1e40af}
.pill-exempt{background:#dcfce7;color:#166534}.pill-not_applicable{background:#e5e7eb;color:#374151}
.pill-score{background:#ede9fe;color:#5b21b6}
.af-empty{padding:30px;text-align:center;color:var(--rc-muted);background:#fff;border:1px dashed var(--rc-line);border-radius:var(--rc-radius)}
.af-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;font-size:12px;color:var(--rc-muted);flex-wrap:wrap}
.af-pager{display:flex;gap:6px}
.af-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px;z-index:50}
.af-dialog{background:#fff;border-radius:14px;max-width:720px;width:100%;max-height:92vh;overflow:auto;padding:18px 20px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.af-dialog h2{margin:0 0 4px;font-size:17px}
.af-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 16px;margin:10px 0;font-size:13px}
.af-grid b{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted);font-weight:600}
.af-form{display:flex;flex-direction:column;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid var(--rc-line)}
.af-form label{font-size:12px;font-weight:600;color:var(--rc-muted);display:flex;flex-direction:column;gap:4px}
.af-form select,.af-form input,.af-form textarea{padding:7px 9px;border:1px solid var(--rc-line);border-radius:8px;font:inherit;font-size:13px}
.af-row{display:flex;gap:8px;flex-wrap:wrap}.af-row label{flex:1;min-width:120px}
.af-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.af-note{font-size:12px;color:var(--rc-muted)}
.af-attempts{margin:8px 0 0;padding:0;list-style:none;font-size:13px}
.af-attempts li{padding:6px 0;border-bottom:1px dashed var(--rc-line)}
.nc-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;z-index:60}
.nc-toast.nc-err{background:#b91c1c}.nc-toast.nc-ok{background:#166534}
@media (max-width:820px){.af-table th.opt,.af-table td.opt{display:none}}
</style>
</head>
<body>
<div class="rc" data-role="<?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>">
  <header class="rc-header">
    <div class="rc-header-left">
      <a class="rc-iconbtn" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" title="Back to dashboard" aria-label="Back">
        <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
      </a>
      <div>
        <h1 class="rc-title">Assessment Follow-ups <span class="rc-badge amh">የማካካሻ ፈተና ክትትል</span></h1>
        <div class="rc-subtitle"><?= htmlspecialchars($roleLabels[$userRole] ?? $userRole, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ethio_date_format($today, 'F j, Y'), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="rc-header-right">
      <button class="rc-btn rc-btn-ghost rc-btn-sm" id="af-reload" type="button">Reload</button>
    </div>
  </header>

  <main class="af-wrap">
    <div id="af-unavailable" class="af-empty" hidden>
      Assessment follow-ups are not available yet (feature flag off or <code>sql/075_assessment_followups.sql</code> not applied).
    </div>

    <section class="af-cards" id="af-cards" aria-label="Queue summary"></section>

    <div class="af-filters">
      <label>Decision
        <select id="af-decision">
          <option value="">Open (pending + make-up)</option>
          <option value="pending">Pending</option>
          <option value="makeup_required">Make-up required</option>
          <option value="exempt">Exempt</option>
          <option value="not_applicable">Not applicable</option>
          <option value="all">All</option>
        </select>
      </label>
      <label>Search
        <input type="search" id="af-q" placeholder="member, code or assessment">
      </label>
      <span class="rc-muted" id="af-total"></span>
    </div>

    <div id="af-list"></div>
    <div class="af-foot">
      <span id="af-meta"></span>
      <div class="af-pager">
        <button class="rc-btn rc-btn-ghost rc-btn-sm" id="af-prev" type="button">‹ Prev</button>
        <button class="rc-btn rc-btn-ghost rc-btn-sm" id="af-next" type="button">Next ›</button>
      </div>
    </div>
    <p class="af-note">A follow-up is opened automatically when a member returns to Education after an inactive period. Nothing is scored automatically: choose <em>make-up required</em>, <em>exempt</em> or <em>not applicable</em>. A make-up result is stored separately — the original assessment row is never changed.</p>
  </main>
</div>

<div id="af-modal-root"></div>
<script>window.AF_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/admin/js/score_limit.js?v=<?= $assetVer ?>"></script>
<script src="/admin/js/assessment_followups.js?v=<?= $assetVer ?>"></script>
</body>
</html>
