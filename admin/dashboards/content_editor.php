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
.photo-card img{width:100%;height:150px;object-fit:cover;display:block}
.photo-card .feat-star{position:absolute;top:6px;right:6px;background:var(--gold);color:var(--maroon-dark);width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.7rem}

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

<script>
const CSRF = '<?= $csrfToken ?>';
const API = '/admin/api_cms.php';

/* ─── helpers ─── */
function esc(s){const d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
function toast(msg,isError){const t=document.getElementById('toast');t.innerHTML=(isError?'<i class="fa-solid fa-circle-exclamation"></i>':'<i class="fa-solid fa-circle-check"></i>')+' '+esc(msg);t.className='toast show'+(isError?' error':'');setTimeout(()=>t.className='toast',3000);}

async function api(action, formData){
  formData = formData || new FormData();
  formData.append('action', action);
  formData.append('csrf_token', CSRF);
  try{
    const r = await fetch(API,{method:'POST',body:formData,headers:{'Accept':'application/json'}});
    const ct = r.headers.get('content-type')||'';
    if(!ct.includes('application/json')){
      if(r.redirected||r.url.includes('index.php')){toast('Session expired. Reloading…',true);setTimeout(()=>location.reload(),1200);return null;}
      toast('Unexpected server response',true);return null;
    }
    const d = await r.json();
    if(d.status==='session_expired'){toast('Session expired. Reloading…',true);setTimeout(()=>location.reload(),1200);return null;}
    return d;
  }catch(e){
    if(!navigator.onLine){toast('You are offline',true);return null;}
    toast('Connection error',true);console.error(e);return null;
  }
}

async function apiGet(action, params){
  const qs = new URLSearchParams({action, ...(params||{})}).toString();
  try{
    const r = await fetch(API+'?'+qs,{headers:{'Accept':'application/json'}});
    const ct=r.headers.get('content-type')||'';
    if(!ct.includes('application/json')){location.reload();return null;}
    return await r.json();
  }catch(e){toast('Connection error',true);return null;}
}

/* ─── tab switching ─── */
document.querySelectorAll('.tab').forEach(t=>{
  t.addEventListener('click',()=>{
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(x=>x.classList.remove('active'));
    t.classList.add('active');
    const p = t.dataset.panel;
    document.getElementById('panel-'+p).classList.add('active');
    loadPanel(p);
  });
});

const loaded = {};
function loadPanel(p, force){
  if(loaded[p] && !force) return;
  loaded[p] = true;
  if(p==='registrations') loadRegistrations('all');
  if(p==='gallery') loadGallery();
  if(p==='teachers') loadTeachers();
  if(p==='schedule') loadSchedule();
  if(p==='programs') loadPrograms();
  if(p==='social') loadSocial();
}

/* ════════ REGISTRATIONS ════════ */
let currentRegFilter='all';
async function loadRegistrations(filter){
  currentRegFilter = filter||'all';
  const d = await apiGet('sub_list',{filter:currentRegFilter});
  if(!d||d.status!=='success') return;
  // badges
  const counts = d.counts||{};
  document.querySelectorAll('#regFilters .badge').forEach(b=>{b.textContent=counts[b.dataset.count]||0;});
  const newCount = counts.new||0;
  const rb=document.getElementById('regBadge');
  if(newCount>0){rb.style.display='inline-block';rb.textContent=newCount;}else{rb.style.display='none';}

  const list=document.getElementById('regList');
  if(!d.data.length){list.innerHTML='<div class="empty"><i class="fa-solid fa-inbox"></i>No submissions yet.</div>';return;}
  let html='<table class="tbl"><thead><tr><th>Name</th><th>Phone</th><th>Interest</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody>';
  d.data.forEach(s=>{
    const dt=(typeof WBWSCalendar!=='undefined')?WBWSCalendar.formatDate(s.created_at,'medium'):new Date(s.created_at).toLocaleDateString();
    html+=`<tr>
      <td><strong>${esc(s.full_name)}</strong>${s.email?'<br><span style="font-size:0.72rem;color:var(--text-dim)">'+esc(s.email)+'</span>':''}</td>
      <td>${esc(s.phone)}</td>
      <td>${esc(s.program_interest||'—')}</td>
      <td>${dt}</td>
      <td><span class="pill pill-${s.status}">${s.status}</span></td>
      <td><button class="btn btn-ghost btn-sm" onclick='viewSub(${JSON.stringify(s)})'><i class="fa-solid fa-eye"></i></button></td>
    </tr>`;
  });
  html+='</tbody></table>';
  list.innerHTML=html;
}
document.querySelectorAll('#regFilters .subtab').forEach(s=>{
  s.addEventListener('click',()=>{
    document.querySelectorAll('#regFilters .subtab').forEach(x=>x.classList.remove('active'));
    s.classList.add('active');
    loadRegistrations(s.dataset.filter);
  });
});

function viewSub(s){
  modalMode={type:'sub',id:s.id};
  document.getElementById('modalTitle').textContent='Submission Details';
  document.getElementById('modalBody').innerHTML=`
    <div class="field"><label>Name</label><div style="padding:0.5rem 0;font-weight:600">${esc(s.full_name)}</div></div>
    <div class="field-row">
      <div class="field"><label>Phone</label><div style="padding:0.5rem 0">${esc(s.phone)}</div></div>
      <div class="field"><label>Email</label><div style="padding:0.5rem 0">${esc(s.email||'—')}</div></div>
    </div>
    <div class="field-row">
      <div class="field"><label>Age</label><div style="padding:0.5rem 0">${esc(s.age||'—')}</div></div>
      <div class="field"><label>Gender</label><div style="padding:0.5rem 0">${esc(s.gender||'—')}</div></div>
    </div>
    <div class="field"><label>Address</label><div style="padding:0.5rem 0">${esc(s.address||'—')}</div></div>
    <div class="field"><label>Program Interest</label><div style="padding:0.5rem 0">${esc(s.program_interest||'—')}</div></div>
    <div class="field"><label>Message</label><div style="padding:0.5rem 0;white-space:pre-wrap">${esc(s.message||'—')}</div></div>
    <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0">
    <div class="field"><label>Status</label>
      <select id="m_status">
        <option value="new"${s.status==='new'?' selected':''}>New</option>
        <option value="contacted"${s.status==='contacted'?' selected':''}>Contacted</option>
        <option value="enrolled"${s.status==='enrolled'?' selected':''}>Enrolled</option>
        <option value="rejected"${s.status==='rejected'?' selected':''}>Rejected</option>
      </select>
    </div>
    <div class="field"><label>Admin Notes</label><textarea id="m_notes" placeholder="Internal notes (not shown publicly)">${esc(s.admin_notes||'')}</textarea></div>
    <button class="btn btn-danger btn-sm" onclick="deleteSub(${s.id})"><i class="fa-solid fa-trash"></i> Delete this submission</button>
  `;
  document.getElementById('modalBg').classList.add('open');
}
async function deleteSub(id){
  if(!confirm('Delete this submission permanently?'))return;
  const fd=new FormData();fd.append('id',id);
  const d=await api('sub_delete',fd);
  if(d&&d.status==='success'){toast(d.message);closeModal();loadRegistrations(currentRegFilter);}
  else if(d) toast(d.message,true);
}

/* ════════ GALLERY ════════ */
let galCategories=[];let currentGalCat=0;
async function loadGallery(){
  const c=await apiGet('cat_list');
  if(c&&c.status==='success'){galCategories=c.data;renderGalFilters();}
  loadPhotos(currentGalCat);
}
function renderGalFilters(){
  let html='<div class="subtab'+(currentGalCat===0?' active':'')+'" onclick="loadPhotos(0)">All Photos</div>';
  galCategories.forEach(c=>{
    html+=`<div class="subtab${currentGalCat==c.id?' active':''}" onclick="loadPhotos(${c.id})">${esc(c.name)} <button class="modal-close" style="font-size:0.9rem;padding:0 0 0 4px" onclick="event.stopPropagation();editCat(${JSON.stringify(c).replace(/"/g,'&quot;')})"><i class="fa-solid fa-pen" style="font-size:0.6rem"></i></button></div>`;
  });
  document.getElementById('galCatFilters').innerHTML=html;
}
async function loadPhotos(catId){
  currentGalCat=catId;renderGalFilters();
  const d=await apiGet('photo_list',catId?{category_id:catId}:{});
  const grid=document.getElementById('photoGrid');
  if(!d||d.status!=='success'){grid.innerHTML='';return;}
  if(!d.data.length){grid.innerHTML='<div class="empty" style="grid-column:1/-1"><i class="fa-solid fa-images"></i>No photos yet. Click "Upload Photo" to add some.</div>';return;}
  grid.innerHTML=d.data.map(p=>`
    <div class="card photo-card">
      ${p.is_featured==1?'<div class="feat-star"><i class="fa-solid fa-star"></i></div>':''}
      <img src="${esc(p.image_path)}" alt="${esc(p.caption||'')}">
      <div class="card-body">
        <div class="card-title" style="font-size:0.8rem">${esc(p.caption||'Untitled')}</div>
        <div class="card-meta">${esc(p.category_name||'Uncategorized')}</div>
        <div class="card-actions">
          <button class="btn btn-ghost btn-sm" onclick='editPhoto(${JSON.stringify(p)})'><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-danger btn-sm" onclick="deletePhoto(${p.id})"><i class="fa-solid fa-trash"></i></button>
        </div>
      </div>
    </div>`).join('');
}
function catOptions(sel){
  return galCategories.map(c=>`<option value="${c.id}"${sel==c.id?' selected':''}>${esc(c.name)}</option>`).join('');
}
function openPhotoModal(){
  modalMode={type:'photo_new'};
  document.getElementById('modalTitle').textContent='Upload Photo';
  document.getElementById('modalBody').innerHTML=`
    <div class="field"><label>Image *</label><input type="file" id="m_image" accept="image/*"><div class="hint">JPG, PNG, GIF or WebP · max 8MB</div></div>
    <div class="field"><label>Album</label><select id="m_category"><option value="">— Uncategorized —</option>${catOptions(currentGalCat)}</select></div>
    <div class="field"><label>Caption (English)</label><input type="text" id="m_caption" placeholder="e.g. Christmas celebration 2025"></div>
    <div class="field"><label>Caption (Amharic)</label><input type="text" id="m_caption_am" class="amharic" placeholder="የገና በዓል"></div>
    <div class="check-row"><input type="checkbox" id="m_featured"><label style="margin:0">Featured (show in slideshow)</label></div>
  `;
  document.getElementById('modalBg').classList.add('open');
}
function editPhoto(p){
  modalMode={type:'photo_edit',id:p.id};
  document.getElementById('modalTitle').textContent='Edit Photo';
  document.getElementById('modalBody').innerHTML=`
    <div style="text-align:center;margin-bottom:1rem"><img src="${esc(p.image_path)}" style="max-width:100%;max-height:160px;border-radius:0.5rem"></div>
    <div class="field"><label>Album</label><select id="m_category"><option value="">— Uncategorized —</option>${catOptions(p.category_id)}</select></div>
    <div class="field"><label>Caption (English)</label><input type="text" id="m_caption" value="${esc(p.caption||'')}"></div>
    <div class="field"><label>Caption (Amharic)</label><input type="text" id="m_caption_am" class="amharic" value="${esc(p.caption_am||'')}"></div>
    <div class="check-row"><input type="checkbox" id="m_featured"${p.is_featured==1?' checked':''}><label style="margin:0">Featured (show in slideshow)</label></div>
  `;
  document.getElementById('modalBg').classList.add('open');
}
async function deletePhoto(id){
  if(!confirm('Delete this photo?'))return;
  const fd=new FormData();fd.append('id',id);
  const d=await api('photo_delete',fd);
  if(d&&d.status==='success'){toast(d.message);loadPhotos(currentGalCat);}
  else if(d)toast(d.message,true);
}
function openCatModal(){
  modalMode={type:'cat_new'};
  document.getElementById('modalTitle').textContent='New Album';
  document.getElementById('modalBody').innerHTML=`
    <div class="field"><label>Album Name (English) *</label><input type="text" id="m_name" placeholder="e.g. Events"></div>
    <div class="field"><label>Album Name (Amharic)</label><input type="text" id="m_name_am" class="amharic"></div>
    <div class="field"><label>Description</label><textarea id="m_description"></textarea></div>
  `;
  document.getElementById('modalBg').classList.add('open');
}
function editCat(c){
  modalMode={type:'cat_edit',id:c.id};
  document.getElementById('modalTitle').textContent='Edit Album';
  document.getElementById('modalBody').innerHTML=`
    <div class="field"><label>Album Name (English) *</label><input type="text" id="m_name" value="${esc(c.name)}"></div>
    <div class="field"><label>Album Name (Amharic)</label><input type="text" id="m_name_am" class="amharic" value="${esc(c.name_am||'')}"></div>
    <div class="field"><label>Description</label><textarea id="m_description">${esc(c.description||'')}</textarea></div>
    <button class="btn btn-danger btn-sm" onclick="deleteCat(${c.id})"><i class="fa-solid fa-trash"></i> Delete album</button>
  `;
  document.getElementById('modalBg').classList.add('open');
}
async function deleteCat(id){
  if(!confirm('Delete this album? Photos inside become uncategorized.'))return;
  const fd=new FormData();fd.append('id',id);
  const d=await api('cat_delete',fd);
  if(d&&d.status==='success'){toast(d.message);closeModal();currentGalCat=0;loadGallery();}
  else if(d)toast(d.message,true);
}

/* ════════ TEACHERS ════════ */
async function loadTeachers(){
  const d=await apiGet('teacher_list');
  const grid=document.getElementById('teacherGrid');
  if(!d||d.status!=='success'){grid.innerHTML='';return;}
  if(!d.data.length){grid.innerHTML='<div class="empty" style="grid-column:1/-1"><i class="fa-solid fa-chalkboard-user"></i>No teachers added yet.</div>';return;}
  grid.innerHTML=d.data.map(t=>`
    <div class="card">
      ${t.photo_path?`<img src="${esc(t.photo_path)}" style="width:100%;height:160px;object-fit:cover">`:`<div style="height:160px;background:var(--bg);display:flex;align-items:center;justify-content:center;color:var(--border);font-size:2.5rem"><i class="fa-solid fa-user"></i></div>`}
      <div class="card-body">
        <div class="card-title amharic">${esc(t.name_am||t.name)}</div>
        <div class="card-meta">${esc(t.role_title||'')}</div>
        ${t.is_active==0?'<div style="font-size:0.7rem;color:#b91c1c;margin-top:3px"><i class="fa-solid fa-eye-slash"></i> Hidden</div>':''}
        <div class="card-actions">
          <button class="btn btn-ghost btn-sm" onclick='editTeacher(${JSON.stringify(t)})'><i class="fa-solid fa-pen"></i> Edit</button>
          <button class="btn btn-danger btn-sm" onclick="deleteTeacher(${t.id})"><i class="fa-solid fa-trash"></i></button>
        </div>
      </div>
    </div>`).join('');
}
function teacherForm(t){
  t=t||{};
  return `
    <div class="field"><label>Photo</label><input type="file" id="m_photo" accept="image/*">${t.photo_path?`<div class="hint">Current photo will be kept unless you choose a new one</div>`:''}</div>
    <div class="field-row">
      <div class="field"><label>Name (English) *</label><input type="text" id="m_name" value="${esc(t.name||'')}"></div>
      <div class="field"><label>Name (Amharic)</label><input type="text" id="m_name_am" class="amharic" value="${esc(t.name_am||'')}"></div>
    </div>
    <div class="field-row">
      <div class="field"><label>Role/Title (English)</label><input type="text" id="m_role_title" value="${esc(t.role_title||'')}" placeholder="e.g. Head Teacher"></div>
      <div class="field"><label>Role/Title (Amharic)</label><input type="text" id="m_role_title_am" class="amharic" value="${esc(t.role_title_am||'')}"></div>
    </div>
    <div class="field"><label>Short Bio</label><textarea id="m_bio">${esc(t.bio||'')}</textarea></div>
    <div class="field-row">
      <div class="field"><label>Display Order</label><input type="number" id="m_sort_order" value="${t.sort_order||0}"></div>
      <div class="field"><label>&nbsp;</label><div class="check-row"><input type="checkbox" id="m_active"${(t.is_active==1||t.id===undefined)?' checked':''}><label style="margin:0">Show on website</label></div></div>
    </div>`;
}
function openTeacherModal(){modalMode={type:'teacher_new'};document.getElementById('modalTitle').textContent='Add Teacher';document.getElementById('modalBody').innerHTML=teacherForm();document.getElementById('modalBg').classList.add('open');}
function editTeacher(t){modalMode={type:'teacher_edit',id:t.id};document.getElementById('modalTitle').textContent='Edit Teacher';document.getElementById('modalBody').innerHTML=teacherForm(t);document.getElementById('modalBg').classList.add('open');}
async function deleteTeacher(id){if(!confirm('Delete this teacher?'))return;const fd=new FormData();fd.append('id',id);const d=await api('teacher_delete',fd);if(d&&d.status==='success'){toast(d.message);loadTeachers();}else if(d)toast(d.message,true);}

/* ════════ SCHEDULE ════════ */
async function loadSchedule(){
  const d=await apiGet('schedule_list');
  const list=document.getElementById('scheduleList');
  if(!d||d.status!=='success'){list.innerHTML='';return;}
  if(!d.data.length){list.innerHTML='<div class="empty"><i class="fa-solid fa-calendar-week"></i>No schedule entries yet.</div>';return;}
  let html='<table class="tbl"><thead><tr><th>Day</th><th>Time</th><th>Activity</th><th>Location</th><th>Visible</th><th></th></tr></thead><tbody>';
  d.data.forEach(s=>{
    html+=`<tr>
      <td><strong>${esc(s.day_of_week)}</strong>${s.day_of_week_am?'<br><span class="amharic" style="font-size:0.72rem;color:var(--text-dim)">'+esc(s.day_of_week_am)+'</span>':''}</td>
      <td>${esc(s.time_label||'—')}</td>
      <td>${esc(s.activity)}${s.activity_am?'<br><span class="amharic" style="font-size:0.72rem;color:var(--text-dim)">'+esc(s.activity_am)+'</span>':''}</td>
      <td>${esc(s.location||'—')}</td>
      <td>${s.is_active==1?'<i class="fa-solid fa-eye" style="color:#166534"></i>':'<i class="fa-solid fa-eye-slash" style="color:#b91c1c"></i>'}</td>
      <td><button class="btn btn-ghost btn-sm" onclick='editSchedule(${JSON.stringify(s)})'><i class="fa-solid fa-pen"></i></button> <button class="btn btn-danger btn-sm" onclick="deleteSchedule(${s.id})"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  });
  html+='</tbody></table>';
  list.innerHTML=html;
}
function scheduleForm(s){
  s=s||{};
  return `
    <div class="field-row">
      <div class="field"><label>Day (English) *</label><input type="text" id="m_day_of_week" value="${esc(s.day_of_week||'')}" placeholder="Sunday"></div>
      <div class="field"><label>Day (Amharic)</label><input type="text" id="m_day_of_week_am" class="amharic" value="${esc(s.day_of_week_am||'')}" placeholder="እሁድ"></div>
    </div>
    <div class="field"><label>Time</label><input type="text" id="m_time_label" value="${esc(s.time_label||'')}" placeholder="e.g. 8:00 AM - 10:00 AM"></div>
    <div class="field-row">
      <div class="field"><label>Activity (English) *</label><input type="text" id="m_activity" value="${esc(s.activity||'')}"></div>
      <div class="field"><label>Activity (Amharic)</label><input type="text" id="m_activity_am" class="amharic" value="${esc(s.activity_am||'')}"></div>
    </div>
    <div class="field"><label>Location</label><input type="text" id="m_location" value="${esc(s.location||'')}"></div>
    <div class="field-row">
      <div class="field"><label>Display Order</label><input type="number" id="m_sort_order" value="${s.sort_order||0}"></div>
      <div class="field"><label>&nbsp;</label><div class="check-row"><input type="checkbox" id="m_active"${(s.is_active==1||s.id===undefined)?' checked':''}><label style="margin:0">Show on website</label></div></div>
    </div>`;
}
function openScheduleModal(){modalMode={type:'schedule_new'};document.getElementById('modalTitle').textContent='Add Schedule Entry';document.getElementById('modalBody').innerHTML=scheduleForm();document.getElementById('modalBg').classList.add('open');}
function editSchedule(s){modalMode={type:'schedule_edit',id:s.id};document.getElementById('modalTitle').textContent='Edit Schedule Entry';document.getElementById('modalBody').innerHTML=scheduleForm(s);document.getElementById('modalBg').classList.add('open');}
async function deleteSchedule(id){if(!confirm('Delete this schedule entry?'))return;const fd=new FormData();fd.append('id',id);const d=await api('schedule_delete',fd);if(d&&d.status==='success'){toast(d.message);loadSchedule();}else if(d)toast(d.message,true);}

/* ════════ PROGRAMS ════════ */
async function loadPrograms(){
  const d=await apiGet('program_list');
  const grid=document.getElementById('programGrid');
  if(!d||d.status!=='success'){grid.innerHTML='';return;}
  if(!d.data.length){grid.innerHTML='<div class="empty" style="grid-column:1/-1"><i class="fa-solid fa-graduation-cap"></i>No programs yet.</div>';return;}
  grid.innerHTML=d.data.map(p=>`
    <div class="card"><div class="card-body">
      <div style="font-size:1.5rem;color:var(--gold-dark);margin-bottom:0.5rem"><i class="${esc(p.icon_class||'fa-solid fa-book')}"></i></div>
      <div class="card-title">${esc(p.title)}</div>
      ${p.title_am?'<div class="card-meta amharic">'+esc(p.title_am)+'</div>':''}
      <div class="card-meta" style="margin-top:0.4rem;line-height:1.4">${esc((p.description||'').substring(0,80))}${(p.description||'').length>80?'…':''}</div>
      ${p.is_active==0?'<div style="font-size:0.7rem;color:#b91c1c;margin-top:5px"><i class="fa-solid fa-eye-slash"></i> Hidden</div>':''}
      <div class="card-actions">
        <button class="btn btn-ghost btn-sm" onclick='editProgram(${JSON.stringify(p)})'><i class="fa-solid fa-pen"></i> Edit</button>
        <button class="btn btn-danger btn-sm" onclick="deleteProgram(${p.id})"><i class="fa-solid fa-trash"></i></button>
      </div>
    </div></div>`).join('');
}
function programForm(p){
  p=p||{};
  return `
    <div class="field"><label>Icon (Font Awesome class)</label><input type="text" id="m_icon_class" value="${esc(p.icon_class||'fa-solid fa-book')}" placeholder="fa-solid fa-book"><div class="hint">Find icons at fontawesome.com/icons — e.g. fa-solid fa-cross, fa-solid fa-music</div></div>
    <div class="field-row">
      <div class="field"><label>Title (English) *</label><input type="text" id="m_title" value="${esc(p.title||'')}"></div>
      <div class="field"><label>Title (Amharic)</label><input type="text" id="m_title_am" class="amharic" value="${esc(p.title_am||'')}"></div>
    </div>
    <div class="field"><label>Description (English)</label><textarea id="m_description">${esc(p.description||'')}</textarea></div>
    <div class="field"><label>Description (Amharic)</label><textarea id="m_description_am" class="amharic">${esc(p.description_am||'')}</textarea></div>
    <div class="field"><label>Key Features (one per line)</label><textarea id="m_features" placeholder="Bible study&#10;Prayer&#10;Hymns">${esc(p.features||'')}</textarea><div class="hint">Each line becomes a bullet point with a checkmark</div></div>
    <div class="field-row">
      <div class="field"><label>Display Order</label><input type="number" id="m_sort_order" value="${p.sort_order||0}"></div>
      <div class="field"><label>&nbsp;</label><div class="check-row"><input type="checkbox" id="m_active"${(p.is_active==1||p.id===undefined)?' checked':''}><label style="margin:0">Show on website</label></div></div>
    </div>`;
}
function openProgramModal(){modalMode={type:'program_new'};document.getElementById('modalTitle').textContent='Add Program';document.getElementById('modalBody').innerHTML=programForm();document.getElementById('modalBg').classList.add('open');}
function editProgram(p){modalMode={type:'program_edit',id:p.id};document.getElementById('modalTitle').textContent='Edit Program';document.getElementById('modalBody').innerHTML=programForm(p);document.getElementById('modalBg').classList.add('open');}
async function deleteProgram(id){if(!confirm('Delete this program?'))return;const fd=new FormData();fd.append('id',id);const d=await api('program_delete',fd);if(d&&d.status==='success'){toast(d.message);loadPrograms();}else if(d)toast(d.message,true);}

/* ════════ SOCIAL ════════ */
async function loadSocial(){
  const d=await apiGet('social_list');
  const list=document.getElementById('socialList');
  if(!d||d.status!=='success'){list.innerHTML='';return;}
  if(!d.data.length){list.innerHTML='<div class="empty"><i class="fa-solid fa-share-nodes"></i>No social links yet.</div>';return;}
  let html='<table class="tbl"><thead><tr><th>Icon</th><th>Platform</th><th>URL</th><th>Visible</th><th></th></tr></thead><tbody>';
  d.data.forEach(s=>{
    html+=`<tr>
      <td style="font-size:1.2rem;color:var(--maroon)"><i class="${esc(s.icon_class)}"></i></td>
      <td><strong>${esc(s.platform)}</strong></td>
      <td style="font-size:0.78rem;color:var(--text-dim);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.url)}</td>
      <td>${s.is_active==1?'<i class="fa-solid fa-eye" style="color:#166534"></i>':'<i class="fa-solid fa-eye-slash" style="color:#b91c1c"></i>'}</td>
      <td><button class="btn btn-ghost btn-sm" onclick='editSocial(${JSON.stringify(s)})'><i class="fa-solid fa-pen"></i></button> <button class="btn btn-danger btn-sm" onclick="deleteSocial(${s.id})"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  });
  html+='</tbody></table>';
  list.innerHTML=html;
}
const ICONS={Facebook:'fa-brands fa-facebook',Telegram:'fa-brands fa-telegram',YouTube:'fa-brands fa-youtube',TikTok:'fa-brands fa-tiktok',Instagram:'fa-brands fa-instagram',Twitter:'fa-brands fa-x-twitter',WhatsApp:'fa-brands fa-whatsapp',LinkedIn:'fa-brands fa-linkedin'};
function socialForm(s){
  s=s||{};
  const opts=Object.keys(ICONS).map(p=>`<option value="${p}"${s.platform===p?' selected':''}>${p}</option>`).join('');
  return `
    <div class="field"><label>Platform *</label><select id="m_platform" onchange="document.getElementById('m_icon_class').value=({${Object.entries(ICONS).map(([k,v])=>`'${k}':'${v}'`).join(',')}})[this.value]||'fa-solid fa-link'">${opts}<option value="Other"${s.platform&&!ICONS[s.platform]?' selected':''}>Other</option></select></div>
    <div class="field"><label>Icon class</label><input type="text" id="m_icon_class" value="${esc(s.icon_class||'fa-brands fa-facebook')}"></div>
    <div class="field"><label>URL *</label><input type="text" id="m_url" value="${esc(s.url||'')}" placeholder="https://..."></div>
    <div class="field"><label>Label (optional)</label><input type="text" id="m_label" value="${esc(s.label||'')}"></div>
    <div class="field-row">
      <div class="field"><label>Display Order</label><input type="number" id="m_sort_order" value="${s.sort_order||0}"></div>
      <div class="field"><label>&nbsp;</label><div class="check-row"><input type="checkbox" id="m_active"${(s.is_active==1||s.id===undefined)?' checked':''}><label style="margin:0">Show on website</label></div></div>
    </div>`;
}
function openSocialModal(){modalMode={type:'social_new'};document.getElementById('modalTitle').textContent='Add Social Link';document.getElementById('modalBody').innerHTML=socialForm();document.getElementById('modalBg').classList.add('open');}
function editSocial(s){modalMode={type:'social_edit',id:s.id};document.getElementById('modalTitle').textContent='Edit Social Link';document.getElementById('modalBody').innerHTML=socialForm(s);document.getElementById('modalBg').classList.add('open');}
async function deleteSocial(id){if(!confirm('Delete this social link?'))return;const fd=new FormData();fd.append('id',id);const d=await api('social_delete',fd);if(d&&d.status==='success'){toast(d.message);loadSocial();}else if(d)toast(d.message,true);}

/* ════════ MODAL SAVE ROUTER ════════ */
let modalMode={};
function closeModal(){document.getElementById('modalBg').classList.remove('open');modalMode={};}
function val(id){const el=document.getElementById(id);return el?el.value:'';}
function checked(id){const el=document.getElementById(id);return el&&el.checked;}
function fileOf(id){const el=document.getElementById(id);return el&&el.files&&el.files[0]?el.files[0]:null;}

async function saveModal(){
  const btn=document.getElementById('modalSave');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
  const fd=new FormData();
  let action='';
  const m=modalMode;

  if(m.type==='cat_new'||m.type==='cat_edit'){
    action='cat_save';if(m.id)fd.append('id',m.id);
    fd.append('name',val('m_name'));fd.append('name_am',val('m_name_am'));fd.append('description',val('m_description'));
  }
  else if(m.type==='photo_new'){
    action='photo_upload';
    const img=fileOf('m_image');if(!img){toast('Please choose an image',true);resetBtn();return;}
    fd.append('image',img);fd.append('category_id',val('m_category'));fd.append('caption',val('m_caption'));fd.append('caption_am',val('m_caption_am'));if(checked('m_featured'))fd.append('is_featured','1');
  }
  else if(m.type==='photo_edit'){
    action='photo_update';fd.append('id',m.id);
    fd.append('category_id',val('m_category'));fd.append('caption',val('m_caption'));fd.append('caption_am',val('m_caption_am'));if(checked('m_featured'))fd.append('is_featured','1');
  }
  else if(m.type==='teacher_new'||m.type==='teacher_edit'){
    action='teacher_save';if(m.id)fd.append('id',m.id);
    const ph=fileOf('m_photo');if(ph)fd.append('photo',ph);
    fd.append('name',val('m_name'));fd.append('name_am',val('m_name_am'));fd.append('role_title',val('m_role_title'));fd.append('role_title_am',val('m_role_title_am'));fd.append('bio',val('m_bio'));fd.append('sort_order',val('m_sort_order'));if(checked('m_active'))fd.append('is_active','1');
  }
  else if(m.type==='schedule_new'||m.type==='schedule_edit'){
    action='schedule_save';if(m.id)fd.append('id',m.id);
    fd.append('day_of_week',val('m_day_of_week'));fd.append('day_of_week_am',val('m_day_of_week_am'));fd.append('time_label',val('m_time_label'));fd.append('activity',val('m_activity'));fd.append('activity_am',val('m_activity_am'));fd.append('location',val('m_location'));fd.append('sort_order',val('m_sort_order'));if(checked('m_active'))fd.append('is_active','1');
  }
  else if(m.type==='program_new'||m.type==='program_edit'){
    action='program_save';if(m.id)fd.append('id',m.id);
    fd.append('title',val('m_title'));fd.append('title_am',val('m_title_am'));fd.append('description',val('m_description'));fd.append('description_am',val('m_description_am'));fd.append('icon_class',val('m_icon_class'));fd.append('features',val('m_features'));fd.append('sort_order',val('m_sort_order'));if(checked('m_active'))fd.append('is_active','1');
  }
  else if(m.type==='social_new'||m.type==='social_edit'){
    action='social_save';if(m.id)fd.append('id',m.id);
    fd.append('platform',val('m_platform'));fd.append('url',val('m_url'));fd.append('icon_class',val('m_icon_class'));fd.append('label',val('m_label'));fd.append('sort_order',val('m_sort_order'));if(checked('m_active'))fd.append('is_active','1');
  }
  else if(m.type==='sub'){
    action='sub_update_status';fd.append('id',m.id);fd.append('status',val('m_status'));fd.append('admin_notes',val('m_notes'));
  }

  const d=await api(action,fd);
  resetBtn();
  if(d&&d.status==='success'){
    toast(d.message);closeModal();
    // reload the relevant panel
    if(action.startsWith('cat')||action.startsWith('photo')){currentGalCat=currentGalCat;loadGallery();}
    else if(action.startsWith('teacher'))loadTeachers();
    else if(action.startsWith('schedule'))loadSchedule();
    else if(action.startsWith('program'))loadPrograms();
    else if(action.startsWith('social'))loadSocial();
    else if(action.startsWith('sub'))loadRegistrations(currentRegFilter);
  }else if(d){toast(d.message,true);}
  function resetBtn(){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Save';}
}
function resetBtn(){const btn=document.getElementById('modalSave');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Save';}

// Close modal on background click
document.getElementById('modalBg').addEventListener('click',e=>{if(e.target.id==='modalBg')closeModal();});

// Initial load
loadRegistrations('all');
</script>
</body>
</html>
