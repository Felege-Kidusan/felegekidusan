<?php
/**
 * Material Department Dashboard — <?= SCHOOL_NAME ?>
 * Full CRUD: Inventory items, Stock management, Requests, Transactions, Categories
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';

$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Material Admin';
$username = $_SESSION['admin_username'] ?? '';
$csrfToken = generateCsrfToken();
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');
$greeting = ((int)$today->format('H') < 12) ? 'Good Morning' : (((int)$today->format('H') < 17) ? 'Good Afternoon' : 'Good Evening');
$initials = strtoupper(substr($fullName, 0, 1));

$matReady = false;
$mStats = ['total'=>0,'in_stock'=>0,'low'=>0,'out'=>0,'maint'=>0,'pending_req'=>0];
if (isset($conn)) {
    try {
        $conn->query("SELECT 1 FROM material_items LIMIT 0");
        $matReady = true;
        $r=$conn->query("SELECT COUNT(*) t,SUM(status='in_stock') s,SUM(status='low_stock') l,SUM(status='out_of_stock') o,SUM(status='maintenance') m FROM material_items");
        if($r&&$row=$r->fetch_assoc()){$mStats['total']=(int)$row['t'];$mStats['in_stock']=(int)$row['s'];$mStats['low']=(int)$row['l'];$mStats['out']=(int)$row['o'];$mStats['maint']=(int)$row['m'];}
        $r=$conn->query("SELECT COUNT(*) c FROM material_requests WHERE status='pending'");
        if($r&&$row=$r->fetch_assoc())$mStats['pending_req']=(int)$row['c'];
    } catch(Exception $e) { $matReady = false; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>Material — <?= SCHOOL_NAME_SHORT ?></title>
<?= wbws_calendar_scripts($conn) ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⛪</text></svg>">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',system-ui,sans-serif}
:root{--ac:#a855f7;--ac2:#7c3aed;--bg:#0f172a;--sb:#020617;--cd:rgba(255,255,255,0.04);--cb:rgba(255,255,255,0.08);--tx:#e5e7eb;--dm:#94a3b8;--ok:#22c55e;--wn:#fbbf24;--bd:#ef4444;--in:#0ea5e9}
body{min-height:100vh;display:flex;background:var(--bg);color:var(--tx)}
aside{width:260px;background:linear-gradient(180deg,var(--sb),var(--bg));padding:1.5rem 1.25rem;display:flex;flex-direction:column;gap:1.5rem;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0;z-index:20}
.brand{display:flex;align-items:center;gap:.75rem}.bl{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;box-shadow:0 8px 20px rgba(168,85,247,0.4)}
.nt{font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:var(--dm);padding:.5rem .75rem}
.np{display:flex;align-items:center;gap:.75rem;padding:.65rem .75rem;border-radius:10px;color:var(--dm);text-decoration:none;font-size:.85rem;cursor:pointer;transition:all .2s;border:none;background:none;width:100%;text-align:left}.np:hover,.np.active{background:rgba(255,255,255,0.08);color:#f1f5f9}.np.active{background:rgba(168,85,247,0.15);color:var(--ac);font-weight:600}.np i{width:20px;text-align:center}
.uc{margin-top:auto;display:flex;align-items:center;gap:.75rem;padding:.75rem;border-radius:12px;background:var(--cd);border:1px solid var(--cb)}.ua{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff}
main{flex:1;padding:1.5rem 2rem 6rem;overflow-y:auto;max-width:calc(100vw - 260px)}
.cs{display:none}.cs.active{display:block}
.sg{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
.sc{background:var(--cd);border:1px solid var(--cb);border-radius:16px;padding:1.25rem;transition:all .2s}.sc:hover{transform:translateY(-2px)}.sc .ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.75rem}.sc .val{font-size:1.75rem;font-weight:700;color:#f1f5f9}.sc .lbl{font-size:.72rem;color:var(--dm);margin-top:.25rem}
.crd{background:var(--cd);border:1px solid var(--cb);border-radius:16px;padding:1.25rem;margin-bottom:1.25rem}.crt{font-size:1rem;font-weight:600;color:#f1f5f9;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.tw{overflow-x:auto;border-radius:12px;border:1px solid var(--cb)}table{width:100%;border-collapse:collapse;font-size:.8rem}thead{background:rgba(255,255,255,0.04)}th{padding:.7rem .8rem;text-align:left;color:var(--dm);font-weight:600;font-size:.7rem;text-transform:uppercase;white-space:nowrap}td{padding:.6rem .8rem;border-top:1px solid var(--cb);white-space:nowrap}tr:hover td{background:rgba(255,255,255,0.02)}
.inp{background:rgba(255,255,255,0.06);border:1px solid var(--cb);border-radius:10px;padding:.55rem .85rem;color:#f1f5f9;font-size:.8rem;outline:none}.inp:focus{border-color:var(--ac)}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:10px;font-size:.8rem;font-weight:600;cursor:pointer;border:none;transition:all .2s}.bp{background:linear-gradient(135deg,var(--ac),var(--ac2));color:#fff}.bo{background:transparent;border:1px solid var(--cb);color:var(--tx)}.bo:hover{border-color:var(--ac);color:var(--ac)}.bs{padding:.35rem .75rem;font-size:.72rem}
.bg{display:inline-flex;padding:.2rem .55rem;border-radius:99px;font-size:.65rem;font-weight:600}.bg-ok{background:rgba(34,197,94,.15);color:#22c55e}.bg-w{background:rgba(251,191,36,.15);color:#fbbf24}.bg-bd{background:rgba(239,68,68,.15);color:#ef4444}.bg-in{background:rgba(14,165,233,.15);color:#0ea5e9}.bg-p{background:rgba(168,85,247,.15);color:#a855f7}
.mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);z-index:100;align-items:center;justify-content:center;padding:1rem}.mo.show{display:flex}.md{background:#1e293b;border:1px solid var(--cb);border-radius:20px;padding:1.5rem;max-width:560px;width:100%;max-height:90vh;overflow-y:auto}.md h3{font-size:1.1rem;font-weight:700;color:#f1f5f9;margin-bottom:1rem}
.toast{position:fixed;top:1.5rem;right:1.5rem;padding:.75rem 1.25rem;border-radius:12px;color:#fff;font-size:.8rem;font-weight:600;z-index:200;transform:translateX(120%);transition:transform .3s;display:flex;align-items:center;gap:.5rem}.toast.show{transform:translateX(0)}.t-ok{background:#16a34a}.t-err{background:#dc2626}
.bn{display:none;position:fixed;bottom:0;left:0;right:0;background:rgba(15,23,42,0.95);backdrop-filter:blur(10px);border-top:1px solid var(--cb);padding:.4rem 0;z-index:50}.bni{display:flex;justify-content:space-around;max-width:500px;margin:0 auto}.bn button,.bn a{display:flex;flex-direction:column;align-items:center;gap:.15rem;background:none;border:none;color:var(--dm);font-size:.6rem;padding:.25rem .5rem;cursor:pointer;text-decoration:none}.bn button.active{color:var(--ac)}.bn i{font-size:1.1rem}
@media(max-width:768px){aside{display:none}main{max-width:100%;padding:1rem 1rem 5rem}.bn{display:block}.sg{grid-template-columns:repeat(2,1fr)}.sc .val{font-size:1.2rem}}
@media print{aside,.bn,.no-print{display:none!important}main{max-width:100%;padding:0}body{background:#fff;color:#000}}
</style>
<link rel="stylesheet" href="/admin/css/mobile.css">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body>
<aside class="school-sidebar">
<div class="brand"><div class="bl"><i class="fa-solid fa-warehouse"></i></div><div><span style="font-size:.95rem;font-weight:600;color:#f1f5f9"><?= SCHOOL_NAME_SHORT ?> Material</span><br><span style="font-size:.7rem;color:var(--dm)"><?= DEPT_MATERIAL_NAME ?></span></div></div>
<div><div class="nt">Inventory</div>
<button class="np active" data-section="dashboard"><i class="fa-solid fa-gauge-high"></i> Overview</button>
<button class="np" data-section="inventory"><i class="fa-solid fa-boxes-stacked"></i> Inventory</button>
<button class="np" data-section="incoming"><i class="fa-solid fa-truck-ramp-box"></i> Incoming</button>
<button class="np" data-section="outgoing"><i class="fa-solid fa-dolly"></i> Outgoing</button>
<button class="np" data-section="requests"><i class="fa-solid fa-clipboard-list"></i> Requests</button>
<button class="np" data-section="categories"><i class="fa-solid fa-tags"></i> Categories</button>
</div>
<div class="uc"><div class="ua"><?= $initials ?></div><div><span style="font-size:.8rem;font-weight:600;color:#f1f5f9"><?= e($fullName) ?></span><br><span style="font-size:.65rem;color:var(--dm)">Material • <?= $todayFormatted ?></span></div></div>
<a href="/admin/logout.php" class="np" style="color:var(--bd)"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
</aside>
<main>
<!-- Mobile Header -->
<div class="wbws-mob-header">
    <a href="/admin/dashboard.php" class="mob-back"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="mob-title">
        <h1>Material Dept</h1>
        <p class="mob-sub"><?= $todayFormatted ?></p>
    </div>
    <div class="mob-avatar"><?= $initials ?></div>
</div>
<?php if (!$matReady): ?>
<div class="crd" style="text-align:center;padding:3rem"><i class="fa-solid fa-database" style="font-size:3rem;color:var(--ac);margin-bottom:1rem"></i><h2 style="color:#f1f5f9;margin-bottom:.5rem">Setup Required</h2><p style="color:var(--dm);margin-bottom:1.5rem">Material tables need to be created.</p><a href="/admin/migrations/004_add_finance_material_tables.php" class="btn bp"><i class="fa-solid fa-play"></i> Run Migration 004</a></div>
<?php else: ?>
<!-- DASHBOARD -->
<div id="section-dashboard" class="cs active">
<div style="margin-bottom:1.5rem"><h1 style="font-size:1.4rem;font-weight:700;color:#f1f5f9"><?= $greeting ?>, <?= e(explode(' ',$fullName)[0]) ?> 📦</h1><p style="color:var(--dm);font-size:.8rem"><?= $todayFormatted ?> • Inventory Management</p></div>
<div class="sg">
<div class="sc"><div class="ico" style="background:rgba(168,85,247,.15);color:var(--ac)"><i class="fa-solid fa-boxes-stacked"></i></div><div class="val"><?= $mStats['total'] ?></div><div class="lbl">Total Items</div></div>
<div class="sc"><div class="ico" style="background:rgba(34,197,94,.15);color:var(--ok)"><i class="fa-solid fa-check-circle"></i></div><div class="val"><?= $mStats['in_stock'] ?></div><div class="lbl">In Stock</div></div>
<div class="sc"><div class="ico" style="background:rgba(251,191,36,.15);color:var(--wn)"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="val"><?= $mStats['low'] ?></div><div class="lbl">Low Stock</div></div>
<div class="sc"><div class="ico" style="background:rgba(239,68,68,.15);color:var(--bd)"><i class="fa-solid fa-xmark-circle"></i></div><div class="val"><?= $mStats['out'] ?></div><div class="lbl">Out of Stock</div></div>
<div class="sc"><div class="ico" style="background:rgba(14,165,233,.15);color:var(--in)"><i class="fa-solid fa-wrench"></i></div><div class="val"><?= $mStats['maint'] ?></div><div class="lbl">Maintenance</div></div>
<div class="sc"><div class="ico" style="background:rgba(245,158,11,.15);color:#f59e0b"><i class="fa-solid fa-clipboard-list"></i></div><div class="val"><?= $mStats['pending_req'] ?></div><div class="lbl">Pending Requests</div></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
<div class="crd"><div class="crt"><i class="fa-solid fa-triangle-exclamation" style="color:var(--wn)"></i> Low Stock Alerts</div><div id="lowStockList"><p style="color:var(--dm);font-size:.8rem">Loading...</p></div></div>
<div class="crd"><div class="crt"><i class="fa-solid fa-clock-rotate-left" style="color:var(--ac)"></i> Recent Activity</div><div id="recentList"><p style="color:var(--dm);font-size:.8rem">Loading...</p></div></div>
</div></div>
<!-- INVENTORY -->
<div id="section-inventory" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem"><h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9"><i class="fa-solid fa-boxes-stacked" style="color:var(--ac)"></i> Inventory</h2><div style="display:flex;gap:.5rem" class="no-print"><button class="btn bp bs" onclick="openItemModal()"><i class="fa-solid fa-plus"></i> Add Item</button><button class="btn bo bs" onclick="exportItems()"><i class="fa-solid fa-download"></i> Export</button></div></div>
<div class="crd no-print"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem"><div><label style="font-size:.65rem;color:var(--dm)">Search</label><input type="text" id="invSearch" class="inp" style="width:100%" oninput="loadItems()" placeholder="Search..."></div><div><label style="font-size:.65rem;color:var(--dm)">Category</label><select id="invCat" class="inp" style="width:100%" onchange="loadItems()"><option value="">All</option></select></div><div><label style="font-size:.65rem;color:var(--dm)">Status</label><select id="invStatus" class="inp" style="width:100%" onchange="loadItems()"><option value="">All</option><option value="in_stock">In Stock</option><option value="low_stock">Low Stock</option><option value="out_of_stock">Out of Stock</option></select></div></div></div>
<div class="tw"><table><thead><tr><th>Name</th><th>Category</th><th>Qty</th><th>Min</th><th>Unit</th><th>Location</th><th>Condition</th><th>Status</th><th>Actions</th></tr></thead><tbody id="invBody"></tbody></table></div>
</div>
<!-- INCOMING -->
<div id="section-incoming" class="cs">
<h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9;margin-bottom:1rem"><i class="fa-solid fa-truck-ramp-box" style="color:var(--ok)"></i> Receive Items</h2>
<div class="crd"><div style="display:flex;flex-direction:column;gap:.75rem">
<div><label style="font-size:.7rem;color:var(--dm)">Item</label><select id="inItem" class="inp" style="width:100%"></select></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem"><div><label style="font-size:.7rem;color:var(--dm)">Quantity</label><input type="number" id="inQty" class="inp" style="width:100%" min="1"></div><div><label style="font-size:.7rem;color:var(--dm)">Date</label><input type="date" id="inDate" class="inp" style="width:100%"></div></div>
<div><label style="font-size:.7rem;color:var(--dm)">Received From / Reason</label><input id="inReason" class="inp" style="width:100%"></div>
<div><label style="font-size:.7rem;color:var(--dm)">Handled By</label><input id="inHandler" class="inp" style="width:100%"></div>
<button class="btn bp" onclick="addTxn('incoming')"><i class="fa-solid fa-check"></i> Record Incoming</button>
</div></div></div>
<!-- OUTGOING -->
<div id="section-outgoing" class="cs">
<h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9;margin-bottom:1rem"><i class="fa-solid fa-dolly" style="color:var(--bd)"></i> Issue Items</h2>
<div class="crd"><div style="display:flex;flex-direction:column;gap:.75rem">
<div><label style="font-size:.7rem;color:var(--dm)">Item</label><select id="outItem" class="inp" style="width:100%"></select></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem"><div><label style="font-size:.7rem;color:var(--dm)">Quantity</label><input type="number" id="outQty" class="inp" style="width:100%" min="1"></div><div><label style="font-size:.7rem;color:var(--dm)">Date</label><input type="date" id="outDate" class="inp" style="width:100%"></div></div>
<div><label style="font-size:.7rem;color:var(--dm)">Issued To / Reason</label><input id="outReason" class="inp" style="width:100%"></div>
<div><label style="font-size:.7rem;color:var(--dm)">Handled By</label><input id="outHandler" class="inp" style="width:100%"></div>
<button class="btn bp" onclick="addTxn('outgoing')"><i class="fa-solid fa-check"></i> Record Outgoing</button>
</div></div></div>
<!-- REQUESTS -->
<div id="section-requests" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9"><i class="fa-solid fa-clipboard-list" style="color:var(--ac)"></i> Requests</h2><button class="btn bp bs" onclick="openReqModal()"><i class="fa-solid fa-plus"></i> New Request</button></div>
<div class="tw"><table><thead><tr><th>Item</th><th>Qty</th><th>Requested By</th><th>Department</th><th>Reason</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody id="reqBody"></tbody></table></div>
</div>
<!-- CATEGORIES -->
<div id="section-categories" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9"><i class="fa-solid fa-tags" style="color:var(--ac)"></i> Categories</h2><button class="btn bp bs" onclick="openMatCatModal()"><i class="fa-solid fa-plus"></i> Add</button></div>
<div class="crd"><div id="matCatList"></div></div>
</div>
<?php endif; ?>
</main>
<!-- ADD ITEM MODAL -->
<div class="mo" id="itemModal"><div class="md"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h3 style="margin:0">Add / Edit Item</h3><button onclick="closeModal('itemModal')" style="background:none;border:none;color:var(--dm);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div>
<div style="display:flex;flex-direction:column;gap:.75rem">
<input type="hidden" id="itemId" value="0">
<div><label style="font-size:.7rem;color:var(--dm)">Name</label><input id="itemName" class="inp" style="width:100%"></div>
<div><label style="font-size:.7rem;color:var(--dm)">Category</label><select id="itemCat" class="inp" style="width:100%"></select></div>
<div><label style="font-size:.7rem;color:var(--dm)">Description</label><input id="itemDesc" class="inp" style="width:100%"></div>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem"><div><label style="font-size:.7rem;color:var(--dm)">Quantity</label><input type="number" id="itemQty" class="inp" style="width:100%" min="0"></div><div><label style="font-size:.7rem;color:var(--dm)">Min Qty</label><input type="number" id="itemMin" class="inp" style="width:100%" min="0"></div><div><label style="font-size:.7rem;color:var(--dm)">Unit</label><input id="itemUnit" class="inp" style="width:100%" value="piece"></div></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem"><div><label style="font-size:.7rem;color:var(--dm)">Location</label><input id="itemLoc" class="inp" style="width:100%"></div><div><label style="font-size:.7rem;color:var(--dm)">Condition</label><select id="itemCond" class="inp" style="width:100%"><option value="good">Good</option><option value="fair">Fair</option><option value="poor">Poor</option><option value="damaged">Damaged</option></select></div></div>
<button class="btn bp" onclick="saveItem()"><i class="fa-solid fa-save"></i> Save</button>
</div></div></div>
<!-- REQUEST MODAL -->
<div class="mo" id="reqModal"><div class="md"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h3 style="margin:0">New Request</h3><button onclick="closeModal('reqModal')" style="background:none;border:none;color:var(--dm);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div>
<div style="display:flex;flex-direction:column;gap:.75rem">
<div><label style="font-size:.7rem;color:var(--dm)">Item</label><select id="reqItem" class="inp" style="width:100%"></select></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem"><div><label style="font-size:.7rem;color:var(--dm)">Quantity</label><input type="number" id="reqQty" class="inp" style="width:100%" min="1" value="1"></div><div><label style="font-size:.7rem;color:var(--dm)">Requested By</label><input id="reqBy" class="inp" style="width:100%"></div></div>
<div><label style="font-size:.7rem;color:var(--dm)">Department</label><input id="reqDept" class="inp" style="width:100%"></div>
<div><label style="font-size:.7rem;color:var(--dm)">Reason</label><input id="reqReason" class="inp" style="width:100%"></div>
<button class="btn bp" onclick="saveReq()"><i class="fa-solid fa-save"></i> Submit</button>
</div></div></div>
<!-- CATEGORY MODAL -->
<div class="mo" id="matCatModal"><div class="md"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h3 style="margin:0">Add Category</h3><button onclick="closeModal('matCatModal')" style="background:none;border:none;color:var(--dm);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div>
<div style="display:flex;flex-direction:column;gap:.75rem">
<div><label style="font-size:.7rem;color:var(--dm)">Name</label><input id="mcName" class="inp" style="width:100%"></div>
<div><label style="font-size:.7rem;color:var(--dm)">Description</label><input id="mcDesc" class="inp" style="width:100%"></div>
<button class="btn bp" onclick="saveMatCat()"><i class="fa-solid fa-save"></i> Save</button>
</div></div></div>
<!-- BOTTOM NAV -->
<nav class="wbws-bnav" id="wbwsBottomNav">
<div class="wbws-bnav-scroll-hint-left" id="bnScrollL"></div>
<div class="wbws-bnav-scroll-hint-right visible" id="bnScrollR"></div>
<div class="wbws-bnav-inner" id="bnScroll">
<button class="wbws-bnav-btn active" data-section="dashboard"><i class="fa-solid fa-gauge-high"></i><span>Home</span></button>
<button class="wbws-bnav-btn" data-section="inventory"><i class="fa-solid fa-boxes-stacked"></i><span>Items</span></button>
<button class="wbws-bnav-btn" data-section="incoming"><i class="fa-solid fa-truck-ramp-box"></i><span>In</span></button>
<button class="wbws-bnav-btn" data-section="outgoing"><i class="fa-solid fa-dolly"></i><span>Out</span></button>
<div class="wbws-bnav-divider"></div>
<button class="wbws-bnav-btn" data-section="requests"><i class="fa-solid fa-clipboard-list"></i><span>Requests</span></button>
<button class="wbws-bnav-btn" data-section="categories"><i class="fa-solid fa-tags"></i><span>Categories</span></button>
<div class="wbws-bnav-divider"></div>
<a href="/admin/logout.php" class="wbws-bnav-btn bnav-exit"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
</div></nav>
<script>(function(){const sc=document.getElementById('bnScroll'),sl=document.getElementById('bnScrollL'),sr=document.getElementById('bnScrollR');if(!sc)return;function upd(){sl.classList.toggle('visible',sc.scrollLeft>10);sr.classList.toggle('visible',sc.scrollLeft<sc.scrollWidth-sc.clientWidth-10);}sc.addEventListener('scroll',upd,{passive:true});setTimeout(upd,100);sc.querySelectorAll('.wbws-bnav-btn[data-section]').forEach(b=>{b.addEventListener('click',function(){const s=this.dataset.section;if(typeof nav==='function')nav(s);sc.querySelectorAll('.wbws-bnav-btn').forEach(x=>x.classList.remove('active'));this.classList.add('active');});});})();</script>
<div class="toast" id="toast"></div>
<script>
let cats=[],items=[];const API='/admin/api_material.php';
const CSRF='<?=$csrfToken?>';
function postAPI(fd){fd.append('csrf_token',CSRF);return fetch(API,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json());}
function nav(n){document.querySelectorAll('.cs').forEach(s=>s.classList.remove('active'));const t=document.getElementById('section-'+n);if(t)t.classList.add('active');document.querySelectorAll('aside .np').forEach(b=>b.classList.remove('active'));document.querySelectorAll('aside [data-section="'+n+'"]').forEach(b=>b.classList.add('active'));document.querySelectorAll('.bn button').forEach(b=>b.classList.remove('active'));document.querySelectorAll('.bn [data-section="'+n+'"]').forEach(b=>b.classList.add('active'));if(n==='inventory')loadItems();if(n==='requests')loadReqs();if(n==='categories')loadMatCats();if(n==='incoming'||n==='outgoing')populateItemSelects();const _u=new URL(window.location);_u.searchParams.set('section',n);history.replaceState(null,'',_u);}
document.querySelectorAll('[data-section]').forEach(el=>{el.addEventListener('click',function(e){e.preventDefault();const n=this.getAttribute('data-section');if(n)nav(n);});});
{const _sp=new URLSearchParams(window.location.search).get('section');if(_sp)nav(_sp);}

async function loadDash(){try{const r=await fetch(API+'?action=dashboard',{credentials:'same-origin'});const d=await r.json();if(d.status==='success'&&d.data){const dt=d.data;document.getElementById('lowStockList').innerHTML=(dt.low_stock_items||[]).length?dt.low_stock_items.map(i=>`<div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--cb);font-size:.8rem"><span>${esc(i.name)}</span><span class="bg bg-w">${i.quantity} left</span></div>`).join(''):'<p style="color:var(--dm);font-size:.8rem">All stocked! ✓</p>';document.getElementById('recentList').innerHTML=(dt.recent||[]).length?dt.recent.slice(0,8).map(t=>`<div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--cb);font-size:.75rem"><span>${esc(t.item_name||'Item #'+t.item_id)}</span><span class="${t.type==='incoming'?'bg bg-ok':'bg bg-bd'}">${t.type} ×${t.quantity}</span></div>`).join(''):'<p style="color:var(--dm);font-size:.8rem">No activity yet</p>';}}catch(e){}}

async function loadMatCats(){try{const r=await fetch(API+'?action=categories',{credentials:'same-origin'});const d=await r.json();if(d.status==='success'){cats=d.categories||[];renderMatCats();populateCatDropdowns();}}catch(e){}}
function renderMatCats(){document.getElementById('matCatList').innerHTML=cats.length?cats.map(c=>`<div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid var(--cb);font-size:.8rem"><span style="font-weight:600">${esc(c.name)}</span><span style="color:var(--dm);font-size:.7rem">${esc(c.description||'')}</span></div>`).join(''):'<p style="color:var(--dm)">No categories yet</p>';}
function populateCatDropdowns(){const opts=cats.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');['invCat','itemCat'].forEach(id=>{const s=document.getElementById(id);if(s){const v=s.value;s.innerHTML='<option value="">All</option>'+opts;s.value=v;}});}
function openMatCatModal(){document.getElementById('matCatModal').classList.add('show');}
async function saveMatCat(){const nm=document.getElementById('mcName').value.trim(),ds=document.getElementById('mcDesc').value.trim();if(!nm)return toast('Name required','e');const fd=new FormData();fd.append('action','save_category');fd.append('name',nm);fd.append('description',ds);try{const d=await postAPI(fd);if(d.status==='success'){toast('Saved!','s');closeModal('matCatModal');loadMatCats();document.getElementById('mcName').value='';document.getElementById('mcDesc').value='';}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}

async function loadItems(){const s=document.getElementById('invSearch')?.value||'',c=document.getElementById('invCat')?.value||'',st=document.getElementById('invStatus')?.value||'';try{const r=await fetch(API+`?action=items&search=${encodeURIComponent(s)}&category_id=${c}&status=${st}`,{credentials:'same-origin'});const d=await r.json();if(d.status==='success'){items=d.items||[];renderItems();}}catch(e){}}
function renderItems(){document.getElementById('invBody').innerHTML=items.length?items.map(i=>`<tr><td style="font-weight:600">${esc(i.name)}</td><td>${esc(i.category_name||'—')}</td><td style="font-weight:700;color:${i.quantity<=0?'var(--bd)':i.quantity<=(i.min_quantity||0)?'var(--wn)':'var(--ok)'}">${i.quantity}</td><td>${i.min_quantity||0}</td><td>${esc(i.unit||'—')}</td><td>${esc(i.location||'—')}</td><td>${esc(i.condition_status||'—')}</td><td>${stBg(i.status)}</td><td style="display:flex;gap:.25rem"><button class="btn bo bs" onclick="editItem(${i.id})" title="Edit"><i class="fa-solid fa-pen"></i></button><button class="btn bo bs" onclick="delItem(${i.id})" title="Delete"><i class="fa-solid fa-trash" style="color:var(--bd)"></i></button></td></tr>`).join(''):'<tr><td colspan="9" style="text-align:center;color:var(--dm);padding:1.5rem">No items. Click Add Item to start.</td></tr>';}
function openItemModal(data){document.getElementById('itemId').value=data?.id||0;document.getElementById('itemName').value=data?.name||'';document.getElementById('itemCat').value=data?.category_id||'';document.getElementById('itemDesc').value=data?.description||'';document.getElementById('itemQty').value=data?.quantity||0;document.getElementById('itemMin').value=data?.min_quantity||0;document.getElementById('itemUnit').value=data?.unit||'piece';document.getElementById('itemLoc').value=data?.location||'';document.getElementById('itemCond').value=data?.condition_status||'good';populateCatDropdowns();if(data?.category_id)document.getElementById('itemCat').value=data.category_id;document.getElementById('itemModal').classList.add('show');}
function editItem(id){const i=items.find(x=>x.id==id);if(i)openItemModal(i);}
async function saveItem(){const id=document.getElementById('itemId').value,nm=document.getElementById('itemName').value.trim();if(!nm)return toast('Name required','e');const fd=new FormData();fd.append('action','save_item');fd.append('id',id);fd.append('name',nm);fd.append('category_id',document.getElementById('itemCat').value);fd.append('description',document.getElementById('itemDesc').value);fd.append('quantity',document.getElementById('itemQty').value);fd.append('min_quantity',document.getElementById('itemMin').value);fd.append('unit',document.getElementById('itemUnit').value);fd.append('location',document.getElementById('itemLoc').value);fd.append('condition_status',document.getElementById('itemCond').value);try{const d=await postAPI(fd);if(d.status==='success'){toast('Saved!','s');closeModal('itemModal');loadItems();loadDash();}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}
async function delItem(id){if(!confirm('Delete this item?'))return;const fd=new FormData();fd.append('action','delete_item');fd.append('id',id);try{const d=await postAPI(fd);if(d.status==='success'){toast('Deleted','s');loadItems();}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}
function populateItemSelects(){const opts=items.length?items.map(i=>`<option value="${i.id}">${esc(i.name)} (${i.quantity} ${i.unit||''})</option>`).join(''):'';['inItem','outItem','reqItem'].forEach(id=>{const s=document.getElementById(id);if(s)s.innerHTML='<option value="">Select item...</option>'+opts;});}
async function addTxn(type){const pfx=type==='incoming'?'in':'out';const itemId=document.getElementById(pfx+'Item').value,qty=document.getElementById(pfx+'Qty').value,date=document.getElementById(pfx+'Date').value,reason=document.getElementById(pfx+'Reason').value,handler=document.getElementById(pfx+'Handler').value;if(!itemId||!qty)return toast('Select item and quantity','e');const fd=new FormData();fd.append('action','add_transaction');fd.append('item_id',itemId);fd.append('type',type);fd.append('quantity',qty);fd.append('transaction_date',date||new Date().toISOString().slice(0,10));fd.append('reason',reason);fd.append('handled_by',handler);try{const d=await postAPI(fd);if(d.status==='success'){toast('Recorded!','s');[pfx+'Qty',pfx+'Reason',pfx+'Handler'].forEach(id=>document.getElementById(id).value='');loadItems();loadDash();}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}

async function loadReqs(){try{const r=await fetch(API+'?action=requests',{credentials:'same-origin'});const d=await r.json();if(d.status==='success'){const reqs=d.requests||[];document.getElementById('reqBody').innerHTML=reqs.length?reqs.map(q=>`<tr><td>${esc(q.item_name||q.item_name_ref||'—')}</td><td>${q.quantity}</td><td>${esc(q.requested_by)}</td><td>${esc(q.department||'—')}</td><td style="max-width:150px;overflow:hidden;text-overflow:ellipsis">${esc(q.reason||'—')}</td><td>${reqBg(q.status)}</td><td style="font-size:.7rem">${fDate(q.created_at)}</td><td>${q.status==='pending'?`<button class="btn bs" style="background:var(--ok);color:#fff" onclick="updateReq(${q.id},'approved')"><i class="fa-solid fa-check"></i></button> <button class="btn bs" style="background:var(--bd);color:#fff" onclick="updateReq(${q.id},'denied')"><i class="fa-solid fa-xmark"></i></button>`:''}</td></tr>`).join(''):'<tr><td colspan="8" style="text-align:center;color:var(--dm);padding:1.5rem">No requests</td></tr>';}}catch(e){}}
function openReqModal(){if(!items.length)loadItems().then(()=>populateItemSelects());else populateItemSelects();document.getElementById('reqModal').classList.add('show');}
async function saveReq(){const fd=new FormData();fd.append('action','save_request');fd.append('item_id',document.getElementById('reqItem').value);fd.append('item_name','');fd.append('quantity',document.getElementById('reqQty').value);fd.append('requested_by',document.getElementById('reqBy').value);fd.append('department',document.getElementById('reqDept').value);fd.append('reason',document.getElementById('reqReason').value);try{const d=await postAPI(fd);if(d.status==='success'){toast('Request submitted!','s');closeModal('reqModal');loadReqs();}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}
async function updateReq(id,st){const fd=new FormData();fd.append('action','update_request');fd.append('id',id);fd.append('status',st);try{const d=await postAPI(fd);if(d.status==='success'){toast(st==='approved'?'Approved':'Denied','s');loadReqs();}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}

function exportItems(){if(!items.length)return toast('No data','e');const h=['Name','Category','Qty','Min','Unit','Location','Condition','Status'];const rows=items.map(i=>[i.name,i.category_name||'',i.quantity,i.min_quantity,i.unit||'',i.location||'',i.condition_status||'',i.status]);const ws=XLSX.utils.aoa_to_sheet([h,...rows]);ws['!cols']=h.map(()=>({wch:16}));const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Inventory');XLSX.writeFile(wb,'<?= MEMBER_CODE_PREFIX ?>_Inventory_'+new Date().toISOString().slice(0,10)+'.xlsx');}

function closeModal(id){document.getElementById(id).classList.remove('show');}
function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function toast(m,t){const el=document.getElementById('toast');el.className='toast '+(t==='s'?'t-ok':'t-err')+' show';el.innerHTML=`<i class="fa-solid fa-${t==='s'?'check-circle':'exclamation-circle'}"></i> ${m}`;setTimeout(()=>el.classList.remove('show'),3000);}
function fDate(d){if(!d)return'—';if(typeof WBWSCalendar!=='undefined')return WBWSCalendar.formatDate(d,'medium');try{return new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d;}}
function stBg(s){const m={in_stock:'bg-ok',low_stock:'bg-w',out_of_stock:'bg-bd',maintenance:'bg-in'};return`<span class="bg ${m[s]||'bg-in'}">${(s||'').replace(/_/g,' ')}</span>`;}
function reqBg(s){const m={pending:'bg-w',approved:'bg-ok',denied:'bg-bd',fulfilled:'bg-in'};return`<span class="bg ${m[s]||'bg-in'}">${s||'—'}</span>`;}

document.addEventListener('DOMContentLoaded',()=>{loadDash();loadMatCats();loadItems().then(()=>populateItemSelects());
    const t=new Date().toISOString().slice(0,10);['inDate','outDate'].forEach(id=>{const e=document.getElementById(id);if(e)e.value=t;});
});
</script>
</body></html>
