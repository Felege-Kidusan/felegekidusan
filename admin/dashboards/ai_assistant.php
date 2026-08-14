<?php
/**
 * AI Assistant Dashboard
 * Chat with AI about your school data — powered by Google Gemini (Free)
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_id'])) { header('Location: ../index.php'); exit; }

$fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
$userRole = $_SESSION['admin_role'] ?? '';
$isSuperAdmin = $userRole === 'super_admin';
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Assistant — <?= SCHOOL_NAME_SHORT ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap');
        :root{--bg:#0a0e17;--bg2:#111827;--bg3:#1a2234;--surface:#1e293b;--border:#2d3a4f;--text:#e2e8f0;--text2:#94a3b8;--accent:#22c55e;--accent2:#10b981;--ai-bg:rgba(16,185,129,.08);--ai-border:rgba(16,185,129,.2);--user-bg:rgba(59,130,246,.08);--user-border:rgba(59,130,246,.2);--blue:#3b82f6;--purple:#8b5cf6;--amber:#f59e0b;--red:#ef4444}
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'IBM Plex Sans',-apple-system,sans-serif;background:var(--bg);color:var(--text);height:100vh;display:flex;flex-direction:column;overflow:hidden}

        /* ── Top Bar ── */
        .topbar{display:flex;align-items:center;justify-content:space-between;padding:.6rem 1.2rem;background:var(--bg2);border-bottom:1px solid var(--border);flex-shrink:0;z-index:10}
        .topbar-left{display:flex;align-items:center;gap:.8rem}
        .back-btn{color:var(--text2);text-decoration:none;font-size:.85rem;display:flex;align-items:center;gap:.4rem;padding:.4rem .6rem;border-radius:.4rem;transition:all .15s}
        .back-btn:hover{background:rgba(255,255,255,.05);color:var(--text)}
        .topbar-title{display:flex;align-items:center;gap:.5rem}
        .ai-icon{width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,var(--accent2),var(--blue));display:flex;align-items:center;justify-content:center;font-size:.9rem;color:#fff}
        .topbar-title h1{font-size:.95rem;font-weight:600}
        .badge{font-size:.6rem;padding:.15rem .4rem;border-radius:99px;background:rgba(16,185,129,.15);color:var(--accent2);font-weight:600;letter-spacing:.3px}
        .topbar-right{display:flex;align-items:center;gap:.5rem}
        .topbar-btn{background:none;border:1px solid var(--border);color:var(--text2);padding:.35rem .6rem;border-radius:.4rem;font-size:.75rem;cursor:pointer;transition:all .15s;font-family:inherit;display:flex;align-items:center;gap:.3rem}
        .topbar-btn:hover{background:rgba(255,255,255,.05);color:var(--text)}

        /* ── Layout ── */
        .main-layout{display:flex;flex:1;overflow:hidden}

        /* ── Sidebar ── */
        .sidebar{width:260px;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0;overflow-y:auto}
        .sidebar-section{padding:.8rem}
        .sidebar-section-title{font-size:.65rem;text-transform:uppercase;letter-spacing:.8px;color:var(--text2);font-weight:600;margin-bottom:.5rem}
        .insight-btn,.report-btn{width:100%;padding:.55rem .65rem;border:1px solid var(--border);background:var(--surface);color:var(--text);border-radius:.5rem;font-size:.78rem;cursor:pointer;text-align:left;transition:all .15s;font-family:inherit;display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem}
        .insight-btn:hover{border-color:var(--accent2);background:var(--ai-bg)}
        .insight-btn i{width:16px;font-size:.75rem}
        .report-btn{border-color:var(--ai-border);background:var(--ai-bg);color:var(--accent2);font-weight:500}
        .report-btn:hover{background:rgba(16,185,129,.15);border-color:var(--accent2)}
        .report-btn i{width:16px;font-size:.75rem}
        .ic-green{color:var(--accent)}.ic-blue{color:var(--blue)}.ic-purple{color:var(--purple)}.ic-amber{color:var(--amber)}.ic-red{color:var(--red)}

        /* ── Setup Panel ── */
        .setup-panel{background:var(--surface);border:1px solid var(--border);border-radius:.6rem;padding:.8rem;margin-top:.5rem}
        .setup-panel label{font-size:.75rem;color:var(--text2);display:block;margin-bottom:.3rem}
        .setup-panel input{width:100%;padding:.4rem .5rem;background:var(--bg);border:1px solid var(--border);border-radius:.4rem;color:var(--text);font-family:'IBM Plex Mono',monospace;font-size:.75rem;margin-bottom:.4rem}
        .setup-panel input:focus{outline:none;border-color:var(--accent2)}
        .save-key-btn{width:100%;padding:.4rem;background:linear-gradient(135deg,var(--accent2),var(--blue));border:none;border-radius:.4rem;color:#fff;font-size:.78rem;font-weight:600;cursor:pointer;font-family:inherit}
        .save-key-btn:hover{opacity:.9}
        .setup-help{font-size:.68rem;color:var(--text2);margin-top:.4rem;line-height:1.5}
        .setup-help a{color:var(--blue);text-decoration:none}
        .setup-help a:hover{text-decoration:underline}

        /* ── Chat Area ── */
        .chat-area{flex:1;display:flex;flex-direction:column;overflow:hidden}
        .chat-messages{flex:1;overflow-y:auto;padding:1rem 1.5rem;scroll-behavior:smooth}

        .message{max-width:85%;margin-bottom:1rem;animation:msgIn .3s ease}
        @keyframes msgIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
        .message.user{margin-left:auto}
        .message.assistant{margin-right:auto}
        .msg-header{display:flex;align-items:center;gap:.35rem;margin-bottom:.3rem;font-size:.7rem;color:var(--text2)}
        .msg-header .avatar{width:20px;height:20px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:#fff}
        .avatar.ai-av{background:linear-gradient(135deg,var(--accent2),var(--blue))}
        .avatar.user-av{background:linear-gradient(135deg,var(--blue),var(--purple))}
        .msg-bubble{padding:.75rem 1rem;border-radius:.75rem;font-size:.85rem;line-height:1.7}
        .message.user .msg-bubble{background:var(--user-bg);border:1px solid var(--user-border);border-bottom-right-radius:.2rem}
        .message.assistant .msg-bubble{background:var(--ai-bg);border:1px solid var(--ai-border);border-bottom-left-radius:.2rem}

        /* Markdown in AI responses */
        .msg-bubble h1,.msg-bubble h2,.msg-bubble h3{margin:.6rem 0 .3rem;font-weight:700}
        .msg-bubble h1{font-size:1.05rem}
        .msg-bubble h2{font-size:.95rem;color:var(--accent)}
        .msg-bubble h3{font-size:.88rem;color:var(--blue)}
        .msg-bubble p{margin:.3rem 0}
        .msg-bubble ul,.msg-bubble ol{padding-left:1.2rem;margin:.3rem 0}
        .msg-bubble li{margin-bottom:.2rem}
        .msg-bubble strong{color:var(--text);font-weight:600}
        .msg-bubble code{background:rgba(0,0,0,.3);padding:.1rem .3rem;border-radius:.25rem;font-family:'IBM Plex Mono',monospace;font-size:.8rem}
        .msg-bubble pre{background:rgba(0,0,0,.3);padding:.6rem;border-radius:.4rem;overflow-x:auto;margin:.4rem 0}
        .msg-bubble pre code{padding:0;background:none}
        .msg-bubble table{border-collapse:collapse;width:100%;margin:.5rem 0;font-size:.8rem}
        .msg-bubble th,.msg-bubble td{padding:.35rem .5rem;border:1px solid var(--border);text-align:left}
        .msg-bubble th{background:rgba(255,255,255,.05);font-weight:600}
        .msg-bubble hr{border:none;border-top:1px solid var(--border);margin:.6rem 0}

        .msg-actions{display:flex;gap:.4rem;margin-top:.4rem}
        .msg-action-btn{background:none;border:1px solid var(--border);color:var(--text2);padding:.2rem .5rem;border-radius:.3rem;font-size:.68rem;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:.25rem;transition:all .15s}
        .msg-action-btn:hover{background:rgba(255,255,255,.05);color:var(--text)}

        /* Welcome state */
        .welcome{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem;text-align:center}
        .welcome-icon{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,var(--accent2),var(--blue));display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;margin-bottom:1rem}
        .welcome h2{font-size:1.2rem;margin-bottom:.4rem}
        .welcome p{font-size:.85rem;color:var(--text2);max-width:420px;line-height:1.6}
        .welcome-suggestions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1.2rem;justify-content:center;max-width:550px}
        .suggest-btn{padding:.45rem .75rem;border:1px solid var(--border);background:var(--surface);color:var(--text2);border-radius:99px;font-size:.78rem;cursor:pointer;transition:all .15s;font-family:inherit}
        .suggest-btn:hover{border-color:var(--accent2);color:var(--text);background:var(--ai-bg)}

        /* Typing indicator */
        .typing{display:none;align-items:center;gap:.4rem;padding:.5rem 0;color:var(--text2);font-size:.8rem;max-width:85%}
        .typing.active{display:flex}
        .typing-dots{display:flex;gap:3px}
        .typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--accent2);animation:blink 1.4s infinite}
        .typing-dots span:nth-child(2){animation-delay:.2s}
        .typing-dots span:nth-child(3){animation-delay:.4s}
        @keyframes blink{0%,80%,100%{opacity:.3}40%{opacity:1}}

        /* Input Area */
        .input-area{padding:.8rem 1.5rem;border-top:1px solid var(--border);background:var(--bg2);flex-shrink:0}
        .input-row{display:flex;gap:.5rem;align-items:flex-end}
        .input-wrapper{flex:1;position:relative}
        #chatInput{width:100%;padding:.65rem .8rem;padding-right:2.5rem;background:var(--surface);border:1px solid var(--border);border-radius:.6rem;color:var(--text);font-size:.85rem;font-family:inherit;resize:none;min-height:42px;max-height:150px;line-height:1.5}
        #chatInput:focus{outline:none;border-color:var(--accent2)}
        #chatInput::placeholder{color:var(--text2);opacity:.6}
        .send-btn{position:absolute;right:.4rem;bottom:.35rem;width:32px;height:32px;border-radius:.4rem;border:none;background:linear-gradient(135deg,var(--accent2),var(--blue));color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.85rem;transition:all .15s}
        .send-btn:hover{opacity:.85;transform:scale(1.05)}
        .send-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}
        .input-hint{font-size:.68rem;color:var(--text2);margin-top:.3rem;opacity:.6}

        .no-key-banner{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);border-radius:.5rem;padding:.6rem .8rem;font-size:.8rem;color:var(--amber);display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem}

        @media(max-width:768px){
            .sidebar{display:none}
            .chat-messages{padding:.8rem}
            .message{max-width:95%}
            .input-area{padding:.6rem .8rem}
            .topbar{padding:.5rem .8rem}
        }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css">
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <a href="/admin/dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <div class="topbar-title">
            <div class="ai-icon"><i class="fa-solid fa-robot"></i></div>
            <h1>AI Assistant</h1>
            <span class="badge">GEMINI FREE</span>
        </div>
    </div>
    <div class="topbar-right">
        <button class="topbar-btn" onclick="clearChat()"><i class="fa-solid fa-trash-can"></i> Clear</button>
        <button class="topbar-btn" onclick="exportChat()"><i class="fa-solid fa-download"></i> Export</button>
    </div>
</div>

<div class="main-layout">
    <div class="sidebar">
        <div class="sidebar-section">
            <div class="sidebar-section-title"><i class="fa-solid fa-bolt"></i> Quick Insights</div>
            <button class="insight-btn" onclick="quickInsight('overview')"><i class="fa-solid fa-gauge-high ic-green"></i> School Overview</button>
            <button class="insight-btn" onclick="quickInsight('members')"><i class="fa-solid fa-users ic-blue"></i> Member Analysis</button>
            <button class="insight-btn" onclick="quickInsight('attendance')"><i class="fa-solid fa-calendar-check ic-purple"></i> Attendance Patterns</button>
            <button class="insight-btn" onclick="quickInsight('education')"><i class="fa-solid fa-graduation-cap ic-amber"></i> Education Status</button>
            <button class="insight-btn" onclick="quickInsight('finance')"><i class="fa-solid fa-coins ic-green"></i> Finance Summary</button>
        </div>
        <div class="sidebar-section">
            <div class="sidebar-section-title"><i class="fa-solid fa-file-lines"></i> Generate Reports</div>
            <button class="report-btn" onclick="generateReport('monthly')"><i class="fa-solid fa-calendar-days"></i> Monthly Report</button>
            <button class="report-btn" onclick="generateReport('attendance')"><i class="fa-solid fa-clipboard-list"></i> Attendance Report</button>
            <button class="report-btn" onclick="generateReport('financial')"><i class="fa-solid fa-receipt"></i> Financial Report</button>
            <button class="report-btn" onclick="generateReport('demographic')"><i class="fa-solid fa-chart-pie"></i> Demographics Report</button>
        </div>
        <?php if ($isSuperAdmin): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-title"><i class="fa-solid fa-key"></i> AI Setup</div>
            <div class="setup-panel" id="aiSetupPanel">
                <div id="aiActiveLine" style="font-size:.72rem;opacity:.85;margin-bottom:.55rem">Loading…</div>
                <label>AI Provider</label>
                <select id="aiProvider" onchange="aiProviderChanged()" style="width:100%;padding:.5rem;border-radius:8px;background:rgba(255,255,255,.06);color:inherit;border:1px solid rgba(255,255,255,.15);margin-bottom:.4rem"></select>
                <label>API Key</label>
                <input type="password" id="aiKey" placeholder="paste key" autocomplete="off" />
                <div id="aiKeyStatus" style="font-size:.66rem;opacity:.75;margin:.2rem 0 .4rem"></div>
                <label>Model</label>
                <input type="text" id="aiModel" placeholder="model name" autocomplete="off" />
                <div id="aiBaseWrap" style="display:none">
                    <label>Base URL (OpenAI-compatible)</label>
                    <input type="text" id="aiBase" placeholder="https://…/v1" autocomplete="off" />
                </div>
                <label style="display:flex;align-items:center;gap:.45rem;margin:.5rem 0;font-size:.76rem;cursor:pointer"><input type="checkbox" id="aiActive" style="width:auto"> Make this the active provider</label>
                <div style="display:flex;gap:.4rem">
                    <button class="save-key-btn" onclick="testConnection()" style="flex:1;background:rgba(255,255,255,.12)"><i class="fa-solid fa-plug"></i> Test</button>
                    <button class="save-key-btn" onclick="saveAiConfig()" style="flex:1"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                </div>
                <div id="aiTestResult" style="font-size:.7rem;margin-top:.45rem;min-height:1em"></div>
                <div class="setup-help" id="aiSignup"></div>
                <div class="setup-help" style="margin-top:.35rem;opacity:.65">Keys are encrypted before saving and never shown again in full.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="chat-area">
        <div class="chat-messages" id="chatMessages">
            <div class="welcome" id="welcomeState">
                <div class="welcome-icon"><i class="fa-solid fa-robot"></i></div>
                <h2><?= SCHOOL_NAME_SHORT ?> AI Assistant</h2>
                <p>Ask me anything about your school data — members, attendance, finances, classes. I analyze trends, generate reports, and give actionable insights.</p>
                <div class="welcome-suggestions">
                    <button class="suggest-btn" onclick="sendSuggestion('How many active members and what is the gender breakdown?')">👥 Member Stats</button>
                    <button class="suggest-btn" onclick="sendSuggestion('What is our attendance rate and which days are lowest?')">📊 Attendance</button>
                    <button class="suggest-btn" onclick="sendSuggestion('Give me a summary of our financial health')">💰 Finance</button>
                    <button class="suggest-btn" onclick="sendSuggestion('Which classes need more students?')">🏫 Classes</button>
                    <button class="suggest-btn" onclick="sendSuggestion('What are the top data quality issues to fix?')">🔍 Data Issues</button>
                    <button class="suggest-btn" onclick="sendSuggestion('Complete school status report for this month')">📋 Full Report</button>
                </div>
            </div>
            <div class="typing" id="typingIndicator">
                <div class="avatar ai-av"><i class="fa-solid fa-robot"></i></div>
                <div class="typing-dots"><span></span><span></span><span></span></div>
                <span>Analyzing your data...</span>
            </div>
        </div>

        <div class="input-area">
            <div id="noKeyBanner" class="no-key-banner" style="display:none">
                <i class="fa-solid fa-exclamation-triangle"></i>
                <span><?= $isSuperAdmin ? 'Set up an AI provider in the sidebar (AI Setup) to activate AI.' : 'Ask a Super Admin to set up an AI provider.' ?></span>
            </div>
            <div class="input-row">
                <div class="input-wrapper">
                    <textarea id="chatInput" rows="1" placeholder="Ask about your school data..." onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
                    <button class="send-btn" id="sendBtn" onclick="sendMessage()"><i class="fa-solid fa-paper-plane"></i></button>
                </div>
            </div>
            <div class="input-hint">Enter to send • Shift+Enter for new line • <span id="aiPoweredBy">Multi-provider AI</span></div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= $csrfToken ?>';
const API = '/admin/api_ai.php';
let hasApiKey = false;
let isProcessing = false;

document.addEventListener('DOMContentLoaded', () => { checkStatus(); loadHistory(); loadAiConfig(); });

/* ─── Multi-provider AI settings (Super Admin) ─── */
let AI_PROVIDERS = [];
async function loadAiConfig(){
    const sel = document.getElementById('aiProvider');
    if(!sel) return; // not a Super Admin
    try{
        const r = await fetch(API + '?action=get_ai_config', {credentials:'same-origin'});
        const d = await r.json();
        if(d.status !== 'success') return;
        AI_PROVIDERS = d.providers || [];
        sel.innerHTML = AI_PROVIDERS.map(p =>
            `<option value="${p.id}" ${p.is_active?'selected':''}>${p.label}${p.is_active?'  ✓ active':''}${p.has_key?'  • key set':''}</option>`
        ).join('');
        const active = AI_PROVIDERS.find(p=>p.is_active);
        const line = document.getElementById('aiActiveLine');
        if(active && active.has_key){ line.innerHTML = 'Active: <b>'+escapeHtml(active.label)+'</b> — key '+escapeHtml(active.key_masked); }
        else if(active){ line.innerHTML = 'Active: <b>'+escapeHtml(active.label)+'</b> — <span style="color:#fbbf24">no key yet</span>'; }
        else { line.innerHTML = '<span style="color:#fbbf24">No active provider yet — pick one, add a key, tick “make active”, Save.</span>'; }
        const pb = document.getElementById('aiPoweredBy');
        if(pb && active && active.has_key) pb.textContent = 'Powered by '+active.label;
        aiProviderChanged();
    }catch(e){}
}
function aiCurrentProvider(){ return AI_PROVIDERS.find(p => p.id === document.getElementById('aiProvider').value); }
function aiProviderChanged(){
    const p = aiCurrentProvider(); if(!p) return;
    document.getElementById('aiModel').value = p.model || p.default_model || '';
    document.getElementById('aiBaseWrap').style.display = p.needs_base ? 'block' : 'none';
    const base = document.getElementById('aiBase'); if(base) base.value = p.base_url || '';
    const keyInput = document.getElementById('aiKey');
    keyInput.value = '';
    keyInput.placeholder = p.has_key ? ('saved: '+p.key_masked+' — leave blank to keep') : (p.keyhint || 'paste key');
    document.getElementById('aiKeyStatus').textContent = p.has_key ? ('✓ key saved ('+p.key_masked+')') : 'no key saved yet';
    document.getElementById('aiActive').checked = !!p.is_active;
    document.getElementById('aiSignup').innerHTML = p.signup
        ? ('Get a '+(p.free?'FREE ':'')+'key → <a href="'+p.signup+'" target="_blank" rel="noopener">'+p.signup.replace(/^https?:\/\//,'')+'</a>')
        : '';
    document.getElementById('aiTestResult').textContent = '';
}
function aiFormData(action){
    const p = aiCurrentProvider();
    const f = new FormData();
    f.append('action', action);
    f.append('provider', p ? p.id : '');
    f.append('api_key', document.getElementById('aiKey').value.trim());
    f.append('model', document.getElementById('aiModel').value.trim());
    const base = document.getElementById('aiBase'); if(base) f.append('base_url', base.value.trim());
    f.append('csrf_token', CSRF);
    return f;
}
async function testConnection(){
    const res = document.getElementById('aiTestResult');
    res.style.color = '#94a3b8'; res.textContent = 'Testing…';
    try{
        const r = await fetch(API, {method:'POST', body: aiFormData('test_connection'), credentials:'same-origin'});
        const d = await r.json();
        res.style.color = d.status==='success' ? '#34d399' : '#f87171';
        res.textContent = (d.status==='success'?'✓ ':'✕ ') + d.message;
    }catch(e){ res.style.color='#f87171'; res.textContent='Network error while testing.'; }
}
async function saveAiConfig(){
    const res = document.getElementById('aiTestResult');
    res.style.color = '#94a3b8'; res.textContent = 'Saving…';
    const f = aiFormData('save_ai_config');
    if(document.getElementById('aiActive').checked) f.append('make_active','1');
    try{
        const r = await fetch(API, {method:'POST', body: f, credentials:'same-origin'});
        const d = await r.json();
        res.style.color = d.status==='success' ? '#34d399' : '#f87171';
        res.textContent = (d.status==='success'?'✓ ':'✕ ') + d.message;
        if(d.status==='success'){ await loadAiConfig(); checkStatus(); }
    }catch(e){ res.style.color='#f87171'; res.textContent='Network error while saving.'; }
}
function escapeHtml(s){ const d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 150) + 'px';
}

function scrollToBottom() {
    const c = document.getElementById('chatMessages');
    c.scrollTop = c.scrollHeight;
}

// ── Status check ──
async function checkStatus() {
    try {
        const r = await fetch(API + '?action=check_status');
        const d = await r.json();
        hasApiKey = d.has_api_key;
        document.getElementById('noKeyBanner').style.display = hasApiKey ? 'none' : 'flex';
    } catch(e) {}
}

// ── Load chat history ──
async function loadHistory() {
    try {
        const r = await fetch(API + '?action=get_history&limit=30');
        const d = await r.json();
        if (d.status === 'success' && d.messages.length > 0) {
            document.getElementById('welcomeState').style.display = 'none';
            d.messages.forEach(m => appendMessage(m.role, m.message, m.role === 'assistant'));
            scrollToBottom();
        }
    } catch(e) {}
}

// ── Render markdown ──
function renderMd(text) {
    return text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        .replace(/^---$/gm, '<hr>')
        .replace(/^\- (.+)$/gm, '<li>$1</li>')
        .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>')
        .replace(/\n{2,}/g, '</p><p>')
        .replace(/\n/g, '<br>')
        .replace(/^/, '<p>').replace(/$/, '</p>')
        .replace(/<p><(h[123]|pre|ul|ol|hr|li)/g, '<$1')
        .replace(/<\/(h[123]|pre|ul|ol|hr|li)><\/p>/g, '</$1>');
}

// ── Append message to chat ──
function appendMessage(role, text, useMd) {
    const container = document.getElementById('chatMessages');
    const typing = document.getElementById('typingIndicator');

    const div = document.createElement('div');
    div.className = 'message ' + role;

    const isAi = role === 'assistant';
    const avatarClass = isAi ? 'ai-av' : 'user-av';
    const avatarIcon = isAi ? 'fa-robot' : 'fa-user';
    const name = isAi ? 'AI Assistant' : '<?= e($fullName) ?>';

    let content = useMd ? renderMd(text) : '<p>' + text.replace(/\n/g, '<br>') + '</p>';

    let actions = '';
    if (isAi) {
        actions = '<div class="msg-actions">' +
            '<button class="msg-action-btn" onclick="copyMsg(this)"><i class="fa-solid fa-copy"></i> Copy</button>' +
            '<button class="msg-action-btn" onclick="downloadMsg(this)"><i class="fa-solid fa-download"></i> Save as TXT</button>' +
            '</div>';
    }

    div.innerHTML =
        '<div class="msg-header"><div class="avatar ' + avatarClass + '"><i class="fa-solid ' + avatarIcon + '"></i></div> ' + name + '</div>' +
        '<div class="msg-bubble">' + content + '</div>' + actions;

    container.insertBefore(div, typing);
    scrollToBottom();
}

function showTyping() { document.getElementById('typingIndicator').classList.add('active'); scrollToBottom(); }
function hideTyping() { document.getElementById('typingIndicator').classList.remove('active'); }

// ── Send message ──
function handleKey(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } }
function sendSuggestion(t) { document.getElementById('chatInput').value = t; sendMessage(); }

async function sendMessage() {
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg || isProcessing) return;
    if (!hasApiKey) {
        document.getElementById('welcomeState').style.display = 'none';
        appendMessage('assistant', '⚠️ AI is not configured yet. A Super Admin needs to add a free Google Gemini API key.\n\nGet one at: https://aistudio.google.com/apikey', false);
        return;
    }

    document.getElementById('welcomeState').style.display = 'none';
    appendMessage('user', msg, false);
    input.value = ''; autoResize(input);
    showTyping();
    isProcessing = true;
    document.getElementById('sendBtn').disabled = true;

    try {
        const form = new FormData();
        form.append('action', 'chat');
        form.append('message', msg);
        form.append('csrf_token', CSRF);
        const r = await fetch(API, { method: 'POST', body: form });
        const d = await r.json();
        hideTyping();
        if (d.status === 'success') appendMessage('assistant', d.response, true);
        else appendMessage('assistant', '❌ ' + (d.message || 'Something went wrong'), false);
    } catch(e) {
        hideTyping();
        appendMessage('assistant', '❌ Connection error. Please try again.', false);
    }
    isProcessing = false;
    document.getElementById('sendBtn').disabled = false;
}

// ── Quick Insight ──
async function quickInsight(type) {
    if (isProcessing) return;
    if (!hasApiKey) { sendMessage(); return; }
    const labels = {overview:'School Overview',members:'Member Analysis',attendance:'Attendance Patterns',education:'Education Status',finance:'Financial Health'};
    document.getElementById('welcomeState').style.display = 'none';
    appendMessage('user', '📊 ' + (labels[type] || type), false);
    showTyping(); isProcessing = true;
    try {
        const form = new FormData();
        form.append('action', 'quick_insight');
        form.append('type', type);
        form.append('csrf_token', CSRF);
        const r = await fetch(API, { method: 'POST', body: form });
        const d = await r.json();
        hideTyping();
        if (d.status === 'success') appendMessage('assistant', d.response, true);
        else appendMessage('assistant', '❌ ' + (d.message || 'Failed'), false);
    } catch(e) { hideTyping(); appendMessage('assistant', '❌ Connection error', false); }
    isProcessing = false;
}

// ── Generate Report ──
async function generateReport(type) {
    if (isProcessing) return;
    if (!hasApiKey) { sendMessage(); return; }
    const labels = {monthly:'Monthly Status Report',attendance:'Attendance Report',financial:'Financial Report',demographic:'Demographics Report'};
    document.getElementById('welcomeState').style.display = 'none';
    appendMessage('user', '📋 Generate: ' + (labels[type] || type), false);
    showTyping(); isProcessing = true;
    try {
        const form = new FormData();
        form.append('action', 'generate_report');
        form.append('report_type', type);
        form.append('csrf_token', CSRF);
        const r = await fetch(API, { method: 'POST', body: form });
        const d = await r.json();
        hideTyping();
        if (d.status === 'success') appendMessage('assistant', d.report, true);
        else appendMessage('assistant', '❌ ' + (d.message || 'Failed'), false);
    } catch(e) { hideTyping(); appendMessage('assistant', '❌ Connection error', false); }
    isProcessing = false;
}

// (Legacy single-key saveApiKey() removed — replaced by the multi-provider
//  AI Setup panel: loadAiConfig / saveAiConfig / testConnection above.)

// ── Clear chat ──
async function clearChat() {
    if (!confirm('Clear all chat history?')) return;
    try {
        const form = new FormData();
        form.append('action', 'clear_history');
        form.append('csrf_token', CSRF);
        await fetch(API, { method: 'POST', body: form });
    } catch(e) {}
    const c = document.getElementById('chatMessages');
    const typing = document.getElementById('typingIndicator');
    const welcome = document.getElementById('welcomeState');
    // Remove all messages but keep typing indicator and welcome
    Array.from(c.children).forEach(el => {
        if (el !== typing && el !== welcome) el.remove();
    });
    welcome.style.display = 'flex';
}

// ── Copy message ──
function copyMsg(btn) {
    const text = btn.closest('.message').querySelector('.msg-bubble').innerText;
    navigator.clipboard.writeText(text).then(() => {
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy'; }, 2000);
    });
}

// ── Download message as txt ──
function downloadMsg(btn) {
    const text = btn.closest('.message').querySelector('.msg-bubble').innerText;
    const blob = new Blob([text], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = '<?= EXPORT_PREFIX ?>_ai_report_' + new Date().toISOString().slice(0,10) + '.txt';
    a.click();
}

// ── Export full chat ──
function exportChat() {
    const messages = document.querySelectorAll('.message');
    if (messages.length === 0) return alert('No messages to export');
    let text = '<?= SCHOOL_NAME_SHORT ?> AI Assistant — Chat Export\nDate: ' + new Date().toLocaleString() + '\n' + '='.repeat(50) + '\n\n';
    messages.forEach(m => {
        const role = m.classList.contains('user') ? 'You' : 'AI';
        const content = m.querySelector('.msg-bubble').innerText;
        text += role + ':\n' + content + '\n\n---\n\n';
    });
    const blob = new Blob([text], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = '<?= EXPORT_PREFIX ?>_chat_' + new Date().toISOString().slice(0,10) + '.txt';
    a.click();
}
</script>
<!-- MOBILE BOTTOM NAV -->
<nav class="wbws-bnav" id="wbwsBottomNav">
<div class="wbws-bnav-inner">
<a href="/admin/dashboard.php" class="wbws-bnav-btn"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>
<button class="wbws-bnav-btn active"><i class="fa-solid fa-robot"></i><span>AI Chat</span></button>
<a href="/admin/logout.php" class="wbws-bnav-btn bnav-exit"><i class="fa-solid fa-power-off"></i><span>Exit</span></a>
</div></nav>
</body>
</html>
