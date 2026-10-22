<?php
/**
 * ============================================================================
 * Lesson attendance history — one member (Attendance alerting — Phase 6)
 * ============================================================================
 * Requirements §3: Education, HR and Information can see, per member, every
 * lesson outcome with subject, lesson number, teacher, date and result.
 * Teachers see only lessons of their own classes (scoped by the API).
 * Education may cancel a lesson here (kept, flagged, re-derives the day).
 * Ethiopian calendar only: every date comes from the API.
 * No data, no SQL, no CDN assets on this shell.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/LessonAttendanceService.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}
$userRole = (string)($_SESSION['admin_role'] ?? '');
if (!in_array($userRole, \App\Services\LessonAttendanceService::READ_ROLES, true)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
$memberId = (int)($_GET['member_id'] ?? 0);
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$roleLabels = [
    'super_admin' => 'Super Admin', 'school_admin' => 'School Admin', 'info_dept' => 'Information Department',
    'hr_dept' => 'HR Department', 'edu_dept' => 'Education Department', 'teacher' => 'Teacher',
];
$cfg = [
    'api' => '/admin/api_lesson_attendance.php',
    'csrf' => generateCsrfToken(),
    'memberUrl' => '/admin/info_manage_member.php?id=',
    'role' => $userRole,
    'canCancel' => $userRole === 'edu_dept',
    'memberId' => $memberId,
];
$assetVer = '20261022p';
$schoolName = defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'SSMS';
$backUrl = $userRole === 'teacher' ? '/admin/dashboards/teacher.php' : '/admin/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lesson Attendance · <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/admin/css/reports_v2.css?v=<?= $assetVer ?>">
<style>
.la-wrap{padding:16px 20px 40px;max-width:1100px;margin:0 auto}
.la-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.la-head h2{margin:0;font-size:16px}
.la-form{display:flex;gap:8px;align-items:center}
.la-form input{padding:7px 9px;border:1px solid var(--rc-line);border-radius:8px;font:inherit;font-size:13px;width:140px}
.la-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--rc-line);border-radius:var(--rc-radius);overflow:hidden}
.la-table th,.la-table td{padding:8px 10px;border-bottom:1px solid var(--rc-line);text-align:left;vertical-align:middle;font-size:13px}
.la-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--rc-muted);white-space:nowrap}
.pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.pill-present{background:#dcfce7;color:#166534}.pill-absent{background:#fee2e2;color:#991b1b}
.pill-late{background:#fef3c7;color:#92400e}.pill-excused{background:#dbeafe;color:#1e40af}
.pill-holiday,.pill-cancelled{background:#e5e7eb;color:#374151}
.la-empty{padding:30px;text-align:center;color:var(--rc-muted);background:#fff;border:1px dashed var(--rc-line);border-radius:var(--rc-radius)}
.la-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;font-size:12px;color:var(--rc-muted);flex-wrap:wrap}
.la-note{font-size:12px;color:var(--rc-muted);margin-top:14px}
.nc-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;z-index:60}
.nc-toast.nc-err{background:#b91c1c}.nc-toast.nc-ok{background:#166534}
@media (max-width:720px){.la-table th.opt,.la-table td.opt{display:none}}
</style>
</head>
<body>
<div class="rc" data-role="<?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>">
  <header class="rc-header">
    <div class="rc-header-left">
      <a class="rc-iconbtn" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" title="Back to dashboard" aria-label="Back">
        <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
      </a>
      <div>
        <h1 class="rc-title">Lesson Attendance <span class="rc-badge amh">የትምህርት ክፍለ ጊዜ ቅጥር</span></h1>
        <div class="rc-subtitle"><?= htmlspecialchars($roleLabels[$userRole] ?? $userRole, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ethio_date_format($today, 'F j, Y'), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="rc-header-right">
      <form class="la-form" id="la-form">
        <input type="number" min="1" id="la-member" placeholder="Member ID" value="<?= $memberId > 0 ? $memberId : '' ?>">
        <button class="rc-btn rc-btn-ghost rc-btn-sm" type="submit">Open</button>
      </form>
    </div>
  </header>

  <main class="la-wrap">
    <div id="la-unavailable" class="la-empty" hidden>
      Lesson attendance is not available yet (feature flag off or <code>sql/076_attendance_sessions.sql</code> not applied).
    </div>
    <div class="la-head">
      <h2 id="la-title">Select a member</h2>
      <span class="rc-muted" id="la-total"></span>
    </div>
    <div id="la-list"></div>
    <div class="la-foot">
      <span id="la-meta"></span>
      <div>
        <label class="rc-muted" style="font-size:.8rem">Per page <select id="la-size" aria-label="Rows per page"><option>25</option><option selected>50</option><option>100</option><option>200</option></select></label>
        <button class="rc-btn rc-btn-ghost rc-btn-sm" id="la-prev" type="button">‹ Prev</button>
        <button class="rc-btn rc-btn-ghost rc-btn-sm" id="la-next" type="button">Next ›</button>
        <button class="rc-btn rc-btn-sm" id="la-more" type="button" hidden>Load more</button>
      </div>
    </div>
    <p class="la-note">Each row is one lesson sheet saved by a teacher. The class/day result used for warnings and monthly rates is calculated from the lessons of that day: present if present in any lesson, else late, else excused, else absent. A cancelled lesson is kept for history but no longer counts.</p>
  </main>
</div>

<script>window.LA_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
<script>
(function () {
  'use strict';
  var cfg = window.LA_CONFIG || {};
  // Phase 3 paging: per page remembered; Load more appends; Next continues after the rows shown.
  var LA_KEY = 'fkss_pp_lesson_att';
  var PAGE = (function () { try { var v = parseInt(localStorage.getItem(LA_KEY), 10); return [25, 50, 100, 200].indexOf(v) >= 0 ? v : 50; } catch (e) { return 50; } })();
  var offset = 0, total = 0, memberId = cfg.memberId || 0, shown = [], busy = false;
  var $ = function (id) { return document.getElementById(id); };
  if ($('la-size')) $('la-size').value = String(PAGE);
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg, ok) {
    var t = document.createElement('div'); t.className = 'nc-toast ' + (ok ? 'nc-ok' : 'nc-err'); t.textContent = msg;
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3200);
  }
  function load(append) {
    if (!memberId) { $('la-list').innerHTML = ''; return; }
    if (busy) return;
    busy = true;
    var from = append ? offset + shown.length : offset;
    if (append && $('la-more')) { $('la-more').disabled = true; $('la-more').textContent = 'Loading…'; }
    fetch(cfg.api + '?action=member&member_id=' + encodeURIComponent(memberId) + '&limit=' + PAGE + '&offset=' + from, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        if (d.status !== 'success') { toast(d.message || 'Could not load.', false); render([]); return; }
        $('la-unavailable').hidden = !!d.available;
        total = d.total || 0;
        var m = d.member || {};
        $('la-title').innerHTML = '<a href="' + esc(cfg.memberUrl + memberId) + '">' + esc(m.name || ('Member #' + memberId)) + '</a> <span class="rc-muted">' + esc(m.member_code || '') + '</span>';
        $('la-total').textContent = total ? total + ' lesson record(s)' : '';
        shown = append ? shown.concat(d.items || []) : (d.items || []);
        render(shown);
      })
      .catch(function () { busy = false; toast('Could not load lesson attendance.', false); if (append) render(shown); });
  }
  function render(items) {
    var more = $('la-more');
    if (more) { var left = Math.max(0, total - (offset + items.length)); more.hidden = left <= 0; more.disabled = false; more.textContent = 'Load more (' + left + ' left)'; }
    if (!items.length) { $('la-list').innerHTML = '<div class="la-empty">No lesson attendance recorded for this member' + (cfg.role === 'teacher' ? ' in your classes' : '') + '.</div>'; $('la-meta').textContent = ''; return; }
    var rows = items.map(function (it) {
      var cancelled = it.session_status === 'cancelled';
      return '<tr' + (cancelled ? ' style="opacity:.6"' : '') + '>' +
        '<td>' + esc(it.date_ec) + '</td>' +
        '<td>' + esc(it.class_name) + '</td>' +
        '<td>' + esc(it.subject_name || it.subject_name_en || ('#' + it.subject_id)) + '</td>' +
        '<td>L' + esc(it.lesson_no) + '</td>' +
        '<td class="opt">' + esc(it.teacher_name || ('#' + it.teacher_id)) + '</td>' +
        '<td><span class="pill pill-' + esc(it.status) + '">' + esc(it.status) + '</span>' + (cancelled ? ' <span class="pill pill-cancelled" title="Does not count">cancelled</span>' : '') + '</td>' +
        '<td class="opt">' + esc(it.note) + '</td>' +
        (cfg.canCancel ? '<td>' + (cancelled ? '' : '<button class="rc-btn rc-btn-ghost rc-btn-sm" data-cancel="' + esc(it.session_id) + '" type="button">Cancel lesson</button>') + '</td>' : '') +
        '</tr>';
    }).join('');
    $('la-list').innerHTML = '<table class="la-table"><thead><tr><th>Date (EC)</th><th>Class</th><th>Subject</th><th>Lesson</th><th class="opt">Teacher</th><th>Outcome</th><th class="opt">Note</th>' + (cfg.canCancel ? '<th></th>' : '') + '</tr></thead><tbody>' + rows + '</tbody></table>';
    $('la-meta').textContent = 'Showing ' + (offset + 1) + '–' + Math.min(offset + items.length, total) + ' of ' + total;
    $('la-prev').disabled = offset <= 0; $('la-next').disabled = offset + items.length >= total;
  }
  $('la-list').addEventListener('click', function (e) {
    var b = e.target.closest('[data-cancel]'); if (!b) return;
    // Danger confirmation (MEDIUM) with the required reason (was window.prompt).
    if (!(window.DangerConfirm && window.DangerConfirm.ask)) { toast('The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.', false); return; }
    var cells = b.closest('tr') ? b.closest('tr').children : [];
    var label = [0, 1, 2].map(function (i) { return cells[i] ? cells[i].textContent.trim() : ''; }).filter(Boolean).join(' · ');
    window.DangerConfirm.ask({ tier: 'medium', title: 'Cancel this lesson?', object: label || 'This lesson', summary: 'The lesson no longer counts for this member.',
      intent: 'I want to cancel this lesson',
      consequences: ['The lesson is marked CANCELLED and does not count in attendance.', 'The member\u2019s day result is recalculated.'],
      keeps: ['The original record and your reason stay in the history.'],
      reason: { label: 'Reason for cancelling (at least 3 letters)', required: true, minLength: 3, maxLength: 500 },
      phrase: 'CANCEL LESSON', confirmLabel: 'Cancel lesson' }).then(function (dc) { if (dc) sendCancel(b.getAttribute('data-cancel'), dc.reason); });
  });
  function sendCancel(sessionId, reason) {
    var fd = new FormData(); fd.append('action', 'cancel'); fd.append('session_id', sessionId); fd.append('reason', reason); fd.append('csrf_token', cfg.csrf);
    fetch(cfg.api, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (d) { toast(d.message || (d.status === 'success' ? 'Done' : 'Failed'), d.status === 'success'); if (d.status === 'success') load(); })
      .catch(function () { toast('Request failed.', false); });
  }
  $('la-form').addEventListener('submit', function (e) { e.preventDefault(); memberId = parseInt($('la-member').value, 10) || 0; offset = 0; if (memberId) { history.replaceState(null, '', '?member_id=' + memberId); } load(); });
  $('la-prev').addEventListener('click', function () { offset = Math.max(0, offset - PAGE); load(); });
  $('la-next').addEventListener('click', function () { offset += Math.max(shown.length, 1); load(); });
  if ($('la-more')) $('la-more').addEventListener('click', function () { load(true); });
  if ($('la-size')) $('la-size').addEventListener('change', function () {
    var n = parseInt($('la-size').value, 10); if ([25, 50, 100, 200].indexOf(n) < 0) return;
    PAGE = n; offset = 0; try { localStorage.setItem(LA_KEY, String(n)); } catch (e) { /* private mode */ }
    load();
  });
  load();
})();
</script>
</body>
</html>
