<?php
/**
 * Identity & Codes management section — Super Admin only.
 *
 * Included by admin/dashboards/super-admin.php (in scope: $conn,
 * $csrfToken, $activeSection). Pure presentation: zero SQL here; every
 * read/write flows through the session+CSRF-gated hub APIs
 * (/admin/api_identity.php, /admin/api_identity_migration.php).
 *
 * UX contract (industry standard, single source for this section):
 *   * single-flight actions — a button is disabled + aria-busy + spinner
 *     for the whole request, so double-clicks can never double-write;
 *   * feedback via toast (role=status) + aria-live inline messages;
 *   * destructive/execute flows use an inline modal, never blocking dialogs;
 *   * forms reset and lists refresh on success.
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
.idc-codechip{display:inline-block;font-family:ui-monospace,monospace;font-weight:700;letter-spacing:.06em;padding:.15rem .5rem;border-radius:.4rem;background:rgba(59,130,246,.15);color:#93c5fd}
.idc-muted{color:#64748b;font-size:.72rem}
.idc-rowform{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;align-items:end;margin-bottom:.4rem}
.idc-actions{display:flex;gap:.4rem;flex-wrap:wrap}
.idc-msg{margin-top:.75rem;font-size:.78rem;min-height:1.2em}
.idc-msg.ok{color:#4ade80}
.idc-msg.err{color:#f87171}
.mc-min{font-size:0.72em}
.idc-log{font-family:ui-monospace,monospace;font-size:.7rem;background:#020617;border:1px solid rgba(255,255,255,.08);border-radius:.6rem;padding:.85rem;max-height:340px;overflow:auto;white-space:pre-wrap;color:#94a3b8}
/* button busy state */
.idc-busy{opacity:.6;pointer-events:none}
.idc-spin{display:inline-block;width:.8em;height:.8em;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:idc-rot .7s linear infinite;vertical-align:-.1em}
@keyframes idc-rot{to{transform:rotate(360deg)}}
/* toasts */
#idcToasts{position:fixed;bottom:1.4rem;right:1.4rem;display:flex;flex-direction:column;gap:.5rem;z-index:9999}
.idc-toast{padding:.7rem 1.1rem;border-radius:12px;color:#fff;font-size:.8rem;font-weight:500;box-shadow:0 8px 32px rgba(0,0,0,.4);max-width:380px;backdrop-filter:blur(8px);animation:idc-in .25s ease}
.idc-toast.ok{background:linear-gradient(135deg,#059669,#10b981)}
.idc-toast.err{background:linear-gradient(135deg,#dc2626,#ef4444)}
@keyframes idc-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
/* modal */
#idcModalWrap{position:fixed;inset:0;background:rgba(2,6,23,.7);display:none;align-items:center;justify-content:center;z-index:10000;padding:1rem}
#idcModalWrap.open{display:flex}
.idc-modal{background:#0f172a;border:1px solid rgba(255,255,255,.12);border-radius:1rem;padding:1.4rem;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.5)}
.idc-modal h4{font-size:.95rem;color:#f1f5f9;margin-bottom:.6rem}
.idc-modal p{font-size:.78rem;color:#94a3b8;margin-bottom:.9rem}
</style>

<section id="section-identity" class="section <?= $activeSection === 'identity' ? 'active' : '' ?>"<?= $activeSection === 'identity' ? '' : ' hidden' ?>>
    <div class="sec-header">
        <h2 class="sec-title"><i class="fa-solid fa-id-badge"></i> Identity &amp; Codes</h2>
        <p class="sec-desc">Departments, positions, membership types and the v2 code migration</p>
    </div>

    <div class="idc-tabs" role="tablist">
        <button type="button" class="idc-tab active" data-idctab="departments"><i class="fa-solid fa-building"></i> Departments</button>
        <button type="button" class="idc-tab" data-idctab="positions"><i class="fa-solid fa-user-tie"></i> Positions</button>
        <button type="button" class="idc-tab" data-idctab="types"><i class="fa-solid fa-tags"></i> Membership Types</button>
        <button type="button" class="idc-tab" data-idctab="migration"><i class="fa-solid fa-arrows-rotate"></i> Renumbering</button>
    </div>

    <!-- ── TAB: Departments ─────────────────────────────────────────── -->
    <div class="idc-pane active" id="idc-pane-departments">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-plus"></i> Add / Edit Department</h3>
            <p class="idc-muted">Department codes become the middle of staff codes (EDH = Education head, ED<span class="mc-min">N</span> = ordinary member). 1–4 letters A–Z.</p>
            <form id="idcDeptForm" class="idc-rowform" autocomplete="off">
                <input type="hidden" name="id" value="0">
                <div><label class="form-label">Code *</label><input class="form-input" name="code" maxlength="4" pattern="[A-Za-z]{1,4}" required placeholder="ED" style="text-transform:uppercase"></div>
                <div><label class="form-label">Name (Amharic) *</label><input class="form-input eth" name="name_am" maxlength="100" required placeholder="ትምህርት ፍል"></div>
                <div><label class="form-label">Name (English)</label><input class="form-input" name="name_en" maxlength="100" placeholder="Education"></div>
                <div style="display:flex;align-items:center;gap:.5rem;padding-bottom:.55rem"><input type="checkbox" name="is_active" value="1" checked style="accent-color:#22c55e"><span style="font-size:.75rem;color:#94a3b8">Active</span></div>
                <div class="idc-actions"><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-save"></i> Save</button><button class="btn btn-outline btn-sm" type="button" data-idcreset="idcDeptForm">Reset</button></div>
            </form>
            <div class="idc-msg" id="idcDeptMsg" aria-live="polite"></div>
        </div>
        <div class="card" style="margin-top:1rem">
            <h3 class="card-title"><i class="fa-solid fa-list"></i> Departments</h3>
            <div style="overflow-x:auto"><table class="idc-table"><thead><tr><th>Code</th><th>Name (Amharic)</th><th>Name (English)</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead><tbody id="idcDeptRows"><tr><td colspan="5" class="idc-muted">Loading…</td></tr></tbody></table></div>
        </div>
    </div>

    <!-- ── TAB: Positions ───────────────────────────────────────────── -->
    <div class="idc-pane" id="idc-pane-positions">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-plus"></i> Add / Edit Position</h3>
            <p class="idc-muted">
                Positions may belong to a department or be <strong>free</strong> (Director, Secretary…).
                Free-position letters come FIRST in the code (DEDHT-98798), then the department
                segment, then department positions. <strong>N</strong> is reserved; free positions also
                cannot take <strong>A/B/C</strong> (category letters). “Legacy flag” keeps old dashboards in sync.
            </p>
            <form id="idcPosForm" class="idc-rowform" autocomplete="off">
                <input type="hidden" name="id" value="0">
                <div><label class="form-label">Department</label><select class="form-select" name="department_id" id="idcPosDept"><option value="">— Free position —</option></select></div>
                <div><label class="form-label">Letter code *</label><input class="form-input" name="role_code" maxlength="4" pattern="[A-Za-z]{1,4}" required placeholder="T" style="text-transform:uppercase"></div>
                <div><label class="form-label">Title (Amharic) *</label><input class="form-input eth" name="title_am" maxlength="100" required placeholder="መምህር"></div>
                <div><label class="form-label">Title (English)</label><input class="form-input" name="title_en" maxlength="100" placeholder="Teacher"></div>
                <div><label class="form-label">Legacy flag</label><select class="form-select" name="legacy_flag"><option value="">— none —</option><option value="is_teacher">is_teacher</option><option value="is_staff">is_staff</option><option value="is_committee">is_committee</option><option value="is_volunteer">is_volunteer</option></select></div>
                <div style="display:flex;align-items:center;gap:.5rem;padding-bottom:.55rem"><input type="checkbox" name="is_active" value="1" checked style="accent-color:#22c55e"><span style="font-size:.75rem;color:#94a3b8">Active</span></div>
                <div class="idc-actions"><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-save"></i> Save</button><button class="btn btn-outline btn-sm" type="button" data-idcreset="idcPosForm">Reset</button></div>
            </form>
            <div class="idc-msg" id="idcPosMsg" aria-live="polite"></div>
        </div>
        <div class="card" style="margin-top:1rem">
            <h3 class="card-title"><i class="fa-solid fa-list"></i> Positions</h3>
            <div style="overflow-x:auto"><table class="idc-table"><thead><tr><th>Scope</th><th>Code</th><th>Title (Amharic)</th><th>Title (English)</th><th>Legacy flag</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead><tbody id="idcPosRows"><tr><td colspan="7" class="idc-muted">Loading…</td></tr></tbody></table></div>
        </div>
    </div>

    <!-- ── TAB: Membership Types ────────────────────────────────────── -->
    <div class="idc-pane" id="idc-pane-types">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-tags"></i> Membership Types</h3>
            <p class="idc-muted">The three tiers keep stable keys (reports / mobile sync depend on them); labels are editable here.</p>
            <div style="overflow-x:auto"><table class="idc-table"><thead><tr><th>Type key</th><th>Label (Amharic)</th><th>Label (English)</th><th style="text-align:right">Save</th></tr></thead><tbody id="idcTypeRows"><tr><td colspan="4" class="idc-muted">Loading…</td></tr></tbody></table></div>
            <div class="idc-msg" id="idcTypeMsg" aria-live="polite"></div>
        </div>
    </div>

    <!-- ── TAB: Renumbering migration ───────────────────────────────── -->
    <div class="idc-pane" id="idc-pane-migration">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-arrows-rotate"></i> Format v2 renumbering</h3>
            <p style="font-size:.8rem;color:#cbd5e1;margin:.4rem 0 .8rem">
                Issues the new code shape to every member: students <span class="idc-codechip">A-76392</span>,
                staff <span class="idc-codechip">DEDHT-98798</span> with a fresh random 5-digit tail.
                Old codes are preserved in <span class="idc-codechip" style="font-size:.68rem">legacy_member_code</span>,
                QR images regenerated. Already-correct codes are skipped (safe to re-run).
            </p>
            <div class="idc-actions" style="margin-bottom:.75rem">
                <button class="btn btn-outline btn-sm" id="idcDryBtn" type="button"><i class="fa-solid fa-eye"></i> Preview (dry run)</button>
                <button class="btn btn-danger btn-sm" id="idcExecBtn" type="button"><i class="fa-solid fa-bolt"></i> Execute renumbering</button>
            </div>
            <div class="idc-msg" id="idcMigMsg" aria-live="polite"></div>
            <div class="idc-log" id="idcMigLog">Output will appear here. Always run a dry run first.</div>
        </div>
    </div>

    <!-- shared toast stack + modal -->
    <div id="idcToasts" role="status" aria-live="polite"></div>
    <div id="idcModalWrap" role="dialog" aria-modal="true">
        <div class="idc-modal">
            <h4 id="idcModalTitle">Confirm</h4>
            <p id="idcModalBody"></p>
            <input class="form-input" id="idcModalInput" style="display:none;margin-bottom:.9rem" autocomplete="off">
            <div class="idc-actions" style="justify-content:flex-end">
                <button class="btn btn-outline btn-sm" id="idcModalCancel" type="button">Cancel</button>
                <button class="btn btn-danger btn-sm" id="idcModalOk" type="button">Confirm</button>
            </div>
        </div>
    </div>
</section>

<script>
(function(){
'use strict';
const CSRF = <?= json_encode($csrfToken ?? '') ?>;
const API = '/admin/api_identity.php';
const MIG = '/admin/api_identity_migration.php';

/* ── helpers ──────────────────────────────────────────────────────── */
const $ = (id) => document.getElementById(id);
function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function msg(el, text, ok){ el.textContent = text || ''; el.className = 'idc-msg ' + (ok ? 'ok' : 'err'); }
function toast(text, ok){
    const box = $('idcToasts');
    const el = document.createElement('div');
    el.className = 'idc-toast ' + (ok ? 'ok' : 'err');
    el.textContent = text;
    box.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 320); }, 4200);
}
/** Single-flight: disables the button + spinner for the whole request. */
async function busy(btn, fn){
    if (!btn || btn.classList.contains('idc-busy')) return;
    const original = btn.innerHTML;
    btn.classList.add('idc-busy');
    btn.setAttribute('aria-busy', 'true');
    btn.innerHTML = '<span class="idc-spin"></span> Working…';
    try {
        return await fn();
    } finally {
        btn.innerHTML = original;
        btn.removeAttribute('aria-busy');
        btn.classList.remove('idc-busy');
    }
}
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
/** Inline modal replacement for blocking browser dialogs. Resolves value|null. */
function modal({title, body, confirmLabel = 'Confirm', danger = true, input = null}){
    return new Promise((resolve) => {
        const wrap = $('idcModalWrap');
        $('idcModalTitle').textContent = title;
        $('idcModalBody').textContent = body;
        const inp = $('idcModalInput');
        inp.style.display = input ? '' : 'none';
        inp.value = '';
        if (input) inp.placeholder = input.placeholder || '';
        const okBtn = $('idcModalOk');
        okBtn.textContent = confirmLabel;
        okBtn.className = 'btn btn-sm ' + (danger ? 'btn-danger' : 'btn-primary');
        wrap.classList.add('open');
        if (input) inp.focus(); else okBtn.focus();
        function done(val){
            wrap.classList.remove('open');
            okBtn.removeEventListener('click', onOk);
            $('idcModalCancel').removeEventListener('click', onCancel);
            wrap.removeEventListener('click', onBack);
            resolve(val);
        }
        function onOk(){
            if (input){
                const v = inp.value.trim();
                if (v !== input.expect){ toast('Confirmation word not entered.', false); return; }
                done(v);
            } else done(true);
        }
        function onCancel(){ done(null); }
        function onBack(e){ if (e.target === wrap) done(null); }
        okBtn.addEventListener('click', onOk);
        $('idcModalCancel').addEventListener('click', onCancel);
        wrap.addEventListener('click', onBack);
    });
}

/* ── state ────────────────────────────────────────────────────────── */
let departments = [], positions = [];
const loaded = {};

/* ── tabs ─────────────────────────────────────────────────────────── */
document.querySelectorAll('#section-identity .idc-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('#section-identity .idc-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('#section-identity .idc-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const key = btn.dataset.idctab;
        $('idc-pane-' + key).classList.add('active');
        if (key === 'departments' && !loaded.departments) renderDepartments();
        if (key === 'positions' && !loaded.positions) renderPositions();
        if (key === 'types' && !loaded.types) renderTypes();
    });
});
document.querySelectorAll('[data-idcreset]').forEach(btn => {
    btn.addEventListener('click', () => {
        const f = $(btn.dataset.idcreset);
        f.reset(); f.id.value = '0';
        const active = f.querySelector('[name=is_active]'); if (active) active.checked = true;
    });
});

/* ── departments ─────────────────────────────────────────────────── */
async function renderDepartments(){
    loaded.departments = true;
    const r = await apiGet('list_departments');
    if (r.status !== 'success') { msg($('idcDeptMsg'), r.message, false); return; }
    departments = r.departments || [];
    const tbody = $('idcDeptRows'); tbody.innerHTML = '';
    if (!departments.length) tbody.innerHTML = '<tr><td colspan="5" class="idc-muted">No departments yet — create the first one above.</td></tr>';
    departments.forEach(d => {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><span class="idc-codechip">' + esc(d.code) + '</span></td>' +
            '<td class="eth">' + esc(d.name_am) + '</td>' +
            '<td>' + esc(d.name_en || '—') + '</td>' +
            '<td><span class="badge ' + (d.is_active ? 'badge-active">Active' : 'badge-inactive">Inactive') + '</span></td>' +
            '<td style="text-align:right"><div class="idc-actions">' +
                '<button class="btn btn-outline btn-sm" type="button">Edit</button>' +
                (d.is_active ? '<button class="btn btn-danger btn-sm" type="button">Deactivate</button>' : '') +
            '</div></td>';
        const [editBtn, deactBtn] = tr.querySelectorAll('button');
        editBtn.addEventListener('click', () => {
            const f = $('idcDeptForm');
            f.id.value = d.id; f.code.value = d.code; f.name_am.value = d.name_am || '';
            f.name_en.value = d.name_en || ''; f.is_active.checked = !!d.is_active;
            f.code.focus();
        });
        if (deactBtn) deactBtn.addEventListener('click', () => busy(deactBtn, async () => {
            const ok = await modal({title:'Deactivate department', body:'Deactivate ' + (d.name_en || d.name_am) + ' (' + d.code + ')? Existing member codes keep working.', confirmLabel:'Deactivate'});
            if (!ok) return;
            const r2 = await apiPost('delete_department', {id: d.id});
            toast(r2.message, r2.status === 'success');
            if (r2.status === 'success') renderDepartments();
        }));
        tbody.appendChild(tr);
    });
    refreshDeptSelect();
}
function refreshDeptSelect(){
    const sel = $('idcPosDept');
    const current = sel.value;
    sel.innerHTML = '<option value="">— Free position —</option>';
    departments.filter(d => d.is_active).forEach(d => {
        const o = document.createElement('option');
        o.value = d.id; o.textContent = d.code + ' — ' + (d.name_en || d.name_am);
        sel.appendChild(o);
    });
    sel.value = current;
}
$('idcDeptForm').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const f = ev.target;
    busy(f.querySelector('[type=submit]'), async () => {
        const fields = {id: f.id.value, code: f.code.value.toUpperCase(), name_am: f.name_am.value, name_en: f.name_en.value};
        if (f.is_active.checked) fields.is_active = '1';
        const r = await apiPost('save_department', fields);
        msg($('idcDeptMsg'), r.message, r.status === 'success');
        toast(r.message, r.status === 'success');
        if (r.status === 'success'){ f.reset(); f.id.value = '0'; f.is_active.checked = true; renderDepartments(); }
    });
});

/* ── positions ────────────────────────────────────────────────────── */
async function renderPositions(){
    loaded.positions = true;
    if (!loaded.departments) await renderDepartments();
    const r = await apiGet('list_positions');
    if (r.status !== 'success'){ msg($('idcPosMsg'), r.message, false); return; }
    positions = r.positions || [];
    const tbody = $('idcPosRows'); tbody.innerHTML = '';
    if (!positions.length) tbody.innerHTML = '<tr><td colspan="7" class="idc-muted">No positions yet.</td></tr>';
    positions.forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + (p.dept_code ? '<span class="idc-codechip">' + esc(p.dept_code) + '</span>' : '<span class="badge badge-active">Free</span>') + '</td>' +
            '<td><span class="idc-codechip">' + esc(p.role_code) + '</span>' + (p.role_code === 'H' ? ' <span class="idc-muted">(head)</span>' : '') + '</td>' +
            '<td class="eth">' + esc(p.title_am) + '</td>' +
            '<td>' + esc(p.title_en || '—') + '</td>' +
            '<td>' + esc(p.legacy_flag || '—') + '</td>' +
            '<td><span class="badge ' + (p.is_active ? 'badge-active">Active' : 'badge-inactive">Inactive') + '</span></td>' +
            '<td style="text-align:right"><button class="btn btn-outline btn-sm" type="button">Edit</button></td>';
        tr.querySelector('button').addEventListener('click', () => {
            const f = $('idcPosForm');
            f.id.value = p.id; f.department_id.value = p.department_id || ''; f.role_code.value = p.role_code;
            f.title_am.value = p.title_am || ''; f.title_en.value = p.title_en || '';
            f.legacy_flag.value = p.legacy_flag || ''; f.is_active.checked = !!p.is_active;
            f.role_code.focus();
        });
        tbody.appendChild(tr);
    });
}
$('idcPosForm').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const f = ev.target;
    busy(f.querySelector('[type=submit]'), async () => {
        const fields = {
            id: f.id.value,
            department_id: f.department_id.value || '',
            role_code: f.role_code.value.toUpperCase(),
            title_am: f.title_am.value,
            title_en: f.title_en.value,
            legacy_flag: f.legacy_flag.value
        };
        if (f.is_active.checked) fields.is_active = '1';
        const r = await apiPost('save_position', fields);
        msg($('idcPosMsg'), r.message, r.status === 'success');
        toast(r.message, r.status === 'success');
        if (r.status === 'success'){ f.reset(); f.id.value = '0'; f.is_active.checked = true; renderPositions(); }
    });
});

/* ── membership types ─────────────────────────────────────────────── */
async function renderTypes(){
    loaded.types = true;
    const r = await apiGet('list_member_types');
    if (r.status !== 'success'){ msg($('idcTypeMsg'), r.message, false); return; }
    const tbody = $('idcTypeRows'); tbody.innerHTML = '';
    (r.member_types || []).forEach(t => {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><span class="idc-codechip">' + esc(t.type_key) + '</span></td>' +
            '<td><input class="form-input eth" style="max-width:260px" data-k="am" maxlength="150" value="' + esc(t.label_am) + '"></td>' +
            '<td><input class="form-input" style="max-width:260px" data-k="en" maxlength="150" value="' + esc(t.label_en) + '"></td>' +
            '<td style="text-align:right"><button class="btn btn-primary btn-sm" type="button">Save</button></td>';
        tr.querySelector('button').addEventListener('click', (e) => busy(e.currentTarget, async () => {
            const r2 = await apiPost('save_member_type', {
                type_key: t.type_key,
                label_am: tr.querySelector('[data-k=am]').value,
                label_en: tr.querySelector('[data-k=en]').value
            });
            msg($('idcTypeMsg'), r2.message + ' (' + t.type_key + ')', r2.status === 'success');
            toast(r2.message, r2.status === 'success');
        }));
        tbody.appendChild(tr);
    });
}

/* ── migration ────────────────────────────────────────────────────── */
async function runMigration(mode, btn){
    await busy(btn, async () => {
        if (mode === 'execute'){
            const word = await modal({
                title: 'Execute renumbering',
                body: 'This permanently re-issues identity codes for every member (old codes kept as legacy, QR regenerated).',
                confirmLabel: 'Execute',
                input: {expect: 'RENUMBER', placeholder: 'Type RENUMBER to confirm'}
            });
            if (!word) { msg($('idcMigMsg'), 'Aborted — confirmation word not entered.', false); return; }
        }
        msg($('idcMigMsg'), mode === 'dry_run' ? 'Running preview…' : 'Executing… this may take a while.', true);
        $('idcMigLog').textContent = '';
        const fd = new URLSearchParams(); fd.set('mode', mode); fd.set('csrf_token', CSRF);
        const res = await fetch(MIG, {method:'POST', credentials:'same-origin', body: fd});
        const r = await res.json();
        if (r.status !== 'success'){ msg($('idcMigMsg'), r.message || 'Migration failed.', false); toast(r.message || 'Migration failed.', false); }
        else {
            const head = mode === 'dry_run'
                ? 'Preview complete (no changes made): ' + r.renumbered + ' would change · ' + (r.pending || 0) + ' pending.'
                : 'Done. Renumbered: ' + r.renumbered + ' · QR refreshed: ' + r.qr_refreshed + ' · pending: ' + (r.pending || 0) + ' · errors: ' + r.error_count;
            msg($('idcMigMsg'), head, true);
            toast(head, true);
        }
        $('idcMigLog').textContent = (r.log || []).join('\n') || '(no output)';
    });
}
$('idcDryBtn').addEventListener('click', (e) => runMigration('dry_run', e.currentTarget));
$('idcExecBtn').addEventListener('click', (e) => runMigration('execute', e.currentTarget));
})();
</script>
