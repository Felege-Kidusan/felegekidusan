/*
 * Bounded archived-member directory — shared PaginatedList engine.
 * Used by Information and HR dashboards.
 */
(function () {
    'use strict';

    var list = null;
    var archiveType = '';

    var reasonLabels = {
        left_school: 'ከት/ቤት ወጥቷል',
        graduated: 'ተመርቋል',
        transferred: 'ተዛውሯል',
        failed_observation: 'የሙከራ ጊዜ አላጠናቀቀም',
        inactive_long: 'ረጅም ጊዜ ቦዝ',
        deceased: 'አርፏል',
        other: 'ሌላ'
    };

    function el(id) { return document.getElementById(id); }
    function node(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }

    function displayDate(v) {
        if (!v) return '—';
        try { return new Date(String(v).replace(' ', 'T')).toLocaleDateString('en-GB'); } catch (_) { return String(v); }
    }

    function buildRow(member) {
        var card = node('div', 'bg-white border border-slate-200 rounded-xl p-4 hover:shadow-sm transition');
        var row = node('div', 'flex items-start gap-4');
        var initial = member.student_name ? member.student_name.charAt(0) : '?';
        row.appendChild(node('div', 'w-14 h-14 rounded-xl bg-slate-100 flex items-center justify-center flex-shrink-0 border-2 border-amber-200 text-slate-500 font-bold', initial));

        var content = node('div', 'flex-1 min-w-0');
        var heading = node('div', 'flex items-start justify-between gap-2');
        var identity = node('div');
        identity.appendChild(node('h4', 'font-bold text-slate-800 text-sm truncate', [member.student_name, member.father_name].filter(Boolean).join(' ') || 'Unnamed'));
        identity.appendChild(node('p', 'text-xs text-slate-500 mt-0.5', (member.member_code || 'No ID') + ' • ' + (member.current_section || member.age_group || '—')));
        heading.appendChild(identity);
        heading.appendChild(node('span', 'px-2 py-1 bg-amber-100 text-amber-700 rounded-lg text-[10px] font-bold flex-shrink-0', 'ARCHIVED'));
        content.appendChild(heading);

        var meta = node('div', 'mt-3 flex flex-wrap items-center gap-2 text-xs');
        meta.appendChild(node('span', 'px-2 py-1 bg-slate-100 text-slate-600 rounded-lg', reasonLabels[member.archive_reason] || member.archive_reason || 'Unknown'));
        meta.appendChild(node('span', 'text-slate-400', displayDate(member.archived_at)));
        content.appendChild(meta);

        var actions = node('div', 'mt-3 flex gap-2');
        var restoreBtn = node('button', 'flex-1 px-3 py-2 bg-emerald-50 text-emerald-600 hover:bg-emerald-100 rounded-lg text-xs font-bold transition', 'Restore to Active');
        restoreBtn.type = 'button';
        restoreBtn.addEventListener('click', function () {
            if (typeof window.restoreMember === 'function' && member.id > 0)
                window.restoreMember(member.id, [member.student_name, member.father_name].filter(Boolean).join(' '));
        });
        actions.appendChild(restoreBtn);
        var viewBtn = node('button', 'px-3 py-2 bg-slate-100 text-slate-600 hover:bg-slate-200 rounded-lg text-xs font-bold transition', 'View');
        viewBtn.type = 'button';
        viewBtn.addEventListener('click', function () {
            if (typeof window.openManageSheet === 'function' && member.id > 0) window.openManageSheet(member.id);
        });
        actions.appendChild(viewBtn);
        content.appendChild(actions);
        row.appendChild(content);
        card.appendChild(row);
        return card;
    }

    function getParams() {
        var p = { view: 'archive' };
        if (archiveType) p.archive_type = archiveType;
        var search = el('archiveSearch');
        if (search && search.value.trim()) p.q = search.value.trim();
        return p;
    }

    function switchTab(tabName) {
        if (!['permanent_archive', 'failed_observation'].includes(tabName)) return;
        archiveType = tabName;
        var permanent = el('tab_permanent_archive'), failed = el('tab_failed_observation');
        if (permanent && failed) {
            permanent.className = tabName === 'permanent_archive'
                ? 'px-4 py-2 text-sm font-semibold text-amber-700 border-b-2 border-amber-500'
                : 'px-4 py-2 text-sm font-semibold text-slate-500 border-b-2 border-transparent hover:text-amber-700';
            failed.className = tabName === 'failed_observation'
                ? 'px-4 py-2 text-sm font-semibold text-amber-700 border-b-2 border-amber-500'
                : 'px-4 py-2 text-sm font-semibold text-slate-500 border-b-2 border-transparent hover:text-amber-700';
        }
        if (list) list.reset();
    }

    function init() {
        var bodyEl = el('archivedMembersList');
        if (!bodyEl) return;

        // PaginatedList works with any container that has appendChild.
        list = new PaginatedList({
            endpoint: '/admin/api_list_members.php',
            bodyElement: bodyEl,
            loadMoreHost: el('archivedMembersPagination'),
            renderRow: buildRow,
            getParams: getParams,
            pageSize: 50
        });

        archiveType = el('tab_permanent_archive') ? 'permanent_archive' : '';
        window.loadArchivedMembers = function () { if (list) list.reset(); };
        window.filterArchivedMembers = function () { if (list) list.reset(); };
        window.switchArchiveTab = switchTab;

        var search = el('archiveSearch');
        if (search) {
            var timer;
            search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { if (list) list.reset(); }, 300); });
        }
        document.querySelectorAll('[data-section="archive"]').forEach(function (c) {
            c.addEventListener('click', function () { if (list) list.reset(); });
        });
        if (new URLSearchParams(window.location.search).get('section') === 'archive') { if (list) list.reset(); }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}());
