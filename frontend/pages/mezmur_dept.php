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
                <li><button class="school-nav-link" data-section="attendance"><i class="fa-solid fa-calendar-check"></i> Attendance</button></li>
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
                </div>

                <!-- Quick actions (taker tile hidden for non-managers by JS) -->
                <div class="quick-actions">
                    <button class="quick-tile qa-primary" onclick="Mezmur.quickTake()">
                        <i class="fa-solid fa-clipboard-check"></i> Take Attendance
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
                                    <tr><th>Date</th><th>Program</th><th>Attended</th><th>Rate</th></tr>
                                </thead>
                                <tbody id="mzOvRecentDays">
                                    <tr><td colspan="4"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
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
                    <!-- Toolbar -->
                    <div class="toolbar">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input id="mzSearch" class="school-input" type="search" placeholder="Search by title, Amharic title or reference…" autocomplete="off" aria-label="Search hymns">
                        </div>
                        <select id="mzCategoryFilter" class="school-input" aria-label="Filter by category">
                            <option value="">All categories</option>
                        </select>
                        <select id="mzStatusFilter" class="school-input" aria-label="Filter by status">
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                            <option value="">All</option>
                        </select>
                    </div>

                    <!-- List -->
                    <div class="table-shell">
                        <table>
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th class="amharic">ስም (አማርኛ)</th>
                                    <th>Category</th>
                                    <th>Reference</th>
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
            <section id="section-attendance" class="school-section" data-section="attendance">

                <!-- Day list view -->
                <div id="mzSessionListView">
                    <div class="page-head">
                        <div>
                            <h2><i class="fa-solid fa-calendar-check"></i> Attendance</h2>
                            <div class="page-head-sub amharic">በቀን መሠረት • በክፍል (section) የተከፋፈለ</div>
                        </div>
                    </div>

                    <!-- Take attendance hero -->
                    <div class="school-card">
                        <div class="toolbar">
                            <div class="toolbar-grow">
                                <label class="school-label" for="mzAttDate">Attendance date</label>
                                <input id="mzAttDate" class="school-input" type="date">
                            </div>
                            <div class="toolbar-grow">
                                <label class="school-label" for="mzAttProgram">Program type</label>
                                <select id="mzAttProgram" class="school-input">
                                    <option value="rehearsal">Rehearsal (የዝማሬ ልምምድ)</option>
                                    <option value="service">Service (አገልግሎት)</option>
                                    <option value="feast">Feast (በዓል)</option>
                                    <option value="training">Training (ሥልጠና)</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="page-head-actions">
                                <button class="btn-primary" onclick="Mezmur.openDay()"><i class="fa-solid fa-clipboard-check"></i> Take Attendance</button>
                            </div>
                        </div>
                        <div class="text-dim" aria-hidden="true">
                            <i class="fa-regular fa-keyboard"></i>
                            In the sheet: <b>↑ / ↓</b> move between members, <b>P</b> present, <b>L</b> late, <b>A</b> absent.
                        </div>
                    </div>

                    <!-- History -->
                    <div class="school-card">
                        <div class="toolbar">
                            <div class="toolbar-title">
                                <h3 class="school-card-title"><i class="fa-solid fa-clock-rotate-left"></i> Attendance Days</h3>
                            </div>
                            <label class="school-label visually-hidden" for="mzSessFrom">From date</label>
                            <input id="mzSessFrom" class="school-input" type="date" aria-label="From date">
                            <label class="school-label visually-hidden" for="mzSessTo">To date</label>
                            <input id="mzSessTo" class="school-input" type="date" aria-label="To date">
                            <button class="btn-secondary" onclick="Mezmur.loadDays()"><i class="fa-solid fa-filter"></i> Filter</button>
                        </div>
                        <div class="table-shell">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Program</th>
                                        <th>Title</th>
                                        <th>Marked</th>
                                        <th>Attended</th>
                                        <th>Rate</th>
                                        <th class="nowrap">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="mzSessTbody">
                                    <tr><td colspan="7"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="mzSessPagination" class="pager" role="navigation" aria-label="Attendance days pagination"></div>
                    </div>
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
                            <button class="btn-secondary" onclick="Mezmur.markAll('present')"><i class="fa-solid fa-check-double"></i> All Present</button>
                            <button class="btn-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
                            <button class="btn-secondary" onclick="Mezmur.closeSheet()"><i class="fa-solid fa-arrow-left"></i> Back</button>
                        </div>
                    </div>

                    <div id="mzSheetBody" aria-live="polite">
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                        <div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div>
                    </div>

                    <div class="sheet-summarybar">
                        <div id="mzSheetSummary" aria-live="polite"></div>
                        <button class="btn-primary" id="mzSheetSaveBtn" onclick="Mezmur.saveSheet()"><i class="fa-solid fa-save"></i> Save Attendance</button>
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
                        <select id="mzAnProgram" class="school-input" aria-label="Filter by program type">
                            <option value="">All programs</option>
                            <option value="rehearsal">Rehearsal</option>
                            <option value="service">Service</option>
                            <option value="feast">Feast</option>
                            <option value="training">Training</option>
                            <option value="other">Other</option>
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
                                    <th>Linked Member</th>
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
            <label class="school-label" for="mzTitle">Title *</label>
            <input id="mzTitle" class="school-input" maxlength="255" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzTitleAm">ስም በአማርኛ (Amharic title)</label>
            <input id="mzTitleAm" class="school-input amharic" maxlength="255" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzCategory">Category</label>
            <input id="mzCategory" class="school-input" list="mzCategoryOptions" maxlength="50" placeholder="e.g. Feast, Praise, Lent…">
            <datalist id="mzCategoryOptions"></datalist>
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzReference">Reference (composer / book / source)</label>
            <input id="mzReference" class="school-input" maxlength="255">
        </div>
        <div class="school-form-group">
            <label class="school-label" for="mzLyrics">Lyrics</label>
            <textarea id="mzLyrics" class="school-input amharic" rows="9" placeholder="የመዝሙሩ ግጥም…"></textarea>
        </div>
        <div class="school-error-msg is-hidden" id="mzModalError" role="alert"></div>
        <button class="btn-primary btn-block" id="mzSaveBtn" onclick="Mezmur.save()"><i class="fa-solid fa-save"></i> Save Hymn</button>
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

<!-- ═══ MOBILE BOTTOM NAV ═══ -->
<nav class="school-bottom-nav" aria-label="Mezmur sections">
    <div class="school-bottom-nav-inner">
        <button class="school-bottom-nav-btn active" data-section="overview" aria-label="Overview"><i class="fa-solid fa-gauge-high"></i><span>Home</span></button>
        <button class="school-bottom-nav-btn" data-section="library" aria-label="Hymn Library"><i class="fa-solid fa-book-open"></i><span>Library</span></button>
        <button class="school-bottom-nav-btn" data-section="attendance" aria-label="Attendance"><i class="fa-solid fa-calendar-check"></i><span>Attend</span></button>
        <button class="school-bottom-nav-btn" data-section="analytics" aria-label="Analytics"><i class="fa-solid fa-chart-column"></i><span>Analyze</span></button>
        <button class="school-bottom-nav-btn" data-section="takers" aria-label="Attendance takers"><i class="fa-solid fa-user-shield"></i><span>Takers</span></button>
    </div>
</nav>

<?php
$bodyContent = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
?>
