/* Bounded, server-backed ID-card member directory. */
(function () {
    'use strict';

    var endpoint = '/admin/api_list_members.php';
    var state = { cursor: null, history: [], request: null, timer: null, total: null };

    function element(id) { return document.getElementById(id); }

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
                input.type = 'hidden';
                input.name = entry[0];
                input.value = String(entry[1]);
                form.appendChild(input);
            });

        var button = document.createElement('button');
        button.type = 'submit';
        button.className = className;
        button.textContent = label;
        if (action === 'renew') {
            form.addEventListener('submit', function (event) {
                if (!window.confirm('Renew this ID card and reset its issue date?')) event.preventDefault();
            });
        }
        form.appendChild(button);
        return form;
    }

    function textCell(value, className) {
        var cell = document.createElement('td');
        cell.className = className;
        cell.textContent = value || '—';
        return cell;
    }

    function render(members) {
        var body = element('idCardMembersBody');
        if (!body) return;
        body.replaceChildren();
        if (!members.length) {
            var emptyRow = document.createElement('tr');
            var empty = textCell('No eligible members found.', 'px-4 py-8 text-center text-slate-400');
            empty.colSpan = 5;
            emptyRow.appendChild(empty);
            body.appendChild(emptyRow);
            return;
        }

        members.forEach(function (member) {
            var id = Number.parseInt(member.id, 10);
            if (!Number.isSafeInteger(id) || id <= 0) return;
            member.id = id;
            var row = document.createElement('tr');
            row.className = 'hover:bg-slate-50 transition';
            row.appendChild(textCell([member.student_name, member.father_name].filter(Boolean).join(' '), 'px-4 py-3 font-medium'));
            row.appendChild(textCell(member.member_code || 'Pending', 'px-4 py-3 text-xs font-mono'));
            row.appendChild(textCell(member.member_type === 'honorary' ? 'Honorary' : member.registration_type, 'px-4 py-3 text-xs font-semibold text-slate-600'));

            var generated = member.id_card_status === 'generated';
            var expired = generated && isExpired(member.id_card_generated_at);
            var status = textCell(expired ? 'EXPIRED' : (generated ? 'Generated' : 'Not Created'), 'px-4 py-3 text-xs font-semibold');
            status.classList.add(expired ? 'text-red-700' : (generated ? 'text-emerald-700' : 'text-amber-700'));
            row.appendChild(status);

            var actions = document.createElement('td');
            actions.className = 'px-4 py-3 text-right whitespace-nowrap';
            if (generated) {
                var view = document.createElement('a');
                view.href = '/admin/id_cards/view_id_card.php?member_id=' + encodeURIComponent(id);
                view.target = '_blank';
                view.rel = 'noopener noreferrer';
                view.className = 'inline-flex bg-white border border-slate-200 text-slate-700 px-3 py-1.5 rounded text-xs font-medium';
                view.textContent = 'View';
                actions.appendChild(view);
                if (expired) {
                    actions.appendChild(actionForm(member, 'renew', 'Renew', 'bg-blue-600 text-white px-3 py-1.5 rounded text-xs font-medium'));
                }
            } else {
                actions.appendChild(actionForm(member, 'generate', 'Generate', 'bg-emerald-600 text-white px-3 py-1.5 rounded text-xs font-medium'));
            }
            row.appendChild(actions);
            body.appendChild(row);
        });
    }

    function renderPagination(data) {
        var host = element('idCardMembersPagination');
        if (!host) return;
        host.replaceChildren();
        var summary = document.createElement('span');
        summary.className = 'text-xs text-slate-500';
        summary.textContent = state.total === null ? 'Eligible ID-card members' : state.total + ' eligible member(s)';
        host.appendChild(summary);

        var controls = document.createElement('span');
        var previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'px-3 py-1.5 border rounded-lg text-xs disabled:opacity-50';
        previous.textContent = 'Previous';
        previous.disabled = state.history.length === 0;
        previous.addEventListener('click', function () {
            state.cursor = state.history.pop() ?? null;
            load(false);
        });
        controls.appendChild(previous);

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'ml-2 px-3 py-1.5 border rounded-lg text-xs disabled:opacity-50';
        next.textContent = 'Next';
        next.disabled = !data.next_cursor;
        next.addEventListener('click', function () {
            state.history.push(state.cursor);
            state.cursor = data.next_cursor;
            load(false);
        });
        controls.appendChild(next);
        host.appendChild(controls);
    }

    async function load(reset) {
        if (!element('idCardMembersBody')) return;
        if (reset) {
            state.cursor = null;
            state.history = [];
            state.total = null;
        }
        if (state.request) state.request.abort();
        state.request = new AbortController();
        var params = new URLSearchParams({ view: 'id_cards', limit: '50', page: '1' });
        var search = element('idCardMemberSearch');
        if (search && search.value.trim()) params.set('q', search.value.trim());
        if (state.cursor) {
            params.set('cursor', String(state.cursor));
            params.set('include_total', '0');
        }
        try {
            var response = await fetch(endpoint + '?' + params, {
                credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: state.request.signal
            });
            var data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Could not load ID cards');
            if (data.total !== null && data.total !== undefined) state.total = data.total;
            render(Array.isArray(data.members) ? data.members : []);
            renderPagination(data);
        } catch (error) {
            if (error.name !== 'AbortError') {
                render([]);
                console.error('ID-card directory failed', error);
            }
        }
    }

    function initialize() {
        var search = element('idCardMemberSearch');
        if (!search) return;
        search.addEventListener('input', function () {
            clearTimeout(state.timer);
            state.timer = setTimeout(function () { load(true); }, 300);
        });
        window.loadIdCardMembers = function () { load(true); };
        document.querySelectorAll('[data-section="idcards"]').forEach(function (control) {
            control.addEventListener('click', function () { load(true); });
        });
        if (new URLSearchParams(window.location.search).get('section') === 'idcards') {
            load(true);
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}());
