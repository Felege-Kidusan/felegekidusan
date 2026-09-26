<?php
/**
 * Universal Profile Tab Component — Sunday School Management System (FKSS / WBWS)
 *
 * Renders an in-dashboard profile view & editor styled strictly with the official
 * SSMS Brand Design System: White background, Deep Maroon accents (#800000), and Warm Gold/Yellow (#f59e0b).
 *
 * Can be embedded directly inside any dashboard (<main>) as a first-class tab.
 */

if (!function_exists('renderProfileTabSection')) {
    /**
     * @param string $sectionId The HTML ID for the section container (e.g. 'sec-profile', 'section-profile')
     * @param string $sectionClass The CSS class for dashboard tabs (e.g. 'sec', 'section', 'cs')
     * @param bool $isActive Whether this section should be visible initially
     */
    function renderProfileTabSection(string $sectionId = 'sec-profile', string $sectionClass = 'sec', bool $isActive = false): void {
        $activeClass = $isActive ? ' act active' : '';
        $apiUrl = function_exists('ssms_app_url') ? ssms_app_url('admin/api_settings.php') : '/admin/api_settings.php';
        $imageUrl = function_exists('ssms_app_url') ? ssms_app_url('admin/profile_image.php') : '/admin/profile_image.php';
        ?>
        <div id="<?= htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars($sectionClass . $activeClass, ENT_QUOTES, 'UTF-8') ?>">
            <!-- Header -->
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
                <div>
                    <span style="font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#d97706;display:block;margin-bottom:.2rem">Self-Service Account</span>
                    <h2 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;display:flex;align-items:center;gap:.5rem">
                        <i class="fa-solid fa-user-gear" style="color:#d97706"></i> My Profile
                    </h2>
                    <p style="font-size:.78rem;color:#64748b;margin-top:.2rem">Manage your personal information, live profile photo, and security credentials.</p>
                </div>
                <div id="tabProfileHeaderBadge" style="display:flex;align-items:center;gap:.6rem;background:#fff;padding:.4rem .85rem;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.04)">
                    <span id="tabProfileHeaderDot" style="width:8px;height:8px;border-radius:50%;background:#10b981"></span>
                    <span id="tabProfileHeaderName" style="font-size:.82rem;font-weight:600;color:#334155">Loading…</span>
                </div>
            </div>

            <!-- Global Alert -->
            <div id="tabProfileGlobalAlert" style="display:none;padding:.75rem 1rem;border-radius:12px;font-size:.82rem;margin-bottom:1rem;line-height:1.4"></div>

            <!-- 2-Column Grid -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;align-items:start">
                
                <!-- Left Column: Identity & Avatar Card -->
                <div class="crd" style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:1.5rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.05)">
                    <div style="position:relative;width:7.5rem;height:7.5rem;margin:0 auto 1rem">
                        <div id="tabProfileAvatarWrap" style="width:100%;height:100%;border-radius:50%;overflow:hidden;border:3px solid #fde68a;background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 4px 14px rgba(245,158,11,.25);display:flex;align-items:center;justify-content:center">
                            <img id="tabProfileAvatarImg" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;display:none" onerror="this.style.display='none';document.getElementById('tabProfileAvatarInitials').style.display='flex';">
                            <span id="tabProfileAvatarInitials" style="font-size:2rem;font-weight:700;color:#fff;display:flex">…</span>
                        </div>
                        <span id="tabProfileStatusDot" style="position:absolute;bottom:4px;right:4px;width:1.1rem;height:1.1rem;border:3px solid #fff;border-radius:50%;background:#10b981"></span>
                    </div>

                    <h3 id="tabProfileDisplayName" style="font-size:1.15rem;font-weight:700;color:#1e293b;margin:0 0 .2rem">Loading…</h3>
                    <p id="tabProfileDisplayUsername" style="font-size:.82rem;color:#64748b;margin:0 0 .75rem">@…</p>

                    <div style="display:flex;justify-content:center;gap:.4rem;flex-wrap:wrap;margin-bottom:1.25rem">
                        <span id="tabProfileRoleBadge" style="display:inline-block;padding:.25rem .7rem;border-radius:99px;font-size:.68rem;font-weight:700;text-transform:uppercase;background:#fef3c7;color:#92400e">Role</span>
                        <span id="tabProfileStatusBadge" style="display:inline-block;padding:.25rem .7rem;border-radius:99px;font-size:.68rem;font-weight:700;text-transform:uppercase;background:#d1fae5;color:#065f46">Active</span>
                    </div>

                    <div style="border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;padding:.75rem 0;margin-bottom:1.25rem;text-align:left;font-size:.78rem">
                        <div style="display:flex;justify-content:space-between;padding:.35rem 0"><span style="color:#64748b">Email</span><span id="tabProfileFactEmail" style="font-weight:600;color:#1e293b">—</span></div>
                        <div style="display:flex;justify-content:space-between;padding:.35rem 0"><span style="color:#64748b">Member Since</span><span id="tabProfileFactCreated" style="font-weight:600;color:#1e293b">—</span></div>
                        <div style="display:flex;justify-content:space-between;padding:.35rem 0"><span style="color:#64748b">Last Login</span><span id="tabProfileFactLogin" style="font-weight:600;color:#1e293b">—</span></div>
                    </div>

                    <div style="display:flex;justify-content:center;gap:.5rem;flex-wrap:wrap">
                        <button type="button" id="tabProfileChooseBtn" class="btn" style="background:#fffbeb;color:#b45309;border:1px solid #fde68a;font-size:.75rem;padding:.45rem .85rem;border-radius:8px">
                            <i class="fa-solid fa-camera"></i> Change Photo
                        </button>
                        <input type="file" id="tabProfileFileInput" accept="image/jpeg,image/png,image/webp" style="display:none">
                        <button type="button" id="tabProfileRemoveBtn" class="btn" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;font-size:.75rem;padding:.45rem .85rem;border-radius:8px" disabled>
                            <i class="fa-solid fa-trash-can"></i> Remove
                        </button>
                    </div>
                    <p style="font-size:.68rem;color:#94a3b8;margin-top:.6rem">JPEG, PNG, or WebP up to 4 MB.</p>
                </div>

                <!-- Right Column: Personal Details & Password Management -->
                <div style="display:flex;flex-direction:column;gap:1.25rem">
                    
                    <!-- Edit Profile Card -->
                    <div class="crd" style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05)">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.15rem;border-bottom:1px solid #f1f5f9;padding-bottom:.75rem">
                            <div>
                                <span style="font-size:.68rem;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.08em;display:block">Personal Details</span>
                                <h3 style="font-size:1.1rem;font-weight:700;color:#1e293b;margin:0">Edit Profile</h3>
                            </div>
                            <span id="tabProfileDraftState" style="font-size:.72rem;color:#94a3b8">Synced</span>
                        </div>

                        <form id="tabProfileForm" novalidate>
                            <div style="margin-bottom:1rem">
                                <label class="lbl" for="tabProfileFullName" style="display:block;font-size:.75rem;font-weight:600;color:#475569;margin-bottom:.3rem">Full Name <span style="color:#dc2626">*</span></label>
                                <input type="text" id="tabProfileFullName" name="full_name" class="inp" maxlength="100" required style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:.6rem .85rem;font-size:.85rem;outline:none" placeholder="Enter your full name">
                                <p id="tabProfileFullNameErr" style="font-size:.7rem;color:#dc2626;margin-top:.25rem;display:none"></p>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:1rem">
                                <div>
                                    <label class="lbl" for="tabProfileUsername" style="display:block;font-size:.75rem;font-weight:600;color:#475569;margin-bottom:.3rem">Username <span style="color:#dc2626">*</span></label>
                                    <input type="text" id="tabProfileUsername" name="username" class="inp" minlength="3" maxlength="50" required autocapitalize="none" spellcheck="false" style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:.6rem .85rem;font-size:.85rem;outline:none">
                                    <p id="tabProfileUsernameErr" style="font-size:.7rem;color:#dc2626;margin-top:.25rem;display:none"></p>
                                </div>
                                <div>
                                    <label class="lbl" for="tabProfileEmail" style="display:block;font-size:.75rem;font-weight:600;color:#475569;margin-bottom:.3rem">Email Address</label>
                                    <input type="email" id="tabProfileEmail" name="email" class="inp" maxlength="100" style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:.6rem .85rem;font-size:.85rem;outline:none" placeholder="Optional">
                                    <p id="tabProfileEmailErr" style="font-size:.7rem;color:#dc2626;margin-top:.25rem;display:none"></p>
                                </div>
                            </div>

                            <div id="tabProfileCurrentPasswordWrap" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.85rem;margin-bottom:1rem">
                                <label class="lbl" for="tabProfileCurrentPassword" style="display:block;font-size:.75rem;font-weight:700;color:#92400e;margin-bottom:.3rem">Current Password <span style="color:#dc2626">* (Required to change username or email)</span></label>
                                <input type="password" id="tabProfileCurrentPassword" name="current_password" class="inp" maxlength="4096" style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.55rem .75rem;font-size:.85rem;background:#fff" placeholder="Enter current password">
                                <p id="tabProfileCurrentPasswordErr" style="font-size:.7rem;color:#dc2626;margin-top:.25rem;display:none"></p>
                            </div>

                            <div style="display:flex;justify-content:flex-end;align-items:center;gap:.65rem;margin-top:1.25rem">
                                <button type="button" id="tabProfileDiscardBtn" class="btn" style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;padding:.55rem 1rem;border-radius:10px;font-size:.8rem;font-weight:500" disabled>Discard</button>
                                <button type="submit" id="tabProfileSaveBtn" class="btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:600;padding:.55rem 1.25rem;border-radius:10px;font-size:.82rem;box-shadow:0 2px 8px rgba(245,158,11,.35);border:none;cursor:pointer" disabled>
                                    <i class="fa-solid fa-check"></i> Save Profile
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Security & Password Card -->
                    <div class="crd" style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05)">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
                            <div>
                                <span style="font-size:.68rem;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.08em;display:block">Security &amp; Credentials</span>
                                <h3 style="font-size:1.1rem;font-weight:700;color:#1e293b;margin:0">Account Password</h3>
                            </div>
                            <i class="fa-solid fa-shield-halved" style="color:#d97706;font-size:1.4rem"></i>
                        </div>
                        <p style="font-size:.8rem;color:#64748b;margin:0 0 1rem;line-height:1.5">Use a strong, unique password with at least 12 characters to keep your account safe.</p>
                        <button type="button" id="tabProfileOpenPasswordBtn" class="btn" style="background:#f8fafc;color:#334155;border:1px solid #cbd5e1;padding:.55rem 1.15rem;border-radius:10px;font-size:.8rem;font-weight:600">
                            <i class="fa-solid fa-key" style="color:#d97706"></i> Change Password
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <!-- Password Modal -->
        <div class="mo" id="tabProfilePasswordModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:1rem">
            <div class="mc" style="background:#fff;border-radius:18px;max-width:480px;width:100%;box-shadow:0 25px 60px rgba(0,0,0,.3);overflow:hidden">
                <div style="background:linear-gradient(135deg,#800000,#5c0606);color:#fff;padding:1.15rem 1.4rem;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-weight:700;font-size:1.05rem;margin:0"><i class="fa-solid fa-shield-halved" style="color:#fbbf24"></i> Change Password</h3>
                    <button type="button" onclick="document.getElementById('tabProfilePasswordModal').style.display='none'" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1">&times;</button>
                </div>
                <form id="tabProfilePasswordForm" style="padding:1.4rem" novalidate>
                    <div id="tabProfilePasswordAlert" style="display:none;padding:.65rem .85rem;border-radius:8px;font-size:.78rem;margin-bottom:1rem"></div>

                    <div style="margin-bottom:1rem">
                        <label style="display:block;font-size:.75rem;font-weight:600;color:#475569;margin-bottom:.3rem">Current Password</label>
                        <input type="password" id="tabPwdCurrent" required class="inp" style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.55rem .75rem;font-size:.85rem">
                        <p id="tabPwdCurrentErr" style="font-size:.7rem;color:#dc2626;margin-top:.25rem;display:none"></p>
                    </div>

                    <div style="margin-bottom:1rem">
                        <label style="display:block;font-size:.75rem;font-weight:600;color:#475569;margin-bottom:.3rem">New Password (Min. 12 characters)</label>
                        <input type="password" id="tabPwdNew" required class="inp" style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.55rem .75rem;font-size:.85rem">
                        <div style="display:flex;justify-content:space-between;font-size:.68rem;color:#64748b;margin-top:.3rem">
                            <span id="tabPwdLen">0 characters</span>
                            <span id="tabPwdBytes">0 / 72 bytes</span>
                        </div>
                        <p id="tabPwdNewErr" style="font-size:.7rem;color:#dc2626;margin-top:.25rem;display:none"></p>
                    </div>

                    <div style="margin-bottom:1.25rem">
                        <label style="display:block;font-size:.75rem;font-weight:600;color:#475569;margin-bottom:.3rem">Confirm New Password</label>
                        <input type="password" id="tabPwdConfirm" required class="inp" style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.55rem .75rem;font-size:.85rem">
                        <p id="tabPwdConfirmErr" style="font-size:.7rem;color:#dc2626;margin-top:.25rem;display:none"></p>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:.6rem">
                        <button type="button" onclick="document.getElementById('tabProfilePasswordModal').style.display='none'" class="btn" style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .95rem;font-size:.8rem">Cancel</button>
                        <button type="submit" id="tabPwdSubmitBtn" class="btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:8px;padding:.5rem 1.15rem;font-size:.8rem;font-weight:600;cursor:pointer">Change Password</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Image Upload Preview Modal -->
        <div class="mo" id="tabProfileImageModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:1rem">
            <div class="mc" style="background:#fff;border-radius:18px;max-width:400px;width:100%;box-shadow:0 25px 60px rgba(0,0,0,.3);overflow:hidden">
                <div style="background:linear-gradient(135deg,#800000,#5c0606);color:#fff;padding:1rem 1.25rem;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-weight:700;font-size:1rem;margin:0"><i class="fa-solid fa-camera" style="color:#fbbf24"></i> Profile Photo Preview</h3>
                    <button type="button" onclick="document.getElementById('tabProfileImageModal').style.display='none'" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1">&times;</button>
                </div>
                <div style="padding:1.4rem;text-align:center">
                    <img id="tabProfileImagePreview" src="" alt="Preview" style="width:10rem;height:10rem;border-radius:50%;object-fit:cover;margin:0 auto 1.25rem;border:3px solid #f59e0b;box-shadow:0 6px 18px rgba(0,0,0,.15);display:block">
                    <p style="font-size:.78rem;color:#64748b;margin:0 0 1.25rem">Set this photo as your profile avatar?</p>
                    <div style="display:flex;justify-content:center;gap:.6rem">
                        <button type="button" onclick="document.getElementById('tabProfileImageModal').style.display='none'" class="btn" style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .95rem;font-size:.8rem">Cancel</button>
                        <button type="button" id="tabProfileConfirmUploadBtn" class="btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:8px;padding:.5rem 1.15rem;font-size:.8rem;font-weight:600;cursor:pointer">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Upload Photo
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            let _profData = null;
            let _pendingImageFile = null;

            function getCsrf() {
                if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) return CSRF_TOKEN;
                const m = document.querySelector('meta[name="csrf-token"]');
                return m ? m.getAttribute('content') || '' : '';
            }

            function showAlert(msg, kind) {
                const el = document.getElementById('tabProfileGlobalAlert');
                if (!el) return;
                if (!msg) { el.style.display = 'none'; return; }
                el.textContent = msg;
                el.style.display = 'block';
                if (kind === 'success') {
                    el.style.background = '#ecfdf5';
                    el.style.color = '#065f46';
                    el.style.border = '1px solid #a7f3d0';
                } else {
                    el.style.background = '#fef2f2';
                    el.style.color = '#991b1b';
                    el.style.border = '1px solid #fecaca';
                }
            }

            function toastMsg(msg, kind) {
                if (typeof toast === 'function') {
                    toast(msg, kind === 'success' ? 'ok' : 'err');
                } else {
                    showAlert(msg, kind);
                }
            }

            function updateInitials(name) {
                let init = '';
                const parts = (name || '').trim().split(/\s+/);
                for (const p of parts) { if (p) init += p.charAt(0); }
                init = (init.substring(0, 2) || 'U').toUpperCase();
                const iniEl = document.getElementById('tabProfileAvatarInitials');
                if (iniEl) iniEl.textContent = init;
                return init;
            }

            async function loadTabProfile() {
                try {
                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=profile_get', {
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });
                    const d = await r.json();
                    if (d.status === 'success' && d.user) {
                        _profData = d.user;
                        renderProfile(d.user);
                    } else {
                        showAlert(d.message || 'Could not load profile data.', 'error');
                    }
                } catch (e) {
                    console.error('Profile load error:', e);
                    showAlert('Network error loading profile. Please try again.', 'error');
                }
            }

            function renderProfile(u) {
                const name = u.full_name || u.username || 'User';
                const nameEl = document.getElementById('tabProfileDisplayName');
                if (nameEl) nameEl.textContent = name;
                const usrEl = document.getElementById('tabProfileDisplayUsername');
                if (usrEl) usrEl.textContent = '@' + (u.username || '');
                const hdrName = document.getElementById('tabProfileHeaderName');
                if (hdrName) hdrName.textContent = name;

                const fnInput = document.getElementById('tabProfileFullName');
                if (fnInput) fnInput.value = u.full_name || '';
                const unInput = document.getElementById('tabProfileUsername');
                if (unInput) unInput.value = u.username || '';
                const emInput = document.getElementById('tabProfileEmail');
                if (emInput) emInput.value = u.email || '';

                const emailFact = document.getElementById('tabProfileFactEmail');
                if (emailFact) emailFact.textContent = u.email || '—';
                const createdFact = document.getElementById('tabProfileFactCreated');
                if (createdFact) createdFact.textContent = (u.created_at || '').split('T')[0] || (u.created_at || '—');
                const loginFact = document.getElementById('tabProfileFactLogin');
                if (loginFact) loginFact.textContent = (u.last_login || '').split('T')[0] || (u.last_login || '—');

                const roleBadge = document.getElementById('tabProfileRoleBadge');
                if (roleBadge) roleBadge.textContent = (u.role || 'user').replace(/_/g, ' ');

                const imgEl = document.getElementById('tabProfileAvatarImg');
                const iniEl = document.getElementById('tabProfileAvatarInitials');
                const remBtn = document.getElementById('tabProfileRemoveBtn');

                updateInitials(name);

                if (u.profile_image && u.profile_image.present && u.profile_image.url) {
                    if (imgEl) {
                        imgEl.src = u.profile_image.url + '?t=' + Date.now();
                        imgEl.style.display = 'block';
                    }
                    if (iniEl) iniEl.style.display = 'none';
                    if (remBtn) remBtn.disabled = false;
                } else {
                    if (imgEl) imgEl.style.display = 'none';
                    if (iniEl) iniEl.style.display = 'flex';
                    if (remBtn) remBtn.disabled = true;
                }

                checkDirty();
            }

            function checkDirty() {
                if (!_profData) return;
                const fn = (document.getElementById('tabProfileFullName')?.value || '').trim();
                const un = (document.getElementById('tabProfileUsername')?.value || '').trim();
                const em = (document.getElementById('tabProfileEmail')?.value || '').trim();

                const isDirty = fn !== (_profData.full_name || '')
                    || un !== (_profData.username || '')
                    || em !== (_profData.email || '');

                const identityChanged = un !== (_profData.username || '') || em !== (_profData.email || '');
                const pwdWrap = document.getElementById('tabProfileCurrentPasswordWrap');
                if (pwdWrap) pwdWrap.style.display = identityChanged ? 'block' : 'none';

                const saveBtn = document.getElementById('tabProfileSaveBtn');
                if (saveBtn) saveBtn.disabled = !isDirty;
                const discBtn = document.getElementById('tabProfileDiscardBtn');
                if (discBtn) discBtn.disabled = !isDirty;

                const draftState = document.getElementById('tabProfileDraftState');
                if (draftState) {
                    draftState.textContent = isDirty ? 'Unsaved changes' : 'Synced';
                    draftState.style.color = isDirty ? '#d97706' : '#94a3b8';
                }
            }

            // Input handlers
            ['tabProfileFullName', 'tabProfileUsername', 'tabProfileEmail'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', checkDirty);
            });

            document.getElementById('tabProfileDiscardBtn')?.addEventListener('click', () => {
                if (_profData) renderProfile(_profData);
            });

            // Save profile form
            document.getElementById('tabProfileForm')?.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!_profData) return;
                showAlert('', '');

                const fn = (document.getElementById('tabProfileFullName')?.value || '').trim();
                const un = (document.getElementById('tabProfileUsername')?.value || '').trim();
                const em = (document.getElementById('tabProfileEmail')?.value || '').trim();
                const cp = (document.getElementById('tabProfileCurrentPassword')?.value || '').trim();

                if (!fn) { showAlert('Full name is required.', 'error'); return; }
                if (!un) { showAlert('Username is required.', 'error'); return; }

                const identityChanged = un !== (_profData.username || '') || em !== (_profData.email || '');
                if (identityChanged && !cp) {
                    showAlert('Current password is required to change username or email.', 'error');
                    return;
                }

                const payload = {
                    full_name: fn,
                    username: un,
                    email: em || null,
                    profile_version: _profData.profile_version
                };
                if (identityChanged) payload.current_password = cp;

                const saveBtn = document.getElementById('tabProfileSaveBtn');
                if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…'; }

                try {
                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=profile_update', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrf()
                        },
                        body: JSON.stringify(payload),
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (d.status === 'success' && d.user) {
                        _profData = d.user;
                        renderProfile(d.user);
                        const pwdInput = document.getElementById('tabProfileCurrentPassword');
                        if (pwdInput) pwdInput.value = '';
                        toastMsg('Profile updated successfully!', 'success');
                    } else {
                        showAlert(d.message || 'Could not update profile.', 'error');
                    }
                } catch (err) {
                    console.error('Save error:', err);
                    showAlert('Network error while saving profile.', 'error');
                } finally {
                    if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> Save Profile'; }
                    checkDirty();
                }
            });

            // Image pick & upload
            const fileInp = document.getElementById('tabProfileFileInput');
            document.getElementById('tabProfileChooseBtn')?.addEventListener('click', () => {
                if (fileInp) fileInp.click();
            });

            fileInp?.addEventListener('change', (e) => {
                const file = e.target.files?.[0];
                if (!file) return;
                if (file.size > 4 * 1024 * 1024) {
                    showAlert('Image is too large. Maximum size is 4 MB.', 'error');
                    return;
                }
                _pendingImageFile = file;
                const reader = new FileReader();
                reader.onload = (re) => {
                    const prev = document.getElementById('tabProfileImagePreview');
                    if (prev) prev.src = re.target.result;
                    const modal = document.getElementById('tabProfileImageModal');
                    if (modal) modal.style.display = 'flex';
                };
                reader.readAsDataURL(file);
            });

            document.getElementById('tabProfileConfirmUploadBtn')?.addEventListener('click', async () => {
                if (!_pendingImageFile || !_profData) return;
                const modal = document.getElementById('tabProfileImageModal');
                const fd = new FormData();
                fd.append('image', _pendingImageFile);
                fd.append('profile_version', _profData.profile_version);
                fd.append('csrf_token', getCsrf());

                try {
                    const r = await fetch('<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        if (modal) modal.style.display = 'none';
                        toastMsg('Profile image updated!', 'success');
                        loadTabProfile();
                    } else {
                        alert(d.message || 'Image upload failed.');
                    }
                } catch (e) {
                    alert('Error uploading image.');
                }
            });

            document.getElementById('tabProfileRemoveBtn')?.addEventListener('click', async () => {
                if (!_profData || !confirm('Remove your profile photo?')) return;
                try {
                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=profile_image_remove', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrf()
                        },
                        body: JSON.stringify({ profile_version: _profData.profile_version }),
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        toastMsg('Profile image removed.', 'success');
                        loadTabProfile();
                    } else {
                        showAlert(d.message || 'Could not remove image.', 'error');
                    }
                } catch (e) {
                    showAlert('Network error removing image.', 'error');
                }
            });

            // Password Modal
            document.getElementById('tabProfileOpenPasswordBtn')?.addEventListener('click', () => {
                const modal = document.getElementById('tabProfilePasswordModal');
                if (modal) {
                    modal.style.display = 'flex';
                    document.getElementById('tabProfilePasswordForm')?.reset();
                    document.getElementById('tabProfilePasswordAlert').style.display = 'none';
                    document.getElementById('tabPwdLen').textContent = '0 characters';
                    document.getElementById('tabPwdBytes').textContent = '0 / 72 bytes';
                }
            });

            document.getElementById('tabPwdNew')?.addEventListener('input', function() {
                const val = this.value || '';
                const len = Array.from(val).length;
                const bytes = new TextEncoder().encode(val).length;
                document.getElementById('tabPwdLen').textContent = len + ' characters';
                document.getElementById('tabPwdBytes').textContent = bytes + ' / 72 bytes';
            });

            document.getElementById('tabProfilePasswordForm')?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const cur = (document.getElementById('tabPwdCurrent')?.value || '').trim();
                const np = (document.getElementById('tabPwdNew')?.value || '');
                const conf = (document.getElementById('tabPwdConfirm')?.value || '');
                const alertEl = document.getElementById('tabProfilePasswordAlert');

                if (!cur || !np || !conf) {
                    alertEl.textContent = 'All fields are required.';
                    alertEl.style.display = 'block';
                    alertEl.style.background = '#fef2f2';
                    alertEl.style.color = '#991b1b';
                    return;
                }
                if (np !== conf) {
                    alertEl.textContent = 'New passwords do not match.';
                    alertEl.style.display = 'block';
                    alertEl.style.background = '#fef2f2';
                    alertEl.style.color = '#991b1b';
                    return;
                }
                if (np.length < 12) {
                    alertEl.textContent = 'Password must be at least 12 characters.';
                    alertEl.style.display = 'block';
                    alertEl.style.background = '#fef2f2';
                    alertEl.style.color = '#991b1b';
                    return;
                }

                const btn = document.getElementById('tabPwdSubmitBtn');
                if (btn) { btn.disabled = true; btn.textContent = 'Changing…'; }

                try {
                    const r = await fetch('<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>?action=password_change', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrf()
                        },
                        body: JSON.stringify({
                            current_password: cur,
                            new_password: np,
                            confirm_password: conf
                        }),
                        credentials: 'same-origin'
                    });
                    const d = await r.json();
                    if (d.status === 'success') {
                        document.getElementById('tabProfilePasswordModal').style.display = 'none';
                        toastMsg('Password changed successfully!', 'success');
                    } else {
                        alertEl.textContent = d.message || 'Could not change password.';
                        alertEl.style.display = 'block';
                        alertEl.style.background = '#fef2f2';
                        alertEl.style.color = '#991b1b';
                    }
                } catch (err) {
                    alertEl.textContent = 'Network error changing password.';
                    alertEl.style.display = 'block';
                    alertEl.style.background = '#fef2f2';
                    alertEl.style.color = '#991b1b';
                } finally {
                    if (btn) { btn.disabled = false; btn.textContent = 'Change Password'; }
                }
            });

            // Initial load
            loadTabProfile();
            window.loadTabProfile = loadTabProfile;
        })();
        </script>
        <?php
    }
}
