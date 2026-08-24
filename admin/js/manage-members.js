/*
 * Paginated member-management directory.
 *
 * The server owns filtering and authorization; this module owns rendering and
 * interaction only. It is shared by the Information and HR dashboards.
 */
(function () {
    'use strict';

    var endpoint = '/admin/api_list_members.php';
    var pageSize = 50;
    var currentPage = 1;
    var totalPages = 1;
    var totalEntries = 0;
    var activeRequest = null;
    var debounceTimer = null;
    var cursors = { 1: null };

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

    function text(value) {
        return value == null ? '' : String(value);
    }

    function safeMemberId(value) {
        var id = Number.parseInt(value, 10);
        return Number.isSafeInteger(id) && id > 0 ? id : null;
    }

    function buildQuery(page) {
        var params = new URLSearchParams({
            view: 'manager',
            limit: String(pageSize),
            page: String(page)
        });
        Object.keys(filterMap).forEach(function (id) {
            var element = document.getElementById(id);
            var value = element ? element.value.trim() : '';
            if (value !== '') params.set(filterMap[id], value);
        });
        if (page > 1) params.set('include_total', '0');
        if (page > 1 && cursors[page]) params.set('cursor', String(cursors[page]));
        return params;
    }

    function appendText(parent, tag, value, className) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        node.textContent = text(value);
        parent.appendChild(node);
        return node;
    }

    function statusClasses(status) {
        var classes = {
            active: 'bg-emerald-100 text-emerald-700',
            warning: 'bg-amber-100 text-amber-700',
            inactive: 'bg-slate-100 text-slate-600',
            archived: 'bg-gray-100 text-gray-600'
        };
        return classes[status] || classes.archived;
    }

    function renderEmpty(message, isError) {
        var body = document.getElementById('manageMembersTableBody');
        if (!body) return;
        body.replaceChildren();
        var row = document.createElement('tr');
        var cell = document.createElement('td');
        cell.colSpan = 4;
        cell.className = 'p-4 text-center ' + (isError ? 'text-red-500' : 'text-slate-400');
        cell.textContent = message;
        row.appendChild(cell);
        body.appendChild(row);
    }

    function renderMembers(members) {
        var body = document.getElementById('manageMembersTableBody');
        if (!body) return;
        body.replaceChildren();
        if (!Array.isArray(members) || members.length === 0) {
            renderEmpty('No members found.', false);
            return;
        }

        members.forEach(function (member) {
            var id = safeMemberId(member.id);
            if (id === null) return;

            var row = document.createElement('tr');
            row.className = 'hover:bg-slate-50 transition';

            var memberCell = document.createElement('td');
            memberCell.className = 'px-4 py-3';
            appendText(
                memberCell,
                'div',
                [text(member.student_name), text(member.father_name)].filter(Boolean).join(' '),
                'font-bold text-slate-800'
            );
            appendText(memberCell, 'div', member.member_code || 'No ID', 'text-[10px] text-slate-500');
            row.appendChild(memberCell);

            var typeCell = document.createElement('td');
            typeCell.className = 'px-4 py-3 capitalize';
            appendText(
                typeCell,
                'span',
                member.registration_type,
                'px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold border border-slate-200'
            );
            row.appendChild(typeCell);

            var statusCell = document.createElement('td');
            statusCell.className = 'px-4 py-3';
            appendText(
                statusCell,
                'span',
                member.status,
                'px-2 py-0.5 rounded-full text-[10px] font-bold border border-white shadow-sm ' + statusClasses(member.status)
            );
            row.appendChild(statusCell);

            var actionsCell = document.createElement('td');
            actionsCell.className = 'px-4 py-3 text-right';
            var actions = document.createElement('div');
            actions.className = 'flex items-center justify-end gap-2';

            var manage = document.createElement('button');
            manage.type = 'button';
            manage.className = 'px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 font-bold text-[11px] transition';
            manage.innerHTML = '<i class="fa-solid fa-pen-to-square mr-1" aria-hidden="true"></i> Manage';
            manage.addEventListener('click', function () {
                if (typeof window.openManageSheet === 'function') window.openManageSheet(id);
            });
            actions.appendChild(manage);

            var archive = document.createElement('button');
            archive.type = 'button';
            archive.className = 'px-3 py-1.5 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-100 font-bold text-[11px] transition';
            archive.title = 'Move to Archive';
            archive.setAttribute('aria-label', 'Archive member');
            archive.innerHTML = '<i class="fa-solid fa-box-archive" aria-hidden="true"></i>';
            archive.addEventListener('click', function () {
                if (typeof window.archiveMember === 'function') {
                    var fullName = [text(member.student_name), text(member.father_name)].filter(Boolean).join(' ');
                    window.archiveMember(String(id), fullName);
                }
            });
            actions.appendChild(archive);
            actionsCell.appendChild(actions);
            row.appendChild(actionsCell);
            body.appendChild(row);
        });
    }

    function renderPagination(total, page, pages, limit, nextCursor) {
        var container = document.getElementById('manageMembersPagination');
        if (!container) return;
        container.replaceChildren();
        currentPage = page;
        totalPages = pages;

        var summary = document.createElement('span');
        summary.className = 'text-xs text-slate-500';
        var first = total === 0 ? 0 : ((page - 1) * limit) + 1;
        var last = Math.min(total, page * limit);
        summary.textContent = 'Showing ' + first + '–' + last + ' of ' + total;
        container.appendChild(summary);

        var controls = document.createElement('div');
        controls.className = 'flex items-center gap-2';
        var previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'px-3 py-1.5 rounded-lg border text-xs font-semibold disabled:opacity-40';
        previous.textContent = 'Previous';
        previous.disabled = page <= 1;
        previous.addEventListener('click', function () { loadManageMembers(page - 1); });
        controls.appendChild(previous);

        appendText(controls, 'span', 'Page ' + page + ' of ' + pages, 'text-xs text-slate-600');

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'px-3 py-1.5 rounded-lg border text-xs font-semibold disabled:opacity-40';
        next.textContent = 'Next';
        next.disabled = page >= pages || !nextCursor;
        next.addEventListener('click', function () {
            cursors[page + 1] = nextCursor;
            loadManageMembers(page + 1);
        });
        controls.appendChild(next);
        container.appendChild(controls);
    }

    async function loadManageMembers(page) {
        page = Number.isSafeInteger(page) && page > 0 ? page : 1;
        var body = document.getElementById('manageMembersTableBody');
        if (!body) return;
        if (activeRequest) activeRequest.abort();
        activeRequest = new AbortController();
        renderEmpty('Loading members…', false);

        try {
            var response = await fetch(endpoint + '?' + buildQuery(page).toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: activeRequest.signal
            });
            var data = await response.json();
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Could not load members.');
            }
            renderMembers(data.members);
            if (data.total !== null && data.total !== undefined) totalEntries = Number(data.total) || 0;
            if (data.pages !== null && data.pages !== undefined) totalPages = Number(data.pages) || 1;
            renderPagination(
                totalEntries,
                Number(data.page) || 1,
                totalPages,
                Number(data.limit) || pageSize,
                Number(data.next_cursor) || null
            );
        } catch (error) {
            if (error.name === 'AbortError') return;
            renderEmpty('Could not load members. Please try again.', true);
            renderPagination(0, 1, 1, pageSize);
            console.error('Member directory request failed', error);
        } finally {
            activeRequest = null;
        }
    }

    function applyManageFilters() {
        clearTimeout(debounceTimer);
        cursors = { 1: null };
        debounceTimer = setTimeout(function () { loadManageMembers(1); }, 300);
    }

    function resetManageFilters() {
        Object.keys(filterMap).forEach(function (id) {
            var element = document.getElementById(id);
            if (element) element.value = '';
        });
        clearTimeout(debounceTimer);
        cursors = { 1: null };
        loadManageMembers(1);
    }

    function initialize() {
        Object.keys(filterMap).forEach(function (id) {
            var element = document.getElementById(id);
            if (!element) return;
            element.addEventListener(id === 'manageSearchInput' ? 'input' : 'change', applyManageFilters);
        });
        document.querySelectorAll('[data-section="manage"]').forEach(function (button) {
            button.addEventListener('click', function () { loadManageMembers(1); });
        });
        var section = document.getElementById('section-manage');
        if (section && (section.classList.contains('active') || section.classList.contains('content-section-active'))) {
            loadManageMembers(1);
        }
    }

    window.loadManageMembers = loadManageMembers;
    window.applyManageFilters = applyManageFilters;
    window.resetManageFilters = resetManageFilters;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}());
