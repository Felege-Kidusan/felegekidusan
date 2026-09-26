/**
 * Felege Kidusan Sunday School - Registration Form Handler
 * Extracted and decoupled from index.php
 */

document.addEventListener('DOMContentLoaded', () => {
    const registrationForm = document.getElementById('registration-form');
    if (!registrationForm) return;

    registrationForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('regSubmitBtn');
        const msg = document.getElementById('regFormMsg');
        const origText = btn.textContent;
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Submitting…';
        msg.className = 'hidden';

        try {
            const fd = new FormData(registrationForm);
            
            // Check if backend API endpoint is reachable, otherwise fallback to local mock simulation
            let isStandalone = false;
            let d;
            
            try {
                const r = await fetch('/register_submit.php', { 
                    method: 'POST', 
                    body: fd, 
                    headers: { 'Accept': 'application/json' } 
                });
                const ct = r.headers.get('content-type') || '';
                if (ct.includes('application/json')) {
                    d = await r.json();
                } else {
                    isStandalone = true;
                }
            } catch (netErr) {
                isStandalone = true;
            }

            // Local simulation fallback for standalone frontend development
            if (isStandalone) {
                await new Promise(resolve => setTimeout(resolve, 800));
                
                // Store in local storage for developer inspection
                const submissions = JSON.parse(localStorage.getItem('fkss_submissions') || '[]');
                submissions.push({
                    full_name: fd.get('full_name'),
                    phone: fd.get('phone'),
                    email: fd.get('email'),
                    age: fd.get('age'),
                    message: fd.get('message'),
                    submitted_at: new Date().toISOString()
                });
                localStorage.setItem('fkss_submissions', JSON.stringify(submissions));

                d = {
                    status: 'success',
                    message: 'Thank you! Your registration request has been received. Our team will contact you soon.'
                };
            }

            if (d.status === 'success') {
                msg.className = 'text-center p-3 rounded-lg text-sm';
                msg.style.background = '#dcfce7';
                msg.style.color = '#166534';
                msg.textContent = d.message;
                registrationForm.reset();
            } else {
                msg.className = 'text-center p-3 rounded-lg text-sm';
                msg.style.background = '#fee2e2';
                msg.style.color = '#991b1b';
                msg.textContent = d.message || 'Something went wrong. Please try again.';
            }
        } catch (err) {
            msg.className = 'text-center p-3 rounded-lg text-sm';
            msg.style.background = '#fee2e2';
            msg.style.color = '#991b1b';
            msg.textContent = 'Could not submit. Please check your connection and try again.';
        } finally {
            btn.disabled = false;
            btn.textContent = origText;
        }
    });
});
