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

    // ── Danger confirmation (DC-4, T1/M7) ─────────────────────────────
    // snapshot(students): {memberId: savedStatus} for rows that were already
    // saved on the server (status != null) — take it right after loading.
    function snapshot(students) {
        const saved = {};
        (students || []).forEach((s) => {
            const id = Number.parseInt(s.member_id || s.id || '', 10);
            const st = normalizeStatus(s.status);
            if (id > 0 && st) saved[id] = st;
        });
        return saved;
    }

    // confirmReplace(records, saved, nameOf) → Promise<boolean>
    // First saves and re-saves that keep every saved status go straight
    // through. Changing already-saved records asks a MEDIUM confirmation
    // that lists who changes (uses admin/js/danger_confirm.js).
    function confirmReplace(records, saved, nameOf) {
        const changes = (records || []).filter((r) => saved && saved[r.member_id] && r.status && saved[r.member_id] !== r.status);
        if (!changes.length) return Promise.resolve(true);
        const dc = global.DangerConfirm;
        if (!(dc && dc.ask)) {
            if (typeof global.showToast === 'function') global.showToast('The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.', 'error');
            return Promise.resolve(false);
        }
        const label = (st) => ({ present: 'present', absent: 'absent', late: 'late', excused: 'excused' }[st] || st);
        const lines = changes.slice(0, 6).map((r) => {
            const nm = (typeof nameOf === 'function' && nameOf(r.member_id)) || ('Member #' + r.member_id);
            return nm + ': ' + label(saved[r.member_id]) + ' \u2192 ' + label(r.status);
        });
        if (changes.length > 6) lines.push('\u2026and ' + (changes.length - 6) + ' more.');
        return dc.ask({
            tier: 'medium', title: 'Replace saved attendance?', object: changes.length + ' saved record(s) will change',
            summary: 'This sheet was already saved. Saving again overwrites what was recorded.', intent: 'I want to change saved attendance',
            consequences: lines.concat(['Attendance totals, warnings and reports use the new values.']),
            keeps: ['Members whose status is not changed keep their saved record.', 'The change is recorded in the audit log.'],
            phrase: 'REPLACE ATTENDANCE', confirmLabel: 'Save changes'
        }).then((r) => !!r);
    }

    global.AttendanceSheet = Object.freeze({
        VALID_STATUSES,
        collect,
        snapshot,
        confirmReplace,
        counts,
        isValidStatus,
        markAll,
        normalizeStatus,
        setStatus,
    });
})(window);
