/* Search-backed member select used by finance forms at large roster sizes.
 *
 * Phase 3 paging (owner request 2026-10-22): every member is reachable.
 * - The first 50 load with the form; the list says how many there are.
 * - The last option "Show 50 more… (N left)" appends the next page, so a
 *   member can be picked without typing (was: silently cut at 50 of 440).
 * - Typing in the search box searches all members; results page the same way.
 *
 * Markup (unchanged):
 *   <input type="search" data-member-picker-target="feeMember">
 *   <select id="feeMember" [data-optional="true"]></select>
 * API: MemberPicker.search(input)  — (re)load page 1 for the input's query
 *      MemberPicker.load(input)    — same, used for the first load of a form
 */
(function () {
    'use strict';

    var endpoint = '/admin/api_list_members.php';
    var PAGE = 50;
    var MORE = '__more__';
    var timers = new WeakMap();
    var controllers = new WeakMap();
    var state = new WeakMap();   // select -> { input, query, page, total, shown }

    function addOption(select, member) {
        var id = Number.parseInt(member.id, 10);
        if (!Number.isSafeInteger(id) || id <= 0) return false;
        var option = document.createElement('option');
        option.value = String(id);
        var name = [member.student_name, member.father_name].filter(Boolean).join(' ');
        option.textContent = name + (member.member_code ? ' (' + member.member_code + ')' : '');
        select.appendChild(option);
        return true;
    }

    function moreOption(select, st) {
        var old = select.querySelector('option[value="' + MORE + '"]');
        if (old) old.remove();
        var left = Math.max(0, st.total - st.shown);
        if (left <= 0) return;
        var o = document.createElement('option');
        o.value = MORE;
        o.setAttribute('data-member-picker-more', '');
        o.textContent = 'Show ' + Math.min(PAGE, left) + ' more… (' + left + ' left)';
        select.appendChild(o);
    }

    function populate(select, members, append, st) {
        var selected = select.value === MORE ? (st.lastValue || '') : select.value;
        if (!append) {
            select.replaceChildren();
            var placeholder = document.createElement('option');
            placeholder.value = '';
            var optional = select.dataset.optional === 'true';
            placeholder.textContent = optional ? '— None —' : 'Select member…';
            select.appendChild(placeholder);
            st.shown = 0;
        } else {
            var old = select.querySelector('option[value="' + MORE + '"]');
            if (old) old.remove();
        }
        members.forEach(function (member) { if (addOption(select, member)) st.shown++; });
        moreOption(select, st);
        if (Array.from(select.options).some(function (option) { return option.value === selected; })) {
            select.value = selected;
        } else {
            select.value = '';
        }
        st.lastValue = select.value;
        select.title = st.total > st.shown
            ? st.shown + ' of ' + st.total + ' members shown. Type in the search box or choose “Show more”.'
            : st.total + ' members';
    }

    function stateFor(select, input) {
        var st = state.get(select);
        if (!st) {
            st = { input: input, query: '', page: 0, total: 0, shown: 0, lastValue: '' };
            state.set(select, st);
            // "Show more…" is an option: picking it loads the next page and
            // puts the previous choice back (it is never a real value).
            select.addEventListener('change', function () {
                if (select.value !== MORE) { st.lastValue = select.value; return; }
                select.value = st.lastValue || '';
                fetchPage(st.input, select, st.page + 1, true);
            });
        }
        st.input = input;
        return st;
    }

    async function fetchPage(input, select, page, append) {
        var st = stateFor(select, input);
        var previous = controllers.get(input);
        if (previous) previous.abort();
        var controller = new AbortController();
        controllers.set(input, controller);
        var query = append ? st.query : input.value.trim();
        input.setAttribute('aria-busy', 'true');
        try {
            var params = new URLSearchParams({ view: 'picker', limit: String(PAGE), page: String(page) });
            if (query !== '') params.set('q', query);
            if (input.dataset.memberPickerStatus) {
                params.set('status', input.dataset.memberPickerStatus);
            }
            var response = await fetch(endpoint + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal
            });
            var data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Search failed');
            var rows = Array.isArray(data.members) ? data.members : [];
            st.query = query;
            st.page = Number(data.page) || page;
            if (data.total != null) st.total = Number(data.total) || 0;
            else if (!append) st.total = rows.length;
            populate(select, rows, append, st);
        } catch (error) {
            if (error.name !== 'AbortError') console.error('Member picker search failed', error);
        } finally {
            input.removeAttribute('aria-busy');
            controllers.delete(input);
        }
    }

    async function search(input) {
        if (!input) return;
        var select = document.getElementById(input.dataset.memberPickerTarget);
        if (!select) return;
        return fetchPage(input, select, 1, false);
    }

    function initialize() {
        document.querySelectorAll('[data-member-picker-target]').forEach(function (input) {
            input.addEventListener('input', function () {
                clearTimeout(timers.get(input));
                timers.set(input, setTimeout(function () { search(input); }, 300));
            });
        });
    }

    window.MemberPicker = { search: search, load: search };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}());
