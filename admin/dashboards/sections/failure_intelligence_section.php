<?php
/**
 * Super Admin — Failure Intelligence Section
 *
 * Pure shell: every number on this surface is fetched client-side from
 * admin/api_failure_intelligence.php, so a super-admin page load runs zero
 * failure queries until the section is opened (the Fleet Analytics preamble
 * runs queries on every load — that overhead is deliberately not repeated
 * here).
 */
?>
<section id="section-failure_intelligence" class="section <?= ($activeSection ?? '') === 'failure_intelligence' ? 'active' : '' ?>"<?= ($activeSection ?? '') === 'failure_intelligence' ? '' : ' hidden' ?>>
    <div class="sec-header"><h2 class="sec-title"><i class="fa-solid fa-triangle-exclamation"></i> Failure Intelligence</h2><p class="sec-desc">What fails, why, when, where — and how to fix it. Issues group the three failure channels: sync attempts, server errors, and app crashes.</p></div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.9rem;margin-bottom:1.25rem">
        <div class="at-kpi-card" style="border-left:4px solid #f87171">
            <div class="at-kpi-label"><i class="fa-solid fa-circle-xmark" style="color:#f87171"></i> Sync failure rate (24h)</div>
            <div class="at-kpi-num" id="fi-kpi-failure-rate">—</div>
            <div class="at-kpi-sub" id="fi-kpi-failure-rate-sub">loading…</div>
        </div>
        <div class="at-kpi-card" style="border-left:4px solid #38bdf8">
            <div class="at-kpi-label"><i class="fa-solid fa-stopwatch" style="color:#38bdf8"></i> p95 attempt duration</div>
            <div class="at-kpi-num" id="fi-kpi-p95">—</div>
            <div class="at-kpi-sub" id="fi-kpi-p95-sub">completed attempts, 24h</div>
        </div>
        <div class="at-kpi-card" style="border-left:4px solid #a78bfa">
            <div class="at-kpi-label"><i class="fa-solid fa-wave-square" style="color:#a78bfa"></i> Attempts / min (24h)</div>
            <div class="at-kpi-num" id="fi-kpi-attempts-min">—</div>
            <div class="at-kpi-sub" id="fi-kpi-attempts-min-sub">traffic signal</div>
        </div>
        <div class="at-kpi-card" style="border-left:4px solid #4ade80">
            <div class="at-kpi-label"><i class="fa-solid fa-mobile-screen" style="color:#4ade80"></i> Crash-free installs (24h)</div>
            <div class="at-kpi-num" id="fi-kpi-crash-free">—</div>
            <div class="at-kpi-sub" id="fi-kpi-crash-free-sub">active installs that did not crash</div>
        </div>
        <div class="at-kpi-card" style="border-left:4px solid #f59e0b">
            <div class="at-kpi-label"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i> Open issues</div>
            <div class="at-kpi-num" id="fi-kpi-open-issues">—</div>
            <div class="at-kpi-sub" id="fi-kpi-open-issues-sub">all channels · <span id="fi-kpi-server-errors">—</span> server errors 24h</div>
        </div>
    </div>

    <div class="at-card" style="margin-bottom:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:.6rem">
            <div>
                <h3 style="margin:0;color:#f8fafc;font-size:1.1rem"><i class="fa-solid fa-bell" style="color:#facc15"></i> Recent Alerts</h3>
                <p style="margin:.35rem 0 0;color:#94a3b8;font-size:.78rem">Symptom-based alerts on a 15-minute schedule: new crash identities (≥5 installs/24h), build velocity vs fleet baseline (2×), and the multi-window sync error budget. Delivered to the notification center (super_admin) and Telegram when configured.</p>
            </div>
        </div>
        <div style="overflow-x:auto">
            <table class="ar-table" style="width:100%;border-collapse:collapse;font-size:.8rem">
                <thead><tr><th>Severity</th><th>Kind</th><th>Alert</th><th>Last sent</th><th>Sent</th></tr></thead>
                <tbody id="fi-alerts-body"><tr><td colspan="5" style="text-align:center;padding:1rem;color:#94a3b8">No alerts have fired yet.</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="at-card" style="margin-bottom:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
            <div>
                <h3 style="margin:0;color:#f8fafc;font-size:1.1rem"><i class="fa-solid fa-layer-group" style="color:#f87171"></i> Failure Issues</h3>
                <p style="margin:.35rem 0 0;color:#94a3b8;font-size:.78rem">Ranked by impact — most affected users/installations first. A resolved issue that recurs reopens as a regression.</p>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <select id="fi-source-filter" class="ar-select" style="width:auto;padding:.4rem .65rem;font-size:.78rem">
                    <option value="">All sources</option>
                    <option value="sync">Sync failures</option>
                    <option value="server_error">Server errors</option>
                    <option value="crash">App crashes</option>
                </select>
                <select id="fi-status-filter" class="ar-select" style="width:auto;padding:.4rem .65rem;font-size:.78rem">
                    <option value="">All statuses</option>
                    <option value="open">Open</option>
                    <option value="acknowledged">Acknowledged</option>
                    <option value="resolved">Resolved</option>
                </select>
                <select id="fi-window-filter" class="ar-select" style="width:auto;padding:.4rem .65rem;font-size:.78rem">
                    <option value="24h">Last 24 hours</option>
                    <option value="7d" selected>Last 7 days</option>
                    <option value="30d">Last 30 days</option>
                </select>
                <button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.refresh()"><i class="fa-solid fa-rotate"></i> Refresh</button>
            </div>
        </div>
        <p id="fi-status" role="status" hidden style="margin:0 0 1rem;padding:.65rem .8rem;border:1px solid rgba(248,113,113,.35);border-radius:6px;background:rgba(127,29,29,.18);color:#fecaca;font-size:.78rem"></p>
        <div style="overflow-x:auto">
            <table class="ar-table" style="width:100%;border-collapse:collapse;font-size:.8rem">
                <thead><tr><th>Issue</th><th>Source</th><th>Affected (window)</th><th>Occurrences (window)</th><th>Lifetime</th><th>First / last seen</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody id="fi-issues-body"><tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#94a3b8">Open this section to load issues…</td></tr></tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.8rem;gap:.6rem;flex-wrap:wrap">
            <span id="fi-page-info" style="color:#94a3b8;font-size:.75rem"></span>
            <span style="display:flex;gap:.4rem">
                <button type="button" class="btn btn-outline btn-sm" id="fi-prev-page" onclick="FailureIntelligenceUI.page(-1)">‹ Prev</button>
                <button type="button" class="btn btn-outline btn-sm" id="fi-next-page" onclick="FailureIntelligenceUI.page(1)">Next ›</button>
            </span>
        </div>
    </div>

    <div class="at-card" id="fi-issue-detail" hidden style="margin-bottom:1.5rem"></div>

    <div class="at-card" style="margin-bottom:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
            <div>
                <h3 style="margin:0;color:#f8fafc;font-size:1.1rem"><i class="fa-solid fa-file-lines" style="color:#a78bfa"></i> Recorded Failure Reports</h3>
                <p style="margin:.35rem 0 0;color:#94a3b8;font-size:.78rem">Auto-recorded when an issue crosses its threshold (≥5 affected or ≥50 occurrences in 24h, once per 24h), or generated on demand. Reports survive raw-data retention.</p>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="FailureIntelligenceUI.loadReports(1)"><i class="fa-solid fa-rotate"></i> Refresh reports</button>
        </div>
        <div style="overflow-x:auto">
            <table class="ar-table" style="width:100%;border-collapse:collapse;font-size:.8rem">
                <thead><tr><th>Severity</th><th>Issue</th><th>Window</th><th>Trigger</th><th>Generated</th><th>View</th></tr></thead>
                <tbody id="fi-reports-body"><tr><td colspan="6" style="text-align:center;padding:1.5rem;color:#94a3b8">Open this section to load reports…</td></tr></tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:.8rem;gap:.4rem">
            <button type="button" class="btn btn-outline btn-sm" id="fi-reports-prev" onclick="FailureIntelligenceUI.reportsPage(-1)">‹ Prev</button>
            <button type="button" class="btn btn-outline btn-sm" id="fi-reports-next" onclick="FailureIntelligenceUI.reportsPage(1)">Next ›</button>
        </div>
    </div>

    <div class="at-card" id="fi-report-view" hidden style="margin-bottom:1.5rem"></div>
</section>
