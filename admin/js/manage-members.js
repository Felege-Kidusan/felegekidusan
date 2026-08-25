/*
 * Paginated member-management directory — shared PaginatedList engine.
 * Load-More appends rows; filters reset to page 1.
 * Used by Information and HR dashboards.
 */
(function () {
    'use strict';

    var list = null;

    var filterMap = {
        manageSearchInput: 'q',
        manageFilterType: 'registration_type',
        manageFilterStatus: 'status',
        manageFilterMemberType: 'member_type',
        manageFilterGender: 'gender',
        manageFilterCity: 'city',
        manageFilterAgeGroup: 'age_group',
        manageFilterEducation: 'education_level'
    };

    function text(v) { return v == null ? '' : String(v); }
    function safeId(v) { var n = Number.parseInt(v, 10); return Number.isSafeInteger(n) && n > 0 ? n : null; }

    function buildRow(member) {
        var id = safeId(member.id);
        if (id === null) return document.createDocumentFragment();

        var row = document.createElement('tr');
        row.className = 'hover:bg-slate-50 transition';

        var mc = document.createElement('td');
        mc.className = 'px-4 py-3';
        var nameDiv = document.createElement('div');
        nameDiv.className = 'font-bold text-slate-800';
        nameDiv.textContent = [text(member.student_name), text(member.father_name)].filter(Boolean).join(' ');
        mc.appendChild(nameDiv);
        var codeDiv = document.createElement('div');
        codeDiv.className = 'text-[10px] text-slate-500';
        codeDiv.textContent = text(member.member_code) || 'No ID';
        mc.appendChild(codeDiv);
        row.appendChild(mc);

        var tc = document.createElement('td');
        tc.className = 'px-4 py-3 capitalize';
        var typeSpan = document.createElement('span');
        typeSpan.className = 'px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold border border-slate-200';
        typeSpan.textContent = text(member.registration_type);
        tc.appendChild(typeSpan);
        row.appendChild(tc);

        var sc = document.createElement('td');
        sc.className = 'px-4 py-3';
        var stSpan = document.createElement('span');
        var stCls = { active: 'bg-emerald-100 text-emerald-700', warning: 'bg-amber-100 text-amber-700', inactive: 'bg-slate-100 text-slate-600' };
        stSpan.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold border border-white shadow-sm ' + (stCls[member.status] || 'bg-gray-100 text-gray-600');
        stSpan.textContent = text(member.status);
        sc.appendChild(stSpan);
        row.appendChild(sc);

        var ac = document.createElement('td');
        ac.className = 'px-4 py-3 text-right';
        var actions = document.createElement('div');
        actions.className = 'flex items-center justify-end gap-2';

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 font-bold text-[11px] transition';
        editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square mr-1" aria-hidden="true"></i> Manage';
        editBtn.addEventListener('click', function () {
            if (typeof window.openManageSheet === 'function') window.openManageSheet(id);
        });
        actions.appendChild(editBtn);

        var archBtn = document.createElement('button');
        archBtn.type = 'button';
        archBtn.className = 'px-3 py-1.5 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-100 font-bold text-[11px] transition';
        archBtn.title = 'Move to Archive';
        archBtn.innerHTML = '<i class="fa-solid fa-box-archive" aria-hidden="true"></i>';
        archBtn.addEventListener('click', function () {
            if (typeof window.archiveMember === 'function') {
                window.archiveMember(String(id), [text(member.student_name), text(member.father_name)].filter(Boolean).join(' '));
            }
        });
        actions.appendChild(archBtn);
        ac.appendChild(actions);
        row.appendChild(ac);
        return row;
    }

    function getParams() {
        var params = { view: 'manager' };
        Object.keys(filterMap).forEach(function (elId) {
            var el = document.getElementById(elId);
            if (el && el.value.trim() !== '') params[filterMap[elId]] = el.value.trim();
        });
        return params;
    }

    function init() {
        var body = document.getElementById('manageMembersTableBody');
        var host = document.getElementById('manageMembersPagination');
        if (!body) return;

        list = new PaginatedList({
            endpoint: '/admin/api_list_members.php',
            bodyElement: body,
            loadMoreHost: host,
            renderRow: buildRow,
            getParams: getParams,
            pageSize: 50
        });

        Object.keys(filterMap).forEach(function (elId) {
            var el = document.getElementById(elId);
            if (!el) return;
            var evt = elId === 'manageSearchInput' ? 'input' : 'change';
            el.addEventListener(evt, debounce(function () { list.reset(); }, 300));
        });
        var resetBtn = document.querySelector('[onclick="resetManageFilters()"]');
        if (resetBtn) resetBtn.addEventListener('click', function () {
            Object.keys(filterMap).forEach(function (elId) {
                var el = document.getElementById(elId); if (el) el.value = '';
            });
            list.reset();
        });
        document.querySelectorAll('[data-section="manage"]').forEach(function (btn) {
            btn.addEventListener('click', function () { list.reset(); });
        });
        var section = document.getElementById('section-manage');
        if (section && section.classList.contains('active')) list.reset();
    }

    function debounce(fn, ms) {
        var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); };
    }

    window.loadManageMembers = function () { if (list) list.reset(); };
    window.applyManageFilters = function () { if (list) list.reset(); };
    window.resetManageFilters = function () {
        Object.keys(filterMap).forEach(function (id) { var e = document.getElementById(id); if (e) e.value = ''; });
        if (list) list.reset();
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}());
