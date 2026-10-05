<?php
/**
 * Super Admin — Mobile App Fleet Analytics & Telemetry Section
 */
$atProjectRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
require_once $atProjectRoot . '/admin/backend/services/AppTelemetryService.php';

$atMetrics = [
    'summary' => [
        'total_installations' => 0,
        'active_today' => 0,
        'active_7d' => 0,
        'active_30d' => 0,
        'total_downloads' => 0,
        'downloads_today' => 0,
        'total_launches' => 0,
        'sync_success' => 0,
        'sync_fail' => 0,
        'sync_health_percentage' => 100,
        'total_crashes' => 0,
        'adoption_percentage' => 0,
    ],
    'versions' => [],
    'os_versions' => [],
    'brands' => [],
    'models' => [],
];

if (isset($conn) && $conn instanceof mysqli) {
    try {
        $atMetrics = \App\Services\AppTelemetryService::getFleetMetrics($conn);
    } catch (Throwable $e) {}
}

$sum = $atMetrics['summary'] ?? [];
?>
<style>
/* ═══ App Telemetry Custom Styles ═══ */
#section-app_telemetry {
    color: #f1f5f9;
}
#section-app_telemetry .at-card {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 0.75rem;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2), 0 2px 4px -2px rgba(0, 0, 0, 0.2);
}
#section-app_telemetry .at-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
#section-app_telemetry .at-kpi-card {
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 0.65rem;
    padding: 1rem 1.15rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.15s ease, border-color 0.15s ease;
}
#section-app_telemetry .at-kpi-card:hover {
    border-color: #475569;
    transform: translateY(-2px);
}
#section-app_telemetry .at-kpi-num {
    font-size: 1.75rem;
    font-weight: 700;
    color: #f8fafc;
    line-height: 1.2;
    margin-top: 0.25rem;
}
#section-app_telemetry .at-kpi-label {
    font-size: 0.775rem;
    color: #94a3b8;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
#section-app_telemetry .at-kpi-sub {
    font-size: 0.7rem;
    color: #64748b;
    margin-top: 0.35rem;
}
#section-app_telemetry .at-bar-track {
    background: #0f172a;
    height: 10px;
    border-radius: 99px;
    overflow: hidden;
    margin-top: 0.4rem;
    border: 1px solid #334155;
}
#section-app_telemetry .at-bar-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.3s ease;
}
#section-app_telemetry .at-filter-btn {
    padding: 0.45rem 0.85rem;
    border-radius: 99px;
    font-size: 0.775rem;
    font-weight: 600;
    cursor: pointer;
    background: #0f172a;
    border: 1px solid #334155;
    color: #94a3b8;
    transition: all 0.15s ease;
}
#section-app_telemetry .at-filter-btn:hover {
    color: #f8fafc;
    border-color: #64748b;
}
#section-app_telemetry .at-filter-btn.active {
    background: #38bdf8;
    color: #0f172a;
    border-color: #38bdf8;
}
#section-app_telemetry .at-table-wrap {
    overflow-x: auto;
    border: 1px solid #334155;
    border-radius: 0.5rem;
    background: #0f172a;
}
#section-app_telemetry table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.825rem;
    text-align: left;
}
#section-app_telemetry th {
    background: #131d31;
    color: #94a3b8;
    font-weight: 600;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #334155;
    text-transform: uppercase;
    font-size: 0.725rem;
    letter-spacing: 0.5px;
}
#section-app_telemetry td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    color: #e2e8f0;
}
#section-app_telemetry tr:hover td {
    background: rgba(255, 255, 255, 0.02);
}
/* The shared .ar-* controls are scoped to App Release. Keep telemetry
   controls on the same dark admin surface instead of browser-white defaults. */
#section-app_telemetry .ar-input,
#section-app_telemetry .ar-select {
    box-sizing: border-box;
    background-color: #0f172a !important;
    color: #f8fafc !important;
    border: 1px solid #475569 !important;
    border-radius: 0.5rem;
    color-scheme: dark;
    font-family: inherit;
}
#section-app_telemetry .ar-input::placeholder {
    color: #94a3b8 !important;
    opacity: 1;
}
#section-app_telemetry .ar-input:focus,
#section-app_telemetry .ar-select:focus {
    outline: none !important;
    border-color: #38bdf8 !important;
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.22) !important;
}
#section-app_telemetry .ar-select option {
    background-color: #0f172a !important;
    color: #f8fafc !important;
}
#section-app_telemetry .ar-input:disabled,
#section-app_telemetry .ar-select:disabled {
    background-color: #1e293b !important;
    color: #cbd5e1 !important;
    opacity: 1;
}
</style>

<!-- ═══ MOBILE FLEET TELEMETRY & ANALYTICS ═══ -->
<section id="section-app_telemetry" class="section <?= ($activeSection ?? '') === 'app_telemetry' ? 'active' : '' ?>"<?= ($activeSection ?? '') === 'app_telemetry' ? '' : ' hidden' ?>>
    <div class="sec-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem">
        <div>
            <h2 class="sec-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.4rem;color:#f8fafc">
                <i class="fa-solid fa-chart-line" style="color:#38bdf8"></i> Mobile Fleet Telemetry &amp; Analytics
            </h2>
            <p class="sec-desc" style="color:#94a3b8;font-size:0.875rem">
                Fleet installations, version/device telemetry, and server-observed sync operations
            </p>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
            <button type="button" class="btn btn-outline btn-sm" onclick="if(window.AppTelemetryUI)window.AppTelemetryUI.refresh()"><i class="fa-solid fa-rotate"></i> Refresh Telemetry</button>
        </div>
    </div>
    <p id="telemetry-status" role="status" hidden style="margin:-0.75rem 0 1rem;padding:0.75rem 1rem;border:1px solid rgba(248,113,113,.35);border-radius:6px;background:rgba(127,29,29,.18);color:#fecaca"></p>

    <!-- Server-observed sync health and recent operations are integrated into
         the existing fleet analytics section below; there is one dashboard
         surface rather than a second sync dashboard. -->
    <!-- Filter Control Bar -->
    <div class="at-card" style="margin-bottom:1.5rem;padding:0.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap">
            <span style="font-size:0.775rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px">Time Window:</span>
            <button type="button" class="at-filter-btn" data-range="today" onclick="AppTelemetryUI.setRange('today', this)">Today (24h)</button>
            <button type="button" class="at-filter-btn active" data-range="7d" onclick="AppTelemetryUI.setRange('7d', this)">7 Days (WAU)</button>
            <button type="button" class="at-filter-btn" data-range="30d" onclick="AppTelemetryUI.setRange('30d', this)">30 Days (MAU)</button>
            <button type="button" class="at-filter-btn" data-range="all" onclick="AppTelemetryUI.setRange('all', this)">All Time</button>
        </div>
        <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap">
            <select id="telemetry-version-filter" class="ar-select" style="width:auto;padding:0.4rem 0.75rem;font-size:0.8rem" onchange="AppTelemetryUI.setVersion(this.value)">
                <option value="">All App Versions</option>
                <?php foreach (($atMetrics['versions'] ?? []) as $v): ?>
                    <option value="<?= htmlspecialchars($v['version']) ?>">v<?= htmlspecialchars($v['version']) ?> (Build <?= htmlspecialchars($v['build']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Executive KPI Metrics Grid -->
    <div class="at-kpi-grid">
        <div class="at-kpi-card" style="border-left:4px solid #38bdf8">
            <div class="at-kpi-label"><i class="fa-solid fa-mobile-screen-button" style="color:#38bdf8"></i> Total Installed Devices</div>
            <div class="at-kpi-num" id="kpi-total-devices"><?= number_format((int)($sum['total_installations'] ?? 0)) ?></div>
            <div class="at-kpi-sub">Unique installation IDs recorded</div>
        </div>

        <div class="at-kpi-card" style="border-left:4px solid #4ade80">
            <div class="at-kpi-label"><i class="fa-solid fa-users" style="color:#4ade80"></i> Active Devices (7 Days)</div>
            <div class="at-kpi-num" id="kpi-active-7d"><?= number_format((int)($sum['active_7d'] ?? 0)) ?></div>
            <div class="at-kpi-sub">Active today: <span style="color:#4ade80;font-weight:600" id="kpi-active-today"><?= number_format((int)($sum['active_today'] ?? 0)) ?></span> (DAU)</div>
        </div>

        <div class="at-kpi-card" style="border-left:4px solid #a78bfa">
            <div class="at-kpi-label"><i class="fa-solid fa-cloud-arrow-down" style="color:#a78bfa"></i> APK Downloads</div>
            <div class="at-kpi-num" id="kpi-downloads"><?= number_format((int)($sum['total_downloads'] ?? 0)) ?></div>
            <div class="at-kpi-sub"><span id="kpi-downloads-today"><?= number_format((int)($sum['downloads_today'] ?? 0)) ?></span> downloaded today</div>
        </div>

        <div class="at-kpi-card" style="border-left:4px solid #f59e0b">
            <div class="at-kpi-label"><i class="fa-solid fa-code-branch" style="color:#f59e0b"></i> Version Adoption</div>
            <div class="at-kpi-num" id="kpi-adoption"><?= htmlspecialchars((string)($sum['adoption_percentage'] ?? 0)) ?>%</div>
            <div class="at-kpi-sub">On latest build <span style="color:#f59e0b;font-weight:600" id="kpi-latest-ver">v<?= htmlspecialchars((string)($sum['latest_version'] ?? '')) ?></span></div>
        </div>

        <div class="at-kpi-card" style="border-left:4px solid #ec4899">
            <div class="at-kpi-label"><i class="fa-solid fa-bug" style="color:#ec4899"></i> Fleet Crashes</div>
            <div class="at-kpi-num" id="kpi-crashes"><?= number_format((int)($sum['total_crashes'] ?? 0)) ?></div>
            <div class="at-kpi-sub"><span id="kpi-launches"><?= number_format((int)($sum['total_launches'] ?? 0)) ?></span> total app sessions</div>
        </div>

        <?php foreach ([
            ['pending','Pending','Not server-observable','#64748b'],
            ['retrying','Retrying','Retry schedule not server-observable','#64748b'],
            ['failed','Failed / rejected','Server responses','#f87171'],
            ['in_flight','In flight','Open server reservations','#38bdf8'],
            ['stale','Stale','Open > 15 minutes','#f59e0b'],
            ['recent_successful','Recent successful','Completed server responses','#4ade80'],
        ] as $card): ?>
            <div class="at-kpi-card" style="border-left:4px solid <?= $card[3] ?>">
                <div class="at-kpi-label"><i class="fa-solid fa-circle" style="color:<?= $card[3] ?>;font-size:.5rem"></i> <?= htmlspecialchars($card[1]) ?></div>
                <div class="at-kpi-num" id="sync-kpi-<?= $card[0] ?>">—</div>
                <div class="at-kpi-sub" id="sync-kpi-<?= $card[0] ?>-sub"><?= htmlspecialchars($card[2]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- RECENT SERVER-OBSERVED SYNC OPERATIONS -->
    <div class="at-card" style="margin-bottom:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
            <div>
                <h3 style="margin:0;color:#f8fafc;font-size:1.1rem"><i class="fa-solid fa-wave-square" style="color:#f59e0b"></i> Recent Sync Operations</h3>
                <p style="margin:.35rem 0 0;color:#94a3b8;font-size:.78rem">Server-observed lifecycle, idempotency, request correlation, and safe outcomes. Local pending work is not counted as zero.</p>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="if(window.SyncMonitorUI)window.SyncMonitorUI.refresh()"><i class="fa-solid fa-rotate"></i> Refresh Sync Operations</button>
        </div>
        <p id="sync-monitor-status" role="status" hidden style="margin:0 0 1rem;padding:.65rem .8rem;border:1px solid rgba(248,113,113,.35);border-radius:6px;background:rgba(127,29,29,.18);color:#fecaca;font-size:.78rem"></p>
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.9rem">
            <select id="sync-monitor-range" class="ar-select" style="width:auto;padding:.4rem .65rem;font-size:.78rem" onchange="SyncMonitorUI.refresh()">
                <option value="1h">Last hour</option><option value="24h">Last 24 hours</option><option value="7d" selected>Last 7 days</option><option value="30d">Last 30 days</option><option value="all">All retained</option>
            </select>
            <select id="sync-monitor-status-filter" class="ar-select" style="width:auto;padding:.4rem .65rem;font-size:.78rem" onchange="SyncMonitorUI.loadAttempts(1)">
                <option value="">All statuses</option><option value="in_flight">In flight</option><option value="stale">Stale</option><option value="completed">Completed</option><option value="failed">Failed</option><option value="rejected">Rejected</option><option value="replayed">Replayed</option>
            </select>
            <select id="sync-monitor-domain-filter" class="ar-select" style="width:auto;padding:.4rem .65rem;font-size:.78rem" onchange="SyncMonitorUI.loadAttempts(1)">
                <option value="">All domains</option><option value="attendance">Attendance</option><option value="grades">Grades</option><option value="hr">HR</option><option value="mezmur">Mezmur</option><option value="notifications">Notifications</option><option value="users">Users</option><option value="other">Other</option>
            </select>
            <select id="sync-monitor-source-filter" class="ar-select" style="width:auto;padding:.4rem .65rem;font-size:.78rem" onchange="SyncMonitorUI.loadAttempts(1)">
                <option value="">All sources</option><option value="foreground">Foreground</option><option value="background">Background</option><option value="not_observed">Source not observed</option>
            </select>
            <input id="sync-monitor-search" class="ar-input" style="width:220px;padding:.4rem .65rem;font-size:.78rem" placeholder="Search request or operation id" oninput="SyncMonitorUI.search(this.value)">
        </div>
        <div class="at-table-wrap">
            <table>
                <thead><tr><th>Status</th><th>Started</th><th>Domain / operation</th><th>User</th><th>Attempt</th><th>Source</th><th>HTTP / outcome</th><th>Detail</th></tr></thead>
                <tbody id="sync-monitor-table-body"><tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#94a3b8">Loading server-observed attempts…</td></tr></tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.8rem;font-size:.75rem;color:#94a3b8">
            <span id="sync-monitor-page-info">—</span>
            <div style="display:flex;gap:.4rem"><button type="button" class="btn btn-outline btn-sm" id="sync-monitor-prev" onclick="SyncMonitorUI.page(-1)">Prev</button><button type="button" class="btn btn-outline btn-sm" id="sync-monitor-next" onclick="SyncMonitorUI.page(1)">Next</button></div>
        </div>
        <div id="sync-monitor-detail" hidden style="margin-top:1rem;padding:.9rem;border:1px solid #334155;border-radius:.5rem;background:#0f172a;font-size:.78rem"></div>
    </div>

    <!-- Two-Column Analytics Grid -->
    <div class="grid-2" style="margin-bottom:1.5rem;gap:1.5rem">
        <!-- App Version Distribution -->
        <div class="at-card">
            <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.05rem;color:#f8fafc;margin-bottom:1rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
                <i class="fa-solid fa-tags" style="color:#38bdf8"></i> Version Distribution &amp; Fleet Share
            </h3>
            <div id="telemetry-version-bars">
                <?php if (empty($atMetrics['versions'])): ?>
                    <p style="font-size:0.825rem;color:#94a3b8;font-style:italic;text-align:center;padding:1.5rem">No version telemetry recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($atMetrics['versions'] as $v): ?>
                        <div style="margin-bottom:1rem">
                            <div style="display:flex;justify-content:space-between;font-size:0.825rem;font-weight:600;color:#f8fafc">
                                <span>Version <?= htmlspecialchars($v['version']) ?> <span style="font-size:0.75rem;color:#94a3b8;font-weight:400">(Build <?= htmlspecialchars($v['build']) ?>)</span></span>
                                <span style="color:#38bdf8"><?= htmlspecialchars($v['count']) ?> devices (<?= htmlspecialchars($v['percentage']) ?>%)</span>
                            </div>
                            <div class="at-bar-track">
                                <div class="at-bar-fill" style="width:<?= htmlspecialchars($v['percentage']) ?>%;background:linear-gradient(90deg,#38bdf8,#3b82f6)"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Android OS & Hardware Class -->
        <div class="at-card">
            <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.05rem;color:#f8fafc;margin-bottom:1rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
                <i class="fa-brands fa-android" style="color:#4ade80"></i> Android OS &amp; Hardware Tier Breakdown
            </h3>
            <div id="telemetry-os-bars">
                <?php if (empty($atMetrics['os_versions'])): ?>
                    <p style="font-size:0.825rem;color:#94a3b8;font-style:italic;text-align:center;padding:1.5rem">No OS telemetry recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($atMetrics['os_versions'] as $os): ?>
                        <div style="margin-bottom:0.85rem">
                            <div style="display:flex;justify-content:space-between;font-size:0.8rem;font-weight:500;color:#f8fafc">
                                <span>Android <?= htmlspecialchars($os['os_version']) ?> <span style="font-size:0.7rem;color:#64748b">(SDK <?= htmlspecialchars($os['sdk_int']) ?>)</span></span>
                                <span style="color:#4ade80"><?= htmlspecialchars($os['count']) ?> (<?= htmlspecialchars($os['percentage']) ?>%)</span>
                            </div>
                            <div class="at-bar-track">
                                <div class="at-bar-fill" style="width:<?= htmlspecialchars($os['percentage']) ?>%;background:linear-gradient(90deg,#10b981,#4ade80)"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Active Fleet Device Directory Table -->
    <div class="at-card" style="margin-bottom:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.75rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
            <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.05rem;color:#f8fafc;margin:0">
                <i class="fa-solid fa-list-check" style="color:#a78bfa"></i> Active Fleet Device Directory
            </h3>
            <div style="display:flex;gap:0.5rem;align-items:center">
                <input type="text" id="telemetry-search-input" class="ar-input" placeholder="Search model, brand or ID..." style="width:240px;padding:0.4rem 0.75rem;font-size:0.8rem" oninput="AppTelemetryUI.search(this.value)">
            </div>
        </div>

        <div class="at-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Device Model</th>
                        <th>Android OS</th>
                        <th>App Version</th>
                        <th>ABI / Arch</th>
                        <th>RAM / Class</th>
                        <th>Launches</th>
                        <th>Syncs (Ok / Fail)</th>
                        <th>Last Active</th>
                    </tr>
                </thead>
                <tbody id="telemetry-table-body">
                    <tr>
                        <td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8">
                            <i class="fa-solid fa-spinner fa-spin"></i> Loading device installations...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="telemetry-pagination" style="display:flex;justify-content:space-between;align-items:center;margin-top:1rem;font-size:0.775rem;color:#94a3b8">
            <span id="telemetry-page-info">Showing devices</span>
            <div style="display:flex;gap:0.4rem">
                <button type="button" class="btn btn-outline btn-sm" id="btn-telemetry-prev" onclick="AppTelemetryUI.prevPage()"><i class="fa-solid fa-chevron-left"></i> Prev</button>
                <button type="button" class="btn btn-outline btn-sm" id="btn-telemetry-next" onclick="AppTelemetryUI.nextPage()">Next <i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
</section>
