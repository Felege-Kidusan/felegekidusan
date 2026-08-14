<?php
/**
 * Groups & Associations Management
 * PROFESSIONAL REBUILD v3.0 - Fixed server errors, PDF export, analytics
 */
require_once __DIR__ . '/config.php';
if (!isLoggedIn()) { header('Location: index.php'); exit; }
require __DIR__ . '/backend/config.php';
if (file_exists(__DIR__ . '/backend/ethiopian_date.php')) {
    require_once __DIR__ . '/backend/ethiopian_date.php';
}
$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User';
$userRole = $_SESSION['admin_role'] ?? '';
$todayFormatted = function_exists('ethio_date_format') ? ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'F j, Y') : date('F j, Y');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$stats = ['total_groups'=>0,'under_ss'=>0,'parish'=>0,'total_leaders'=>0,'total_members'=>0];
if (isset($conn) && $conn) {
    try {
        $r=$conn->query("SELECT COUNT(*) as c FROM wbws_groups");if($r)$stats['total_groups']=(int)$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(*) as c FROM wbws_groups WHERE is_under_sunday_school=1");if($r)$stats['under_ss']=(int)$r->fetch_assoc()['c'];
        $stats['parish']=$stats['total_groups']-$stats['under_ss'];
        $r=$conn->query("SELECT COUNT(*) as c FROM wbws_group_leaders");if($r)$stats['total_leaders']=(int)$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(*) as c FROM wbws_group_members");if($r)$stats['total_members']=(int)$r->fetch_assoc()['c'];
    } catch(Exception $e){}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Groups Management - <?= SCHOOL_NAME_SHORT ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Inter',system-ui,sans-serif;background:#f0fdf4;min-height:100vh}
.sidebar{width:260px;background:linear-gradient(180deg,#047857,#065f46);position:fixed;top:0;left:0;height:100vh;padding:1.25rem;display:flex;flex-direction:column;color:#fff;z-index:40;transition:transform .3s}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:35}.sidebar-overlay.active{display:block}
@media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}}
.main{margin-left:260px;min-height:100vh}@media(max-width:768px){.main{margin-left:0}}
.topbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:1rem 1.5rem;position:sticky;top:0;z-index:30;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
.nav-link{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-radius:.75rem;color:rgba(255,255,255,.8);font-size:.875rem;transition:all .2s;cursor:pointer;text-decoration:none;border:none;background:0;width:100%;text-align:left}
.nav-link:hover,.nav-link.active{background:rgba(255,255,255,.15);color:#fff}.nav-link i{width:20px;text-align:center}
.card{background:#fff;border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid #e2e8f0;overflow:hidden}
.card-header{padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem}
.card-body{padding:1.25rem}
.stat-card{background:#fff;border-radius:1rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid #e2e8f0;display:flex;align-items:center;gap:1rem;transition:all .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,.1)}
.stat-icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.25rem}
.stat-value{font-size:1.75rem;font-weight:700;color:#1e293b}.stat-label{font-size:.75rem;color:#64748b}
.table-container{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.875rem}
th,td{padding:.875rem 1rem;text-align:left;border-bottom:1px solid #f1f5f9}
th{background:#f8fafc;color:#64748b;font-weight:600;font-size:.75rem;text-transform:uppercase;white-space:nowrap}tr:hover td{background:#f0fdf4}
.badge{display:inline-flex;align-items:center;padding:.25rem .6rem;border-radius:999px;font-size:.7rem;font-weight:600;gap:.25rem}
.badge-green{background:#dcfce7;color:#166534}.badge-blue{background:#dbeafe;color:#1e40af}.badge-purple{background:#f3e8ff;color:#7c3aed}.badge-amber{background:#fef3c7;color:#92400e}
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.625rem 1.25rem;border-radius:.625rem;font-size:.8125rem;font-weight:600;cursor:pointer;transition:all .2s;border:none;text-decoration:none}
.btn-primary{background:linear-gradient(135deg,#059669,#10b981);color:#fff}.btn-primary:hover{box-shadow:0 4px 12px rgba(16,185,129,.4);transform:translateY(-1px)}
.btn-secondary{background:#f1f5f9;color:#475569}.btn-secondary:hover{background:#e2e8f0}
.btn-danger{background:#ef4444;color:#fff}.btn-outline{background:0;border:1px solid #e2e8f0;color:#64748b}.btn-outline:hover{background:#f1f5f9}
.btn-sm{padding:.5rem .875rem;font-size:.75rem}.btn:disabled{opacity:.6;cursor:not-allowed}
.btn .spinner{display:none}.btn.loading .spinner{display:inline-block}.btn.loading .btn-text{display:none}
.action-btn{width:34px;height:34px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:#64748b;font-size:.8rem}
.action-btn:hover{background:#f1f5f9}.action-btn.edit:hover{background:#dbeafe;color:#1d4ed8}.action-btn.danger:hover{background:#fee2e2;color:#dc2626}.action-btn.view:hover{background:#dcfce7;color:#16a34a}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:50;padding:1rem;backdrop-filter:blur(4px)}.modal.open{display:flex}
.modal-content{background:#fff;border-radius:1rem;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;animation:mu .3s}.modal-content.lg{max-width:960px}.modal-content.xl{max-width:1100px}
@keyframes mu{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.modal-header{padding:1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:10;border-radius:1rem 1rem 0 0}
.modal-body{padding:1.25rem}.modal-footer{padding:1rem 1.25rem;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:.75rem;position:sticky;bottom:0;background:#fff}
.form-group{margin-bottom:1rem}.form-label{display:block;font-size:.8rem;font-weight:500;color:#374151;margin-bottom:.375rem}
.form-input,.form-select,.form-textarea{width:100%;padding:.625rem .875rem;border:1.5px solid #d1d5db;border-radius:.5rem;font-size:.875rem;transition:all .15s;background:#fafbfc}
.form-input:focus,.form-select:focus,.form-textarea:focus{outline:none;border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15);background:#fff}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}@media(max-width:640px){.form-row{grid-template-columns:1fr}}
.form-section{background:#f0fdf4;border-radius:.75rem;padding:1rem;margin-bottom:1rem;border:1px solid #bbf7d0}
.form-section-title{font-size:.875rem;font-weight:600;color:#047857;margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem}
.stepper{display:flex;align-items:center;margin-bottom:1.5rem;padding:0 .5rem}
.step{display:flex;align-items:center;gap:.5rem;flex:1}.step-num{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;background:#e2e8f0;color:#64748b;transition:all .3s}
.step.active .step-num{background:#059669;color:#fff;box-shadow:0 4px 12px rgba(5,150,105,.3)}.step.done .step-num{background:#10b981;color:#fff}
.step-label{font-size:.75rem;color:#64748b;font-weight:500}.step.active .step-label{color:#059669;font-weight:600}
.step-line{flex:1;height:2px;background:#e2e8f0;margin:0 .5rem}.step-line.active{background:#10b981}
.step-content{display:none}.step-content.active{display:block}
.toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:100;display:flex;flex-direction:column;gap:.5rem}
.toast{padding:1rem 1.25rem;border-radius:.75rem;color:#fff;font-size:.875rem;font-weight:500;display:flex;align-items:center;gap:.75rem;animation:ti .3s;box-shadow:0 4px 12px rgba(0,0,0,.15);min-width:280px}
.toast-success{background:#059669}.toast-error{background:#dc2626}.toast-info{background:#0284c7}@keyframes ti{from{opacity:0;transform:translateX(100px)}to{opacity:1;transform:translateX(0)}}
.tabs{display:flex;gap:.5rem;flex-wrap:wrap}.tab-btn{padding:.5rem 1rem;border-radius:.5rem;font-size:.8rem;font-weight:500;cursor:pointer;border:1px solid transparent;background:0;color:#64748b;transition:all .15s}
.tab-btn:hover{background:#f1f5f9}.tab-btn.active{background:#dcfce7;color:#166534;border-color:#bbf7d0}
.search-box{display:flex;align-items:center;background:#f1f5f9;border-radius:.625rem;padding:0 1rem;gap:.5rem}
.search-box input{border:none;background:0;padding:.625rem 0;font-size:.875rem;width:200px}.search-box input:focus{outline:none}
.empty-state{text-align:center;padding:3rem;color:#64748b}.empty-state i{font-size:3rem;color:#cbd5e1;margin-bottom:1rem;display:block}
.pagination{display:flex;align-items:center;gap:.5rem;justify-content:center;padding:1rem}
.pagination button{width:36px;height:36px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;font-size:.875rem;transition:all .15s}
.pagination button:hover:not(:disabled){background:#f1f5f9}.pagination button.active{background:#059669;color:#fff;border-color:#059669}.pagination button:disabled{opacity:.5;cursor:not-allowed}
.hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;align-items:center;justify-content:center;font-size:1.25rem;color:#475569}
@media(max-width:768px){.hamburger{display:flex}.hide-mobile{display:none!important}}
.analytics-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}@media(max-width:768px){.analytics-grid{grid-template-columns:1fr}}
.growth-tag{display:inline-flex;align-items:center;gap:.25rem;padding:.125rem .5rem;border-radius:999px;font-size:.7rem;font-weight:600}
.growth-up{background:#dcfce7;color:#166534}.growth-down{background:#fee2e2;color:#991b1b}
.export-option{padding:.75rem;border:1.5px solid #e2e8f0;border-radius:.75rem;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:.75rem}
.export-option:hover{border-color:#10b981;background:#f0fdf4}.export-option.selected{border-color:#059669;background:#ecfdf5}
.export-option input[type="checkbox"]{width:18px;height:18px;accent-color:#059669}
.section-page{display:none}.section-page.active{display:block}
.checkbox-wrapper{display:flex;align-items:center;gap:.5rem}.checkbox-wrapper input[type="checkbox"]{width:18px;height:18px;accent-color:#059669}
</style>
<link rel="stylesheet" href="/admin/css/mobile.css">
</head>
<body>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
    <div class="flex items-center gap-3 mb-6"><div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center"><i class="fa-solid fa-layer-group text-xl"></i></div><div><div class="font-bold">Groups / &#4635;&#4613;&#4704;&#4651;&#4725;</div><div class="text-xs text-emerald-200">&#4840;&#4635;&#4613;&#4704;&#4651;&#4725; &#4768;&#4661;&#4720;&#4851;&#4848;&#4653;</div></div></div>
    <nav class="flex-1 space-y-1">
        <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
        <div class="border-t border-white/20 my-3"></div>
        <button class="nav-link active" id="navGroups" onclick="showPage('groups')"><i class="fa-solid fa-list"></i> All Groups</button>
        <button class="nav-link" id="navAnalytics" onclick="showPage('analytics')"><i class="fa-solid fa-chart-pie"></i> Analytics</button>
        <button class="nav-link" onclick="openGroupModal()"><i class="fa-solid fa-plus"></i> Register New Group</button>
        <div class="border-t border-white/20 my-3"></div>
        <button class="nav-link" onclick="exportAllGroups()"><i class="fa-solid fa-file-excel"></i> Export to Excel</button>
    </nav>
    <div class="mt-auto pt-4 border-t border-white/20"><div class="flex items-center gap-2 text-sm"><div class="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center font-bold"><?=strtoupper(substr($fullName,0,1))?></div><div><div class="font-medium truncate"><?=htmlspecialchars($fullName)?></div><div class="text-xs text-emerald-200"><?=ucfirst(str_replace('_',' ',$userRole))?></div></div></div></div>
</aside>

<div class="main">
<header class="topbar">
    <div class="flex items-center gap-3">
        <button class="hamburger" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
        <div><h1 class="text-lg font-bold text-slate-800">Groups & Associations</h1><p class="text-sm text-slate-500"><?=$todayFormatted?></p></div>
    </div>
    <div class="flex items-center gap-2">
        <button class="btn btn-secondary btn-sm" onclick="showPage('analytics')"><i class="fa-solid fa-chart-pie"></i> <span class="hidden sm:inline">Analytics</span></button>
        <button class="btn btn-primary" onclick="openGroupModal()"><i class="fa-solid fa-plus"></i> <span class="hidden sm:inline">New Group</span></button>
    </div>
</header>
<div class="p-4 md:p-6">
    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="stat-card"><div class="stat-icon bg-emerald-100 text-emerald-600"><i class="fa-solid fa-layer-group"></i></div><div><div class="stat-value" id="statTotal"><?=$stats['total_groups']?></div><div class="stat-label">Total Groups</div></div></div>
        <div class="stat-card"><div class="stat-icon bg-blue-100 text-blue-600"><i class="fa-solid fa-church"></i></div><div><div class="stat-value" id="statSS"><?=$stats['under_ss']?></div><div class="stat-label">Sunday School</div></div></div>
        <div class="stat-card"><div class="stat-icon bg-purple-100 text-purple-600"><i class="fa-solid fa-building-columns"></i></div><div><div class="stat-value" id="statParish"><?=$stats['parish']?></div><div class="stat-label">Parish</div></div></div>
        <div class="stat-card"><div class="stat-icon bg-amber-100 text-amber-600"><i class="fa-solid fa-user-tie"></i></div><div><div class="stat-value" id="statLeaders"><?=$stats['total_leaders']?></div><div class="stat-label">Leaders</div></div></div>
        <div class="stat-card"><div class="stat-icon bg-rose-100 text-rose-600"><i class="fa-solid fa-users"></i></div><div><div class="stat-value" id="statMembers"><?=$stats['total_members']?></div><div class="stat-label">Members</div></div></div>
    </div>

    <!-- GROUPS LIST PAGE -->
    <div class="section-page active" id="pageGroups">
        <div class="card">
            <div class="card-header">
                <h3 class="font-semibold text-slate-800 flex items-center gap-2"><i class="fa-solid fa-layer-group text-emerald-600"></i> Registered Groups</h3>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="search-box"><i class="fa-solid fa-search"></i><input type="text" id="searchInput" placeholder="Search groups..." onkeyup="debounceSearch()"></div>
                    <div class="tabs">
                        <button class="tab-btn active" onclick="filterCat('all',this)">All</button>
                        <button class="tab-btn" onclick="filterCat('ss',this)">Sunday School</button>
                        <button class="tab-btn" onclick="filterCat('parish',this)">Parish</button>
                    </div>
                </div>
            </div>
            <div class="table-container">
                <table><thead><tr><th>#</th><th>Group Name</th><th>Category</th><th class="hide-mobile">Est. Year</th><th class="hide-mobile">Initial (M/F)</th><th>Current (M/F)</th><th>Leaders</th><th>Members</th><th>Actions</th></tr></thead>
                <tbody id="groupsBody"><tr><td colspan="9"><div class="text-center py-8 text-slate-400"><i class="fa-solid fa-spinner fa-spin text-2xl"></i><p class="mt-2">Loading...</p></div></td></tr></tbody></table>
            </div>
            <div class="pagination" id="pagination"></div>
        </div>
    </div>

    <!-- ANALYTICS PAGE -->
    <div class="section-page" id="pageAnalytics">
        <div class="card"><div class="card-header"><h3 class="font-semibold text-slate-800 flex items-center gap-2"><i class="fa-solid fa-chart-pie text-emerald-600"></i> Groups Analytics</h3><button class="btn btn-sm btn-secondary" onclick="loadAnalytics()"><i class="fa-solid fa-refresh"></i> Refresh</button></div>
        <div class="card-body" id="analyticsContent"><div class="text-center py-8 text-slate-400"><i class="fa-solid fa-spinner fa-spin text-2xl"></i><p class="mt-2">Loading analytics...</p></div></div></div>
    </div>
</div>
</div>

<div class="toast-container" id="toastContainer"></div>

<!-- GROUP MODAL -->
<div id="groupModal" class="modal"><div class="modal-content">
    <div class="modal-header"><h3 id="groupModalTitle" class="font-bold text-lg">Register New Group</h3><button onclick="closeModal('groupModal')" class="action-btn"><i class="fa-solid fa-times"></i></button></div>
    <form id="groupForm" onsubmit="saveGroup(event)"><div class="modal-body">
        <input type="hidden" name="id" id="gId"><input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
        <div class="form-group"><label class="form-label">Group Name (Amharic) *</label><input type="text" name="group_name" id="gName" class="form-input" required placeholder="e.g. &#4677;&#4849;&#4661; &#4634;&#4659;&#4772;&#4621; &#4635;&#4613;&#4704;&#4653;"></div>
        <div class="form-group"><label class="form-label">Group Name (English)</label><input type="text" name="group_name_en" id="gNameEn" class="form-input" placeholder="e.g. St. Michael Association"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Year Est. (E.C.)</label><input type="text" name="established_year" id="gEstYear" class="form-input" placeholder="2010"></div>
            <div class="form-group"><label class="form-label">Year Est. (G.C.)</label><input type="text" name="established_year_gc" id="gEstYearGc" class="form-input" placeholder="2018"></div>
        </div>
        <div class="form-group"><div class="checkbox-wrapper mt-2"><input type="checkbox" name="is_under_sunday_school" id="gUnderSS" checked><span class="text-sm">Under Sunday School</span></div></div>
        <div class="form-section"><div class="form-section-title"><i class="fa-solid fa-users"></i> Initial Membership</div>
            <div class="form-row"><div class="form-group mb-0"><label class="form-label">Male</label><input type="number" name="founding_male" id="gFM" class="form-input" value="0" min="0"></div><div class="form-group mb-0"><label class="form-label">Female</label><input type="number" name="founding_female" id="gFF" class="form-input" value="0" min="0"></div></div>
        </div>
        <div class="form-section" style="background:#ecfdf5;border-color:#6ee7b7"><div class="form-section-title" style="color:#047857"><i class="fa-solid fa-chart-line"></i> Current Membership</div>
            <div class="form-row"><div class="form-group mb-0"><label class="form-label">Male</label><input type="number" name="current_male" id="gCM" class="form-input" value="0" min="0"></div><div class="form-group mb-0"><label class="form-label">Female</label><input type="number" name="current_female" id="gCF" class="form-input" value="0" min="0"></div></div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="gDesc" class="form-textarea" rows="2"></textarea></div>
        <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="gNotes" class="form-textarea" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" onclick="closeModal('groupModal')" class="btn btn-outline">Cancel</button><button type="submit" class="btn btn-primary" id="saveGroupBtn"><i class="fa-solid fa-spinner fa-spin spinner"></i><span class="btn-text"><i class="fa-solid fa-save"></i> Save Group</span></button></div>
    </form>
</div></div>

<!-- VIEW GROUP MODAL -->
<div id="viewModal" class="modal"><div class="modal-content xl">
    <div class="modal-header"><h3 id="viewTitle" class="font-bold text-lg">Group Details</h3>
    <div class="flex items-center gap-2"><button onclick="openPdfModal()" class="btn btn-sm btn-secondary"><i class="fa-solid fa-file-pdf text-red-500"></i> PDF</button><button onclick="closeModal('viewModal')" class="action-btn"><i class="fa-solid fa-times"></i></button></div></div>
    <div class="modal-body" id="viewContent"><div class="text-center py-8"><i class="fa-solid fa-spinner fa-spin text-2xl text-slate-400"></i></div></div>
</div></div>

<!-- MEMBER MODAL (Stepper) -->
<div id="memberModal" class="modal"><div class="modal-content">
    <div class="modal-header"><h3 id="memberModalTitle" class="font-bold text-lg"><i class="fa-solid fa-user-plus text-emerald-600 mr-2"></i>Add Member</h3><button onclick="closeModal('memberModal')" class="action-btn"><i class="fa-solid fa-times"></i></button></div>
    <form id="memberForm" onsubmit="saveMember(event)"><div class="modal-body">
        <input type="hidden" name="id" id="mId"><input type="hidden" name="group_id" id="mGid"><input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
        <div class="stepper">
            <div class="step active" id="s1"><div class="step-num">1</div><div class="step-label">Basic</div></div><div class="step-line" id="sl1"></div>
            <div class="step" id="s2"><div class="step-num">2</div><div class="step-label">Contact</div></div><div class="step-line" id="sl2"></div>
            <div class="step" id="s3"><div class="step-num">3</div><div class="step-label">Details</div></div>
        </div>
        <!-- Step 1 -->
        <div class="step-content active" id="sc1"><div class="form-section"><div class="form-section-title"><i class="fa-solid fa-user"></i> Personal Information</div>
            <div class="form-row"><div class="form-group"><label class="form-label">Full Name (Amharic) *</label><input type="text" name="full_name" id="mName" class="form-input" required></div><div class="form-group"><label class="form-label">Full Name (English)</label><input type="text" name="full_name_en" id="mNameEn" class="form-input"></div></div>
            <div class="form-row"><div class="form-group"><label class="form-label">Baptismal Name</label><input type="text" name="baptismal_name" id="mBaptismal" class="form-input"></div><div class="form-group"><label class="form-label">Gender *</label><select name="gender" id="mGender" class="form-select"><option value="M">Male</option><option value="F">Female</option></select></div></div>
            <div class="form-row"><div class="form-group"><label class="form-label">Date of Birth</label><input type="date" name="date_of_birth" id="mDob" class="form-input"></div><div class="form-group"><label class="form-label">Joined Date</label><input type="date" name="joined_date" id="mJoined" class="form-input"></div></div>
        </div></div>
        <!-- Step 2 -->
        <div class="step-content" id="sc2"><div class="form-section"><div class="form-section-title"><i class="fa-solid fa-phone"></i> Contact & Address</div>
            <div class="form-row"><div class="form-group"><label class="form-label">Phone</label><input type="tel" name="phone" id="mPhone" class="form-input" placeholder="09..."></div><div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="mEmail" class="form-input"></div></div>
            <div class="form-row"><div class="form-group"><label class="form-label">City</label><input type="text" name="city" id="mCity" class="form-input" value="Addis Ababa"></div><div class="form-group"><label class="form-label">Sub City</label><input type="text" name="sub_city" id="mSubCity" class="form-input"></div></div>
            <div class="form-row"><div class="form-group"><label class="form-label">Woreda</label><input type="text" name="woreda" id="mWoreda" class="form-input"></div><div class="form-group"><label class="form-label">House No.</label><input type="text" name="house_number" id="mHouse" class="form-input"></div></div>
        </div></div>
        <!-- Step 3 -->
        <div class="step-content" id="sc3"><div class="form-section"><div class="form-section-title"><i class="fa-solid fa-graduation-cap"></i> Additional Details</div>
            <div class="form-row"><div class="form-group"><label class="form-label">Education</label><select name="education_level" id="mEdu" class="form-select"><option value="">Select...</option><option>Elementary</option><option>High School</option><option>Diploma</option><option>Degree</option><option>Masters</option><option>PhD</option></select></div><div class="form-group"><label class="form-label">Occupation</label><input type="text" name="occupation" id="mOccupation" class="form-input"></div></div>
            <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="mNotes" class="form-textarea" rows="3"></textarea></div>
        </div></div>
    </div>
    <div class="modal-footer">
        <button type="button" onclick="prevStep()" class="btn btn-outline" id="prevBtn" style="display:none"><i class="fa-solid fa-arrow-left"></i> Back</button><div style="flex:1"></div>
        <button type="button" onclick="closeModal('memberModal')" class="btn btn-outline">Cancel</button>
        <button type="button" onclick="nextStep()" class="btn btn-primary" id="nextBtn">Next <i class="fa-solid fa-arrow-right"></i></button>
        <button type="submit" class="btn btn-primary" id="saveMemberBtn" style="display:none"><i class="fa-solid fa-spinner fa-spin spinner"></i><span class="btn-text"><i class="fa-solid fa-save"></i> Save Member</span></button>
    </div></form>
</div></div>

<!-- LEADER MODAL -->
<div id="leaderModal" class="modal"><div class="modal-content">
    <div class="modal-header"><h3 id="leaderModalTitle" class="font-bold text-lg">Add Leader</h3><button onclick="closeModal('leaderModal')" class="action-btn"><i class="fa-solid fa-times"></i></button></div>
    <form id="leaderForm" onsubmit="saveLeader(event)"><div class="modal-body">
        <input type="hidden" name="id" id="lId"><input type="hidden" name="group_id" id="lGid"><input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
        <div class="form-row"><div class="form-group"><label class="form-label">Full Name (Amharic) *</label><input type="text" name="leader_full_name" id="lName" class="form-input" required></div><div class="form-group"><label class="form-label">Full Name (English)</label><input type="text" name="leader_full_name_en" id="lNameEn" class="form-input"></div></div>
        <div class="form-row"><div class="form-group"><label class="form-label">Gender *</label><select name="sex" id="lSex" class="form-select"><option value="M">Male</option><option value="F">Female</option></select></div><div class="form-group"><label class="form-label">Phone</label><input type="tel" name="phone" id="lPhone" class="form-input"></div></div>
        <div class="form-row"><div class="form-group"><label class="form-label">Education</label><select name="education_level" id="lEdu" class="form-select"><option value="">Select</option><option>Elementary</option><option>High School</option><option>Diploma</option><option>Degree</option><option>Masters</option></select></div><div class="form-group"><label class="form-label">Responsibility</label><input type="text" name="responsibility" id="lResp" class="form-input"></div></div>
        <div class="form-group"><label class="form-label">Remarks</label><textarea name="remark" id="lRemark" class="form-textarea" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" onclick="closeModal('leaderModal')" class="btn btn-outline">Cancel</button><button type="submit" class="btn btn-primary" id="saveLeaderBtn"><i class="fa-solid fa-spinner fa-spin spinner"></i><span class="btn-text"><i class="fa-solid fa-save"></i> Save</span></button></div>
    </form>
</div></div>

<!-- PDF EXPORT MODAL -->
<div id="pdfModal" class="modal"><div class="modal-content" style="max-width:480px">
    <div class="modal-header"><h3 class="font-bold text-lg"><i class="fa-solid fa-file-pdf text-red-500 mr-2"></i>Export PDF</h3><button onclick="closeModal('pdfModal')" class="action-btn"><i class="fa-solid fa-times"></i></button></div>
    <div class="modal-body">
        <p class="text-sm text-slate-500 mb-4">Select what to include:</p>
        <div class="space-y-3">
            <label class="export-option selected"><input type="checkbox" id="pdfInfo" checked><div><div class="font-medium text-sm">Group Information</div><div class="text-xs text-slate-400">Name, category, membership counts</div></div></label>
            <label class="export-option selected"><input type="checkbox" id="pdfLeaders" checked><div><div class="font-medium text-sm">Leaders List</div><div class="text-xs text-slate-400">Names, responsibilities, phones</div></div></label>
            <label class="export-option selected"><input type="checkbox" id="pdfMembers" checked><div><div class="font-medium text-sm">Members List</div><div class="text-xs text-slate-400">Full member directory</div></div></label>
            <label class="export-option"><input type="checkbox" id="pdfStats"><div><div class="font-medium text-sm">Statistics Summary</div><div class="text-xs text-slate-400">Gender breakdown, growth</div></div></label>
        </div>
    </div>
    <div class="modal-footer"><button onclick="closeModal('pdfModal')" class="btn btn-outline">Cancel</button><button onclick="generatePdf()" class="btn btn-primary"><i class="fa-solid fa-download"></i> Generate PDF</button></div>
</div></div>

<!-- CONFIRM MODAL -->
<div id="confirmModal" class="modal"><div class="modal-content" style="max-width:400px">
    <div class="modal-header"><h3 class="font-bold text-lg text-red-600"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Confirm</h3><button onclick="closeModal('confirmModal')" class="action-btn"><i class="fa-solid fa-times"></i></button></div>
    <div class="modal-body"><p id="confirmMsg" class="text-slate-600"></p></div>
    <div class="modal-footer"><button onclick="closeModal('confirmModal')" class="btn btn-outline">Cancel</button><button id="confirmBtn" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete</button></div>
</div></div>

<script>
const API='backend/groups_api.php',CSRF='<?=$csrfToken?>';
let curGid=null,curPage=1,curFilter='all',searchTO=null,curStep=1;

function esc(t){if(!t)return'';const d=document.createElement('div');d.textContent=t;return d.innerHTML}
function toast(m,t='success'){const c=document.getElementById('toastContainer'),e=document.createElement('div');e.className='toast toast-'+t;const i={success:'fa-check-circle',error:'fa-times-circle',info:'fa-info-circle'};e.innerHTML='<i class="fa-solid '+i[t]+'"></i> '+esc(m);c.appendChild(e);setTimeout(()=>{e.style.opacity='0';setTimeout(()=>e.remove(),300)},4000)}
function setLoading(b,l){if(l){b.classList.add('loading');b.disabled=true}else{b.classList.remove('loading');b.disabled=false}}
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.querySelector('.sidebar-overlay').classList.toggle('active')}
function debounceSearch(){clearTimeout(searchTO);searchTO=setTimeout(()=>{curPage=1;loadGroups()},300)}
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
function showPage(p){document.querySelectorAll('.section-page').forEach(e=>e.classList.remove('active'));document.querySelectorAll('.nav-link').forEach(e=>e.classList.remove('active'));if(p==='analytics'){document.getElementById('pageAnalytics').classList.add('active');document.getElementById('navAnalytics').classList.add('active');loadAnalytics()}else{document.getElementById('pageGroups').classList.add('active');document.getElementById('navGroups').classList.add('active')}}

async function api(action,method='GET',data=null){
    try{let url=API+'?action='+action;let opts={method};
        if(method==='POST'&&data){
            if(data instanceof FormData){
                // Only add csrf_token if not already in the FormData (prevents duplicate)
                if(!data.has('csrf_token'))data.append('csrf_token',CSRF);
                opts.body=data;
            }else{
                const fd=new FormData();
                for(const k in data)fd.append(k,data[k]);
                if(!fd.has('csrf_token'))fd.append('csrf_token',CSRF);
                opts.body=fd;
            }
        }
        else if(method==='GET'&&data){url+='&'+new URLSearchParams(data).toString()}
        const r=await fetch(url,opts);
        const text=await r.text();
        // Debug: log non-JSON responses
        let j;
        try{j=JSON.parse(text)}catch(e){
            console.error('API non-JSON response for action='+action+':',text.substring(0,500));
            // Check if it's a PHP error/warning mixed in
            if(text.includes('<!DOCTYPE')||text.includes('<br')||text.includes('Fatal error')){
                toast('Server PHP error. Check server logs.','error');
            }else if(text.includes('Security token')||text.includes('csrf')){
                toast('Session expired. Please refresh the page.','error');
            }else{
                toast('Server error for: '+action,'error');
            }
            return{success:false,rawResponse:text.substring(0,200)}
        }
        if(!j.success&&j.message)toast(j.message,'error');
        return j;
    }catch(e){console.error('API network error:',e);toast('Network error: '+e.message,'error');return{success:false}}
}

// ===== GROUPS =====
async function loadGroups(){const s=document.getElementById('searchInput').value;const r=await api('list_groups','GET',{page:curPage,limit:20,search:s,category:curFilter});if(r.success){renderGroups(r.data);renderPag(r.meta)}}
function renderGroups(gs){const tb=document.getElementById('groupsBody');if(!gs||!gs.length){tb.innerHTML='<tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No groups found</p></div></td></tr>';return}
tb.innerHTML=gs.map((g,i)=>'<tr><td>'+(((curPage-1)*20)+i+1)+'</td><td><div class="font-medium">'+esc(g.group_name)+'</div>'+(g.group_name_en?'<div class="text-xs text-slate-400">'+esc(g.group_name_en)+'</div>':'')+'</td><td>'+(g.is_under_sunday_school==1?'<span class="badge badge-green"><i class="fa-solid fa-church"></i> SS</span>':'<span class="badge badge-purple"><i class="fa-solid fa-building-columns"></i> Parish</span>')+'</td><td class="hide-mobile">'+(esc(g.established_year)||'\u2014')+'</td><td class="hide-mobile">'+(g.founding_male||0)+' / '+(g.founding_female||0)+'</td><td><strong>'+(g.current_male||0)+'</strong> / <strong>'+(g.current_female||0)+'</strong></td><td><span class="badge badge-blue">'+(g.leader_count||0)+'</span></td><td><span class="badge badge-green">'+(g.member_count||0)+'</span></td><td><div class="flex gap-1"><button class="action-btn view" onclick="viewGroup('+g.id+')" title="View"><i class="fa-solid fa-eye"></i></button><button class="action-btn edit" onclick="editGroup('+g.id+')" title="Edit"><i class="fa-solid fa-pen"></i></button><button class="action-btn danger" onclick="confirmDel('+g.id+',\''+esc(g.group_name).replace(/'/g,"")+'\')"><i class="fa-solid fa-trash"></i></button></div></td></tr>').join('')}
function renderPag(m){if(!m||m.pages<=1){document.getElementById('pagination').innerHTML='';return}let h='<button '+(m.page<=1?'disabled':'')+' onclick="goPage('+(m.page-1)+')"><i class="fa-solid fa-chevron-left"></i></button>';for(let i=1;i<=Math.min(m.pages,7);i++)h+='<button class="'+(i===m.page?'active':'')+'" onclick="goPage('+i+')">'+i+'</button>';h+='<button '+(m.page>=m.pages?'disabled':'')+' onclick="goPage('+(m.page+1)+')"><i class="fa-solid fa-chevron-right"></i></button>';document.getElementById('pagination').innerHTML=h}
function goPage(p){curPage=p;loadGroups()}
function filterCat(c,btn){curFilter=c;curPage=1;document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));btn.classList.add('active');loadGroups()}

function openGroupModal(){document.getElementById('groupForm').reset();document.getElementById('gId').value='';document.getElementById('groupModalTitle').textContent='Register New Group';document.getElementById('gUnderSS').checked=true;openModal('groupModal')}
async function editGroup(id){const r=await api('get_group','GET',{id});if(r.success){const g=r.data;document.getElementById('gId').value=g.id;document.getElementById('gName').value=g.group_name||'';document.getElementById('gNameEn').value=g.group_name_en||'';document.getElementById('gEstYear').value=g.established_year||'';document.getElementById('gEstYearGc').value=g.established_year_gc||'';document.getElementById('gUnderSS').checked=g.is_under_sunday_school==1;document.getElementById('gFM').value=g.founding_male||0;document.getElementById('gFF').value=g.founding_female||0;document.getElementById('gCM').value=g.current_male||0;document.getElementById('gCF').value=g.current_female||0;document.getElementById('gDesc').value=g.description||'';document.getElementById('gNotes').value=g.notes||'';document.getElementById('groupModalTitle').textContent='Edit Group';openModal('groupModal')}}
async function saveGroup(e){e.preventDefault();const btn=document.getElementById('saveGroupBtn');setLoading(btn,true);const fd=new FormData(document.getElementById('groupForm'));if(!document.getElementById('gUnderSS').checked)fd.delete('is_under_sunday_school');const r=await api('save_group','POST',fd);setLoading(btn,false);if(r.success){toast(r.message);closeModal('groupModal');loadGroups();refreshStats()}}
function confirmDel(id,name){document.getElementById('confirmMsg').textContent='Delete "'+name+'"? This removes all leaders and members.';document.getElementById('confirmBtn').onclick=()=>delGroup(id);openModal('confirmModal')}
async function delGroup(id){closeModal('confirmModal');const r=await api('delete_group','POST',{id});if(r.success){toast(r.message);loadGroups();refreshStats()}}

// ===== VIEW GROUP =====
async function viewGroup(id){
    curGid=id;openModal('viewModal');document.getElementById('viewContent').innerHTML='<div class="text-center py-8"><i class="fa-solid fa-spinner fa-spin text-2xl text-slate-400"></i></div>';
    const[gR,lR,mR]=await Promise.all([api('get_group','GET',{id}),api('list_leaders','GET',{group_id:id}),api('list_members','GET',{group_id:id,status:'all'})]);
    if(!gR.success){document.getElementById('viewContent').innerHTML='<p class="text-center text-red-500">Failed to load</p>';return}
    const g=gR.data,ls=lR.success?lR.data:[],ms=mR.success?mR.data:[];
    const tm=ms.filter(m=>m.gender==='M').length,tf=ms.filter(m=>m.gender==='F').length;
    document.getElementById('viewTitle').textContent=g.group_name;
    let h='<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">';
    h+='<div class="p-3 bg-slate-50 rounded-lg"><div class="text-xs text-slate-500">Category</div><div class="font-semibold">'+(g.is_under_sunday_school==1?'Sunday School':'Parish')+'</div></div>';
    h+='<div class="p-3 bg-slate-50 rounded-lg"><div class="text-xs text-slate-500">Est. Year</div><div class="font-semibold">'+(esc(g.established_year)||'\u2014')+'</div></div>';
    h+='<div class="p-3 bg-slate-50 rounded-lg"><div class="text-xs text-slate-500">Initial (M/F)</div><div class="font-semibold">'+(g.founding_male||0)+' / '+(g.founding_female||0)+'</div></div>';
    h+='<div class="p-3 bg-emerald-50 rounded-lg border border-emerald-200"><div class="text-xs text-emerald-600">Current (M/F)</div><div class="font-semibold text-emerald-700">'+(g.current_male||0)+' / '+(g.current_female||0)+'</div></div></div>';
    // Leaders
    h+='<div class="mb-6"><div class="flex items-center justify-between mb-3"><h4 class="font-semibold text-slate-700"><i class="fa-solid fa-user-tie text-amber-500 mr-2"></i>Leaders ('+ls.length+')</h4><button onclick="openLeaderModal('+id+')" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i> Add Leader</button></div>';
    h+='<div class="table-container"><table class="text-sm"><thead><tr><th>Name</th><th>Gender</th><th>Phone</th><th>Role</th><th>Actions</th></tr></thead><tbody>';
    if(ls.length===0)h+='<tr><td colspan="5" class="text-center text-slate-400 py-4">No leaders yet</td></tr>';
    else ls.forEach(l=>{h+='<tr><td class="font-medium">'+esc(l.leader_full_name)+'</td><td>'+l.sex+'</td><td>'+(esc(l.phone)||'\u2014')+'</td><td><span class="badge badge-amber">'+(esc(l.responsibility)||'\u2014')+'</span></td><td><button class="action-btn edit" onclick="editLeader('+l.id+')"><i class="fa-solid fa-pen"></i></button> <button class="action-btn danger" onclick="deleteLeader('+l.id+')"><i class="fa-solid fa-trash"></i></button></td></tr>'});
    h+='</tbody></table></div></div>';
    // Members
    h+='<div><div class="flex items-center justify-between mb-3"><h4 class="font-semibold text-slate-700"><i class="fa-solid fa-users text-emerald-500 mr-2"></i>Members ('+ms.length+')</h4><div class="flex gap-2"><span class="badge badge-blue"><i class="fa-solid fa-mars"></i> '+tm+' Male</span><span class="badge badge-purple"><i class="fa-solid fa-venus"></i> '+tf+' Female</span><button onclick="openMemberModal('+id+')" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i> Add Member</button></div></div>';
    h+='<div class="table-container"><table class="text-sm"><thead><tr><th>#</th><th>Name</th><th>Baptismal</th><th>Gender</th><th>Phone</th><th class="hide-mobile">City</th><th>Actions</th></tr></thead><tbody>';
    if(ms.length===0)h+='<tr><td colspan="7" class="text-center text-slate-400 py-4">No members yet</td></tr>';
    else ms.forEach((m,i)=>{h+='<tr><td>'+(i+1)+'</td><td class="font-medium">'+esc(m.full_name)+'</td><td>'+(esc(m.baptismal_name)||'\u2014')+'</td><td>'+m.gender+'</td><td>'+(esc(m.phone)||'\u2014')+'</td><td class="hide-mobile">'+(esc(m.city)||'\u2014')+'</td><td><button class="action-btn edit" onclick="editMember('+m.id+')"><i class="fa-solid fa-pen"></i></button> <button class="action-btn danger" onclick="deleteMember('+m.id+')"><i class="fa-solid fa-trash"></i></button></td></tr>'});
    h+='</tbody></table></div></div>';
    document.getElementById('viewContent').innerHTML=h;
}

// ===== STEPPER =====
function resetStep(){curStep=1;for(let i=1;i<=3;i++){document.getElementById('s'+i).className=i===1?'step active':'step';document.getElementById('sc'+i).className=i===1?'step-content active':'step-content';if(i<3)document.getElementById('sl'+i).className='step-line'}document.getElementById('prevBtn').style.display='none';document.getElementById('nextBtn').style.display='';document.getElementById('saveMemberBtn').style.display='none'}
function nextStep(){if(curStep===1&&!document.getElementById('mName').value.trim()){toast('Full name is required','error');document.getElementById('mName').focus();return}if(curStep<3){document.getElementById('s'+curStep).classList.replace('active','done');document.getElementById('sl'+curStep).classList.add('active');document.getElementById('sc'+curStep).classList.remove('active');curStep++;document.getElementById('s'+curStep).classList.add('active');document.getElementById('sc'+curStep).classList.add('active')}if(curStep>1)document.getElementById('prevBtn').style.display='';if(curStep===3){document.getElementById('nextBtn').style.display='none';document.getElementById('saveMemberBtn').style.display=''}}
function prevStep(){if(curStep>1){document.getElementById('s'+curStep).classList.remove('active');document.getElementById('sc'+curStep).classList.remove('active');curStep--;document.getElementById('s'+curStep).className='step active';document.getElementById('sl'+curStep).classList.remove('active');document.getElementById('sc'+curStep).classList.add('active')}if(curStep===1)document.getElementById('prevBtn').style.display='none';document.getElementById('nextBtn').style.display='';document.getElementById('saveMemberBtn').style.display='none'}

// ===== MEMBERS =====
function openMemberModal(gid){document.getElementById('memberForm').reset();document.getElementById('mId').value='';document.getElementById('mGid').value=gid;document.getElementById('memberModalTitle').innerHTML='<i class="fa-solid fa-user-plus text-emerald-600 mr-2"></i>Add Member';document.getElementById('mCity').value='Addis Ababa';resetStep();openModal('memberModal')}
async function editMember(id){const r=await api('get_member','GET',{id});if(!r.success)return;const m=r.data;document.getElementById('mId').value=m.id;document.getElementById('mGid').value=m.group_id;document.getElementById('mName').value=m.full_name||'';document.getElementById('mNameEn').value=m.full_name_en||'';document.getElementById('mBaptismal').value=m.baptismal_name||'';document.getElementById('mGender').value=m.gender||'M';document.getElementById('mDob').value=m.date_of_birth||'';document.getElementById('mJoined').value=m.joined_date||'';document.getElementById('mPhone').value=m.phone||'';document.getElementById('mEmail').value=m.email||'';document.getElementById('mCity').value=m.city||'';document.getElementById('mSubCity').value=m.sub_city||'';document.getElementById('mWoreda').value=m.woreda||'';document.getElementById('mHouse').value=m.house_number||'';document.getElementById('mEdu').value=m.education_level||'';document.getElementById('mOccupation').value=m.occupation||'';document.getElementById('mNotes').value=m.notes||'';document.getElementById('memberModalTitle').innerHTML='<i class="fa-solid fa-user-pen text-blue-600 mr-2"></i>Edit Member';resetStep();openModal('memberModal')}
async function saveMember(e){e.preventDefault();const btn=document.getElementById('saveMemberBtn');
    // Validate required fields before sending
    const name=document.getElementById('mName').value.trim();
    const gid=document.getElementById('mGid').value;
    if(!name){toast('Full name is required','error');resetStep();document.getElementById('mName').focus();return}
    if(!gid){toast('Group ID is missing. Please close and reopen.','error');return}
    setLoading(btn,true);
    const fd=new FormData(document.getElementById('memberForm'));
    // Debug: log what we're sending
    console.log('Saving member - group_id:',fd.get('group_id'),'name:',fd.get('full_name'),'csrf:',fd.get('csrf_token')?'present':'MISSING');
    const r=await api('save_member','POST',fd);
    setLoading(btn,false);
    if(r.success){toast(r.message||'Member saved!');closeModal('memberModal');viewGroup(curGid);refreshStats()}
    else{console.error('Save member failed:',r)}}
async function deleteMember(id){if(!confirm('Remove this member?'))return;const r=await api('delete_member','POST',{id});if(r.success){toast(r.message);viewGroup(curGid);refreshStats()}}

// ===== LEADERS =====
function openLeaderModal(gid){document.getElementById('leaderForm').reset();document.getElementById('lId').value='';document.getElementById('lGid').value=gid;document.getElementById('leaderModalTitle').textContent='Add Leader';openModal('leaderModal')}
async function editLeader(id){const r=await api('get_leader','GET',{id});if(r.success){const l=r.data;document.getElementById('lId').value=l.id;document.getElementById('lGid').value=l.group_id;document.getElementById('lName').value=l.leader_full_name||'';document.getElementById('lNameEn').value=l.leader_full_name_en||'';document.getElementById('lSex').value=l.sex||'M';document.getElementById('lPhone').value=l.phone||'';document.getElementById('lEdu').value=l.education_level||'';document.getElementById('lResp').value=l.responsibility||'';document.getElementById('lRemark').value=l.remark||'';document.getElementById('leaderModalTitle').textContent='Edit Leader';openModal('leaderModal')}}
async function saveLeader(e){e.preventDefault();const btn=document.getElementById('saveLeaderBtn');
    if(!document.getElementById('lName').value.trim()){toast('Leader name is required','error');return}
    if(!document.getElementById('lGid').value){toast('Group ID missing. Close and reopen.','error');return}
    setLoading(btn,true);const fd=new FormData(document.getElementById('leaderForm'));const r=await api('save_leader','POST',fd);setLoading(btn,false);if(r.success){toast(r.message||'Leader saved!');closeModal('leaderModal');viewGroup(curGid);refreshStats()}}
async function deleteLeader(id){if(!confirm('Remove this leader?'))return;const r=await api('delete_leader','POST',{id});if(r.success){toast(r.message);viewGroup(curGid);refreshStats()}}

// ===== STATS =====
async function refreshStats(){const r=await api('get_stats');if(r.success){document.getElementById('statTotal').textContent=r.data.total_groups||0;document.getElementById('statSS').textContent=r.data.under_ss||0;document.getElementById('statParish').textContent=r.data.parish||0;document.getElementById('statLeaders').textContent=r.data.total_leaders||0;document.getElementById('statMembers').textContent=r.data.total_members||0}}

// ===== ANALYTICS =====
async function loadAnalytics(){
    const c=document.getElementById('analyticsContent');c.innerHTML='<div class="text-center py-8"><i class="fa-solid fa-spinner fa-spin text-2xl text-slate-400"></i></div>';
    const r=await api('get_analytics');if(!r.success){c.innerHTML='<p class="text-center text-red-500">Failed to load</p>';return}
    const d=r.data,gd=d.gender;const mP=gd.current_total>0?Math.round(gd.current_male/gd.current_total*100):0;const fP=100-mP;
    let bars='';d.groups.forEach(g=>{const max=Math.max(...d.groups.map(x=>(parseInt(x.current_male)||0)+(parseInt(x.current_female)||0)),1);const tot=(parseInt(g.current_male)||0)+(parseInt(g.current_female)||0);const mw=tot>0?Math.round((parseInt(g.current_male)||0)/tot*100):50;bars+='<div class="flex items-center gap-3 mb-3"><div class="w-32 text-xs text-slate-600 truncate font-medium">'+esc(g.group_name).substring(0,18)+'</div><div class="flex-1 bg-slate-100 rounded-lg overflow-hidden h-6 flex"><div class="bg-blue-400 h-full" style="width:'+mw+'%"></div><div class="bg-pink-400 h-full" style="width:'+(100-mw)+'%"></div></div><div class="text-xs text-slate-500 w-12 text-right">'+tot+'</div><div class="w-16">'+(g.growth_rate>0?'<span class="growth-tag growth-up"><i class="fa-solid fa-arrow-up"></i>'+g.growth_rate+'%</span>':g.growth_rate<0?'<span class="growth-tag growth-down"><i class="fa-solid fa-arrow-down"></i>'+Math.abs(g.growth_rate)+'%</span>':'<span class="text-xs text-slate-400">\u2014</span>')+'</div></div>'});
    let recent=d.recent_members.map(m=>'<div class="flex items-center gap-3 py-2 border-b border-slate-50"><div class="w-8 h-8 rounded-full '+(m.gender==='M'?'bg-blue-100 text-blue-600':'bg-pink-100 text-pink-600')+' flex items-center justify-center text-xs font-bold">'+m.gender+'</div><div class="flex-1"><div class="text-sm font-medium">'+esc(m.full_name)+'</div><div class="text-xs text-slate-400">'+esc(m.group_name)+'</div></div></div>').join('');
    c.innerHTML='<div class="analytics-grid"><div class="card"><div class="card-body"><h4 class="font-semibold text-sm mb-4"><i class="fa-solid fa-venus-mars text-purple-500 mr-2"></i>Gender Distribution</h4><div class="flex items-center gap-6 mb-4"><div class="text-center"><div class="text-3xl font-bold text-blue-600">'+gd.current_male+'</div><div class="text-xs text-slate-500">Male ('+mP+'%)</div></div><div class="text-center"><div class="text-3xl font-bold text-pink-500">'+gd.current_female+'</div><div class="text-xs text-slate-500">Female ('+fP+'%)</div></div><div class="text-center"><div class="text-3xl font-bold text-emerald-600">'+gd.current_total+'</div><div class="text-xs text-slate-500">Total</div></div></div><div class="flex rounded-lg overflow-hidden h-8 mb-2"><div class="bg-blue-400 flex items-center justify-center text-white text-xs font-bold" style="width:'+mP+'%">'+mP+'%</div><div class="bg-pink-400 flex items-center justify-center text-white text-xs font-bold" style="width:'+fP+'%">'+fP+'%</div></div></div></div><div class="card"><div class="card-body"><h4 class="font-semibold text-sm mb-4"><i class="fa-solid fa-chart-line text-emerald-500 mr-2"></i>Overall Growth</h4><div class="grid grid-cols-2 gap-4 mb-4"><div class="p-3 bg-slate-50 rounded-lg"><div class="text-xs text-slate-500">Founded With</div><div class="text-xl font-bold">'+gd.founding_total+'</div></div><div class="p-3 bg-emerald-50 rounded-lg"><div class="text-xs text-emerald-600">Current</div><div class="text-xl font-bold text-emerald-700">'+gd.current_total+'</div></div></div><div class="text-center p-4 rounded-xl '+(gd.growth>0?'bg-emerald-50':'bg-red-50')+'"><div class="text-4xl font-bold '+(gd.growth>0?'text-emerald-600':'text-red-600')+'">'+(gd.growth>0?'+':'')+gd.growth+'%</div><div class="text-sm text-slate-500 mt-1">Overall Growth</div></div></div></div></div><div class="card mt-6"><div class="card-body"><h4 class="font-semibold text-sm mb-4"><i class="fa-solid fa-chart-bar text-blue-500 mr-2"></i>By Group <span class="text-xs text-slate-400 font-normal ml-2"><i class="fa-solid fa-square text-blue-400"></i> Male <i class="fa-solid fa-square text-pink-400 ml-2"></i> Female</span></h4>'+(bars||'<p class="text-slate-400 text-center py-4">No data</p>')+'</div></div><div class="analytics-grid mt-6"><div class="card"><div class="card-body"><h4 class="font-semibold text-sm mb-4"><i class="fa-solid fa-trophy text-amber-500 mr-2"></i>Top 5 Largest</h4>'+d.top_groups.map((g,i)=>'<div class="flex items-center gap-3 py-2 '+(i<4?'border-b border-slate-50':'')+'"><div class="w-7 h-7 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xs font-bold">'+(i+1)+'</div><div class="flex-1 text-sm font-medium">'+esc(g.group_name)+'</div><div class="text-sm font-bold">'+((parseInt(g.current_male)||0)+(parseInt(g.current_female)||0))+'</div></div>').join('')+'</div></div><div class="card"><div class="card-body"><h4 class="font-semibold text-sm mb-4"><i class="fa-solid fa-clock-rotate-left text-cyan-500 mr-2"></i>Recent Members</h4>'+(recent||'<p class="text-center text-slate-400 py-4">No recent</p>')+'</div></div></div>';
}

// ===== PDF =====
function openPdfModal(){openModal('pdfModal');document.querySelectorAll('.export-option input').forEach(cb=>{cb.onchange=function(){this.closest('.export-option').classList.toggle('selected',this.checked)}})}
async function generatePdf(){
    const ii=document.getElementById('pdfInfo').checked,il=document.getElementById('pdfLeaders').checked,im=document.getElementById('pdfMembers').checked,is=document.getElementById('pdfStats').checked;
    if(!ii&&!il&&!im){toast('Select at least one section','error');return}
    toast('Generating PDF...','info');closeModal('pdfModal');
    const r=await api('export_group_pdf','GET',{group_id:curGid,include_members:im?'1':'0',include_leaders:il?'1':'0',include_stats:is?'1':'0'});
    if(!r.success)return;const{jsPDF}=window.jspdf;const doc=new jsPDF('p','mm','a4');const pw=210;let y=15;const g=r.data.group;
    doc.setFillColor(5,150,105);doc.rect(0,0,pw,35,'F');doc.setTextColor(255);doc.setFontSize(18);doc.text(SCHOOL_NAME_SHORT . ' ' . SCHOOL_TYPE,15,15);doc.setFontSize(10);doc.text('Groups Management Report',15,22);doc.setFontSize(8);doc.text('Generated: '+new Date().toLocaleDateString(),15,29);y=45;doc.setTextColor(0);
    if(ii){doc.setFontSize(14);doc.setFont(undefined,'bold');doc.text(g.group_name||'Group',15,y);y+=7;if(g.group_name_en){doc.setFontSize(10);doc.setFont(undefined,'normal');doc.text(g.group_name_en,15,y);y+=6}doc.setFontSize(9);doc.autoTable({startY:y,head:[['Field','Value']],body:[['Category',g.is_under_sunday_school==1?'Sunday School':'Parish'],['Est. Year',g.established_year||'N/A'],['Initial M/F',(g.founding_male||0)+' / '+(g.founding_female||0)],['Current M/F',(g.current_male||0)+' / '+(g.current_female||0)],['Total',''+(parseInt(g.current_male)||0)+(parseInt(g.current_female)||0)]],theme:'grid',headStyles:{fillColor:[5,150,105]},margin:{left:15,right:15},styles:{fontSize:9}});y=doc.lastAutoTable.finalY+10}
    if(il&&r.data.leaders&&r.data.leaders.length>0){if(y>250){doc.addPage();y=15}doc.setFontSize(12);doc.setFont(undefined,'bold');doc.text('Leaders ('+r.data.leaders.length+')',15,y);y+=5;doc.autoTable({startY:y,head:[['#','Name','Gender','Phone','Responsibility']],body:r.data.leaders.map((l,i)=>[i+1,l.leader_full_name||'',l.sex==='M'?'Male':'Female',l.phone||'',l.responsibility||'']),theme:'striped',headStyles:{fillColor:[217,119,6]},margin:{left:15,right:15},styles:{fontSize:8}});y=doc.lastAutoTable.finalY+10}
    if(im&&r.data.members&&r.data.members.length>0){if(y>250){doc.addPage();y=15}doc.setFontSize(12);doc.setFont(undefined,'bold');doc.text('Members ('+r.data.members.length+')',15,y);y+=5;doc.autoTable({startY:y,head:[['#','Full Name','Baptismal','Gender','Phone','City']],body:r.data.members.map((m,i)=>[i+1,m.full_name||'',m.baptismal_name||'',m.gender==='M'?'M':'F',m.phone||'',m.city||'']),theme:'striped',headStyles:{fillColor:[5,150,105]},margin:{left:15,right:15},styles:{fontSize:8}});y=doc.lastAutoTable.finalY+10}
    if(is&&r.data.member_stats){if(y>250){doc.addPage();y=15}const s=r.data.member_stats;doc.setFontSize(12);doc.setFont(undefined,'bold');doc.text('Statistics',15,y);y+=5;doc.autoTable({startY:y,head:[['Metric','Value']],body:[['Total',s.total],['Male',s.male],['Female',s.female],['Male %',s.total>0?Math.round(s.male/s.total*100)+'%':'0%'],['Female %',s.total>0?Math.round(s.female/s.total*100)+'%':'0%']],theme:'grid',headStyles:{fillColor:[99,102,241]},margin:{left:15,right:15},styles:{fontSize:9}})}
    const pc=doc.internal.getNumberOfPages();for(let i=1;i<=pc;i++){doc.setPage(i);doc.setFontSize(7);doc.setTextColor(150);doc.text((window.SCHOOL_SHORT||'School')+' Report - Page '+i+'/'+pc,pw/2,290,{align:'center'})}
    doc.save((typeof SCHOOL_NAME_SHORT !== 'undefined' ? SCHOOL_NAME_SHORT : 'School')+'_'+(g.group_name_en||g.group_name||'Group').replace(/[^a-zA-Z0-9]/g,'_')+'_Report.pdf');toast('PDF downloaded!')
}

// ===== EXCEL =====
async function exportAllGroups(){toast('Preparing...','info');const r=await api('export_all_groups');if(r.success&&r.data&&r.data.length>0){const data=r.data.map(g=>({'Group Name':g.group_name,'English':g.group_name_en||'','Category':g.is_under_sunday_school==1?'SS':'Parish','Est.':g.established_year||'','Male':g.current_male||0,'Female':g.current_female||0,'Leaders':g.leader_count||0,'Members':g.member_count||0}));const ws=XLSX.utils.json_to_sheet(data);const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Groups');XLSX.writeFile(wb,strtoupper(EXPORT_PREFIX) . '_Groups_'+new Date().toISOString().split('T')[0]+'.xlsx');toast('Exported!')}else toast('No data','error')}

// Export option click
document.querySelectorAll('.export-option').forEach(o=>{o.onclick=function(e){if(e.target.type!=='checkbox'){const cb=this.querySelector('input[type="checkbox"]');cb.checked=!cb.checked;this.classList.toggle('selected',cb.checked)}}});
// Init
document.addEventListener('DOMContentLoaded',loadGroups);
</script>
</body></html>
