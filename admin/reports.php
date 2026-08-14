<?php
/**
 * ============================================================
 * School Advanced Reports & Analytics Center
 * ============================================================
 * ZERO CDN DEPENDENCY for exports - all PDF/CSV/DOCX generated
 * server-side via export_pdf.php
 * Chart.js loaded from CDN only for visual charts (graceful fallback)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$userName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User';
$userRole = $_SESSION['admin_role'] ?? '';

$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports & Analytics - <?= SCHOOL_NAME_SHORT ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/admin/js/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=DM+Sans:wght@300;400;500;600;700&display=swap');
        *{box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:#f1f5f9;margin:0}
        .amh{font-family:'Noto Serif Ethiopic',serif}
        .glass{background:rgba(255,255,255,.95);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.6)}
        .card{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06),0 0 0 1px rgba(0,0,0,.02);overflow:hidden}
        .card-hd{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
        .card-bd{padding:20px}
        .sp{padding:16px;border-radius:14px;text-align:center}
        .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:500;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
        .btn-sm{padding:6px 12px;font-size:11px}
        .btn-pri{background:#16a34a;color:#fff}.btn-pri:hover{background:#15803d}
        .btn-blue{background:#2563eb;color:#fff}.btn-blue:hover{background:#1d4ed8}
        .btn-out{background:#fff;color:#475569;border:1px solid #e2e8f0}.btn-out:hover{background:#f8fafc}
        .btn-ghost{background:transparent;color:#64748b}.btn-ghost:hover{background:#f1f5f9}
        .sel{padding:8px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:12px;background:#fff;min-width:130px}
        .sel:focus{outline:none;border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.1)}
        .tbar{display:flex;gap:4px;background:#f1f5f9;padding:4px;border-radius:12px}
        .tbtn{padding:8px 16px;border-radius:10px;font-size:12px;font-weight:500;cursor:pointer;border:none;background:transparent;color:#64748b;transition:all .15s}
        .tbtn.on{background:#fff;color:#0f172a;box-shadow:0 1px 3px rgba(0,0,0,.08)}
        .cw{position:relative;height:280px}.cw-s{position:relative;height:220px}
        .dt{width:100%;font-size:12px;border-collapse:collapse}
        .dt th{background:#f8fafc;padding:10px 14px;text-align:left;font-weight:600;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:1}
        .dt td{padding:10px 14px;border-bottom:1px solid #f1f5f9}
        .dt tbody tr:hover td{background:#f0fdf4}
        .ch{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:600}
        .ch-g{background:#d1fae5;color:#065f46}.ch-a{background:#fef3c7;color:#92400e}.ch-r{background:#fee2e2;color:#991b1b}.ch-b{background:#dbeafe;color:#1e40af}.ch-p{background:#fce7f3;color:#9d174d}
        .ld{display:inline-block;width:20px;height:20px;border:3px solid #e2e8f0;border-top-color:#16a34a;border-radius:50%;animation:spn .6s linear infinite}
        @keyframes spn{to{transform:rotate(360deg)}}
        .fi{animation:fi .3s ease-out}@keyframes fi{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
        .pb{height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden}.pf{height:100%;border-radius:3px;transition:width .5s ease}
        .toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:12px;color:#fff;font-size:13px;z-index:200;animation:su .3s}
        @keyframes su{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        .exp-card{padding:12px 16px;border-radius:12px;background:#f8fafc;cursor:pointer;transition:.15s;display:flex;align-items:center;justify-content:space-between}
        .exp-card:hover{background:#f0fdf4;box-shadow:0 2px 8px rgba(0,0,0,.04)}
        @media(max-width:768px){.cw,.cw-s{height:200px}}
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css">
</head>
<body>
    <!-- Header -->
    <header class="bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-600 text-white px-4 md:px-8 py-4">
        <div class="max-w-[1400px] mx-auto flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="/admin/dashboard.php" class="btn btn-ghost text-white/70 hover:text-white hover:bg-white/10"><i class="fa-solid fa-arrow-left"></i></a>
                <div>
                    <h1 class="text-lg font-bold flex items-center gap-2"><i class="fa-solid fa-chart-line"></i> Reports & Analytics</h1>
                    <p class="text-xs text-emerald-100 amh">የ<?= SCHOOL_NAME_SHORT_AM ?> ሪፖርቶችና ትንተናዎች</p>
                </div>
            </div>
            <div class="hidden sm:flex items-center gap-3 text-xs text-emerald-100">
                <span class="amh"><?= e($todayFormatted) ?></span>
                <span class="px-2 py-1 bg-white/15 rounded-lg"><?= e($userName) ?></span>
            </div>
        </div>
    </header>

    <!-- Tabs -->
    <div class="sticky top-0 z-30 glass border-b border-slate-200/60">
        <div class="max-w-[1400px] mx-auto px-4 md:px-8 py-3">
            <div class="tbar inline-flex">
                <button class="tbtn on" onclick="showTab(this,'analytics')"><i class="fa-solid fa-chart-pie mr-1"></i>Analytics</button>
                <button class="tbtn" onclick="showTab(this,'explorer')"><i class="fa-solid fa-filter mr-1"></i>Data Explorer</button>
                <button class="tbtn" onclick="showTab(this,'exports')"><i class="fa-solid fa-download mr-1"></i>Export Center</button>
            </div>
        </div>
    </div>

    <main class="max-w-[1400px] mx-auto px-4 md:px-8 py-6">

        <!-- ============ TAB 1: ANALYTICS ============ -->
        <div id="tab-analytics" class="tc fi">
            <div id="aLoad" class="text-center py-16"><div class="ld"></div><p class="text-sm text-slate-400 mt-3">Loading analytics...</p></div>
            <div id="aCont" style="display:none">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6" id="kpiRow"></div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-venus-mars mr-1 text-pink-500"></i>Gender</span></div><div class="card-bd"><div class="cw-s"><canvas id="cGender"></canvas></div></div></div>
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-users-between-lines mr-1 text-violet-500"></i>Age Groups</span></div><div class="card-bd"><div class="cw-s"><canvas id="cAgeGrp"></canvas></div></div></div>
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-circle-dot mr-1 text-emerald-500"></i>Status</span></div><div class="card-bd"><div class="cw-s"><canvas id="cStatus"></canvas></div></div></div>
                </div>
                <div class="card mb-4"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-chart-area mr-1 text-blue-500"></i>Registration Trend (12 Months)</span></div><div class="card-bd"><div class="cw"><canvas id="cTrend"></canvas></div></div></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-chart-column mr-1 text-teal-500"></i>Detailed Age Distribution</span></div><div class="card-bd"><div class="cw"><canvas id="cAgeDist"></canvas></div></div></div>
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-clipboard-list mr-1 text-amber-500"></i>Registration Types</span></div><div class="card-bd"><div class="cw-s"><canvas id="cRegType"></canvas></div></div></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-map-location-dot mr-1 text-red-500"></i>Top Locations</span></div><div class="card-bd"><div id="locBars"></div></div></div>
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-graduation-cap mr-1 text-indigo-500"></i>Education</span></div><div class="card-bd"><div class="cw-s"><canvas id="cEdu"></canvas></div></div></div>
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-briefcase mr-1 text-orange-500"></i>Top Professions</span></div><div class="card-bd"><div id="profBars"></div></div></div>
                </div>
                <div class="card mb-4"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-clock mr-1 text-cyan-500"></i>Daily Registrations (30 Days)</span></div><div class="card-bd"><div class="cw-s"><canvas id="cRecent"></canvas></div></div></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-id-card mr-1 text-sky-500"></i>ID Card Status</span></div><div class="card-bd"><div class="cw-s"><canvas id="cIdCard"></canvas></div></div></div>
                    <div class="card"><div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-building mr-1 text-fuchsia-500"></i>Sub-City</span></div><div class="card-bd"><div class="cw-s"><canvas id="cSubCity"></canvas></div></div></div>
                </div>
            </div>
        </div>

        <!-- ============ TAB 2: DATA EXPLORER ============ -->
        <div id="tab-explorer" class="tc" style="display:none">
            <div class="card mb-4 fi">
                <div class="card-hd"><span class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-filter mr-1 text-emerald-500"></i>Filter Members</span><button onclick="resetF()" class="btn btn-sm btn-ghost"><i class="fa-solid fa-rotate-left"></i> Reset</button></div>
                <div class="card-bd">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-3">
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Gender</label><select id="fGender" class="sel w-full"><option value="">All</option><option value="male">Male</option><option value="female">Female</option></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Age Group</label><select id="fAge" class="sel w-full"><option value="">All</option><option value="under6">Under 6</option><option value="7_13">7-13</option><option value="14_17">14-17</option><option value="18_plus">18+</option></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Status</label><select id="fStatus" class="sel w-full"><option value="">All Active</option><option value="active">Active</option><option value="warning">Warning</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Member Type</label><select id="fMemType" class="sel w-full"><option value="">All</option><option value="regular">Regular</option><option value="honorary">Honorary</option></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Registration</label><select id="fRegType" class="sel w-full"><option value="">All</option><option value="direct">Direct</option><option value="transfer">Transfer</option><option value="waiting">Waiting</option></select></div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-3">
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">City</label><select id="fCity" class="sel w-full"><option value="">All</option></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Sub-City</label><select id="fSubCity" class="sel w-full"><option value="">All</option></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Education</label><select id="fEdu" class="sel w-full"><option value="">All</option></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">ID Card</label><select id="fIdCard" class="sel w-full"><option value="">All</option><option value="yes">Has ID</option><option value="no">No ID</option></select></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Phone</label><select id="fPhone" class="sel w-full"><option value="">All</option><option value="yes">Has Phone</option><option value="no">No Phone</option></select></div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Search</label><input id="fSearch" class="sel w-full" placeholder="Name, code, phone, guardian..."></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Registered From</label><input type="date" id="fDateFrom" class="sel w-full"></div>
                        <div><label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Registered To</label><input type="date" id="fDateTo" class="sel w-full"></div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button onclick="applyF()" class="btn btn-pri"><i class="fa-solid fa-magnifying-glass"></i> Apply Filters</button>
                        <button onclick="applyF();showExpCharts()" class="btn btn-blue"><i class="fa-solid fa-chart-bar"></i> Show Charts</button>
                    </div>
                </div>
            </div>
            <div id="expSum" class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 mb-4" style="display:none"></div>
            <div id="expCharts" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4" style="display:none">
                <div class="card"><div class="card-hd"><span class="text-xs font-semibold text-slate-600">Gender Split</span></div><div class="card-bd"><div style="height:180px"><canvas id="ecGender"></canvas></div></div></div>
                <div class="card"><div class="card-hd"><span class="text-xs font-semibold text-slate-600">Age Group Split</span></div><div class="card-bd"><div style="height:180px"><canvas id="ecAge"></canvas></div></div></div>
            </div>
            <div class="card" id="expRes" style="display:none">
                <div class="card-hd">
                    <span class="text-sm font-semibold text-slate-700" id="expCount">Results</span>
                    <div class="flex gap-2">
                        <button onclick="expToServer('csv')" class="btn btn-sm btn-out"><i class="fa-solid fa-file-csv text-emerald-600"></i> CSV</button>
                        <button onclick="expToServer('pdf')" class="btn btn-sm btn-out"><i class="fa-solid fa-file-pdf text-red-500"></i> PDF</button>
                        <button onclick="expToServer('docx')" class="btn btn-sm btn-out"><i class="fa-solid fa-file-word text-blue-600"></i> Word</button>
                    </div>
                </div>
                <div style="max-height:500px;overflow:auto">
                    <table class="dt"><thead><tr><th>#</th><th>Name</th><th>Code</th><th>Gender</th><th>Age Grp</th><th>Phone</th><th>City</th><th>Status</th><th>Reg Type</th></tr></thead>
                    <tbody id="expBody"></tbody></table>
                </div>
            </div>
            <div id="expEmpty" class="card" style="display:none"><div class="text-center py-12 text-slate-400"><i class="fa-solid fa-filter text-4xl mb-3"></i><p class="font-medium">No results match your filters</p></div></div>
        </div>

        <!-- ============ TAB 3: EXPORT CENTER ============ -->
        <div id="tab-exports" class="tc" style="display:none">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 fi">
                <div class="card">
                    <div class="card-hd bg-emerald-50"><span class="text-sm font-semibold text-emerald-800"><i class="fa-solid fa-file-csv mr-1"></i> CSV Exports</span></div>
                    <div class="card-bd space-y-2">
                        <a href="/admin/export_pdf.php?format=csv&filter=all" class="exp-card"><span><i class="fa-solid fa-users text-emerald-600 mr-2"></i>All Members</span><i class="fa-solid fa-download text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=csv&filter=active" class="exp-card"><span><i class="fa-solid fa-user-check text-blue-600 mr-2"></i>Active Only</span><i class="fa-solid fa-download text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=csv&filter=waiting" class="exp-card"><span><i class="fa-solid fa-hourglass-half text-amber-600 mr-2"></i>Waiting Members</span><i class="fa-solid fa-download text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=csv&filter=no_id" class="exp-card"><span><i class="fa-solid fa-id-card text-red-500 mr-2"></i>Without ID Card</span><i class="fa-solid fa-download text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=csv&filter=male" class="exp-card"><span><i class="fa-solid fa-person text-sky-600 mr-2"></i>Male Members</span><i class="fa-solid fa-download text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=csv&filter=female" class="exp-card"><span><i class="fa-solid fa-person-dress text-pink-600 mr-2"></i>Female Members</span><i class="fa-solid fa-download text-slate-400"></i></a>
                    </div>
                </div>
                <div class="card">
                    <div class="card-hd bg-red-50"><span class="text-sm font-semibold text-red-800"><i class="fa-solid fa-file-pdf mr-1"></i> PDF Reports</span></div>
                    <div class="card-bd space-y-2">
                        <a href="/admin/export_pdf.php?format=pdf&filter=all" target="_blank" class="exp-card"><span><i class="fa-solid fa-users text-red-600 mr-2"></i>All Members</span><i class="fa-solid fa-print text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=pdf&filter=active" target="_blank" class="exp-card"><span><i class="fa-solid fa-user-check text-red-600 mr-2"></i>Active Members</span><i class="fa-solid fa-print text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=pdf&filter=waiting" target="_blank" class="exp-card"><span><i class="fa-solid fa-hourglass-half text-red-600 mr-2"></i>Waiting List</span><i class="fa-solid fa-print text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=pdf&filter=no_id" target="_blank" class="exp-card"><span><i class="fa-solid fa-id-card text-red-600 mr-2"></i>Without ID Card</span><i class="fa-solid fa-print text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=pdf&filter=male" target="_blank" class="exp-card"><span><i class="fa-solid fa-person text-red-600 mr-2"></i>Male Members</span><i class="fa-solid fa-print text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=pdf&filter=female" target="_blank" class="exp-card"><span><i class="fa-solid fa-person-dress text-red-600 mr-2"></i>Female Members</span><i class="fa-solid fa-print text-slate-400"></i></a>
                    </div>
                </div>
                <div class="card">
                    <div class="card-hd bg-blue-50"><span class="text-sm font-semibold text-blue-800"><i class="fa-solid fa-file-word mr-1"></i> Word Documents</span></div>
                    <div class="card-bd space-y-2">
                        <a href="/admin/export_pdf.php?format=docx&filter=all" class="exp-card"><span><i class="fa-solid fa-users text-blue-600 mr-2"></i>All Members</span><i class="fa-solid fa-file-word text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=docx&filter=active" class="exp-card"><span><i class="fa-solid fa-user-check text-blue-600 mr-2"></i>Active Members</span><i class="fa-solid fa-file-word text-slate-400"></i></a>
                        <a href="/admin/export_pdf.php?format=docx&filter=no_id" class="exp-card"><span><i class="fa-solid fa-id-card text-blue-600 mr-2"></i>Missing ID List</span><i class="fa-solid fa-file-word text-slate-400"></i></a>
                    </div>
                </div>
            </div>
            <div class="card mt-4"><div class="card-bd text-center text-xs text-slate-400"><i class="fa-solid fa-lightbulb mr-1 text-amber-400"></i> PDF opens a print-ready page. Use <strong>Ctrl+P → Save as PDF</strong> in Chrome to download. For custom filters, use <strong>Data Explorer</strong> tab.</div></div>
        </div>
    </main>
    <div id="toastC"></div>

<script>
let AD=null,FM=[],CI={};
const PAL=['#16a34a','#2563eb','#f59e0b','#dc2626','#8b5cf6','#06b6d4','#ec4899','#f97316','#14b8a6','#6366f1'];
const hasChart=typeof Chart!=='undefined';

function esc(t){if(!t)return'';const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function sv(v){return v==null?'':String(v);}
function toast(m,ok){const t=document.createElement('div');t.className='toast';t.style.background=ok!==false?'#16a34a':'#dc2626';t.innerHTML=m;document.getElementById('toastC').appendChild(t);setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.remove(),300)},3000);}

function showTab(el,name){
    document.querySelectorAll('.tc').forEach(t=>t.style.display='none');
    document.querySelectorAll('.tbtn').forEach(b=>b.classList.remove('on'));
    document.getElementById('tab-'+name).style.display='block';
    el.classList.add('on');
    if(name==='analytics'&&!AD)loadA();
    if(name==='explorer')loadFO();
    const _u=new URL(window.location);_u.searchParams.set('tab',name);history.replaceState(null,'',_u);
}
{const _t=new URLSearchParams(window.location.search).get('tab');if(_t){const _b=document.querySelector('.tbtn[onclick*="\''+_t+'\'"]');if(_b)showTab(_b,_t);}}

// ============ ANALYTICS ============
function loadA(){
    fetch('/admin/api_reports.php?action=get_analytics',{credentials:'same-origin'})
    .then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
    .then(d=>{
        if(d.status!=='success'){document.getElementById('aLoad').innerHTML='<p class="text-red-500 text-sm">'+esc(d.message||'Error')+'</p>';return;}
        AD=d.analytics;document.getElementById('aLoad').style.display='none';document.getElementById('aCont').style.display='block';
        renderKPIs();if(hasChart)renderCharts();else document.querySelectorAll('canvas').forEach(c=>{c.parentElement.innerHTML='<p class="text-xs text-slate-400 text-center py-8">Charts unavailable (CDN blocked). Data is shown in KPIs above.</p>';});
    }).catch(err=>{document.getElementById('aLoad').innerHTML='<p class="text-red-500 text-sm">Error: '+esc(err.message)+'</p>';});
}

function renderKPIs(){
    const t=AD.totals,tot=Number(t.active||0)+Number(t.warning||0)+Number(t.inactive||0);
    document.getElementById('kpiRow').innerHTML=[
        {l:'Total Members',v:tot,c:'#16a34a',b:'#d1fae5'},{l:'Male',v:t.male||0,c:'#2563eb',b:'#dbeafe'},
        {l:'Female',v:t.female||0,c:'#ec4899',b:'#fce7f3'},{l:'With ID',v:t.has_id||0,c:'#06b6d4',b:'#cffafe'},
        {l:'Waiting',v:t.waiting||0,c:'#f59e0b',b:'#fef3c7'},{l:'Teachers',v:t.is_teacher||0,c:'#8b5cf6',b:'#ede9fe'}
    ].map(k=>'<div class="sp" style="background:'+k.b+'"><div class="text-2xl font-bold" style="color:'+k.c+'">'+Number(k.v).toLocaleString()+'</div><div class="text-[10px] font-medium" style="color:'+k.c+'80">'+k.l+'</div></div>').join('');
}

function mc(id,cfg){if(!hasChart)return;if(CI[id])CI[id].destroy();const ctx=document.getElementById(id);if(!ctx)return;CI[id]=new Chart(ctx.getContext('2d'),cfg);}
function dO(p){return{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:p||'bottom',labels:{boxWidth:12,font:{size:10}}}}};}

function renderCharts(){
    const t=AD.totals;
    mc('cGender',{type:'doughnut',data:{labels:['Male','Female'],datasets:[{data:[t.male||0,t.female||0],backgroundColor:['#3b82f6','#ec4899'],borderWidth:0}]},options:dO()});
    mc('cAgeGrp',{type:'doughnut',data:{labels:['Under 6','7-13','14-17','18+'],datasets:[{data:[t.under6||0,t.ag_7_13||0,t.ag_14_17||0,t.ag_18_plus||0],backgroundColor:['#10b981','#3b82f6','#f59e0b','#ef4444'],borderWidth:0}]},options:dO()});
    mc('cStatus',{type:'doughnut',data:{labels:['Active','Warning','Inactive'],datasets:[{data:[t.active||0,t.warning||0,t.inactive||0],backgroundColor:['#16a34a','#f59e0b','#94a3b8'],borderWidth:0}]},options:dO()});
    const tr=AD.registration_trend||[];
    mc('cTrend',{type:'line',data:{labels:tr.map(x=>x.month),datasets:[{label:'Total',data:tr.map(x=>x.count),borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.08)',fill:true,tension:.4,borderWidth:2,pointRadius:4,pointBackgroundColor:'#16a34a'},{label:'Male',data:tr.map(x=>x.male_count),borderColor:'#3b82f6',backgroundColor:'transparent',tension:.4,borderWidth:1.5,borderDash:[4,4],pointRadius:3},{label:'Female',data:tr.map(x=>x.female_count),borderColor:'#ec4899',backgroundColor:'transparent',tension:.4,borderWidth:1.5,borderDash:[4,4],pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{boxWidth:12,font:{size:11}}}},scales:{y:{beginAtZero:true},x:{ticks:{font:{size:10}}}}}});
    const ag=AD.age_distribution||[];
    mc('cAgeDist',{type:'bar',data:{labels:ag.map(a=>a.age_range),datasets:[{label:'Male',data:ag.map(a=>a.male_count),backgroundColor:'#3b82f6',borderRadius:6},{label:'Female',data:ag.map(a=>a.female_count),backgroundColor:'#ec4899',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{boxWidth:12}}},scales:{y:{beginAtZero:true,stacked:true},x:{stacked:true}}}});
    mc('cRegType',{type:'pie',data:{labels:['Direct','Transfer','Waiting','Honorary'],datasets:[{data:[t.direct||0,t.transfer_reg||0,t.waiting||0,t.honorary||0],backgroundColor:['#16a34a','#3b82f6','#f59e0b','#8b5cf6'],borderWidth:0}]},options:dO()});
    const locs=AD.location_distribution||[],maxL=locs.length>0?Math.max(...locs.map(l=>l.count)):1;
    document.getElementById('locBars').innerHTML=locs.length===0?'<p class="text-xs text-slate-400">No data</p>':locs.map((l,i)=>'<div class="flex items-center gap-2 mb-2"><span class="text-[10px] text-slate-600 w-20 truncate">'+esc(l.location)+'</span><div class="flex-1 pb"><div class="pf" style="width:'+(l.count/maxL*100)+'%;background:'+PAL[i%10]+'"></div></div><span class="text-[10px] font-bold text-slate-700">'+l.count+'</span></div>').join('');
    const edu=AD.education_distribution||[];
    mc('cEdu',{type:'doughnut',data:{labels:edu.map(e=>e.level),datasets:[{data:edu.map(e=>e.count),backgroundColor:PAL.slice(0,edu.length),borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'55%',plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:9}}}}}});
    const pf=AD.profession_distribution||[],maxP=pf.length>0?Math.max(...pf.map(p=>p.count)):1;
    document.getElementById('profBars').innerHTML=pf.length===0?'<p class="text-xs text-slate-400">No data</p>':pf.map((p,i)=>'<div class="flex items-center gap-2 mb-2"><span class="text-[10px] text-slate-600 w-20 truncate">'+esc(p.profession)+'</span><div class="flex-1 pb"><div class="pf" style="width:'+(p.count/maxP*100)+'%;background:'+PAL[i%10]+'"></div></div><span class="text-[10px] font-bold text-slate-700">'+p.count+'</span></div>').join('');
    const rc=AD.recent_activity||[];
    mc('cRecent',{type:'bar',data:{labels:rc.map(r=>r.day.slice(5)),datasets:[{label:'Registrations',data:rc.map(r=>r.count),backgroundColor:'#16a34a',borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true},x:{ticks:{font:{size:8},maxRotation:45}}}}});
    mc('cIdCard',{type:'doughnut',data:{labels:['Has ID','No ID'],datasets:[{data:[t.has_id||0,t.no_id||0],backgroundColor:['#06b6d4','#f87171'],borderWidth:0}]},options:dO()});
    const sc=AD.subcity_distribution||[];
    mc('cSubCity',{type:'bar',data:{labels:sc.map(x=>x.sub_city),datasets:[{label:'Members',data:sc.map(x=>x.count),backgroundColor:PAL.slice(0,sc.length),borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
}

// ============ DATA EXPLORER ============
let foL=false;
function loadFO(){if(foL)return;fetch('/admin/api_reports.php?action=get_filter_options',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{if(d.status!=='success')return;const o=d.options;pS('fCity',o.city||[]);pS('fSubCity',o.sub_city||[]);pS('fEdu',o.education_level||[]);foL=true;});}
function pS(id,v){const s=document.getElementById(id);v.forEach(x=>{const o=document.createElement('option');o.value=x;o.textContent=x;s.appendChild(o);});}

function getP(){
    const p=new URLSearchParams(),m={gender:'fGender',age_group:'fAge',f_status:'fStatus',member_type:'fMemType',registration_type:'fRegType',city:'fCity',sub_city:'fSubCity',education_level:'fEdu',has_id_card:'fIdCard',has_phone:'fPhone',search:'fSearch',date_from:'fDateFrom',date_to:'fDateTo'};
    for(const[k,id]of Object.entries(m)){const v=document.getElementById(id)?.value;if(v)p.set(k,v);}
    return p;
}

function resetF(){['fGender','fAge','fStatus','fMemType','fRegType','fCity','fSubCity','fEdu','fIdCard','fPhone','fSearch','fDateFrom','fDateTo'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});['expSum','expCharts','expRes','expEmpty'].forEach(id=>document.getElementById(id).style.display='none');}

function applyF(){
    const p=getP();p.set('action','get_filtered_members');
    // Remap f_status to status for API
    if(p.has('f_status')){p.set('status',p.get('f_status'));p.delete('f_status');}
    ['expRes','expEmpty'].forEach(id=>document.getElementById(id).style.display='none');
    fetch('/admin/api_reports.php?'+p.toString(),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
        if(d.status!=='success'){toast(d.message||'Error',false);return;}
        FM=d.members;const sm=d.summary;
        document.getElementById('expSum').style.display='grid';
        document.getElementById('expSum').innerHTML=[
            {l:'Total',v:sm.total||0,c:'#16a34a',b:'#d1fae5'},{l:'Male',v:sm.male||0,c:'#2563eb',b:'#dbeafe'},
            {l:'Female',v:sm.female||0,c:'#ec4899',b:'#fce7f3'},{l:'Active',v:sm.active||0,c:'#059669',b:'#d1fae5'},
            {l:'Warning',v:sm.warning||0,c:'#d97706',b:'#fef3c7'},{l:'Under 6',v:sm.under6||0,c:'#14b8a6',b:'#ccfbf1'},
            {l:'7-13',v:sm.ag7_13||0,c:'#6366f1',b:'#e0e7ff'},{l:'18+',v:sm.ag18_plus||0,c:'#f97316',b:'#ffedd5'}
        ].map(k=>'<div class="sp" style="background:'+k.b+'"><div class="text-lg font-bold" style="color:'+k.c+'">'+Number(k.v).toLocaleString()+'</div><div class="text-[9px] font-medium" style="color:'+k.c+'80">'+k.l+'</div></div>').join('');
        if(d.count===0){document.getElementById('expEmpty').style.display='block';return;}
        document.getElementById('expRes').style.display='block';
        document.getElementById('expCount').textContent=d.count+' members found';
        const agL={under6:'Under 6','7_13':'7-13','14_17':'14-17','18_plus':'18+'},stC={active:'ch-g',warning:'ch-a',inactive:'ch-r',archived:'ch-b'};
        document.getElementById('expBody').innerHTML=FM.slice(0,500).map((m,i)=>
            '<tr><td>'+(i+1)+'</td><td class="font-medium">'+esc(sv(m.student_name)+' '+sv(m.father_name)+' '+sv(m.grandfather_name))+'</td>'+
            '<td><code class="text-[10px] bg-slate-100 px-1.5 py-0.5 rounded">'+esc(sv(m.member_code)||'—')+'</code></td>'+
            '<td>'+(m.gender==='male'?'<span class="ch ch-b">M</span>':'<span class="ch ch-p">F</span>')+'</td>'+
            '<td class="text-[10px]">'+(agL[m.age_group]||sv(m.age_group)||'—')+'</td>'+
            '<td class="text-[10px]">'+esc(sv(m.phone_number)||'—')+'</td>'+
            '<td class="text-[10px]">'+esc(sv(m.city)||'—')+'</td>'+
            '<td><span class="ch '+(stC[m.status]||'ch-b')+'">'+(sv(m.status)||'—')+'</span></td>'+
            '<td class="text-[10px]">'+(sv(m.registration_type)||'—')+'</td></tr>'
        ).join('');
    }).catch(err=>{toast('Network error: '+err.message,false);});
}

function showExpCharts(){
    if(!FM.length||!hasChart)return;
    document.getElementById('expCharts').style.display='grid';
    let m=0,f=0,u6=0,a13=0,a17=0,a18=0;
    FM.forEach(x=>{if(x.gender==='male')m++;else f++;if(x.age_group==='under6')u6++;else if(x.age_group==='7_13')a13++;else if(x.age_group==='14_17')a17++;else if(x.age_group==='18_plus')a18++;});
    mc('ecGender',{type:'doughnut',data:{labels:['Male','Female'],datasets:[{data:[m,f],backgroundColor:['#3b82f6','#ec4899'],borderWidth:0}]},options:dO()});
    mc('ecAge',{type:'doughnut',data:{labels:['Under 6','7-13','14-17','18+'],datasets:[{data:[u6,a13,a17,a18],backgroundColor:['#10b981','#3b82f6','#f59e0b','#ef4444'],borderWidth:0}]},options:dO()});
}

// ============ SERVER-SIDE EXPORTS ============
function expToServer(fmt){
    const p=getP();
    // Remap for server export
    if(p.has('f_status')){p.set('f_status',p.get('f_status'));}
    p.set('format',fmt);
    p.set('title','Filtered Members Report');
    const url='/admin/export_pdf.php?'+p.toString();
    if(fmt==='pdf') window.open(url,'_blank');
    else window.location.href=url;
}

// Init
loadA();
</script>
</body>
</html>
