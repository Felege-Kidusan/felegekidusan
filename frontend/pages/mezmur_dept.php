<?php
/**
 * ============================================================
 * Mezmur Department Dashboard (መዝሙር ክፍል) — SEPARATED
 * ============================================================
 *
 * This is an HTML shell. It contains:
 *   - Structure and layout (HTML only)
 *   - No database queries (those are in admin/api_mezmur.php)
 *   - No inline CSS (that's in themes/[school]/theme.css)
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

        <nav class="school-nav-section">
            <div class="school-nav-title">Mezmur</div>
            <ul class="school-nav-list">
                <li><button class="school-nav-link active" data-section="library"><i class="fa-solid fa-book-open"></i> Hymn Library</button></li>
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

            <!-- ═══ LIBRARY SECTION ═══ -->
            <section class="school-section active" data-section="library">

                <!-- Stats -->
                <div class="grid-3" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem">
                    <div class="school-stat-card">
                        <div class="school-stat-icon" style="background:rgba(139,92,246,.12);color:#8b5cf6"><i class="fa-solid fa-music"></i></div>
                        <div><div class="school-stat-value" id="mzStatTotal">—</div><div class="school-stat-label">Total Hymns</div></div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon" style="background:rgba(16,185,129,.12);color:#10b981"><i class="fa-solid fa-circle-check"></i></div>
                        <div><div class="school-stat-value" id="mzStatActive">—</div><div class="school-stat-label">Active</div></div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon" style="background:rgba(245,158,11,.12);color:#f59e0b"><i class="fa-solid fa-tags"></i></div>
                        <div><div class="school-stat-value" id="mzStatCategories">—</div><div class="school-stat-label">Categories</div></div>
                    </div>
                </div>

                <div class="school-card">
                    <!-- Toolbar -->
                    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin-bottom:1rem">
                        <div style="position:relative;flex:1;min-width:220px">
                            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:var(--school-text-dim);font-size:.8rem"></i>
                            <input id="mzSearch" class="school-input" style="padding-left:2.2rem;width:100%" type="search" placeholder="Search by title, Amharic title or reference…" autocomplete="off">
                        </div>
                        <select id="mzCategoryFilter" class="school-input" style="min-width:160px" title="Filter by category">
                            <option value="">All categories</option>
                        </select>
                        <select id="mzStatusFilter" class="school-input" style="min-width:120px" title="Filter by status">
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                            <option value="">All</option>
                        </select>
                        <button id="mzAddBtn" class="btn-primary" onclick="Mezmur.openAdd()"><i class="fa-solid fa-plus"></i> Add Hymn</button>
                    </div>

                    <!-- List -->
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                            <thead>
                                <tr style="text-align:left;color:var(--school-text-dim);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">
                                    <th style="padding:.6rem .75rem">Title</th>
                                    <th style="padding:.6rem .75rem" class="amharic">ስም (አማርኛ)</th>
                                    <th style="padding:.6rem .75rem">Category</th>
                                    <th style="padding:.6rem .75rem">Reference</th>
                                    <th style="padding:.6rem .75rem">Updated</th>
                                    <th style="padding:.6rem .75rem;text-align:right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mzTbody">
                                <tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:1.5rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading hymns…</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div id="mzPagination" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;margin-top:1rem"></div>
                </div>
            </section>

            <!-- ═══ ATTENDANCE SECTION ═══ -->
            <section class="school-section" data-section="attendance">

                <!-- Session list view -->
                <div id="mzSessionListView">
                    <div class="school-card">
                        <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin-bottom:1rem">
                            <div style="flex:1;min-width:200px">
                                <h3 class="school-card-title" style="margin:0"><i class="fa-solid fa-calendar-check"></i> Mezmur Sessions</h3>
                                <div style="color:var(--school-text-dim);font-size:.8rem" class="amharic">የዝማሬ እና የአገልግሎት መርሐግብሮች</div>
                            </div>
                            <input id="mzSessFrom" class="school-input" type="date" style="min-width:150px" title="From date">
                            <input id="mzSessTo" class="school-input" type="date" style="min-width:150px" title="To date">
                            <button class="btn-secondary" onclick="Mezmur.loadSessions()"><i class="fa-solid fa-filter"></i> Filter</button>
                            <button class="btn-primary" onclick="Mezmur.openSessionModal()"><i class="fa-solid fa-plus"></i> New Session</button>
                        </div>
                        <div style="overflow-x:auto">
                            <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                                <thead>
                                    <tr style="text-align:left;color:var(--school-text-dim);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">
                                        <th style="padding:.6rem .75rem">Date</th>
                                        <th style="padding:.6rem .75rem">Program</th>
                                        <th style="padding:.6rem .75rem">Title</th>
                                        <th style="padding:.6rem .75rem">Marked</th>
                                        <th style="padding:.6rem .75rem">Attended</th>
                                        <th style="padding:.6rem .75rem;text-align:right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="mzSessTbody">
                                    <tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:1.5rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading sessions…</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="mzSessPagination" style="display:flex;gap:.5rem;align-items:center;justify-content:space-between;margin-top:1rem;flex-wrap:wrap"></div>
                    </div>
                </div>

                <!-- Sheet view (hidden until a session is opened) -->
                <div id="mzSheetView" style="display:none">
                    <div class="school-card">
                        <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin-bottom:1rem">
                            <button class="btn-secondary" onclick="Mezmur.closeSheet()"><i class="fa-solid fa-arrow-left"></i> Back</button>
                            <div style="flex:1;min-width:180px">
                                <h3 class="school-card-title" style="margin:0" id="mzSheetTitle">Attendance Sheet</h3>
                                <div style="color:var(--school-text-dim);font-size:.8rem" id="mzSheetMeta"></div>
                            </div>
                            <button class="btn-secondary" onclick="Mezmur.markAll('present')"><i class="fa-solid fa-check-double"></i> All Present</button>
                        </div>
                        <div id="mzSheetBody"></div>
                        <div style="position:sticky;bottom:0;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;padding:1rem 0 0;margin-top:1rem;border-top:1px solid var(--school-border,rgba(255,255,255,.08))">
                            <div id="mzSheetSummary" style="color:var(--school-text-dim);font-size:.85rem"></div>
                            <button class="btn-primary" id="mzSheetSaveBtn" onclick="Mezmur.saveSheet()"><i class="fa-solid fa-save"></i> Save Attendance</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ ANALYTICS SECTION ═══ -->
            <section class="school-section" data-section="analytics">

                <!-- Filters -->
                <div class="school-card">
                    <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
                        <select id="mzAnSection" class="school-input" style="min-width:140px" title="Section">
                            <option value="">All sections</option>
                        </select>
                        <select id="mzAnProgram" class="school-input" style="min-width:140px" title="Program type">
                            <option value="">All programs</option>
                            <option value="rehearsal">Rehearsal</option>
                            <option value="service">Service</option>
                            <option value="feast">Feast</option>
                            <option value="training">Training</option>
                            <option value="other">Other</option>
                        </select>
                        <input id="mzAnFrom" class="school-input" type="date" style="min-width:140px" title="From">
                        <input id="mzAnTo" class="school-input" type="date" style="min-width:140px" title="To">
                        <input id="mzAnSearch" class="school-input" type="search" placeholder="Search member…" style="min-width:150px;flex:1">
                        <input id="mzAnMinRate" class="school-input" type="number" min="0" max="100" placeholder="Min rate %" style="max-width:110px" title="Minimum attendance rate %">
                        <input id="mzAnMinAtt" class="school-input" type="number" min="0" placeholder="Min attended" style="max-width:130px" title="Minimum sessions attended">
                        <button class="btn-primary" onclick="Mezmur.runAnalytics()"><i class="fa-solid fa-magnifying-glass-chart"></i> Analyze</button>
                        <button class="btn-secondary" onclick="Mezmur.exportCsv()"><i class="fa-solid fa-file-csv"></i> CSV</button>
                    </div>
                </div>

                <!-- Headline stats -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin:1.25rem 0">
                    <div class="school-stat-card">
                        <div class="school-stat-icon" style="background:rgba(139,92,246,.12);color:#8b5cf6"><i class="fa-solid fa-calendar-day"></i></div>
                        <div><div class="school-stat-value" id="mzAnHeld">—</div><div class="school-stat-label">Sessions Held</div></div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon" style="background:rgba(16,185,129,.12);color:#10b981"><i class="fa-solid fa-users"></i></div>
                        <div><div class="school-stat-value" id="mzAnMembers">—</div><div class="school-stat-label">Members Ranked</div></div>
                    </div>
                    <div class="school-stat-card">
                        <div class="school-stat-icon" style="background:rgba(245,158,11,.12);color:#f59e0b"><i class="fa-solid fa-percent"></i></div>
                        <div><div class="school-stat-value" id="mzAnAvgRate">—</div><div class="school-stat-label">Average Rate</div></div>
                    </div>
                </div>

                <!-- Section rollups -->
                <div class="school-card" style="margin-bottom:1.25rem">
                    <h3 class="school-card-title"><i class="fa-solid fa-layer-group"></i> By Section</h3>
                    <div id="mzSectionCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1rem;margin-top:.75rem"></div>
                </div>

                <!-- Monthly trend -->
                <div class="school-card" style="margin-bottom:1.25rem">
                    <h3 class="school-card-title"><i class="fa-solid fa-chart-line"></i> Monthly Trend</h3>
                    <div id="mzTrendBody" style="margin-top:.75rem"></div>
                </div>

                <!-- Member ranking table -->
                <div class="school-card">
                    <h3 class="school-card-title"><i class="fa-solid fa-ranking-star"></i> Member Ranking <span style="color:var(--school-text-dim);font-size:.75rem;font-weight:400">— every number with its percentage</span></h3>
                    <div style="overflow-x:auto;margin-top:.5rem">
                        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                            <thead>
                                <tr style="text-align:left;color:var(--school-text-dim);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">
                                    <th style="padding:.6rem .75rem">#</th>
                                    <th style="padding:.6rem .75rem;cursor:pointer" onclick="Mezmur.sortBy('name')">Member</th>
                                    <th style="padding:.6rem .75rem;cursor:pointer" onclick="Mezmur.sortBy('section')">Section</th>
                                    <th style="padding:.6rem .75rem;cursor:pointer" onclick="Mezmur.sortBy('attended')">Attended</th>
                                    <th style="padding:.6rem .75rem;cursor:pointer" onclick="Mezmur.sortBy('rate')">Rate %</th>
                                    <th style="padding:.6rem .75rem;cursor:pointer" onclick="Mezmur.sortBy('absent')">Absent</th>
                                    <th style="padding:.6rem .75rem;cursor:pointer" onclick="Mezmur.sortBy('last_attended')">Last Attended</th>
                                </tr>
                            </thead>
                            <tbody id="mzAnTbody">
                                <tr><td colspan="7" style="text-align:center;color:var(--school-text-dim);padding:1.5rem">Set filters and press <b>Analyze</b>.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="mzAnPagination" style="display:flex;gap:.5rem;align-items:center;justify-content:space-between;margin-top:1rem;flex-wrap:wrap"></div>
                </div>
            </section>

            <!-- ═══ ATTENDANCE TAKERS SECTION ═══ -->
            <section class="school-section" data-section="takers">
                <div class="school-card">
                    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin-bottom:1rem">
                        <div style="flex:1;min-width:200px">
                            <h3 class="school-card-title" style="margin:0"><i class="fa-solid fa-user-shield"></i> Attendance Takers</h3>
                            <div style="color:var(--school-text-dim);font-size:.8rem">Accounts allowed to record Mezmur attendance from web or mobile.</div>
                        </div>
                        <button class="btn-primary" onclick="Mezmur.openTakerModal()"><i class="fa-solid fa-user-plus"></i> Add Taker</button>
                    </div>
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                            <thead>
                                <tr style="text-align:left;color:var(--school-text-dim);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">
                                    <th style="padding:.6rem .75rem">Name</th>
                                    <th style="padding:.6rem .75rem">Username</th>
                                    <th style="padding:.6rem .75rem">Linked Member</th>
                                    <th style="padding:.6rem .75rem">Created</th>
                                    <th style="padding:.6rem .75rem">Status</th>
                                    <th style="padding:.6rem .75rem;text-align:right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mzTakerTbody">
                                <tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:1.5rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

        </div>
    </div>
</div>

<!-- ═══ MODAL: ADD / EDIT HYMN ═══ -->
<div class="school-modal" id="mzHymnModal">
    <div class="school-modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 id="mzModalTitle" style="font-size:1.1rem;font-weight:700;color:var(--school-text-bright)"><i class="fa-solid fa-music"></i> Add Hymn</h3>
            <button onclick="Mezmur.closeModal()" style="background:none;border:none;color:var(--school-text-dim);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <input type="hidden" id="mzHymnId" value="0">
        <div class="school-form-group">
            <label class="school-label">Title *</label>
            <input id="mzTitle" class="school-input" maxlength="255" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label">ስም በአማርኛ (Amharic title)</label>
            <input id="mzTitleAm" class="school-input amharic" maxlength="255" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label">Category</label>
            <input id="mzCategory" class="school-input" list="mzCategoryOptions" maxlength="50" placeholder="e.g. Feast, Praise, Lent…">
            <datalist id="mzCategoryOptions"></datalist>
        </div>
        <div class="school-form-group">
            <label class="school-label">Reference (composer / book / source)</label>
            <input id="mzReference" class="school-input" maxlength="255">
        </div>
        <div class="school-form-group">
            <label class="school-label">Lyrics</label>
            <textarea id="mzLyrics" class="school-input amharic" rows="9" placeholder="የመዝሙሩ ግጥም…"></textarea>
        </div>
        <div class="school-error-msg" id="mzModalError" style="display:none"></div>
        <button class="btn-primary" id="mzSaveBtn" onclick="Mezmur.save()" style="width:100%;justify-content:center"><i class="fa-solid fa-save"></i> Save Hymn</button>
    </div>
</div>

<!-- ═══ MODAL: VIEW HYMN ═══ -->
<div class="school-modal" id="mzViewModal">
    <div class="school-modal-content" style="max-width:640px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 id="mzViewTitle" style="font-size:1.1rem;font-weight:700;color:var(--school-text-bright)"><i class="fa-solid fa-book-open"></i> Hymn</h3>
            <button onclick="Mezmur.closeView()" style="background:none;border:none;color:var(--school-text-dim);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="mzViewMeta" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem"></div>
        <pre class="amharic" id="mzViewLyrics" style="white-space:pre-wrap;word-break:break-word;background:var(--school-bg-hover,rgba(255,255,255,.03));border:1px solid var(--school-border,rgba(255,255,255,.08));border-radius:.75rem;padding:1rem;max-height:55vh;overflow-y:auto;font-size:.9rem;line-height:1.8;color:var(--school-text)"></pre>
    </div>
</div>

<!-- ═══ MOBILE BOTTOM NAV ═══ -->
<nav class="school-bottom-nav">
    <div class="school-bottom-nav-inner">
        <button class="school-bottom-nav-btn active" data-section="library"><i class="fa-solid fa-book-open"></i><span>Library</span></button>
        <button class="school-bottom-nav-btn" data-section="attendance"><i class="fa-solid fa-calendar-check"></i><span>Attend</span></button>
        <button class="school-bottom-nav-btn" data-section="analytics"><i class="fa-solid fa-chart-column"></i><span>Analyze</span></button>
        <button class="school-bottom-nav-btn" data-section="takers"><i class="fa-solid fa-user-shield"></i><span>Takers</span></button>
    </div>
</nav>

<!-- ═══ MODAL: NEW SESSION ═══ -->
<div class="school-modal" id="mzSessionModal">
    <div class="school-modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 style="font-size:1.1rem;font-weight:700;color:var(--school-text-bright)"><i class="fa-solid fa-calendar-plus"></i> New Session</h3>
            <button onclick="modal('mzSessionModal',false)" style="background:none;border:none;color:var(--school-text-dim);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="school-form-group">
            <label class="school-label">Date *</label>
            <input id="mzSessDate" class="school-input" type="date">
        </div>
        <div class="school-form-group">
            <label class="school-label">Program type *</label>
            <select id="mzSessType" class="school-input">
                <option value="rehearsal">Rehearsal (የዝማሬ ልምምድ)</option>
                <option value="service">Service (አገልግሎት)</option>
                <option value="feast">Feast (በዓል)</option>
                <option value="training">Training (ሥልጠና)</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div class="school-form-group">
            <label class="school-label">Title *</label>
            <input id="mzSessTitle" class="school-input" maxlength="255" placeholder="e.g. የመስቀል በዓል ዝግጅት">
        </div>
        <div class="school-form-group">
            <label class="school-label">Notes</label>
            <input id="mzSessNotes" class="school-input" maxlength="500">
        </div>
        <div class="school-error-msg" id="mzSessError" style="display:none"></div>
        <button class="btn-primary" onclick="Mezmur.createSession()" style="width:100%;justify-content:center"><i class="fa-solid fa-save"></i> Create Session</button>
    </div>
</div>

<!-- ═══ MODAL: ADD TAKER ═══ -->
<div class="school-modal" id="mzTakerModal">
    <div class="school-modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 style="font-size:1.1rem;font-weight:700;color:var(--school-text-bright)"><i class="fa-solid fa-user-plus"></i> Add Attendance Taker</h3>
            <button onclick="modal('mzTakerModal',false)" style="background:none;border:none;color:var(--school-text-dim);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="school-form-group">
            <label class="school-label">Full Name *</label>
            <input id="mzTkName" class="school-input" maxlength="150" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label">Username *</label>
            <input id="mzTkUser" class="school-input" maxlength="50" autocomplete="off">
        </div>
        <div class="school-form-group">
            <label class="school-label">Password * <span style="font-weight:400;color:var(--school-text-dim)">(12+ characters)</span></label>
            <input id="mzTkPass" class="school-input" type="password" minlength="12" maxlength="72" autocomplete="new-password">
        </div>
        <div class="school-error-msg" id="mzTkError" style="display:none"></div>
        <button class="btn-primary" id="mzTkSaveBtn" onclick="Mezmur.createTaker()" style="width:100%;justify-content:center"><i class="fa-solid fa-save"></i> Create Account</button>
    </div>
</div>

<?php
$bodyContent = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
?>
