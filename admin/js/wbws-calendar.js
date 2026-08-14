/**
 * School Ethiopian Calendar System v2
 * Full date picker with month/year selectors, mobile bottom sheet
 */
(function(global) {
'use strict';

const MODE = (typeof WBWS_CALENDAR_MODE !== 'undefined') ? WBWS_CALENDAR_MODE : 'ethiopian';

const EC_MONTHS_AM = ['','መስከረም','ጥቅምት','ህዳር','ታህሳስ','ጥር','የካቲት','መጋቢት','ሚያዝያ','ግንቦት','ሰኔ','ሐምሌ','ነሐሴ','ጳጉሜ'];
const EC_MONTHS_EN = ['','Meskerem','Tikimt','Hidar','Tahsas','Tir','Yekatit','Megabit','Miyazya','Ginbot','Sene','Hamle','Nehasse','Pagume'];
const EC_MONTHS_SHORT = ['','Mes','Tik','Hid','Tah','Tir','Yek','Meg','Miy','Gin','Sen','Ham','Neh','Pag'];
const EC_DAYS_AM = ['እሑድ','ሰኞ','ማክሰኞ','ረቡዕ','ሐሙስ','ዓርብ','ቅዳሜ'];
const EC_DAYS_SHORT = ['እሑ','ሰኞ','ማክ','ረቡ','ሐሙ','ዓር','ቅዳ'];

// ═══ CONVERSION ═══
function isGLeap(y){return(y%4===0&&y%100!==0)||(y%400===0);}
function mes1Day(gy){return isGLeap(gy+1)?12:11;}
function getMes1(gy){return new Date(gy,8,mes1Day(gy));}

function toEthiopian(input){
    let dt=(input instanceof Date)?new Date(input.getTime()):new Date(input);
    if(isNaN(dt.getTime()))return{year:0,month:0,day:0};
    // CRITICAL: Normalize to LOCAL midnight to prevent timezone/DST off-by-one
    dt=new Date(dt.getFullYear(),dt.getMonth(),dt.getDate());
    const gy=dt.getFullYear();
    let mes=getMes1(gy),ey;
    if(dt<mes){mes=getMes1(gy-1);ey=gy-8;}else{ey=gy-7;}
    // Use Math.round instead of Math.floor for DST/timezone safety
    const ds=Math.round((dt.getTime()-mes.getTime())/86400000);
    let em=Math.floor(ds/30)+1,ed=(ds%30)+1;
    if(em>13){em=13;ed=ds-360+1;}
    if(em<1)em=1;if(ed<1)ed=1;
    return{year:ey,month:em,day:ed};
}

function toGregorian(ey,em,ed){
    const gy=ey+7,mes=getMes1(gy);
    const r=new Date(mes);r.setDate(r.getDate()+(em-1)*30+(ed-1));return r;
}

function daysInMonth(m,y){
    if(m>=1&&m<=12)return 30;
    if(m===13)return(y%4===3)?6:5;
    return 30;
}

// ═══ FORMATTING ═══
function formatDate(input,fmt){
    if(!input)return '—';fmt=fmt||'medium';
    try{
        const dt=(input instanceof Date)?input:new Date(input);
        if(isNaN(dt.getTime()))return String(input);
        return MODE==='ethiopian'?fmtEth(dt,fmt):fmtGC(dt,fmt);
    }catch(e){return String(input);}
}
function fmtEth(dt,f){
    const ec=toEthiopian(dt);if(!ec.year)return '—';
    const dp=String(ec.day).padStart(2,'0'),mp=String(ec.month).padStart(2,'0');
    switch(f){
        case 'short':return dp+'/'+mp+'/'+ec.year;
        case 'medium':return EC_MONTHS_SHORT[ec.month]+' '+ec.day+', '+ec.year;
        case 'long':return EC_MONTHS_AM[ec.month]+' '+ec.day+', '+ec.year+' ዓ.ም.';
        case 'full':return EC_DAYS_AM[dt.getDay()]+' '+EC_MONTHS_AM[ec.month]+' '+ec.day+', '+ec.year+' ዓ.ም.';
        case 'month-year':return EC_MONTHS_AM[ec.month]+' '+ec.year;
        case 'iso':return ec.year+'-'+mp+'-'+dp;
        default:return EC_MONTHS_SHORT[ec.month]+' '+ec.day+', '+ec.year;
    }
}
function fmtGC(dt,f){
    const o={short:{day:'2-digit',month:'2-digit',year:'numeric'},medium:{day:'2-digit',month:'short',year:'numeric'},long:{day:'numeric',month:'long',year:'numeric'},full:{weekday:'long',day:'numeric',month:'long',year:'numeric'}};
    return dt.toLocaleDateString('en-GB',o[f]||o.medium);
}
function formatDateTime(input){
    if(!input)return '—';
    try{const dt=(input instanceof Date)?input:new Date(input);if(isNaN(dt.getTime()))return String(input);
    return formatDate(dt,'medium')+' '+dt.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});}catch(e){return String(input);}
}
function todayFormatted(f){return formatDate(new Date(),f||'long');}
function currentECYear(){return toEthiopian(new Date()).year;}
function currentECMonth(){return toEthiopian(new Date()).month;}

// ═══ DATE PICKER ═══
const isMobile=()=>window.innerWidth<=600;
let currentPopup=null,currentOverlay=null;

function initDatePickers(){
    if(MODE!=='ethiopian')return;
    document.querySelectorAll('input[type="date"]:not([data-ec-init])').forEach(inp=>convertPicker(inp));
}

function convertPicker(input){
    input.dataset.ecInit='1';
    const wrap=document.createElement('div');
    wrap.className='ec-wrap';wrap.style.cssText='position:relative;display:inline-block;width:100%';
    input.parentNode.insertBefore(wrap,input);
    const disp=document.createElement('input');
    disp.type='text';disp.readOnly=true;disp.className=input.className;
    disp.placeholder='ቀን ይምረጡ (Select date)';
    disp.style.cssText=(input.style.cssText||'')+';cursor:pointer;padding-right:36px';
    const uid=input.id||('ec-'+Math.random().toString(36).substr(2,6));
    disp.dataset.for=uid;
    input.type='hidden';input.dataset.ecHidden='1';
    if(!input.id)input.id=uid+'-gc';
    wrap.appendChild(disp);wrap.appendChild(input);
    const ico=document.createElement('span');
    ico.innerHTML='<i class="fa-solid fa-calendar-days"></i>';
    ico.style.cssText='position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#7c3aed;pointer-events:none;font-size:.8rem';
    wrap.appendChild(ico);
    if(input.value){const p=input.value.split('-');if(p.length===3){const ec=toEthiopian(new Date(+p[0],+p[1]-1,+p[2]));if(ec.year)disp.value=EC_MONTHS_AM[ec.month]+' '+ec.day+', '+ec.year;}}
    disp.addEventListener('click',e=>{e.stopPropagation();openCal(disp,input);});
    new MutationObserver(()=>{
        if(input.value){const p=input.value.split('-');if(p.length===3){const ec=toEthiopian(new Date(+p[0],+p[1]-1,+p[2]));if(ec.year)disp.value=EC_MONTHS_AM[ec.month]+' '+ec.day+', '+ec.year;}}
        else disp.value='';
    }).observe(input,{attributes:true,attributeFilter:['value']});
}

function openCal(display,hidden){
    closeCal();
    // Parse hidden value as LOCAL date (not UTC)
    let ec;
    if(hidden.value){const p=hidden.value.split('-');ec=p.length===3?toEthiopian(new Date(+p[0],+p[1]-1,+p[2])):toEthiopian(new Date());}
    else{ec=toEthiopian(new Date());}
    const mob=isMobile();
    let view='days'; // days|months|years

    if(mob){
        currentOverlay=document.createElement('div');
        currentOverlay.className='ec-overlay';
        currentOverlay.addEventListener('click',closeCal);
        document.body.appendChild(currentOverlay);
    }

    const pop=document.createElement('div');
    pop.className='ec-cal'+(mob?' ec-mob':'');
    pop.addEventListener('click',e=>e.stopPropagation());
    currentPopup=pop;

    function render(vy,vm){
        const todayEc=toEthiopian(new Date());
        let selEc=null;if(hidden.value){const p=hidden.value.split('-');if(p.length===3)selEc=toEthiopian(new Date(+p[0],+p[1]-1,+p[2]));}

        // ═══ YEAR PICKER ═══
        if(view==='years'){
            const sy=vy-6;
            let h='<div class="ec-hd"><button class="ec-nb" id="ec-py">◀</button><div class="ec-hc"><span class="ec-ht">'+sy+' — '+(sy+11)+'</span></div><button class="ec-nb" id="ec-ny">▶</button></div>';
            h+='<div class="ec-bd"><div class="ec-ygrid">';
            for(let i=0;i<12;i++){
                const y=sy+i;
                let c='ec-ycell';
                if(y===vy)c+=' ec-sel';else if(y===todayEc.year)c+=' ec-now';
                h+='<button class="'+c+'" data-y="'+y+'">'+y+'</button>';
            }
            h+='</div></div>';
            pop.innerHTML=h;
            pop.querySelector('#ec-py').onclick=e=>{e.stopPropagation();render(vy-12,vm);};
            pop.querySelector('#ec-ny').onclick=e=>{e.stopPropagation();render(vy+12,vm);};
            pop.querySelectorAll('.ec-ycell').forEach(b=>{b.onclick=e=>{e.stopPropagation();view='months';render(parseInt(b.dataset.y),vm);};});
            return;
        }

        // ═══ MONTH PICKER ═══
        if(view==='months'){
            let h='<div class="ec-hd"><button class="ec-nb" id="ec-pyy">◀</button><div class="ec-hc"><button class="ec-yb" id="ec-goyr">'+vy+' ዓ.ም. <i class="fa-solid fa-chevron-down" style="font-size:.45rem;opacity:.6"></i></button></div><button class="ec-nb" id="ec-nyy">▶</button></div>';
            h+='<div class="ec-bd"><div class="ec-mgrid">';
            for(let m=1;m<=13;m++){
                let c='ec-mcell';
                if(m===vm)c+=' ec-sel';else if(todayEc.year===vy&&todayEc.month===m)c+=' ec-now';
                h+='<button class="'+c+'" data-m="'+m+'"><span class="ec-mam">'+EC_MONTHS_AM[m]+'</span><span class="ec-men">'+EC_MONTHS_EN[m]+'</span></button>';
            }
            h+='</div></div>';
            pop.innerHTML=h;
            pop.querySelector('#ec-pyy').onclick=e=>{e.stopPropagation();render(vy-1,vm);};
            pop.querySelector('#ec-nyy').onclick=e=>{e.stopPropagation();render(vy+1,vm);};
            pop.querySelector('#ec-goyr').onclick=e=>{e.stopPropagation();view='years';render(vy,vm);};
            pop.querySelectorAll('.ec-mcell').forEach(b=>{b.onclick=e=>{e.stopPropagation();view='days';render(vy,parseInt(b.dataset.m));};});
            return;
        }

        // ═══ DAYS VIEW ═══
        const days=daysInMonth(vm,vy);
        const gcF=toGregorian(vy,vm,1);
        const dow=gcF.getDay();

        let h='<div class="ec-hd">';
        h+='<button class="ec-nb" id="ec-pm">◀</button>';
        h+='<div class="ec-hc">';
        h+='<button class="ec-mb" id="ec-gom">'+EC_MONTHS_AM[vm]+' <i class="fa-solid fa-chevron-down" style="font-size:.45rem;opacity:.6"></i></button>';
        h+='<button class="ec-yb" id="ec-goy">'+vy+' ዓ.ም. <i class="fa-solid fa-chevron-down" style="font-size:.45rem;opacity:.6"></i></button>';
        h+='</div>';
        h+='<button class="ec-nb" id="ec-nm">▶</button></div>';

        h+='<div class="ec-bd"><div class="ec-wk">';
        EC_DAYS_SHORT.forEach(d=>{h+='<div class="ec-wd">'+d+'</div>';});
        h+='</div><div class="ec-dg">';
        for(let i=0;i<dow;i++)h+='<div class="ec-emp"></div>';
        for(let d=1;d<=days;d++){
            const isT=todayEc.year===vy&&todayEc.month===vm&&todayEc.day===d;
            const isS=selEc&&selEc.year===vy&&selEc.month===vm&&selEc.day===d;
            let c='ec-d';if(isS)c+=' ec-sel';else if(isT)c+=' ec-now';
            h+='<button class="'+c+'" data-d="'+d+'">'+d+'</button>';
        }
        h+='</div>';

        // Footer
        const nowD=new Date();
        const dbgEc=toEthiopian(nowD);
        const dbgGc=nowD.getFullYear()+'-'+String(nowD.getMonth()+1).padStart(2,'0')+'-'+String(nowD.getDate()).padStart(2,'0');
        h+='<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:6px 8px;margin-top:8px;font-size:.6rem;color:#166534;line-height:1.4">';
        h+='<b>Debug:</b> GC='+dbgGc+' | EC='+dbgEc.month+'/'+dbgEc.day+'/'+dbgEc.year;
        h+=' | TZ='+Intl.DateTimeFormat().resolvedOptions().timeZone;
        h+=' | Mes1=Sep '+mes1Day(nowD.getFullYear()>8?nowD.getFullYear()-1:nowD.getFullYear())+','+((nowD.getMonth()<8)?nowD.getFullYear()-1:nowD.getFullYear());
        h+='</div>';
        h+='<div class="ec-ft">';
        h+='<button class="ec-fb ec-td"><i class="fa-solid fa-calendar-check"></i> ዛሬ Today</button>';
        h+='<button class="ec-fb ec-cl"><i class="fa-solid fa-xmark"></i> Clear</button>';
        if(mob)h+='<button class="ec-fb ec-cs"><i class="fa-solid fa-times"></i> Close</button>';
        h+='</div></div>';

        pop.innerHTML=h;

        // Bind
        pop.querySelector('#ec-pm').onclick=e=>{e.stopPropagation();let nm=vm-1,ny=vy;if(nm<1){nm=13;ny--;}render(ny,nm);};
        pop.querySelector('#ec-nm').onclick=e=>{e.stopPropagation();let nm=vm+1,ny=vy;if(nm>13){nm=1;ny++;}render(ny,nm);};
        pop.querySelector('#ec-gom').onclick=e=>{e.stopPropagation();view='months';render(vy,vm);};
        pop.querySelector('#ec-goy').onclick=e=>{e.stopPropagation();view='years';render(vy,vm);};
        pop.querySelectorAll('.ec-d').forEach(b=>{b.onclick=e=>{e.stopPropagation();pickDate(vy,vm,parseInt(b.dataset.d),display,hidden);};});
        pop.querySelector('.ec-td').onclick=e=>{e.stopPropagation();const t=toEthiopian(new Date());pickDate(t.year,t.month,t.day,display,hidden);};
        pop.querySelector('.ec-cl').onclick=e=>{e.stopPropagation();hidden.value='';display.value='';hidden.dispatchEvent(new Event('change',{bubbles:true}));closeCal();};
        const cs=pop.querySelector('.ec-cs');if(cs)cs.onclick=e=>{e.stopPropagation();closeCal();};
    }

    render(ec.year,ec.month);

    // Position — ALWAYS keep fully visible
    if(!mob){
        const r=display.getBoundingClientRect();
        const vw=window.innerWidth,vh=window.innerHeight;
        // Measure popup after adding to DOM (hidden)
        pop.style.visibility='hidden';
        document.body.appendChild(pop);
        const pH=pop.offsetHeight||420;
        pop.style.visibility='';

        const spaceBelow=vh-r.bottom;
        const spaceAbove=r.top;
        let topPos,leftPos;

        if(spaceBelow>=pH+8){
            // Fits below — preferred
            topPos=r.bottom+4;
        }else if(spaceAbove>=pH+8){
            // Fits above
            topPos=r.top-pH-4;
        }else{
            // Doesn't fit either way — position at top of viewport with some padding
            // or center vertically if popup is smaller than viewport
            topPos=Math.max(8,Math.min(r.bottom+4,vh-pH-8));
        }
        // Clamp: never go above 8px from viewport top
        topPos=Math.max(8,topPos);
        // Clamp: never go below viewport
        if(topPos+pH>vh-8)topPos=Math.max(8,vh-pH-8);

        leftPos=Math.max(8,Math.min(r.left,vw-340));
        pop.style.top=topPos+'px';
        pop.style.left=leftPos+'px';
    }else{
        document.body.appendChild(pop);
    }
    if(!mob)setTimeout(()=>document.addEventListener('click',closeCal),50);
}

function pickDate(ey,em,ed,disp,hidden){
    const gc=toGregorian(ey,em,ed);
    // Use local date components, NOT ISO string (which JS parses as UTC)
    const y=gc.getFullYear(),m=gc.getMonth()+1,d=gc.getDate();
    const isoVal=y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    hidden.value=isoVal;
    // Verify round-trip: convert back to confirm correct day
    const verify=toEthiopian(new Date(y,gc.getMonth(),d));
    disp.value=EC_MONTHS_AM[verify.month]+' '+verify.day+', '+verify.year;
    // DEBUG: show alert so user can screenshot
    console.log('[EC Debug] Picked EC:'+em+'/'+ed+'/'+ey+' -> GC:'+isoVal+' -> Verify EC:'+verify.month+'/'+verify.day+'/'+verify.year);
    if(window._ecDebug)alert('DEBUG: You picked EC '+em+'/'+ed+'/'+ey+'\nConverted to GC: '+isoVal+'\nVerify back: EC '+verify.month+'/'+verify.day+'/'+verify.year+'\nDisplay: '+disp.value);
    hidden.dispatchEvent(new Event('change',{bubbles:true}));
    closeCal();
}

function closeCal(){
    if(currentPopup){currentPopup.remove();currentPopup=null;}
    if(currentOverlay){currentOverlay.remove();currentOverlay=null;}
    document.removeEventListener('click',closeCal);
}

// ═══ STYLES ═══
function injectStyles(){
    if(document.getElementById('ec-css'))return;
    const s=document.createElement('style');s.id='ec-css';
    s.textContent=`
@keyframes ecIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
@keyframes ecUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.ec-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;backdrop-filter:blur(2px)}
.ec-cal{position:fixed;z-index:10000;background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:330px;overflow:hidden;font-family:'Noto Sans Ethiopic','Poppins',system-ui,sans-serif;animation:ecIn .2s ease;max-height:calc(100vh - 16px);overflow-y:auto}
.ec-cal.ec-mob{position:fixed!important;bottom:0!important;left:0!important;right:0!important;top:auto!important;width:100%!important;max-width:100%!important;border-radius:20px 20px 0 0;max-height:85vh;animation:ecUp .3s ease;box-shadow:0 -10px 40px rgba(0,0,0,.2)}
.ec-hd{background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;padding:12px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.ec-mob .ec-hd{padding:16px 16px 14px}
.ec-nb{background:rgba(255,255,255,.2);border:none;color:#fff;width:38px;height:38px;border-radius:10px;cursor:pointer;font-size:.95rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s;-webkit-tap-highlight-color:transparent}
.ec-nb:hover,.ec-nb:active{background:rgba(255,255,255,.35)}
.ec-mob .ec-nb{width:44px;height:44px;font-size:1.1rem}
.ec-hc{display:flex;flex-direction:column;align-items:center;gap:2px;flex:1}
.ec-ht{color:#fff;font-size:.9rem;font-weight:700}
.ec-mb,.ec-yb{background:none;border:none;color:#fff;cursor:pointer;font-family:inherit;padding:3px 12px;border-radius:8px;transition:background .15s;-webkit-tap-highlight-color:transparent}
.ec-mb{font-size:1.05rem;font-weight:700}
.ec-yb{font-size:.75rem;opacity:.85}
.ec-mb:hover,.ec-yb:hover{background:rgba(255,255,255,.2)}
.ec-mob .ec-mb{font-size:1.2rem;padding:5px 16px}
.ec-mob .ec-yb{font-size:.9rem}
.ec-bd{padding:8px 10px 10px}
.ec-mob .ec-bd{padding:12px 16px 16px}
.ec-wk{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:6px}
.ec-wd{text-align:center;font-size:.65rem;color:#94a3b8;padding:4px 0;font-weight:600}
.ec-mob .ec-wd{font-size:.78rem;padding:6px 0}
.ec-dg{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.ec-emp{aspect-ratio:1}
.ec-d{aspect-ratio:1;border:none;border-radius:10px;cursor:pointer;font-size:.85rem;font-weight:500;background:transparent;color:#334155;display:flex;align-items:center;justify-content:center;transition:all .15s;font-family:inherit;min-height:36px;-webkit-tap-highlight-color:transparent}
.ec-d:hover{background:#ede9fe;color:#7c3aed}
.ec-d:active{background:#ddd6fe;transform:scale(.92)}
.ec-d.ec-now{background:#ede9fe;color:#7c3aed;font-weight:700}
.ec-d.ec-sel{background:#7c3aed;color:#fff;font-weight:700;box-shadow:0 2px 8px rgba(124,58,237,.3)}
.ec-mob .ec-d{font-size:1rem;min-height:44px;border-radius:12px}
.ec-ft{display:flex;gap:6px;padding:10px 0 4px;border-top:1px solid #f1f5f9;margin-top:8px}
.ec-mob .ec-ft{padding:12px 0 8px;gap:8px;padding-bottom:max(8px,env(safe-area-inset-bottom))}
.ec-fb{flex:1;padding:8px 6px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;color:#7c3aed;font-size:.72rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:4px;font-family:inherit;transition:all .15s;-webkit-tap-highlight-color:transparent}
.ec-fb:hover{background:#ede9fe;border-color:#c4b5fd}
.ec-fb:active{transform:scale(.97)}
.ec-cl{color:#dc2626;border-color:#fecaca}
.ec-cl:hover{background:#fef2f2;border-color:#f87171}
.ec-cs{color:#64748b;border-color:#cbd5e1}
.ec-mob .ec-fb{padding:12px 6px;font-size:.82rem;border-radius:12px;min-height:44px}
.ec-mgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:4px 0}
.ec-mcell{border:none;border-radius:12px;cursor:pointer;padding:10px 8px;background:#f8fafc;display:flex;flex-direction:column;align-items:center;gap:2px;transition:all .15s;font-family:inherit;-webkit-tap-highlight-color:transparent}
.ec-mcell:hover{background:#ede9fe}
.ec-mcell:active{transform:scale(.95)}
.ec-mcell.ec-sel{background:#7c3aed;color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.25)}
.ec-mcell.ec-sel .ec-men{color:rgba(255,255,255,.7)}
.ec-mcell.ec-now{background:#ede9fe;border:2px solid #7c3aed}
.ec-mam{font-size:.9rem;font-weight:700;color:inherit}
.ec-men{font-size:.6rem;color:#94a3b8}
.ec-mob .ec-mcell{padding:14px 8px}
.ec-mob .ec-mam{font-size:1rem}
.ec-mob .ec-men{font-size:.7rem}
.ec-ygrid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:4px 0}
.ec-ycell{border:none;border-radius:12px;cursor:pointer;padding:14px 8px;background:#f8fafc;font-size:.9rem;font-weight:600;color:#334155;transition:all .15s;font-family:inherit;-webkit-tap-highlight-color:transparent}
.ec-ycell:hover{background:#ede9fe;color:#7c3aed}
.ec-ycell:active{transform:scale(.95)}
.ec-ycell.ec-sel{background:#7c3aed;color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.25)}
.ec-ycell.ec-now{background:#ede9fe;border:2px solid #7c3aed;color:#7c3aed}
.ec-mob .ec-ycell{padding:18px 8px;font-size:1rem}
.ec-wrap input[type="text"]:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1);outline:none}
`;
    document.head.appendChild(s);
}

// ═══ GLOBALS ═══
global.fmtDate=function(d){return formatDate(d,'medium');};
global.fDate=function(d){return formatDate(d,'medium');};
global.fmtDateTime=function(d){return formatDateTime(d);};

global.WBWSCalendar={
    mode:MODE,isEthiopian:MODE==='ethiopian',
    toEthiopian:toEthiopian,toGregorian:toGregorian,
    formatDate:formatDate,formatDateTime:formatDateTime,
    todayFormatted:todayFormatted,currentECYear:currentECYear,currentECMonth:currentECMonth,
    months:{am:EC_MONTHS_AM,en:EC_MONTHS_EN,short:EC_MONTHS_SHORT},
    days:{am:EC_DAYS_AM},
    daysInMonth:daysInMonth,
    initPickers:initDatePickers,convertInput:convertPicker,
    refreshPickers:function(){document.querySelectorAll('input[type="date"]:not([data-ec-init])').forEach(inp=>convertPicker(inp));}
};

// ═══ AUTO-INIT ═══
injectStyles();
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initDatePickers);
else initDatePickers();

new MutationObserver(function(muts){
    for(const m of muts)if(m.type==='childList'&&m.addedNodes.length){setTimeout(()=>WBWSCalendar.refreshPickers(),100);break;}
}).observe(document.body,{childList:true,subtree:true});

})(window);
