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
<style>
/* Scoped like every sibling section: App Telemetry / App Release define the
   shared-looking .at-*/.ar-* classes ONLY under their own section ids, so
   those selectors never reached this section — it rendered with unstyled KPI
   cards and browser-white selects on the dark chrome. Values mirror the
   telemetry section so both analytics surfaces read as one product; the
   caption gray is #7c8aa5 because #7c8aa5 on the page background is 4.0:1,
   under WCAG's 4.5:1 floor for small text. */
#section-failure_intelligence .at-card {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 0.75rem;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2), 0 2px 4px -2px rgba(0, 0, 0, 0.2);
}
#section-failure_intelligence .at-kpi-card {
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 0.65rem;
    padding: 1rem 1.15rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.15s ease, border-color 0.15s ease;
}
#section-failure_intelligence .at-kpi-card:hover {
    border-color: #7c8aa5;
    transform: translateY(-2px);
}
#section-failure_intelligence .at-kpi-num {
    font-size: 1.75rem;
    font-weight: 700;
    color: #f8fafc;
    line-height: 1.2;
    margin-top: 0.25rem;
}
#section-failure_intelligence .at-kpi-label {
    font-size: 0.775rem;
    color: #94a3b8;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
#section-failure_intelligence .at-kpi-sub {
    font-size: 0.7rem;
    color: #7c8aa5;
    margin-top: 0.35rem;
}
#section-failure_intelligence .ar-table,
#section-failure_intelligence table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.825rem;
    text-align: left;
}
#section-failure_intelligence th {
    background: #131d31;
    color: #94a3b8;
    font-weight: 600;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #334155;
    text-transform: uppercase;
    font-size: 0.725rem;
    letter-spacing: 0.5px;
}
#section-failure_intelligence td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    color: #e2e8f0;
}
#section-failure_intelligence tr:hover td {
    background: rgba(255, 255, 255, 0.02);
}
#section-failure_intelligence .ar-select {
    box-sizing: border-box;
    background-color: #0f172a !important;
    color: #f8fafc !important;
    border: 1px solid #7c8aa5 !important;
    border-radius: 0.5rem;
    color-scheme: dark;
    font-family: inherit;
}
#section-failure_intelligence .ar-select:focus {
    outline: none !important;
    border-color: #38bdf8 !important;
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.22) !important;
}
#section-failure_intelligence .ar-select option {
    background-color: #0f172a !important;
    color: #f8fafc !important;
}
#section-failure_intelligence .ar-select:disabled {
    background-color: #1e293b !important;
    color: #cbd5e1 !important;
    opacity: 1;
}
@media (prefers-reduced-motion: reduce) {
    #section-failure_intelligence .at-kpi-card,
    #section-failure_intelligence .at-kpi-card:hover {
        transition: none;
        transform: none;
    }
}
</style>
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
