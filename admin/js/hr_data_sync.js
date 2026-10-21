/**
 * HR Excel Sync — upload UI for admin/api_import_members.php.
 *
 * "Check file" sends dry_run=1: the server validates every row and saves
 * nothing. "Start Import" saves the whole file only when every row is
 * valid (all-or-nothing). All server text is rendered with textContent —
 * messages can quote spreadsheet cells, so they are never treated as HTML.
 */
document.addEventListener('DOMContentLoaded', () => {
    const statusContainer = document.getElementById('dataSyncStatusContainer');
    const statusLog = document.getElementById('dataSyncStatusLog');
    if (!statusContainer || !statusLog) return;

    const SUMMARY_LABELS = [
        ['rows', 'Rows read'],
        ['new_permanent', 'New permanent members'],
        ['new_temporary', 'New temporary members'],
        ['updated', 'Members to update'],
        ['unchanged', 'Unchanged'],
        ['to_enroll', 'Class assignments'],
        ['unassigned_new', 'New members without class'],
        ['codes_to_issue', 'Member codes to issue'],
        ['codes_pending', 'Member codes pending (no age)'],
        ['skipped_archived', 'Archived members skipped'],
    ];

    function line(text, cls) {
        const div = document.createElement('div');
        if (cls) div.className = cls;
        div.textContent = text;
        statusLog.appendChild(div);
        return div;
    }

    function list(title, items, cls, total) {
        if (!Array.isArray(items) || items.length === 0) return;
        const shown = total && total > items.length ? ` (first ${items.length} of ${total})` : '';
        line(`${title}${shown}:`, `${cls} font-bold mt-2`);
        const ol = document.createElement('ol');
        ol.className = `${cls} list-decimal ml-5`;
        items.forEach((item) => {
            const li = document.createElement('li');
            li.textContent = String(item);
            ol.appendChild(li);
        });
        statusLog.appendChild(ol);
    }

    function renderSummary(summary) {
        if (!summary || typeof summary !== 'object') return;
        SUMMARY_LABELS.forEach(([key, label]) => {
            const value = Number(summary[key] || 0);
            if (value > 0 || key === 'rows') line(`${label}: ${value}`, 'text-slate-300');
        });
        const sections = summary.sections && typeof summary.sections === 'object' ? Object.entries(summary.sections) : [];
        if (sections.length) {
            line('Sections (from age): ' + sections.map(([k, v]) => `${k} ${v}`).join(' · '), 'text-slate-300');
        }
        const classes = summary.classes && typeof summary.classes === 'object' ? Object.entries(summary.classes) : [];
        if (classes.length) {
            line('Classes: ' + classes.map(([k, v]) => `${k} ${v}`).join(' · '), 'text-slate-300');
        }
    }

    function attachFormLogic(formId, inputId, displayId, btnId, checkBtnId, tier) {
        const importForm = document.getElementById(formId);
        const fileInput = document.getElementById(inputId);
        const displaySpan = document.getElementById(displayId);
        const submitBtn = document.getElementById(btnId);
        const checkBtn = document.getElementById(checkBtnId);
        if (!importForm || !fileInput || !submitBtn) return;

        const submitHtml = submitBtn.innerHTML;
        const checkHtml = checkBtn ? checkBtn.innerHTML : '';

        fileInput.addEventListener('change', () => {
            displaySpan.textContent = fileInput.files[0]?.name || 'Select Excel File';
        });

        function pickedFile() {
            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Please select an Excel (.xlsx) file first.');
                return null;
            }
            const file = fileInput.files[0];
            if (!file.name.toLowerCase().endsWith('.xlsx')) {
                alert('Only Excel files (.xlsx) are allowed.');
                return null;
            }
            return file;
        }

        function setBusy(busy, activeBtn) {
            submitBtn.disabled = busy;
            if (checkBtn) checkBtn.disabled = busy;
            if (busy && activeBtn) {
                activeBtn.textContent = '';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-circle-notch fa-spin';
                activeBtn.appendChild(icon);
                activeBtn.appendChild(document.createTextNode(activeBtn === checkBtn ? ' Checking...' : ' Importing...'));
            }
            if (!busy) {
                submitBtn.innerHTML = submitHtml;
                if (checkBtn) checkBtn.innerHTML = checkHtml;
            }
        }

        // Danger confirmation (HIGH): the dialog first runs "Check file" and
        // shows the real counts; the typed phrase goes to the server.
        function previewImpact(file) {
            const fd = new FormData();
            fd.append('import_file', file);
            fd.append('tier', tier);
            fd.append('dry_run', '1');
            if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);
            return fetch('/admin/api_import_members.php', { method: 'POST', body: fd }).then((r) => r.json()).then((res) => {
                const sm = res.summary || {};
                if (!res.success) {
                    const n = Number(res.error_count || (res.errors || []).length || 0);
                    return [`The file has ${n || 'some'} problem(s), so the import will be REFUSED and nothing is saved. Cancel and press "Check file" to see them.`];
                }
                const out = [];
                const newP = Number(sm.new_permanent || 0), newT = Number(sm.new_temporary || 0);
                if (newP + newT) out.push(`${newP + newT} NEW member(s) are created (${newP} permanent, ${newT} temporary).`);
                if (Number(sm.updated || 0)) out.push(`${sm.updated} existing member(s) are UPDATED with the values in the file.`);
                if (Number(sm.to_enroll || 0)) out.push(`${sm.to_enroll} class assignment(s) are made.`);
                if (Number(sm.codes_to_issue || 0)) out.push(`${sm.codes_to_issue} member code(s) are issued.`);
                if (!out.length) out.push('Nothing changes: every row already matches the database.');
                return out;
            });
        }

        async function send(dryRun, phrase) {
            const file = pickedFile();
            if (!file) return;
            if (!dryRun && !phrase) {
                if (!(window.DangerConfirm && window.DangerConfirm.ask)) {
                    line('✗ The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.', 'text-red-400 font-bold');
                    statusContainer.classList.remove('hidden');
                    return;
                }
                const dc = await window.DangerConfirm.ask({
                    tier: 'high',
                    title: `Import ${tier} members from this file?`,
                    object: file.name,
                    summary: 'Members are created or updated from the Excel file in one all-or-nothing step.',
                    intent: 'I want to import this file',
                    consequences: () => previewImpact(file),
                    fallbackConsequences: ['Could not check the file first. New members may be created and existing members updated with the values in the file.'],
                    keeps: ['The whole file is saved only if every row is valid.', 'Members that are not in the file are not touched.', 'Family links are made later in Family Link.'],
                    phrase: 'IMPORT MEMBERS',
                    confirmLabel: 'Import members'
                });
                if (!dc) return;
                phrase = dc.phrase;
            }
            setBusy(true, dryRun ? checkBtn : submitBtn);
            statusContainer.classList.remove('hidden');
            statusLog.textContent = '';
            line(`${dryRun ? 'Checking' : 'Importing'} ${file.name} (${tier} members)...`, 'text-blue-400');

            const formData = new FormData();
            formData.append('import_file', file);
            formData.append('tier', tier);
            formData.append('dry_run', dryRun ? '1' : '0');
            if (!dryRun && phrase) formData.append('confirm_phrase', phrase);
            if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) {
                formData.append('csrf_token', CSRF_TOKEN);
            }

            let keepFile = false;
            try {
                const response = await fetch('/admin/api_import_members.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    line('✓ ' + String(result.message || ''), 'text-green-400 font-bold mt-2');
                    renderSummary(result.summary);
                    list('Warnings', result.warnings, 'text-amber-300');
                    if (dryRun) {
                        keepFile = true; // ready for Start Import without re-selecting
                        line('Nothing was saved. Press Start Import to save this file.', 'text-blue-300 mt-2');
                    } else if (typeof fetchMembers === 'function') {
                        setTimeout(() => fetchMembers(), 1500);
                    }
                } else {
                    line('✗ ' + String(result.message || 'The file was not imported.'), 'text-red-400 font-bold mt-2');
                    list('Problems to fix', result.errors, 'text-red-300', Number(result.error_count || 0));
                    list('Warnings', result.warnings, 'text-amber-300');
                }
            } catch (err) {
                console.error(err);
                line('✗ Network or server error occurred. Nothing was confirmed as saved — check the member list before retrying.', 'text-red-400 font-bold mt-2');
            } finally {
                setBusy(false);
                if (!keepFile) {
                    fileInput.value = '';
                    displaySpan.textContent = 'Select Excel File';
                }
            }
        }

        importForm.addEventListener('submit', (e) => {
            e.preventDefault();
            send(false);
        });
        if (checkBtn) {
            checkBtn.addEventListener('click', () => send(true));
        }
    }

    attachFormLogic('formSyncTemporary', 'import_file_temp', 'fileNameDisplayTemp', 'btnSyncTemp', 'btnCheckTemp', 'temporary');
    attachFormLogic('formSyncPermanent', 'import_file_perm', 'fileNameDisplayPerm', 'btnSyncPerm', 'btnCheckPerm', 'permanent');
});
