<?php
/**
 * Super Admin — Mobile App Release Management Section
 */
?>
<style>
/* ═══ App Release UI Custom Styles (High Contrast & Professional Ergonomics) ═══ */
#section-app_release {
    color: #f1f5f9;
}
#section-app_release .ar-card {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 0.75rem;
    padding: 1.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2), 0 2px 4px -2px rgba(0, 0, 0, 0.2);
}
#section-app_release .ar-form-group {
    margin-bottom: 1.25rem;
}
#section-app_release .ar-label {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.825rem;
    font-weight: 600;
    color: #e2e8f0;
    margin-bottom: 0.45rem;
    letter-spacing: 0.01em;
}
#section-app_release .ar-label .req {
    color: #f87171;
    font-weight: bold;
}
#section-app_release .ar-input,
#section-app_release .ar-select,
#section-app_release .ar-textarea {
    width: 100%;
    padding: 0.7rem 0.95rem;
    background-color: #0f172a !important;
    border: 1.5px solid #334155 !important;
    border-radius: 0.5rem;
    color: #f8fafc !important;
    font-size: 0.875rem;
    line-height: 1.5;
    transition: all 0.15s ease-in-out;
    box-sizing: border-box;
}
#section-app_release .ar-input::placeholder,
#section-app_release .ar-textarea::placeholder {
    color: #64748b;
    opacity: 1;
}
#section-app_release .ar-input:focus,
#section-app_release .ar-select:focus,
#section-app_release .ar-textarea:focus {
    outline: none !important;
    border-color: #38bdf8 !important;
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25) !important;
    background-color: #090d16 !important;
}
#section-app_release .ar-select option {
    background-color: #0f172a;
    color: #f8fafc;
    padding: 0.5rem;
}
#section-app_release .ar-help {
    display: block;
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 0.4rem;
    line-height: 1.4;
}
#section-app_release .ar-file-upload-box {
    border: 2px dashed #475569;
    background: #0f172a;
    border-radius: 0.5rem;
    padding: 1.25rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
}
#section-app_release .ar-file-upload-box:hover {
    border-color: #38bdf8;
    background: #131d31;
}
#section-app_release .ar-toggle-card {
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 0.5rem;
    padding: 0.85rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
    transition: border-color 0.15s ease;
}
#section-app_release .ar-toggle-card:hover {
    border-color: #475569;
}
#section-app_release .ar-toggle-card input[type="checkbox"] {
    width: 1.15rem;
    height: 1.15rem;
    cursor: pointer;
    accent-color: #3b82f6;
    flex-shrink: 0;
}
#section-app_release .ar-toggle-card.warn-box {
    background: rgba(239, 68, 68, 0.08);
    border-color: rgba(239, 68, 68, 0.3);
}
#section-app_release .ar-toggle-card.warn-box:hover {
    border-color: rgba(239, 68, 68, 0.5);
}
#section-app_release .ar-toggle-card.warn-box input[type="checkbox"] {
    accent-color: #ef4444;
}
</style>

<!-- ═══ MOBILE APP RELEASE MANAGER ═══ -->
<section id="section-app_release" class="section <?= ($activeSection ?? '') === 'app_release' ? 'active' : '' ?>"<?= ($activeSection ?? '') === 'app_release' ? '' : ' hidden' ?>>
    <div class="sec-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem">
        <div>
            <h2 class="sec-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.4rem;color:#f8fafc"><i class="fa-solid fa-mobile-screen-button" style="color:#38bdf8"></i> Mobile App Release Manager</h2>
            <p class="sec-desc" style="color:#94a3b8;font-size:0.875rem">Direct in-app update management, APK upload, version enforcement & announcements (No cPanel required)</p>
        </div>
        <button type="button" class="btn btn-outline btn-sm" onclick="if(window.AppReleaseUI)window.AppReleaseUI.refresh()"><i class="fa-solid fa-rotate"></i> Refresh Status</button>
    </div>

    <div id="app-release-alert-box"></div>

    <!-- Live Status & Metrics Grid -->
    <div class="grid-2" style="margin-bottom:1.5rem;gap:1.5rem">
        <div class="ar-card">
            <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.1rem;color:#f8fafc;margin-bottom:1rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
                <i class="fa-solid fa-circle-info" style="color:#38bdf8"></i> Active Release Status
            </h3>
            <div id="app-release-status-container">
                <div style="text-align:center;padding:2rem;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading release status...</div>
            </div>
        </div>

        <div class="ar-card">
            <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.1rem;color:#f8fafc;margin-bottom:0.5rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
                <i class="fa-solid fa-cloud-arrow-up" style="color:#4ade80"></i> Upload New APK Build
            </h3>
            <p style="font-size:0.825rem;color:#94a3b8;margin-bottom:1.25rem;line-height:1.5">Upload newly built <code>.apk</code> binary directly. SHA-256 hash and file size are calculated automatically.</p>
            
            <form id="form-upload-apk" enctype="multipart/form-data" onsubmit="AppReleaseUI.uploadApk(event)">
                <?= csrfField() ?>
                <div class="ar-form-group">
                    <label class="ar-label" for="upload-abi-select">
                        <i class="fa-solid fa-microchip" style="color:#94a3b8;font-size:0.75rem"></i> Artifact Architecture
                    </label>
                    <select name="abi" id="upload-abi-select" class="ar-select">
                        <option value="universal">Universal APK (Recommended — Works on all Android devices)</option>
                        <option value="arm64-v8a">ARM64-v8a (64-bit split APK — ~50% smaller file)</option>
                        <option value="armeabi-v7a">ARMeabi-v7a (32-bit legacy split APK)</option>
                    </select>
                </div>
                
                <div class="ar-form-group">
                    <label class="ar-label" for="upload-apk-input">
                        <i class="fa-solid fa-file-arrow-up" style="color:#94a3b8;font-size:0.75rem"></i> APK File (.apk) <span class="req">*</span>
                    </label>
                    <input type="file" name="apk_file" id="upload-apk-input" accept=".apk,application/vnd.android.package-archive" class="ar-input" required style="padding:0.5rem">
                    <span class="ar-help">Select the release build e.g. <code>build/app/outputs/flutter-apk/app-release.apk</code> (Max 200MB)</span>
                </div>

                <div id="upload-progress-wrapper" style="display:none;margin-bottom:1.25rem;background:#0f172a;padding:0.75rem;border-radius:0.5rem;border:1px solid #334155">
                    <div style="display:flex;justify-content:space-between;font-size:0.8rem;color:#e2e8f0;margin-bottom:0.4rem;font-weight:500">
                        <span id="upload-progress-label">Uploading...</span>
                        <span id="upload-progress-pct" style="color:#38bdf8;font-weight:700">0%</span>
                    </div>
                    <div style="width:100%;height:10px;background:#1e293b;border-radius:5px;overflow:hidden">
                        <div id="upload-progress-bar" style="width:0%;height:100%;background:linear-gradient(90deg,#3b82f6,#10b981);transition:width 0.2s"></div>
                    </div>
                </div>

                <button type="submit" id="btn-upload-apk" class="btn btn-primary" style="width:100%;justify-content:center;padding:0.75rem 1.25rem;font-size:0.875rem"><i class="fa-solid fa-upload"></i> Upload & Publish APK</button>
            </form>
        </div>
    </div>

    <!-- Version Policy & Announcement Form -->
    <div class="ar-card" style="margin-bottom:1.5rem">
        <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.1rem;color:#f8fafc;margin-bottom:0.75rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
            <i class="fa-solid fa-sliders" style="color:#a78bfa"></i> Version Policy & Update Settings
        </h3>
        
        <form id="form-release-config" onsubmit="AppReleaseUI.saveConfig(event)">
            <?= csrfField() ?>
            <div class="grid-2" style="gap:1.5rem;margin-bottom:0.5rem">
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-latest-version">
                        <i class="fa-solid fa-tag" style="color:#94a3b8;font-size:0.75rem"></i> Latest Version String <span class="req">*</span>
                    </label>
                    <input type="text" name="latest_version" id="cfg-latest-version" class="ar-input" placeholder="1.5.0" required>
                    <span class="ar-help">Must match <code>version: X.Y.Z</code> in pubspec.yaml</span>
                </div>
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-latest-build">
                        <i class="fa-solid fa-code-commit" style="color:#94a3b8;font-size:0.75rem"></i> Latest Build Number (VersionCode) <span class="req">*</span>
                    </label>
                    <input type="number" name="latest_build" id="cfg-latest-build" class="ar-input" placeholder="24" min="1" required>
                    <span class="ar-help">Must match <code>+build</code> in pubspec.yaml</span>
                </div>
            </div>

            <div class="grid-2" style="gap:1.5rem;margin-bottom:0.5rem">
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-min-version">
                        <i class="fa-solid fa-shield" style="color:#94a3b8;font-size:0.75rem"></i> Minimum Required Version
                    </label>
                    <input type="text" name="min_version" id="cfg-min-version" class="ar-input" placeholder="1.0.0">
                    <span class="ar-help">Devices running older versions will be blocked until updated</span>
                </div>
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-min-build">
                        <i class="fa-solid fa-shield-halved" style="color:#94a3b8;font-size:0.75rem"></i> Minimum Required Build Code
                    </label>
                    <input type="number" name="min_build" id="cfg-min-build" class="ar-input" placeholder="1" min="1">
                    <span class="ar-help">Devices below this build number will be blocked until updated</span>
                </div>
            </div>

            <div class="ar-form-group">
                <label class="ar-toggle-card warn-box" for="cfg-force-update">
                    <input type="checkbox" name="force_update" id="cfg-force-update" value="1">
                    <div>
                        <div style="font-size:0.875rem;font-weight:600;color:#f87171;display:flex;align-items:center;gap:0.4rem">
                            <i class="fa-solid fa-triangle-exclamation"></i> Enforce Mandatory Update Gate
                        </div>
                        <div style="font-size:0.75rem;color:#cbd5e1;margin-top:0.15rem">
                            When enabled, displays a full-screen blocking modal on all older app versions preventing further usage until the update is installed.
                        </div>
                    </div>
                </label>
            </div>

            <div class="ar-form-group">
                <label class="ar-label" for="cfg-release-notes">
                    <i class="fa-solid fa-file-lines" style="color:#94a3b8;font-size:0.75rem"></i> Release Notes / Changelog
                </label>
                <textarea name="release_notes" id="cfg-release-notes" class="ar-textarea" rows="4" placeholder="• Faster QR attendance&#10;• Offline sync improvements&#10;• Bug fixes in Mezmur player"></textarea>
                <span class="ar-help">Shown directly to teachers and students on the in-app update prompt</span>
            </div>

            <div class="grid-2" style="gap:1.5rem;margin-bottom:0.5rem">
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-banner-text">
                        <i class="fa-solid fa-bullhorn" style="color:#94a3b8;font-size:0.75rem"></i> In-App Top Banner Announcement
                    </label>
                    <input type="text" name="banner_text" id="cfg-banner-text" class="ar-input" placeholder="New update available with offline sync improvements!">
                    <span class="ar-help">Optional non-intrusive alert banner shown at top of the mobile home screen</span>
                </div>
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-banner-kind">
                        <i class="fa-solid fa-palette" style="color:#94a3b8;font-size:0.75rem"></i> Banner Tone / Style
                    </label>
                    <select name="banner_kind" id="cfg-banner-kind" class="ar-select">
                        <option value="info">Information (Blue)</option>
                        <option value="warn">Warning / Alert (Amber)</option>
                    </select>
                    <span class="ar-help">Controls the accent color and icon of the in-app announcement banner</span>
                </div>
            </div>

            <div class="ar-form-group" style="margin-bottom:1.5rem">
                <label class="ar-toggle-card" for="cfg-drains-enabled">
                    <input type="checkbox" name="background_drains_enabled" id="cfg-drains-enabled" value="1" checked>
                    <div>
                        <div style="font-size:0.875rem;font-weight:600;color:#e2e8f0;display:flex;align-items:center;gap:0.4rem">
                            <i class="fa-solid fa-cloud-arrow-up" style="color:#38bdf8"></i> Enable Background Outbox Sync Drains
                        </div>
                        <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.15rem">
                            Allows mobile devices to sync offline queues. Uncheck only during active backend maintenance.
                        </div>
                    </div>
                </label>
            </div>

            <div style="display:flex;justify-content:flex-end">
                <button type="submit" id="btn-save-cfg" class="btn btn-primary" style="padding:0.75rem 1.75rem;font-size:0.875rem"><i class="fa-solid fa-floppy-disk"></i> Save Release Policy</button>
            </div>
        </form>
    </div>
</section>
