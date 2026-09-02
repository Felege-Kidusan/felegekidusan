<?php
/**
 * ============================================================
 * Mezmur Department Dashboard (መዝሙር ክፍል) — SEPARATED
 * ============================================================
 *
 * This is an HTML shell. It contains:
 *   - Structure and layout (HTML only)
 *   - No database queries (those are in admin/api_mezmur.php)
 *   - No inline CSS (theme.css + themes/components.css)
 *   - No inline JavaScript logic (that's in frontend/js/mezmur.js)
 *   - School identity comes from window.APP (set by base.php)
 *
 * To redesign for a new school: change theme.css only.
 * To change logic: edit mezmur.js or api_mezmur.php.
 * This file almost never changes.
 * ============================================================
 */

$pageTitle  = 'Mezmur Department';
$pageScript = 'mezmur';
$bodyClass  = 'page-mezmur';

// Only mezmur staff and admins may open the mezmur dashboard.
// (base.php enforces this list; the mezmur API is guarded separately.)
$requiredRoles = ['super_admin', 'school_admin', 'mezmur_dept'];
$requiredFeature = 'mezmur';

ob_start();
?>
<!-- ═══ MAIN LAYOUT ═══ -->
<div class="school-layout">

    <!-- ═══ SIDEBAR ═══ -->
    <aside class="school-sidebar">
        <div class="school-brand">
            <div class="school-brand-logo"><i class="fa-solid fa-music"></i></div>
            <div>
                <div class="school-brand-name"><span data-school-short></span> Mezmur</div>
                <div class="school-brand-sub amharic">መዝሙር ክፍል</div>
            </div>
        </div>

        <nav class="school-nav-section" aria-label="Mezmur sections">
            <div class="school-nav-title">Mezmur</div>
            <ul class="school-nav-list">
                <li><button class="school-nav-link active" data-section="overview"><i class="fa-solid fa-gauge-high"></i> Overview</button></li>
                <li><button class="school-nav-link" data-section="library"><i class="fa-solid fa-book-open"></i> Hymn Library</button></li>
                <li><button class="school-nav-link" data-section="catalog"><i class="fa-solid fa-tags"></i> Catalog</button></li>
                <li><button class="school-nav-link" data-section="attendance"><i class="fa-solid fa-inbox"></i> Submissions</button></li>
                <li><button class="school-nav-link" data-section="analytics"><i class="fa-solid fa-chart-column"></i> Analytics</button></li>
                <li><button class="school-nav-link" data-section="takers"><i class="fa-solid fa-user-shield"></i> Attendance Takers</button></li>
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
                    <div class="school-user-role">Mezmur • <span data-today></span></div>
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
                <h1>Mezmur Department</h1>
                <div class="school-topbar-sub" data-today></div>
            </div>
            <div class="school-status-badge">
                <span class="school-status-dot"></span> Online
            </div>
        </div>

        <div class="school-content">

            <!-- ═══ OVERVIEW SECTION ═══ -->
            <section id="section-overview" class="school-section active" data-section="overview">

                <div class="page-head">
                    <div>
                        <h1 id="mzGreeting">Welcome 🎵</h1>
                        <div class="page-head-sub"><span data-today></span></div>
                    </div>
                    <div class="page-head-actions">
                        <button class="btn-secondary btn-sm" onclick="Mezmur.migrateSchema()"
                                title="Align the database with the current code (safe, idempotent)">
                            <i class="fa-solid fa-database"></i> Sync DB schema
                        </button>
                    </div>
                </div>

                <!-- Quick actions (taker tile hidden for non-managers by JS) -->
                <div class="quick-actions">
                    <button class="quick-tile qa-primary" onclick="Mezmur.quickReview()">
                        <i class="fa-solid fa-inbox"></i> Review Queue
                    </button>
                    <button class="quick-tile" onclick="Mezmur.quickLibrary()">
                        <i class="fa-solid fa-book-open"></i> Hymn Library
                    </button>
                    <button class="quick-tile qa-accent2" onclick="Mezmur.quickAnalytics()">
                        <i class="fa-solid fa-chart-column"></i> Run Analytics
                    </button>
                    <button class="quick-tile qa-warning" id="mzQaTakers" onclick="Mezmur.quickTakers()">
                        <i class="fa-solid fa-user-shield"></i> Attendance Takers
                    </button>
                </div>

                <!-- KPI strip -->
                <div class="stat-grid">
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-accent2"><i class="fa-solid fa-calendar-day"></i></div>
                        <div class="school-stat-value" id="mzOvDays">—</div>
                        <div class="school-stat-label">Attendance days this month</div>
                        <div class="stat-delta flat" id="mzOvDaysDelta"></div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-ok"><i class="fa-solid fa-percent"></i></div>
                        <div class="school-stat-value" id="mzOvRate">—</div>
                        <div class="school-stat-label">Average attendance rate (month)</div>
                        <div class="stat-delta flat" id="mzOvRateDelta"></div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-accent"><i class="fa-solid fa-music"></i></div>
                        <div class="school-stat-value" id="mzOvHymns">—</div>
                        <div class="school-stat-label">Hymns in library</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-info"><i class="fa-solid fa-users"></i></div>
                        <div class="school-stat-value" id="mzOvMembers">—</div>
                        <div class="school-stat-label">Active members</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-warn"><i class="fa-solid fa-user-shield"></i></div>
                        <div class="school-stat-value" id="mzOvTakers">—</div>
                        <div class="school-stat-label">Attendance takers</div>
                    </div>
                </div>

                <!-- Recent activity -->
                <div class="grid-2">
                    <div class="school-card">
                        <div class="school-card-title"><i class="fa-solid fa-calendar-check"></i> Recent Attendance Days</div>
                        <div class="table-shell">
                            <table>
                                <thead>
                                    <tr><th>Date</th><th>Attended</th><th>Rate</th></tr>
                                </thead>
                                <tbody id="mzOvRecentDays">
                                    <tr><td colspan="3"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="school-card">
                        <div class="school-card-title"><i class="fa-solid fa-music"></i> Recently Updated Hymns</div>
                        <div class="table-shell">
                            <table>
                                <thead>
                                    <tr><th>Title</th><th>Category</th><th>Updated</th></tr>
                                </thead>
                                <tbody id="mzOvRecentHymns">
                                    <tr><td colspan="3"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Review queue (department inbox preview) -->
                <div class="school-card">
                    <div class="school-card-title"><i class="fa-solid fa-inbox"></i> Attendance Review Queue
                        <span class="text-dim">— packets saved or submitted by takers</span>
                    </div>
                    <div class="table-shell">
                        <table>
                            <thead>
                                <tr><th>Date</th><th>Section</th><th>Marked</th><th>Status</th><th>Updated</th></tr>
                            </thead>
                            <tbody id="mzOvQueue">
                                <tr><td colspan="5"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ═══ LIBRARY SECTION ═══ -->
            <section id="section-library" class="school-section" data-section="library">

                <div class="page-head">
                    <div>
                        <h2><i class="fa-solid fa-book-open"></i> Hymn Library</h2>
                        <div class="page-head-sub amharic">የመዝሙር መጻሕፍት ቤት</div>
                    </div>
                    <div class="page-head-actions">
                        <button id="mzAddBtn" class="btn-primary" onclick="Mezmur.openAdd()"><i class="fa-solid fa-plus"></i> Add Hymn</button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stat-grid">
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-accent2"><i class="fa-solid fa-music"></i></div>
                        <div class="school-stat-value" id="mzStatTotal">—</div>
                        <div class="school-stat-label">Total Hymns</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-ok"><i class="fa-solid fa-circle-check"></i></div>
                        <div class="school-stat-value" id="mzStatActive">—</div>
                        <div class="school-stat-label">Active</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-warn"><i class="fa-solid fa-tags"></i></div>
                        <div class="school-stat-value" id="mzStatCategories">—</div>
                        <div class="school-stat-label">Categories</div>
                    </div>
                </div>

                <div class="school-card">
                    <!-- Toolbar: one compact professional row (P30) -->
                    <div class="toolbar toolbar-compact">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input id="mzSearch" class="school-input" type="search" placeholder="Search by title or lyrics…" autocomplete="off" aria-label="Search hymns">
                        </div>
                        <select id="mzCategoryFilter" class="school-input" aria-label="Filter by category">
                            <option value="">All categories</option>
                        </select>
                        <select id="mzStatusFilter" class="school-input" aria-label="Filter by status">
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                            <option value="">All</option>
                        </select>
                        <select id="mzLengthFilter" class="school-input" aria-label="Filter by length">
                            <option value="">All lengths</option>
                            <option value="long">Long</option>
                            <option value="short">Short</option>
                        </select>
                        <select id="mzLanguageFilter" class="school-input" aria-label="Filter by language">
                            <option value="">All languages</option>
                            <option value="amharic">Amharic</option>
                            <option value="geez">Geez (ግዕዝ)</option>
                        </select>
                    </div>

                    <!-- Tabs: All / Categories / Zemarians -->
                    <div class="toolbar">
                        <button class="btn-secondary btn-sm mz-tab active" data-tab="all" onclick="Mezmur.tab('all')">All</button>
                        <button class="btn-secondary btn-sm mz-tab" data-tab="categories" onclick="Mezmur.tab('categories')">Categories</button>
                        <button class="btn-secondary btn-sm mz-tab" data-tab="zemarians" onclick="Mezmur.tab('zemarians')">Zemarians</button>
                    </div>

                    <div id="mzBrowse" class="is-hidden">
                        <div id="mzBrowseList" class="chip-list"></div>
                    </div>

                    <!-- List -->
                    <div class="table-shell">
                        <table>
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Updated</th>
                                    <th class="nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mzTbody">
                                <tr><td colspan="6"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div id="mzPagination" class="pager" role="navigation" aria-label="Hymn list pagination"></div>
                </div>
            </section>

            <!-- ═══ ATTENDANCE SECTION ═══ -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<!-- ═══ SECTION: CATALOG (standalone singers/category/subcategory manager) ═══ -->
            <section id="section-catalog" class="school-section" data-section="catalog">
                <div class="page-head">
                    <h2><i class="fa-solid fa-tags"></i> Catalog</h2>
                    <div class="page-head-sub">Categories, sub-categories &amp; singers — everything managed here, inline.</div>
                </div>
                <div class="school-card">
                    <div class="toolbar">
                        <button class="btn-secondary btn-sm mz-cmgr-tab active" id="mzMgrCatTabBtn" onclick="Mezmur.mgrTab('categories')"><i class="fa-solid fa-sitemap"></i> Categories &amp; Sub-categories</button>
                        <button class="btn-secondary btn-sm mz-cmgr-tab" id="mzMgrZemTabBtn" onclick="Mezmur.mgrTab('zemarians')"><i class="fa-solid fa-user-group"></i> Singers</button>
                    </div>

                    <!-- categories manager -->
                    <div id="mzMgrCats">
                        <div class="toolbar">
                            <div class="toolbar-grow"><input id="mzMgrMainName" class="school-input" maxlength="50" placeholder="New main category name…" autocomplete="off"></div>
                            <button class="btn-primary btn-sm" onclick="Mezmur.mgrAddMain()"><i class="fa-solid fa-plus"></i> Add main category</button>
                        </div>
                        <div class="table-shell"><table class="school-table">
                            <thead><tr><th style="width:44px">Cover</th><th>Name</th><th style="width:70px">Hymns</th><th style="width:150px">Order</th><th style="width:290px">Actions</th></tr></thead>
                            <tbody id="mzMgrCatRows"></tbody>
                        </table></div>
                        <input type="file" id="mzMgrFile" accept="image/jpeg,image/png,image/webp" class="is-hidden" aria-hidden="true">
                    </div>

                    <!-- singers manager -->
                    <div id="mzMgrZems" class="is-hidden">
                        <div class="toolbar">
                            <div class="toolbar-grow"><input id="mzMgrZemName" class="school-input" maxlength="100" placeholder="New singer name…" autocomplete="off"></div>
                            <input id="mzMgrZemNameAm" class="school-input amharic" maxlength="100" placeholder="የዘማሪያን ስም (በአማርኛ)" style="max-width:220px" autocomplete="off">
                            <button class="btn-primary btn-sm" onclick="Mezmur.mgrAddZem()"><i class="fa-solid fa-plus"></i> Add singer</button>
                        </div>
                        <div class="table-shell"><table class="school-table">
                            <thead><tr><th>Name</th><th>ስም በአማርኛ</th><th style="width:70px">Hymns</th><th style="width:210px">Actions</th></tr></thead>
                            <tbody id="mzMgrZemRows"></tbody>
                        </table></div>
                    </div>
                </div>
            </section>

            <section id="section-attendance" class="school-section" data-section="attendance">

                <!-- Day list view -->
                <div id="mzSessionListView">
                    <!-- ═══ SUBMISSIONS — mirrors the Education department layout exactly ═══ -->
                    <div class="page-head">
                        <div>
                            <h2><i class="fa-solid fa-inbox"></i> Mezmur Submissions</h2>
                            <div class="page-head-sub">Drafts are still being worked on. Submitted means the taker finished.</div>
                        </div>
                        <div class="page-head-actions">
                            <label class="school-label visually-hidden" for="mzSubSection">Section</label>
                            <select id="mzSubSection" class="school-input" aria-label="Filter by section" onchange="Mezmur.loadSubmissions()">
                                <option value="">All sections</option>
                            </select>
                            <label class="school-label visually-hidden" for="mzSubFrom">From date</label>
                            <input id="mzSubFrom" class="school-input" type="date" aria-label="From date" onchange="Mezmur.loadSubmissions()">
                            <label class="school-label visually-hidden" for="mzSubTo">To date</label>
                            <input id="mzSubTo" class="school-input" type="date" aria-label="To date" onchange="Mezmur.loadSubmissions()">
                            <button class="btn-secondary" type="button" onclick="Mezmur.exportSubmissions()"><i class="fa-solid fa-download"></i> Excel</button>
                            <button class="btn-secondary" type="button" onclick="Mezmur.loadSubmissions()"><i class="fa-solid fa-sync"></i> Refresh</button>
                            <button class="btn-secondary" type="button" onclick="Mezmur.printQrRoster()" title="Printable QR tiles for this section — scanned by takers in the mobile app"><i class="fa-solid fa-qrcode"></i> QR Roster</button>
                        </div>
                    </div>

                    <!-- Drafts | Submitted | Insights tabs -->
                    <div class="sub-tabs" role="tablist" aria-label="Submission tabs">
                        <button class="sub-tab active" id="mzSubTabDraft" type="button" role="tab" aria-selected="true" onclick="Mezmur.switchSubTab('draft')"><i class="fa-solid fa-pen-to-square"></i> Drafts</button>
                        <button class="sub-tab" id="mzSubTabSubmitted" type="button" role="tab" aria-selected="false" onclick="Mezmur.switchSubTab('submitted')"><i class="fa-solid fa-paper-plane"></i> Submitted</button>
                        <button class="sub-tab" id="mzSubTabInsights" type="button" role="tab" aria-selected="false" onclick="Mezmur.switchSubTab('insights')"><i class="fa-solid fa-chart-line"></i> Insights</button>
                    </div>
                    <input autocomplete="off" type="hidden" id="mzSubTabStatus" value="draft">

                    <!-- Insight strip -->
                    <div id="mzSubStatsRow" class="sub-stats-row" aria-live="polite"></div>

                    <!-- Packet table -->
                    <div id="mzSubmissionsList" class="table-shell">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Section</th>
                                    <th>Taker</th>
                                    <th>Members</th>
                                    <th>Result</th>
                                    <th>Status</th>
                                    <th>Updated</th>
                                    <th class="nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mzSubTbody">
                                <tr><td colspan="8"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Insights pane -->
                    <div id="mzSubInsights" class="is-hidden" aria-live="polite"></div>
                </div>

                <!-- Sheet view -->
                <div id="mzSheetView" class="school-card">
                    <div class="print-only">
                        <h2 id="mzPrintTitle">Mezmur Attendance</h2>
                        <div id="mzPrintMeta" class="text-dim"></div>
                    </div>
                    <div class="page-head no-print">
                        <div>
                            <h2 id="mzSheetTitle">Attendance</h2>
                            <div class="page-head-sub" id="mzSheetMeta"></div>
                        </div>
                        <div class="page-head-actions">
                            <select id="mzViewSection" class="school-input mz-view-select" aria-label="Section">
                                <option value="">Select section…</option>
                            </select>
                            <button class="btn-secondary" onclick="Mezmur.viewSheet()"><i class="fa-solid fa-eye"></i> View</button>
                            <button class="btn-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
                            <button class="btn-secondary" onclick="Mezmur.closeSheet()"><i class="fa-solid fa-arrow-left"></i> Back</button>
                        </div>
                    </div>

                    <!-- Packet status banner (draft / submitted / review note) -->
                    <div id="mzSheetStatus" class="is-hidden" role="status" aria-live="polite"></div>

                    <div id="mzSheetBody" aria-live="polite">
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                    </div>

                    <div class="sheet-summarybar">
                        <div id="mzSheetSummary" aria-live="polite"></div>
                        <div class="text-dim"><i class="fa-solid fa-lock"></i> Read-only — sheets are recorded and submitted from the mobile app.</div>
                    </div>
                </div>
            </section>

            <!-- ═══ ANALYTICS SECTION ═══ -->
            <section id="section-analytics" class="school-section" data-section="analytics">

                <div class="page-head">
                    <div>
                        <h2><i class="fa-solid fa-chart-column"></i> Attendance Analytics</h2>
                        <div class="page-head-sub">Every member with number <b>and</b> percentage — program eligibility at a glance.</div>
                    </div>
                    <div class="page-head-actions">
                        <button class="btn-secondary" onclick="Mezmur.exportCsv()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="school-card">
                    <div class="toolbar">
                        <select id="mzAnSection" class="school-input" aria-label="Filter by section">
                            <option value="">All sections</option>
                        </select>
                        <input id="mzAnFrom" class="school-input" type="date" aria-label="From date">
                        <input id="mzAnTo" class="school-input" type="date" aria-label="To date">
                        <input id="mzAnSearch" class="school-input" type="search" placeholder="Search member…" aria-label="Search member">
                        <input id="mzAnMinRate" class="school-input" type="number" min="0" max="100" placeholder="Min rate %" aria-label="Minimum attendance rate percent">
                        <input id="mzAnMinAtt" class="school-input" type="number" min="0" placeholder="Min attended" aria-label="Minimum days attended">
                        <button class="btn-primary" onclick="Mezmur.runAnalytics()"><i class="fa-solid fa-magnifying-glass-chart"></i> Analyze</button>
                    </div>
                </div>

                <!-- Headline stats -->
                <div class="stat-grid">
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-accent2"><i class="fa-solid fa-calendar-day"></i></div>
                        <div class="school-stat-value" id="mzAnHeld">—</div>
                        <div class="school-stat-label">Days Held</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-info"><i class="fa-solid fa-users"></i></div>
                        <div class="school-stat-value" id="mzAnMembers">—</div>
                        <div class="school-stat-label">Members Ranked</div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon si-warn"><i class="fa-solid fa-percent"></i></div>
                        <div class="school-stat-value" id="mzAnAvgRate">—</div>
                        <div class="school-stat-label">Average Rate</div>
                    </div>
                </div>

                <!-- Section rollups -->
                <div class="school-card">
                    <h3 class="school-card-title"><i class="fa-solid fa-layer-group"></i> By Section</h3>
                    <div id="mzSectionCards" class="stat-grid"></div>
                </div>

                <!-- Monthly trend -->
                <div class="school-card">
                    <h3 class="school-card-title"><i class="fa-solid fa-chart-line"></i> Monthly Trend</h3>
                    <div id="mzTrendBody" class="mt-1"></div>
                </div>

                <!-- Member ranking table -->
                <div class="school-card">
                    <h3 class="school-card-title"><i class="fa-solid fa-ranking-star"></i> Member Ranking
                        <span class="text-dim">— every number with its percentage</span>
                    </h3>
                    <div class="table-shell mt-1">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('name')" aria-sort="none">Member</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('section')" aria-sort="none">Section</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('attended')" aria-sort="none">Attended</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('rate')" aria-sort="none">Rate %</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('absent')" aria-sort="none">Absent</th>
                                    <th class="th-sortable" onclick="Mezmur.sortBy('last_attended')" aria-sort="none">Last Attended</th>
                                </tr>
                            </thead>
                            <tbody id="mzAnTbody">
                                <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-sliders"></i><div class="state-title">No analysis yet</div><p class="state-text">Set your filters above and press <b>Analyze</b> to rank every member by attendance.</p></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="mzAnPagination" class="pager" role="navigation" aria-label="Analytics pagination"></div>
                </div>
            </section>

            <!-- ═══ ATTENDANCE TAKERS SECTION ═══ -->
            <section id="section-takers" class="school-section" data-section="takers">
                <div class="page-head">
                    <div>
                        <h2><i class="fa-solid fa-user-shield"></i> Attendance Takers</h2>
                        <div class="page-head-sub">Accounts allowed to record Mezmur attendance from web or mobile.</div>
                    </div>
                    <div class="page-head-actions">
                        <button class="btn-primary" onclick="Mezmur.openTakerModal()"><i class="fa-solid fa-user-plus"></i> Add Taker</button>
                    </div>
                </div>
                <div class="school-card">
                    <div class="table-shell">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Type</th>
                                    <th>Created</th>
                                    <th>Status</th>
                                    <th class="nowrap">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mzTakerTbody">
                                <tr><td colspan="6"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

        </div>
    </div>
</div>

<!-- ═══ MODAL: ADD / EDIT HYMN ═══ -->
<div class="school-modal" id="mzHymnModal" role="dialog" aria-modal="true" aria-labelledby="mzModalTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzModalTitle"><i class="fa-solid fa-music"></i> Add Hymn</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeModal()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <input type="hidden" id="mzHymnId" value="0">
        <div class="school-form-group">
            <label class="school-label" for="mzTitle">Title (ርዕስ) *</label>
            <input id="mzTitle" class="school-input" maxlength="255" autocomplete="off">
        </div>
        <div class="school-form-group">
            <div class="mz-cascade">
                <div>
                    <label class="school-label" for="mzHymnMainCat">Category *</label>
                    <select id="mzHymnMainCat" class="school-input" aria-label="Category"></select>
                </div>
                <div>
                    <label class="school-label" for="mzHymnSubCat">Sub-category *</label>
                    <select id="mzHymnSubCat" class="school-input" aria-label="Sub-category" disabled><option value="">Select a category first…</option></select>
                </div>
            </div>
            <button type="button" class="btn-secondary btn-sm catalog-manage" onclick="Mezmur.openCatalog('categories')"><i class="fa-solid fa-tags"></i> Manage categories</button>
        </div>
        <div class="school-form-group mz-catpick">
            <label class="school-label" id="mzZemLbl">Singers / Zemarians (one or more)</label>
            <button type="button" id="mzZemPickBtn" class="school-input mz-pick-btn" aria-haspopup="listbox" aria-labelledby="mzZemLbl">Select singers…</button>
            <div id="mzZemariansBox" class="mz-pick-panel is-hidden" role="listbox"></div>
            <button type="button" class="btn-secondary btn-sm catalog-manage" onclick="Mezmur.openCatalog('zemarians')"><i class="fa-solid fa-user-plus"></i> Manage singers</button>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzLength">Length</label>
            <select id="mzLength" class="school-input">
                <option value="long" selected>Long</option>
                <option value="short">Short</option>
            </select>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzLanguage">Language</label>
            <select id="mzLanguage" class="school-input">
                <option value="amharic" selected>Amharic (አማርኛ)</option>
                <option value="geez">Geez (ግዕዝ)</option>
            </select>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzEditor">Lyrics</label>
            <div class="mz-ed-toolbar" role="toolbar" aria-label="Lyrics styling">
                <button type="button" class="mz-ed-btn" data-cmd="bold" title="Bold (Ctrl+B)" aria-label="Bold"><i class="fa-solid fa-bold"></i></button>
                <button type="button" class="mz-ed-btn" data-cmd="italic" title="Italic (Ctrl+I)" aria-label="Italic"><i class="fa-solid fa-italic"></i></button>
                <button type="button" class="mz-ed-btn" data-cmd="underline" title="Underline (Ctrl+U)" aria-label="Underline"><i class="fa-solid fa-underline"></i></button>
                <span style="width:1px;height:18px;background:var(--school-border,#e5e7eb);margin:0 .25rem"></span>
                <button type="button" class="mz-ed-btn" data-cmd="section" title="Insert section header (e.g. Verse 1)" aria-label="Insert section header"><i class="fa-solid fa-heading"></i> Section</button>
                <span class="mz-sec-pop is-hidden" id="mzSecPop">
                    <input id="mzSecPopInput" maxlength="60" placeholder="Section name (e.g. Verse 1)">
                    <button type="button" class="btn-primary btn-sm" id="mzSecPopOk">Insert</button>
                    <button type="button" class="btn-secondary btn-sm" id="mzSecPopCancel">Cancel</button>
                </span>
            </div>
            <div id="mzEditor" class="mz-editor amharic" contenteditable="true" spellcheck="false" data-placeholder="የመዝሙሩ ግጥም…"></div>
            <textarea id="mzLyrics" class="is-hidden" aria-hidden="true"></textarea>
            <p class="text-dim" style="font-size:.72rem;margin-top:.25rem">Style as you write with the toolbar — what you see is how it prints.</p>
        </div>
        <div class="school-error-msg is-hidden" id="mzModalError" role="alert"></div>
        <button class="btn-primary btn-block" id="mzSaveBtn" onclick="Mezmur.save()"><i class="fa-solid fa-save"></i> Save Hymn</button>
    </div>
</div>

<!-- ═══ DIALOG: COVER IMAGE PREVIEW (upload with a real preview) ═══ -->
<div class="school-modal" id="mzImageDialog" role="dialog" aria-modal="true" aria-labelledby="mzImageTitle">
    <div class="school-modal-content" style="max-width:380px">
        <div class="page-head">
            <h3 id="mzImageTitle"><i class="fa-solid fa-image"></i> Cover image</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeImageDialog()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mz-img-preview"><img id="mzImgPreviewImg" src="" alt="Selected cover preview"></div>
        <div class="text-dim" id="mzImgMeta" style="font-size:.75rem;margin:.4rem 0 .6rem"></div>
        <p class="text-dim" style="font-size:.72rem;line-height:1.5;margin-bottom:.7rem">
            The cover is dimmed slightly on tiles and headers, so titles always stay readable over any photo.
        </p>
        <div class="toolbar" style="justify-content:flex-end;margin-bottom:0">
            <button class="btn-secondary btn-sm" id="mzImgCancel">Cancel</button>
            <button class="btn-primary btn-sm" id="mzImgUpload"><i class="fa-solid fa-upload"></i> Upload</button>
        </div>
    </div>
</div>

<!-- ═══ DIALOG: COVER COLOR (gradient picker) ═══ -->
<div class="school-modal" id="mzColorDialog" role="dialog" aria-modal="true" aria-labelledby="mzColorTitle">
    <div class="school-modal-content" style="max-width:380px">
        <div class="page-head">
            <h3 id="mzColorTitle"><i class="fa-solid fa-palette"></i> Cover color</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeColorDialog()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mz-color-preview" id="mzColorPreview"><span id="mzColorPreviewName"></span></div>
        <div class="text-dim" id="mzColorNote" style="font-size:.72rem;margin:.35rem 0 .55rem"></div>
        <div class="school-label">Presets</div>
        <div class="mz-swatches" id="mzSwatches" role="radiogroup" aria-label="Gradient presets"></div>
        <div class="mz-color-custom">
            <label>Start <input type="color" id="mzGradStart" value="#4f46e5" aria-label="Gradient start color"></label>
            <label>End <input type="color" id="mzGradEnd" value="#7c3aed" aria-label="Gradient end color"></label>
            <button type="button" class="btn-secondary btn-sm" id="mzGradAuto"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto (by name)</button>
        </div>
        <div class="mz-opacity-row">
            <label>Start opacity <input type="range" id="mzGradStartOp" min="20" max="100" step="1" value="100" aria-label="Start color opacity"><span id="mzGradStartOpV">100%</span></label>
            <label>End opacity <input type="range" id="mzGradEndOp" min="20" max="100" step="1" value="100" aria-label="End color opacity"><span id="mzGradEndOpV">100%</span></label>
        </div>
        <div class="toolbar" style="justify-content:space-between;margin-bottom:0">
            <button type="button" class="btn-secondary btn-sm" id="mzRemoveImg"><i class="fa-solid fa-trash-can"></i> Remove image</button>
            <span class="toolbar" style="gap:.4rem">
                <button type="button" class="btn-secondary btn-sm" onclick="Mezmur.closeColorDialog()">Cancel</button>
                <button type="button" class="btn-primary btn-sm" id="mzGradSave"><i class="fa-solid fa-check"></i> Save</button>
            </span>
        </div>
    </div>
</div>

<!-- ═══ SYSTEM DIALOG: in-app confirm (never browser popups) ═══ -->
<div class="school-modal" id="mzSysDialog" role="dialog" aria-modal="true" aria-labelledby="mzSysDialogTitle">
    <div class="school-modal-content" style="max-width:420px">
        <div class="page-head"><h3 id="mzSysDialogTitle"><i class="fa-solid fa-circle-question"></i> Confirm</h3></div>
        <p id="mzSysDialogBody" style="font-size:.85rem;line-height:1.6"></p>
        <div class="toolbar" style="justify-content:flex-end;margin-bottom:0">
            <button class="btn-secondary btn-sm" id="mzSysDialogNo">Cancel</button>
            <button class="btn-primary btn-sm" id="mzSysDialogYes">Confirm</button>
        </div>
    </div>
</div>

<!-- ═══ MODAL: VIEW HYMN ═══ -->
<div class="school-modal" id="mzViewModal" role="dialog" aria-modal="true" aria-labelledby="mzViewTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzViewTitle"><i class="fa-solid fa-book-open"></i> Hymn</h3>
            <button class="btn-secondary btn-sm" onclick="Mezmur.closeView()" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="mzViewMeta" class="toolbar"></div>
        <pre class="amharic lyrics-view" id="mzViewLyrics"></pre>
    </div>
</div>

<!-- ═══ MODAL: ADD TAKER ═══ -->
<div class="school-modal" id="mzTakerModal" role="dialog" aria-modal="true" aria-labelledby="mzTakerModalTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzTakerModalTitle"><i class="fa-solid fa-user-plus"></i> Add Attendance Taker</h3>
            <button class="btn-secondary btn-sm" onclick="modal('mzTakerModal',false)" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzTkName">Full Name *</label>
            <input id="mzTkName" class="school-input" maxlength="150" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzTkUser">Username *</label>
            <input id="mzTkUser" class="school-input" maxlength="50" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzTkPass">Password * <span class="text-dim">(12+ characters)</span></label>
            <input id="mzTkPass" class="school-input" type="password" minlength="12" maxlength="72" autocomplete="new-password">
        </div>
        <div class="school-error-msg is-hidden" id="mzTkError" role="alert"></div>
        <button class="btn-primary btn-block" id="mzTkSaveBtn" onclick="Mezmur.createTaker()"><i class="fa-solid fa-save"></i> Create Account</button>
    </div>
</div>

<!-- ═══ MODAL: REVIEW SUBMISSION (approve / return-with-note) ═══ -->
<div class="school-modal" id="mzReviewModal" role="dialog" aria-modal="true" aria-labelledby="mzReviewTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzReviewTitle"><i class="fa-solid fa-clipboard-check"></i> Review Attendance</h3>
            <button class="btn-secondary btn-sm" onclick="modal('mzReviewModal',false)" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <input type="hidden" id="mzRvId" value="0">
        <div id="mzRvMeta" class="toolbar"></div>
        <div class="school-form-group">
            <label class="school-label" for="mzRvDecision">Decision *</label>
            <select id="mzRvDecision" class="school-input">
                <option value="approved">Approve — packet is final</option>
                <option value="revision_needed">Return for correction — taker can edit again</option>
                <option value="rejected">Reject — dismiss this packet</option>
            </select>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzRvNotes">Reason <span class="text-dim">(required for returns/rejections — the taker sees it)</span></label>
            <textarea id="mzRvNotes" class="school-input" rows="4" maxlength="500" placeholder="What should the taker fix or confirm?"></textarea>
        </div>
        <div class="school-error-msg is-hidden" id="mzRvError" role="alert"></div>
        <button class="btn-primary btn-block" id="mzRvSaveBtn" onclick="Mezmur.submitReview()"><i class="fa-solid fa-gavel"></i> Record Decision</button>
    </div>
</div>

<!-- ═══ MODAL: PACKET DETAIL (rows) ═══ -->
<div class="school-modal" id="mzPacketModal" role="dialog" aria-modal="true" aria-labelledby="mzPacketTitle">
    <div class="school-modal-content">
        <div class="page-head">
            <h3 id="mzPacketTitle"><i class="fa-solid fa-list-check"></i> Attendance Packet</h3>
            <button class="btn-secondary btn-sm" onclick="modal('mzPacketModal',false)" aria-label="Close dialog"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="mzPacketMeta" class="toolbar"></div>
        <div id="mzPacketBody"></div>
    </div>
</div>

<!-- ═══ MOBILE BOTTOM NAV ═══ -->
<nav class="school-bottom-nav" aria-label="Mezmur sections">
    <div class="school-bottom-nav-inner">
        <button class="school-bottom-nav-btn active" data-section="overview" aria-label="Overview"><i class="fa-solid fa-gauge-high"></i><span>Home</span></button>
        <button class="school-bottom-nav-btn" data-section="library" aria-label="Hymn Library"><i class="fa-solid fa-book-open"></i><span>Library</span></button>
        <button class="school-bottom-nav-btn" data-section="catalog" aria-label="Catalog"><i class="fa-solid fa-tags"></i><span>Catalog</span></button>
        <button class="school-bottom-nav-btn" data-section="attendance" aria-label="Attendance"><i class="fa-solid fa-calendar-check"></i><span>Attend</span></button>
        <button class="school-bottom-nav-btn" data-section="analytics" aria-label="Analytics"><i class="fa-solid fa-chart-column"></i><span>Analyze</span></button>
        <button class="school-bottom-nav-btn" data-section="takers" aria-label="Attendance takers"><i class="fa-solid fa-user-shield"></i><span>Takers</span></button>
    </div>
</nav>

<?php
$bodyContent = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
?>
