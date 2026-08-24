/* Bounded archived-member directory shared by Information and HR dashboards. */
(function () {
    'use strict';

    var endpoint = '/admin/api_list_members.php';
    var state = { cursor: null, history: [], total: null, request: null, timer: null, archiveType: '' };
    var reasonLabels = {
        left_school: 'ከት/ቤት ወጥቷል',
        graduated: 'ተመርቋል',
        transferred: 'ተዛውሯል',
        failed_observation: 'የሙከራ ጊዜ አላጠናቀቀም',
        inactive_long: 'ረጅም ጊዜ ቦዝ',
        deceased: 'አርፏል',
        other: 'ሌላ'
    };

    function element(id) { return document.getElementById(id); }

    function node(tag, className, text) {
        var result = document.createElement(tag);
        if (className) result.className = className;
        if (text !== undefined) result.textContent = text;
        return result;
    }

    function displayDate(value) {
        if (!value) return '—';
        try {
            if (window.WBWSCalendar && typeof window.WBWSCalendar.formatDate === 'function') {
                return window.WBWSCalendar.formatDate(value, 'medium');
            }
            return new Date(String(value).replace(' ', 'T')).toLocaleDateString('en-GB');
        } catch (_error) {
            return String(value);
        }
    }

    function render(members) {
        var host = element('archivedMembersList');
        if (!host) return;
        host.replaceChildren();
        if (!members.length) {
            host.appendChild(node('div', 'text-center py-12 text-slate-400', 'No archived members found.'));
            return;
        }

        members.forEach(function (member) {
            var id = Number.parseInt(member.id, 10);
            if (!Number.isSafeInteger(id) || id <= 0) return;
            var fullName = [member.student_name, member.father_name].filter(Boolean).join(' ');
            var card = node('div', 'bg-white border border-slate-200 rounded-xl p-4 hover:shadow-md transition');
            var row = node('div', 'flex items-start gap-4');
            var initial = node('div', 'w-14 h-14 rounded-xl bg-slate-100 flex items-center justify-center flex-shrink-0 border-2 border-amber-200 text-slate-500 font-bold', (member.student_name || '?').charAt(0));
            row.appendChild(initial);

            var content = node('div', 'flex-1 min-w-0');
            var heading = node('div', 'flex items-start justify-between gap-2');
            var identity = node('div');
            identity.appendChild(node('h4', 'font-bold text-slate-800 text-sm truncate', fullName || 'Unnamed member'));
            identity.appendChild(node(
                'p',
                'text-xs text-slate-500 mt-0.5',
                (member.member_code || 'No ID') + ' • ' + (member.current_section || member.age_group || '—')
            ));
            heading.appendChild(identity);
            heading.appendChild(node('span', 'px-2 py-1 bg-amber-100 text-amber-700 rounded-lg text-[10px] font-bold flex-shrink-0', 'ARCHIVED'));
            content.appendChild(heading);

            var metadata = node('div', 'mt-3 flex flex-wrap items-center gap-2 text-xs');
            metadata.appendChild(node('span', 'px-2 py-1 bg-slate-100 text-slate-600 rounded-lg', reasonLabels[member.archive_reason] || member.archive_reason || 'Unknown reason'));
            metadata.appendChild(node('span', 'text-slate-400', displayDate(member.archived_at)));
            content.appendChild(metadata);

            var actions = node('div', 'mt-3 flex gap-2');
            var restore = node('button', 'flex-1 px-3 py-2 bg-emerald-50 text-emerald-600 hover:bg-emerald-100 rounded-lg text-xs font-bold transition', 'Restore to Active');
            restore.type = 'button';
            restore.addEventListener('click', function () {
                if (typeof window.restoreMember === 'function') window.restoreMember(id, fullName);
            });
            actions.appendChild(restore);
            var view = node('button', 'px-3 py-2 bg-slate-100 text-slate-600 hover:bg-slate-200 rounded-lg text-xs font-bold transition', 'View');
            view.type = 'button';
            view.addEventListener('click', function () {
                if (typeof window.openManageSheet === 'function') window.openManageSheet(id);
            });
            actions.appendChild(view);
            content.appendChild(actions);
            row.appendChild(content);
            card.appendChild(row);
            host.appendChild(card);
        });
    }

    function renderPagination(data) {
        var host = element('archivedMembersPagination');
        if (!host) return;
        host.replaceChildren();
        host.appendChild(node('span', 'text-xs text-slate-500', state.total === null ? 'Archived members' : state.total + ' archived member(s)'));
        var controls = node('span');
        var previous = node('button', 'px-3 py-1.5 border rounded-lg text-xs disabled:opacity-50', 'Previous');
        previous.type = 'button';
        previous.disabled = state.history.length === 0;
        previous.addEventListener('click', function () {
            state.cursor = state.history.pop() ?? null;
            load(false);
        });
        controls.appendChild(previous);
        var next = node('button', 'ml-2 px-3 py-1.5 border rounded-lg text-xs disabled:opacity-50', 'Next');
        next.type = 'button';
        next.disabled = !data.next_cursor;
        next.addEventListener('click', function () {
            state.history.push(state.cursor);
            state.cursor = data.next_cursor;
            load(false);
        });
        controls.appendChild(next);
        host.appendChild(controls);
    }

    function showError(message) {
        var host = element('archivedMembersList');
        if (!host) return;
        host.replaceChildren(node('div', 'text-center py-8 text-red-500', message));
    }

    async function load(reset) {
        if (!element('archivedMembersList')) return;
        if (reset !== false) {
            state.cursor = null;
            state.history = [];
            state.total = null;
        }
        if (state.request) state.request.abort();
        var controller = new AbortController();
        state.request = controller;
        var params = new URLSearchParams({ view: 'archive', limit: '50', page: '1' });
        var search = element('archiveSearch');
        if (search && search.value.trim()) params.set('q', search.value.trim());
        if (state.archiveType) params.set('archive_type', state.archiveType);
        if (state.cursor) {
            params.set('cursor', String(state.cursor));
            params.set('include_total', '0');
        }
        try {
            var response = await fetch(endpoint + '?' + params, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal
            });
            var data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Unable to load archived members.');
            if (data.total !== null && data.total !== undefined) state.total = data.total;
            var count = element('archivedCount');
            if (count) count.textContent = state.total === null ? 'Archived' : state.total + ' Members';
            render(Array.isArray(data.members) ? data.members : []);
            renderPagination(data);
        } catch (error) {
            if (error.name !== 'AbortError') showError(error.message || 'Unable to load archived members.');
        } finally {
            if (state.request === controller) state.request = null;
        }
    }

    function filter() {
        clearTimeout(state.timer);
        state.timer = setTimeout(function () { load(true); }, 300);
    }

    function switchTab(tabName) {
        if (!['permanent_archive', 'failed_observation'].includes(tabName)) return;
        state.archiveType = tabName;
        var permanent = element('tab_permanent_archive');
        var failed = element('tab_failed_observation');
        if (permanent && failed) {
            permanent.className = tabName === 'permanent_archive'
                ? 'px-4 py-2 text-sm font-semibold text-amber-700 border-b-2 border-amber-500'
                : 'px-4 py-2 text-sm font-semibold text-slate-500 border-b-2 border-transparent hover:text-amber-700';
            failed.className = tabName === 'failed_observation'
                ? 'px-4 py-2 text-sm font-semibold text-amber-700 border-b-2 border-amber-500'
                : 'px-4 py-2 text-sm font-semibold text-slate-500 border-b-2 border-transparent hover:text-amber-700';
        }
        load(true);
    }

    function initialize() {
        if (!element('archivedMembersList')) return;
        state.archiveType = element('tab_permanent_archive') ? 'permanent_archive' : '';
        window.loadArchivedMembers = function () { load(true); };
        window.filterArchivedMembers = filter;
        window.switchArchiveTab = switchTab;
        document.querySelectorAll('[data-section="archive"]').forEach(function (control) {
            control.addEventListener('click', function () { load(true); });
        });
        if (new URLSearchParams(window.location.search).get('section') === 'archive') load(true);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}());
