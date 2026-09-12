<?php
/**
 * ============================================================
 * Notification Center — full history page (P72)
 * ============================================================
 * Every staff role lands here from the bell ("See all"). Shows:
 *   • Announcements addressed to me (full text, pinned first)
 *   • My complete ALERT history (read + unread, filterable)
 *   • The announcement composer (only roles that may announce)
 * Read state is per-user (notification_reads). Data comes from
 * /admin/api_notifications.php — this page holds no business logic.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['admin_logged_in'])) { header('Location: index.php'); exit; }

require_once __DIR__ . '/backend/services/NotificationCenterService.php';
use App\Services\NotificationCenterService;

$role     = (string)($_SESSION['admin_role'] ?? '');
$userId   = (int)($_SESSION['admin_id'] ?? 0);
$userName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User';
$canAnnounce = NotificationCenterService::canAnnounce($role);
$csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
$todayFormatted = date('F j, Y');
if (function_exists('ethio_date_format')) {
    try { $todayFormatted = ethio_date_format(new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')), 'F j, Y'); } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e($csrf) ?>">
<title>Notification Center — <?= e(defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg: #f1f5f9; --card: #ffffff; --ink: #0f172a; --dim: #64748b;
    --line: #eef1f6; --ac: #059669; --ac-soft: #d1fae5;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; min-height: 100vh; }

.top {
    background: linear-gradient(135deg, #047857, #059669);
    color: #fff; padding: 14px 20px; display: flex; align-items: center;
    justify-content: space-between; gap: 12px; position: sticky; top: 0; z-index: 50;
    box-shadow: 0 2px 14px rgba(4,120,87,.35);
}
.top h1 { font-size: 1.02rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.top .sub { font-size: .72rem; opacity: .85; margin-top: 2px; }
.top-right { display: flex; align-items: center; gap: 10px; }
.back {
    color: #fff; text-decoration: none; font-size: .78rem; font-weight: 600;
    background: rgba(255,255,255,.15); padding: 8px 14px; border-radius: 10px;
    display: inline-flex; gap: 7px; align-items: center;
}
.back:hover { background: rgba(255,255,255,.28); }

.wrap { max-width: 860px; margin: 0 auto; padding: 22px 16px 60px; }

.toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 18px; }
.seg { display: inline-flex; background: #e2e8f0; border-radius: 12px; padding: 3px; }
.seg button {
    border: none; background: none; padding: 8px 16px; border-radius: 10px;
    font-size: .78rem; font-weight: 600; color: #475569; cursor: pointer; font-family: inherit;
}
.seg button.is-on { background: #fff; color: #047857; box-shadow: 0 1px 4px rgba(15,23,42,.12); }
.spacer { flex: 1; }
.btn {
    border: none; cursor: pointer; font-family: inherit; font-weight: 700;
    display: inline-flex; align-items: center; gap: 8px; border-radius: 12px;
    padding: 10px 16px; font-size: .8rem;
}
.btn-p { background: var(--ac); color: #fff; }
.btn-p:hover { background: #047857; }
.btn-o { background: #fff; color: #475569; border: 1px solid #e2e8f0; }
.btn-o:hover { background: #f8fafc; }

.sec-title {
    font-size: .82rem; font-weight: 700; color: var(--dim); text-transform: uppercase;
    letter-spacing: .04em; margin: 26px 2px 10px; display: flex; align-items: center; gap: 8px;
}
.card {
    background: var(--card); border: 1px solid var(--line); border-radius: 16px;
    padding: 16px; margin-bottom: 10px;
}
.card.unread { border-color: #a7f3d0; background: #f6fef9; }
.ann-head { display: flex; align-items: flex-start; gap: 12px; }
.ann-ico {
    flex: none; width: 40px; height: 40px; border-radius: 12px; background: var(--ac-soft);
    color: #047857; display: flex; align-items: center; justify-content: center; font-size: 16px;
}
.ann-ico.hi { background: #fef3c7; color: #b45309; }
.ann-ico.ur { background: #fee2e2; color: #b91c1c; }
.ann-t { font-size: .95rem; font-weight: 700; flex: 1; }
.ann-meta { font-size: .7rem; color: var(--dim); margin-top: 3px; }
.ann-body { font-size: .85rem; color: #334155; line-height: 1.65; margin-top: 10px; white-space: pre-wrap; word-break: break-word; }
.chip {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px;
    font-size: .66rem; font-weight: 700; background: #f1f5f9; color: #475569;
}
.chip.ur { background: #fee2e2; color: #b91c1c; }
.chip.hi { background: #fef3c7; color: #b45309; }

.item { display: flex; gap: 12px; padding: 14px 16px; background: var(--card); border: 1px solid var(--line); border-radius: 14px; margin-bottom: 8px; cursor: pointer; transition: background .15s; }
.item:hover { background: #f8fafc; }
.item.unread { background: #f6fef9; border-color: #a7f3d0; }
.item .ico { flex: none; width: 38px; height: 38px; border-radius: 11px; background: #dbeafe; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 15px; }
.item .t { font-size: .84rem; font-weight: 600; }
.item.unread .t { font-weight: 800; }
.item .m { font-size: .78rem; color: #475569; margin-top: 3px; line-height: 1.5; }
.item .tm { font-size: .68rem; color: #94a3b8; margin-top: 5px; }

.empty { text-align: center; padding: 46px 16px; color: #94a3b8; font-size: .85rem; background: var(--card); border: 1px dashed #e2e8f0; border-radius: 16px; }
.empty i { display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1; }
.more { display: block; width: 100%; text-align: center; margin-top: 12px; }

/* ── composer modal ── */
.modal { position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 900; display: none; align-items: center; justify-content: center; padding: 16px; }
.modal.show { display: flex; }
.sheet {
    background: #fff; border-radius: 18px; width: 560px; max-width: 100%;
    max-height: 90vh; overflow-y: auto; padding: 22px;
}
.sheet h2 { font-size: 1rem; display: flex; align-items: center; gap: 9px; margin-bottom: 16px; }
.lbl { display: block; font-size: .7rem; font-weight: 700; color: #475569; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: .03em; }
.inp, textarea.inp, select.inp {
    width: 100%; border: 1px solid #e2e8f0; border-radius: 11px; padding: 10px 12px;
    font-size: .84rem; font-family: inherit; background: #fff; color: var(--ink);
}
.inp:focus { outline: 2px solid #a7f3d0; border-color: var(--ac); }
textarea.inp { resize: vertical; min-height: 110px; }
.pick { display: flex; flex-wrap: wrap; gap: 7px; }
.pick .p {
    border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 999px; padding: 6px 13px;
    font-size: .74rem; font-weight: 600; color: #475569; cursor: pointer; user-select: none;
}
.pick .p.on { background: var(--ac-soft); border-color: #6ee7b7; color: #065f46; }
.row2 { display: flex; gap: 12px; flex-wrap: wrap; }
.row2 > * { flex: 1; min-width: 150px; }
.sheet-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 20px; }
.err { color: #dc2626; font-size: .76rem; margin-top: 10px; min-height: 1em; font-weight: 600; }
select.inp[multiple] { min-height: 96px; }
@media (max-width: 640px) {
    .top .sub { display: none; }
    .sheet { padding: 16px; }
}
.toast {
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
    background: #0f172a; color: #fff; font-size: .8rem; padding: 11px 18px;
    border-radius: 12px; z-index: 999; display: none; box-shadow: 0 8px 30px rgba(15,23,42,.4);
}
</style>
</head>
<body>
<div class="top">
    <div>
        <h1><i class="fa-solid fa-bell"></i> Notification Center</h1>
        <div class="sub"><?= e($todayFormatted) ?> · <?= e(NotificationCenterService::ROLE_LABELS[$role] ?? $role) ?></div>
    </div>
    <div class="top-right">
        <?php include __DIR__ . '/components/notification_center.php'; ?><?= renderNotificationCenter() ?>
        <a class="back" href="/admin/dashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    </div>
</div>

<div class="wrap">
    <div class="toolbar">
        <div class="seg" role="tablist">
            <button id="fAll" class="is-on" onclick="setFilter('all')">All</button>
            <button id="fUnread" onclick="setFilter('unread')">Unread <span id="unreadN" hidden></span></button>
        </div>
        <div class="spacer"></div>
        <?php if ($canAnnounce): ?>
        <button class="btn btn-p" onclick="openComposer()"><i class="fa-solid fa-bullhorn"></i> New announcement</button>
        <?php endif; ?>
    </div>

    <div class="sec-title"><i class="fa-solid fa-bullhorn"></i> Announcements</div>
    <div id="annList"><div class="empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div></div>

    <div class="sec-title"><i class="fa-solid fa-bell"></i> Alerts history</div>
    <div id="feedList"><div class="empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div></div>
    <button class="btn btn-o more" id="moreBtn" hidden onclick="loadFeed()">Load older alerts</button>
</div>

<?php if ($canAnnounce): ?>
<div class="modal" id="composer" role="dialog" aria-modal="true" aria-labelledby="cmpTitle">
    <div class="sheet">
        <h2 id="cmpTitle"><i class="fa-solid fa-bullhorn" style="color:#059669"></i> New announcement</h2>
        <label class="lbl" for="cmpTitleInp">Title</label>
        <input class="inp" id="cmpTitleInp" maxlength="200" placeholder="e.g. Schedule change this Friday">
        <label class="lbl" for="cmpBody">Message</label>
        <textarea class="inp" id="cmpBody" maxlength="5000" placeholder="Write the announcement…"></textarea>
        <div class="row2" style="margin-top:4px">
            <div>
                <label class="lbl" for="cmpPriority">Priority</label>
                <select class="inp" id="cmpPriority">
                    <option value="normal">Normal</option>
                    <option value="high">High — important</option>
                    <option value="urgent">Urgent — needs attention now</option>
                </select>
            </div>
            <div>
                <label class="lbl">Audience</label>
                <div class="pick" id="cmpAudience">
                    <div class="p on" data-a="roles">Whole groups</div>
                    <div class="p" data-a="users">Selected people</div>
                </div>
            </div>
        </div>
        <div id="cmpRolesWrap">
            <label class="lbl">Groups</label>
            <div class="pick" id="cmpRoles"></div>
        </div>
        <div id="cmpUsersWrap" hidden>
            <label class="lbl" for="cmpUsers">People</label>
            <select class="inp" id="cmpUsers" multiple size="6"></select>
            <div class="err" style="margin-top:6px;color:#64748b;font-weight:400">Hold Ctrl / Cmd to select several.</div>
        </div>
        <div class="err" id="cmpErr"></div>
        <div class="sheet-actions">
            <button class="btn btn-o" onclick="closeComposer()">Cancel</button>
            <button class="btn btn-p" onclick="publish()"><i class="fa-solid fa-paper-plane"></i> Publish</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"></div>

<script>
'use strict';
var CSRF = <?= json_encode($csrf) ?>;
var API = '/admin/api_notifications.php';
var feedOffset = 0, filter = 'all', feedTotal = 0, targets = null;

function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function post(action, fields) {
    var b = new URLSearchParams(); b.set('action', action); b.set('csrf_token', CSRF);
    if (fields) { Object.keys(fields).forEach(function (k) { b.set(k, fields[k]); }); }
    return fetch(API, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: b.toString() }).then(function (r) { return r.json(); });
}
function get(action, qs) {
    return fetch(API + '?action=' + encodeURIComponent(action) + (qs || ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); });
}
function relTime(iso) {
    if (!iso) { return ''; }
    var t = Date.parse(String(iso).replace(' ', 'T')); if (isNaN(t)) { return ''; }
    var s = Math.max(0, (Date.now() - t) / 1000);
    if (s < 60) { return 'just now'; }
    if (s < 3600) { return Math.floor(s / 60) + 'm ago'; }
    if (s < 86400) { return Math.floor(s / 3600) + 'h ago'; }
    if (s < 604800) { return Math.floor(s / 86400) + 'd ago'; }
    return new Date(t).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function toast(msg, ok) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.style.background = ok ? '#059669' : '#dc2626'; t.style.display = 'block';
    setTimeout(function () { t.style.display = 'none'; }, 2600);
}

// ── announcements ───────────────────────────────────────────
function loadAnnouncements() {
    get('announcements', '&limit=50').then(function (d) {
        var el = document.getElementById('annList');
        var rows = (d && d.announcements) || [];
        if (!rows.length) { el.innerHTML = '<div class="empty"><i class="fa-solid fa-bullhorn"></i>No announcements right now.</div>'; return; }
        el.innerHTML = rows.map(function (a) {
            var p = a.priority === 'urgent' ? 'ur' : a.priority === 'high' ? 'hi' : '';
            return '<div class="card ' + (a.is_unread ? 'unread' : '') + '" data-ann="' + esc(a.id) + '">' +
                '<div class="ann-head"><div class="ann-ico ' + p + '"><i class="fa-solid fa-bullhorn"></i></div>' +
                '<div style="flex:1"><div class="ann-t">' + (a.is_pinned == 1 ? '📌 ' : '') + esc(a.title) + '</div>' +
                '<div class="ann-meta">' + esc(a.author_label || a.source_dept) + (a.author_name ? ' · ' + esc(a.author_name) : '') +
                ' · ' + esc(relTime(a.created_at)) + '</div></div>' +
                (a.priority !== 'normal' ? '<span class="chip ' + p + '">' + esc(a.priority) + '</span>' : '') +
                (a.is_unread ? '<span class="chip" style="background:#d1fae5;color:#065f46">new</span>' : '') +
                '</div><div class="ann-body">' + esc(a.body) + '</div></div>';
        }).join('');
        el.querySelectorAll('[data-ann]').forEach(function (c) {
            c.addEventListener('click', function () {
                if (c.classList.contains('unread')) {
                    post('announcement_read', { id: c.dataset.ann }).then(function () {
                        c.classList.remove('unread');
                        var n = c.querySelector('.chip:last-child'); if (n && n.textContent === 'new') { n.remove(); }
                        refreshUnreadBadge();
                    });
                }
            });
        });
    }).catch(function () {
        document.getElementById('annList').innerHTML = '<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i>Could not load announcements.</div>';
    });
}

// ── alerts feed ─────────────────────────────────────────────
var ICONS = [
    [/member/, 'fa-user'], [/class|enroll/, 'fa-school'], [/attendance/, 'fa-clipboard-check'],
    [/grade|marks/, 'fa-graduation-cap'], [/task/, 'fa-list-check'], [/role/, 'fa-id-badge'],
    [/document|share/, 'fa-file-lines'], [/sync|change/, 'fa-rotate']
];
function iconFor(t) { t = String(t || ''); for (var i = 0; i < ICONS.length; i++) { if (ICONS[i][0].test(t)) { return ICONS[i][1]; } } return 'fa-bell'; }
function loadFeed() {
    get('feed', '&limit=25&offset=' + feedOffset + (filter === 'unread' ? '&unread=1' : '')).then(function (d) {
        var el = document.getElementById('feedList');
        var rows = (d && d.rows) || [];
        feedTotal = (d && d.total) || 0;
        if (!feedOffset) {
            el.innerHTML = rows.length ? '' : '<div class="empty"><i class="fa-solid fa-bell-slash"></i>' +
                (filter === 'unread' ? 'Nothing unread — you are all caught up.' : 'No alerts yet.') + '</div>';
        }
        rows.forEach(function (n) {
            var div = document.createElement('div');
            div.className = 'item ' + (n.is_unread ? 'unread' : '');
            div.innerHTML = '<div class="ico"><i class="fa-solid ' + iconFor(n.type) + '"></i></div>' +
                '<div style="flex:1;min-width:0"><div class="t">' + esc(n.title) + '</div>' +
                '<div class="m">' + esc(n.message) + '</div>' +
                '<div class="tm">' + esc(relTime(n.created_at)) + (n.source_dept ? ' · ' + esc(n.source_dept) : '') +
                (n.priority === 'urgent' || n.priority === 'high' ? ' · <b style="color:#b45309">' + esc(n.priority) + '</b>' : '') + '</div></div>';
            if (n.is_unread) {
                div.addEventListener('click', function () {
                    post('mark_read', { id: n.id }).then(function () {
                        div.classList.remove('unread'); refreshUnreadBadge();
                    });
                });
            }
            el.appendChild(div);
        });
        feedOffset += rows.length;
        document.getElementById('moreBtn').hidden = !(feedOffset < (filter === 'unread' ? (d && d.unread) || 0 : feedTotal));
        var un = document.getElementById('unreadN');
        un.hidden = !((d && d.unread) > 0); un.textContent = (d && d.unread) || 0;
    }).catch(function () {
        document.getElementById('feedList').innerHTML = '<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i>Could not load alerts.</div>';
    });
}
function setFilter(f) {
    filter = f; feedOffset = 0;
    document.getElementById('fAll').classList.toggle('is-on', f === 'all');
    document.getElementById('fUnread').classList.toggle('is-on', f === 'unread');
    document.getElementById('feedList').innerHTML = '';
    loadFeed();
}
function refreshUnreadBadge() { get('summary').then(function (d) { if (d.status === 'success') { var un = document.getElementById('unreadN'); un.hidden = !(d.summary.alerts > 0); un.textContent = d.summary.alerts; } }).catch(function () {}); }

<?php if ($canAnnounce): ?>
// ── composer ────────────────────────────────────────────────
function openComposer() {
    document.getElementById('composer').classList.add('show');
    if (!targets) {
        get('targets').then(function (d) {
            targets = d;
            var rp = document.getElementById('cmpRoles');
            rp.innerHTML = Object.keys(d.roles || {}).map(function (r) {
                return '<div class="p" data-role="' + esc(r) + '">' + esc(d.roles[r]) + '</div>';
            }).join('');
            rp.querySelectorAll('.p').forEach(function (p) {
                p.addEventListener('click', function () { p.classList.toggle('on'); });
            });
            var us = document.getElementById('cmpUsers');
            us.innerHTML = (d.users || []).map(function (u) {
                return '<option value="' + u.id + '">' + esc(u.label) + '</option>';
            }).join('');
        });
    }
}
function closeComposer() { document.getElementById('composer').classList.remove('show'); }
document.getElementById('composer').addEventListener('click', function (e) { if (e.target === this) { closeComposer(); } });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeComposer(); } });
document.querySelectorAll('#cmpAudience .p').forEach(function (p) {
    p.addEventListener('click', function () {
        document.querySelectorAll('#cmpAudience .p').forEach(function (q) { q.classList.remove('on'); });
        p.classList.add('on');
        document.getElementById('cmpRolesWrap').hidden = p.dataset.a !== 'roles';
        document.getElementById('cmpUsersWrap').hidden = p.dataset.a !== 'users';
    });
});
function publish() {
    var err = document.getElementById('cmpErr'); err.textContent = '';
    var audience = document.querySelector('#cmpAudience .p.on').dataset.a;
    var roles = [], users = [];
    if (audience === 'roles') {
        document.querySelectorAll('#cmpRoles .p.on').forEach(function (p) { roles.push(p.dataset.role); });
        if (!roles.length) { err.textContent = 'Choose at least one group.'; return; }
    } else {
        var sel = document.getElementById('cmpUsers');
        Array.prototype.forEach.call(sel.selectedOptions || [], function (o) { users.push(o.value); });
        if (!users.length) { err.textContent = 'Choose at least one recipient.'; return; }
    }
    post('compose', {
        title: document.getElementById('cmpTitleInp').value,
        body: document.getElementById('cmpBody').value,
        priority: document.getElementById('cmpPriority').value,
        audience: audience,
        roles: roles.join(','),
        user_ids: users.join(',')
    }).then(function (d) {
        if (d.status === 'success') {
            closeComposer();
            document.getElementById('cmpTitleInp').value = '';
            document.getElementById('cmpBody').value = '';
            toast('Announcement published ✓', true);
            loadAnnouncements();
        } else { err.textContent = d.message || 'Could not publish.'; }
    }).catch(function () { err.textContent = 'Network error — try again.'; });
}
<?php endif; ?>

loadAnnouncements();
loadFeed();
<?php if ($canAnnounce): ?>
if (location.hash === '#compose') { openComposer(); }
<?php endif; ?>
</script>
</body>
</html>
