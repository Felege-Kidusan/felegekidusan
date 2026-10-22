/**
 * Family Link — HR workspace (guardians, families, review queue, backfill).
 *
 * Talks to /admin/api_guardians.php. Mounts into #section-family of the HR
 * dashboard; the member profile opens through window.openManageSheet when
 * the dashboard provides it. Light theme to match the HR content area.
 */
(function (window, document) {
  'use strict';

  var API = '/admin/api_guardians.php';
  var state = {
    tab: 'families',
    q: '',
    filter: 'all',
    page: 1,
    guardian: null,       // currently open guardian (object)
    stats: null,
    unlinkedPage: 1,
    inited: false
  };

  // ── helpers ──────────────────────────────────────────────────────
  function $(id) { return document.getElementById(id); }
  function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
  function csrf() {
    var i = document.querySelector('input[name="csrf_token"]');
    if (i && i.value) return i.value;
    if (window.SA_BOOT && window.SA_BOOT.csrf) return window.SA_BOOT.csrf;
    return window.CSRF_TOKEN || '';
  }
  function toast(msg, type) {
    if (typeof window.showToast === 'function') { try { window.showToast(msg, type || 'success'); return; } catch (e) { /* fall through */ } }
    var t = document.createElement('div');
    t.className = 'fam-toast ' + (type === 'error' ? 'fam-toast-err' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('show'); }, 10);
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 3200);
  }
  function get(action, params) {
    var u = new URL(API, window.location.origin);
    u.searchParams.set('action', action);
    Object.keys(params || {}).forEach(function (k) { u.searchParams.set(k, params[k]); });
    return fetch(u.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function post(action, params) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrf());
    Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || d.status !== 'success') { throw new Error((d && d.message) || 'Request failed'); }
      return d;
    });
  }
  function fail(e) { toast((e && e.message) || 'Network error', 'error'); }
  // Danger confirmation dialog (admin/js/danger_confirm.js) — owner decisions 2026-10-21.
  function dcAsk(o) {
    if (window.DangerConfirm && window.DangerConfirm.ask) return window.DangerConfirm.ask(o);
    toast('The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.', 'error');
    return Promise.resolve(false);
  }
  function fmtDate(s) {
    if (!s) return '—';
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(s);
    if (typeof WBWSCalendar !== 'undefined' && typeof WBWSCalendar.formatDateTime === 'function') return WBWSCalendar.formatDateTime(d);
    return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  function relLabel(r) { return r === 'father' ? 'Father' : r === 'mother' ? 'Mother' : 'Guardian'; }
  function kidName(k) { return k.student_name || k.full_name_am || ('#' + k.id); }
  function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
  function openProfile(memberId) {
    if (typeof window.openManageSheet === 'function') { window.openManageSheet(memberId); return; }
    window.location.href = '/admin/info_manage_member.php?id=' + encodeURIComponent(memberId);
  }

  // ── stats strip ──────────────────────────────────────────────────
  function loadStats() {
    return get('stats').then(function (d) {
      if (d.status !== 'success') return;
      state.stats = d.stats;
      var s = d.stats;
      var box = $('famStats');
      if (box) {
        box.innerHTML = [
          kpi('fa-people-roof', 'Guardians', s.guardians, 'Active guardian records', 'emerald', null),
          kpi('fa-children', 'Families with 2+ children', s.families_multi, 'Siblings linked under one guardian', 'sky', 'multi'),
          kpi('fa-link', 'Members linked', s.members_linked, 'Members with a guardian record', 'indigo', null),
          kpi('fa-link-slash', 'Not linked yet', s.members_unlinked, s.members_unlinked ? 'Have a guardian name but no record — click to link' : 'All legacy guardian data linked', s.members_unlinked ? 'amber' : 'slate', 'unlinked'),
          kpi('fa-scale-balanced', 'Needs review', s.pending_reviews, s.pending_reviews ? 'Probable duplicate guardians' : 'No duplicates pending', s.pending_reviews ? 'rose' : 'slate', 'review')
        ].join('');
      }
      var badge = $('famQueueBadge');
      if (badge) { if (s.pending_reviews > 0) { badge.textContent = s.pending_reviews; badge.classList.remove('hidden'); } else { badge.classList.add('hidden'); } }
      setCount('famTabReviewCount', s.pending_reviews);
      setCount('famTabUnlinkedCount', s.members_unlinked);
      var lr = $('famLastRematch');
      if (lr) lr.textContent = s.last_rematch ? ('Last run: ' + fmtDate(s.last_rematch)) : 'Never run on this server yet';
    });
  }
  function setCount(id, n) { var el = $(id); if (!el) return; if (n > 0) { el.textContent = n; el.style.display = ''; } else { el.style.display = 'none'; } }
  function kpi(icon, label, value, hint, color, goto) {
    var map = { emerald: ['bg-emerald-100', 'text-emerald-600'], sky: ['bg-sky-100', 'text-sky-600'], indigo: ['bg-indigo-100', 'text-indigo-600'], amber: ['bg-amber-100', 'text-amber-600'], rose: ['bg-rose-100', 'text-rose-600'], slate: ['bg-slate-100', 'text-slate-500'] }[color] || ['bg-slate-100', 'text-slate-500'];
    var click = goto ? ' onclick="FamilyLink.go(\'' + goto + '\')" role="button" tabindex="0"' : '';
    return '<div class="fam-kpi' + (goto ? ' fam-kpi-click' : '') + '"' + click + '>' +
      '<div class="fam-kpi-icon ' + map[0] + ' ' + map[1] + '"><i class="fa-solid ' + icon + '"></i></div>' +
      '<div><div class="fam-kpi-value">' + esc(value) + '</div><div class="fam-kpi-label">' + esc(label) + '</div><div class="fam-kpi-hint">' + esc(hint) + '</div></div></div>';
  }

  // ── tabs ─────────────────────────────────────────────────────────
  function setTab(name) {
    state.tab = name;
    ['families', 'review', 'unlinked', 'activity'].forEach(function (t) {
      var pane = $('famPane-' + t); if (pane) pane.style.display = t === name ? '' : 'none';
      var btn = $('famTab-' + t); if (btn) btn.classList.toggle('fam-tab-on', t === name);
    });
    if (name === 'families') loadFamilies();
    if (name === 'review') loadQueue();
    if (name === 'unlinked') loadUnlinked();
    if (name === 'activity') loadActivity();
  }
  function go(where) {
    if (where === 'multi') { state.filter = 'multi'; syncFilterChips(); setTab('families'); return; }
    setTab(where);
  }

  // ── families tab ─────────────────────────────────────────────────
  function syncFilterChips() {
    document.querySelectorAll('#famFilters [data-filter]').forEach(function (b) { b.classList.toggle('fam-chip-on', b.getAttribute('data-filter') === state.filter); });
  }
  function loadFamilies() {
    var box = $('famList'); if (!box) return;
    box.innerHTML = '<div class="fam-empty fam-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    get('list_guardians', { q: state.q, filter: state.filter, page: state.page }).then(function (d) {
      if (d.status !== 'success') { box.innerHTML = '<div class="fam-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
      var rows = d.rows || [];
      var meta = $('famListMeta'); if (meta) meta.textContent = d.total + ' guardian' + (d.total === 1 ? '' : 's') + (state.q ? ' matching "' + state.q + '"' : '');
      if (!rows.length) {
        box.innerHTML = emptyFamilies();
        renderPager(d); return;
      }
      box.innerHTML = '<table class="fam-table"><thead><tr><th>Guardian</th><th>Phones</th><th>Children</th><th class="hide-sm">Updated</th><th></th></tr></thead><tbody>' +
        rows.map(function (g) {
          var kids = g.children_names ? String(g.children_names).split(', ') : [];
          var kidsHtml = kids.length ? kids.slice(0, 3).map(function (k) { return '<span class="fam-pill">' + esc(k) + '</span>'; }).join('') + (kids.length > 3 ? '<span class="fam-pill fam-pill-more">+' + (kids.length - 3) + '</span>' : '') : '<span class="text-slate-400">none linked</span>';
          return '<tr class="fam-row" onclick="FamilyLink.open(' + parseInt(g.id, 10) + ')">' +
            '<td><div class="font-semibold text-slate-800">' + esc(g.display_name) + '</div><div class="text-[11px] text-slate-400">' + esc([g.sub_city, g.city].filter(Boolean).join(', ') || '') + '</div></td>' +
            '<td class="text-slate-600">' + (g.phone_primary ? esc(g.phone_primary) : '<span class="text-amber-600"><i class="fa-solid fa-triangle-exclamation"></i> no phone</span>') + (g.phone_alt ? '<div class="text-[11px] text-slate-400">' + esc(g.phone_alt) + '</div>' : '') + '</td>' +
            '<td><span class="fam-count' + (parseInt(g.children_count, 10) >= 2 ? ' fam-count-multi' : '') + '">' + esc(g.children_count) + '</span> ' + kidsHtml + '</td>' +
            '<td class="hide-sm text-[11px] text-slate-400">' + fmtDate(g.updated_at) + '</td>' +
            '<td class="text-right"><i class="fa-solid fa-chevron-right text-slate-300"></i></td></tr>';
        }).join('') + '</tbody></table>';
      renderPager(d);
    }).catch(function () { box.innerHTML = '<div class="fam-empty">Network error</div>'; });
  }
  function emptyFamilies() {
    if (state.q) return '<div class="fam-empty"><i class="fa-regular fa-face-meh text-2xl mb-2"></i><div class="font-semibold">No guardian matches "' + esc(state.q) + '"</div><div class="text-slate-400 mt-1">Try the phone number, a shorter name, or an Amharic spelling. New guardians are created when HR registers a child.</div></div>';
    if (state.filter !== 'all') return '<div class="fam-empty"><div class="font-semibold">Nothing in this filter</div><div class="text-slate-400 mt-1">Switch back to <b>All</b> to see every guardian.</div></div>';
    var s = state.stats || {};
    if (s.members_unlinked > 0) return '<div class="fam-empty"><i class="fa-solid fa-link-slash text-2xl text-amber-500 mb-2"></i><div class="font-semibold">No guardian records yet — but ' + esc(s.members_unlinked) + ' members already have guardian details</div><div class="text-slate-400 mt-1 mb-3">Link them now to build the family picture. Nothing is merged automatically; near-duplicates go to the review queue.</div><button class="fam-btn fam-btn-primary" onclick="FamilyLink.go(\'unlinked\')"><i class="fa-solid fa-wand-magic-sparkles"></i> Go to “Not linked yet”</button></div>';
    return '<div class="fam-empty"><i class="fa-solid fa-people-roof text-2xl text-emerald-500 mb-2"></i><div class="font-semibold">No guardians yet</div><div class="text-slate-400 mt-1">Guardian records are created automatically when HR registers a member with guardian details.</div></div>';
  }
  function renderPager(d) {
    var p = $('famPager'); if (!p) return;
    if (!d || d.pages <= 1) { p.innerHTML = ''; return; }
    p.innerHTML = '<button class="fam-btn" ' + (d.page <= 1 ? 'disabled' : '') + ' onclick="FamilyLink.page(' + (d.page - 1) + ')"><i class="fa-solid fa-chevron-left"></i></button>' +
      '<span class="text-xs text-slate-500">Page ' + d.page + ' of ' + d.pages + '</span>' +
      '<button class="fam-btn" ' + (d.page >= d.pages ? 'disabled' : '') + ' onclick="FamilyLink.page(' + (d.page + 1) + ')"><i class="fa-solid fa-chevron-right"></i></button>';
  }

  // ── guardian drawer ──────────────────────────────────────────────
  function open(gid) {
    var drawer = $('famDrawer'); if (!drawer) return;
    drawer.classList.add('open');
    $('famDrawerBody').innerHTML = '<div class="fam-empty fam-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    var url = new URL(window.location); url.searchParams.set('guardian', gid); history.replaceState(null, '', url);
    get('get', { guardian_id: gid }).then(function (d) {
      if (d.status !== 'success') { $('famDrawerBody').innerHTML = '<div class="fam-empty">' + esc(d.message || 'Not found') + '</div>'; return; }
      state.guardian = d.guardian;
      renderGuardian(d.guardian);
    }).catch(function () { $('famDrawerBody').innerHTML = '<div class="fam-empty">Network error</div>'; });
  }
  function close() {
    var drawer = $('famDrawer'); if (drawer) drawer.classList.remove('open');
    state.guardian = null;
    var url = new URL(window.location); url.searchParams.delete('guardian'); history.replaceState(null, '', url);
    loadFamilies(); loadStats();
  }
  function refresh() { if (state.guardian) open(state.guardian.id); }

  function renderGuardian(g) {
    var kids = g.children || [];
    var merged = g.status === 'merged';
    var html = '';
    if (merged) html += '<div class="fam-note fam-note-warn"><i class="fa-solid fa-code-merge"></i> This record was merged into guardian #' + esc(g.merged_into) + '. <a href="#" onclick="FamilyLink.open(' + parseInt(g.merged_into, 10) + ');return false;" class="underline">Open the surviving record</a>.</div>';

    // header
    html += '<div class="fam-gh"><div class="fam-avatar">' + esc((g.display_name || '?').trim().charAt(0).toUpperCase()) + '</div>' +
      '<div class="min-w-0"><div class="text-lg font-bold text-slate-800 truncate">' + esc(g.display_name) + '</div>' +
      '<div class="text-xs text-slate-500">' + (g.phone_primary ? '<i class="fa-solid fa-phone text-slate-400"></i> ' + esc(g.phone_primary) : '<span class="text-amber-600"><i class="fa-solid fa-triangle-exclamation"></i> no phone on file</span>') + (g.phone_alt ? ' · ' + esc(g.phone_alt) : '') + '</div>' +
      '<div class="text-[11px] text-slate-400 mt-0.5">Guardian #' + esc(g.id) + ' · ' + kids.length + ' child' + (kids.length === 1 ? '' : 'ren') + ' · source: ' + esc(g.source || 'hr') + '</div></div></div>';

    // details (edit form)
    html += '<div class="fam-card"><div class="fam-card-hd"><span><i class="fa-solid fa-id-card text-emerald-500"></i> Guardian details</span><span class="text-[11px] text-slate-400">Saving updates every linked child</span></div>' +
      '<form id="famEditForm" onsubmit="return FamilyLink.save(event)" class="fam-form">' +
      field('display_name', 'Full name', g.display_name, true) + field('phone_primary', 'Primary phone', g.phone_primary) + field('phone_alt', 'Alternate phone', g.phone_alt) +
      field('city', 'City', g.city) + field('sub_city', 'Sub-city', g.sub_city) + field('woreda', 'Woreda', g.woreda) + field('mender', 'Mender', g.mender) + field('block_number', 'Block', g.block_number) + field('house', 'House', g.house) +
      '<div class="fam-form-actions"><button type="submit" class="fam-btn fam-btn-primary" ' + (merged ? 'disabled' : '') + '><i class="fa-solid fa-floppy-disk"></i> Save changes</button></div></form>' +
      '<div class="mt-3"><div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Also known as</div><div class="flex flex-wrap gap-1 items-center">' +
      (g.aliases || []).map(function (a) { return '<span class="fam-pill">' + esc(a) + ' <a href="#" title="Remove alias" onclick="FamilyLink.removeAlias(' + parseInt(g.id, 10) + ',this.parentNode.firstChild.textContent.trim());return false;" class="text-slate-400 hover:text-rose-500">×</a></span>'; }).join('') +
      '<form onsubmit="return FamilyLink.addAlias(event,' + parseInt(g.id, 10) + ')" class="inline-flex gap-1"><input name="alias" placeholder="Add spelling (Amharic ok)" class="fam-input fam-input-sm"><button class="fam-btn fam-btn-sm" type="submit">+ alias</button></form></div>' +
      '<div class="text-[11px] text-slate-400 mt-1">Aliases make this guardian findable under every spelling and improve duplicate detection.</div></div></div>';

    // children
    html += '<div class="fam-card"><div class="fam-card-hd"><span><i class="fa-solid fa-children text-sky-500"></i> Children (' + kids.length + ')</span></div>';
    if (!kids.length) html += '<div class="fam-empty fam-empty-sm">No children linked yet. Add one below.</div>';
    else html += '<table class="fam-table fam-table-sm"><thead><tr><th>Member</th><th>Relation</th><th title="Primary contact: whose phone appears on the member record">Primary</th><th></th></tr></thead><tbody>' + kids.map(function (k) {
      var mid = parseInt(k.id, 10);
      return '<tr><td><a href="#" onclick="FamilyLink.profile(' + mid + ');return false;" class="font-semibold text-slate-800 hover:text-emerald-600">' + esc(kidName(k)) + '</a><div class="text-[11px] text-slate-400">' + esc(k.member_code || 'no code') + (k.current_section ? ' · ' + esc(k.current_section) : '') + (k.status && k.status !== 'active' ? ' · <span class="text-amber-600">' + esc(k.status) + '</span>' : '') + '</div></td>' +
        '<td><select class="fam-input fam-input-sm" onchange="FamilyLink.setRelation(' + parseInt(g.id, 10) + ',' + mid + ',this.value,null)">' + ['father', 'mother', 'other'].map(function (r) { return '<option value="' + r + '"' + (k.relation === r ? ' selected' : '') + '>' + relLabel(r) + '</option>'; }).join('') + '</select></td>' +
        '<td class="text-center">' + (parseInt(k.is_primary, 10) === 1 ? '<span class="fam-pill fam-pill-ok" title="This guardian is the primary contact for ' + esc(kidName(k)) + '">&#9733; Primary</span>' : '<button class="fam-btn fam-btn-sm" title="Make this guardian the primary contact for ' + esc(kidName(k)) + '" onclick="FamilyLink.setRelation(' + parseInt(g.id, 10) + ',' + mid + ',null,1)">Make primary</button>') + '</td>' +
        '<td class="text-right whitespace-nowrap"><button class="fam-btn fam-btn-sm" title="Open member profile" onclick="FamilyLink.profile(' + mid + ')"><i class="fa-regular fa-id-badge"></i></button> <button class="fam-btn fam-btn-sm fam-btn-danger" title="Unlink from this guardian" onclick="FamilyLink.unlink(' + parseInt(g.id, 10) + ',' + mid + ',' + esc(JSON.stringify(String(kidName(k)))) + ')"><i class="fa-solid fa-link-slash"></i></button></td></tr>';
    }).join('') + '</tbody></table>';
    html += '<div class="mt-3" style="position:relative"><div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Add a child</div><div class="flex gap-2"><input id="famKidQ" autocomplete="off" placeholder="Type member name or code…" class="fam-input flex-1" ' + (merged ? 'disabled' : '') + '><select id="famKidRel" class="fam-input" style="width:auto"><option value="father">Father</option><option value="mother">Mother</option><option value="other" selected>Guardian</option></select></div><div id="famKidL" class="fam-dropdown" style="display:none"></div></div></div>';

    // tools
    html += '<div class="fam-card"><div class="fam-card-hd"><span><i class="fa-solid fa-screwdriver-wrench text-slate-400"></i> Tools</span></div>' +
      '<div class="text-xs text-slate-500 mb-2">Is this the same person as another guardian record? Merge keeps <b>this</b> record and moves the other one’s children here (reversible, audited).</div>' +
      '<div style="position:relative"><input id="famMergeQ" autocomplete="off" placeholder="Search the duplicate guardian to merge into this one…" class="fam-input" ' + (merged ? 'disabled' : '') + '><div id="famMergeL" class="fam-dropdown" style="display:none"></div></div></div>';

    $('famDrawerBody').innerHTML = html;
    $('famDrawerTitle').textContent = g.display_name;
    attachKidTypeahead(g.id);
    attachMergeTypeahead(g.id);
  }
  function field(name, label, value, required) {
    return '<label class="fam-field"><span>' + esc(label) + (required ? ' <b class="text-rose-500">*</b>' : '') + '</span><input name="' + name + '" value="' + esc(value || '') + '" class="fam-input" ' + (required ? 'required' : '') + '></label>';
  }
  function save(ev) {
    ev.preventDefault();
    if (!state.guardian) return false;
    var f = ev.target; var data = { guardian_id: state.guardian.id };
    ['display_name', 'phone_primary', 'phone_alt', 'city', 'sub_city', 'woreda', 'mender', 'block_number', 'house'].forEach(function (k) { data[k] = f.elements[k].value; });
    if (!data.display_name.trim()) { toast('Guardian name cannot be empty.', 'error'); return false; }
    post('update', data).then(function () { toast('Saved — all linked children updated.'); refresh(); }).catch(fail);
    return false;
  }
  function addAlias(ev, gid) {
    ev.preventDefault();
    var v = ev.target.elements.alias.value.trim(); if (!v) return false;
    post('add_alias', { guardian_id: gid, alias_name: v }).then(function () { toast('Alias added.'); refresh(); }).catch(fail);
    return false;
  }
  function removeAlias(gid, alias) {
    dcAsk({ tier: 'low', title: 'Remove the alias "' + alias + '"?', summary: 'This spelling will no longer find this guardian when matching or searching.', confirmLabel: 'Remove alias' }).then(function (ok) {
      if (!ok) return;
      post('remove_alias', { guardian_id: gid, alias_name: alias }).then(function () { refresh(); }).catch(fail);
    });
  }
  function setRelation(gid, mid, rel, prim) {
    var p = { guardian_id: gid, member_id: mid };
    if (rel) p.relation = rel;
    if (prim !== null && prim !== undefined) p.is_primary = prim;
    post('set_relation', p).then(function () { toast('Link updated.'); refresh(); }).catch(function (e) { fail(e); refresh(); });
  }
  function unlink(gid, mid, name) {
    dcAsk({ tier: 'medium', title: 'Remove ' + name + ' from this guardian?', object: name,
      summary: 'The family link between this member and this guardian is removed.', intent: 'I want to remove this link',
      consequences: ['This guardian no longer sees ' + name + ' (Parent app, family lists and contact).', 'If another guardian is linked, that guardian becomes the primary contact; otherwise the member has no guardian.'],
      keeps: ['The member record itself is not changed.', 'You can link them again at any time.'],
      phrase: 'UNLINK CHILD', confirmLabel: 'Remove link' }).then(function (ok) {
      if (!ok) return;
      post('unlink', { guardian_id: gid, member_id: mid }).then(function () { toast('Unlinked.'); refresh(); }).catch(fail);
    });
  }
  function attachKidTypeahead(gid) {
    var input = $('famKidQ'), list = $('famKidL'); if (!input || !list) return;
    var run = debounce(function () {
      var q = input.value.trim(); if (q.length < 2) { list.style.display = 'none'; return; }
      fetch('/admin/api_list_members.php?q=' + encodeURIComponent(q) + '&limit=8', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        var rows = (d && d.members) || [];
        if (!rows.length) { list.innerHTML = '<div class="fam-dd-empty">No member found</div>'; list.style.display = 'block'; return; }
        list.innerHTML = rows.map(function (m) { return '<button type="button" data-mid="' + parseInt(m.id, 10) + '" class="fam-dd-item"><b>' + esc(m.student_name || m.full_name_am) + '</b> <span class="text-slate-400">' + esc(m.member_code || '') + (m.current_section ? ' · ' + esc(m.current_section) : '') + '</span>' + (m.guardian_name ? '<div class="text-[11px] text-slate-400">guardian on record: ' + esc(m.guardian_name) + '</div>' : '') + '</button>'; }).join('');
        list.style.display = 'block';
        Array.prototype.forEach.call(list.querySelectorAll('[data-mid]'), function (b) {
          b.addEventListener('mousedown', function (e) { e.preventDefault(); });
          b.addEventListener('click', function () {
            var mid = parseInt(b.getAttribute('data-mid'), 10);
            var rel = $('famKidRel') ? $('famKidRel').value : 'other';
            list.style.display = 'none'; input.value = '';
            post('link', { guardian_id: gid, member_id: mid, relation: rel, contact_priority: 1 }).then(function () { toast('Child linked.'); refresh(); }).catch(fail);
          });
        });
      }).catch(function () { list.style.display = 'none'; });
    }, 250);
    input.addEventListener('input', run);
    input.addEventListener('blur', function () { setTimeout(function () { list.style.display = 'none'; }, 180); });
  }
  function attachMergeTypeahead(gid) {
    var input = $('famMergeQ'), list = $('famMergeL'); if (!input || !list) return;
    var run = debounce(function () {
      var q = input.value.trim(); if (q.length < 2) { list.style.display = 'none'; return; }
      get('search', { q: q }).then(function (d) {
        var rows = ((d && d.guardians) || []).filter(function (x) { return parseInt(x.id, 10) !== parseInt(gid, 10); });
        if (!rows.length) { list.innerHTML = '<div class="fam-dd-empty">No other guardian matches</div>'; list.style.display = 'block'; return; }
        list.innerHTML = rows.map(function (x) { return '<button type="button" data-gid="' + parseInt(x.id, 10) + '" class="fam-dd-item"><b>' + esc(x.display_name) + '</b> <span class="text-slate-400">' + esc(x.phone_primary || 'no phone') + '</span><div class="text-[11px] text-slate-400">' + ((x.children || []).map(function (k) { return esc(k.student_name); }).join(', ') || 'no children') + '</div></button>'; }).join('');
        list.style.display = 'block';
        Array.prototype.forEach.call(list.querySelectorAll('[data-gid]'), function (b) {
          b.addEventListener('mousedown', function (e) { e.preventDefault(); });
          b.addEventListener('click', function () {
            var other = parseInt(b.getAttribute('data-gid'), 10);
            var name = b.querySelector('b').textContent;
            list.style.display = 'none'; input.value = '';
            var into = state.guardian ? state.guardian.display_name : 'this guardian';
            dcAsk({ tier: 'medium', title: 'Merge "' + name + '" into "' + into + '"?', object: name + '  \u2192  ' + into,
              summary: 'Two guardian records become one.', intent: 'I want to merge these guardians',
              consequences: ['All children of "' + name + '" move to "' + into + '".', 'The "' + name + '" record is closed; its spelling is kept as an alias.'],
              keeps: ['No member record is changed.', 'HR can reverse the merge.'],
              phrase: 'MERGE GUARDIANS', confirmLabel: 'Merge guardians' }).then(function (ok) {
              if (!ok) return;
              post('merge', { from_id: other, into_id: gid }).then(function () { toast('Merged.'); refresh(); loadStats(); }).catch(fail);
            });
          });
        });
      }).catch(function () { list.style.display = 'none'; });
    }, 250);
    input.addEventListener('input', run);
    input.addEventListener('blur', function () { setTimeout(function () { list.style.display = 'none'; }, 180); });
  }

  // ── review queue ─────────────────────────────────────────────────
  function evidenceWords(ev) {
    var out = [];
    if (ev.phone) out.push('<span class="fam-ev fam-ev-strong"><i class="fa-solid fa-phone"></i> same phone</span>');
    if (typeof ev.name === 'number') out.push('<span class="fam-ev' + (ev.name >= 0.9 ? ' fam-ev-strong' : '') + '"><i class="fa-solid fa-signature"></i> names ' + Math.round(ev.name * 100) + '% similar</span>');
    if (ev.phonetic) out.push('<span class="fam-ev"><i class="fa-solid fa-ear-listen"></i> sound alike</span>');
    if (ev.address) out.push('<span class="fam-ev"><i class="fa-solid fa-location-dot"></i> same address</span>');
    if (ev.source === 'rematch') out.push('<span class="fam-ev fam-ev-muted">found by re-match</span>');
    return out.join(' ') || '<span class="fam-ev fam-ev-muted">weak match</span>';
  }
  function compareCol(g, side, sid, canMerge) {
    if (!g) return '<div class="fam-cmp"><div class="text-slate-400">Guardian no longer exists</div></div>';
    var kids = (g.children || []);
    return '<div class="fam-cmp"><div class="text-[10px] uppercase tracking-wide text-slate-400 mb-1">Record ' + side + ' · #' + esc(g.id) + '</div>' +
      '<div class="font-bold text-slate-800">' + esc(g.display_name) + '</div>' +
      ((g.aliases || []).length ? '<div class="text-[11px] text-slate-400">aka ' + esc(g.aliases.join(', ')) + '</div>' : '') +
      '<div class="text-xs text-slate-600 mt-1"><i class="fa-solid fa-phone text-slate-400"></i> ' + esc(g.phone_primary || '—') + (g.phone_alt ? ' · ' + esc(g.phone_alt) : '') + '</div>' +
      '<div class="text-xs text-slate-600"><i class="fa-solid fa-location-dot text-slate-400"></i> ' + esc([g.sub_city, g.woreda ? 'W.' + g.woreda : '', g.house ? 'H.' + g.house : ''].filter(Boolean).join(' ') || '—') + '</div>' +
      '<div class="text-xs mt-1"><i class="fa-solid fa-children text-slate-400"></i> ' + (kids.length ? kids.map(function (k) { return '<a href="#" onclick="FamilyLink.profile(' + parseInt(k.id, 10) + ');return false;" class="underline decoration-dotted">' + esc(kidName(k)) + '</a>'; }).join(', ') : '<span class="text-slate-400">no children</span>') + '</div>' +
      '<div class="mt-2 flex gap-1 flex-wrap"><button class="fam-btn fam-btn-sm" onclick="FamilyLink.open(' + parseInt(g.id, 10) + ')"><i class="fa-regular fa-eye"></i> Open</button>' +
      (canMerge ? '<button class="fam-btn fam-btn-sm fam-btn-primary" onclick="FamilyLink.resolve(' + sid + ',\'' + side.toLowerCase() + '\')"><i class="fa-solid fa-code-merge"></i> Keep ' + side + ', merge the other into it</button>' : '') + '</div></div>';
  }
  function loadQueue() {
    var box = $('famQueue'); if (!box) return;
    box.innerHTML = '<div class="fam-empty fam-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    get('list_suggestions').then(function (d) {
      var rows = (d && d.suggestions) || [];
      // Phase 3: the real number waiting (the list shows the best 100).
      var total = Math.max(rows.length, parseInt((d && d.total) || 0, 10) || 0);
      setCount('famTabReviewCount', total);
      var badge = $('famQueueBadge'); if (badge) { if (total) { badge.textContent = total; badge.classList.remove('hidden'); } else { badge.classList.add('hidden'); } }
      if (!rows.length) { box.innerHTML = '<div class="fam-empty"><i class="fa-regular fa-circle-check text-2xl text-emerald-500 mb-2"></i><div class="font-semibold">Nothing to review</div><div class="text-slate-400 mt-1">Near-duplicate guardians appear here when HR registers a child or after a re-match run. Nothing is ever merged automatically.</div></div>'; return; }
      box.innerHTML = rows.map(function (s) {
        var ev = {}; try { ev = JSON.parse(s.evidence || '{}'); } catch (e) { /* ignore */ }
        var score = parseFloat(s.score);
        var sid = parseInt(s.id, 10);
        return '<div class="fam-sug">' +
          '<div class="fam-sug-hd"><div><span class="fam-score ' + (score >= 0.8 ? 'fam-score-hi' : 'fam-score-mid') + '">' + Math.round(score * 100) + '% match</span> ' + evidenceWords(ev) + '</div>' +
          '<div class="text-[11px] text-slate-400">filed ' + fmtDate(s.created_at) + (s.member_id ? ' · at registration of member #' + esc(s.member_id) : '') + '</div></div>' +
          '<div class="fam-cmp-grid">' + compareCol(s.guardian_a_row, 'A', sid, true) + '<div class="fam-cmp-vs">vs</div>' + compareCol(s.guardian_b_row, 'B', sid, true) + '</div>' +
          '<div class="flex justify-end gap-2 mt-2"><button class="fam-btn" onclick="FamilyLink.resolve(' + sid + ',\'keep\')"><i class="fa-solid fa-people-arrows"></i> Different people — keep both</button></div></div>';
      }).join('') + (total > rows.length
        ? '<div class="fam-empty" data-fam-queue-more>Showing the top ' + rows.length + ' of ' + total + ' by match. Resolve these to see the rest.</div>'
        : '');
    }).catch(function () { box.innerHTML = '<div class="fam-empty">Network error</div>'; });
  }
  function resolve(sid, how) {
    if (how === 'keep') {
      post('reject_suggestion', { suggestion_id: sid }).then(function () { toast('Kept as separate guardians.'); loadQueue(); loadStats(); }).catch(fail);
      return;
    }
    dcAsk({ tier: 'medium', title: 'Merge these two guardian records?', summary: 'Two guardian records become one.', intent: 'I want to merge these guardians',
      consequences: ['All children move to the record you chose to keep.', 'The other record is closed; its spelling stays as an alias.'],
      keeps: ['No member record is changed.', 'HR can reverse the merge.'],
      phrase: 'MERGE GUARDIANS', confirmLabel: 'Merge guardians' }).then(function (ok) {
      if (!ok) return;
      post('merge_suggestion', { suggestion_id: sid, keep: how }).then(function () { toast('Merged.'); loadQueue(); loadStats(); }).catch(function (e) { fail(e); loadQueue(); });
    });
  }
  function runRematch(cursor, acc) {
    if (!cursor && !acc) {
      dcAsk({ tier: 'low', title: 'Scan all guardians for probable duplicates?', summary: 'Runs in short steps. Nothing is merged automatically: findings appear in this queue for you to decide.', confirmLabel: 'Start scan' })
        .then(function (ok) { if (ok) runRematch(0, { scanned: 0, filed: 0 }); });
      return;
    }
    cursor = cursor || 0;
    var btn = $('famRematchBtn'), bar = $('famRematchBar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Scanning… ' + acc.scanned + ' checked, ' + acc.filed + ' found'; }
    if (bar) bar.style.display = '';
    post('run_rematch', { cursor: cursor }).then(function (d) {
      acc.scanned += d.scanned; acc.filed += d.filed;
      if (!d.done && d.next_cursor > cursor) { runRematch(d.next_cursor, acc); return; }
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-magnifying-glass-arrow-right"></i> Run re-match now'; }
      if (bar) bar.style.display = 'none';
      toast('Re-match complete: ' + acc.scanned + ' guardians checked, ' + acc.filed + ' new suggestion(s).');
      loadQueue(); loadStats();
    }).catch(function (e) { fail(e); if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-magnifying-glass-arrow-right"></i> Run re-match now'; } if (bar) bar.style.display = 'none'; });
  }

  // ── not linked yet ───────────────────────────────────────────────
  function loadUnlinked() {
    var box = $('famUnlinked'); if (!box) return;
    box.innerHTML = '<div class="fam-empty fam-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    get('unlinked_members', { page: state.unlinkedPage }).then(function (d) {
      var rows = (d && d.rows) || [];
      setCount('famTabUnlinkedCount', d.total || 0);
      var meta = $('famUnlinkedMeta'); if (meta) meta.textContent = (d.total || 0) + ' member' + (d.total === 1 ? '' : 's') + ' with guardian details but no guardian record';
      var allBtn = $('famLinkAllBtn'); if (allBtn) allBtn.style.display = (d.total || 0) > 0 ? '' : 'none';
      if (!rows.length) {
        var s = state.stats || {};
        box.innerHTML = '<div class="fam-empty"><i class="fa-regular fa-circle-check text-2xl text-emerald-500 mb-2"></i><div class="font-semibold">Everything is linked</div><div class="text-slate-400 mt-1">Every member with guardian details has a guardian record.' + (s.members_no_guardian ? ' <b>' + esc(s.members_no_guardian) + '</b> members have no guardian details at all — add them from the member profile when known.' : '') + '</div></div>';
        $('famUnlinkedPager').innerHTML = ''; return;
      }
      box.innerHTML = '<table class="fam-table"><thead><tr><th>Member</th><th>Guardian on record</th><th>Phone</th><th class="text-right">Action</th></tr></thead><tbody>' + rows.map(function (m) {
        var mid = parseInt(m.id, 10);
        return '<tr><td><a href="#" onclick="FamilyLink.profile(' + mid + ');return false;" class="font-semibold text-slate-800 hover:text-emerald-600">' + esc(m.student_name || m.full_name_am) + '</a><div class="text-[11px] text-slate-400">' + esc(m.member_code || 'no code') + (m.current_section ? ' · ' + esc(m.current_section) : '') + '</div></td>' +
          '<td class="text-slate-700">' + esc(m.guardian_name) + '</td><td class="text-slate-600">' + esc(m.guardian_phone1 || '—') + '</td>' +
          '<td class="text-right"><button class="fam-btn fam-btn-sm fam-btn-primary" onclick="FamilyLink.attach(' + mid + ',this)"><i class="fa-solid fa-link"></i> Link</button></td></tr>';
      }).join('') + '</tbody></table>';
      var p = $('famUnlinkedPager');
      p.innerHTML = d.pages > 1 ? '<button class="fam-btn" ' + (d.page <= 1 ? 'disabled' : '') + ' onclick="FamilyLink.unlinkedPage(' + (d.page - 1) + ')"><i class="fa-solid fa-chevron-left"></i></button><span class="text-xs text-slate-500">Page ' + d.page + ' of ' + d.pages + '</span><button class="fam-btn" ' + (d.page >= d.pages ? 'disabled' : '') + ' onclick="FamilyLink.unlinkedPage(' + (d.page + 1) + ')"><i class="fa-solid fa-chevron-right"></i></button>' : '';
    }).catch(function () { box.innerHTML = '<div class="fam-empty">Network error</div>'; });
  }
  function attach(mid, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; }
    post('attach_member', { member_id: mid }).then(function (d) {
      toast(d.mode === 'auto' ? 'Linked to an existing guardian (match found).' : d.mode === 'created' ? 'Guardian record created and linked.' : 'Linked.');
      loadUnlinked(); loadStats();
    }).catch(function (e) { fail(e); if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-link"></i> Link'; } });
  }
  function linkAll(cursor, acc) {
    cursor = cursor || 0; acc = acc || { processed: 0, created: 0, matched: 0, failed: 0 };
    var btn = $('famLinkAllBtn');
    if (!acc || !acc.phrase) {
      var n = (state.stats && state.stats.members_unlinked) || 0;
      dcAsk({ tier: 'high', title: 'Link all ' + (n || '') + ' unlinked members to guardians?', object: (n || 'All') + ' unlinked member(s)',
        summary: 'Every member without a guardian is linked in one run.', intent: 'I want to link all members',
        consequences: [
          (n ? n + ' member(s)' : 'Every unlinked member') + ' is matched to an existing guardian (same phone or near-identical name) or gets a NEW guardian record.',
          'New guardians become visible to Family Link and the Parent app.',
          'Wrong matches have to be fixed one by one afterwards (unlink or merge).'
        ],
        keeps: ['Members that already have a guardian are not touched.', 'Nothing is merged automatically.'],
        phrase: 'LINK ALL', confirmLabel: 'Link all members' }).then(function (r) {
        if (r) linkAll(0, { processed: 0, created: 0, matched: 0, failed: 0, phrase: r.phrase });
      });
      return;
    }
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Linking… ' + acc.processed + ' done'; }
    post('attach_batch', { cursor: cursor, confirm_phrase: acc.phrase }).then(function (d) {
      acc.processed += d.processed; acc.created += d.created; acc.matched += d.matched; acc.failed += d.failed;
      if (!d.done && d.processed > 0) { linkAll(d.next_cursor, acc); return; }
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Link all'; }
      toast('Done: ' + acc.processed + ' linked (' + acc.matched + ' matched existing, ' + acc.created + ' new guardians' + (acc.failed ? ', ' + acc.failed + ' failed' : '') + ').');
      loadUnlinked(); loadStats();
    }).catch(function (e) { fail(e); if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Link all'; } });
  }

  // ── activity ─────────────────────────────────────────────────────
  function loadActivity() {
    var box = $('famActivity'); if (!box) return;
    box.innerHTML = '<div class="fam-empty fam-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    get('activity').then(function (d) {
      var rows = (d && d.rows) || [];
      if (!rows.length) { box.innerHTML = '<div class="fam-empty"><div class="font-semibold">No Family Link activity yet</div><div class="text-slate-400 mt-1">Links, edits, merges and re-match runs are recorded here with who did them.</div></div>'; return; }
      var icon = { 'Guardian Created': 'fa-user-plus text-emerald-500', 'Guardian Updated': 'fa-pen text-sky-500', 'Guardian Linked': 'fa-link text-indigo-500', 'Guardian Unlinked': 'fa-link-slash text-amber-500', 'Guardian Merged': 'fa-code-merge text-rose-500', 'Guardian Rematch': 'fa-magnifying-glass text-slate-500', 'Guardian Backfill': 'fa-wand-magic-sparkles text-emerald-500', 'Suggestion Rejected': 'fa-people-arrows text-slate-500', 'Guardian Alias Added': 'fa-tag text-sky-500', 'Guardian Alias Removed': 'fa-tag text-slate-400' };
      box.innerHTML = '<ul class="fam-activity">' + rows.map(function (r) {
        return '<li><i class="fa-solid ' + (icon[r.action] || 'fa-circle text-slate-300') + '"></i><div><div class="text-xs text-slate-700">' + esc(r.details) + '</div><div class="text-[11px] text-slate-400">' + esc(r.action) + ' · ' + esc(r.username || '') + ' · ' + fmtDate(r.created_at) + '</div></div></li>';
      }).join('') + '</ul>';
    }).catch(function () { box.innerHTML = '<div class="fam-empty">Network error</div>'; });
  }

  // ── registration picker glue (HR "New member" form) ──────────────
  function clearPick() { var i = $('regGuardianLinkId'); if (i) i.value = 0; var c = $('regGuardianPicked'); if (c) c.style.display = 'none'; }
  function initRegistrationPicker() {
    if (!window.GuardianPicker) return;
    var input = $('regGuardianPick'), list = $('regGuardianPickList');
    if (!input || !list || input.__famBound) return;
    input.__famBound = true;
    window.GuardianPicker.attach(input, list, function (g) {
      $('regGuardianLinkId').value = g.id;
      var chip = $('regGuardianPicked');
      chip.style.display = 'block';
      chip.innerHTML = '<i class="fa-solid fa-link text-emerald-600"></i> Will be linked to existing guardian <b>' + esc(g.display_name) + '</b> (' + esc(g.phone_primary || 'no phone') + (g.children && g.children.length ? ' · ' + g.children.length + ' child' + (g.children.length === 1 ? '' : 'ren') + ' already' : '') + '). The typed guardian fields below are replaced by this record on save. <a href="#" onclick="FamilyLink.clearPick(); return false;" class="text-rose-600 underline">clear</a>';
    });
  }

  // ── init ─────────────────────────────────────────────────────────
  function init() {
    initRegistrationPicker();
    if (!$('famStats')) return;
    if (!state.inited) {
      state.inited = true;
      var q = $('famSearch');
      if (q) q.addEventListener('input', debounce(function () { state.q = q.value; state.page = 1; loadFamilies(); }, 300));
      document.querySelectorAll('#famFilters [data-filter]').forEach(function (b) { b.addEventListener('click', function () { state.filter = b.getAttribute('data-filter'); state.page = 1; syncFilterChips(); loadFamilies(); }); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && $('famDrawer') && $('famDrawer').classList.contains('open')) close(); });
    }
    loadStats().then(function () { if (state.tab === 'families') loadFamilies(); });
    setTab(state.tab);
    var params = new URLSearchParams(window.location.search);
    var gid = parseInt(params.get('guardian') || '0', 10);
    if (gid > 0) open(gid);
  }

  window.FamilyLink = {
    init: init, go: go, tab: setTab, page: function (p) { state.page = p; loadFamilies(); }, unlinkedPage: function (p) { state.unlinkedPage = p; loadUnlinked(); },
    open: open, close: close, refresh: refresh, save: save, addAlias: addAlias, removeAlias: removeAlias, setRelation: setRelation, unlink: unlink,
    profile: openProfile, resolve: resolve, runRematch: runRematch, attach: attach, linkAll: linkAll, clearPick: clearPick, loadQueue: loadQueue, loadStats: loadStats
  };
  // legacy names kept for the dashboard nav badge code
  window.famLoadQueue = loadQueue;
  window.famClearPick = clearPick;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})(window, document);
