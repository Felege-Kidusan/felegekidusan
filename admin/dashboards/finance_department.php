<?php
/**
 * Finance Department Dashboard — <?= SCHOOL_NAME ?>
 * Full CRUD: Transactions, Categories, Member Fees, Reports, Export
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';

$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Finance Admin';
$username = $_SESSION['admin_username'] ?? '';
$csrfToken = generateCsrfToken();
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');
$greeting = ((int)$today->format('H') < 12) ? 'Good Morning' : (((int)$today->format('H') < 17) ? 'Good Afternoon' : 'Good Evening');
$initials = strtoupper(substr($fullName, 0, 1));
$ecYear = (int)ethio_date_format($today, 'Y');

$finReady = false;
$fStats = ['income'=>0,'expense'=>0,'balance'=>0,'month_in'=>0,'month_ex'=>0,'pending'=>0];
if (isset($conn)) {
    try {
        $conn->query("SELECT 1 FROM finance_transactions LIMIT 0");
        $finReady = true;
        $r=$conn->query("SELECT COALESCE(SUM(CASE WHEN type='income' AND status='confirmed' THEN amount END),0) i, COALESCE(SUM(CASE WHEN type='expense' AND status='confirmed' THEN amount END),0) e FROM finance_transactions");
        if($r&&$row=$r->fetch_assoc()){$fStats['income']=(float)$row['i'];$fStats['expense']=(float)$row['e'];$fStats['balance']=$fStats['income']-$fStats['expense'];}
        $m1=date('Y-m-01');$m2=date('Y-m-t');
        $r=$conn->query("SELECT COALESCE(SUM(CASE WHEN type='income' AND status='confirmed' THEN amount END),0) i, COALESCE(SUM(CASE WHEN type='expense' AND status='confirmed' THEN amount END),0) e FROM finance_transactions WHERE transaction_date BETWEEN '$m1' AND '$m2'");
        if($r&&$row=$r->fetch_assoc()){$fStats['month_in']=(float)$row['i'];$fStats['month_ex']=(float)$row['e'];}
        $r=$conn->query("SELECT COUNT(*) c FROM finance_transactions WHERE status='pending'");
        if($r&&$row=$r->fetch_assoc())$fStats['pending']=(int)$row['c'];
    } catch(Exception $e) { $finReady = false; }
}
$activeMembers = 0;
if(isset($conn)){$r=$conn->query("SELECT COUNT(*) c FROM members WHERE status='active'");if($r&&$row=$r->fetch_assoc())$activeMembers=(int)$row['c'];}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>Finance — <?= SCHOOL_NAME_SHORT ?></title>
<?= wbws_calendar_scripts($conn) ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⛪</text></svg>">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',system-ui,sans-serif}
:root{--ac:#f59e0b;--ac2:#d97706;--bg:#0f172a;--sb:#020617;--cd:rgba(255,255,255,0.04);--cb:rgba(255,255,255,0.08);--tx:#e5e7eb;--dm:#94a3b8;--ok:#22c55e;--wn:#fbbf24;--bd:#ef4444;--in:#0ea5e9}
body{min-height:100vh;display:flex;background:var(--bg);color:var(--tx)}
aside{width:260px;background:linear-gradient(180deg,var(--sb),var(--bg));padding:1.5rem 1.25rem;display:flex;flex-direction:column;gap:1.5rem;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0;z-index:20}
.brand{display:flex;align-items:center;gap:.75rem}.bl{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;box-shadow:0 8px 20px rgba(245,158,11,0.4)}
.nt{font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:var(--dm);padding:.5rem .75rem}
.np{display:flex;align-items:center;gap:.75rem;padding:.65rem .75rem;border-radius:10px;color:var(--dm);text-decoration:none;font-size:.85rem;cursor:pointer;transition:all .2s;border:none;background:none;width:100%;text-align:left}.np:hover,.np.active{background:rgba(255,255,255,0.08);color:#f1f5f9}.np.active{background:rgba(245,158,11,0.15);color:var(--ac);font-weight:600}.np i{width:20px;text-align:center}
.uc{margin-top:auto;display:flex;align-items:center;gap:.75rem;padding:.75rem;border-radius:12px;background:var(--cd);border:1px solid var(--cb)}.ua{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--ac),var(--ac2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff}
main{flex:1;padding:1.5rem 2rem 6rem;overflow-y:auto;max-width:calc(100vw - 260px)}
.cs{display:none}.cs.active{display:block}
.sg{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
.sc{background:var(--cd);border:1px solid var(--cb);border-radius:16px;padding:1.25rem;transition:all .2s}.sc:hover{transform:translateY(-2px)}.sc .ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.75rem}.sc .val{font-size:1.5rem;font-weight:700;color:#f1f5f9}.sc .lbl{font-size:.72rem;color:var(--dm);margin-top:.25rem}
.crd{background:var(--cd);border:1px solid var(--cb);border-radius:16px;padding:1.25rem;margin-bottom:1.25rem}.crt{font-size:1rem;font-weight:600;color:#f1f5f9;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.tw{overflow-x:auto;border-radius:12px;border:1px solid var(--cb)}table{width:100%;border-collapse:collapse;font-size:.8rem}thead{background:rgba(255,255,255,0.04)}th{padding:.7rem .8rem;text-align:left;color:var(--dm);font-weight:600;font-size:.7rem;text-transform:uppercase;white-space:nowrap}td{padding:.6rem .8rem;border-top:1px solid var(--cb);white-space:nowrap}tr:hover td{background:rgba(255,255,255,0.02)}
.inp{background:rgba(255,255,255,0.06);border:1px solid var(--cb);border-radius:10px;padding:.55rem .85rem;color:#f1f5f9;font-size:.8rem;outline:none}.inp:focus{border-color:var(--ac)}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:10px;font-size:.8rem;font-weight:600;cursor:pointer;border:none;transition:all .2s}.bp{background:linear-gradient(135deg,var(--ac),var(--ac2));color:#fff}.bo{background:transparent;border:1px solid var(--cb);color:var(--tx)}.bo:hover{border-color:var(--ac);color:var(--ac)}.bs{padding:.35rem .75rem;font-size:.72rem}.bk{background:var(--ok);color:#fff}.br{background:var(--bd);color:#fff}
.bg{display:inline-flex;padding:.2rem .55rem;border-radius:99px;font-size:.65rem;font-weight:600}.bg-ok{background:rgba(34,197,94,.15);color:#22c55e}.bg-w{background:rgba(251,191,36,.15);color:#fbbf24}.bg-bd{background:rgba(239,68,68,.15);color:#ef4444}.bg-in{background:rgba(14,165,233,.15);color:#0ea5e9}
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
<div class="brand"><div class="bl"><i class="fa-solid fa-coins"></i></div><div><span style="font-size:.95rem;font-weight:600;color:#f1f5f9"><?= SCHOOL_NAME_SHORT ?> Finance</span><br><span style="font-size:.7rem;color:var(--dm)"><?= DEPT_FINANCE_NAME ?></span></div></div>
<div><div class="nt">Finance</div>
<button class="np active" data-section="dashboard"><i class="fa-solid fa-gauge-high"></i> Overview</button>
<button class="np" data-section="income"><i class="fa-solid fa-arrow-trend-up"></i> Income</button>
<button class="np" data-section="expense"><i class="fa-solid fa-arrow-trend-down"></i> Expenses</button>
<button class="np" data-section="fees"><i class="fa-solid fa-hand-holding-dollar"></i> Member Fees</button>
<button class="np" data-section="categories"><i class="fa-solid fa-tags"></i> Categories</button>
<button class="np" data-section="reports"><i class="fa-solid fa-chart-line"></i> Reports</button>
</div>
<div class="uc"><div class="ua"><?= $initials ?></div><div><span style="font-size:.8rem;font-weight:600;color:#f1f5f9"><?= e($fullName) ?></span><br><span style="font-size:.65rem;color:var(--dm)">Finance • <?= $todayFormatted ?></span></div></div>
<a href="/admin/logout.php" class="np" style="color:var(--bd)"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
</aside>
<main>
<!-- Mobile Header -->
<div class="wbws-mob-header">
    <a href="/admin/dashboard.php" class="mob-back"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="mob-title">
        <h1>Finance Dept</h1>
        <p class="mob-sub"><?= $todayFormatted ?></p>
    </div>
    <div class="mob-avatar"><?= $initials ?></div>
</div>
<?php if (!$finReady): ?>
<div class="crd" style="text-align:center;padding:3rem"><i class="fa-solid fa-database" style="font-size:3rem;color:var(--ac);margin-bottom:1rem"></i><h2 style="color:#f1f5f9;margin-bottom:.5rem">Setup Required</h2><p style="color:var(--dm);margin-bottom:1.5rem">Finance tables need to be created.</p><a href="/admin/migrations/004_add_finance_material_tables.php" class="btn bp"><i class="fa-solid fa-play"></i> Run Migration 004</a></div>
<?php else: ?>
<!-- DASHBOARD -->
<div id="section-dashboard" class="cs active">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.5rem"><div><h1 style="font-size:1.4rem;font-weight:700;color:#f1f5f9"><?= $greeting ?>, <?= e(explode(' ',$fullName)[0]) ?> 💰</h1><p style="color:var(--dm);font-size:.8rem"><?= $todayFormatted ?></p></div>
<div style="display:flex;gap:.5rem" class="no-print"><button class="btn bo bs" onclick="nav('income');openAddTxn('income')"><i class="fa-solid fa-plus" style="color:var(--ok)"></i> Income</button><button class="btn bo bs" onclick="nav('expense');openAddTxn('expense')"><i class="fa-solid fa-minus" style="color:var(--bd)"></i> Expense</button></div></div>
<div class="sg">
<div class="sc"><div class="ico" style="background:rgba(34,197,94,.15);color:var(--ok)"><i class="fa-solid fa-sack-dollar"></i></div><div class="val" style="color:var(--ok)"><?= number_format($fStats['income'],2) ?></div><div class="lbl">Total Income (ETB)</div></div>
<div class="sc"><div class="ico" style="background:rgba(239,68,68,.15);color:var(--bd)"><i class="fa-solid fa-money-bill-transfer"></i></div><div class="val" style="color:var(--bd)"><?= number_format($fStats['expense'],2) ?></div><div class="lbl">Total Expense (ETB)</div></div>
<div class="sc"><div class="ico" style="background:rgba(14,165,233,.15);color:var(--in)"><i class="fa-solid fa-wallet"></i></div><div class="val" style="color:<?= $fStats['balance']>=0?'var(--ok)':'var(--bd)' ?>"><?= number_format($fStats['balance'],2) ?></div><div class="lbl">Balance</div></div>
<div class="sc"><div class="ico" style="background:rgba(245,158,11,.15);color:var(--ac)"><i class="fa-solid fa-calendar"></i></div><div class="val"><?= number_format($fStats['month_in'],2) ?></div><div class="lbl">This Month</div></div>
<div class="sc"><div class="ico" style="background:rgba(168,85,247,.15);color:#a855f7"><i class="fa-solid fa-clock"></i></div><div class="val"><?= $fStats['pending'] ?></div><div class="lbl">Pending</div></div>
<div class="sc"><div class="ico" style="background:rgba(14,165,233,.15);color:var(--in)"><i class="fa-solid fa-users"></i></div><div class="val"><?= $activeMembers ?></div><div class="lbl">Members</div></div>
</div>
<div class="crd"><div class="crt"><i class="fa-solid fa-clock-rotate-left" style="color:var(--ac)"></i> Recent Transactions</div><div class="tw"><table><thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead><tbody id="recentBody"><tr><td colspan="6" style="text-align:center;color:var(--dm);padding:1rem">Loading...</td></tr></tbody></table></div></div>
</div>
<!-- INCOME -->
<div id="section-income" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem"><h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9"><i class="fa-solid fa-arrow-trend-up" style="color:var(--ok)"></i> Income</h2><div style="display:flex;gap:.5rem" class="no-print"><button class="btn bp bs" onclick="openAddTxn('income')"><i class="fa-solid fa-plus"></i> Add</button><button class="btn bo bs" onclick="exportTxns('income')"><i class="fa-solid fa-download"></i> Export</button></div></div>
<div class="tw"><table><thead><tr><th>Date</th><th>Category</th><th>Member</th><th>Description</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Actions</th></tr></thead><tbody id="incBody"></tbody></table></div>
</div>
<!-- EXPENSE -->
<div id="section-expense" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem"><h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9"><i class="fa-solid fa-arrow-trend-down" style="color:var(--bd)"></i> Expenses</h2><div style="display:flex;gap:.5rem" class="no-print"><button class="btn bp bs" onclick="openAddTxn('expense')"><i class="fa-solid fa-plus"></i> Add</button><button class="btn bo bs" onclick="exportTxns('expense')"><i class="fa-solid fa-download"></i> Export</button></div></div>
<div class="tw"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Status</th><th>Actions</th></tr></thead><tbody id="expBody"></tbody></table></div>
</div>
<!-- FEES -->
<div id="section-fees" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9"><i class="fa-solid fa-hand-holding-dollar" style="color:var(--ac)"></i> Member Fees</h2><button class="btn bp bs" onclick="openFeeModal()"><i class="fa-solid fa-plus"></i> Record</button></div>
<div class="crd no-print"><div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem"><div><label style="font-size:.65rem;color:var(--dm)">Status</label><select id="feeStatus" class="inp" style="width:100%" onchange="loadFees()"><option value="">All</option><option value="paid">Paid</option><option value="unpaid">Unpaid</option></select></div><div><label style="font-size:.65rem;color:var(--dm)">EC Year</label><input type="number" id="feeYear" class="inp" style="width:100%" value="<?= $ecYear ?>" onchange="loadFees()"></div></div></div>
<div class="tw"><table><thead><tr><th>Member</th><th>Code</th><th>Type</th><th>Amount</th><th>Month</th><th>Year</th><th>Status</th><th>Paid</th></tr></thead><tbody id="feesBody"></tbody></table></div>
</div>
<!-- CATEGORIES -->
<div id="section-categories" class="cs">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9"><i class="fa-solid fa-tags" style="color:var(--ac)"></i> Categories</h2><button class="btn bp bs" onclick="openCatModal()"><i class="fa-solid fa-plus"></i> Add</button></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem"><div class="crd"><div class="crt" style="color:var(--ok)"><i class="fa-solid fa-arrow-up"></i> Income</div><div id="incCats"></div></div><div class="crd"><div class="crt" style="color:var(--bd)"><i class="fa-solid fa-arrow-down"></i> Expense</div><div id="expCats"></div></div></div>
</div>
<!-- REPORTS -->
<div id="section-reports" class="cs">
<h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9;margin-bottom:1rem"><i class="fa-solid fa-chart-line" style="color:var(--ac)"></i> Financial Reports</h2>
<div class="crd no-print"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem"><div><label style="font-size:.65rem;color:var(--dm)">From</label><input type="date" id="rptFrom" class="inp" style="width:100%"></div><div><label style="font-size:.65rem;color:var(--dm)">To</label><input type="date" id="rptTo" class="inp" style="width:100%"></div><div style="display:flex;align-items:flex-end;gap:.5rem"><button class="btn bp bs" onclick="loadReport()"><i class="fa-solid fa-search"></i> Generate</button><button class="btn bo bs" onclick="exportReport()"><i class="fa-solid fa-download"></i> Export</button></div></div></div>
<div class="sg" id="rptStats"></div>
<div class="crd" id="rptDetail" style="display:none"><div class="crt">By Category</div><div class="tw"><table><thead><tr><th>Category</th><th>Type</th><th>Total (ETB)</th><th>Count</th></tr></thead><tbody id="rptBody"></tbody></table></div></div>
</div>
<?php endif; ?>
</main>
<!-- ADD TRANSACTION MODAL -->
<div class="mo" id="txnModal"><div class="md">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h3 id="txnTitle" style="margin:0">Add Transaction</h3><button onclick="closeModal('txnModal')" style="background:none;border:none;color:var(--dm);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div>
<div style="display:flex;flex-direction:column;gap:.75rem">
<input type="hidden" id="txnType" value="income">
<div><label style="font-size:.7rem;color:var(--dm)">Category</label><select id="txnCat" class="inp" style="width:100%"></select></div>
<div><label style="font-size:.7rem;color:var(--dm)">Amount (ETB)</label><input type="number" id="txnAmt" class="inp" style="width:100%" step="0.01" min="0"></div>
<div><label style="font-size:.7rem;color:var(--dm)">Description</label><input id="txnDesc" class="inp" style="width:100%"></div>
<div><label style="font-size:.7rem;color:var(--dm)">Date</label><input type="date" id="txnDate" class="inp" style="width:100%"></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem"><div><label style="font-size:.7rem;color:var(--dm)">Payment Method</label><select id="txnMethod" class="inp" style="width:100%"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="mobile_money">Mobile Money</option><option value="check">Check</option></select></div><div><label style="font-size:.7rem;color:var(--dm)">Receipt #</label><input id="txnReceipt" class="inp" style="width:100%"></div></div>
<div id="txnMemberWrap"><label style="font-size:.7rem;color:var(--dm)">Member (optional)</label><select id="txnMember" class="inp" style="width:100%"><option value="">— None —</option></select></div>
<button class="btn bp" onclick="saveTxn()"><i class="fa-solid fa-save"></i> Save</button>
</div></div></div>
<!-- FEE MODAL -->
<div class="mo" id="feeModal"><div class="md">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h3 style="margin:0">Record Fee Payment</h3><button onclick="closeModal('feeModal')" style="background:none;border:none;color:var(--dm);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div>
<div style="display:flex;flex-direction:column;gap:.75rem">
<div><label style="font-size:.7rem;color:var(--dm)">Member</label><select id="feeMember" class="inp" style="width:100%"></select></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem"><div><label style="font-size:.7rem;color:var(--dm)">Amount (ETB)</label><input type="number" id="feeAmt" class="inp" style="width:100%" step="0.01" min="0"></div><div><label style="font-size:.7rem;color:var(--dm)">Fee Type</label><select id="feeType" class="inp" style="width:100%"><option value="monthly">Monthly</option><option value="annual">Annual</option><option value="special">Special</option></select></div></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem"><div><label style="font-size:.7rem;color:var(--dm)">EC Month (1-13)</label><input type="number" id="feeMonth" class="inp" style="width:100%" min="1" max="13"></div><div><label style="font-size:.7rem;color:var(--dm)">EC Year</label><input type="number" id="feeEcYear" class="inp" style="width:100%" value="<?= $ecYear ?>"></div></div>
<div><label style="font-size:.7rem;color:var(--dm)">Status</label><select id="feePayStatus" class="inp" style="width:100%"><option value="paid">Paid</option><option value="unpaid">Unpaid</option><option value="partial">Partial</option></select></div>
<button class="btn bp" onclick="saveFee()"><i class="fa-solid fa-save"></i> Save</button>
</div></div></div>
<!-- CATEGORY MODAL -->
<div class="mo" id="catModal"><div class="md">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><h3 style="margin:0">Add Category</h3><button onclick="closeModal('catModal')" style="background:none;border:none;color:var(--dm);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button></div>
<div style="display:flex;flex-direction:column;gap:.75rem">
<div><label style="font-size:.7rem;color:var(--dm)">Name</label><input id="catName" class="inp" style="width:100%"></div>
<div><label style="font-size:.7rem;color:var(--dm)">Type</label><select id="catType" class="inp" style="width:100%"><option value="income">Income</option><option value="expense">Expense</option></select></div>
<div><label style="font-size:.7rem;color:var(--dm)">Description</label><input id="catDesc" class="inp" style="width:100%"></div>
<button class="btn bp" onclick="saveCat()"><i class="fa-solid fa-save"></i> Save</button>
</div></div></div>
<!-- BOTTOM NAV -->
<nav class="wbws-bnav" id="wbwsBottomNav">
<div class="wbws-bnav-scroll-hint-left" id="bnScrollL"></div>
<div class="wbws-bnav-scroll-hint-right visible" id="bnScrollR"></div>
<div class="wbws-bnav-inner" id="bnScroll">
<button class="wbws-bnav-btn active" data-section="dashboard"><i class="fa-solid fa-gauge-high"></i><span>Home</span></button>
<button class="wbws-bnav-btn" data-section="income"><i class="fa-solid fa-arrow-trend-up"></i><span>Income</span></button>
<button class="wbws-bnav-btn" data-section="expense"><i class="fa-solid fa-arrow-trend-down"></i><span>Expense</span></button>
<button class="wbws-bnav-btn" data-section="fees"><i class="fa-solid fa-hand-holding-dollar"></i><span>Fees</span></button>
<div class="wbws-bnav-divider"></div>
<button class="wbws-bnav-btn" data-section="categories"><i class="fa-solid fa-tags"></i><span>Categories</span></button>
<button class="wbws-bnav-btn" data-section="reports"><i class="fa-solid fa-chart-line"></i><span>Reports</span></button>
<div class="wbws-bnav-divider"></div>
<a href="/admin/logout.php" class="wbws-bnav-btn bnav-exit"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
</div></nav>
<script>(function(){const sc=document.getElementById('bnScroll'),sl=document.getElementById('bnScrollL'),sr=document.getElementById('bnScrollR');if(!sc)return;function upd(){sl.classList.toggle('visible',sc.scrollLeft>10);sr.classList.toggle('visible',sc.scrollLeft<sc.scrollWidth-sc.clientWidth-10);}sc.addEventListener('scroll',upd,{passive:true});setTimeout(upd,100);sc.querySelectorAll('.wbws-bnav-btn[data-section]').forEach(b=>{b.addEventListener('click',function(){const s=this.dataset.section;if(typeof nav==='function')nav(s);sc.querySelectorAll('.wbws-bnav-btn').forEach(x=>x.classList.remove('active'));this.classList.add('active');});});})();</script>
<div class="toast" id="toast"></div>
<script>
let cats=[],members=[],txns={income:[],expense:[]};
const API='/admin/api_finance.php';
const CSRF='<?=$csrfToken?>';
function postAPI(fd){fd.append('csrf_token',CSRF);return fetch(API,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json());}

function nav(n){document.querySelectorAll('.cs').forEach(s=>s.classList.remove('active'));const t=document.getElementById('section-'+n);if(t)t.classList.add('active');document.querySelectorAll('aside .np').forEach(b=>b.classList.remove('active'));document.querySelectorAll('aside [data-section="'+n+'"]').forEach(b=>b.classList.add('active'));document.querySelectorAll('.bn button').forEach(b=>b.classList.remove('active'));document.querySelectorAll('.bn [data-section="'+n+'"]').forEach(b=>b.classList.add('active'));if(n==='income')loadTxns('income');if(n==='expense')loadTxns('expense');if(n==='fees')loadFees();if(n==='categories')loadCats();if(n==='reports')initReport();const _u=new URL(window.location);_u.searchParams.set('section',n);history.replaceState(null,'',_u);}
document.querySelectorAll('[data-section]').forEach(el=>{el.addEventListener('click',function(e){e.preventDefault();const n=this.getAttribute('data-section');if(n)nav(n);});});
{const _sp=new URLSearchParams(window.location.search).get('section');if(_sp)nav(_sp);}

async function loadDashboard(){try{const r=await fetch(API+'?action=dashboard',{credentials:'same-origin'});const d=await r.json();if(d.status==='success'&&d.data?.recent){document.getElementById('recentBody').innerHTML=d.data.recent.map(t=>`<tr><td style="font-size:.7rem">${fDate(t.transaction_date)}</td><td>${t.type==='income'?'<span class="bg bg-ok">Income</span>':'<span class="bg bg-bd">Expense</span>'}</td><td>${esc(t.category_name||'—')}</td><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis">${esc(t.description||'—')}</td><td style="font-weight:600;color:${t.type==='income'?'var(--ok)':'var(--bd)'}">${num(t.amount)}</td><td>${stBg(t.status)}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:var(--dm)">No transactions yet</td></tr>';}}catch(e){}}

async function loadCats(){try{const r=await fetch(API+'?action=categories',{credentials:'same-origin'});const d=await r.json();if(d.status==='success'){cats=d.categories||[];renderCats();}}catch(e){}}
function renderCats(){const inc=cats.filter(c=>c.type==='income'),exp=cats.filter(c=>c.type==='expense');document.getElementById('incCats').innerHTML=inc.map(c=>`<div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--cb);font-size:.8rem"><span>${esc(c.name)}</span><span style="color:var(--dm);font-size:.7rem">${esc(c.description||'')}</span></div>`).join('')||'<p style="color:var(--dm);font-size:.8rem">No categories</p>';document.getElementById('expCats').innerHTML=exp.map(c=>`<div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--cb);font-size:.8rem"><span>${esc(c.name)}</span><span style="color:var(--dm);font-size:.7rem">${esc(c.description||'')}</span></div>`).join('')||'<p style="color:var(--dm);font-size:.8rem">No categories</p>';}

async function loadTxns(type){try{const r=await fetch(API+`?action=transactions&type=${type}`,{credentials:'same-origin'});const d=await r.json();if(d.status==='success'){txns[type]=d.transactions||[];renderTxns(type);}}catch(e){}}
function renderTxns(type){const id=type==='income'?'incBody':'expBody';const tb=document.getElementById(id);if(!txns[type].length){tb.innerHTML=`<tr><td colspan="8" style="text-align:center;color:var(--dm);padding:1.5rem">No ${type} records yet. Click Add to create one.</td></tr>`;return;}tb.innerHTML=txns[type].map(t=>`<tr><td style="font-size:.7rem">${fDate(t.transaction_date)}</td><td>${esc(t.category_name||'—')}</td>${type==='income'?`<td>${esc(t.member_name||'—')}</td>`:''}<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis">${esc(t.description||'—')}</td><td style="font-weight:600;color:${type==='income'?'var(--ok)':'var(--bd)'}">${num(t.amount)}</td><td>${esc(t.payment_method||'—')}</td><td>${esc(t.receipt_number||'—')}</td>${type==='expense'?`<td>${stBg(t.status)}</td>`:''}<td><button class="btn bo bs" onclick="deleteTxn(${t.id},'${type}')" title="Delete"><i class="fa-solid fa-trash" style="color:var(--bd)"></i></button></td></tr>`).join('');}

function openAddTxn(type){document.getElementById('txnType').value=type;document.getElementById('txnTitle').textContent='Add '+(type==='income'?'Income':'Expense');document.getElementById('txnDate').value=new Date().toISOString().slice(0,10);document.getElementById('txnMemberWrap').style.display=type==='income'?'block':'none';populateTxnCats(type);document.getElementById('txnModal').classList.add('show');}
function populateTxnCats(type){const sel=document.getElementById('txnCat');sel.innerHTML='<option value="">Select...</option>'+cats.filter(c=>c.type===type).map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');}
async function saveTxn(){const type=document.getElementById('txnType').value,cat=document.getElementById('txnCat').value,amt=document.getElementById('txnAmt').value,desc=document.getElementById('txnDesc').value,date=document.getElementById('txnDate').value,method=document.getElementById('txnMethod').value,receipt=document.getElementById('txnReceipt').value,member=document.getElementById('txnMember').value;if(!amt||parseFloat(amt)<=0)return toast('Enter amount','e');const fd=new FormData();fd.append('action','add_transaction');fd.append('type',type);fd.append('category_id',cat);fd.append('amount',amt);fd.append('description',desc);fd.append('transaction_date',date);fd.append('payment_method',method);fd.append('receipt_number',receipt);if(member)fd.append('member_id',member);try{const d=await postAPI(fd);if(d.status==='success'){toast('Saved!','s');closeModal('txnModal');loadTxns(type);loadDashboard();['txnAmt','txnDesc','txnReceipt'].forEach(id=>document.getElementById(id).value='');}else toast(d.message||'Error','e');}catch(e){toast('Network error','e');}}
async function deleteTxn(id,type){if(!confirm('Delete this transaction?'))return;const fd=new FormData();fd.append('action','delete_transaction');fd.append('id',id);try{const d=await postAPI(fd);if(d.status==='success'){toast('Deleted','s');loadTxns(type);loadDashboard();}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}

async function loadMembers(){try{const r=await fetch('/admin/api_list_members.php',{credentials:'same-origin'});const d=await r.json();if(d.status==='success'){members=d.members||[];const opts=members.map(m=>`<option value="${m.id}">${esc(m.student_name)} (${esc(m.member_code||'')})</option>`).join('');document.getElementById('txnMember').innerHTML='<option value="">— None —</option>'+opts;document.getElementById('feeMember').innerHTML='<option value="">Select member...</option>'+opts;}}catch(e){}}

function openFeeModal(){if(!members.length)loadMembers();document.getElementById('feeModal').classList.add('show');}
async function saveFee(){const member=document.getElementById('feeMember').value,amt=document.getElementById('feeAmt').value,type=document.getElementById('feeType').value,month=document.getElementById('feeMonth').value,year=document.getElementById('feeEcYear').value,status=document.getElementById('feePayStatus').value;if(!member||!amt)return toast('Member and amount required','e');const fd=new FormData();fd.append('action','save_fee');fd.append('member_id',member);fd.append('amount',amt);fd.append('fee_type',type);fd.append('ec_month',month);fd.append('ec_year',year);fd.append('status',status);fd.append('paid_date',status==='paid'?new Date().toISOString().slice(0,10):'');try{const d=await postAPI(fd);if(d.status==='success'){toast('Fee recorded!','s');closeModal('feeModal');loadFees();}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}
async function loadFees(){const st=document.getElementById('feeStatus')?.value||'',yr=document.getElementById('feeYear')?.value||'';try{const r=await fetch(API+`?action=member_fees&status=${st}&ec_year=${yr}`,{credentials:'same-origin'});const d=await r.json();if(d.status==='success'){const f=d.fees||[];document.getElementById('feesBody').innerHTML=f.length?f.map(x=>`<tr><td style="font-weight:600">${esc(x.student_name||'—')}</td><td><span class="bg bg-in">${esc(x.member_code||'—')}</span></td><td>${esc(x.fee_type)}</td><td style="font-weight:600">${num(x.amount)}</td><td>${x.ec_month||'—'}</td><td>${x.ec_year||'—'}</td><td>${x.status==='paid'?'<span class="bg bg-ok">Paid</span>':x.status==='partial'?'<span class="bg bg-w">Partial</span>':'<span class="bg bg-bd">Unpaid</span>'}</td><td style="font-size:.7rem">${x.paid_date?fDate(x.paid_date):'—'}</td></tr>`).join(''):'<tr><td colspan="8" style="text-align:center;color:var(--dm);padding:1.5rem">No fees recorded</td></tr>';}}catch(e){}}

function openCatModal(){document.getElementById('catModal').classList.add('show');}
async function saveCat(){const nm=document.getElementById('catName').value.trim(),tp=document.getElementById('catType').value,ds=document.getElementById('catDesc').value.trim();if(!nm)return toast('Name required','e');const fd=new FormData();fd.append('action','save_category');fd.append('name',nm);fd.append('type',tp);fd.append('description',ds);try{const d=await postAPI(fd);if(d.status==='success'){toast('Category saved!','s');closeModal('catModal');loadCats();document.getElementById('catName').value='';document.getElementById('catDesc').value='';}else toast(d.message||'Error','e');}catch(e){toast('Error','e');}}

function initReport(){const t=new Date(),y=new Date(t);y.setFullYear(y.getFullYear(),0,1);document.getElementById('rptFrom').value=y.toISOString().slice(0,10);document.getElementById('rptTo').value=t.toISOString().slice(0,10);}
async function loadReport(){const from=document.getElementById('rptFrom').value,to=document.getElementById('rptTo').value;if(!from||!to)return toast('Select dates','e');try{const r=await fetch(API+`?action=report&from=${from}&to=${to}`,{credentials:'same-origin'});const d=await r.json();if(d.status==='success'&&d.data){const dt=d.data;document.getElementById('rptStats').innerHTML=`<div class="sc"><div class="ico" style="background:rgba(34,197,94,.15);color:var(--ok)"><i class="fa-solid fa-arrow-up"></i></div><div class="val" style="color:var(--ok)">${num(dt.totals?.income||0)}</div><div class="lbl">Income</div></div><div class="sc"><div class="ico" style="background:rgba(239,68,68,.15);color:var(--bd)"><i class="fa-solid fa-arrow-down"></i></div><div class="val" style="color:var(--bd)">${num(dt.totals?.expense||0)}</div><div class="lbl">Expense</div></div><div class="sc"><div class="ico" style="background:rgba(14,165,233,.15);color:var(--in)"><i class="fa-solid fa-wallet"></i></div><div class="val">${num((parseFloat(dt.totals?.income||0)-parseFloat(dt.totals?.expense||0)))}</div><div class="lbl">Net</div></div>`;if(dt.by_category?.length){document.getElementById('rptBody').innerHTML=dt.by_category.map(c=>`<tr><td>${esc(c.name||'—')}</td><td>${c.type==='income'?'<span class="bg bg-ok">Income</span>':'<span class="bg bg-bd">Expense</span>'}</td><td style="font-weight:600">${num(c.total)}</td><td>${c.cnt}</td></tr>`).join('');document.getElementById('rptDetail').style.display='block';}}}catch(e){toast('Error','e');}}
function exportTxns(type){const data=txns[type]||[];if(!data.length)return toast('No data','e');const h=['Date','Category','Description','Amount','Method','Receipt','Status'];const rows=data.map(t=>[fDate(t.transaction_date),t.category_name||'',t.description||'',t.amount,t.payment_method||'',t.receipt_number||'',t.status]);expXls(h,rows,'<?= MEMBER_CODE_PREFIX ?>_'+type+'_'+new Date().toISOString().slice(0,10));}
function exportReport(){const rows=[];document.querySelectorAll('#rptBody tr').forEach(tr=>{rows.push([...tr.querySelectorAll('td')].map(td=>td.textContent.trim()));});if(!rows.length)return toast('Generate report first','e');expXls(['Category','Type','Total','Count'],rows,'<?= MEMBER_CODE_PREFIX ?>_Financial_Report');}
function expXls(h,r,fn){const ws=XLSX.utils.aoa_to_sheet([h,...r]);ws['!cols']=h.map(()=>({wch:16}));const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Data');XLSX.writeFile(wb,fn+'.xlsx');}

function closeModal(id){document.getElementById(id).classList.remove('show');}
function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function toast(m,t){const el=document.getElementById('toast');el.className='toast '+(t==='s'?'t-ok':'t-err')+' show';el.innerHTML=`<i class="fa-solid fa-${t==='s'?'check-circle':'exclamation-circle'}"></i> ${m}`;setTimeout(()=>el.classList.remove('show'),3000);}
function num(n){return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fDate(d){if(!d)return'—';if(typeof WBWSCalendar!=='undefined')return WBWSCalendar.formatDate(d,'medium');try{return new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d;}}
function stBg(s){return s==='confirmed'?'<span class="bg bg-ok">Confirmed</span>':s==='pending'?'<span class="bg bg-w">Pending</span>':'<span class="bg bg-bd">'+esc(s)+'</span>';}

document.addEventListener('DOMContentLoaded',()=>{loadDashboard();loadCats();loadMembers();});
</script>
</body></html>
