/*
 * All Members directory — full-column PaginatedList with search and filters.
 * Replaces the legacy PHP-rendered 400-row table that couldn't paginate.
 * Used by the "All Members" section in HR and Information dashboards.
 */
(function () {
    'use strict';

    var list = null;
    var rowCounter = 0;

    var filterMap = {
        memberSearchInput: 'q',
        filterType: 'registration_type',
        filterStatus: 'status',
        filterGender: 'gender',
        filterAgeGroup: 'age_group'
    };

    function text(v) { return v == null ? '' : String(v); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function sectionLabel(group) {
        var map = { '7_13': 'ህጻናት (A)', '14_17': 'ማዕከላዊያን (B)', '18_plus': 'ወጣቶች (C)' };
        return map[group] || group || '—';
    }

    function buildRow(member) {
        var id = Number.parseInt(member.id, 10);
        if (!Number.isSafeInteger(id) || id <= 0) return document.createDocumentFragment();
        rowCounter++;

        var row = document.createElement('tr');
        row.className = 'hover:bg-slate-50 transition';
        if (rowCounter % 2 === 0) row.classList.add('bg-slate-50/50');

        function td(html, cls) {
            var d = document.createElement('td');
            d.className = cls || 'px-3 py-2';
            if (html) d.innerHTML = html;
            return d;
        }

        // #
        row.appendChild(td(String(rowCounter), 'px-3 py-2 text-slate-400'));

        // Member name + tier
        var name = [text(member.student_name), text(member.father_name)].filter(Boolean).join(' ');
        var tier = member.membership_tier === 'temporary' ?
            '<span class="text-[9px] text-amber-600 font-semibold">Temporary</span>' :
            '<span class="text-[9px] text-emerald-600 font-semibold">Permanent</span>';
        row.appendChild(td(
            '<div class="font-semibold text-slate-800">' + esc(name) + '</div>' +
            '<div class="text-[10px] text-slate-400">' + esc(member.age_group ? sectionLabel(member.age_group) : '') + ' · ' + tier + '</div>'
        ));

        // Code
        row.appendChild(td('<span class="font-mono text-[10px] text-slate-600">' + esc(text(member.member_code) || '—') + '</span>'));

        // Reg type
        row.appendChild(td('<span class="capitalize text-[10px]">' + esc(text(member.registration_type)) + '</span>'));

        // Member type
        row.appendChild(td('<span class="capitalize text-[10px]">' + esc(text(member.member_type)) + '</span>'));

        // Gender
        var gender = member.gender === 'male' ? '♂' : member.gender === 'female' ? '♀' : '—';
        var genderColor = member.gender === 'male' ? 'text-blue-500' : 'text-pink-500';
        row.appendChild(td('<span class="' + genderColor + ' text-xs">' + gender + '</span>'));

        // Section
        row.appendChild(td('<span class="text-[10px]">' + esc(sectionLabel(member.age_group)) + '</span>'));

        // Status
        var stCls = { active: 'bg-emerald-100 text-emerald-700', warning: 'bg-amber-100 text-amber-700', inactive: 'bg-slate-100 text-slate-600', archived: 'bg-gray-100 text-gray-500' };
        row.appendChild(td('<span class="px-2 py-0.5 rounded-full text-[9px] font-bold ' + (stCls[member.status] || 'bg-gray-100 text-gray-600') + '">' + esc(text(member.status)) + '</span>'));

        // Phone
        row.appendChild(td('<span class="text-[10px] text-slate-600">' + esc(text(member.phone_number) || '—') + '</span>'));

        // Location
        var loc = [text(member.city), text(member.sub_city)].filter(Boolean).join(' / ');
        row.appendChild(td('<span class="text-[10px] text-slate-500">' + esc(loc || '—') + '</span>'));

        // Actions
        var actionsTd = document.createElement('td');
        actionsTd.className = 'px-3 py-2 text-right whitespace-nowrap';
        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'p-1.5 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 transition';
        editBtn.title = 'Edit member';
        editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square text-xs"></i>';
        editBtn.addEventListener('click', function () {
            if (typeof window.openManageSheet === 'function') window.openManageSheet(id);
        });
        actionsTd.appendChild(editBtn);

        var cardBtn = document.createElement('button');
        cardBtn.type = 'button';
        cardBtn.className = 'p-1.5 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition ml-1';
        cardBtn.title = 'ID Card';
        cardBtn.innerHTML = '<i class="fa-solid fa-id-card text-xs"></i>';
        cardBtn.addEventListener('click', function () {
            window.open('/admin/id_cards/view_id_card.php?member_id=' + id, '_blank');
        });
        actionsTd.appendChild(cardBtn);
        row.appendChild(actionsTd);

        return row;
    }

    function getParams() {
        var p = {};
        Object.keys(filterMap).forEach(function (elId) {
            var el = document.getElementById(elId);
            if (el && el.value.trim() !== '') params[filterMap[elId]] = el.value.trim();
        });
        return p;
    }

    function init() {
        var body = document.getElementById('membersTableBody');
        if (!body || body.dataset.paginatedInit) return;
        body.dataset.paginatedInit = '1';

        var table = body.closest('table');
        var container = table ? table.closest('.overflow-x-auto') || table.parentNode : null;
        var host = container ? container.parentNode : null;
        var pagHost = document.createElement('div');
        if (container && container.parentNode) {
            container.parentNode.insertBefore(pagHost, container.nextSibling);
        }

        list = new PaginatedList({
            endpoint: '/admin/api_list_members.php',
            bodyElement: body,
            loadMoreHost: pagHost,
            pageNavHost: pagHost,
            renderRow: buildRow,
            getParams: getParams,
            pageSize: 50
        });

        // Search + filters
        Object.keys(filterMap).forEach(function (elId) {
            var el = document.getElementById(elId);
            if (!el) return;
            var evt = elId === 'memberSearchInput' ? 'input' : 'change';
            el.addEventListener(evt, debounce(function () { list.reset(); rowCounter = 0; }, 300));
        });

        // Load when section becomes visible
        document.querySelectorAll('[data-section="members"]').forEach(function (btn) {
            btn.addEventListener('click', function () { list.reset(); rowCounter = 0; });
        });
        var section = document.getElementById('section-members');
        if (section && section.classList.contains('active')) list.reset();

        window.loadAllMembers = function () { list.reset(); rowCounter = 0; };
    }

    function debounce(fn, ms) { var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}());
