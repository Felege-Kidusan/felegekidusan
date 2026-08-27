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
    </div>
</nav>

<?php
$bodyContent = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
?>
