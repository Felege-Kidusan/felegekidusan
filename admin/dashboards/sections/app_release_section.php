<?php
/**
 * Super Admin — Mobile App Release Management Section
 */
?>
<!-- ═══ MOBILE APP RELEASE MANAGER ═══ -->
<section id="section-app_release" class="section <?= ($activeSection ?? '') === 'app_release' ? 'active' : '' ?>"<?= ($activeSection ?? '') === 'app_release' ? '' : ' hidden' ?>>
    <div class="sec-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div>
            <h2 class="sec-title"><i class="fa-solid fa-mobile-screen-button"></i> Mobile App Release Manager</h2>
            <p class="sec-desc">Direct in-app update management, APK upload, version enforcement & announcements (No cPanel required)</p>
        </div>
        <button type="button" class="btn btn-outline btn-sm" onclick="if(window.AppReleaseUI)window.AppReleaseUI.refresh()"><i class="fa-solid fa-rotate"></i> Refresh Status</button>
    </div>

    <div id="app-release-alert-box"></div>

    <!-- Live Status & Metrics Grid -->
    <div class="grid-2" style="margin-bottom:1.5rem">
        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-circle-info"></i> Active Release Status</h3>
            <div id="app-release-status-container">
                <div style="text-align:center;padding:1.5rem;color:#64748b"><i class="fa-solid fa-spinner fa-spin"></i> Loading release status...</div>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title"><i class="fa-solid fa-cloud-arrow-up"></i> Upload New APK Build</h3>
            <p style="font-size:.8rem;color:#94a3b8;margin-bottom:1rem">Upload newly built <code>.apk</code> binary directly. SHA-256 and sizes are calculated automatically.</p>
            
            <form id="form-upload-apk" enctype="multipart/form-data" onsubmit="AppReleaseUI.uploadApk(event)">
                <?= csrfField() ?>
                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label" style="font-size:.8rem">Artifact Type</label>
                    <select name="abi" id="upload-abi-select" class="form-control" style="font-size:.85rem">
                        <option value="universal">Universal APK (Recommended - Works on all phones)</option>
                        <option value="arm64-v8a">ARM64-v8a (64-bit split APK - ~50% smaller)</option>
                        <option value="armeabi-v7a">ARMeabi-v7a (32-bit legacy split APK)</option>
                    </select>
                </div>
                
                <div class="form-group" style="margin-bottom:.85rem">
                    <label class="form-label" style="font-size:.8rem">APK File (.apk)</label>
                    <input type="file" name="apk_file" id="upload-apk-input" accept=".apk,application/vnd.android.package-archive" class="form-control" required style="font-size:.85rem">
                </div>

                <div id="upload-progress-wrapper" style="display:none;margin-bottom:1rem">
                    <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#94a3b8;margin-bottom:.25rem">
                        <span id="upload-progress-label">Uploading...</span>
                        <span id="upload-progress-pct">0%</span>
                    </div>
                    <div style="width:100%;height:8px;background:rgba(255,255,255,0.1);border-radius:4px;overflow:hidden">
                        <div id="upload-progress-bar" style="width:0%;height:100%;background:linear-gradient(90deg,#3b82f6,#10b981);transition:width .2s"></div>
                    </div>
                </div>

                <button type="submit" id="btn-upload-apk" class="btn btn-primary" style="width:100%"><i class="fa-solid fa-upload"></i> Upload & Publish APK</button>
            </form>
        </div>
    </div>

    <!-- Version Policy & Announcement Form -->
    <div class="card" style="margin-bottom:1.5rem">
        <h3 class="card-title"><i class="fa-solid fa-sliders"></i> Version Policy & Update Settings</h3>
        <form id="form-release-config" onsubmit="AppReleaseUI.saveConfig(event)">
            <?= csrfField() ?>
            <div class="grid-2" style="margin-bottom:1rem">
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Latest Version String <span style="color:#f87171">*</span></label>
                    <input type="text" name="latest_version" id="cfg-latest-version" class="form-control" placeholder="1.5.0" required>
                    <small style="font-size:.7rem;color:#64748b">Must match <code>version: X.Y.Z</code> in pubspec.yaml</small>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Latest Build Number (VersionCode) <span style="color:#f87171">*</span></label>
                    <input type="number" name="latest_build" id="cfg-latest-build" class="form-control" placeholder="24" min="1" required>
                    <small style="font-size:.7rem;color:#64748b">Must match <code>+build</code> in pubspec.yaml</small>
                </div>
            </div>

            <div class="grid-2" style="margin-bottom:1rem">
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Minimum Required Version</label>
                    <input type="text" name="min_version" id="cfg-min-version" class="form-control" placeholder="1.0.0">
                    <small style="font-size:.7rem;color:#64748b">Phones below this version are forced to update</small>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Minimum Required Build</label>
                    <input type="number" name="min_build" id="cfg-min-build" class="form-control" placeholder="1" min="1">
                    <small style="font-size:.7rem;color:#64748b">Phones below this build code are forced to update</small>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:1rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
                    <input type="checkbox" name="force_update" id="cfg-force-update" value="1">
                    <span style="font-size:.85rem;font-weight:600;color:#f87171"><i class="fa-solid fa-triangle-exclamation"></i> Enforce Mandatory Update (Full screen blocking gate on all older builds)</span>
                </label>
            </div>

            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label" style="font-size:.8rem">Release Notes / Changelog</label>
                <textarea name="release_notes" id="cfg-release-notes" class="form-control" rows="3" placeholder="• Faster QR attendance&#10;• Offline sync improvements&#10;• Bug fixes in Mezmur player"></textarea>
                <small style="font-size:.7rem;color:#64748b">Shown directly to teachers on the in-app update prompt</small>
            </div>

            <div class="grid-2" style="margin-bottom:1rem">
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">In-App Banner Announcement</label>
                    <input type="text" name="banner_text" id="cfg-banner-text" class="form-control" placeholder="New update available!">
                    <small style="font-size:.7rem;color:#64748b">Optional non-intrusive top banner message</small>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Banner Tone / Style</label>
                    <select name="banner_kind" id="cfg-banner-kind" class="form-control">
                        <option value="info">Information (Blue)</option>
                        <option value="warn">Warning / Alert (Amber)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
                    <input type="checkbox" name="background_drains_enabled" id="cfg-drains-enabled" value="1" checked>
                    <span style="font-size:.85rem;color:#cbd5e1">Enable Background Outbox Sync Drains (Uncheck only during active backend maintenance)</span>
                </label>
            </div>

            <button type="submit" id="btn-save-cfg" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Release Policy</button>
        </form>
    </div>

    <!-- Step-by-Step Production Build Guide Box -->
    <div class="card" style="background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.08)">
        <h3 class="card-title" style="color:#38bdf8"><i class="fa-solid fa-terminal"></i> How to Build and Publish a New Release</h3>
        <div style="font-size:.8rem;color:#cbd5e1;line-height:1.6">
            <p style="margin-bottom:.5rem"><strong>1. Bump Version in Flutter:</strong> In <code>Mobile/wbws_flutter_app/pubspec.yaml</code>, update version e.g. <code>version: 1.5.1+25</code>.</p>
            <p style="margin-bottom:.5rem"><strong>2. Build the APK on your machine:</strong></p>
            <pre style="background:#0f172a;padding:.6rem;border-radius:.4rem;color:#4ade80;overflow-x:auto;font-family:monospace;margin-bottom:.5rem">cd Mobile/wbws_flutter_app
flutter build apk --release</pre>
            <p style="margin-bottom:.5rem"><strong>3. Upload here:</strong> Choose the built <code>build/app/outputs/flutter-apk/app-release.apk</code> in the upload card above and click <strong>Upload & Publish</strong>. The app will immediately notify all teachers and students!</p>
        </div>
    </div>
</section>
