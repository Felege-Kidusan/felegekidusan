document.addEventListener('DOMContentLoaded', () => {
    const statusContainer = document.getElementById('dataSyncStatusContainer');
    const statusLog = document.getElementById('dataSyncStatusLog');
    
    function attachFormLogic(formId, inputId, displayId, btnId, tier) {
        const importForm = document.getElementById(formId);
        const fileInput = document.getElementById(inputId);
        const displaySpan = document.getElementById(displayId);
        const submitBtn = document.getElementById(btnId);

        if (!importForm) return;

        fileInput.addEventListener('change', () => {
            displaySpan.textContent = fileInput.files[0]?.name || 'Select Excel File';
        });

        importForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Please select an Excel (.xlsx) file first.');
                return;
            }
            
            const file = fileInput.files[0];
            if (!file.name.toLowerCase().endsWith('.xlsx') && !file.name.toLowerCase().endsWith('.xls')) {
                alert('Only Excel files (.xlsx) are allowed.');
                return;
            }
            
            // UI Loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Processing...';
            statusContainer.classList.remove('hidden');
            statusLog.innerHTML = `<div class="text-blue-400">Uploading ${file.name} for ${tier} members...</div>`;
            
            const formData = new FormData();
            formData.append('import_file', file);
            formData.append('tier', tier);
            if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) {
                formData.append('csrf_token', CSRF_TOKEN);
            }
            
            try {
                const response = await fetch('/admin/api_import_members.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    statusLog.innerHTML += `<div class="text-green-400 font-bold mt-2"><i class="fa-solid fa-check"></i> ${result.message}</div>`;
                    statusLog.innerHTML += `<div class="text-slate-400 mt-1">Strict protection rule was applied. No existing data was overwritten.</div>`;
                    
                    if (typeof fetchMembers === 'function') {
                        setTimeout(() => fetchMembers(), 1500);
                    }
                } else {
                    statusLog.innerHTML += `<div class="text-red-400 font-bold mt-2"><i class="fa-solid fa-xmark"></i> Error: ${result.message}</div>`;
                }
            } catch (err) {
                console.error(err);
                statusLog.innerHTML += `<div class="text-red-400 font-bold mt-2"><i class="fa-solid fa-xmark"></i> Network or server error occurred. Check console.</div>`;
            } finally {
                // Reset button
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Start Import';
                fileInput.value = ''; // Reset file input
                displaySpan.textContent = 'Select Excel File';
            }
        });
    }

    attachFormLogic('formSyncTemporary', 'import_file_temp', 'fileNameDisplayTemp', 'btnSyncTemp', 'temporary');
    attachFormLogic('formSyncPermanent', 'import_file_perm', 'fileNameDisplayPerm', 'btnSyncPerm', 'permanent');
});
