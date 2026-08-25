/*
 * Bounded, server-backed ID-card member directory — shared PaginatedList engine.
 */
(function () {
    'use strict';

    var list = null;

    function el(id) { return document.getElementById(id); }
    function text(v) { return v == null ? '' : String(v); }

    function isExpired(raw) {
        if (!raw) return false;
        var issued = new Date(String(raw).replace(' ', 'T'));
        if (Number.isNaN(issued.getTime())) return false;
        issued.setFullYear(issued.getFullYear() + 4);
        return issued.getTime() < Date.now();
    }

    function actionForm(member, action, label, className) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = '/admin/id_cards/generate_id_card.php';
        form.className = 'inline-block ml-2';
        [['member_id', member.id], ['action', action], ['csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '']]
            .forEach(function (entry) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = entry[0]; input.value = String(entry[1]);
                form.appendChild(input);
            });
        var btn = document.createElement('button');
        btn.type = 'submit'; btn.className = className; btn.textContent = label;
        if (action === 'renew') form.addEventListener('submit', function (e) {
            if (!window.confirm('Renew this ID card and reset its issue date?')) e.preventDefault();
        });
        form.appendChild(btn);
        return form;
    }

    function buildRow(member) {
        var id = Number.parseInt(member.id, 10);
        if (!Number.isSafeInteger(id) || id <= 0) return document.createDocumentFragment();
        member.id = id;

        var row = document.createElement('tr');
        row.className = 'hover:bg-slate-50 transition';

        function cell(v, cls) {
            var td = document.createElement('td'); td.className = cls;
            td.textContent = text(v) || '—'; return td;
        }

        row.appendChild(cell([text(member.student_name), text(member.father_name)].filter(Boolean).join(' '), 'px-4 py-3 font-medium'));
        row.appendChild(cell(text(member.member_code) || 'Pending', 'px-4 py-3 text-xs font-mono'));
        row.appendChild(cell(text(member.member_type === 'honorary' ? 'Honorary' : text(member.registration_type)), 'px-4 py-3 text-xs font-semibold text-slate-600'));

        var generated = member.id_card_status === 'generated';
        var expired = generated && isExpired(member.id_card_generated_at);
        var statusTd = document.createElement('td'); statusTd.className = 'px-4 py-3 text-xs font-semibold ' + (expired ? 'text-red-700' : generated ? 'text-emerald-700' : 'text-amber-700');
        statusTd.textContent = expired ? 'EXPIRED' : generated ? 'Generated' : 'Not Created';
        row.appendChild(statusTd);

        var actionsTd = document.createElement('td');
        actionsTd.className = 'px-4 py-3 text-right whitespace-nowrap';
        if (generated) {
            var viewLink = document.createElement('a');
            viewLink.href = '/admin/id_cards/view_id_card.php?member_id=' + encodeURIComponent(id);
            viewLink.target = '_blank'; viewLink.rel = 'noopener noreferrer';
            viewLink.className = 'inline-flex bg-white border border-slate-200 text-slate-700 px-3 py-1.5 rounded text-xs font-medium';
            viewLink.textContent = 'View';
            actionsTd.appendChild(viewLink);
            if (expired) actionsTd.appendChild(actionForm(member, 'renew', 'Renew', 'bg-blue-600 text-white px-3 py-1.5 rounded text-xs font-medium ml-2'));
        } else {
            actionsTd.appendChild(actionForm(member, 'generate', 'Generate', 'bg-emerald-600 text-white px-3 py-1.5 rounded text-xs font-medium'));
        }
        row.appendChild(actionsTd);
        return row;
    }

    function init() {
        var body = el('idCardMembersBody');
        var host = el('idCardMembersPagination');
        if (!body) return;

        list = new PaginatedList({
            endpoint: '/admin/api_list_members.php',
            bodyElement: body,
            loadMoreHost: host,
            renderRow: buildRow,
            getParams: function () {
                var p = { view: 'id_cards' };
                var search = el('idCardMemberSearch');
                if (search && search.value.trim()) p.q = search.value.trim();
                return p;
            },
            pageSize: 50
        });

        var search = el('idCardMemberSearch');
        if (search) search.addEventListener('input', debounce(function () { list.reset(); }, 300));
        window.loadIdCardMembers = function () { if (list) list.reset(); };
        document.querySelectorAll('[data-section="idcards"]').forEach(function (c) {
            c.addEventListener('click', function () { if (list) list.reset(); });
        });
        if (new URLSearchParams(window.location.search).get('section') === 'idcards') list.reset();
    }

    function debounce(fn, ms) { var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}());
