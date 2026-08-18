<?php
/**
 * Education — Teachers (one place: login + class assignment).
 * HTML shell only. Logic: frontend/js/education_teachers.js
 */

if (!defined('ROOT_PATH')) {
    $cfg = dirname(__DIR__, 2) . '/config.php';
    if (is_file($cfg)) {
        require_once $cfg;
    }
}

$pageTitle  = 'Teachers';
$pageScript = 'education_teachers';
$bodyClass  = 'page-edu-teachers';
$requiredRoles = ['super_admin', 'school_admin', 'edu_dept'];

$extraHead = '
<style>
.t-list { display:flex; flex-direction:column; gap:.55rem; }
.t-card { display:flex; align-items:center; gap:.75rem; padding:.85rem 1rem; border:1px solid var(--school-border); border-radius:14px; background:var(--school-surface); cursor:pointer; text-align:left; width:100%; font-family:inherit; color:inherit; }
.t-card:hover { border-color:var(--school-border-hover); background:var(--school-surface-hover); }
.t-card.active { border-color:var(--school-accent); box-shadow:0 0 0 2px var(--school-accent-a20); }
.t-av { width:40px; height:40px; border-radius:12px; background:linear-gradient(135deg,var(--school-accent),var(--school-accent-2)); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; flex-shrink:0; }
.t-meta { font-size:.72rem; color:var(--school-text-dim); }
.t-chips { display:flex; flex-wrap:wrap; gap:.3rem; margin-top:.25rem; }
.t-step { font-size:.68rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--school-accent); margin:1.15rem 0 .45rem; }
.t-choice { display:flex; gap:.5rem; flex-wrap:wrap; }
.t-choice label { flex:1; min-width:140px; border:1px solid var(--school-border); border-radius:12px; padding:.7rem .8rem; cursor:pointer; font-size:.82rem; }
.t-choice input { margin-right:.4rem; }
.t-choice label.active { border-color:var(--school-accent); background:var(--school-accent-a10); }
.t-hits { border:1px solid var(--school-border); border-radius:10px; max-height:200px; overflow:auto; margin-top:.35rem; }
.t-hit { padding:.55rem .75rem; cursor:pointer; font-size:.82rem; border-bottom:1px solid var(--school-border); }
.t-hit:hover { background:var(--school-accent-a10); }
.asg-class-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:.4rem; max-height:220px; overflow:auto; }
.asg-class-grid label { display:flex; align-items:center; gap:.4rem; font-size:.8rem; padding:.35rem .5rem; border:1px solid var(--school-border); border-radius:8px; cursor:pointer; }
.asg-chip { display:inline-flex; align-items:center; gap:5px; padding:3px 8px; border-radius:999px; font-size:.68rem; font-weight:600; background:var(--school-accent-a20); }
.asg-chip button { background:none; border:none; cursor:pointer; color:inherit; opacity:.7; }
.asg-chip button:hover { color:var(--school-danger); opacity:1; }
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
            <div class="school-brand-logo"><i class="fa-solid fa-chalkboard-teacher"></i></div>
            <div>
                <div class="school-brand-name"><span data-school-short></span> Education</div>
                <div class="school-brand-sub amharic" data-school-dept-edu></div>
            </div>
        </div>
        <nav class="school-nav-section">
            <div class="school-nav-title">Education</div>
            <ul class="school-nav-list">
                <li><a class="school-nav-link" href="/admin/dashboards/edu_dept.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a></li>
                <li><button class="school-nav-link active" type="button"><i class="fa-solid fa-chalkboard-teacher"></i> Teachers</button></li>
                <li><a class="school-nav-link" href="/admin/dashboards/edu_dept.php?section=classes"><i class="fa-solid fa-school"></i> Classes</a></li>
                <li><a class="school-nav-link" href="/admin/dashboards/edu_dept.php?section=subjects"><i class="fa-solid fa-book"></i> Subjects</a></li>
                <li><a class="school-nav-link" href="/admin/dashboards/edu_dept.php?section=enrollment"><i class="fa-solid fa-user-graduate"></i> Enrollment</a></li>
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
            <a href="/backend/auth/logout.php" class="school-logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </aside>

    <div class="school-main">
        <div class="school-topbar">
            <div>
                <h1>Teachers</h1>
                <div class="school-topbar-sub">Create login, link a member, assign classes — one screen</div>
            </div>
            <button class="btn-primary btn-sm" onclick="Teachers.startNew()"><i class="fa-solid fa-plus"></i> Add Teacher</button>
        </div>

        <div class="school-content">
            <div id="view-list">
                <div class="school-card" style="margin-bottom:1rem">
                    <input id="teacherQ" class="school-input" placeholder="Search teachers by name or username…" autocomplete="off">
                </div>
                <div id="teacherList" class="t-list"><p style="color:var(--school-text-dim)">Loading…</p></div>
            </div>

            <div id="view-form" style="display:none">
                <button class="btn-secondary btn-sm" onclick="Teachers.showList()" style="margin-bottom:1rem"><i class="fa-solid fa-arrow-left"></i> All teachers</button>
                <div class="school-card">
                    <h2 id="formTitle" style="font-size:1.15rem;font-weight:700;color:var(--school-text-bright);margin-bottom:.25rem">Add Teacher</h2>
                    <p style="font-size:.78rem;color:var(--school-text-dim);margin-bottom:.5rem">This creates their login. They use the username and password to open the Teacher portal.</p>

                    <div class="t-step">1 · Who is this person?</div>
                    <div class="school-form-group">
                        <label class="school-label">Link an existing member (optional)</label>
                        <input id="memberQ" class="school-input" placeholder="Search member name or code…" autocomplete="off">
                        <div id="memberHits" class="t-hits" style="display:none"></div>
                        <div id="memberPicked" class="t-meta" style="margin-top:.4rem">Not linked — you can still type a name below.</div>
                    </div>
                    <div class="school-form-group">
                        <label class="school-label">Full name *</label>
                        <input id="fullName" class="school-input" autocomplete="off">
                    </div>

                    <div class="t-step">2 · Login</div>
                    <div class="grid-2">
                        <div class="school-form-group">
                            <label class="school-label">Username *</label>
                            <input id="username" class="school-input" autocomplete="off">
                        </div>
                        <div class="school-form-group">
                            <label class="school-label">Email</label>
                            <input id="email" class="school-input" type="email" autocomplete="off">
                        </div>
                    </div>
                    <div class="school-form-group">
                        <label class="school-label" id="passwordLabel">Password *</label>
                        <input id="password" class="school-input" type="password" autocomplete="new-password">
                        <div class="t-meta" id="passwordHint">They will sign in with this password.</div>
                    </div>

                    <div class="t-step">3 · Teaching this year</div>
                    <div class="school-form-group">
                        <label class="school-label">Role</label>
                        <div class="t-choice" id="roleChoice">
                            <label class="active"><input type="radio" name="teachRole" value="regular" checked> Regular teacher — teaches a subject</label>
                            <label><input type="radio" name="teachRole" value="homeroom"> Class Teacher — homeroom, no subject</label>
                            <label><input type="radio" name="teachRole" value="both"> Both</label>
                        </div>
                    </div>
                    <div class="school-form-group" id="subjectWrap">
                        <label class="school-label">Subject</label>
                        <select id="subjectId" class="school-input"></select>
                    </div>
                    <div class="school-form-group">
                        <label class="school-label">Classes</label>
                        <div style="margin-bottom:.4rem">
                            <button type="button" class="btn-secondary btn-sm" onclick="Teachers.toggleClasses(true)">Select all</button>
                            <button type="button" class="btn-secondary btn-sm" onclick="Teachers.toggleClasses(false)">Clear</button>
                        </div>
                        <div id="classGrid" class="asg-class-grid"></div>
                    </div>
                    <div class="school-form-group" id="homeroomWrap" style="display:none">
                        <label class="school-label">Class Teacher of</label>
                        <select id="homeroomClass" class="school-input"></select>
                    </div>

                    <div id="currentAssign" style="margin-bottom:1rem"></div>

                    <button class="btn-primary" onclick="Teachers.save()" style="width:100%;justify-content:center" id="saveBtn">
                        <i class="fa-solid fa-save"></i> Save teacher
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<nav class="school-bottom-nav">
    <div class="school-bottom-nav-inner">
        <a class="school-bottom-nav-btn" href="/admin/dashboards/edu_dept.php"><i class="fa-solid fa-gauge-high"></i><span>Home</span></a>
        <button class="school-bottom-nav-btn active" type="button"><i class="fa-solid fa-chalkboard-teacher"></i><span>Teachers</span></button>
        <a class="school-bottom-nav-btn" href="/admin/dashboards/edu_dept.php?section=classes"><i class="fa-solid fa-school"></i><span>Classes</span></a>
        <a class="school-bottom-nav-btn" href="/admin/dashboards/edu_dept.php?section=enrollment"><i class="fa-solid fa-user-graduate"></i><span>Enroll</span></a>
    </div>
</nav>
<?php
$bodyContent = ob_get_clean();
require __DIR__ . '/../layouts/base.php';
