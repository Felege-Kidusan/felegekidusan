/**
 * Super Admin — Mobile App Release Management UI Module
 */
(function (window, document) {
  'use strict';

  var API_URL = '/admin/api_app_release.php';
  var loaded = false;

  function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showAlert(type, message) {
    var box = document.getElementById('app-release-alert-box');
    if (!box) return;
    var icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
    box.innerHTML = '<div class="alert alert-' + type + '" style="margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;border-radius:0.5rem;padding:0.85rem 1.25rem">' +
      '<i class="fa-solid fa-' + icon + '"></i> <span>' + escapeHtml(message) + '</span>' +
      '</div>';
    setTimeout(function () {
      if (box.innerHTML.indexOf(message) !== -1) {
        box.innerHTML = '';
      }
    }, 6000);
  }

  function getCsrfToken() {
    var input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  var AppReleaseUI = {
    init: function () {
      if (loaded) return;
      loaded = true;
      this.refresh();
    },

    refresh: function () {
      var container = document.getElementById('app-release-status-container');
      if (container) {
        container.innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8">' +
          '<i class="fa-solid fa-spinner fa-spin"></i> Loading release status...</div>';
      }

      fetch(API_URL + '?action=get_release', {
        headers: { 'Accept': 'application/json' }
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success' && res.data) {
          AppReleaseUI.renderStatus(res.data);
          AppReleaseUI.populateForm(res.data);
        } else {
          if (container) {
            container.innerHTML = '<div style="color:#f87171;padding:1.5rem;font-size:.875rem;background:#0f172a;border-radius:0.5rem;border:1px solid rgba(239,68,68,0.3)">' +
              '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(res.message || 'Failed to load release info.') + '</div>';
          }
        }
      })
      .catch(function (err) {
        if (container) {
          container.innerHTML = '<div style="color:#f87171;padding:1.5rem;font-size:.875rem;background:#0f172a;border-radius:0.5rem;border:1px solid rgba(239,68,68,0.3)">' +
            '<i class="fa-solid fa-triangle-exclamation"></i> Network error loading release info.</div>';
        }
      });
    },

    renderStatus: function (data) {
      var container = document.getElementById('app-release-status-container');
      if (!container) return;

      var hasUniversal = data.artifacts_detail && data.artifacts_detail.universal;
      var hasArm64 = data.artifacts_detail && data.artifacts_detail['arm64-v8a'];
      var hasArm32 = data.artifacts_detail && data.artifacts_detail['armeabi-v7a'];

      var html = '<div style="display:flex;flex-direction:column;gap:1rem">';
      
      // Version status banner
      html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;background:#0f172a;border-radius:0.5rem;border:1px solid #334155;flex-wrap:wrap;gap:0.75rem">' +
        '<div>' +
          '<div style="font-size:1.2rem;font-weight:700;color:#f8fafc;letter-spacing:0.02em">Version ' + escapeHtml(data.latest_version) + ' <span style="font-size:.85rem;color:#94a3b8;font-weight:400">(Build ' + escapeHtml(data.latest_build) + ')</span></div>' +
          '<div style="font-size:.775rem;color:#cbd5e1;margin-top:.3rem">Min required: v' + escapeHtml(data.min_version) + ' (Build ' + escapeHtml(data.min_build) + ')' +
          (data.force_update ? ' • <span style="color:#f87171;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Mandatory Update Gate</span>' : '') + '</div>' +
        '</div>' +
        '<div>' +
          (data.download_available
            ? '<span style="background:rgba(16,185,129,0.15);color:#4ade80;border:1px solid rgba(16,185,129,0.4);padding:.4rem .9rem;border-radius:99px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:.35rem"><i class="fa-solid fa-circle-check"></i> Published</span>'
            : '<span style="background:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.4);padding:.4rem .9rem;border-radius:99px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:.35rem"><i class="fa-solid fa-circle-xmark"></i> No APK Uploaded</span>') +
        '</div>' +
      '</div>';

      // Active artifacts list
      html += '<div style="margin-top:.25rem">';
      html += '<div style="font-size:.75rem;font-weight:700;color:#94a3b8;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:0.75px">Active Artifacts</div>';

      if (!hasUniversal && !hasArm64 && !hasArm32) {
        html += '<div style="padding:1rem;background:#0f172a;border:1px dashed #334155;border-radius:0.5rem;font-size:.825rem;color:#94a3b8;text-align:center"><i class="fa-solid fa-box-open" style="font-size:1.25rem;display:block;margin-bottom:0.4rem;color:#64748b"></i>No APK binary uploaded yet. Use the upload card on the right to publish a build.</div>';
      } else {
        if (hasUniversal) {
          var u = data.artifacts_detail.universal;
          html += this._renderArtifactItem('Universal APK (All devices)', u, 'universal');
        }
        if (hasArm64) {
          var a64 = data.artifacts_detail['arm64-v8a'];
          html += this._renderArtifactItem('ARM64-v8a (64-bit split)', a64, 'arm64-v8a');
        }
        if (hasArm32) {
          var a32 = data.artifacts_detail['armeabi-v7a'];
          html += this._renderArtifactItem('ARMeabi-v7a (32-bit legacy)', a32, 'armeabi-v7a');
        }
      }
      html += '</div>';

      // Quick test link
      if (data.download_available) {
        html += '<div style="margin-top:.75rem;display:flex;gap:.75rem;flex-wrap:wrap">' +
          '<a href="/api/v1/app/download" target="_blank" class="btn btn-outline btn-sm" style="flex:1;justify-content:center;background:#0f172a;border-color:#334155;color:#e2e8f0;padding:0.6rem 1rem"><i class="fa-solid fa-download" style="color:#38bdf8"></i> Test Direct APK Download</a>' +
          '<a href="/api/v1/app/config" target="_blank" class="btn btn-outline btn-sm" style="flex:1;justify-content:center;background:#0f172a;border-color:#334155;color:#e2e8f0;padding:0.6rem 1rem"><i class="fa-solid fa-code" style="color:#a78bfa"></i> View Config JSON</a>' +
        '</div>';
      }

      html += '</div>';
      container.innerHTML = html;
    },

    _renderArtifactItem: function (label, art, abi) {
      return '<div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;margin-bottom:.5rem;background:#0f172a;border-radius:.5rem;border:1px solid #334155;border-left:4px solid #38bdf8">' +
        '<div style="min-width:0;flex:1">' +
          '<div style="font-size:.85rem;font-weight:600;color:#f8fafc;display:flex;align-items:center;gap:.45rem">' +
            '<i class="fa-solid fa-cube" style="color:#38bdf8;font-size:.8rem"></i> ' + escapeHtml(label) +
            '<span style="font-size:.75rem;color:#94a3b8;font-weight:400">(' + escapeHtml(art.size_formatted) + ')</span>' +
          '</div>' +
          '<div style="font-size:.7rem;color:#cbd5e1;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.2rem" title="SHA-256: ' + escapeHtml(art.sha256) + '"><span style="color:#64748b">SHA-256:</span> ' + escapeHtml(art.sha256) + '</div>' +
        '</div>' +
        '<div style="display:flex;gap:.35rem;margin-left:.75rem">' +
          '<button type="button" class="btn btn-outline btn-sm" style="padding:.35rem .6rem;font-size:.75rem;color:#f87171;border-color:rgba(239,68,68,0.4);background:#1e293b" onclick="AppReleaseUI.deleteApk(\'' + escapeHtml(abi) + '\')" title="Delete artifact"><i class="fa-solid fa-trash"></i></button>' +
        '</div>' +
      '</div>';
    },

    populateForm: function (data) {
      var setVal = function (id, val) {
        var el = document.getElementById(id);
        if (el) el.value = val != null ? val : '';
      };
      var setChecked = function (id, checked) {
        var el = document.getElementById(id);
        if (el) el.checked = !!checked;
      };

      setVal('cfg-latest-version', data.latest_version);
      setVal('cfg-latest-build', data.latest_build);
      setVal('cfg-min-version', data.min_version);
      setVal('cfg-min-build', data.min_build);
      setChecked('cfg-force-update', data.force_update);
      setVal('cfg-release-notes', data.release_notes);
      setVal('cfg-banner-text', data.banner_text);
      setVal('cfg-banner-kind', data.banner_kind || 'info');
      setChecked('cfg-drains-enabled', data.background_drains_enabled !== false);
    },

    saveConfig: function (e) {
      if (e) e.preventDefault();
      var btn = document.getElementById('btn-save-cfg');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
      }

      var form = document.getElementById('form-release-config');
      var formData = new FormData(form);
      formData.append('action', 'save_config');

      fetch(API_URL, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        body: formData
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success') {
          showAlert('success', res.message || 'Release policy saved successfully!');
          if (res.data) {
            AppReleaseUI.renderStatus(res.data);
            AppReleaseUI.populateForm(res.data);
          }
        } else {
          showAlert('danger', res.message || 'Failed to save configuration.');
        }
      })
      .catch(function () {
        showAlert('danger', 'Network error saving configuration.');
      })
      .finally(function () {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Release Policy';
        }
      });
    },

    uploadApk: function (e) {
      if (e) e.preventDefault();
      var fileInput = document.getElementById('upload-apk-input');
      if (!fileInput || !fileInput.files || !fileInput.files[0]) {
        alert('Please select an APK file to upload.');
        return;
      }

      var file = fileInput.files[0];
      if (!file.name.toLowerCase().endsWith('.apk')) {
        alert('The selected file is not a valid APK file (.apk).');
        return;
      }

      var btn = document.getElementById('btn-upload-apk');
      var progressWrapper = document.getElementById('upload-progress-wrapper');
      var progressBar = document.getElementById('upload-progress-bar');
      var progressPct = document.getElementById('upload-progress-pct');
      var progressLabel = document.getElementById('upload-progress-label');

      if (btn) btn.disabled = true;
      if (progressWrapper) progressWrapper.style.display = 'block';
      if (progressBar) progressBar.style.width = '0%';
      if (progressPct) progressPct.textContent = '0%';
      if (progressLabel) progressLabel.textContent = 'Uploading ' + file.name + ' (' + (file.size / 1048576).toFixed(1) + ' MB)...';

      var form = document.getElementById('form-upload-apk');
      var formData = new FormData(form);
      formData.append('action', 'upload_apk');

      var xhr = new XMLHttpRequest();
      xhr.open('POST', API_URL, true);
      xhr.setRequestHeader('X-CSRF-TOKEN', getCsrfToken());

      xhr.upload.onprogress = function (pe) {
        if (pe.lengthComputable) {
          var pct = Math.round((pe.loaded / pe.total) * 100);
          if (progressBar) progressBar.style.width = pct + '%';
          if (progressPct) progressPct.textContent = pct + '%';
          if (pct === 100 && progressLabel) {
            progressLabel.textContent = 'Computing cryptographic SHA-256 hash and publishing...';
          }
        }
      };

      xhr.onload = function () {
        if (btn) btn.disabled = false;
        if (progressWrapper) progressWrapper.style.display = 'none';

        try {
          var res = JSON.parse(xhr.responseText);
          if (xhr.status === 200 && res.status === 'success') {
            showAlert('success', res.message || 'APK uploaded and published successfully!');
            fileInput.value = '';
            if (res.data) {
              AppReleaseUI.renderStatus(res.data);
              AppReleaseUI.populateForm(res.data);
            }
          } else {
            showAlert('danger', res.message || ('Upload failed with status ' + xhr.status));
          }
        } catch (err) {
          showAlert('danger', 'Server returned invalid response during upload.');
        }
      };

      xhr.onerror = function () {
        if (btn) btn.disabled = false;
        if (progressWrapper) progressWrapper.style.display = 'none';
        showAlert('danger', 'Network error during APK upload.');
      };

      xhr.send(formData);
    },

    deleteApk: function (abi) {
      if (!confirm('Are you sure you want to remove the ' + abi + ' APK artifact?')) {
        return;
      }

      var formData = new FormData();
      formData.append('action', 'delete_apk');
      formData.append('abi', abi);
      formData.append('csrf_token', getCsrfToken());

      fetch(API_URL, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        body: formData
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success') {
          showAlert('success', res.message || 'Artifact removed.');
          if (res.data) {
            AppReleaseUI.renderStatus(res.data);
            AppReleaseUI.populateForm(res.data);
          }
        } else {
          showAlert('danger', res.message || 'Failed to remove artifact.');
        }
      })
      .catch(function () {
        showAlert('danger', 'Network error removing artifact.');
      });
    }
  };

  window.AppReleaseUI = AppReleaseUI;
})(window, document);
