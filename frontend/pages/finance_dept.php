<?php
/**
 * ============================================================
 * Finance Department Dashboard — SEPARATED
 * ============================================================
 * 
 * This is an HTML shell. It contains:
 *   - Structure and layout (HTML only)
 *   - No database queries (those are in api_finance.php)
 *   - No inline CSS (that's in themes/[school]/theme.css)
 *   - No inline JavaScript logic (that's in frontend/js/finance.js)
 *   - School identity comes from window.APP (set by base.php)
 * 
 * To redesign for a new school: change theme.css only.
 * To change logic: edit finance.js or api_finance.php.
 * This file almost never changes.
 * ============================================================
 */

$pageTitle  = 'Finance Department';
$pageScript = 'finance';
$bodyClass  = 'page-finance';

// Only finance staff and admins may open the finance dashboard.
// (base.php enforces this list; the finance API is guarded separately.)
$requiredRoles = ['super_admin', 'school_admin', 'finance_dept'];

// Extra libraries needed by this dashboard
$extraHead = '
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
';

ob_start();
?>
<!-- ═══ MAIN LAYOUT ═══ -->
<div class="school-layout">

    <!-- ═══ SIDEBAR ═══ -->
    <aside class="school-sidebar">
        <div class="school-brand">
            <div class="school-brand-logo"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="school-brand-name"><span data-school-short></span> Finance</div>
                <div class="school-brand-sub amharic" data-school-dept-finance></div>
            </div>
        </div>

        <nav class="school-nav-section">
            <div class="school-nav-title">Finance</div>
            <ul class="school-nav-list">
                <li><button class="school-nav-link active" data-section="dashboard"><i class="fa-solid fa-gauge-high"></i> Overview</button></li>
                <li><button class="school-nav-link" data-section="income"><i class="fa-solid fa-arrow-trend-up"></i> Income</button></li>
                <li><button class="school-nav-link" data-section="expense"><i class="fa-solid fa-arrow-trend-down"></i> Expenses</button></li>
                <li><button class="school-nav-link" data-section="fees"><i class="fa-solid fa-hand-holding-dollar"></i> Member Fees</button></li>
                <li><button class="school-nav-link" data-section="categories"><i class="fa-solid fa-tags"></i> Categories</button></li>
                <li><button class="school-nav-link" data-section="reports"><i class="fa-solid fa-chart-line"></i> Reports</button></li>
            </ul>
        </nav>

        <div class="school-sidebar-footer">
            <!-- Theme toggle -->
            <button class="school-theme-toggle" id="themeToggle" onclick="toggleTheme()">
                <span class="school-theme-toggle-label" id="themeLabel">Dark Mode</span>
                <div class="school-theme-toggle-track">
                    <div class="school-theme-toggle-thumb"></div>
                </div>
            </button>

            <div class="school-user-card">
                <div class="school-user-avatar" data-user-initials></div>
                <div>
                    <div class="school-user-name" data-user-name></div>
                    <div class="school-user-role">Finance • <span data-today></span></div>
                </div>
            </div>
            <a href="/backend/auth/logout.php" class="school-logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </aside>

    <!-- ═══ MAIN CONTENT ═══ -->
    <div class="school-main">

        <!-- Mobile header -->
        <div class="school-topbar">
            <div>
                <h1>Finance Department</h1>
                <div class="school-topbar-sub" data-today></div>
            </div>
            <div class="school-status-badge">
                <span class="school-status-dot"></span> Online
            </div>
        </div>

        <div class="school-content">

            <!-- Setup required notice (hidden by JS when tables exist) -->
            <div id="setup-notice" class="school-card" style="display:none; text-align:center; padding:3rem">
                <i class="fa-solid fa-database" style="font-size:3rem; color:var(--school-accent); margin-bottom:1rem"></i>
                <h2 style="color:var(--school-text-bright); margin-bottom:0.5rem">Setup Required</h2>
                <p style="color:var(--school-text-dim); margin-bottom:1.5rem">Finance tables need to be created.</p>
                <a href="/admin/migrations/004_add_finance_material_tables.php" class="btn-primary" style="display:inline-flex;padding:0.6rem 1.25rem;border-radius:var(--school-btn-radius);text-decoration:none;color:#fff">
                    <i class="fa-solid fa-play"></i> Run Migration
                </a>
            </div>

            <!-- ═══ DASHBOARD SECTION ═══ -->
            <div id="section-dashboard" class="school-section active">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.5rem">
                    <div>
                        <h1 id="greeting" style="font-size:1.4rem;font-weight:700;color:var(--school-text-bright)"></h1>
                        <p style="color:var(--school-text-dim);font-size:0.8rem" data-today></p>
                    </div>
                    <div style="display:flex;gap:0.5rem">
                        <button class="btn-secondary btn-sm" onclick="Finance.nav('income');Finance.openAddTxn('income')">
                            <i class="fa-solid fa-plus" style="color:var(--school-success)"></i> Income
                        </button>
                        <button class="btn-secondary btn-sm" onclick="Finance.nav('expense');Finance.openAddTxn('expense')">
                            <i class="fa-solid fa-minus" style="color:var(--school-danger)"></i> Expense
                        </button>
                    </div>
                </div>

                <!-- Stats grid (populated by JS) -->
                <div class="grid-3" id="stats-grid" style="margin-bottom:1.5rem"></div>

                <!-- Recent transactions -->
                <div class="school-card">
                    <div class="school-card-title">
                        <i class="fa-solid fa-clock-rotate-left"></i> Recent Transactions
                    </div>
                    <div style="overflow-x:auto">
                        <table>
                            <thead>
                                <tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th>Amount</th><th>Status</th></tr>
                            </thead>
                            <tbody id="recentBody">
                                <tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:1rem">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══ INCOME SECTION ═══ -->
            <div id="section-income" class="school-section">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem">
                    <h2 style="font-size:1.2rem;font-weight:700;color:var(--school-text-bright)">
                        <i class="fa-solid fa-arrow-trend-up" style="color:var(--school-success)"></i> Income
                    </h2>
                    <div style="display:flex;gap:0.5rem">
                        <button class="btn-primary btn-sm" onclick="Finance.openAddTxn('income')"><i class="fa-solid fa-plus"></i> Add</button>
                        <button class="btn-secondary btn-sm" onclick="Finance.exportTxns('income')"><i class="fa-solid fa-download"></i> Export</button>
                    </div>
                </div>
                <div style="overflow-x:auto">
                    <table><thead><tr><th>Date</th><th>Category</th><th>Member</th><th>Description</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Actions</th></tr></thead>
                    <tbody id="incBody"></tbody></table>
                </div>
            </div>

            <!-- ═══ EXPENSE SECTION ═══ -->
            <div id="section-expense" class="school-section">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem">
                    <h2 style="font-size:1.2rem;font-weight:700;color:var(--school-text-bright)">
                        <i class="fa-solid fa-arrow-trend-down" style="color:var(--school-danger)"></i> Expenses
                    </h2>
                    <div style="display:flex;gap:0.5rem">
                        <button class="btn-primary btn-sm" onclick="Finance.openAddTxn('expense')"><i class="fa-solid fa-plus"></i> Add</button>
                        <button class="btn-secondary btn-sm" onclick="Finance.exportTxns('expense')"><i class="fa-solid fa-download"></i> Export</button>
                    </div>
                </div>
                <div style="overflow-x:auto">
                    <table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="expBody"></tbody></table>
                </div>
            </div>

            <!-- ═══ FEES SECTION ═══ -->
            <div id="section-fees" class="school-section">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                    <h2 style="font-size:1.2rem;font-weight:700;color:var(--school-text-bright)">
                        <i class="fa-solid fa-hand-holding-dollar" style="color:var(--school-accent)"></i> Member Fees
                    </h2>
                    <button class="btn-primary btn-sm" onclick="Finance.openFeeModal()"><i class="fa-solid fa-plus"></i> Record</button>
                </div>
                <div class="school-card" style="margin-bottom:1rem">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                        <div>
                            <label class="school-label">Status</label>
                            <select id="feeStatus" class="school-input" onchange="Finance.loadFees()">
                                <option value="">All</option>
                                <option value="paid">Paid</option>
                                <option value="unpaid">Unpaid</option>
                            </select>
                        </div>
                        <div>
                            <label class="school-label">EC Year</label>
                            <input type="number" id="feeYear" class="school-input" onchange="Finance.loadFees()">
                        </div>
                    </div>
                </div>
                <div style="overflow-x:auto">
                    <table><thead><tr><th>Member</th><th>Code</th><th>Type</th><th>Amount</th><th>Month</th><th>Year</th><th>Status</th><th>Paid</th></tr></thead>
                    <tbody id="feesBody"></tbody></table>
                </div>
            </div>

            <!-- ═══ CATEGORIES SECTION ═══ -->
            <div id="section-categories" class="school-section">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                    <h2 style="font-size:1.2rem;font-weight:700;color:var(--school-text-bright)">
                        <i class="fa-solid fa-tags" style="color:var(--school-accent)"></i> Categories
                    </h2>
                    <button class="btn-primary btn-sm" onclick="Finance.openCatModal()"><i class="fa-solid fa-plus"></i> Add</button>
                </div>
                <div class="grid-2">
                    <div class="school-card">
                        <div class="school-card-title" style="color:var(--school-success)"><i class="fa-solid fa-arrow-up"></i> Income</div>
                        <div id="incCats"></div>
                    </div>
                    <div class="school-card">
                        <div class="school-card-title" style="color:var(--school-danger)"><i class="fa-solid fa-arrow-down"></i> Expense</div>
                        <div id="expCats"></div>
                    </div>
                </div>
            </div>

            <!-- ═══ REPORTS SECTION ═══ -->
            <div id="section-reports" class="school-section">
                <h2 style="font-size:1.2rem;font-weight:700;color:var(--school-text-bright);margin-bottom:1rem">
                    <i class="fa-solid fa-chart-line" style="color:var(--school-accent)"></i> Financial Reports
                </h2>
                <div class="school-card" style="margin-bottom:1rem">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.75rem">
                        <div><label class="school-label">From</label><input type="date" id="rptFrom" class="school-input"></div>
                        <div><label class="school-label">To</label><input type="date" id="rptTo" class="school-input"></div>
                        <div style="display:flex;align-items:flex-end;gap:0.5rem">
                            <button class="btn-primary btn-sm" onclick="Finance.loadReport()"><i class="fa-solid fa-search"></i> Generate</button>
                            <button class="btn-secondary btn-sm" onclick="Finance.exportReport()"><i class="fa-solid fa-download"></i> Export</button>
                        </div>
                    </div>
                </div>
                <div class="grid-3" id="rptStats" style="margin-bottom:1rem"></div>
                <div class="school-card" id="rptDetail" style="display:none">
                    <div class="school-card-title">By Category</div>
                    <div style="overflow-x:auto">
                        <table><thead><tr><th>Category</th><th>Type</th><th>Total (ETB)</th><th>Count</th></tr></thead>
                        <tbody id="rptBody"></tbody></table>
                    </div>
                </div>
            </div>

        </div><!-- .school-content -->
    </div><!-- .school-main -->
</div><!-- .school-layout -->

<!-- ═══ MODALS ═══ -->

<!-- Add Transaction -->
<div class="school-modal" id="txnModal">
    <div class="school-modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 id="txnTitle" style="font-size:1.1rem;font-weight:700;color:var(--school-text-bright)">Add Transaction</h3>
            <button onclick="modal('txnModal',false)" style="background:none;border:none;color:var(--school-text-dim);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <input type="hidden" id="txnType" value="income">
        <div class="school-form-group"><label class="school-label">Category</label><select id="txnCat" class="school-input"></select></div>
        <div class="school-form-group"><label class="school-label">Amount (ETB)</label><input type="number" id="txnAmt" class="school-input" step="0.01" min="0"></div>
        <div class="school-form-group"><label class="school-label">Description</label><input id="txnDesc" class="school-input"></div>
        <div class="school-form-group"><label class="school-label">Date</label><input type="date" id="txnDate" class="school-input"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem">
            <div class="school-form-group"><label class="school-label">Payment Method</label>
                <select id="txnMethod" class="school-input"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="mobile_money">Mobile Money</option><option value="check">Check</option></select>
            </div>
            <div class="school-form-group"><label class="school-label">Receipt #</label><input id="txnReceipt" class="school-input"></div>
        </div>
        <div class="school-form-group" id="txnMemberWrap"><label class="school-label">Member (optional)</label><select id="txnMember" class="school-input"><option value="">— None —</option></select></div>
        <button class="btn-primary" onclick="Finance.saveTxn()" style="width:100%;justify-content:center"><i class="fa-solid fa-save"></i> Save</button>
    </div>
</div>

<!-- Record Fee -->
<div class="school-modal" id="feeModal">
    <div class="school-modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 style="font-size:1.1rem;font-weight:700;color:var(--school-text-bright)">Record Fee Payment</h3>
            <button onclick="modal('feeModal',false)" style="background:none;border:none;color:var(--school-text-dim);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="school-form-group"><label class="school-label">Member</label><select id="feeMember" class="school-input"></select></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem">
            <div class="school-form-group"><label class="school-label">Amount (ETB)</label><input type="number" id="feeAmt" class="school-input" step="0.01" min="0"></div>
            <div class="school-form-group"><label class="school-label">Fee Type</label><select id="feeType" class="school-input"><option value="monthly">Monthly</option><option value="annual">Annual</option><option value="special">Special</option></select></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem">
            <div class="school-form-group"><label class="school-label">EC Month (1-13)</label><input type="number" id="feeMonth" class="school-input" min="1" max="13"></div>
            <div class="school-form-group"><label class="school-label">EC Year</label><input type="number" id="feeEcYear" class="school-input"></div>
        </div>
        <div class="school-form-group"><label class="school-label">Status</label><select id="feePayStatus" class="school-input"><option value="paid">Paid</option><option value="unpaid">Unpaid</option><option value="partial">Partial</option></select></div>
        <button class="btn-primary" onclick="Finance.saveFee()" style="width:100%;justify-content:center"><i class="fa-solid fa-save"></i> Save</button>
    </div>
</div>

<!-- Add Category -->
<div class="school-modal" id="catModal">
    <div class="school-modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 style="font-size:1.1rem;font-weight:700;color:var(--school-text-bright)">Add Category</h3>
            <button onclick="modal('catModal',false)" style="background:none;border:none;color:var(--school-text-dim);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="school-form-group"><label class="school-label">Name</label><input id="catName" class="school-input"></div>
        <div class="school-form-group"><label class="school-label">Type</label><select id="catType" class="school-input"><option value="income">Income</option><option value="expense">Expense</option></select></div>
        <div class="school-form-group"><label class="school-label">Description</label><input id="catDesc" class="school-input"></div>
        <button class="btn-primary" onclick="Finance.saveCat()" style="width:100%;justify-content:center"><i class="fa-solid fa-save"></i> Save</button>
    </div>
</div>

<!-- ═══ MOBILE BOTTOM NAV ═══ -->
<nav class="school-bottom-nav">
    <div class="school-bottom-nav-inner">
        <button class="school-bottom-nav-btn active" data-section="dashboard"><i class="fa-solid fa-gauge-high"></i><span>Home</span></button>
        <button class="school-bottom-nav-btn" data-section="income"><i class="fa-solid fa-arrow-trend-up"></i><span>Income</span></button>
        <button class="school-bottom-nav-btn" data-section="expense"><i class="fa-solid fa-arrow-trend-down"></i><span>Expense</span></button>
        <button class="school-bottom-nav-btn" data-section="fees"><i class="fa-solid fa-hand-holding-dollar"></i><span>Fees</span></button>
        <button class="school-bottom-nav-btn" data-section="categories"><i class="fa-solid fa-tags"></i><span>Cats</span></button>
        <button class="school-bottom-nav-btn" data-section="reports"><i class="fa-solid fa-chart-line"></i><span>Reports</span></button>
    </div>
</nav>

<?php
$bodyContent = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
?>
