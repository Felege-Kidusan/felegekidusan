<?php
/**
 * ============================================================
 * Messages — two-way staff messaging (P72)
 * ============================================================
 * Thread-based messaging between permitted roles (the permission
 * matrix lives in NotificationCenterService — never here).
 * Layout: thread list + conversation pane; on phones a stacked
 * master-detail. Data from /admin/api_notifications.php — this
 * page holds no business logic.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['admin_logged_in'])) { header('Location: index.php'); exit; }

require_once __DIR__ . '/backend/services/NotificationCenterService.php';
use App\Services\NotificationCenterService;

$role     = (string)($_SESSION['admin_role'] ?? '');
$userId   = (int)($_SESSION['admin_id'] ?? 0);
$canMessage = NotificationCenterService::canMessage($role);
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
<title>Messages — <?= e(defined('SCHOOL_NAME_SHORT') ? SCHOOL_NAME_SHORT : 'School') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg: #f1f5f9; --card: #ffffff; --ink: #0f172a; --dim: #64748b;
    --line: #eef1f6; --ac: #059669; --ac-soft: #d1fae5;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; height: 100vh; display: flex; flex-direction: column; }

.top {
    background: linear-gradient(135deg, #0f766e, #0d9488);
    color: #fff; padding: 14px 20px; display: flex; align-items: center;
    justify-content: space-between; gap: 12px; flex: none; position: sticky; top: 0; z-index: 50;
    box-shadow: 0 2px 14px rgba(13,148,136,.35);
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

.main { flex: 1; display: flex; overflow: hidden; max-width: 1060px; width: 100%; margin: 0 auto; }

/* ── thread list ── */
.threads { width: 330px; flex: none; border-right: 1px solid var(--line); background: var(--card); display: flex; flex-direction: column; }
.threads-head { padding: 14px 16px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.threads-head h2 { font-size: .88rem; }
.btn {
    border: none; cursor: pointer; font-family: inherit; font-weight: 700;
    display: inline-flex; align-items: center; gap: 7px; border-radius: 11px;
    padding: 9px 14px; font-size: .78rem;
}
.btn-p { background: var(--ac); color: #fff; }
.btn-p:hover { background: #047857; }
.btn-o { background: #fff; color: #475569; border: 1px solid #e2e8f0; }
.btn-o:hover { background: #f8fafc; }
.tlist { flex: 1; overflow-y: auto; padding: 8px; }
.thread {
    padding: 12px 12px; border-radius: 12px; cursor: pointer; margin-bottom: 4px;
    display: flex; gap: 11px; align-items: flex-start; border: 1px solid transparent;
}
.thread:hover { background: #f1f5f9; }
.thread.on { background: var(--ac-soft); border-color: #a7f3d0; }
.th-ico { flex: none; width: 38px; height: 38px; border-radius: 50%; background: #e0f2fe; color: #0369a1; display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; }
.th-main { flex: 1; min-width: 0; }
.th-row { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
.th-t { font-size: .8rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.th-time { font-size: .64rem; color: #94a3b8; flex: none; }
.th-p { font-size: .68rem; color: var(--dim); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.th-last { font-size: .72rem; color: #475569; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.th-unread { background: var(--ac); color: #fff; border-radius: 9px; font-size: .62rem; font-weight: 700; padding: 1px 7px; margin-left: auto; }

/* ── conversation ── */
.conv { flex: 1; display: flex; flex-direction: column; background: var(--bg); min-width: 0; }
.conv-head { background: var(--card); border-bottom: 1px solid var(--line); padding: 13px 18px; display: none; align-items: center; gap: 10px; }
.conv-head.on { display: flex; }
.conv-head h3 { font-size: .9rem; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.conv-head .who { font-size: .68rem; color: var(--dim); }
.conv-body { flex: 1; overflow-y: auto; padding: 18px; }
.msg { max-width: 72%; margin-bottom: 10px; display: flex; flex-direction: column; }
.msg.mine { margin-left: auto; align-items: flex-end; }
.bubble { padding: 9px 13px; border-radius: 15px; font-size: .82rem; line-height: 1.55; word-break: break-word; white-space: pre-wrap; }
.msg:not(.mine) .bubble { background: var(--card); border: 1px solid var(--line); border-bottom-left-radius: 5px; }
.msg.mine .bubble { background: #047857; color: #fff; border-bottom-right-radius: 5px; }
.msg .meta { font-size: .62rem; color: #94a3b8; margin-top: 3px; }
.day-sep { text-align: center; font-size: .64rem; color: #94a3b8; margin: 14px 0; }
.conv-empty { flex: 1; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: .85rem; text-align: center; padding: 20px; }
.conv-empty i { display: block; font-size: 34px; margin-bottom: 12px; color: #cbd5e1; }
.conv-form { background: var(--card); border-top: 1px solid var(--line); padding: 12px 16px; display: none; gap: 10px; }
.conv-form.on { display: flex; }
.conv-form textarea {
    flex: 1; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 13px;
    font-size: .82rem; font-family: inherit; resize: none; height: 44px; max-height: 140px; line-height: 1.5;
}
.conv-form textarea:focus { outline: 2px solid #a7f3d0; border-color: var(--ac); }
.send { background: var(--ac); color: #fff; border: none; width: 44px; height: 44px; border-radius: 12px; font-size: 15px; cursor: pointer; flex: none; }
.send:hover { background: #047857; }
.send:disabled { background: #cbd5e1; cursor: default; }

/* ── composer modal ── */
.modal { position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 900; display: none; align-items: center; justify-content: center; padding: 16px; }
.modal.show { display: flex; }
.sheet { background: #fff; border-radius: 18px; width: 540px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 22px; }
.sheet h2 { font-size: 1rem; display: flex; align-items: center; gap: 9px; margin-bottom: 14px; }
.lbl { display: block; font-size: .7rem; font-weight: 700; color: #475569; margin: 13px 0 6px; text-transform: uppercase; letter-spacing: .03em; }
.inp, textarea.inp, select.inp {
    width: 100%; border: 1px solid #e2e8f0; border-radius: 11px; padding: 10px 12px;
    font-size: .84rem; font-family: inherit; background: #fff;
}
.inp:focus { outline: 2px solid #a7f3d0; border-color: var(--ac); }
textarea.inp { resize: vertical; min-height: 100px; }
select.inp[multiple] { min-height: 110px; }
.err { color: #dc2626; font-size: .76rem; margin-top: 10px; min-height: 1em; font-weight: 600; }
.sheet-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 18px; }

.toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: #0f172a; color: #fff; font-size: .8rem; padding: 11px 18px; border-radius: 12px; z-index: 999; display: none; }

/* ── phone: master-detail stack ── */
@media (max-width: 760px) {
    .main { position: relative; }
    .threads { width: 100%; border-right: none; }
    .conv {
        position: absolute; inset: 0; z-index: 20; transform: translateX(100%);
        transition: transform .22s ease; background: var(--bg);
    }
    .conv.on { transform: none; }
    .msg { max-width: 86%; }
}
</style>
</head>
<body>
<div class="top">
    <div>
        <h1><i class="fa-solid fa-comments"></i> Messages</h1>
        <div class="sub"><?= e($todayFormatted) ?> · <?= e(NotificationCenterService::ROLE_LABELS[$role] ?? $role) ?></div>
    </div>
    <div class="top-right">
        <?php include __DIR__ . '/components/notification_center.php'; ?><?= renderNotificationCenter() ?>
        <a class="back" href="/admin/dashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    </div>
</div>

<div class="main">
    <div class="threads">
        <div class="threads-head">
            <h2>Conversations</h2>
            <?php if ($canMessage): ?>
            <button class="btn btn-p" onclick="openCompose()"><i class="fa-solid fa-plus"></i> New</button>
            <?php endif; ?>
        </div>
        <div class="tlist" id="tlist"><div class="conv-empty" style="padding:30px"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
    </div>

    <div class="conv" id="conv">
        <div class="conv-empty" id="convEmpty">
            <div><i class="fa-regular fa-comment-dots"></i>Select a conversation to read it.<br>Messages stay between their participants.</div>
        </div>
        <div class="conv-head" id="convHead">
            <button class="btn btn-o" id="convBack" style="display:none" onclick="closeThread()"><i class="fa-solid fa-arrow-left"></i></button>
            <div style="flex:1;min-width:0">
                <h3 id="convTitle"></h3>
                <div class="who" id="convWho"></div>
            </div>
        </div>
        <div class="conv-body" id="convBody"></div>
        <form class="conv-form" id="convForm" onsubmit="return sendReply(event)">
            <textarea id="replyBox" placeholder="Write a reply…" maxlength="5000" required></textarea>
            <button type="submit" class="send" id="sendBtn"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
    </div>
</div>

<?php if ($canMessage): ?>
<div class="modal" id="composeModal" role="dialog" aria-modal="true">
    <div class="sheet">
        <h2><i class="fa-solid fa-comment" style="color:#0d9488"></i> New conversation</h2>
        <label class="lbl" for="cTo">To</label>
        <select class="inp" id="cTo" multiple size="6"></select>
        <div class="err" style="color:#64748b;font-weight:400;margin-top:6px">Hold Ctrl / Cmd to select several people.</div>
        <label class="lbl" for="cSubject">Subject</label>
        <input class="inp" id="cSubject" maxlength="200" placeholder="What is this about?">
        <label class="lbl" for="cBody">Message</label>
        <textarea class="inp" id="cBody" maxlength="5000" placeholder="Write your message…"></textarea>
        <div class="err" id="cErr"></div>
        <div class="sheet-actions">
            <button class="btn btn-o" onclick="closeCompose()">Cancel</button>
            <button class="btn btn-p" onclick="startConversation()"><i class="fa-solid fa-paper-plane"></i> Send</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"></div>

<script>
'use strict';
var CSRF = <?= json_encode($csrf) ?>;
var API = '/admin/api_notifications.php';
var activeThread = null;

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
    if (s < 3600) { return Math.floor(s / 60) + 'm'; }
    if (s < 86400) { return Math.floor(s / 3600) + 'h'; }
    if (s < 604800) { return Math.floor(s / 86400) + 'd'; }
    return new Date(t).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}
function timeHM(iso) {
    var t = Date.parse(String(iso).replace(' ', 'T'));
    if (isNaN(t)) { return ''; }
    return new Date(t).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}
function dayLabel(iso) {
    var t = Date.parse(String(iso).replace(' ', 'T'));
    if (isNaN(t)) { return ''; }
    var d = new Date(t), now = new Date();
    if (d.toDateString() === now.toDateString()) { return 'Today'; }
    var y = new Date(now.getTime() - 86400000);
    if (d.toDateString() === y.toDateString()) { return 'Yesterday'; }
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
function toast(msg, ok) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.style.background = ok ? '#059669' : '#dc2626'; t.style.display = 'block';
    setTimeout(function () { t.style.display = 'none'; }, 2600);
}
function initials(name) {
    name = String(name || '?').trim().split(/\s+/);
    return ((name[0] || ' ')[0] + (name.length > 1 ? name[name.length - 1][0] : '')).toUpperCase();
}

// ── thread list ─────────────────────────────────────────────
function loadThreads(keepActive) {
    get('threads').then(function (d) {
        var el = document.getElementById('tlist');
        var rows = (d && d.threads) || [];
        if (!rows.length) {
            el.innerHTML = '<div class="conv-empty" style="padding:30px"><div><i class="fa-regular fa-comment-dots"></i>No conversations yet.' +
                (<?= json_encode($canMessage) ?> ? '<br>Start one with “New”.' : '') + '</div></div>';
            return;
        }
        el.innerHTML = rows.map(function (t) {
            return '<div class="thread ' + (t.id == activeThread ? 'on' : '') + '" data-th="' + esc(t.id) + '">' +
                '<div class="th-ico">' + esc(initials(t.participants_label || '?')) + '</div>' +
                '<div class="th-main"><div class="th-row"><span class="th-t">' + esc(t.subject) + '</span>' +
                (t.unread_count > 0 ? '<span class="th-unread">' + (t.unread_count > 9 ? '9+' : t.unread_count) + '</span>'
                                     : '<span class="th-time">' + esc(relTime(t.last_message_at || t.created_at)) + '</span>') + '</div>' +
                '<div class="th-p">' + esc(t.participants_label || '') + '</div>' +
                '<div class="th-last">' + esc(t.last_body || '') + '</div></div></div>';
        }).join('');
        el.querySelectorAll('[data-th]').forEach(function (n) {
            n.addEventListener('click', function () { openThread(n.dataset.th, n.querySelector('.th-t').textContent, n.querySelector('.th-p').textContent); });
        });
    }).catch(function () {
        document.getElementById('tlist').innerHTML = '<div class="conv-empty" style="padding:30px"><i class="fa-solid fa-triangle-exclamation"></i>Could not load conversations.</div>';
    });
}

// ── conversation ────────────────────────────────────────────
function openThread(id, subject, who) {
    activeThread = id;
    document.querySelectorAll('.thread').forEach(function (n) { n.classList.toggle('on', n.dataset.th == id); });
    document.getElementById('convEmpty').style.display = 'none';
    document.getElementById('convHead').classList.add('on');
    document.getElementById('convTitle').textContent = subject;
    document.getElementById('convWho').textContent = who;
    document.getElementById('convForm').classList.add('on');
    if (window.matchMedia('(max-width: 760px)').matches) {
        document.getElementById('conv').classList.add('on');
        document.getElementById('convBack').style.display = '';
    } else {
        document.getElementById('convBack').style.display = 'none';
    }
    get('thread', '&id=' + encodeURIComponent(id)).then(function (d) {
        renderMessages((d && d.messages) || []);
        loadThreads(true);
    }).catch(function () { toast('Could not load the conversation.', false); });
}
function closeThread() {
    activeThread = null;
    document.getElementById('conv').classList.remove('on');
    document.getElementById('convEmpty').style.display = '';
    document.getElementById('convHead').classList.remove('on');
    document.getElementById('convForm').classList.remove('on');
    document.getElementById('convBody').innerHTML = '';
}
function renderMessages(msgs) {
    var el = document.getElementById('convBody');
    var html = '', lastDay = '';
    msgs.forEach(function (m) {
        var day = dayLabel(m.created_at);
        if (day !== lastDay) { html += '<div class="day-sep">' + esc(day) + '</div>'; lastDay = day; }
        html += '<div class="msg ' + (m.mine ? 'mine' : '') + '">' +
            (m.mine ? '' : '<div class="meta" style="margin-bottom:2px"><b>' + esc(m.sender_name) + '</b> · ' + esc(m.sender_label) + '</div>') +
            '<div class="bubble">' + esc(m.body) + '</div>' +
            '<div class="meta">' + esc(timeHM(m.created_at)) + (m.mine ? ' · Sent' : '') + '</div></div>';
    });
    el.innerHTML = html || '<div class="conv-empty"><i class="fa-regular fa-comment"></i>No messages.</div>';
    el.scrollTop = el.scrollHeight;
}
function sendReply(e) {
    e.preventDefault();
    if (!activeThread) { return false; }
    var box = document.getElementById('replyBox');
    var body = box.value.trim();
    if (!body) { return false; }
    var btn = document.getElementById('sendBtn');
    btn.disabled = true;
    post('send_message', { thread_id: activeThread, body: body }).then(function (d) {
        btn.disabled = false;
        if (d.status === 'success') {
            box.value = '';
            get('thread', '&id=' + encodeURIComponent(activeThread)).then(function (r) { renderMessages((r && r.messages) || []); });
            loadThreads(true);
        } else { toast(d.message || 'Could not send.', false); }
    }).catch(function () { btn.disabled = false; toast('Network error.', false); });
    return false;
}

<?php if ($canMessage): ?>
// ── new conversation ────────────────────────────────────────
function openCompose() {
    var sel = document.getElementById('cTo');
    if (!sel.options.length) {
        get('partners').then(function (d) {
            sel.innerHTML = (d.partners || []).map(function (p) {
                return '<option value="' + p.id + '">' + esc(p.label) + '</option>';
            }).join('');
            if (!sel.options.length) { sel.innerHTML = '<option disabled>Nobody to message yet.</option>'; }
        });
    }
    document.getElementById('composeModal').classList.add('show');
}
function closeCompose() { document.getElementById('composeModal').classList.remove('show'); }
document.getElementById('composeModal').addEventListener('click', function (e) { if (e.target === this) { closeCompose(); } });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeCompose(); } });
function startConversation() {
    var err = document.getElementById('cErr'); err.textContent = '';
    var sel = document.getElementById('cTo');
    var to = Array.prototype.map.call(sel.selectedOptions || [], function (o) { return o.value; });
    if (!to.length) { err.textContent = 'Choose at least one recipient.'; return; }
    post('thread_start', {
        to: to.join(','),
        subject: document.getElementById('cSubject').value,
        body: document.getElementById('cBody').value
    }).then(function (d) {
        if (d.status === 'success') {
            closeCompose();
            document.getElementById('cSubject').value = '';
            document.getElementById('cBody').value = '';
            toast('Conversation started ✓', true);
            activeThread = null;
            loadThreads();
        } else { err.textContent = d.message || 'Could not start the conversation.'; }
    }).catch(function () { err.textContent = 'Network error — try again.'; });
}
<?php endif; ?>

loadThreads();
</script>
</body>
</html>
