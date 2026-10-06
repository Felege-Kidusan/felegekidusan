/**
 * Super Admin — Failure Intelligence UI Module
 *
 * Issues across all three failure channels (sync / server errors / crashes),
 * remediation editing, and recorded failure reports. All data is fetched
 * from admin/api_failure_intelligence.php; nothing is server-rendered, so
 * opening the super-admin dashboard costs zero failure queries until this
 * section is opened.
 */
(function (window, document) {
  'use strict';

  var API_URL = '/admin/api_failure_intelligence.php';

  var state = {
    page: 1,
    pages: 1,
    reportsPage: 1,
    reportsPages: 1,
    loaded: false
  };

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function value(id, text) {
    var el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function getCsrfToken() {
    var input = document.querySelector('input[name="csrf_token"]');
    if (input && input.value) return input.value;
    if (window.SA_BOOT && window.SA_BOOT.csrf) return window.SA_BOOT.csrf;
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function get(path) {
    return fetch(API_URL + path, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (response) { return response.json(); });
  }

  function post(action, fields) {
    var formData = new FormData();
    formData.append('csrf_token', getCsrfToken());
    Object.keys(fields || {}).forEach(function (key) {
      formData.append(key, fields[key]);
    });
    return fetch(API_URL + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (response) { return response.json(); });
  }

  function statusError(message) {
    var el = document.getElementById('fi-status');
    if (!el) return;
    if (!message) { el.hidden = true; el.textContent = ''; return; }
    el.hidden = false;
    el.textContent = message;
  }

  var SOURCE_LABELS = { sync: 'Sync', server_error: 'Server', crash: 'Crash' };
  var SOURCE_COLORS = { sync: '#38bdf8', server_error: '#f59e0b', crash: '#ec4899' };
  var STATUS_COLORS = { open: '#f87171', acknowledged: '#f59e0b', resolved: '#4ade80' };

  function renderOverview(data) {
    if (!data) return;
    var sync = data.sync || {};
    value('fi-kpi-failure-rate', sync.failure_rate_24h !== undefined ? sync.failure_rate_24h + '%' : '—');
    value('fi-kpi-failure-rate-sub', (sync.failures_24h || 0) + ' failed of ' + (sync.attempts_24h || 0) + ' attempts');
    value('fi-kpi-p95', sync.p95_duration_ms_24h !== null && sync.p95_duration_ms_24h !== undefined
      ? Number(sync.p95_duration_ms_24h).toLocaleString() + ' ms' : 'not available');
    value('fi-kpi-attempts-min', String(sync.attempts_per_min_24h || 0));
    var crashFree = data.crash_free || {};
    value('fi-kpi-crash-free', crashFree.crash_free_percent_24h !== null && crashFree.crash_free_percent_24h !== undefined
      ? crashFree.crash_free_percent_24h + '%' : '—');
    value('fi-kpi-crash-free-sub', (crashFree.crashed_installs_24h || 0) + ' of ' + (crashFree.active_installs_24h || 0) + ' active installs crashed');
    value('fi-kpi-open-issues', String((data.issues && data.issues.open) || 0));
    value('fi-kpi-server-errors', String(data.server_errors_24h || 0));
  }

  function renderIssues(data) {
    var body = document.getElementById('fi-issues-body');
    if (!body) return;
    var items = (data && data.items) || [];
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#94a3b8">No issues match these filters.</td></tr>';
    } else {
      var html = '';
      items.forEach(function (issue) {
        var source = issue.source || 'sync';
        var status = issue.status || 'open';
        html += '<tr>' +
          '<td><div style="font-weight:600;color:#f8fafc;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escapeHtml(issue.title) + '">' + escapeHtml(issue.title) + '</div>' +
          '<div style="font-size:.65rem;color:#7c8aa5">' + escapeHtml(issue.category || 'uncategorised') + (issue.regression_count > 0 ? ' · <span style="color:#f87171;font-weight:700">regressed ' + issue.regression_count + '×</span>' : '') + '</div></td>' +
          '<td><span style="color:' + (SOURCE_COLORS[source] || '#94a3b8') + ';font-weight:700;font-size:.72rem">' + (SOURCE_LABELS[source] || source) + '</span></td>' +
          '<td>' + Number(issue.affected || 0).toLocaleString() + '</td>' +
          '<td>' + Number(issue.occurrences || 0).toLocaleString() + '</td>' +
          '<td style="color:#94a3b8">' + Number(issue.total_occurrences || 0).toLocaleString() + '</td>' +
          '<td style="font-size:.68rem;color:#94a3b8;white-space:nowrap">' + escapeHtml(String(issue.first_seen_at).slice(0, 10)) + ' → ' + escapeHtml(String(issue.last_seen_at).slice(0, 10)) + '</td>' +
          '<td><span style="color:' + (STATUS_COLORS[status] || '#94a3b8') + ';font-weight:700;font-size:.72rem">' + status + '</span></td>' +
          '<td><button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.detail(' + Number(issue.id) + ')">View</button></td>' +
          '</tr>';
      });
      body.innerHTML = html;
    }
    var pagination = (data && data.pagination) || {};
    state.pages = pagination.pages || 1;
    value('fi-page-info', pagination.total ? 'Showing ' + (((pagination.page - 1) * pagination.limit) + 1) + '–' + Math.min(pagination.total, pagination.page * pagination.limit) + ' of ' + pagination.total + ' issues (' + (data.window || '7d') + ' window)' : '0 issues found');
  }

  function renderTimeline(timeline) {
    if (!timeline || !timeline.length) return '<div style="color:#94a3b8;font-size:.75rem">No occurrences in the timeline window.</div>';
    var max = 0;
    timeline.forEach(function (d) { max = Math.max(max, Number(d.c)); });
    var bars = timeline.map(function (d) {
      var height = max > 0 ? Math.max(4, Math.round((Number(d.c) / max) * 48)) : 4;
      return '<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px" title="' + escapeHtml(d.day) + ': ' + d.c + '">' +
        '<span style="font-size:.58rem;color:#94a3b8">' + d.c + '</span>' +
        '<div style="width:70%;height:' + height + 'px;background:linear-gradient(180deg,#f87171,#7f1d1d);border-radius:2px 2px 0 0"></div>' +
        '<span style="font-size:.55rem;color:#7c8aa5">' + escapeHtml(String(d.day).slice(5)) + '</span>' +
        '</div>';
    });
    return '<div style="display:flex;align-items:flex-end;gap:2px;height:76px">' + bars.join('') + '</div>';
  }

  function distributionRows(rows, label) {
    if (!rows || !rows.length) return '';
    var parts = rows.map(function (r) {
      return escapeHtml(String(r[label] === null || r[label] === undefined || r[label] === '' ? 'not observed' : r[label])) + ': <strong>' + r.c + '</strong>';
    });
    return '<div style="font-size:.75rem;color:#cbd5e1;margin-bottom:.5rem">' + parts.join(' · ') + '</div>';
  }

  function detail(issue) {
    get('?action=get_failure_issue&id=' + encodeURIComponent(issue)).then(function (res) {
      if (res.status !== 'success' || !res.data) throw new Error('missing');
      var d = res.data;
      var panel = document.getElementById('fi-issue-detail');
      if (!panel) return;
      var ev = d.evidence || {};

      var evidenceHtml = '';
      if (ev.request_ids && ev.request_ids.length) {
        evidenceHtml += '<h4 style="margin:.9rem 0 .4rem;color:#f8fafc;font-size:.85rem">Correlated request ids (sync monitor)</h4><div style="font-family:monospace;font-size:.7rem;color:#94a3b8;line-height:1.7">' +
          ev.request_ids.map(function (r) {
            return escapeHtml(r.request_id) + ' @ ' + escapeHtml(r.started_at) + ' (HTTP ' + (r.http_status || '?') + ')';
          }).join('<br>') + '</div>';
      }
      if (ev.by_app_build && ev.by_app_build.length) {
        evidenceHtml += '<h4 style="margin:.9rem 0 .3rem;color:#f8fafc;font-size:.85rem">By app build (7d)</h4>' + distributionRows(ev.by_app_build, 'app_build');
      }
      if (ev.by_device_model && ev.by_device_model.length) {
        evidenceHtml += '<h4 style="margin:.9rem 0 .3rem;color:#f8fafc;font-size:.85rem">By device model</h4>' + distributionRows(ev.by_device_model, 'device_model');
      }
      if (ev.crash_signature) {
        evidenceHtml += '<h4 style="margin:.9rem 0 .3rem;color:#f8fafc;font-size:.85rem">Crash signature</h4>' +
          '<div style="font-family:monospace;font-size:.75rem;color:#e2e8f0">' + escapeHtml(ev.crash_signature.signature_class) +
          (ev.crash_signature.signature_frames ? '<br>' + escapeHtml(ev.crash_signature.signature_frames) : '') + '</div>' +
          '<div style="font-size:.7rem;color:#94a3b8;margin-top:.25rem">Lifetime ' + ev.crash_signature.total_events + ' events · first ' + escapeHtml(ev.crash_signature.first_seen_at) + ' · last ' + escapeHtml(ev.crash_signature.last_seen_at) + '</div>';
      }
      if (ev.samples && ev.samples.length) {
        evidenceHtml += '<h4 style="margin:.9rem 0 .3rem;color:#f8fafc;font-size:.85rem">Latest server samples (redacted at capture)</h4>' +
          ev.samples.map(function (s) {
            return '<div style="font-size:.72rem;color:#cbd5e1;margin-bottom:.35rem;padding:.4rem .5rem;border-left:2px solid #f59e0b;background:rgba(30,41,59,.5);border-radius:0 4px 4px 0">[' + escapeHtml(s.severity) + '] ' + escapeHtml(s.message) + '<br><span style="color:#7c8aa5">' + escapeHtml(s.file_path) + ':' + s.line_number + ' · ' + escapeHtml(s.created_at) + '</span></div>';
          }).join('');
      }

      var rem = d.remediation || {};
      panel.innerHTML =
        '<div style="display:flex;justify-content:space-between;gap:1rem;margin-bottom:.7rem;flex-wrap:wrap;align-items:flex-start">' +
        '<div><strong style="color:#f8fafc;font-size:1rem">' + escapeHtml(d.title) + '</strong>' +
        '<div style="font-size:.72rem;color:#94a3b8;margin-top:.2rem">' + (SOURCE_LABELS[d.source] || d.source) + ' · ' + escapeHtml(d.category || 'uncategorised') + ' · key ' + escapeHtml(String(d.issue_key).slice(0, 12)) + '… · status <span style="color:' + (STATUS_COLORS[d.status] || '#94a3b8') + ';font-weight:700">' + d.status + '</span>' + (d.regression_count > 0 ? ' · regressed ' + d.regression_count + '×' : '') + '</div></div>' +
        '<div style="display:flex;gap:.4rem;flex-wrap:wrap">' +
        (d.status === 'open' ? '<button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.setStatus(' + d.id + ', \'acknowledged\')">Acknowledge</button>' : '') +
        (d.status !== 'resolved' ? '<button type="button" class="btn btn-sm" style="background:#166534;border-color:#166534" onclick="FailureIntelligenceUI.setStatus(' + d.id + ', \'resolved\')">Resolve</button>' : '<button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.setStatus(' + d.id + ', \'open\')">Reopen</button>') +
        '<button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.generateReport(' + d.id + ')"><i class="fa-solid fa-file-lines"></i> Generate report</button>' +
        '<button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById(\'fi-issue-detail\').hidden=true">Close</button>' +
        '</div></div>' +
        '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.5rem .9rem;margin-bottom:.9rem;font-size:.78rem">' +
        '<div><span style="color:#7c8aa5">24h</span><br><span style="color:#e2e8f0">' + d.occurrences_24h + ' occurrences · ' + d.affected_24h + ' affected</span></div>' +
        '<div><span style="color:#7c8aa5">7d</span><br><span style="color:#e2e8f0">' + d.occurrences_7d + ' occurrences · ' + d.affected_7d + ' affected</span></div>' +
        '<div><span style="color:#7c8aa5">Lifetime</span><br><span style="color:#e2e8f0">' + d.total_occurrences + ' since ' + escapeHtml(d.first_seen_at) + '</span></div>' +
        (d.resolved_at ? '<div><span style="color:#7c8aa5">Resolved</span><br><span style="color:#e2e8f0">' + escapeHtml(d.resolved_at) + ' by ' + escapeHtml(d.resolved_by || '?') + '</span></div>' : '') +
        '</div>' +
        '<h4 style="margin:.4rem 0 .4rem;color:#f8fafc;font-size:.85rem">When (last 14 days)</h4>' + renderTimeline(d.timeline) +
        evidenceHtml +
        '<h4 style="margin:1rem 0 .4rem;color:#f8fafc;font-size:.85rem">How to fix it <span style="color:#7c8aa5;font-weight:400;font-size:.7rem">(' + escapeHtml(rem.source_of_truth === 'admin_override' ? 'admin override' : (rem.source_of_truth === 'catalog_seed' ? 'seeded catalog' : 'not catalogued yet')) + ')</span></h4>' +
        '<div style="display:grid;gap:.5rem">' +
        (rem.cause ? '<div style="font-size:.78rem;color:#cbd5e1"><strong style="color:#f8fafc">Cause:</strong> ' + escapeHtml(rem.cause) + '</div>' : '') +
        (rem.steps ? '<div style="font-size:.78rem;color:#cbd5e1;white-space:pre-line"><strong style="color:#f8fafc">Fix steps:</strong>' + "\n" + escapeHtml(rem.steps) + '</div>' : '<div style="font-size:.75rem;color:#f87171">No cause or fix steps recorded — add them below; they will be attached to every future report for this issue.</div>') +
        (rem.link ? '<div style="font-size:.75rem;color:#94a3b8">Documentation: ' + escapeHtml(rem.link) + '</div>' : '') +
        '</div>' +
        '<details style="margin-top:.9rem"><summary style="cursor:pointer;color:#94a3b8;font-size:.75rem">Edit remediation / resolution note</summary>' +
        '<div style="display:grid;gap:.5rem;margin-top:.6rem">' +
        '<textarea id="fi-rem-cause" rows="2" style="width:100%;background:rgba(15,23,42,.8);border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:.78rem;padding:.5rem" placeholder="Cause — why this failure happens">' + escapeHtml(rem.cause || '') + '</textarea>' +
        '<textarea id="fi-rem-steps" rows="4" style="width:100%;background:rgba(15,23,42,.8);border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:.78rem;padding:.5rem" placeholder="Fix steps — how to fix it (one step per line)">' + escapeHtml(rem.steps || '') + '</textarea>' +
        '<input id="fi-rem-link" style="width:100%;background:rgba(15,23,42,.8);border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:.78rem;padding:.45rem" placeholder="Documentation link (optional)" value="' + escapeHtml(rem.link || '') + '">' +
        '<input id="fi-rem-note" style="width:100%;background:rgba(15,23,42,.8);border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:.78rem;padding:.45rem" placeholder="Resolution note (recorded when you press Resolve)" value="' + escapeHtml(d.resolved_note || '') + '">' +
        '<div><button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.saveRemediation(' + d.id + ', ' + (d.status === 'resolved' ? 'true' : 'false') + ')">Save remediation</button></div>' +
        '</div></details>';
      panel.hidden = false;
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }).catch(function () {
      statusError('The selected issue could not be loaded.');
    });
  }

  function setStatus(id, status) {
    var note = '';
    var noteEl = document.getElementById('fi-rem-note');
    if (status === 'resolved' && noteEl) note = noteEl.value;
    post('update_failure_issue', { id: id, status: status, resolved_note: note }).then(function (res) {
      if (res.status !== 'success') throw new Error('failed');
      detail(id);
      loadIssues(state.page);
      statusError('');
    }).catch(function () {
      statusError('The status update failed.');
    });
  }

  function saveRemediation(id) {
    post('update_failure_issue', {
      id: id,
      remediation_cause: (document.getElementById('fi-rem-cause') || {}).value || '',
      remediation_steps: (document.getElementById('fi-rem-steps') || {}).value || '',
      remediation_link: (document.getElementById('fi-rem-link') || {}).value || ''
    }).then(function (res) {
      if (res.status !== 'success') throw new Error('failed');
      detail(id);
      statusError('');
    }).catch(function () {
      statusError('Saving the remediation failed.');
    });
  }

  function generateReport(id) {
    post('generate_failure_report', { issue_id: id }).then(function (res) {
      if (res.status !== 'success' || !res.data) throw new Error('failed');
      viewReport(res.data.id);
      loadReports(state.reportsPage);
    }).catch(function () {
      statusError('The report could not be generated.');
    });
  }

  function renderReports(data) {
    var body = document.getElementById('fi-reports-body');
    if (!body) return;
    var items = (data && data.items) || [];
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:#94a3b8">No failure reports recorded yet.</td></tr>';
    } else {
      var sevColors = { low: '#94a3b8', medium: '#f59e0b', high: '#f87171', critical: '#ec4899' };
      body.innerHTML = items.map(function (r) {
        return '<tr>' +
          '<td><span style="color:' + (sevColors[r.severity] || '#94a3b8') + ';font-weight:700;font-size:.72rem">' + escapeHtml(r.severity) + '</span></td>' +
          '<td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escapeHtml(r.issue_title) + '">' + escapeHtml(r.issue_title) + '</td>' +
          '<td style="font-size:.68rem;color:#94a3b8;white-space:nowrap">' + escapeHtml(String(r.window_start).slice(0, 10)) + ' → ' + escapeHtml(String(r.window_end).slice(0, 10)) + '</td>' +
          '<td style="font-size:.72rem;color:#94a3b8">' + escapeHtml(r.trigger) + '</td>' +
          '<td style="font-size:.68rem;color:#94a3b8;white-space:nowrap">' + escapeHtml(r.created_at) + '</td>' +
          '<td><button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.viewReport(' + Number(r.id) + ')">View</button></td>' +
          '</tr>';
      }).join('');
    }
    var pagination = (data && data.pagination) || {};
    state.reportsPages = pagination.pages || 1;
  }

  function viewReport(id) {
    get('?action=get_failure_report&id=' + encodeURIComponent(id)).then(function (res) {
      if (res.status !== 'success' || !res.data) throw new Error('missing');
      var r = res.data;
      var panel = document.getElementById('fi-report-view');
      if (!panel) return;
      var body = escapeHtml(r.report_markdown)
        .replace(/^### (.*)$/gm, '<h4 style="margin:.8rem 0 .3rem;color:#f8fafc">$1</h4>')
        .replace(/^## (.*)$/gm, '<h3 style="margin:1rem 0 .4rem;color:#f8fafc">$1</h3>')
        .replace(/^# (.*)$/gm, '<h2 style="margin:.2rem 0 .5rem;color:#f8fafc">$1</h2>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code style="background:rgba(15,23,42,.9);padding:.1rem .3rem;border-radius:3px;font-size:.72rem">$1</code>')
        .replace(/^- (.*)$/gm, '<li style="margin-left:1.2rem">$1</li>')
        .replace(/^---$/gm, '<hr style="border-color:#334155">')
        .replace(/\n/g, '<br>');
      panel.innerHTML = '<div style="display:flex;justify-content:space-between;gap:1rem;margin-bottom:.7rem;align-items:flex-start">' +
        '<div><strong style="color:#f8fafc">Failure Report #' + r.id + '</strong>' +
        '<div style="font-size:.72rem;color:#94a3b8">' + escapeHtml(r.issue_title) + ' · severity ' + escapeHtml(r.severity) + ' · ' + escapeHtml(r.trigger) + ' · generated by ' + escapeHtml(r.generated_by) + ' at ' + escapeHtml(r.created_at) + '</div></div>' +
        '<div style="display:flex;gap:.4rem"><button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.detail(' + Number(r.issue_id) + ')">Open issue</button>' +
        '<button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById(\'fi-report-view\').hidden=true">Close</button></div></div>' +
        '<div style="font-size:.8rem;line-height:1.6;color:#cbd5e1">' + body + '</div>';
      panel.hidden = false;
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }).catch(function () {
      statusError('The report could not be loaded.');
    });
  }

  function loadAlerts() {
    get('?action=get_failure_alerts&limit=15').then(function (res) {
      if (res.status !== 'success' || !res.data) return;
      var body = document.getElementById('fi-alerts-body');
      if (!body) return;
      var items = res.data.items || [];
      if (!items.length) {
        body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:1rem;color:#94a3b8">No alerts have fired yet.</td></tr>';
        return;
      }
      var sevColors = { low: '#94a3b8', medium: '#f59e0b', high: '#f87171', critical: '#ec4899' };
      var kindLabels = { crash_new: 'New crash', velocity: 'Build velocity', sync_budget: 'Error budget' };
      body.innerHTML = items.map(function (a) {
        return '<tr>' +
          '<td><span style="color:' + (sevColors[a.severity] || '#94a3b8') + ';font-weight:700;font-size:.72rem">' + escapeHtml(a.severity) + '</span></td>' +
          '<td style="font-size:.72rem;color:#94a3b8">' + escapeHtml(kindLabels[a.kind] || a.kind) + '</td>' +
          '<td style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escapeHtml(a.title) + '">' + escapeHtml(a.title) + '</td>' +
          '<td style="font-size:.68rem;color:#94a3b8;white-space:nowrap">' + escapeHtml(a.last_sent_at) + '</td>' +
          '<td style="color:#94a3b8">' + Number(a.sent_count || 1) + '×</td>' +
          '</tr>';
      }).join('');
    }).catch(function () {
      // Alerts are supplementary; failure to list them is not worth a banner.
    });
  }

  function loadIssues(page) {
    if (page) state.page = Math.min(Math.max(1, page), state.pages || 1);
    var source = encodeURIComponent(document.getElementById('fi-source-filter') ? document.getElementById('fi-source-filter').value : '');
    var status = encodeURIComponent(document.getElementById('fi-status-filter') ? document.getElementById('fi-status-filter').value : '');
    var win = encodeURIComponent(document.getElementById('fi-window-filter') ? document.getElementById('fi-window-filter').value : '7d');
    get('?action=get_failure_issues&page=' + state.page + '&limit=25&source=' + source + '&status=' + status + '&window=' + win)
      .then(function (res) {
        if (res.status !== 'success' || !res.data) throw new Error('unavailable');
        renderIssues(res.data);
        statusError('');
      }).catch(function () {
        statusError('Failure Intelligence is unavailable. Apply sql/064_failure_intelligence.sql and verify the endpoint.');
      });
  }

  function loadReports(page) {
    if (page) state.reportsPage = Math.min(Math.max(1, page), state.reportsPages || 1);
    get('?action=get_failure_reports&page=' + state.reportsPage + '&limit=20').then(function (res) {
      if (res.status !== 'success' || !res.data) throw new Error('unavailable');
      renderReports(res.data);
    }).catch(function () {
      statusError('Recorded reports are unavailable. Apply sql/064_failure_intelligence.sql.');
    });
  }

  function page(delta) { loadIssues(state.page + delta); }
  function reportsPage(delta) { loadReports(state.reportsPage + delta); }

  function refresh() {
    get('?action=get_failure_overview').then(function (res) {
      if (res.status !== 'success' || !res.data) throw new Error('unavailable');
      renderOverview(res.data);
      statusError('');
    }).catch(function () {
      statusError('Failure Intelligence is unavailable. Apply sql/064_failure_intelligence.sql and verify the endpoint.');
    });
    loadIssues(1);
    loadReports(1);
    loadAlerts();
  }

  function init() {
    if (state.loaded) return;
    state.loaded = true;
    ['fi-source-filter', 'fi-status-filter', 'fi-window-filter'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', function () { loadIssues(1); });
    });
    refresh();
  }

  window.FailureIntelligenceUI = {
    init: init,
    refresh: refresh,
    page: page,
    detail: detail,
    setStatus: setStatus,
    saveRemediation: saveRemediation,
    generateReport: generateReport,
    loadReports: loadReports,
    reportsPage: reportsPage,
    viewReport: viewReport
  };

  function boot() {
    var section = document.getElementById('section-failure_intelligence');
    var bootSection = window.SA_BOOT && window.SA_BOOT.section;
    if ((section && !section.hasAttribute('hidden')) || bootSection === 'failure_intelligence') {
      init();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
