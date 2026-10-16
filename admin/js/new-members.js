/*
 * Paginated "New Members" (temporary) directory for HR dashboard.
 * Reuses the shared PaginatedList engine.
 */
(function () {
    'use strict';

    var list = null;

    var filterMap = {
        newMembersSearch: 'q',
        newMembersFilterStatus: 'status',
        newMembersFilterAgeGroup: 'age_group',
        newMembersFilterEligible: 'eligible_for_promotion'
    };

    function text(v) { return v == null ? '' : String(v); }
    function safeId(v) { var n = Number.parseInt(v, 10); return Number.isSafeInteger(n) && n > 0 ? n : null; }

    function monthsSince(dateStr) {
        if (!dateStr) return 0;
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return 0;
        var now = new Date();
        var m = (now.getFullYear() - d.getFullYear()) * 12 + (now.getMonth() - d.getMonth());
        if (now.getDate() < d.getDate()) m--;
        return m < 0 ? 0 : m;
    }

    function buildRow(member) {
        var id = safeId(member.id);
        if (id === null) return document.createDocumentFragment();

        var months = monthsSince(member.registered_at);
        var eligible = months >= 6;

        var row = document.createElement('tr');
        row.className = eligible ? 'hover:bg-emerald-50 transition bg-emerald-50/40' : 'hover:bg-slate-50 transition';

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

        var cc = document.createElement('td');
        cc.className = 'px-4 py-3 text-[11px] text-slate-600';
        cc.textContent = text(member.member_code) || '—';
        row.appendChild(cc);

        var rc = document.createElement('td');
        rc.className = 'px-4 py-3 text-[11px] text-slate-600';
        rc.textContent = member.registered_at ? new Date(member.registered_at).toLocaleDateString() : '—';
        row.appendChild(rc);

        var mmc = document.createElement('td');
        mmc.className = 'px-4 py-3';
        var mBadge = document.createElement('span');
        mBadge.className = eligible
            ? 'px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold border border-emerald-200'
            : 'px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold border border-slate-200';
        mBadge.textContent = months + ' mo';
        mmc.appendChild(mBadge);
        row.appendChild(mmc);

        var sc = document.createElement('td');
        sc.className = 'px-4 py-3';
        var stSpan = document.createElement('span');
        if (eligible) {
            stSpan.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold border border-white shadow-sm bg-emerald-100 text-emerald-700';
            stSpan.textContent = 'Eligible';
        } else {
            var stCls = { active: 'bg-emerald-100 text-emerald-700', warning: 'bg-amber-100 text-amber-700', inactive: 'bg-slate-100 text-slate-600' };
            stSpan.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold border border-white shadow-sm ' + (stCls[member.status] || 'bg-gray-100 text-gray-600');
            stSpan.textContent = text(member.status);
        }
        sc.appendChild(stSpan);
        row.appendChild(sc);

        var ac = document.createElement('td');
        ac.className = 'px-4 py-3 text-right';
        var actions = document.createElement('div');
        actions.className = 'flex items-center justify-end gap-2';

        var promoteBtn = document.createElement('button');
        promoteBtn.type = 'button';
        promoteBtn.className = eligible
            ? 'px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-bold text-[11px] transition'
            : 'px-3 py-1.5 rounded-lg bg-slate-100 text-slate-500 font-bold text-[11px] transition';
        promoteBtn.innerHTML = '<i class="fa-solid fa-arrow-up mr-1" aria-hidden="true"></i> Promote';
        promoteBtn.addEventListener('click', function () {
            if (!eligible) {
                if (!confirm('This member has not yet reached 6 months. Promote anyway?')) return;
            }
            if (typeof window.openUpgradeModal === 'function') {
                window.openUpgradeModal(id);
            }
        });
        actions.appendChild(promoteBtn);
        ac.appendChild(actions);
        row.appendChild(ac);
        return row;
    }

    function getParams() {
        var params = { view: 'new_members', membership_tier: 'temporary' };
        Object.keys(filterMap).forEach(function (elId) {
            var el = document.getElementById(elId);
            if (el && el.value.trim() !== '') params[filterMap[elId]] = el.value.trim();
        });
        return params;
    }

    function init() {
        var body = document.getElementById('newMembersTableBody');
        var host = document.getElementById('newMembersPagination');
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
            var evt = elId === 'newMembersSearch' ? 'input' : 'change';
            el.addEventListener(evt, debounce(function () { list.reset(); }, 300));
        });

        window.loadNewMembers = function () { if (list) list.reset(); };
        window.applyNewMembersFilters = function () { if (list) list.reset(); };
        window.resetNewMembersFilters = function () {
            Object.keys(filterMap).forEach(function (id) { var e = document.getElementById(id); if (e) e.value = ''; });
            if (list) list.reset();
        };

        var section = document.getElementById('section-newmembers');
        if (section && section.classList.contains('active')) list.reset();
    }

    function debounce(fn, ms) { var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}());
