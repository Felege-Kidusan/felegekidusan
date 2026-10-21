<?php
/**
 * ============================================================================
 * Reporting Centre (V2) — page shell.
 * ============================================================================
 * Everything on this page is driven by api_reports_v2.php (registry‑governed,
 * read‑only). The page itself only renders the frame and hands the browser a
 * small JSON config; no data, no SQL, no CDN assets.
 *
 * Roles: super_admin, school_admin, info_dept, hr_dept (ROLE_MAP + in‑file).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/calendar_system.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}
$userRole = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($userRole, ['super_admin', 'school_admin', 'info_dept', 'hr_dept'], true)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
$userName = (string)($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User');
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');
$roleLabels = [
    'super_admin' => 'Super Admin', 'school_admin' => 'School Admin',
    'info_dept' => 'Information Department', 'hr_dept' => 'HR Department',
];
$cfg = [
    'api'        => '/admin/api_reports_v2.php',
    'role'       => $userRole,
    'roleLabel'  => $roleLabels[$userRole] ?? $userRole,
    'userName'   => $userName,
    'memberUrl'  => '/admin/info_manage_member.php?id=',
    'legacyUrl'  => '/admin/reports.php',
    'viewsApi'   => '/admin/api_report_views.php',
    'csrf'       => generateCsrfToken(),
    'lang'       => 'en',
    'today'      => $todayFormatted,
    'schoolName' => defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'SSMS',
];
$assetVer = '20261022r';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reporting Centre · <?= htmlspecialchars($cfg['schoolName'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/admin/js/vendor/tabulator/tabulator.min.css?v=<?= $assetVer ?>">
<link rel="stylesheet" href="/admin/css/reports_v2.css?v=<?= $assetVer ?>">
</head>
<body>
<div id="rc-app" class="rc" data-role="<?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>">

  <!-- ───────── Header ───────── -->
  <header class="rc-header">
    <div class="rc-header-left">
      <a class="rc-iconbtn" href="/admin/dashboard.php" title="Back to dashboard" aria-label="Back">
        <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
      </a>
      <div>
        <h1 class="rc-title">Reporting Centre <span class="rc-badge">beta</span></h1>
        <div class="rc-subtitle"><?= htmlspecialchars($cfg['roleLabel'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($todayFormatted, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="rc-header-right">
      <div class="rc-seg" role="group" aria-label="Language">
        <button type="button" data-lang="en" class="is-on">EN</button>
        <button type="button" data-lang="am" class="amh">አማ</button>
      </div>
      <button type="button" class="rc-btn rc-btn-ghost" id="rc-glossary-btn" title="Definitions">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg><span>Definitions</span>
      </button>
      <?php if (in_array($userRole, ['super_admin', 'school_admin'], true)): ?>
      <button type="button" class="rc-btn rc-btn-ghost" id="rc-exportlog-btn" title="Who exported what">
        <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4zM8 9h8M8 13h8M8 17h5"/></svg><span>Export log</span>
      </button>
      <?php endif; ?>
      <a class="rc-btn rc-btn-ghost" href="<?= htmlspecialchars($cfg['legacyUrl'], ENT_QUOTES, 'UTF-8') ?>" title="Open the previous reports page">Classic view</a>
    </div>
  </header>

  <!-- ───────── Starters ───────── -->
  <section class="rc-starters" id="rc-starters" aria-label="Starter questions">
    <div class="rc-starters-label">Start from a question</div>
    <div class="rc-starters-row" id="rc-starters-row"><span class="rc-skel" style="width:180px"></span><span class="rc-skel" style="width:220px"></span><span class="rc-skel" style="width:160px"></span></div>
  </section>

  <div class="rc-body">
    <!-- ───────── Filter rail ───────── -->
    <aside class="rc-rail" id="rc-rail" aria-label="Filters">
      <div class="rc-rail-head">
        <strong>Population</strong>
        <div class="rc-rail-actions">
          <button type="button" class="rc-link" id="rc-clear">Clear all</button>
          <button type="button" class="rc-iconbtn rc-rail-close" id="rc-rail-close" aria-label="Close filters"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
      </div>

      <label class="rc-field">
        <span class="rc-field-label">Search</span>
        <input type="search" id="rc-search" placeholder="Member ID, name, phone…" autocomplete="off">
      </label>

      <div class="rc-field">
        <span class="rc-field-label">Registration period</span>
        <select id="rc-period">
          <option value="">Any time</option>
          <option value="this_ec_year">This Ethiopian year</option>
          <option value="last_ec_year">Last Ethiopian year</option>
          <option value="last_30">Last 30 days</option>
          <option value="last_90">Last 90 days</option>
          <option value="last_365">Last 12 months</option>
        </select>
      </div>

      <div class="rc-field" id="rc-quick-age_group"></div>
      <div class="rc-field" id="rc-quick-status"></div>
      <div class="rc-field" id="rc-quick-membership_tier"></div>
      <div class="rc-field" id="rc-quick-gender"></div>
      <div class="rc-field" id="rc-quick-edu_attendance_band"></div>
      <div class="rc-field" id="rc-quick-progression"></div>

      <div class="rc-rules">
        <div class="rc-rules-head"><span class="rc-field-label">More rules</span><button type="button" class="rc-link" id="rc-add-rule">+ Add rule</button></div>
        <div id="rc-rules"></div>
      </div>

      <div class="rc-rail-foot">
        <button type="button" class="rc-btn rc-btn-ghost rc-btn-sm" id="rc-save-view">Save view</button>
        <button type="button" class="rc-btn rc-btn-ghost rc-btn-sm" id="rc-copy-link">Copy link</button>
      </div>
      <div class="rc-views" id="rc-views" aria-live="polite"></div>
    </aside>

    <!-- ───────── Workspace ───────── -->
    <main class="rc-main">
      <div class="rc-toolbar">
        <button type="button" class="rc-btn rc-btn-ghost rc-rail-toggle" id="rc-rail-toggle">
          <svg viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg><span>Filters</span>
        </button>
        <div class="rc-chips" id="rc-chips"></div>
        <div class="rc-toolbar-right">
          <div class="rc-tabs" role="tablist">
            <button type="button" role="tab" data-tab="overview" class="is-on">Overview</button>
            <button type="button" role="tab" data-tab="breakdown">Breakdown</button>
            <button type="button" role="tab" data-tab="list">List</button>
          </div>
          <div class="rc-menu">
            <button type="button" class="rc-btn rc-btn-primary" id="rc-export-btn">
              <svg viewBox="0 0 24 24"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v3h16v-3"/></svg><span>Export</span>
            </button>
            <div class="rc-menu-pop" id="rc-export-menu" hidden>
              <button type="button" data-format="csv">CSV (.csv)</button>
              <button type="button" data-format="xlsx" id="rc-export-xlsx">Excel (.xlsx)</button>
              <div class="rc-menu-note" id="rc-export-note"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- KPI strip -->
      <section class="rc-kpis" id="rc-kpis" aria-live="polite"></section>

      <!-- Overview -->
      <section class="rc-tabpane is-on" id="pane-overview">
        <div class="rc-grid" id="rc-charts"></div>
      </section>

      <!-- Breakdown -->
      <section class="rc-tabpane" id="pane-breakdown">
        <div class="rc-card">
          <div class="rc-card-hd">
            <div class="rc-inline">
              <label>Group by <select id="rc-groupby"></select></label>
              <label>Measures <select id="rc-metrics" multiple size="1"></select></label>
            </div>
            <span class="rc-muted" id="rc-breakdown-note"></span>
          </div>
          <div class="rc-breakdown-wrap">
            <div id="rc-breakdown-chart" class="rc-chart rc-chart-tall"></div>
            <div id="rc-breakdown-table"></div>
          </div>
        </div>
      </section>

      <!-- List -->
      <section class="rc-tabpane" id="pane-list">
        <div class="rc-card">
          <div class="rc-card-hd">
            <div class="rc-inline">
              <span id="rc-list-count" class="rc-muted">—</span>
              <label>Rows <select id="rc-pagesize"><option>25</option><option selected>50</option><option>100</option><option>200</option></select></label>
            </div>
            <div class="rc-menu">
              <button type="button" class="rc-btn rc-btn-ghost rc-btn-sm" id="rc-columns-btn">
                <svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM9 5v14M15 5v14"/></svg><span>Columns</span>
              </button>
              <div class="rc-menu-pop rc-columns-pop" id="rc-columns-pop" hidden></div>
            </div>
          </div>
          <div id="rc-table"></div>
        </div>
      </section>

      <footer class="rc-foot">
        <span id="rc-asof">—</span>
        <span>Source: <strong>members</strong> directory · Education enrolment (current year) · Family Link · staff positions — read‑only</span>
        <span>Lists show up to 2 000 rows; files up to 5 000 rows. Every export is recorded.</span>
      </footer>
    </main>
  </div>

  <!-- Glossary -->
  <div class="rc-modal" id="rc-glossary" hidden>
    <div class="rc-modal-card" role="dialog" aria-modal="true" aria-labelledby="rc-glossary-title">
      <div class="rc-modal-hd"><h2 id="rc-glossary-title">Definitions</h2><button type="button" class="rc-iconbtn" data-close><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
      <div class="rc-modal-bd" id="rc-glossary-body"></div>
    </div>
  </div>

  <!-- Save view dialog -->
  <div class="rc-modal" id="rc-saveview" hidden>
    <form class="rc-modal-card rc-modal-sm" role="dialog" aria-modal="true" aria-labelledby="rc-saveview-title" id="rc-saveview-form" autocomplete="off">
      <div class="rc-modal-hd"><h2 id="rc-saveview-title">Save view</h2><button type="button" class="rc-iconbtn" data-close><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
      <div class="rc-modal-bd">
        <input type="hidden" name="id" value="">
        <label class="rc-form-row"><span>Name</span><input type="text" name="name" maxlength="120" required placeholder="e.g. Section A children without guardian phone"></label>
        <label class="rc-form-row"><span>Description <small>(optional)</small></span><input type="text" name="description" maxlength="500" placeholder="What this list is for, who uses it"></label>
        <fieldset class="rc-form-row rc-scope">
          <legend>Who can open it</legend>
          <label><input type="radio" name="scope" value="private" checked> <b>Only me</b><small>Private to your account</small></label>
          <label><input type="radio" name="scope" value="department"> <b>My department</b><small>Everyone with your role</small></label>
          <label><input type="radio" name="scope" value="shared"> <b>Everyone</b><small>All reporting‑centre users (Info, HR, admins)</small></label>
        </fieldset>
        <?php if (in_array($userRole, ['super_admin', 'school_admin'], true)): ?>
        <label class="rc-form-row rc-pin-row"><input type="checkbox" name="pinned" value="1"> <span><b>Pin as standing report</b><small>Shown to everyone in the “Start from a question” row (forces “Everyone”)</small></span></label>
        <?php endif; ?>
        <p class="rc-muted" id="rc-saveview-summary"></p>
      </div>
      <div class="rc-modal-ft">
        <button type="button" class="rc-btn rc-btn-ghost" data-close>Cancel</button>
        <button type="submit" class="rc-btn rc-btn-primary" id="rc-saveview-submit">Save</button>
      </div>
    </form>
  </div>

  <!-- Export log (school_admin / super_admin) -->
  <div class="rc-modal" id="rc-exportlog" hidden>
    <div class="rc-modal-card rc-modal-wide" role="dialog" aria-modal="true" aria-labelledby="rc-exportlog-title">
      <div class="rc-modal-hd"><h2 id="rc-exportlog-title">Export log — who exported what</h2><button type="button" class="rc-iconbtn" data-close><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
      <div class="rc-modal-bd">
        <p class="rc-muted" id="rc-exportlog-note">Last 200 member exports (files and PDF/legacy exports). Filters are recorded as field:operator — values are never stored.</p>
        <div id="rc-exportlog-table"></div>
      </div>
    </div>
  </div>

  <div class="rc-toast" id="rc-toast" hidden></div>
</div>

<script id="rc-config" type="application/json"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php if (function_exists('wbws_calendar_scripts')) { echo wbws_calendar_scripts(); } // calendar mode is fixed to Ethiopian; no DB access from this shell ?>
<script src="/admin/js/vendor/tabulator/tabulator.min.js?v=<?= $assetVer ?>"></script>
<script src="/admin/js/vendor/echarts/echarts.min.js?v=<?= $assetVer ?>"></script>
<script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
<script src="/admin/js/reports/reports_v2.js?v=<?= $assetVer ?>"></script>
</body>
</html>
