<?php
/**
 * ============================================================
 * School AI Chatbot Widget  (floating, non-modal, movable)
 * ============================================================
 * A floating AI chat button + draggable/resizable window injected into every
 * dashboard. It is NON-MODAL: there is no full-screen overlay, so the dashboard
 * stays fully interactive while the chat is open. The window can be dragged by
 * its header, resized from the corner, minimised, and its position/size are
 * remembered per browser.
 *
 * Security: role-based data access (enforced server-side), CSRF, no secrets.
 * ============================================================
 */
if (empty($_SESSION['admin_id'])) return;

$_aiUserRole = $_SESSION['admin_role'] ?? '';
$_aiUserName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'User';
$_aiCsrf = generateCsrfToken();
?>
<!-- AI Chatbot Widget -->
<style>
#ai-fab{position:fixed;bottom:24px;right:24px;z-index:9990;width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg,#10b981,#3b82f6);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.35rem;box-shadow:0 6px 24px rgba(16,185,129,.4);transition:transform .2s,box-shadow .2s}
#ai-fab:hover{transform:scale(1.08);box-shadow:0 10px 34px rgba(16,185,129,.5)}
#ai-fab .fab-badge{position:absolute;top:-2px;right:-2px;width:13px;height:13px;border-radius:50%;background:#22c55e;border:2px solid #0f1629;display:none}
#ai-fab.has-key .fab-badge{display:block}
#ai-fab.hidden{display:none}

/* Floating window (NON-modal — no overlay) */
#ai-win{position:fixed;z-index:9995;width:390px;height:70vh;max-width:calc(100vw - 24px);max-height:calc(100vh - 24px);min-width:300px;min-height:360px;
  background:#0f1629;border:1px solid #263248;border-radius:16px;display:none;flex-direction:column;overflow:hidden;
  box-shadow:0 24px 70px rgba(0,0,0,.55);font-family:'Segoe UI',system-ui,sans-serif;resize:both}
#ai-win.open{display:flex}
#ai-win.min{height:auto!important;min-height:0;resize:none}
#ai-win.min .aip-body{display:none}

.aip-header{display:flex;align-items:center;justify-content:space-between;padding:.6rem .7rem;border-bottom:1px solid #1e293b;background:linear-gradient(135deg,#111827,#0d1524);flex-shrink:0;cursor:move;user-select:none;touch-action:none}
.aip-hl{display:flex;align-items:center;gap:.5rem;min-width:0}
.aip-hl .aip-icon{width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#10b981,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:.8rem;color:#fff;flex-shrink:0}
.aip-hl h3{font-size:.86rem;font-weight:600;color:#e2e8f0;margin:0;white-space:nowrap}
.aip-hl .aip-badge{font-size:.52rem;padding:.1rem .3rem;border-radius:99px;background:rgba(16,185,129,.16);color:#10b981;font-weight:700;letter-spacing:.3px}
.aip-actions{display:flex;align-items:center;gap:.1rem;flex-shrink:0}
.aip-actions button{background:none;border:none;color:#64748b;font-size:.85rem;cursor:pointer;padding:.35rem;border-radius:.35rem;transition:all .15s;line-height:1;width:28px;height:28px}
.aip-actions button:hover{background:rgba(255,255,255,.07);color:#e2e8f0}

.aip-body{flex:1;display:flex;flex-direction:column;min-height:0}

.aip-suggestions{display:flex;flex-wrap:wrap;gap:.3rem;padding:.5rem .7rem;border-bottom:1px solid #1e293b;flex-shrink:0;max-height:84px;overflow-y:auto}
.aip-sug{padding:.28rem .55rem;border:1px solid #2d3a4f;background:#1a2436;color:#94a3b8;border-radius:99px;font-size:.68rem;cursor:pointer;transition:all .15s;font-family:inherit;white-space:nowrap}
.aip-sug:hover{border-color:#10b981;color:#e2e8f0;background:rgba(16,185,129,.12)}

.aip-messages{flex:1;overflow-y:auto;padding:.8rem;scroll-behavior:smooth}
.aip-msg{margin-bottom:.75rem;animation:aipIn .22s ease}
@keyframes aipIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.aip-msg.user{display:flex;justify-content:flex-end}
.aip-msg.user .aip-bubble{background:rgba(59,130,246,.14);border:1px solid rgba(59,130,246,.25);border-radius:.7rem .7rem .18rem .7rem;max-width:85%;padding:.5rem .7rem;font-size:.8rem;line-height:1.55;color:#e2e8f0}
.aip-msg.ai .aip-bubble{background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.16);border-radius:.7rem .7rem .7rem .18rem;max-width:92%;padding:.5rem .72rem;font-size:.8rem;line-height:1.6;color:#cbd5e1;overflow-x:auto}
.aip-bubble h1,.aip-bubble h2,.aip-bubble h3{margin:.45rem 0 .2rem;font-weight:700;color:#e2e8f0;font-size:.85rem}
.aip-bubble h2{color:#34d399}.aip-bubble h3{color:#60a5fa}
.aip-bubble p{margin:.25rem 0}.aip-bubble strong{color:#f1f5f9}
.aip-bubble ul,.aip-bubble ol{padding-left:1.05rem;margin:.25rem 0}
.aip-bubble li{margin-bottom:.15rem}
.aip-bubble code{background:rgba(0,0,0,.35);padding:.1rem .25rem;border-radius:.2rem;font-size:.75rem}
.aip-bubble pre{background:rgba(0,0,0,.35);padding:.4rem .55rem;border-radius:.4rem;overflow-x:auto;margin:.3rem 0}
.aip-bubble pre code{background:none;padding:0}
.aip-bubble table{border-collapse:collapse;width:100%;margin:.3rem 0;font-size:.72rem}
.aip-bubble th,.aip-bubble td{padding:.25rem .35rem;border:1px solid #2d3a4f;text-align:left}
.aip-bubble th{background:rgba(255,255,255,.05)}
.aip-copy-btn{background:none;border:1px solid #2d3a4f;color:#64748b;padding:.14rem .45rem;border-radius:.25rem;font-size:.62rem;cursor:pointer;margin-top:.35rem;font-family:inherit;transition:all .15s}
.aip-copy-btn:hover{color:#e2e8f0;background:rgba(255,255,255,.05)}

.aip-typing{display:none;align-items:center;gap:.35rem;padding:.3rem .2rem;color:#64748b;font-size:.72rem}
.aip-typing.active{display:flex}
.aip-typing-dots{display:flex;gap:2px}
.aip-typing-dots span{width:5px;height:5px;border-radius:50%;background:#10b981;animation:aipBlink 1.4s infinite}
.aip-typing-dots span:nth-child(2){animation-delay:.2s}.aip-typing-dots span:nth-child(3){animation-delay:.4s}
@keyframes aipBlink{0%,80%,100%{opacity:.3}40%{opacity:1}}

.aip-welcome{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.4rem 1rem;text-align:center;height:100%}
.aip-welcome-icon{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#10b981,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:1.15rem;color:#fff;margin-bottom:.6rem}
.aip-welcome h4{font-size:.92rem;color:#e2e8f0;margin:0 0 .3rem}
.aip-welcome p{font-size:.76rem;color:#7b8aa3;max-width:280px;line-height:1.5;margin:0}

.aip-input-area{padding:.5rem .7rem;border-top:1px solid #1e293b;background:#0d1524;flex-shrink:0}
.aip-setup{padding:.6rem;text-align:center;color:#94a3b8;font-size:.76rem;line-height:1.5}
.aip-setup a{color:#60a5fa;text-decoration:none}.aip-setup a:hover{text-decoration:underline}
.aip-input-row{display:flex;gap:.4rem;align-items:flex-end}
#aipInput{flex:1;padding:.5rem .6rem;background:#1a2436;border:1px solid #2d3a4f;border-radius:.55rem;color:#e2e8f0;font-size:.8rem;font-family:inherit;resize:none;min-height:38px;max-height:110px;line-height:1.4}
#aipInput:focus{outline:none;border-color:#10b981}
#aipInput::placeholder{color:#4b5b73}
.aip-send{width:34px;height:34px;border-radius:.45rem;border:none;background:linear-gradient(135deg,#10b981,#3b82f6);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;transition:opacity .15s}
.aip-send:hover{opacity:.88}.aip-send:disabled{opacity:.3;cursor:not-allowed}
.aip-hint{font-size:.6rem;color:#4b5b73;text-align:center;margin-top:.3rem}
</style>

<button id="ai-fab" onclick="aipToggle()" title="AI Assistant"><i class="fa-solid fa-robot"></i><span class="fab-badge"></span></button>

<div id="ai-win" role="dialog" aria-label="AI Assistant">
  <div class="aip-header" id="aipHeader">
    <div class="aip-hl"><div class="aip-icon"><i class="fa-solid fa-robot"></i></div><h3>AI Assistant</h3><span class="aip-badge">AI</span></div>
    <div class="aip-actions">
      <button onclick="aipClear()" title="New chat"><i class="fa-solid fa-pen-to-square"></i></button>
      <button onclick="aipMinimize()" title="Minimise" id="aipMinBtn"><i class="fa-solid fa-window-minimize"></i></button>
      <button onclick="aipClose()" title="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div class="aip-body">
    <div class="aip-suggestions" id="aipSuggestions"></div>
    <div class="aip-messages" id="aipMessages">
      <div class="aip-welcome" id="aipWelcome">
        <div class="aip-welcome-icon"><i class="fa-solid fa-robot"></i></div>
        <h4>Hi <?= htmlspecialchars(explode(' ', $_aiUserName)[0], ENT_QUOTES, 'UTF-8') ?> 👋</h4>
        <p>Ask me about your school data — or how any part of the system works. Try a suggestion above.</p>
      </div>
      <div class="aip-typing" id="aipTyping"><div class="aip-typing-dots"><span></span><span></span><span></span></div><span>Thinking…</span></div>
    </div>
    <div class="aip-input-area">
      <div id="aipSetupMsg" class="aip-setup" style="display:none"></div>
      <div class="aip-input-row" id="aipInputRow">
        <textarea id="aipInput" rows="1" placeholder="Ask about your data or the system…" onkeydown="aipKey(event)" oninput="aipResize(this)"></textarea>
        <button class="aip-send" id="aipSendBtn" onclick="aipSend()"><i class="fa-solid fa-paper-plane"></i></button>
      </div>
      <div class="aip-hint">Enter to send • Shift+Enter = new line • drag the header to move</div>
    </div>
  </div>
</div>

<script>
(function(){
  const CSRF='<?= $_aiCsrf ?>', API='/admin/api_ai.php', ROLE='<?= $_aiUserRole ?>';
  let hasKey=false, busy=false, loaded=false;
  const LS='fkssAiWin';

  const roleSug={
    super_admin:['❓ What can this system do?','📊 School overview','👥 Member stats','💰 Finance health'],
    school_admin:['❓ What can this system do?','📊 School overview','👥 Member stats','📋 Monthly report'],
    info_dept:['❓ What is the Old Members Archive?','👥 Member demographics','📞 Data quality check','🆕 Registration trends'],
    edu_dept:['❓ How does the academic year work?','🏫 Class enrollment','📖 Teacher ratios','📊 Education status'],
    finance_dept:['❓ What can I do here?','💰 Income vs expenses','📊 Budget summary','⏳ Pending payments'],
    material_dept:['❓ What can I do here?','📦 Material overview','📊 Department stats'],
    teacher:['❓ What can I do here?','📊 My class overview','📖 Student performance tips'],
    attendance_taker:['❓ How do I record attendance?','📊 Attendance overview','📅 Today\'s summary'],
    content_editor:['❓ What can I do here?','📰 Content overview']
  };

  const $=(id)=>document.getElementById(id);

  window.addEventListener('DOMContentLoaded',()=>{
    // suggestions
    const sugs=roleSug[ROLE]||roleSug.teacher, box=$('aipSuggestions');
    sugs.forEach(s=>{const b=document.createElement('button');b.className='aip-sug';b.textContent=s;
      b.onclick=()=>{$('aipInput').value=s.replace(/^[^\s]+\s/,'');aipSend();};box.appendChild(b);});
    restorePos();
    checkStatus();
  });

  async function checkStatus(){
    try{
      const r=await fetch(API+'?action=check_status');const d=await r.json();
      hasKey=!!d.has_api_key;
      if(hasKey) $('ai-fab').classList.add('has-key');
      else{
        const su=$('aipSetupMsg'),isSA=ROLE==='super_admin';
        su.style.display='block';
        su.innerHTML=isSA?'AI is not set up yet. <a href="/admin/dashboards/ai_assistant.php">Open AI Setup</a>':'AI is not set up yet. Ask a Super Admin to configure a provider.';
        $('aipInputRow').style.display='none';
      }
    }catch(e){}
  }

  async function loadHistory(){
    if(loaded) return; loaded=true;
    try{
      const r=await fetch(API+'?action=get_history&limit=20');const d=await r.json();
      if(d.status==='success'&&d.messages&&d.messages.length){
        $('aipWelcome').style.display='none';
        d.messages.forEach(m=>addMsg(m.role,m.message,m.role==='assistant'));
      }
    }catch(e){}
  }

  // ── open/close/minimise (NON-modal) ──
  window.aipToggle=()=>{ const w=$('ai-win'); w.classList.contains('open')?aipClose():aipOpen(); };
  window.aipOpen=()=>{ const w=$('ai-win'); w.classList.add('open'); w.classList.remove('min');
    $('ai-fab').classList.add('hidden'); loadHistory(); setTimeout(()=>{$('aipInput')&&$('aipInput').focus();scrollBot();},50); };
  window.aipClose=()=>{ $('ai-win').classList.remove('open'); $('ai-fab').classList.remove('hidden'); };
  window.aipMinimize=()=>{ $('ai-win').classList.toggle('min'); };

  window.aipClear=async()=>{
    if(!confirm('Start a new chat? This clears the current conversation history.')) return;
    try{const f=new FormData();f.append('action','clear_history');f.append('csrf_token',CSRF);
      await fetch(API,{method:'POST',body:f});}catch(e){}
    document.querySelectorAll('.aip-msg').forEach(n=>n.remove());
    $('aipWelcome').style.display='';
  };

  window.aipKey=(e)=>{ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();aipSend();} };
  window.aipResize=(el)=>{ el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,110)+'px'; };

  window.aipSend=async()=>{
    const inp=$('aipInput'), msg=inp.value.trim();
    if(!msg||busy||!hasKey) return;
    $('aipWelcome').style.display='none';
    addMsg('user',msg,false);
    inp.value=''; aipResize(inp); showTyping(); busy=true; $('aipSendBtn').disabled=true;
    try{
      const f=new FormData();f.append('action','chat');f.append('message',msg);f.append('csrf_token',CSRF);
      const r=await fetch(API,{method:'POST',body:f});const d=await r.json();
      hideTyping();
      if(d.status==='success') addMsg('assistant',d.response,true);
      else addMsg('assistant','⚠️ '+(d.message||'Something went wrong.'),false);
    }catch(e){ hideTyping(); addMsg('assistant','⚠️ Connection error. Please try again.',false); }
    busy=false; $('aipSendBtn').disabled=false;
  };

  // ── lightweight, safe markdown ──
  function renderMd(t){
    let s=t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    s=s.replace(/```\w*\n?([\s\S]*?)```/g,(m,c)=>'<pre><code>'+c.replace(/\n$/,'')+'</code></pre>');
    s=s.replace(/`([^`]+)`/g,'<code>$1</code>');
    s=s.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>').replace(/(^|[^*])\*([^*\n]+)\*/g,'$1<em>$2</em>');
    s=s.replace(/^\s*###\s+(.+)$/gm,'<h3>$1</h3>').replace(/^\s*##\s+(.+)$/gm,'<h2>$1</h2>').replace(/^\s*#\s+(.+)$/gm,'<h2>$1</h2>');
    s=s.replace(/^\s*---\s*$/gm,'<hr>');
    // group consecutive bullet lines into a <ul>
    s=s.replace(/(?:^|\n)\s*[-*]\s+.+(?:\n\s*[-*]\s+.+)*/g,(blk)=>{
      const items=blk.trim().split(/\n/).map(l=>l.replace(/^\s*[-*]\s+/,'').trim()).filter(Boolean);
      return '\n<ul>'+items.map(i=>'<li>'+i+'</li>').join('')+'</ul>';
    });
    // numbered lists
    s=s.replace(/(?:^|\n)\s*\d+\.\s+.+(?:\n\s*\d+\.\s+.+)*/g,(blk)=>{
      const items=blk.trim().split(/\n/).map(l=>l.replace(/^\s*\d+\.\s+/,'').trim()).filter(Boolean);
      return '\n<ol>'+items.map(i=>'<li>'+i+'</li>').join('')+'</ol>';
    });
    // paragraphs / line breaks (leave block tags alone)
    return s.split(/\n{2,}/).map(chunk=>{
      if(/^\s*<(h\d|ul|ol|pre|hr|table)/.test(chunk.trim())) return chunk;
      return '<p>'+chunk.trim().replace(/\n/g,'<br>')+'</p>';
    }).join('');
  }

  function addMsg(role,text,useMd){
    const cont=$('aipMessages'), typing=$('aipTyping'), div=document.createElement('div');
    div.className='aip-msg '+(role==='user'?'user':'ai');
    const content=useMd?renderMd(text):text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
    let extra='';
    if(role==='assistant') extra='<button class="aip-copy-btn" onclick="var b=this.parentElement.querySelector(\'.aip-bubble\');navigator.clipboard.writeText(b.innerText);this.textContent=\'Copied!\';var t=this;setTimeout(function(){t.textContent=\'Copy\';},1500)">Copy</button>';
    div.innerHTML='<div class="aip-bubble">'+content+'</div>'+extra;
    cont.insertBefore(div,typing); scrollBot();
  }
  function showTyping(){ $('aipTyping').classList.add('active'); scrollBot(); }
  function hideTyping(){ $('aipTyping').classList.remove('active'); }
  function scrollBot(){ const c=$('aipMessages'); c.scrollTop=c.scrollHeight; }

  // ── drag (header) + persist position/size (NON-modal) ──
  function clampPos(x,y){
    const w=$('ai-win'), r=w.getBoundingClientRect();
    x=Math.max(6,Math.min(x,window.innerWidth-r.width-6));
    y=Math.max(6,Math.min(y,window.innerHeight-r.height-6));
    return [x,y];
  }
  function applyPos(x,y){ const w=$('ai-win'); w.style.left=x+'px'; w.style.top=y+'px'; w.style.right='auto'; w.style.bottom='auto'; }
  function savePos(){ try{ const w=$('ai-win'); localStorage.setItem(LS,JSON.stringify({l:w.style.left,t:w.style.top,w:w.style.width,h:w.style.height})); }catch(e){} }
  function restorePos(){
    const w=$('ai-win');
    try{ const s=JSON.parse(localStorage.getItem(LS)||'null');
      if(s&&s.l){ w.style.left=s.l;w.style.top=s.t;w.style.right='auto';w.style.bottom='auto'; if(s.w)w.style.width=s.w; if(s.h)w.style.height=s.h;
        const[x,y]=clampPos(parseInt(s.l),parseInt(s.t)); applyPos(x,y); return; }
    }catch(e){}
    // default: above the FAB, bottom-right
    w.style.right='24px'; w.style.bottom='88px'; w.style.left='auto'; w.style.top='auto';
  }
  (function initDrag(){
    const h=$('aipHeader'); let sx,sy,ox,oy,drag=false;
    h.addEventListener('pointerdown',(e)=>{
      if(e.target.closest('.aip-actions')) return; // don't drag when hitting buttons
      const w=$('ai-win'), r=w.getBoundingClientRect();
      // switch to left/top anchoring before moving
      applyPos(r.left,r.top);
      drag=true; sx=e.clientX; sy=e.clientY; ox=r.left; oy=r.top;
      h.setPointerCapture(e.pointerId);
    });
    h.addEventListener('pointermove',(e)=>{ if(!drag)return; const[x,y]=clampPos(ox+(e.clientX-sx),oy+(e.clientY-sy)); applyPos(x,y); });
    h.addEventListener('pointerup',(e)=>{ if(!drag)return; drag=false; try{h.releasePointerCapture(e.pointerId);}catch(_){} savePos(); });
    // save size changes from the resize grip
    if(window.ResizeObserver){ let t; new ResizeObserver(()=>{clearTimeout(t);t=setTimeout(savePos,300);}).observe($('ai-win')); }
    window.addEventListener('resize',()=>{ const w=$('ai-win'); if(w.style.left&&w.style.left!=='auto'){ const[x,y]=clampPos(parseInt(w.style.left),parseInt(w.style.top)); applyPos(x,y); } });
  })();
})();
</script>
