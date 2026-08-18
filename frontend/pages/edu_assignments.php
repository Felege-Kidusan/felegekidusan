<?php
/**
 * Education — Smart Assignments (HTML shell).
 * Logic lives in frontend/js/education_assignments.js
 * Data lives in admin/api_assignments.php via AssignmentService.
 */

if (!defined('ROOT_PATH')) {
    $cfg = dirname(__DIR__, 2) . '/config.php';
    if (is_file($cfg)) {
        require_once $cfg;
    }
}

$pageTitle  = 'Assignments';
$pageScript = 'education_assignments';
$bodyClass  = 'page-edu-assignments';
$requiredRoles = ['super_admin', 'school_admin', 'edu_dept'];

$extraHead = '
<style>
.asg-matrix-wrap { overflow:auto; max-height:70vh; border:1px solid var(--school-border); border-radius:12px; }
.asg-matrix { border-collapse:separate; border-spacing:0; min-width:720px; }
.asg-matrix th, .asg-matrix td { vertical-align:top; min-width:132px; }
.asg-matrix thead th { position:sticky; top:0; z-index:2; background:var(--school-bg-alt); }
body.light-mode .asg-matrix thead th { background:#fff; }
.asg-matrix th.sticky, .asg-matrix td.sticky { position:sticky; left:0; z-index:1; background:var(--school-bg-alt); min-width:150px; box-shadow:4px 0 8px rgba(0,0,0,.06); }
.asg-matrix thead th.sticky { z-index:3; }
body.light-mode .asg-matrix th.sticky, body.light-mode .asg-matrix td.sticky { background:#fff; }
.asg-cell { min-height:56px; cursor:pointer; border-radius:8px; padding:.4rem; }
.asg-cell:hover { background:var(--school-accent-a10); }
.asg-chip { display:inline-flex; align-items:center; gap:4px; margin:2px; padding:2px 8px; border-radius:999px; font-size:.65rem; font-weight:600; max-width:100%; }
.asg-chip-p { background:var(--school-accent-a20); color:var(--school-text-bright); }
.asg-chip-a { background:var(--school-surface-hover); color:var(--school-text-muted); border:1px solid var(--school-border); }
.asg-chip button { background:none; border:none; color:inherit; cursor:pointer; padding:0; font-size:.7rem; opacity:.7; }
.asg-chip button:hover { opacity:1; color:var(--school-danger); }
.asg-empty { color:var(--school-text-dim); font-size:.68rem; }
.asg-warn { background:var(--school-warning-bg); border:1px solid rgba(245,158,11,.35); color:var(--school-warning); border-radius:12px; padding:.75rem 1rem; margin-bottom:1rem; font-size:.82rem; }
.asg-class-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.4rem; max-height:280px; overflow:auto; }
.asg-class-grid label { display:flex; align-items:center; gap:.4rem; font-size:.8rem; padding:.35rem .5rem; border:1px solid var(--school-border); border-radius:8px; cursor:pointer; }
.asg-teacher-hit { padding:.55rem .7rem; cursor:pointer; border-bottom:1px solid var(--school-border); font-size:.82rem; }
.asg-teacher-hit:hover { background:var(--school-accent-a10); }
.asg-tabs { display:flex; gap:0; border-bottom:2px solid var(--school-border); margin-bottom:1rem; }
.asg-tab { background:none; border:none; padding:.6rem 1.1rem; color:var(--school-text-dim); font-size:.82rem; font-weight:500; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; font-family:inherit; }
.asg-tab.active { color:var(--school-nav-active-text); border-bottom-color:var(--school-nav-active-text); font-weight:600; }
</style>
';

ob_start();

if (function_exists('ay_context_bar_html') && isset($conn)) {
    echo ay_context_bar_html($conn);
}
?>
<div class="school-layout">
    <aside class="school-sidebar">
        <div class="school-brand">
            <div class="school-brand-logo"><i class="fa-solid fa-diagram-project"></i></div>
            <div>
                <div class="school-brand-name"><span data-school-short></span> Education</div>
                <div class="school-brand-sub amharic" data-school-dept-edu></div>
            </div>
        </div>
        <nav class="school-nav-section">
            <div class="school-nav-title">Assignments</div>
            <ul class="school-nav-list">
                <li><button class="school-nav-link active" data-section="matrix"><i class="fa-solid fa-table-cells"></i> Matrix</button></li>
                <li><button class="school-nav-link" data-section="bulk"><i class="fa-solid fa-layer-group"></i> Bulk Assign</button></li>
                <li><button class="school-nav-link" data-section="coverage"><i class="fa-solid fa-triangle-exclamation"></i> Coverage</button></li>
            </ul>
            <div class="school-nav-title" style="margin-top:1rem">Education</div>
            <ul class="school-nav-list">
                <li><a class="school-nav-link" href="/admin/dashboards/edu_dept.php"><i class="fa-solid fa-arrow-left"></i> Back to Education</a></li>
            </ul>
        </nav>
        <div class="school-sidebar-footer">
            <button class="school-theme-toggle" id="themeToggle" onclick="toggleTheme()">
                <span class="school-theme-toggle-label" id="themeLabel">Dark Mode</span>
                <div class="school-theme-toggle-track"><div class="school-theme-toggle-thumb"></div></div>
            </button>
            <div class="school-user-card">
                <div class="school-user-avatar" data-user-initials></div>
                <div>
                    <div class="school-user-name" data-user-name></div>
                    <div class="school-user-role">Education • <span data-today></span></div>
                </div>
            </div>
            <a href="/backend/auth/logout.php" class="school-logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </aside>

    <div class="school-main">
        <div class="school-topbar">
            <div>
                <h1>Teacher Assignments</h1>
                <div class="school-topbar-sub amharic">መምህራን፣ ትምህርት ዓይነት እና ክፍል</div>
            </div>
            <div id="asgYearBadge" class="school-status-badge">
                <span class="school-status-dot"></span> <span id="asgYearName">Loading…</span>
            </div>
        </div>

        <div class="school-content">
            <div id="asgGapsBanner" class="asg-warn" style="display:none"></div>

            <div id="section-matrix" class="school-section active">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
                    <div>
                        <h2 style="font-size:1.15rem;font-weight:700;color:var(--school-text-bright)">Assignment Matrix</h2>
                        <p style="font-size:.75rem;color:var(--school-text-dim)">Click a cell to assign. Primary chip is the grade owner. Assistant chips are helpers.</p>
                    </div>
                    <button class="btn-secondary btn-sm" onclick="Asg.loadAll()"><i class="fa-solid fa-rotate"></i> Refresh</button>
                </div>
                <div id="asgMatrixArea"><p style="color:var(--school-text-dim)">Loading matrix…</p></div>
            </div>

            <div id="section-bulk" class="school-section">
                <h2 style="font-size:1.15rem;font-weight:700;color:var(--school-text-bright);margin-bottom:.35rem">Bulk Assign</h2>
                <p style="font-size:.75rem;color:var(--school-text-dim);margin-bottom:1rem">One teacher → many classes, one save.</p>
                <div class="school-card">
                    <div class="school-form-group">
                        <label class="school-label">Teacher</label>
                        <input id="bulkTeacherQ" class="school-input" placeholder="Search teacher…" autocomplete="off">
                        <div id="bulkTeacherHits" class="school-card" style="display:none;padding:0;margin-top:.35rem"></div>
                        <div id="bulkTeacherPicked" style="margin-top:.4rem;font-size:.82rem;color:var(--school-text-muted)">No teacher selected</div>
                    </div>
                    <div class="grid-2">
                        <div class="school-form-group">
                            <label class="school-label">Subject — or Class Teacher only</label>
                            <select id="bulkSubject" class="school-input"></select>
                        </div>
                        <div class="school-form-group">
                            <label class="school-label">Role</label>
                            <select id="bulkRole" class="school-input">
                                <option value="primary">Primary (grade owner)</option>
                                <option value="assistant">Assistant (helper)</option>
                            </select>
                        </div>
                    </div>
                    <div class="school-form-group">
                        <label class="school-label">Classes</label>
                        <div style="margin-bottom:.4rem">
                            <button type="button" class="btn-secondary btn-sm" onclick="Asg.toggleAllClasses(true)">Select all</button>
                            <button type="button" class="btn-secondary btn-sm" onclick="Asg.toggleAllClasses(false)">Clear</button>
                        </div>
                        <div id="bulkClasses" class="asg-class-grid"></div>
                    </div>
                    <button class="btn-primary" onclick="Asg.saveBulk()" style="width:100%;justify-content:center">
                        <i class="fa-solid fa-check-double"></i> Assign to selected classes
                    </button>
                </div>
            </div>

            <div id="section-coverage" class="school-section">
                <h2 style="font-size:1.15rem;font-weight:700;color:var(--school-text-bright);margin-bottom:.35rem">Coverage</h2>
                <p style="font-size:.75rem;color:var(--school-text-dim);margin-bottom:1rem">Gaps and teacher workload for this year.</p>
                <div class="grid-3" id="asgWorkStats" style="margin-bottom:1rem"></div>
                <div class="grid-2">
                    <div class="school-card">
                        <div class="school-card-title"><i class="fa-solid fa-user-tie"></i> Classes with no Class Teacher</div>
                        <div id="gapHomeroom"></div>
                    </div>
                    <div class="school-card">
                        <div class="school-card-title"><i class="fa-solid fa-book"></i> Subjects with no teacher</div>
                        <div id="gapSubjects"></div>
                    </div>
                </div>
                <div class="school-card">
                    <div class="school-card-title"><i class="fa-solid fa-user-clock"></i> Teachers with no assignment</div>
                    <div id="gapIdle"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="school-modal" id="asgPicker">
    <div class="school-modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 id="asgPickerTitle" style="font-size:1.05rem;font-weight:700;color:var(--school-text-bright)">Assign teacher</h3>
            <button onclick="modal('asgPicker',false)" style="background:none;border:none;color:var(--school-text-dim);font-size:1.25rem;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p id="asgPickerSub" style="font-size:.78rem;color:var(--school-text-dim);margin-bottom:.75rem"></p>
        <div id="asgPickerCurrent" style="margin-bottom:.75rem"></div>
        <div class="school-form-group">
            <label class="school-label">Search teacher</label>
            <input id="asgPickerQ" class="school-input" placeholder="Name or username…" autocomplete="off">
        </div>
        <div id="asgPickerHits" style="max-height:220px;overflow:auto;border:1px solid var(--school-border);border-radius:10px;margin-bottom:.75rem"></div>
        <div class="school-form-group" id="asgPickerRoleWrap">
            <label class="school-label">Role</label>
            <select id="asgPickerRole" class="school-input">
                <option value="primary">Primary (grade owner)</option>
                <option value="assistant">Assistant (helper)</option>
            </select>
        </div>
        <button class="btn-primary" id="asgPickerSave" onclick="Asg.confirmPicker()" style="width:100%;justify-content:center" disabled>
            <i class="fa-solid fa-link"></i> Assign
        </button>
    </div>
</div>

<nav class="school-bottom-nav">
    <div class="school-bottom-nav-inner">
        <button class="school-bottom-nav-btn active" data-section="matrix"><i class="fa-solid fa-table-cells"></i><span>Matrix</span></button>
        <button class="school-bottom-nav-btn" data-section="bulk"><i class="fa-solid fa-layer-group"></i><span>Bulk</span></button>
        <button class="school-bottom-nav-btn" data-section="coverage"><i class="fa-solid fa-triangle-exclamation"></i><span>Coverage</span></button>
        <a class="school-bottom-nav-btn" href="/admin/dashboards/edu_dept.php"><i class="fa-solid fa-arrow-left"></i><span>Education</span></a>
    </div>
</nav>
<?php
$bodyContent = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
