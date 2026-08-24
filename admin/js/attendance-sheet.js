(function (global) {
    'use strict';

    const VALID_STATUSES = Object.freeze(['present', 'absent', 'late', 'excused']);

    function isValidStatus(status) {
        return VALID_STATUSES.includes(String(status || '').trim().toLowerCase());
    }

    function normalizeStatus(status) {
        const normalized = String(status || '').trim().toLowerCase();
        return isValidStatus(normalized) ? normalized : '';
    }

    function selectedStatus(row) {
        if (!row) return '';
        const selected = row.querySelector('.att-btn.active[data-attendance-status]');
        return normalizeStatus(selected ? selected.dataset.attendanceStatus : '');
    }

    function setStatus(button, status) {
        const normalized = normalizeStatus(status);
        const row = button && button.closest ? button.closest('tr') : null;
        if (!row || !normalized) return false;
        row.querySelectorAll('.att-btn').forEach((candidate) => {
            candidate.classList.remove('active');
        });
        button.classList.add('active');
        return true;
    }

    function markAll(root, status) {
        const normalized = normalizeStatus(status);
        if (!root || !normalized) return false;
        root.querySelectorAll('tr[data-member-id]').forEach((row) => {
            const button = row.querySelector(
                `.att-btn[data-attendance-status="${normalized}"]`
            );
            if (button) setStatus(button, normalized);
        });
        return true;
    }

    function collect(root) {
        const records = [];
        const unmarked = [];
        if (!root) return { records, unmarked };

        root.querySelectorAll('tr[data-member-id]').forEach((row) => {
            const memberId = Number.parseInt(row.dataset.memberId || '', 10);
            if (!Number.isSafeInteger(memberId) || memberId <= 0) return;

            const status = selectedStatus(row);
            if (!status) unmarked.push(memberId);
            const noteInput = row.querySelector('.att-note');
            records.push({
                member_id: memberId,
                status,
                note: noteInput ? String(noteInput.value || '').trim() : '',
            });
        });

        return { records, unmarked };
    }

    function counts(root) {
        const result = { present: 0, absent: 0, late: 0, excused: 0, unmarked: 0 };
        if (!root) return result;
        root.querySelectorAll('tr[data-member-id]').forEach((row) => {
            const status = selectedStatus(row);
            if (status) result[status] += 1;
            else result.unmarked += 1;
        });
        return result;
    }

    global.AttendanceSheet = Object.freeze({
        VALID_STATUSES,
        collect,
        counts,
        isValidStatus,
        markAll,
        normalizeStatus,
        setStatus,
    });
})(window);
