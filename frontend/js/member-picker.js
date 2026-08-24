/* Search-backed member select used by finance forms at large roster sizes. */
(function () {
    'use strict';

    var endpoint = '/admin/api_list_members.php';
    var timers = new WeakMap();
    var controllers = new WeakMap();

    function populate(select, members) {
        var selected = select.value;
        var optional = select.dataset.optional === 'true';
        select.replaceChildren();
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = optional ? '— None —' : 'Select member…';
        select.appendChild(placeholder);

        members.forEach(function (member) {
            var id = Number.parseInt(member.id, 10);
            if (!Number.isSafeInteger(id) || id <= 0) return;
            var option = document.createElement('option');
            option.value = String(id);
            var name = [member.student_name, member.father_name].filter(Boolean).join(' ');
            option.textContent = name + (member.member_code ? ' (' + member.member_code + ')' : '');
            select.appendChild(option);
        });
        if (Array.from(select.options).some(function (option) { return option.value === selected; })) {
            select.value = selected;
        }
    }

    async function search(input) {
        var targetId = input.dataset.memberPickerTarget;
        var select = document.getElementById(targetId);
        if (!select) return;
        var previous = controllers.get(input);
        if (previous) previous.abort();
        var controller = new AbortController();
        controllers.set(input, controller);

        var query = input.value.trim();
        input.setAttribute('aria-busy', 'true');
        try {
            var params = new URLSearchParams({ view: 'picker', limit: '50', page: '1' });
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
            populate(select, Array.isArray(data.members) ? data.members : []);
        } catch (error) {
            if (error.name !== 'AbortError') console.error('Member picker search failed', error);
        } finally {
            input.removeAttribute('aria-busy');
            controllers.delete(input);
        }
    }

    function initialize() {
        document.querySelectorAll('[data-member-picker-target]').forEach(function (input) {
            input.addEventListener('input', function () {
                clearTimeout(timers.get(input));
                timers.set(input, setTimeout(function () { search(input); }, 300));
            });
        });
    }

    window.MemberPicker = { search: search };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}());
