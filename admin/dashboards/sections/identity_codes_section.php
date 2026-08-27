<?php
/**
 * Identity & Codes management section — Super Admin only.
 *
 * Included by admin/dashboards/super-admin.php (in scope: $conn,
 * $csrfToken, $activeSection). All state changes go through the
 * session+CSRF-gated hub APIs:
 *   - /admin/api_identity.php            (departments, positions, types,
 *                                         member identity editor)
 *   - /admin/api_identity_migration.php  (alphabetical renumber + QR)
 *
 * No SQL is issued from this file; the UI is pure presentation.
 */
use App\Services\MemberCategory;

require_once __DIR__ . '/../../backend/services/MemberCategory.php';

$idcCategories = [];
foreach (MemberCategory::letters() as $idcLetter) {
    $idcCategories[] = [
        'letter' => $idcLetter,
        'group' => MemberCategory::groupFor($idcLetter),
        'label_am' => MemberCategory::labelAm($idcLetter),
        'label_en' => MemberCategory::labelEn($idcLetter),
    ];
}
?>
<style>
/* Identity & Codes — scoped styles (idc- prefix) */
.idc-tabs{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem}
.idc-tab{padding:.5rem 1rem;border-radius:99px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#94a3b8;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s}
.idc-tab:hover{background:rgba(255,255,255,.06);color:#e2e8f0}
.idc-tab.active{background:linear-gradient(135deg,var(--p),var(--pl));border-color:transparent;color:#fff;box-shadow:0 4px 12px rgba(34,197,94,.3)}
.idc-pane{display:none}
.idc-pane.active{display:block}
.idc-table{width:100%;border-collapse:collapse;font-size:.8rem}
.idc-table th{text-align:left;font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;padding:.5rem .6rem;border-bottom:1px solid rgba(255,255,255,.08)}
.idc-table td{padding:.55rem .6rem;border-bottom:1px solid rgba(255,255,255,.05);color:#cbd5e1;vertical-align:middle}
.idc-table tr:hover td{background:rgba(255,255,255,.025)}
.idc-codechip{display:inline-block;font-family:'JetBrains Mono',ui-monospace,monospace;font-weight:700;letter-spacing:.06em;padding:.15rem .5rem;border-radius:.4rem;background:rgba(59,130,246,.15);color:#93c5fd}
.idc-muted{color:#64748b;font-size:.72rem}
.idc-rowform{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;align-items:end;margin-bottom:1rem}
.idc-actions{display:flex;gap:.4rem;flex-wrap:wrap}
.idc-preview{font-family:ui-monospace,monospace;font-size:1.35rem;font-weight:800;letter-spacing:.08em;color:#4ade80;background:rgba(34,197,94,.08);border:1px dashed rgba(34,197,94,.35);border-radius:.7rem;padding:.85rem 1.1rem;text-align:center}
.mc-min{font-size:0.72em}
.idc-check{display:flex;align-items:center;gap:.5rem;padding:.45rem .6rem;border-radius:.5rem;border:1px solid rgba(255,255,255,.08);font-size:.78rem;color:#cbd5e1;cursor:pointer;margin-bottom:.4rem}
.idc-check:hover{background:rgba(255,255,255,.04)}
.idc-check input{accent-color:#22c55e}
.idc-search-item{padding:.6rem .75rem;border-radius:.6rem;border:1px solid rgba(255,255,255,.08);margin-bottom:.45rem;cursor:pointer;font-size:.8rem;color:#cbd5e1;display:flex;justify-content:space-between;gap:.6rem;align-items:center}
.idc-search-item:hover{background:rgba(255,255,255,.05);border-color:rgba(34,197,94,.4)}
.idc-log{font-family:ui-monospace,monospace;font-size:.7rem;background:#020617;border:1px solid rgba(255,255,255,.08);border-radius:.6rem;padding:.85rem;max-height:340px;overflow:auto;white-space:pre-wrap;color:#94a3b8}
.idc-msg{margin-top:.75rem;font-size:.78rem;min-height:1.2em}
.idc-msg.ok{color:#4ade80}
.idc-msg.err{color:#f87171}
</style>

<section id="section-identity" class="section <?= $activeSection === 'identity' ? 'active' : '' ?>"<?= $activeSection === 'identity' ? '' : ' hidden' ?>>
    <div class="sec-header">
        <h2 class="sec-title"><i class="fa-solid fa-id-badge"></i> Identity &amp; Codes</h2>
        <p class="sec-desc">Departments, staff positions, membership types, and member code management</p>
    </div>

    <div class="idc-tabs" role="tablist">
        <button type="button" class="idc-tab active" data-idctab="departments"><i class="fa-solid fa-building"></i> Departments</button>
        <button type="button" class="idc-tab" data-idctab="positions"><i class="fa-solid fa-user-tie"></i> Positions</button>
        <button type="button" class="idc-tab" data-idctab="types"><i class="fa-solid fa-tags"></i> Membership Types</button>
        <button type="button" class="idc-tab" data-idctab="members"><i class="fa-solid fa-user-pen"></i> Member Editor</button>
        <button type="button" class="idc-tab" data-idctab="migration"><i class="fa-solid fa-arrows-rotate"></i> Renumbering</button>
    </div>

    <!-- ── TAB: Departments ─────────────────────────────────────────── -->
    <div class="idc-pane active" id="idc-pane-departments">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-plus"></i> Add / Edit Department</h3>
            <p class="idc-muted">Department codes become the first letters of every staff code (e.g. <span class="idc-codechip">ED</span> → EDH… for the Education department head). 1–4 letters A–Z.</p>
            <form id="idcDeptForm" class="idc-rowform" autocomplete="off">
                <input type="hidden" name="id" value="0">
                <div><label class="form-label">Code *</label><input class="form-input" name="code" maxlength="4" pattern="[A-Za-z]{1,4}" required placeholder="ED" style="text-transform:uppercase"></div>
                <div><label class="form-label">Name (Amharic) *</label><input class="form-input eth" name="name_am" maxlength="100" required placeholder="ትምህርት ክፍል"></div>
                <div><label class="form-label">Name (English)</label><input class="form-input" name="name_en" maxlength="100" placeholder="Education"></div>
                <div style="display:flex;align-items:center;gap:.5rem;padding-bottom:.55rem"><input type="checkbox" name="is_active" value="1" checked style="accent-color:#22c55e"><span style="font-size:.75rem;color:#94a3b8">Active</span></div>
                <div class="idc-actions"><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-save"></i> Save</button><button class="btn btn-outline btn-sm" type="button" onclick="IDC.resetDeptForm()">Reset</button></div>
            </form>
            <div class="idc-msg" id="idcDeptMsg"></div>
        </div>
        <div class="card" style="margin-top:1rem">
            <h3 class="card-title"><i class="fa-solid fa-list"></i> Departments</h3>
            <div style="overflow-x:auto"><table class="idc-table"><thead><tr><th>Code</th><th>Name (Amharic)</th><th>Name (English)</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead><tbody id="idcDeptRows"><tr><td colspan="5" class="idc-muted">Loading…</td></tr></tbody></table></div>
        </div>
    </div>

    <!-- ── TAB: Positions ───────────────────────────────────────────── -->
    <div class="idc-pane" id="idc-pane-positions">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-plus"></i> Add / Edit Staff Position</h3>
            <p class="idc-muted">Position letters are concatenated into staff codes (e.g. <span class="idc-codechip">T</span> = Teacher → EDH·T-##### for a head who also teaches).
               Use code <span class="idc-codechip">H</span> for the department-head role of a department. The letter <strong>N is reserved</strong> — it marks ordinary members and is rendered smaller.</p>
            <form id="idcPosForm" class="idc-rowform" autocomplete="off">
                <input type="hidden" name="id" value="0">
                <div><label class="form-label">Department *</label><select class="form-select" name="department_id" id="idcPosDept" required></select></div>
                <div><label class="form-label">Letter code *</label><input class="form-input" name="role_code" maxlength="4" pattern="[A-Za-z]{1,4}" required placeholder="T" style="text-transform:uppercase"></div>
                <div><label class="form-label">Title (Amharic) *</label><input class="form-input eth" name="title_am" maxlength="100" required placeholder="መምህር"></div>
                <div><label class="form-label">Title (English)</label><input class="form-input" name="title_en" maxlength="100" placeholder="Teacher"></div>
                <div style="display:flex;align-items:center;gap:.5rem;padding-bottom:.55rem"><input type="checkbox" name="is_active" value="1" checked style="accent-color:#22c55e"><span style="font-size:.75rem;color:#94a3b8">Active</span></div>
                <div class="idc-actions"><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-save"></i> Save</button><button class="btn btn-outline btn-sm" type="button" onclick="IDC.resetPosForm()">Reset</button></div>
            </form>
            <div class="idc-msg" id="idcPosMsg"></div>
        </div>
        <div class="card" style="margin-top:1rem">
            <h3 class="card-title"><i class="fa-solid fa-list"></i> Staff Positions</h3>
            <div style="overflow-x:auto"><table class="idc-table"><thead><tr><th>Dept</th><th>Code</th><th>Title (Amharic)</th><th>Title (English)</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead><tbody id="idcPosRows"><tr><td colspan="6" class="idc-muted">Loading…</td></tr></tbody></table></div>
        </div>
    </div>

    <!-- ── TAB: Membership Types ────────────────────────────────────── -->
    <div class="idc-pane" id="idc-pane-types">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-tags"></i> Membership Types</h3>
            <p class="idc-muted">The three membership tiers are fixed by the system; their display labels (Amharic / English) are editable here. Keys cannot be renamed — reports and mobile sync depend on them.</p>
            <div style="overflow-x:auto"><table class="idc-table"><thead><tr><th>Type key</th><th>Label (Amharic)</th><th>Label (English)</th><th style="text-align:right">Save</th></tr></thead><tbody id="idcTypeRows"><tr><td colspan="4" class="idc-muted">Loading…</td></tr></tbody></table></div>
            <div class="idc-msg" id="idcTypeMsg"></div>
        </div>
    </div>

    <!-- ── TAB: Member Editor ───────────────────────────────────────── -->
    <div class="idc-pane" id="idc-pane-members">
        <div class="grid-2">
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-magnifying-glass"></i> Find Member</h3>
                <div class="form-group"><input class="form-input" id="idcMemberSearch" type="search" autocomplete="off" placeholder="Search by name or code (min 2 letters)…"></div>
                <div id="idcSearchResults"></div>
            </div>
            <div class="card" id="idcMemberCard" style="display:none">
                <h3 class="card-title"><i class="fa-solid fa-user-pen"></i> <span id="idcMemberName">—</span></h3>
                <p class="idc-muted">Current code: <span class="idc-codechip" id="idcMemberCode">—</span> <span id="idcMemberMeta"></span></p>

                <div class="form-group">
                    <label class="form-label">Category (manual — no automatic age assignment)</label>
                    <select class="form-select" id="idcCatSelect">
                        <?php foreach ($idcCategories as $idcCat): ?>
                        <option value="<?= e($idcCat['group']) ?>"><?= e($idcCat['label_am']) ?> — <?= e($idcCat['label_en']) ?> (<?= e($idcCat['letter']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Membership type</label>
                    <select class="form-select" id="idcTypeSelect"></select>
                </div>

                <div class="form-group">
                    <label class="form-label">Staff positions (leave empty for students)</label>
                    <div id="idcPositionChecks" style="max-height:220px;overflow-y:auto;padding-right:.4rem"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">Live preview</label>
                    <div class="idc-preview" id="idcPreview">—</div>
                </div>

                <div class="idc-actions">
                    <button class="btn btn-primary btn-sm" id="idcSaveProfile" type="button"><i class="fa-solid fa-save"></i> Save category &amp; type</button>
                    <button class="btn btn-primary btn-sm" id="idcSavePositions" type="button"><i class="fa-solid fa-user-tag"></i> Save positions (re-code)</button>
                </div>
                <div class="idc-msg" id="idcMemberMsg"></div>
            </div>
        </div>
    </div>

    <!-- ── TAB: Renumbering migration ───────────────────────────────── -->
    <div class="idc-pane" id="idc-pane-migration">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-arrows-rotate"></i> Alphabetical Renumbering (students)</h3>
            <p style="font-size:.8rem;color:#cbd5e1;margin:.4rem 0 .8rem">
                Renumbers every student code sequentially <strong>A1, A2, … / B1, B2, … / C1, C2, …</strong>,
                ordered alphabetically by name inside each category. Old codes are preserved in
                <span class="idc-codechip" style="font-size:.68rem">legacy_member_code</span>, every QR image is regenerated,
                and allocation sequences are resynced so new registrations continue after the highest number.
                Staff codes (containing <span class="idc-codechip" style="font-size:.68rem">-</span>) are untouched.
            </p>
            <div class="idc-actions" style="margin-bottom:.75rem">
                <button class="btn btn-outline btn-sm" id="idcDryBtn" type="button"><i class="fa-solid fa-eye"></i> Preview (dry run)</button>
                <button class="btn btn-danger btn-sm" id="idcExecBtn" type="button"><i class="fa-solid fa-bolt"></i> Execute renumbering</button>
            </div>
            <div class="idc-msg" id="idcMigMsg"></div>
            <div class="idc-log" id="idcMigLog">Output will appear here. Always run a dry run first.</div>
        </div>
    </div>
</section>

<script>
(function(){
'use strict';
const CSRF = <?= json_encode($csrfToken ?? '') ?>;
const CATEGORIES = <?= json_encode($idcCategories, JSON_UNESCAPED_UNICODE) ?>;
const API = '/admin/api_identity.php';
const MIG = '/admin/api_identity_migration.php';

/* ── tiny helpers ─────────────────────────────────────────────────── */
const $ = (id) => document.getElementById(id);
function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function msg(el, text, ok){ el.textContent = text || ''; el.className = 'idc-msg ' + (ok ? 'ok' : 'err'); }
async function apiGet(action, params){
    const url = new URL(API, location.origin);
    url.searchParams.set('action', action);
    for (const [k,v] of Object.entries(params || {})) url.searchParams.set(k, v);
    const res = await fetch(url, {credentials:'same-origin'});
    return res.json();
}
async function apiPost(action, fields){
    const fd = new URLSearchParams(); fd.set('action', action); fd.set('csrf_token', CSRF);
    for (const [k,v] of Object.entries(fields)) fd.set(k, v);
    const res = await fetch(API, {method:'POST', credentials:'same-origin', body: fd});
    return res.json();
}
async function migPost(mode){
    const fd = new URLSearchParams(); fd.set('mode', mode); fd.set('csrf_token', CSRF);
    const res = await fetch(MIG, {method:'POST', credentials:'same-origin', body: fd});
    return res.json();
}
/** Render a member code with the N ordinary-marker typeset smaller. */
function mcHtml(code){
    const c = String(code || '').trim();
    if (!c) return '—';
    const e = esc(c);
    if (e.indexOf('-') === -1) return e;               // student code
    const [head, tail] = [e.slice(0, e.indexOf('-')), e.slice(e.indexOf('-') + 1)];
    return head.split('N').join('<span class="mc-min">N</span>') + '-' + tail;
}

/* ── state ────────────────────────────────────────────────────────── */
let departments = [], positions = [], memberTypes = [];
let currentMember = null;
const loaded = {departments:false, positions:false, types:false, members:false, migration:false};

/* ── tabs ─────────────────────────────────────────────────────────── */
document.querySelectorAll('.idc-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.idc-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.idc-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const key = btn.dataset.idctab;
        $('idc-pane-' + key).classList.add('active');
        loadTab(key);
    });
});
function loadTab(key){
    if (key === 'departments' && !loaded.departments) renderDepartments();
    if (key === 'positions' && !loaded.positions) renderPositions();
    if (key === 'types' && !loaded.types) renderTypes();
    if (key === 'members' && !loaded.members) initMembers();
}

/* ── departments ──────────────────────────────────────────────────── */
async function renderDepartments(){
    loaded.departments = true;
    try {
        const r = await apiGet('list_departments');
        if (r.status !== 'success') { msg($('idcDeptMsg'), r.message, false); return; }
        departments = r.departments || [];
        const tbody = $('idcDeptRows'); tbody.innerHTML = '';
        if (!departments.length){ tbody.innerHTML = '<tr><td colspan="5" class="idc-muted">No departments yet — create the first one above.</td></tr>'; }
        departments.forEach(d => {
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td><span class="idc-codechip">' + esc(d.code) + '</span></td>' +
                '<td class="eth">' + esc(d.name_am) + '</td>' +
                '<td>' + esc(d.name_en || '—') + '</td>' +
                '<td><span class="badge ' + (d.is_active ? 'badge-active">Active' : 'badge-inactive">Inactive') + '</span></td>' +
                '<td style="text-align:right"><div class="idc-actions">' +
                    '<button class="btn btn-outline btn-sm" data-act="edit" data-id="' + d.id + '">Edit</button>' +
                    (d.is_active ? '<button class="btn btn-danger btn-sm" data-act="deact" data-id="' + d.id + '">Deactivate</button>' : '') +
                '</div></td>';
            tr.querySelector('[data-act=edit]').addEventListener('click', () => editDept(d));
            const deact = tr.querySelector('[data-act=deact]');
            if (deact) deact.addEventListener('click', () => deactivateDept(d));
            tbody.appendChild(tr);
        });
        refreshDeptSelects();
    } catch(e){ msg($('idcDeptMsg'), 'Failed to load departments.', false); }
}
function editDept(d){
    const f = $('idcDeptForm');
    f.id.value = d.id; f.code.value = d.code; f.name_am.value = d.name_am || '';
    f.name_en.value = d.name_en || ''; f.is_active.checked = !!d.is_active;
    f.code.focus();
}
function deactivateDept(d){
    if (!confirm('Deactivate department ' + d.code + ' (' + (d.name_en || d.name_am) + ')? Existing codes keep working.')) return;
    apiPost('delete_department', {id: d.id}).then(r => {
        msg($('idcDeptMsg'), r.message, r.status === 'success');
        if (r.status === 'success') renderDepartments();
    });
}
$('idcDeptForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target;
    const fields = {id: f.id.value, code: f.code.value.toUpperCase(), name_am: f.name_am.value, name_en: f.name_en.value};
    if (f.is_active.checked) fields.is_active = '1';
    const r = await apiPost('save_department', fields);
    msg($('idcDeptMsg'), r.message, r.status === 'success');
    if (r.status === 'success'){ resetDeptForm(); renderDepartments(); }
});
window.IDC = window.IDC || {};
IDC.resetDeptForm = function(){ const f = $('idcDeptForm'); f.reset(); f.id.value = '0'; f.is_active.checked = true; };
IDC.resetPosForm = function(){ const f = $('idcPosForm'); f.reset(); f.id.value = '0'; f.is_active.checked = true; };

function refreshDeptSelects(){
    const sel = $('idcPosDept');
    sel.innerHTML = '';
    departments.filter(d => d.is_active).forEach(d => {
        const o = document.createElement('option');
        o.value = d.id; o.textContent = d.code + ' — ' + (d.name_en || d.name_am);
        sel.appendChild(o);
    });
}

/* ── positions ────────────────────────────────────────────────────── */
async function renderPositions(){
    loaded.positions = true;
    if (!departments.length) await renderDepartments();
    try {
        const r = await apiGet('list_positions');
        if (r.status !== 'success'){ msg($('idcPosMsg'), r.message, false); return; }
        positions = r.positions || [];
        const tbody = $('idcPosRows'); tbody.innerHTML = '';
        if (!positions.length){ tbody.innerHTML = '<tr><td colspan="6" class="idc-muted">No positions yet.</td></tr>'; }
        positions.forEach(p => {
            const isHead = p.role_code === 'H';
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td><span class="idc-codechip">' + esc(p.dept_code || '?') + '</span></td>' +
                '<td><span class="idc-codechip"' + (p.role_code === 'N' ? ' style="opacity:.5"' : '') + '>' + esc(p.role_code) + '</span>' + (isHead ? ' <span class="idc-muted">(head)</span>' : '') + '</td>' +
                '<td class="eth">' + esc(p.title_am) + '</td>' +
                '<td>' + esc(p.title_en || '—') + '</td>' +
                '<td><span class="badge ' + (p.is_active ? 'badge-active">Active' : 'badge-inactive">Inactive') + '</span></td>' +
                '<td style="text-align:right"><button class="btn btn-outline btn-sm">Edit</button></td>';
            tr.querySelector('button').addEventListener('click', () => {
                const f = $('idcPosForm');
                f.id.value = p.id; f.department_id.value = p.department_id; f.role_code.value = p.role_code;
                f.title_am.value = p.title_am || ''; f.title_en.value = p.title_en || '';
                f.is_active.checked = !!p.is_active; f.role_code.focus();
            });
            tbody.appendChild(tr);
        });
    } catch(e){ msg($('idcPosMsg'), 'Failed to load positions.', false); }
}
$('idcPosForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const f = ev.target;
    if (!f.department_id.value){ msg($('idcPosMsg'), 'Create an active department first.', false); return; }
    const fields = {id: f.id.value, department_id: f.department_id.value, role_code: f.role_code.value.toUpperCase(), title_am: f.title_am.value, title_en: f.title_en.value};
    if (f.is_active.checked) fields.is_active = '1';
    const r = await apiPost('save_position', fields);
    msg($('idcPosMsg'), r.message, r.status === 'success');
    if (r.status === 'success'){ IDC.resetPosForm(); renderPositions(); }
});

/* ── membership types ─────────────────────────────────────────────── */
async function renderTypes(){
    loaded.types = true;
    try {
        const r = await apiGet('list_member_types');
        if (r.status !== 'success'){ msg($('idcTypeMsg'), r.message, false); return; }
        memberTypes = r.member_types || [];
        const tbody = $('idcTypeRows'); tbody.innerHTML = '';
        memberTypes.forEach(t => {
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td><span class="idc-codechip">' + esc(t.type_key) + '</span></td>' +
                '<td><input class="form-input eth" style="max-width:260px" data-k="am" maxlength="150" value="' + esc(t.label_am) + '"></td>' +
                '<td><input class="form-input" style="max-width:260px" data-k="en" maxlength="150" value="' + esc(t.label_en) + '"></td>' +
                '<td style="text-align:right"><button class="btn btn-primary btn-sm">Save</button></td>';
            tr.querySelector('button').addEventListener('click', async () => {
                const r2 = await apiPost('save_member_type', {
                    type_key: t.type_key,
                    label_am: tr.querySelector('[data-k=am]').value,
                    label_en: tr.querySelector('[data-k=en]').value
                });
                msg($('idcTypeMsg'), r2.message + ' (' + t.type_key + ')', r2.status === 'success');
            });
            tbody.appendChild(tr);
        });
    } catch(e){ msg($('idcTypeMsg'), 'Failed to load membership types.', false); }
}

/* ── member editor ────────────────────────────────────────────────── */
let searchTimer = null;
function initMembers(){
    loaded.members = true;
    if (!positions.length){ renderPositions(); }
    if (!memberTypes.length){
        apiGet('list_member_types').then(r => { if (r.status === 'success') memberTypes = r.member_types || []; });
    }
    $('idcMemberSearch').addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = $('idcMemberSearch').value.trim();
        searchTimer = setTimeout(() => runSearch(q), 300);
    });
    $('idcCatSelect').addEventListener('change', updatePreview);
    $('idcPositionChecks').addEventListener('change', updatePreview);
    $('idcSaveProfile').addEventListener('click', saveProfile);
    $('idcSavePositions').addEventListener('click', savePositions);
}
async function runSearch(q){
    const box = $('idcSearchResults');
    if (q.length < 2){ box.innerHTML = ''; return; }
    try {
        const r = await apiGet('identity_search', {q});
        if (r.status !== 'success') return;
        box.innerHTML = '';
        if (!r.members.length){ box.innerHTML = '<p class="idc-muted">No matching members.</p>'; return; }
        r.members.forEach(m => {
            const item = document.createElement('div');
            item.className = 'idc-search-item';
            item.innerHTML = '<span class="eth">' + esc(m.student_name + ' ' + (m.father_name || '')) + '</span><span class="idc-codechip">' + mcHtml(m.member_code) + '</span>';
            item.addEventListener('click', () => openMember(m.id));
            box.appendChild(item);
        });
    } catch(e){ /* transient */ }
}
async function openMember(id){
    try {
        const r = await apiGet('get_member_identity', {member_id: id});
        if (r.status !== 'success'){ alert(r.message || 'Could not load member.'); return; }
        currentMember = r.member;
        $('idcMemberCard').style.display = '';
        $('idcMemberName').textContent = (r.member.student_name || '') + ' ' + (r.member.father_name || '');
        $('idcMemberCode').innerHTML = mcHtml(r.member.member_code);
        $('idcMemberMeta').textContent = ' · status: ' + (r.member.status || '—');
        $('idcCatSelect').value = (r.categories || []).includes(r.member.age_group) ? r.member.age_group : ($('idcCatSelect').options[0] || {}).value;

        const typeSel = $('idcTypeSelect'); typeSel.innerHTML = '';
        memberTypes.forEach(t => {
            const o = document.createElement('option'); o.value = t.type_key;
            o.textContent = t.label_am + ' — ' + t.label_en; typeSel.appendChild(o);
        });
        if (memberTypes.some(t => t.type_key === r.member.member_type)) typeSel.value = r.member.member_type;

        const assigned = new Set((r.assigned_position_ids || []).map(Number));
        const checks = $('idcPositionChecks'); checks.innerHTML = '';
        const activePositions = positions.filter(p => p.is_active);
        if (!activePositions.length){ checks.innerHTML = '<p class="idc-muted">No active positions defined yet.</p>'; }
        activePositions.forEach(p => {
            const label = document.createElement('label'); label.className = 'idc-check';
            const cb = document.createElement('input'); cb.type = 'checkbox'; cb.value = p.id;
            cb.checked = assigned.has(p.id); cb.dataset.dept = p.dept_code || ''; cb.dataset.code = p.role_code;
            const span = document.createElement('span');
            span.innerHTML = '<span class="idc-codechip">' + esc(p.dept_code || '?') + '·' + esc(p.role_code) + '</span> <span class="eth">' + esc(p.title_am) + '</span>' + (p.title_en ? ' <span class="idc-muted">' + esc(p.title_en) + '</span>' : '');
            label.appendChild(cb); label.appendChild(span); checks.appendChild(label);
        });
        updatePreview();
    } catch(e){ alert('Failed to load member details.'); }
}
function selectedPositionMeta(){
    const checks = [...$('idcPositionChecks').querySelectorAll('input:checked')];
    return checks.map(cb => ({id: Number(cb.value), dept: cb.dataset.dept, code: cb.dataset.code}));
}
function updatePreview(){
    const el = $('idcPreview');
    if (!currentMember){ el.innerHTML = '—'; return; }
    const sel = selectedPositionMeta();
    if (!sel.length){
        const cat = CATEGORIES.find(c => c.group === $('idcCatSelect').value);
        el.innerHTML = esc(cat ? cat.letter : '?') + '<span style="opacity:.55">next</span> <span class="idc-muted" style="font-size:.6rem;letter-spacing:0">sequential number assigned on save</span>';
        return;
    }
    const firstDept = sel[0].dept || '?';
    const sameDept = sel.filter(p => p.dept === firstDept);
    const isHead = sameDept.some(p => p.code === 'H');
    const codes = sameDept.filter(p => !(p.code === 'H' && isHead)).map(p => p.code);
    sel.slice(sameDept.length).forEach(p => { if (!codes.includes(p.code)) codes.push(p.code); });
    const head = esc(firstDept) + (isHead ? 'H' : '<span class="mc-min">N</span>') + codes.map(esc).join('');
    el.innerHTML = head + '-<span style="opacity:.55">#####</span>';
}
async function saveProfile(){
    if (!currentMember) return;
    const r = await apiPost('update_member_identity', {
        member_id: currentMember.id,
        age_group: $('idcCatSelect').value,
        member_type: $('idcTypeSelect').value
    });
    msg($('idcMemberMsg'), r.message, r.status === 'success');
    if (r.status === 'success'){ currentMember.member_code = r.member_code; $('idcMemberCode').innerHTML = mcHtml(r.member_code); }
}
async function savePositions(){
    if (!currentMember) return;
    if (!confirm('Re-assign positions for this member? Their identity code will be regenerated and the QR refreshed.')) return;
    const ids = selectedPositionMeta().map(p => p.id);
    const fd = new URLSearchParams();
    fd.set('action', 'assign_positions');
    fd.set('csrf_token', CSRF);
    fd.set('member_id', currentMember.id);
    ids.forEach(id => fd.append('position_ids[]', String(id)));
    const res = await fetch(API, {method:'POST', credentials:'same-origin', body: fd});
    const r = await res.json();
    msg($('idcMemberMsg'), r.message, r.status === 'success');
    if (r.status === 'success' && r.new_code){ currentMember.member_code = r.new_code; $('idcMemberCode').innerHTML = mcHtml(r.new_code); }
}

/* ── migration ────────────────────────────────────────────────────── */
$('idcDryBtn').addEventListener('click', () => runMigration('dry_run'));
$('idcExecBtn').addEventListener('click', () => {
    const word = prompt('This permanently renumbers ALL student codes alphabetically (old codes are kept as legacy).\nType RENUMBER to confirm:');
    if (word !== 'RENUMBER'){ msg($('idcMigMsg'), 'Aborted — confirmation word not entered.', false); return; }
    runMigration('execute');
});
async function runMigration(mode){
    msg($('idcMigMsg'), mode === 'dry_run' ? 'Running preview…' : 'Executing… this may take a while.', true);
    $('idcMigLog').textContent = '';
    const btns = [$('idcDryBtn'), $('idcExecBtn')]; btns.forEach(b => b.disabled = true);
    try {
        const r = await migPost(mode);
        if (r.status !== 'success'){ msg($('idcMigMsg'), r.message || 'Migration failed.', false); }
        else {
            const head = mode === 'dry_run'
                ? 'Preview complete (no changes were made).'
                : 'Done. Renumbered: ' + r.renumbered + ' · QR refreshed: ' + r.qr_refreshed + ' · errors: ' + r.error_count;
            msg($('idcMigMsg'), head, true);
        }
        $('idcMigLog').textContent = (r.log || []).join('\n') || '(no output)';
    } catch(e){
        msg($('idcMigMsg'), 'Migration request failed.', false);
    } finally {
        btns.forEach(b => b.disabled = false);
    }
}
})();
</script>
