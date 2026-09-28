/**
 * Super Admin — Mobile App Release Management UI Module
 * Production-grade Resumable Chunked APK Upload Engine with
 * Real-time State Handling & cPanel Fault-Tolerance.
 */
(function (window, document) {
  'use strict';

  var API_URL = '/admin/api_app_release.php';
  var CHUNK_SIZE = 2 * 1024 * 1024; // 2MB per chunk (Optimal for cPanel / LiteSpeed / Nginx)
  var MAX_CHUNK_RETRIES = 4;

  // Upload State Machine
  var currentUpload = {
    state: 'idle', // 'idle' | 'selected' | 'uploading' | 'assembling' | 'success' | 'error'
    file: null,
    abi: 'universal',
    uploadId: null,
    totalChunks: 0,
    currentChunkIndex: 0,
    chunkRetries: 0,
    startTime: 0,
    uploadedBytesBeforeCurrentChunk: 0,
    lastSpeedUpdate: 0,
    lastLoadedBytes: 0,
    speedBps: 0,
    xhr: null,
    aborted: false
  };

  function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
  }

  function formatDuration(seconds) {
    if (!isFinite(seconds) || seconds <= 0) return 'calculating...';
    if (seconds < 60) return Math.ceil(seconds) + 's';
    var mins = Math.floor(seconds / 60);
    var secs = Math.ceil(seconds % 60);
    return mins + 'm ' + (secs < 10 ? '0' : '') + secs + 's';
  }

  function showAlert(type, message) {
    var box = document.getElementById('app-release-alert-box');
    if (!box) return;
    var icon = type === 'success' ? 'check-circle' : (type === 'warn' ? 'triangle-exclamation' : 'circle-exclamation');
    box.innerHTML = '<div class="alert alert-' + type + '" style="margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;border-radius:0.5rem;padding:0.85rem 1.25rem">' +
      '<i class="fa-solid fa-' + icon + '"></i> <span>' + escapeHtml(message) + '</span>' +
      '</div>';
    setTimeout(function () {
      if (box.innerHTML.indexOf(message) !== -1) {
        box.innerHTML = '';
      }
    }, 8000);
  }

  function getCsrfToken() {
    var input = document.querySelector('input[name="csrf_token"]');
    if (input && input.value) return input.value;
    if (window.SA_BOOT && window.SA_BOOT.csrf) return window.SA_BOOT.csrf;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    return '';
  }

  function generateUploadId() {
    var rand = Math.random().toString(36).substring(2, 12) + Math.random().toString(36).substring(2, 12);
    return 'apk_' + Date.now() + '_' + rand;
  }

  var AppReleaseUI = {
    init: function () {
      this.bindDropZone();
      this.refresh();
    },

    bindDropZone: function () {
      var dropZone = document.getElementById('upload-idle-state') || document.querySelector('.apk-drop-box');
      var fileInput = document.getElementById('upload-apk-input');

      if (dropZone) {
        ['dragenter', 'dragover'].forEach(function (eventName) {
          dropZone.addEventListener(eventName, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('drag-active');
          }, false);
        });

        ['dragleave', 'drop'].forEach(function (eventName) {
          dropZone.addEventListener(eventName, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('drag-active');
          }, false);
        });

        dropZone.addEventListener('drop', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var dt = e.dataTransfer;
          if (dt && dt.files && dt.files.length > 0) {
            if (fileInput) {
              fileInput.files = dt.files;
            }
            AppReleaseUI.onFileSelected(dt.files[0]);
          }
        }, false);
      }

      if (fileInput) {
        fileInput.addEventListener('change', function () {
          if (fileInput.files && fileInput.files[0]) {
            AppReleaseUI.onFileSelected(fileInput.files[0]);
          }
        }, false);
      }
    },

    onFileInputChange: function (input) {
      if (input && input.files && input.files[0]) {
        this.onFileSelected(input.files[0]);
      }
    },

    onAbiChange: function (val) {
      currentUpload.abi = val || 'universal';
      var abiEl = document.getElementById('stage-file-abi');
      if (abiEl) {
        abiEl.textContent = currentUpload.abi === 'universal' ? 'Universal APK' : currentUpload.abi;
      }
    },

    onFileSelected: function (file) {
      if (!file) return;

      if (!file.name.toLowerCase().endsWith('.apk')) {
        showAlert('danger', 'Invalid file type. Only Android APK binaries (.apk) are supported.');
        var fileInput = document.getElementById('upload-apk-input');
        if (fileInput) fileInput.value = '';
        return;
      }

      if (file.size > 262144000) { // 250 MB
        showAlert('danger', 'APK file exceeds the maximum 250MB limit.');
        return;
      }

      currentUpload.file = file;
      currentUpload.state = 'selected';
      currentUpload.totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
      currentUpload.uploadId = generateUploadId();

      var abiSelect = document.getElementById('upload-abi-select');
      if (abiSelect) {
        currentUpload.abi = abiSelect.value || 'universal';
      }

      this.renderUploadUI();
    },

    renderUploadUI: function () {
      var idleBox = document.getElementById('upload-idle-state');
      var stageBox = document.getElementById('upload-stage-state');
      var progressBox = document.getElementById('upload-progress-state');
      var assemblingBox = document.getElementById('upload-assembling-state');
      var successBox = document.getElementById('upload-success-state');
      var errorBox = document.getElementById('upload-error-state');

      var all = [idleBox, stageBox, progressBox, assemblingBox, successBox, errorBox];
      all.forEach(function (el) {
        if (el) el.style.display = 'none';
      });

      switch (currentUpload.state) {
        case 'idle':
          if (idleBox) idleBox.style.display = 'block';
          break;

        case 'selected':
          if (stageBox) {
            stageBox.style.display = 'block';
            var nameEl = document.getElementById('stage-file-name');
            var sizeEl = document.getElementById('stage-file-size');
            var chunksEl = document.getElementById('stage-file-chunks');
            var abiEl = document.getElementById('stage-file-abi');

            if (nameEl) nameEl.textContent = currentUpload.file ? currentUpload.file.name : '';
            if (sizeEl) sizeEl.textContent = currentUpload.file ? formatBytes(currentUpload.file.size) : '';
            if (chunksEl) chunksEl.textContent = currentUpload.totalChunks + ' chunks (' + formatBytes(CHUNK_SIZE) + ' / chunk)';
            if (abiEl) abiEl.textContent = currentUpload.abi === 'universal' ? 'Universal APK' : currentUpload.abi;
          }
          break;

        case 'uploading':
          if (progressBox) progressBox.style.display = 'block';
          break;

        case 'assembling':
          if (assemblingBox) {
            assemblingBox.style.display = 'block';
            var totalChunksText = document.getElementById('assemble-chunks-count');
            if (totalChunksText) totalChunksText.textContent = currentUpload.totalChunks;
          }
          break;

        case 'success':
          if (successBox) successBox.style.display = 'block';
          break;

        case 'error':
          if (errorBox) errorBox.style.display = 'block';
          break;
      }
    },

    startUpload: function () {
      if (!currentUpload.file) {
        var fileInput = document.getElementById('upload-apk-input');
        if (fileInput && fileInput.files && fileInput.files[0]) {
          currentUpload.file = fileInput.files[0];
          currentUpload.totalChunks = Math.max(1, Math.ceil(fileInput.files[0].size / CHUNK_SIZE));
          currentUpload.uploadId = generateUploadId();
        }
      }

      if (!currentUpload.file || currentUpload.state === 'uploading') return;

      var abiSelect = document.getElementById('upload-abi-select');
      if (abiSelect) {
        currentUpload.abi = abiSelect.value || 'universal';
      }

      currentUpload.state = 'uploading';
      currentUpload.aborted = false;
      currentUpload.currentChunkIndex = 0;
      currentUpload.chunkRetries = 0;
      currentUpload.startTime = Date.now();
      currentUpload.lastSpeedUpdate = Date.now();
      currentUpload.uploadedBytesBeforeCurrentChunk = 0;
      currentUpload.lastLoadedBytes = 0;
      currentUpload.speedBps = 0;

      this.renderUploadUI();
      this.uploadNextChunk();
    },

    uploadNextChunk: function () {
      if (currentUpload.aborted || currentUpload.state !== 'uploading') return;

      if (currentUpload.currentChunkIndex >= currentUpload.totalChunks) {
        // All chunks uploaded! Now trigger assembly on server
        this.assembleChunksOnServer();
        return;
      }

      var file = currentUpload.file;
      var chunkIndex = currentUpload.currentChunkIndex;
      var startByte = chunkIndex * CHUNK_SIZE;
      var endByte = Math.min(startByte + CHUNK_SIZE, file.size);
      var chunkBlob = file.slice(startByte, endByte);
      var chunkSize = endByte - startByte;

      var formData = new FormData();
      formData.append('action', 'upload_chunk');
      formData.append('upload_id', currentUpload.uploadId);
      formData.append('chunk_index', chunkIndex);
      formData.append('total_chunks', currentUpload.totalChunks);
      formData.append('chunk_size', chunkSize);
      formData.append('total_size', file.size);
      formData.append('file_name', file.name);
      formData.append('abi', currentUpload.abi);
      formData.append('csrf_token', getCsrfToken());
      formData.append('chunk_file', chunkBlob, 'chunk_' + chunkIndex + '.bin');

      var xhr = new XMLHttpRequest();
      currentUpload.xhr = xhr;
      xhr.open('POST', API_URL, true);
      xhr.withCredentials = true;
      xhr.setRequestHeader('X-CSRF-TOKEN', getCsrfToken());

      xhr.upload.onprogress = function (pe) {
        if (pe.lengthComputable && currentUpload.state === 'uploading') {
          var totalLoaded = currentUpload.uploadedBytesBeforeCurrentChunk + pe.loaded;
          var totalBytes = file.size;
          var pct = Math.min(99, Math.round((totalLoaded / totalBytes) * 100));

          // Calculate Speed & ETA
          var now = Date.now();
          var timeDiff = (now - currentUpload.lastSpeedUpdate) / 1000;
          if (timeDiff >= 0.5) {
            var bytesDiff = totalLoaded - currentUpload.lastLoadedBytes;
            currentUpload.speedBps = bytesDiff / timeDiff;
            currentUpload.lastSpeedUpdate = now;
            currentUpload.lastLoadedBytes = totalLoaded;
          }

          var remainingBytes = Math.max(0, totalBytes - totalLoaded);
          var etaSeconds = currentUpload.speedBps > 0 ? (remainingBytes / currentUpload.speedBps) : 0;

          AppReleaseUI.updateProgressDisplay(pct, totalLoaded, totalBytes, chunkIndex + 1, currentUpload.totalChunks, currentUpload.speedBps, etaSeconds);
        }
      };

      xhr.onload = function () {
        if (currentUpload.aborted) return;

        if (xhr.status === 200) {
          try {
            var res = JSON.parse(xhr.responseText);
            if (res.status === 'success') {
              // Successfully uploaded chunk!
              currentUpload.chunkRetries = 0;
              currentUpload.uploadedBytesBeforeCurrentChunk += chunkSize;
              currentUpload.currentChunkIndex++;
              AppReleaseUI.uploadNextChunk();
              return;
            } else if (res.message) {
              AppReleaseUI.handleChunkFailure(xhr.status, res.message);
              return;
            }
          } catch (e) {}
        }

        // Retry chunk on error
        AppReleaseUI.handleChunkFailure(xhr.status, xhr.responseText);
      };

      xhr.onerror = function () {
        if (currentUpload.aborted) return;
        AppReleaseUI.handleChunkFailure(0, 'Network connection interrupted');
      };

      xhr.ontimeout = function () {
        if (currentUpload.aborted) return;
        AppReleaseUI.handleChunkFailure(408, 'Chunk request timed out');
      };

      xhr.timeout = 60000; // 60s timeout per chunk
      xhr.send(formData);
    },

    handleChunkFailure: function (status, responseText) {
      if (currentUpload.aborted) return;

      currentUpload.chunkRetries++;
      if (currentUpload.chunkRetries <= MAX_CHUNK_RETRIES) {
        var backoffMs = Math.min(6000, 1000 * Math.pow(1.8, currentUpload.chunkRetries - 1));
        var label = document.getElementById('upload-status-subtext');
        if (label) {
          label.innerHTML = '<span style="color:#fbbf24"><i class="fa-solid fa-rotate fa-spin"></i> Network hiccup on chunk ' + (currentUpload.currentChunkIndex + 1) + '. Retrying (' + currentUpload.chunkRetries + '/' + MAX_CHUNK_RETRIES + ') in ' + Math.ceil(backoffMs / 1000) + 's...</span>';
        }
        setTimeout(function () {
          if (!currentUpload.aborted && currentUpload.state === 'uploading') {
            AppReleaseUI.uploadNextChunk();
          }
        }, backoffMs);
      } else {
        var errMsg = 'Upload stopped on chunk ' + (currentUpload.currentChunkIndex + 1) + ' of ' + currentUpload.totalChunks + '.';
        try {
          var res = JSON.parse(responseText);
          if (res.message) errMsg += ' ' + res.message;
        } catch (e) {
          if (status) errMsg += ' (HTTP ' + status + ')';
        }
        AppReleaseUI.showUploadError(errMsg);
      }
    },

    updateProgressDisplay: function (pct, loadedBytes, totalBytes, chunkNum, totalChunks, speedBps, etaSeconds) {
      var bar = document.getElementById('upload-progress-fill');
      var pctText = document.getElementById('upload-pct-display');
      var bytesText = document.getElementById('upload-bytes-display');
      var chunkText = document.getElementById('upload-chunk-display');
      var speedText = document.getElementById('upload-speed-display');
      var etaText = document.getElementById('upload-eta-display');

      if (bar) bar.style.width = pct + '%';
      if (pctText) pctText.textContent = pct + '%';
      if (bytesText) bytesText.textContent = formatBytes(loadedBytes) + ' / ' + formatBytes(totalBytes);
      if (chunkText) chunkText.textContent = 'Chunk ' + chunkNum + ' / ' + totalChunks;
      if (speedText) speedText.textContent = speedBps > 0 ? (formatBytes(speedBps) + '/s') : '--';
      if (etaText) etaText.textContent = formatDuration(etaSeconds);
    },

    assembleChunksOnServer: function () {
      currentUpload.state = 'assembling';
      this.renderUploadUI();

      var formData = new FormData();
      formData.append('action', 'assemble_chunks');
      formData.append('upload_id', currentUpload.uploadId);
      formData.append('total_chunks', currentUpload.totalChunks);
      formData.append('total_size', currentUpload.file.size);
      formData.append('file_name', currentUpload.file.name);
      formData.append('abi', currentUpload.abi);
      formData.append('csrf_token', getCsrfToken());

      fetch(API_URL, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        credentials: 'same-origin',
        body: formData
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success') {
          currentUpload.state = 'success';
          AppReleaseUI.renderUploadUI();
          AppReleaseUI.renderSuccessState(res.data, currentUpload.file.name, currentUpload.file.size);
          showAlert('success', res.message || 'APK published and cryptographic sidecar generated successfully!');
          if (res.data) {
            AppReleaseUI.renderStatus(res.data);
            AppReleaseUI.populateForm(res.data);
          }
        } else {
          AppReleaseUI.showUploadError(res.message || 'Server failed to assemble APK chunks.');
        }
      })
      .catch(function (err) {
        AppReleaseUI.showUploadError('Network error while assembling APK on server: ' + (err.message || 'Request failed'));
      });
    },

    renderSuccessState: function (data, filename, sizeBytes) {
      var nameEl = document.getElementById('success-apk-name');
      var sizeEl = document.getElementById('success-apk-size');
      var shaEl = document.getElementById('success-apk-sha');
      var verEl = document.getElementById('success-apk-version');

      if (nameEl) nameEl.textContent = filename || 'fkss.apk';
      if (sizeEl) sizeEl.textContent = formatBytes(sizeBytes);
      if (verEl && data) verEl.textContent = 'v' + data.latest_version + ' (Build ' + data.latest_build + ')';
      
      var sha = '';
      if (data && data.artifacts_detail && data.artifacts_detail[currentUpload.abi]) {
        sha = data.artifacts_detail[currentUpload.abi].sha256;
      }
      if (shaEl) shaEl.textContent = sha ? ('SHA-256: ' + sha) : '';
    },

    showUploadError: function (msg) {
      currentUpload.state = 'error';
      this.renderUploadUI();
      var errEl = document.getElementById('upload-error-message');
      if (errEl) errEl.textContent = msg;
      showAlert('danger', msg);
    },

    cancelUpload: function () {
      currentUpload.aborted = true;
      if (currentUpload.xhr) {
        try { currentUpload.xhr.abort(); } catch (e) {}
      }

      if (currentUpload.uploadId) {
        var formData = new FormData();
        formData.append('action', 'cancel_upload');
        formData.append('upload_id', currentUpload.uploadId);
        formData.append('csrf_token', getCsrfToken());

        fetch(API_URL, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': getCsrfToken() },
          credentials: 'same-origin',
          body: formData
        }).catch(function () {});
      }

      this.resetUploadState();
    },

    resetUploadState: function () {
      currentUpload.state = 'idle';
      currentUpload.file = null;
      currentUpload.uploadId = null;
      currentUpload.currentChunkIndex = 0;
      currentUpload.totalChunks = 0;
      currentUpload.xhr = null;
      currentUpload.aborted = false;

      var fileInput = document.getElementById('upload-apk-input');
      if (fileInput) fileInput.value = '';

      this.renderUploadUI();
    },

    refresh: function () {
      var container = document.getElementById('app-release-status-container');
      fetch(API_URL + '?action=get_release', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success' && res.data) {
          AppReleaseUI.renderStatus(res.data);
          AppReleaseUI.populateForm(res.data);
        } else {
          if (container && container.innerHTML.indexOf('Version') === -1) {
            container.innerHTML = '<div style="color:#f87171;padding:1.5rem;font-size:.875rem;background:#0f172a;border-radius:0.5rem;border:1px solid rgba(239,68,68,0.3)">' +
              '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(res.message || 'Failed to load release info.') + '</div>';
          }
        }
      })
      .catch(function (err) {
        if (container && container.innerHTML.indexOf('Version') === -1) {
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
        credentials: 'same-origin',
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
        credentials: 'same-origin',
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

  // Auto-init on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      AppReleaseUI.init();
    });
  } else {
    AppReleaseUI.init();
  }
})(window, document);
