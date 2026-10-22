/**
 * Super Admin — Mobile App Fleet Telemetry & Analytics UI Module
 */
(function (window, document) {
  'use strict';

  var API_URL = '/admin/api_telemetry.php';
  // Phase 3 paging: remembered per-page choice.
  function ppGet(key, sizes, def) {
    var v = 0;
    try { v = parseInt(localStorage.getItem(key), 10); } catch (e) { v = 0; }
    return sizes.indexOf(v) >= 0 ? v : def;
  }
  function ppSet(key, v) { try { localStorage.setItem(key, String(v)); } catch (e) { /* private mode */ } }
  function moreLabel(btn, shown, total) {
    if (!btn) return;
    var left = Math.max(0, total - shown);
    btn.disabled = left <= 0;
    btn.textContent = left > 0 ? 'Load more (' + left + ' left)' : (total > 0 ? '\u2713 All ' + total + ' shown' : 'Load more');
  }

  var state = {
    range: '7d',
    version: '',
    search: '',
    page: 1,          // last page loaded
    firstPage: 1,     // first page in view (Load more appends pages)
    shown: 0,
    total: 0,
    limit: ppGet('fkss_pp_telemetry', [15, 25, 50, 100], 15),
    totalPages: 1,
    loaded: false
  };

  var debounceTimer = null;

  function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Ethiopian calendar only (system-wide rule): technical timestamps are
  // shown as EC date + 24h time; raw ISO stays in the API/DB only.
  function fmtTs(v, dateOnly) {
    if (v == null || v === '' || v === 'still open') return v == null ? '' : String(v);
    var s = String(v).replace(' ', 'T');
    if (typeof WBWSCalendar === 'undefined' || typeof WBWSCalendar.formatDate !== 'function') return String(v);
    if (dateOnly || s.length <= 10) return WBWSCalendar.formatDate(s, 'medium');
    return typeof WBWSCalendar.formatDateTime === 'function' ? WBWSCalendar.formatDateTime(s) : WBWSCalendar.formatDate(s, 'medium');
  }

  function formatRelativeTime(minutesAgo) {
    if (minutesAgo == null || isNaN(minutesAgo)) return 'Just now';
    var mins = parseInt(minutesAgo, 10);
    if (mins < 1) return '<span style="color:#4ade80;font-weight:600">Active now</span>';
    if (mins < 60) return mins + ' min' + (mins === 1 ? '' : 's') + ' ago';
    var hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + ' hr' + (hrs === 1 ? '' : 's') + ' ago';
    var days = Math.floor(hrs / 24);
    if (days < 30) return days + ' day' + (days === 1 ? '' : 's') + ' ago';
    return Math.floor(days / 30) + ' mo ago';
  }

  function setTelemetryStatus(message) {
    var status = document.getElementById('telemetry-status');
    if (!status) return;
    status.textContent = message;
    status.hidden = !message;
  }

  function telemetryLoadError() {
    setTelemetryStatus('Telemetry data could not be loaded. Verify the API and confirm sql/051_app_telemetry.sql has been applied to the production database.');
  }

  var AppTelemetryUI = {
    init: function () {
      if (state.loaded) return;
      state.loaded = true;
      this.refresh();
      this.loadInstallations();
      if (window.SyncMonitorUI) window.SyncMonitorUI.refresh();
    },

    refresh: function () {
      var query = '?action=get_overview&range=' + encodeURIComponent(state.range) +
        (state.version ? '&version=' + encodeURIComponent(state.version) : '');

      fetch(API_URL + query, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success' && res.data) {
          AppTelemetryUI.renderOverview(res.data);
          setTelemetryStatus('');
        } else {
          telemetryLoadError();
        }
      })
      .catch(telemetryLoadError);
    },

    setRange: function (range, btn) {
      state.range = range;
      document.querySelectorAll('#section-app_telemetry .at-filter-btn').forEach(function (b) {
        b.classList.remove('active');
      });
      if (btn) btn.classList.add('active');
      this.refresh();
      this.loadInstallations();
    },

    setVersion: function (ver) {
      state.version = ver;
      state.page = 1;
      this.refresh();
      this.loadInstallations();
    },

    search: function (val) {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () {
        state.search = val;
        state.page = 1;
        AppTelemetryUI.loadInstallations();
      }, 350);
    },

    prevPage: function () {
      if (state.firstPage > 1) {
        state.page = state.firstPage - 1;
        this.loadInstallations();
      }
    },

    setPerPage: function (v) {
      var n = parseInt(v, 10);
      if ([15, 25, 50, 100].indexOf(n) < 0) return;
      state.limit = n; ppSet('fkss_pp_telemetry', n);
      state.page = 1;
      this.loadInstallations();
    },

    loadMore: function () {
      if (state.busy || state.page >= state.totalPages) return;
      state.page++;
      this.loadInstallations(true);
    },

    nextPage: function () {
      if (state.page < state.totalPages) {
        state.page++;
        this.loadInstallations();
      }
    },

    loadInstallations: function (append) {
      var tbody = document.getElementById('telemetry-table-body');
      var sizeSel = document.getElementById('telemetry-size');
      if (sizeSel) sizeSel.value = String(state.limit);
      state.busy = true;
      if (tbody && !append) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Loading device installations...</td></tr>';
      }

      var query = '?action=get_installations&page=' + state.page + '&limit=' + state.limit +
        '&range=' + encodeURIComponent(state.range) +
        (state.version ? '&version=' + encodeURIComponent(state.version) : '') +
        (state.search ? '&search=' + encodeURIComponent(state.search) : '');

      fetch(API_URL + query, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success' && res.data) {
          state.busy = false;
          state.totalPages = res.data.total_pages || 1;
          AppTelemetryUI.renderTable(res.data, append === true);
        } else {
          state.busy = false;
          if (append === true) { state.page--; telemetryLoadError(); return; }  // keep rows already shown
          if (tbody) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#f87171">Telemetry device data is unavailable.</td></tr>';
          }
          telemetryLoadError();
        }
      })
      .catch(function () {
        state.busy = false;
        if (append === true) { state.page--; telemetryLoadError(); return; }
        if (tbody) {
          tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#f87171">Failed to load device list.</td></tr>';
        }
        telemetryLoadError();
      });
    },

    renderOverview: function (data) {
      var sum = data.summary || {};
      var setTxt = function (id, val) {
        var el = document.getElementById(id);
        if (el) el.textContent = val;
      };

      setTxt('kpi-total-devices', (sum.total_installations || 0).toLocaleString());
      setTxt('kpi-active-7d', (sum.active_7d || 0).toLocaleString());
      setTxt('kpi-active-today', (sum.active_today || 0).toLocaleString());
      setTxt('kpi-downloads', (sum.total_downloads || 0).toLocaleString());
      setTxt('kpi-downloads-today', (sum.downloads_today || 0).toLocaleString());
      setTxt('kpi-adoption', (sum.adoption_percentage || 0) + '%');
      setTxt('kpi-latest-ver', 'v' + (sum.latest_version || '1.5.1'));
      setTxt('kpi-crashes', (sum.total_crashes || 0).toLocaleString());
      setTxt('kpi-launches', (sum.total_launches || 0).toLocaleString());

      var scope = sum.scope || {};
      var scopeText = 'Installation metrics use the ' + (scope.range || state.range || 'all') +
        ' last-seen cohort. Launch, crash, and legacy sync counters are lifetime counters for that cohort.';
      var scopeEl = document.getElementById('telemetry-scope');
      if (scopeEl) scopeEl.textContent = scopeText;

      // Version bars
      var verBox = document.getElementById('telemetry-version-bars');
      if (verBox && data.versions) {
        if (data.versions.length === 0) {
          verBox.innerHTML = '<p style="font-size:0.825rem;color:#94a3b8;font-style:italic;text-align:center;padding:1.5rem">No version telemetry recorded yet.</p>';
        } else {
          var html = '';
          data.versions.forEach(function (v) {
            html += '<div style="margin-bottom:1rem">' +
              '<div style="display:flex;justify-content:space-between;font-size:0.825rem;font-weight:600;color:#f8fafc">' +
                '<span>Version ' + escapeHtml(v.version) + ' <span style="font-size:0.75rem;color:#94a3b8;font-weight:400">(Build ' + escapeHtml(v.build) + ')</span></span>' +
                '<span style="color:#38bdf8">' + escapeHtml(v.count) + ' devices (' + escapeHtml(v.percentage) + '%)</span>' +
              '</div>' +
              '<div class="at-bar-track">' +
                '<div class="at-bar-fill" style="width:' + escapeHtml(v.percentage) + '%;background:linear-gradient(90deg,#38bdf8,#3b82f6)"></div>' +
              '</div>' +
            '</div>';
          });
          verBox.innerHTML = html;
        }
      }

      // OS bars
      var osBox = document.getElementById('telemetry-os-bars');
      if (osBox && data.os_versions) {
        if (data.os_versions.length === 0) {
          osBox.innerHTML = '<p style="font-size:0.825rem;color:#94a3b8;font-style:italic;text-align:center;padding:1.5rem">No OS telemetry recorded yet.</p>';
        } else {
          var osHtml = '';
          data.os_versions.forEach(function (os) {
            osHtml += '<div style="margin-bottom:0.85rem">' +
              '<div style="display:flex;justify-content:space-between;font-size:0.8rem;font-weight:500;color:#f8fafc">' +
                '<span>Android ' + escapeHtml(os.os_version) + ' <span style="font-size:0.7rem;color:#7c8aa5">(SDK ' + escapeHtml(os.sdk_int) + ')</span></span>' +
                '<span style="color:#4ade80">' + escapeHtml(os.count) + ' (' + escapeHtml(os.percentage) + '%)</span>' +
              '</div>' +
              '<div class="at-bar-track">' +
                '<div class="at-bar-fill" style="width:' + escapeHtml(os.percentage) + '%;background:linear-gradient(90deg,#10b981,#4ade80)"></div>' +
              '</div>' +
            '</div>';
          });
          osBox.innerHTML = osHtml;
        }
      }
    },

    renderTable: function (data, append) {
      var tbody = document.getElementById('telemetry-table-body');
      var pageInfo = document.getElementById('telemetry-page-info');
      var btnPrev = document.getElementById('btn-telemetry-prev');
      var btnNext = document.getElementById('btn-telemetry-next');

      if (!tbody) return;

      var items = data.items || [];
      if (!append) { state.firstPage = state.page; state.shown = 0; }
      state.shown += items.length;
      state.total = Number(data.total) || 0;
      if (items.length === 0 && !append) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8">No matching device installations found.</td></tr>';
      } else {
        var html = '';
        items.forEach(function (row) {
          var brand = row.device_brand || '';
          var model = row.device_model || 'Device';
          var deviceName = (brand ? brand + ' ' : '') + model;
          var ramText = row.ram_mb > 0 ? (row.ram_mb >= 1024 ? (row.ram_mb / 1024).toFixed(1) + ' GB' : row.ram_mb + ' MB') : 'Unknown';
          if (row.is_low_ram == 1) {
            ramText += ' <span style="color:#f59e0b;font-size:0.7rem">(Go)</span>';
          }

          html += '<tr>' +
            '<td>' +
              '<div style="font-weight:600;color:#f8fafc;display:flex;align-items:center;gap:0.4rem">' +
                '<i class="fa-solid fa-mobile" style="color:#38bdf8"></i> ' + escapeHtml(deviceName) +
              '</div>' +
              '<div style="font-size:0.675rem;color:#7c8aa5;font-family:monospace;margin-top:0.15rem" title="ID: ' + escapeHtml(row.installation_id) + '">' +
                escapeHtml(row.installation_id.substring(0, 16)) + '...' +
              '</div>' +
            '</td>' +
            '<td><span class="badge" style="background:rgba(16,185,129,0.1);color:#4ade80;border:1px solid rgba(16,185,129,0.25);padding:0.2rem 0.5rem;border-radius:4px;font-size:0.725rem">Android ' + escapeHtml(row.os_version || 'N/A') + '</span></td>' +
            '<td><span style="font-weight:600;color:#38bdf8">v' + escapeHtml(row.app_version) + '</span> <span style="font-size:0.7rem;color:#94a3b8">(' + escapeHtml(row.app_build) + ')</span></td>' +
            '<td><span style="font-family:monospace;font-size:0.75rem;color:#cbd5e1">' + escapeHtml(row.abi || 'universal') + '</span></td>' +
            '<td>' + ramText + '</td>' +
            '<td>' + escapeHtml(row.launch_count) + '</td>' +
            '<td><span style="color:#4ade80">' + escapeHtml(row.sync_success_count) + '</span> / <span style="color:' + (row.sync_fail_count > 0 ? '#f87171' : '#7c8aa5') + '">' + escapeHtml(row.sync_fail_count) + '</span></td>' +
            '<td>' + formatRelativeTime(row.minutes_ago) + '</td>' +
          '</tr>';
        });
        if (append) tbody.insertAdjacentHTML('beforeend', html); else tbody.innerHTML = html;
      }

      if (pageInfo) {
        var startRow = (state.firstPage - 1) * state.limit + 1;
        var end = Math.min(data.total, startRow + state.shown - 1);
        pageInfo.textContent = data.total > 0
          ? 'Showing ' + startRow + '–' + end + ' of ' + data.total + ' devices (Page ' + (state.firstPage === state.page ? state.page : state.firstPage + '–' + state.page) + ' of ' + state.totalPages + ')'
          : '0 devices found';
      }

      var start = (state.firstPage - 1) * state.limit + 1;
      if (btnPrev) btnPrev.disabled = state.firstPage <= 1;
      if (btnNext) btnNext.disabled = state.page >= state.totalPages;
      moreLabel(document.getElementById('btn-telemetry-more'), start - 1 + state.shown, state.total);
    }
  };

  var SyncMonitorUI = (function () {
    var monitorState = { page: 1, firstPage: 1, shown: 0, busy: false, limit: ppGet('fkss_pp_sync_attempts', [20, 50, 100], 20), pages: 1, query: '' };
    var timer = null;

    function value(id, text) {
      var el = document.getElementById(id);
      if (el) el.textContent = text;
    }

    function monitorError(message) {
      var el = document.getElementById('sync-monitor-status');
      if (!el) return;
      el.textContent = message;
      el.hidden = !message;
    }

    function selected(id) {
      var el = document.getElementById(id);
      return el ? el.value : '';
    }

    function get(path) {
      return fetch(API_URL + path, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function (response) {
        return response.json();
      });
    }

    function displayCount(raw) {
      return raw === null || raw === undefined ? '—' : Number(raw).toLocaleString();
    }

    function renderOverview(data) {
      var counts = data.counts || {};
      ['pending', 'retrying', 'failed', 'in_flight', 'stale', 'recent_successful'].forEach(function (key) {
        value('sync-kpi-' + key, displayCount(counts[key]));
      });
      value('sync-kpi-pending-sub', 'Not server-observable');
      value('sync-kpi-retrying-sub', 'Retry schedule not server-observable');
      value('sync-kpi-failed-sub', (counts.retryable_failures || 0) + ' retryable failures observed');
      value('sync-kpi-in_flight-sub', 'Server reservations currently open');
      value('sync-kpi-stale-sub', 'Threshold: ' + (data.stale_after_minutes || 15) + ' minutes');
      value('sync-kpi-recent_successful-sub', 'Completed; replayed: ' + (counts.replayed || 0));
      renderBuildBreakdown(data.by_app_build || []);
    }

    // Per-build failure rates: the earliest signal of a regressing build.
    // A build failing at >= 5% in the window is highlighted red.
    function renderBuildBreakdown(builds) {
      var el = document.getElementById('sync-build-breakdown');
      if (!el) return;
      if (!builds.length) {
        el.innerHTML = 'Failure rate by app build: no server-observed attempts in this range yet.';
        return;
      }
      var parts = builds.map(function (b) {
        var attempts = Number(b.attempts || 0);
        var failed = Number(b.failed || 0);
        var rate = attempts > 0 ? (failed / attempts) * 100 : 0;
        var label = b.app_build === null || b.app_build === undefined ? 'not observed' : 'build ' + escapeHtml(String(b.app_build));
        var color = attempts > 0 && rate >= 5 ? '#f87171' : '#cbd5e1';
        return '<span style="color:' + color + '">' + label + ' <strong>' + rate.toFixed(2) + '%</strong> (' + failed + '/' + attempts + ' failed)</span>';
      });
      el.innerHTML = '<span style="color:#7c8aa5">Failure rate by app build:</span> ' + parts.join(' · ');
    }

    function refresh() {
      var range = encodeURIComponent(selected('sync-monitor-range') || '7d');
      get('?action=get_sync_overview&range=' + range).then(function (res) {
        if (res.status !== 'success' || !res.data) throw new Error('unavailable');
        renderOverview(res.data);
        monitorError('');
      }).catch(function () {
        monitorError('Sync monitoring is unavailable. Apply sql/059_api_sync_attempts.sql and verify the read-only admin endpoint.');
      });
      loadAttempts(1);
    }

    function search(text) {
      clearTimeout(timer);
      timer = setTimeout(function () {
        monitorState.query = text || '';
        loadAttempts(1);
      }, 300);
    }

    function loadAttempts(page, append) {
      if (append !== true) append = false;
      monitorState.page = page || monitorState.page;
      var sizeSel = document.getElementById('sync-monitor-size');
      if (sizeSel) sizeSel.value = String(monitorState.limit);
      monitorState.busy = true;
      var query = '?action=get_sync_attempts&page=' + monitorState.page + '&limit=' + monitorState.limit +
        '&range=' + encodeURIComponent(selected('sync-monitor-range') || '30d') +
        '&status=' + encodeURIComponent(selected('sync-monitor-status-filter')) +
        '&domain=' + encodeURIComponent(selected('sync-monitor-domain-filter')) +
        '&source=' + encodeURIComponent(selected('sync-monitor-source-filter')) +
        '&search=' + encodeURIComponent(monitorState.query || '');
      var body = document.getElementById('sync-monitor-table-body');
      if (body && !append) body.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:1.5rem;color:#94a3b8">Loading server-observed attempts…</td></tr>';
      get(query).then(function (res) {
        monitorState.busy = false;
        if (res.status !== 'success' || !res.data) throw new Error('unavailable');
        monitorState.pages = (res.data.pagination || {}).pages || 1;
        renderAttempts(res.data, append);
        monitorError('');
      }).catch(function () {
        monitorState.busy = false;
        if (append) { monitorState.page--; monitorError('Could not load more attempts. Try again.'); return; }
        if (body) body.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:1.5rem;color:#f87171">Server-observed attempt data is unavailable.</td></tr>';
      });
    }

    function renderAttempts(data, append) {
      var body = document.getElementById('sync-monitor-table-body');
      var items = data.items || [];
      if (!body) return;
      if (!append) { monitorState.firstPage = monitorState.page; monitorState.shown = 0; }
      monitorState.shown += items.length;
      if (!items.length && !append) {
        body.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:1.5rem;color:#94a3b8">No server-observed attempts match these filters.</td></tr>';
      } else {
        var html = '';
        items.forEach(function (row) {
          var statusColor = row.status === 'completed' || row.status === 'replayed' ? '#4ade80' : (row.status === 'in_flight' ? '#38bdf8' : (row.status === 'stale' ? '#f59e0b' : '#f87171'));
          var attempt = row.attempt_number ? '#' + escapeHtml(row.attempt_number) : 'not observed';
          if (row.attempt_uid) attempt += '<div style="font-size:.65rem;color:#7c8aa5;font-family:monospace">' + escapeHtml(String(row.attempt_uid).slice(0, 12)) + '…</div>';
          var outcome = row.http_status ? escapeHtml(row.http_status) : '—';
          if (row.error_code) outcome += '<div style="font-size:.65rem;color:#fca5a5">' + escapeHtml(row.error_code) + '</div>';
          var app = row.app_version ? escapeHtml(row.app_version) : 'not observed';
          if (row.app_version) app += '<div style="font-size:.65rem;color:#94a3b8">build ' + escapeHtml(String(row.app_build || '?')) + '</div>';
          html += '<tr>' +
            '<td><span style="color:' + statusColor + ';font-weight:700">' + escapeHtml(row.status) + '</span></td>' +
            '<td style="white-space:nowrap;font-size:.72rem">' + escapeHtml(fmtTs(row.started_at)) + '</td>' +
            '<td><div style="font-weight:600;color:#f8fafc">' + escapeHtml(row.domain) + '</div><div style="font-size:.68rem;color:#94a3b8;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escapeHtml(row.operation) + '">' + escapeHtml(row.operation) + '</div></td>' +
            '<td>' + escapeHtml(row.user_id) + '</td>' +
            '<td>' + attempt + '</td>' +
            '<td>' + escapeHtml(row.execution_source || 'not observed') + '</td>' +
            '<td>' + app + '</td>' +
            '<td>' + outcome + '<div style="font-size:.65rem;color:#94a3b8">' + escapeHtml(row.retry_decision || '') + '</div></td>' +
            '<td><button type="button" class="btn btn-outline btn-sm" onclick="SyncMonitorUI.detail(' + Number(row.id) + ')">View</button></td>' +
            '</tr>';
        });
        if (append) body.insertAdjacentHTML('beforeend', html); else body.innerHTML = html;
      }
      var page = data.pagination || {};
      var total = Number(page.total) || 0;
      var first = (monitorState.firstPage - 1) * monitorState.limit + 1;
      value('sync-monitor-page-info', total ? 'Showing ' + first + '–' + Math.min(total, first + monitorState.shown - 1) + ' of ' + total : '0 attempts found');
      var previous = document.getElementById('sync-monitor-prev');
      var next = document.getElementById('sync-monitor-next');
      if (previous) previous.disabled = monitorState.firstPage <= 1;
      if (next) next.disabled = monitorState.page >= monitorState.pages;
      moreLabel(document.getElementById('sync-monitor-more'), first - 1 + monitorState.shown, total);
    }

    function page(delta) {
      var next = delta < 0 ? monitorState.firstPage - 1 : monitorState.page + 1;
      if (next >= 1 && next <= monitorState.pages) loadAttempts(next);
    }

    function more() {
      if (monitorState.busy || monitorState.page >= monitorState.pages) return;
      loadAttempts(monitorState.page + 1, true);
    }

    function setPerPage(v) {
      var n = parseInt(v, 10);
      if ([20, 50, 100].indexOf(n) < 0) return;
      monitorState.limit = n; ppSet('fkss_pp_sync_attempts', n);
      loadAttempts(1);
    }

    function detail(id) {
      get('?action=get_sync_attempt&id=' + encodeURIComponent(id)).then(function (res) {
        if (res.status !== 'success' || !res.data) throw new Error('missing');
        var row = res.data;
        var panel = document.getElementById('sync-monitor-detail');
        if (!panel) return;
        var fields = [
          ['Status', row.status], ['Domain', row.domain], ['Operation', row.operation],
          ['Request ID', row.request_id], ['Client operation ID', row.client_op_id || 'not observed'],
          ['Attempt ID', row.attempt_uid || 'not observed'], ['Attempt number', row.attempt_number || 'not observed'],
          ['Execution source', row.execution_source || 'not observed'], ['Authenticated user ID', row.user_id],
          ['App version', row.app_version || 'not observed'], ['App build', row.app_build || 'not observed'],
          ['Installation', row.installation_id ? escapeHtml(String(row.installation_id).slice(0, 13)) + '…' : 'not observed'],
          ['Entity reference', row.entity_ref || 'not observed'], ['HTTP status', row.http_status || 'not observed'],
          ['Idempotency state', row.idempotency_state], ['Retry decision', row.retry_decision],
          ['Error category', row.error_category || 'none'], ['Safe error code', row.error_code || 'none'],
          ['Started', fmtTs(row.started_at)], ['Completed', row.completed_at ? fmtTs(row.completed_at) : 'still open'], ['Duration (ms)', row.duration_ms]
        ];
        panel.innerHTML = '<div style="display:flex;justify-content:space-between;gap:1rem;margin-bottom:.6rem"><strong style="color:#f8fafc">Safe attempt detail</strong><button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById(\'sync-monitor-detail\').hidden=true">Close</button></div>' +
          '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.45rem .9rem">' +
          fields.map(function (pair) { return '<div><span style="color:#7c8aa5">' + escapeHtml(pair[0]) + '</span><br><span style="color:#e2e8f0;word-break:break-word">' + escapeHtml(pair[1]) + '</span></div>'; }).join('') + '</div>';
        panel.hidden = false;
      }).catch(function () { monitorError('The selected attempt could not be loaded.'); });
    }

    return { refresh: refresh, loadAttempts: loadAttempts, search: search, page: page, detail: detail, more: more, setPerPage: setPerPage };
  }());

  window.SyncMonitorUI = SyncMonitorUI;
  window.AppTelemetryUI = AppTelemetryUI;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      var section = document.getElementById('section-app_telemetry');
      if (section && !section.hasAttribute('hidden')) {
        AppTelemetryUI.init();
      }
    });
  } else {
    var section = document.getElementById('section-app_telemetry');
    if (section && !section.hasAttribute('hidden')) {
      AppTelemetryUI.init();
    }
  }
})(window, document);
