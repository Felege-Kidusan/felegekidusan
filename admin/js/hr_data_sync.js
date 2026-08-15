document.addEventListener('DOMContentLoaded', () => {
    const importForm = document.getElementById('dataSyncImportForm');
    const submitBtn = document.getElementById('btnSyncImportSubmit');
    const fileInput = document.getElementById('import_file');
    const statusContainer = document.getElementById('dataSyncStatusContainer');
    const statusLog = document.getElementById('dataSyncStatusLog');
    
    if (importForm) {
        importForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Please select a CSV file first.');
                return;
            }
            
            const file = fileInput.files[0];
            if (!file.name.toLowerCase().endsWith('.csv')) {
                alert('Only CSV files are allowed. Please export to CSV and try again.');
                return;
            }
            
            // UI Loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Processing...';
            statusContainer.classList.remove('hidden');
            statusLog.innerHTML = `<div class="text-blue-400">Uploading ${file.name}...</div>`;
            
            const formData = new FormData();
            formData.append('import_file', file);
            
            try {
                const response = await fetch('/admin/api_import_members.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    statusLog.innerHTML += `<div class="text-green-400 font-bold mt-2"><i class="fa-solid fa-check"></i> ${result.message}</div>`;
                    statusLog.innerHTML += `<div class="text-slate-400 mt-1">Strict protection rule was applied. No existing data was overwritten.</div>`;
                    
                    // Refresh the members list if the function exists
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
                document.getElementById('fileNameDisplay').textContent = 'Select CSV File';
            }
        });
    }
});
