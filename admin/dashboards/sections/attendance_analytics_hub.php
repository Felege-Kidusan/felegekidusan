<?php
/**
 * Shared Attendance Analytics Hub (Information + HR departments)
 * ----------------------------------------------------------------
 * Read-only analytics over the two independent attendance sources:
 * Education (classes, recorded by teachers) and Mezmur (sections,
 * recorded by Mezmur takers). HR attendance was retired 2026-10-07 —
 * HR analyses the other departments' attendance here instead of
 * recording its own.
 *
 * Host dashboard must define $ajaxPrefix and $userRole and wrap this
 * partial in its own <section>. Data comes from api_info_analytics.php
 * (GET for info_dept / hr_dept / admins; refresh POST = admins only).
 * Logic lives in /admin/js/attendance_analytics_hub.js (AttendanceHub).
 */
$hubAjaxPrefix = isset($ajaxPrefix) ? (string)$ajaxPrefix : '';
$hubRole = isset($userRole) ? (string)$userRole : '';
$hubCanRefresh = in_array($hubRole, ['super_admin', 'school_admin'], true);
$hubAssetVer = defined('SSMS_ASSET_VER') ? SSMS_ASSET_VER : '1';
?>
<div id="attendanceHub" data-api="<?= htmlspecialchars($hubAjaxPrefix, ENT_QUOTES, 'UTF-8') ?>api_info_analytics.php">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
        <div>
            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                <span class="w-7 h-7 rounded-xl bg-cyan-100 flex items-center justify-center text-cyan-600"><i class="fa-solid fa-chart-column"></i></span>
                <span>Attendance Analytics Hub</span>
            </h3>
            <p class="text-xs text-slate-500 mt-1">Read-only analytics across Education &amp; Mezmur attendance. Compare departments — their data is never combined.</p>
        </div>
        <div class="flex items-center gap-2">
            <input id="ihFrom" type="date" onchange="AttendanceHub.reload()" aria-label="From date"
                   class="px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
            <input id="ihTo" type="date" onchange="AttendanceHub.reload()" aria-label="To date"
                   class="px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
            <button type="button" onclick="AttendanceHub.exportAll()" class="px-3 py-2 text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl transition">
                <i class="fa-solid fa-download mr-1"></i> Excel
            </button>
            <?php if ($hubCanRefresh): ?>
            <button type="button" onclick="AttendanceHub.refresh()" class="px-3 py-2 text-xs font-semibold bg-cyan-600 hover:bg-cyan-700 text-white rounded-xl transition">
                <i class="fa-solid fa-rotate mr-1"></i> Refresh data
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Read-only governance notice -->
    <div class="mb-4 px-4 py-3 bg-cyan-50 border border-cyan-100 rounded-2xl text-xs text-cyan-900/80 flex items-start gap-2">
        <i class="fa-solid fa-eye mt-0.5"></i>
        <span>This hub is <b>view-only</b>: the analysing department compares and exports — it never records or edits attendance. Education and Mezmur keep their own takers and their own data; HR attendance was retired. Last rollup refresh: <b id="ihGeneratedAt">—</b>.</span>
    </div>

    <!-- KPI band: one card per source (never merged) -->
    <div id="ihKpiBand" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
        <div class="animate-pulse h-36 bg-slate-100 rounded-2xl"></div>
        <div class="animate-pulse h-36 bg-slate-100 rounded-2xl"></div>
    </div>

    <!-- Comparison table -->
    <div class="panel p-5 mb-5">
        <h4 class="text-sm font-semibold text-slate-700 mb-3"><i class="fa-solid fa-scale-balanced mr-1 text-cyan-500"></i> Department Comparison</h4>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                        <th class="py-2 pr-3">Department</th>
                        <th class="py-2 pr-3">Days</th>
                        <th class="py-2 pr-3">Groups</th>
                        <th class="py-2 pr-3">Marks</th>
                        <th class="py-2 pr-3">Rate</th>
                        <th class="py-2 pr-3">Absent</th>
                        <th class="py-2 pr-3">Late</th>
                        <th class="py-2 pr-3">Sheets</th>
                        <th class="py-2">Approved</th>
                    </tr>
                </thead>
                <tbody id="ihComparisonTbody">
                    <tr><td colspan="9" class="py-4 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Drill-down: per-source trends + group table -->
    <div class="panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h4 class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-magnifying-glass-chart mr-1 text-cyan-500"></i> Drill Down</h4>
            <div class="flex items-center gap-2">
                <button type="button" onclick="AttendanceHub.setSource('edu')" id="ihSrcEdu" class="ih-src px-3 py-1.5 text-xs font-semibold rounded-lg border transition">Education</button>
                <button type="button" onclick="AttendanceHub.setSource('mezmur')" id="ihSrcMezmur" class="ih-src px-3 py-1.5 text-xs font-semibold rounded-lg border transition">Mezmur</button>
            </div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div>
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Daily trend (last days)</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                                <th class="py-2 pr-3">Date</th>
                                <th class="py-2 pr-3">Marked</th>
                                <th class="py-2 pr-3">Attended</th>
                                <th class="py-2 pr-3">Absent</th>
                                <th class="py-2">Rate</th>
                            </tr>
                        </thead>
                        <tbody id="ihTrendTbody">
                            <tr><td colspan="5" class="py-4 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">By section / class</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                                <th class="py-2 pr-3">Group</th>
                                <th class="py-2 pr-3">Days</th>
                                <th class="py-2 pr-3">Marks</th>
                                <th class="py-2 pr-3">Rate</th>
                                <th class="py-2">Absent</th>
                            </tr>
                        </thead>
                        <tbody id="ihGroupTbody">
                            <tr><td colspan="5" class="py-4 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="/admin/js/attendance_analytics_hub.js?v=<?= htmlspecialchars((string)$hubAssetVer, ENT_QUOTES, 'UTF-8') ?>-20261022r"></script>
