<?php
/**
 * FKSS Content Management Dashboard
 * Manages the public website: Gallery, Registrations, Social Links,
 * Teachers, Weekly Schedule, Programs.
 *
 * Access roles: super_admin, school_admin, info_dept, content_editor
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/calendar_system.php';

if (!isLoggedIn()) { header('Location: ../index.php'); exit; }

$role = $_SESSION['admin_role'] ?? '';
$allowed = ['super_admin', 'school_admin', 'info_dept', 'content_editor'];
if (!in_array($role, $allowed)) {
    header('Location: ../dashboard.php');
    exit;
}

$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Editor';
$username = $_SESSION['admin_username'] ?? '';
$csrfToken = generateCsrfToken();
$initials = strtoupper(substr($fullName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<title>Content Manager — <?= SCHOOL_NAME_SHORT ?></title>
<link rel="icon" href="<?= SCHOOL_LOGO_PATH ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --maroon:<?= THEME_PRIMARY ?>;
  --maroon-light:<?= THEME_PRIMARY_LIGHT ?>;
  --maroon-dark:<?= THEME_PRIMARY_DARK ?>;
  --gold:<?= THEME_ACCENT ?>;
  --gold-dark:<?= THEME_ACCENT_2 ?>;
  --bg:#faf7f2;
  --surface:#ffffff;
  --border:#ece4d6;
  --text:#2d2018;
  --text-dim:#8a7d6d;
}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',system-ui,sans-serif}
.amharic{font-family:'Noto Serif Ethiopic',serif}
body{background:var(--bg);color:var(--text);min-height:100vh}

/* Topbar */
.topbar{background:linear-gradient(135deg,var(--maroon-dark),var(--maroon));color:#fff;padding:0.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;box-shadow:0 2px 12px rgba(96,0,0,0.2)}
.topbar-brand{display:flex;align-items:center;gap:0.7rem}
.topbar-logo{width:40px;height:40px;border-radius:50%;background:#fff;border:2px solid var(--gold);padding:3px;display:flex;align-items:center;justify-content:center}
.topbar-logo img{width:100%;height:100%;object-fit:contain}
.topbar-title{font-size:0.95rem;font-weight:600}
.topbar-sub{font-size:0.7rem;opacity:0.75}
.topbar-right{display:flex;align-items:center;gap:0.85rem}
.user-chip{display:flex;align-items:center;gap:0.5rem;font-size:0.82rem}
.user-avatar{width:32px;height:32px;border-radius:50%;background:var(--gold);color:var(--maroon-dark);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem}
.btn-exit{color:#fff;text-decoration:none;font-size:0.8rem;padding:0.4rem 0.8rem;border:1px solid rgba(255,255,255,0.3);border-radius:0.5rem;transition:background 0.15s}
.btn-exit:hover{background:rgba(255,255,255,0.12)}

/* Layout */
.wrap{max-width:1100px;margin:0 auto;padding:1.25rem}

/* Tabs */
.tabs{display:flex;gap:0.4rem;flex-wrap:wrap;margin-bottom:1.25rem;border-bottom:2px solid var(--border);padding-bottom:0}
.tab{padding:0.6rem 1rem;font-size:0.85rem;font-weight:500;color:var(--text-dim);background:none;border:none;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:0.4rem;transition:color 0.15s}
.tab:hover{color:var(--maroon)}
.tab.active{color:var(--maroon);border-bottom-color:var(--gold);font-weight:600}
.tab .badge{background:var(--gold);color:var(--maroon-dark);font-size:0.65rem;font-weight:700;padding:1px 6px;border-radius:999px;min-width:16px;text-align:center}

/* Panels */
.panel{display:none}
.panel.active{display:block;animation:fade 0.2s ease}
@keyframes fade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

.panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:0.75rem}
.panel-head h2{font-size:1.15rem;color:var(--maroon-dark);font-weight:600}
.panel-head p{font-size:0.8rem;color:var(--text-dim);margin-top:2px}

/* Buttons */
.btn{padding:0.55rem 1rem;border-radius:0.5rem;border:none;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem;transition:all 0.12s;text-decoration:none}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold-dark));color:var(--maroon-dark);box-shadow:0 4px 12px rgba(240,192,0,0.3)}
.btn-gold:hover{transform:translateY(-1px)}
.btn-maroon{background:var(--maroon);color:#fff}
.btn-maroon:hover{background:var(--maroon-light)}
.btn-ghost{background:#fff;color:var(--maroon);border:1px solid var(--border)}
.btn-ghost:hover{background:var(--bg)}
.btn-danger{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.btn-danger:hover{background:#fee2e2}
.btn-sm{padding:0.35rem 0.7rem;font-size:0.75rem}

/* Cards & grids */
.grid{display:grid;gap:1rem}
.grid-cards{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}
.card{background:var(--surface);border:1px solid var(--border);border-radius:0.85rem;overflow:hidden;transition:box-shadow 0.15s}
.card:hover{box-shadow:0 6px 20px rgba(96,0,0,0.08)}
.card-body{padding:0.9rem}
.card-title{font-weight:600;font-size:0.9rem;color:var(--maroon-dark)}
.card-meta{font-size:0.75rem;color:var(--text-dim);margin-top:2px}
.card-actions{display:flex;gap:0.4rem;margin-top:0.7rem}

/* Photo card */
.photo-card{position:relative}
.photo-card img{width:100%;height:150px;object-fit:cover;display:block;background:#f3ebe0}
.photo-card .feat-star{position:absolute;top:6px;right:6px;background:var(--gold);color:var(--maroon-dark);width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.7rem}
.photo-card .miss{position:absolute;inset:0 0 auto 0;height:150px;display:flex;align-items:center;justify-content:center;background:#f3ebe0;color:var(--text-dim);font-size:.75rem;flex-direction:column;gap:.25rem}

/* Table */
.tbl{width:100%;border-collapse:collapse;background:#fff;border-radius:0.6rem;overflow:hidden;border:1px solid var(--border)}
.tbl th{background:var(--maroon);color:#fff;text-align:left;padding:0.6rem 0.8rem;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.4px;font-weight:600}
.tbl td{padding:0.65rem 0.8rem;border-top:1px solid var(--border);font-size:0.82rem;vertical-align:middle}
.tbl tr:hover td{background:#fdfaf4}

/* Status pill */
.pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:0.7rem;font-weight:600}
.pill-new{background:#dbeafe;color:#1e40af}
.pill-contacted{background:#fef3c7;color:#92400e}
.pill-enrolled{background:#dcfce7;color:#166534}
.pill-rejected{background:#fee2e2;color:#991b1b}

/* Sub-tabs for registration filter */
.subtabs{display:flex;gap:0.35rem;margin-bottom:0.9rem;flex-wrap:wrap}
.subtab{padding:0.4rem 0.8rem;font-size:0.76rem;border-radius:0.5rem;background:#fff;border:1px solid var(--border);cursor:pointer;color:var(--text-dim);display:flex;align-items:center;gap:0.35rem}
.subtab.active{background:var(--maroon);color:#fff;border-color:var(--maroon)}

/* Empty state */
.empty{text-align:center;padding:3rem 1rem;color:var(--text-dim)}
.empty i{font-size:2.5rem;color:var(--border);margin-bottom:0.75rem;display:block}

/* Modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(45,32,24,0.5);z-index:100;align-items:flex-start;justify-content:center;padding:2rem 1rem;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:1rem;max-width:520px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);border-top:5px solid var(--gold);margin:auto}
.modal-head{padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-head h3{font-size:1.05rem;color:var(--maroon-dark)}
.modal-close{background:none;border:none;font-size:1.3rem;color:var(--text-dim);cursor:pointer;line-height:1}
.modal-body{padding:1.25rem;max-height:65vh;overflow-y:auto}
.modal-foot{padding:1rem 1.25rem;border-top:1px solid var(--border);display:flex;gap:0.6rem;justify-content:flex-end}

/* Form */
.field{margin-bottom:0.9rem}
.field label{display:block;font-size:0.78rem;font-weight:600;color:var(--maroon-dark);margin-bottom:0.3rem}
.field input,.field textarea,.field select{width:100%;padding:0.6rem 0.75rem;border:1.5px solid var(--border);border-radius:0.5rem;font-size:0.85rem;outline:none;transition:border-color 0.15s;background:#fdfaf4}
.field input:focus,.field textarea:focus,.field select:focus{border-color:var(--gold);background:#fff}
.field textarea{resize:vertical;min-height:70px}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem}
.field .hint{font-size:0.7rem;color:var(--text-dim);margin-top:0.2rem}
.check-row{display:flex;align-items:center;gap:0.5rem}
.check-row input{width:auto}

/* Toast */
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--maroon-dark);color:#fff;padding:0.8rem 1.4rem;border-radius:0.7rem;font-size:0.85rem;z-index:200;opacity:0;transition:all 0.3s;box-shadow:0 8px 24px rgba(0,0,0,0.25);display:flex;align-items:center;gap:0.5rem}
.toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.toast.error{background:#b91c1c}
.toast i{color:var(--gold)}
.toast.error i{color:#fff}

.loading{text-align:center;padding:2rem;color:var(--text-dim);font-size:0.85rem}

@media(max-width:600px){
  .field-row{grid-template-columns:1fr}
  .topbar-sub{display:none}
}
</style>
<?= function_exists('wbws_calendar_scripts') ? wbws_calendar_scripts($conn ?? null) : '' ?>
</head>
<body>
<?php if (function_exists("ay_context_bar_html")) echo ay_context_bar_html($conn ?? null); ?>

<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-logo"><img src="<?= SCHOOL_LOGO_PATH ?>" alt="logo" onerror="this.parentElement.innerHTML='<span style=&quot;color:var(--maroon)&quot;><?= ADMIN_LOGO_ICON ?></span>'"></div>
    <div>
      <div class="topbar-title">Content Manager</div>
      <div class="topbar-sub"><?= SCHOOL_NAME_SHORT ?> · Public Website</div>
    </div>
  </div>
  <div class="topbar-right">
    <div class="user-chip">
      <div class="user-avatar"><?= e($initials) ?></div>
      <span><?= e($fullName) ?></span>
    </div>
    <a href="<?= SITE_URL ?>" target="_blank" class="btn-exit"><i class="fa-solid fa-globe"></i> View Site</a>
    <a href="/admin/dashboard.php" class="btn-exit"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
  </div>
</div>

<div class="wrap">

  <div class="tabs">
    <button class="tab active" data-panel="registrations"><i class="fa-solid fa-inbox"></i> Registrations <span class="badge" id="regBadge" style="display:none">0</span></button>
    <button class="tab" data-panel="gallery"><i class="fa-solid fa-images"></i> Gallery</button>
    <button class="tab" data-panel="teachers"><i class="fa-solid fa-chalkboard-user"></i> Teachers</button>
    <button class="tab" data-panel="schedule"><i class="fa-solid fa-calendar-week"></i> Schedule</button>
    <button class="tab" data-panel="programs"><i class="fa-solid fa-graduation-cap"></i> Programs</button>
    <button class="tab" data-panel="social"><i class="fa-solid fa-share-nodes"></i> Social Links</button>
  </div>

  <!-- ═══════════ REGISTRATIONS ═══════════ -->
  <div class="panel active" id="panel-registrations">
    <div class="panel-head">
      <div><h2>Registration Submissions</h2><p>Requests from the public website registration form</p></div>
    </div>
    <div class="subtabs" id="regFilters">
      <div class="subtab active" data-filter="all">All <span class="badge" data-count="all">0</span></div>
      <div class="subtab" data-filter="new">New <span class="badge" data-count="new">0</span></div>
      <div class="subtab" data-filter="contacted">Contacted <span class="badge" data-count="contacted">0</span></div>
      <div class="subtab" data-filter="enrolled">Enrolled <span class="badge" data-count="enrolled">0</span></div>
      <div class="subtab" data-filter="rejected">Rejected <span class="badge" data-count="rejected">0</span></div>
    </div>
    <div id="regList"><div class="loading">Loading…</div></div>
  </div>

  <!-- ═══════════ GALLERY ═══════════ -->
  <div class="panel" id="panel-gallery">
    <div class="panel-head">
      <div><h2>Photo Gallery</h2><p>Manage albums and photos shown on the website</p></div>
      <div style="display:flex;gap:0.5rem">
        <button class="btn btn-ghost" onclick="openCatModal()"><i class="fa-solid fa-folder-plus"></i> New Album</button>
        <button class="btn btn-gold" onclick="openPhotoModal()"><i class="fa-solid fa-upload"></i> Upload Photo</button>
      </div>
    </div>
    <div class="subtabs" id="galCatFilters"></div>
    <div class="grid grid-cards" id="photoGrid"><div class="loading">Loading…</div></div>
  </div>

  <!-- ═══════════ TEACHERS ═══════════ -->
  <div class="panel" id="panel-teachers">
    <div class="panel-head">
      <div><h2>Teachers</h2><p>The "Our Teachers" section on the website</p></div>
      <button class="btn btn-gold" onclick="openTeacherModal()"><i class="fa-solid fa-plus"></i> Add Teacher</button>
    </div>
    <div class="grid grid-cards" id="teacherGrid"><div class="loading">Loading…</div></div>
  </div>

  <!-- ═══════════ SCHEDULE ═══════════ -->
  <div class="panel" id="panel-schedule">
    <div class="panel-head">
      <div><h2>Weekly Schedule</h2><p>Class and activity times shown on the website</p></div>
      <button class="btn btn-gold" onclick="openScheduleModal()"><i class="fa-solid fa-plus"></i> Add Entry</button>
    </div>
    <div id="scheduleList"><div class="loading">Loading…</div></div>
  </div>

  <!-- ═══════════ PROGRAMS ═══════════ -->
  <div class="panel" id="panel-programs">
    <div class="panel-head">
      <div><h2>Programs</h2><p>Educational programs shown on the website</p></div>
      <button class="btn btn-gold" onclick="openProgramModal()"><i class="fa-solid fa-plus"></i> Add Program</button>
    </div>
    <div class="grid grid-cards" id="programGrid"><div class="loading">Loading…</div></div>
  </div>

  <!-- ═══════════ SOCIAL ═══════════ -->
  <div class="panel" id="panel-social">
    <div class="panel-head">
      <div><h2>Social Media Links</h2><p>Icons and links shown in the website footer</p></div>
      <button class="btn btn-gold" onclick="openSocialModal()"><i class="fa-solid fa-plus"></i> Add Link</button>
    </div>
    <div id="socialList"><div class="loading">Loading…</div></div>
  </div>

</div>

<!-- Generic modal container -->
<div class="modal-bg" id="modalBg">
  <div class="modal">
    <div class="modal-head">
      <h3 id="modalTitle">Edit</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn btn-gold" id="modalSave" onclick="saveModal()"><i class="fa-solid fa-check"></i> Save</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script src="/admin/js/content_editor.js?v=<?= (int) filemtime(__DIR__ . '/../js/content_editor.js') ?>"></script>
</body>
</html>
