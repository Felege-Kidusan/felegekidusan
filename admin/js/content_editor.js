'use strict';

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const API = '/admin/api_cms.php';

/* ─── helpers ─── */
function esc(s){const d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
function escAttr(s){return esc(s).replace(/\"/g,'&quot;').replace(/'/g,'&#39;');}
function toast(msg,isError){const t=document.getElementById('toast');const icon=document.createElement('i');icon.className=isError?'fa-solid fa-circle-exclamation':'fa-solid fa-circle-check';t.replaceChildren(icon,document.createTextNode(' '+String(msg??'')));t.className='toast show'+(isError?' error':'');setTimeout(()=>t.className='toast',3000);}
function bindImageFallbacks(container){
  container.querySelectorAll('img[data-image-fallback]').forEach(image=>{
    image.addEventListener('error',()=>{
      if(image.dataset.imageFallback==='hide'){
        image.hidden=true;
        return;
      }
      const fallback=document.createElement('div');
      fallback.className='miss';
      const icon=document.createElement('i');
      icon.className='fa-solid fa-image';
      fallback.replaceChildren(icon,document.createTextNode(' Could not load'));
      image.replaceWith(fallback);
    },{once:true});
  });
}

async function api(action, formData){
  formData = formData || new FormData();
  formData.append('action', action);
  formData.append('csrf_token', CSRF);
  try{
    const r = await fetch(API,{method:'POST',body:formData,headers:{'Accept':'application/json'}});
    const ct = r.headers.get('content-type')||'';
    if(!ct.includes('application/json')){
      if(r.redirected||r.url.includes('index.php')){toast('Session expired. Reloading…',true);setTimeout(()=>location.reload(),1200);return null;}
      toast('Unexpected server response',true);return null;
    }
    const d = await r.json();
    if(d.status==='session_expired'){toast('Session expired. Reloading…',true);setTimeout(()=>location.reload(),1200);return null;}
    return d;
  }catch(e){
    if(!navigator.onLine){toast('You are offline',true);return null;}
    toast('Connection error',true);console.error(e);return null;
  }
}

async function apiGet(action, params){
  const qs = new URLSearchParams({action, ...(params||{})}).toString();
  try{
    const r = await fetch(API+'?'+qs,{headers:{'Accept':'application/json'}});
    const ct=r.headers.get('content-type')||'';
    if(!ct.includes('application/json')){location.reload();return null;}
    return await r.json();
  }catch(e){toast('Connection error',true);return null;}
}

/* ─── tab switching ─── */
document.querySelectorAll('.tab').forEach(t=>{
  t.addEventListener('click',()=>{
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(x=>x.classList.remove('active'));
    t.classList.add('active');
    const p = t.dataset.panel;
    document.getElementById('panel-'+p).classList.add('active');
    loadPanel(p);
  });
});

const loaded = {};
function loadPanel(p, force){
  if(loaded[p] && !force) return;
  loaded[p] = true;
  if(p==='registrations') loadRegistrations('all');
  if(p==='gallery') loadGallery();
  if(p==='teachers') loadTeachers();
  if(p==='schedule') loadSchedule();
  if(p==='programs') loadPrograms();
  if(p==='social') loadSocial();
}

/* ════════ REGISTRATIONS ════════ */
let currentRegFilter='all';
let registrations=[];
async function loadRegistrations(filter){
  currentRegFilter = filter||'all';
  const d = await apiGet('sub_list',{filter:currentRegFilter});
  if(!d||d.status!=='success') return;
  registrations=Array.isArray(d.data)?d.data:[];
  // badges
  const counts = d.counts||{};
  document.querySelectorAll('#regFilters .badge').forEach(b=>{b.textContent=counts[b.dataset.count]||0;});
  const newCount = counts.new||0;
  const rb=document.getElementById('regBadge');
  if(newCount>0){rb.style.display='inline-block';rb.textContent=newCount;}else{rb.style.display='none';}

  const list=document.getElementById('regList');
  if(!registrations.length){list.innerHTML='<div class="empty"><i class="fa-solid fa-inbox"></i>No submissions yet.</div>';return;}
  let html='<table class="tbl"><thead><tr><th>Name</th><th>Phone</th><th>Interest</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody>';
  registrations.forEach((s,index)=>{
    const dt=(typeof WBWSCalendar!=='undefined')?WBWSCalendar.formatDate(s.created_at,'medium'):new Date(s.created_at).toLocaleDateString();
    const status=['new','contacted','enrolled','rejected'].includes(s.status)?s.status:'new';
    html+=`<tr>
      <td><strong>${esc(s.full_name)}</strong>${s.email?'<br><span style="font-size:0.72rem;color:var(--text-dim)">'+esc(s.email)+'</span>':''}</td>
      <td>${esc(s.phone)}</td>
      <td>${esc(s.program_interest||'—')}</td>
      <td>${esc(dt)}</td>
      <td><span class="pill pill-${status}">${esc(status)}</span></td>
      <td><button class="btn btn-ghost btn-sm" type="button" data-content-action="registration-view" data-record-index="${index}"><i class="fa-solid fa-eye"></i></button></td>
    </tr>`;
  });
  html+='</tbody></table>';
  list.innerHTML=html;
}
document.querySelectorAll('#regFilters .subtab').forEach(s=>{
  s.addEventListener('click',()=>{
    document.querySelectorAll('#regFilters .subtab').forEach(x=>x.classList.remove('active'));
    s.classList.add('active');
    loadRegistrations(s.dataset.filter);
  });
});

function viewSub(index){
  const s=registrations[index];
  if(!s)return;
  modalMode={type:'sub',id:s.id};
  document.getElementById('modalTitle').textContent='Submission Details';
  document.getElementById('modalBody').innerHTML=`
    <div class="field"><label>Name</label><div style="padding:0.5rem 0;font-weight:600">${esc(s.full_name)}</div></div>
    <div class="field-row">
      <div class="field"><label>Phone</label><div style="padding:0.5rem 0">${esc(s.phone)}</div></div>
      <div class="field"><label>Email</label><div style="padding:0.5rem 0">${esc(s.email||'—')}</div></div>
    </div>
    <div class="field-row">
      <div class="field"><label>Age</label><div style="padding:0.5rem 0">${esc(s.age||'—')}</div></div>
      <div class="field"><label>Gender</label><div style="padding:0.5rem 0">${esc(s.gender||'—')}</div></div>
    </div>
    <div class="field"><label>Address</label><div style="padding:0.5rem 0">${esc(s.address||'—')}</div></div>
    <div class="field"><label>Program Interest</label><div style="padding:0.5rem 0">${esc(s.program_interest||'—')}</div></div>
    <div class="field"><label>Message</label><div style="padding:0.5rem 0;white-space:pre-wrap">${esc(s.message||'—')}</div></div>
    <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0">
    <div class="field"><label>Status</label>
      <select id="m_status">
        <option value="new"${s.status==='new'?' selected':''}>New</option>
        <option value="contacted"${s.status==='contacted'?' selected':''}>Contacted</option>
        <option value="enrolled"${s.status==='enrolled'?' selected':''}>Enrolled</option>
        <option value="rejected"${s.status==='rejected'?' selected':''}>Rejected</option>
      </select>
    </div>
    <div class="field"><label>Admin Notes</label><textarea id="m_notes" placeholder="Internal notes (not shown publicly)">${esc(s.admin_notes||'')}</textarea></div>
    <button class="btn btn-danger btn-sm" type="button" data-content-action="registration-delete" data-record-index="${index}"><i class="fa-solid fa-trash"></i> Delete this submission</button>
  `;
  document.getElementById('modalBg').classList.add('open');
}
async function deleteSub(id){
  if(!confirm('Delete this submission permanently?'))return;
  const fd=new FormData();fd.append('id',id);
  const d=await api('sub_delete',fd);
  if(d&&d.status==='success'){toast(d.message);closeModal();loadRegistrations(currentRegFilter);}
  else if(d) toast(d.message,true);
}

/* ════════ GALLERY ════════ */
let galCategories=[];let galleryPhotos=[];let currentGalCat=0;
async function loadGallery(){
  const c=await apiGet('cat_list');
  if(c&&c.status==='success'){galCategories=Array.isArray(c.data)?c.data:[];renderGalFilters();}
  loadPhotos(currentGalCat);
}
function renderGalFilters(){
  let html='<div class="subtab'+(currentGalCat===0?' active':'')+'" data-content-action="category-all">All Photos</div>';
  galCategories.forEach((c,index)=>{
    html+=`<div class="subtab${currentGalCat==c.id?' active':''}" data-content-action="category-select" data-record-index="${index}">${esc(c.name)} <button type="button" class="modal-close" style="font-size:0.9rem;padding:0 0 0 4px" data-content-action="category-edit" data-record-index="${index}"><i class="fa-solid fa-pen" style="font-size:0.6rem"></i></button></div>`;
  });
  document.getElementById('galCatFilters').innerHTML=html;
}
async function loadPhotos(catId){
  currentGalCat=catId;renderGalFilters();
  const d=await apiGet('photo_list',catId?{category_id:catId}:{});
  const grid=document.getElementById('photoGrid');
  if(!d||d.status!=='success'){grid.innerHTML='<div class="empty" style="grid-column:1/-1">Could not load photos. Check your connection.</div>';return;}
  galleryPhotos=Array.isArray(d.data)?d.data:[];
  if(!galleryPhotos.length){grid.innerHTML='<div class="empty" style="grid-column:1/-1"><i class="fa-solid fa-images"></i>No photos yet. Click "Upload Photo" to add some.</div>';return;}
  grid.innerHTML=galleryPhotos.map((p,index)=>{
    const src=escAttr(p.view_thumb||p.thumb_path||p.image_path||'');
    const missing=p.file_exists===false && !p.view_thumb;
    return `
    <div class="card photo-card">
      ${p.is_featured==1?'<div class="feat-star"><i class="fa-solid fa-star"></i></div>':''}
      ${missing?'<div class="miss"><i class="fa-solid fa-image"></i>File missing</div>':`<img src="${src}" alt="${escAttr(p.caption||'')}" data-image-fallback="card">`}
      <div class="card-body">
        <div class="card-title" style="font-size:0.8rem">${esc(p.caption||'Untitled')}</div>
        <div class="card-meta">${esc(p.category_name||'Uncategorized')}</div>
        <div class="card-actions">
          <button class="btn btn-ghost btn-sm" type="button" data-content-action="photo-edit" data-record-index="${index}"><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-danger btn-sm" type="button" data-content-action="photo-delete" data-record-index="${index}"><i class="fa-solid fa-trash"></i></button>
        </div>
      </div>
    </div>`;
  }).join('');
  bindImageFallbacks(grid);
}
function catOptions(sel){
  return galCategories.map(c=>`<option value="${escAttr(c.id)}"${sel==c.id?' selected':''}>${esc(c.name)}</option>`).join('');
}
function openPhotoModal(){
  modalMode={type:'photo_new'};
  document.getElementById('modalTitle').textContent='Upload Photo';
  document.getElementById('modalBody').innerHTML=`
    <div class="field"><label>Images *</label><input type="file" id="m_image" accept="image/jpeg,image/png,image/gif,image/webp" multiple><div class="hint">JPG, PNG, GIF or WebP · max 8MB each · you can pick several at once</div></div>
    <div class="field"><label>Album</label><select id="m_category"><option value="">— Uncategorized —</option>${catOptions(currentGalCat)}</select></div>
    <div class="field"><label>Caption (English)</label><input type="text" id="m_caption" placeholder="e.g. Christmas celebration 2025"></div>
    <div class="field"><label>Caption (Amharic)</label><input type="text" id="m_caption_am" class="amharic" placeholder="የገና በዓል"></div>
    <div class="check-row"><input type="checkbox" id="m_featured"><label style="margin:0">Featured (show in slideshow)</label></div>
  `;
  document.getElementById('modalBg').classList.add('open');
}
function editPhoto(p){
  modalMode={type:'photo_edit',id:p.id};
  document.getElementById('modalTitle').textContent='Edit Photo';
  document.getElementById('modalBody').innerHTML=`
    <div style="text-align:center;margin-bottom:1rem"><img src="${escAttr(p.view_thumb||p.thumb_path||p.image_path)}" style="max-width:100%;max-height:160px;border-radius:0.5rem" data-image-fallback="hide"></div>
    <div class="field"><label>Replace image</label><input type="file" id="m_image" accept="image/jpeg,image/png,image/gif,image/webp"><div class="hint">Leave empty to keep the current photo</div></div>
    <div class="field"><label>Album</label><select id="m_category"><option value="">— Uncategorized —</option>${catOptions(p.category_id)}</select></div>
    <div class="field"><label>Caption (English)</label><input type="text" id="m_caption" value="${escAttr(p.caption||'')}"></div>
    <div class="field"><label>Caption (Amharic)</label><input type="text" id="m_caption_am" class="amharic" value="${escAttr(p.caption_am||'')}"></div>
    <div class="check-row"><input type="checkbox" id="m_featured"${p.is_featured==1?' checked':''}><label style="margin:0">Featured (show in slideshow)</label></div>
  `;
  bindImageFallbacks(document.getElementById('modalBody'));
  document.getElementById('modalBg').classList.add('open');
}
async function deletePhoto(id){
  if(!confirm('Delete this photo?'))return;
  const fd=new FormData();fd.append('id',id);
  const d=await api('photo_delete',fd);
  if(d&&d.status==='success'){toast(d.message);loadPhotos(currentGalCat);}
  else if(d)toast(d.message,true);
}
function openCatModal(){
  modalMode={type:'cat_new'};
  document.getElementById('modalTitle').textContent='New Album';
  document.getElementById('modalBody').innerHTML=`
    <div class="field"><label>Album Name (English) *</label><input type="text" id="m_name" placeholder="e.g. Events"></div>
    <div class="field"><label>Album Name (Amharic)</label><input type="text" id="m_name_am" class="amharic"></div>
    <div class="field"><label>Description</label><textarea id="m_description"></textarea></div>
  `;
  document.getElementById('modalBg').classList.add('open');
}
function editCat(c){
  modalMode={type:'cat_edit',id:c.id};
  document.getElementById('modalTitle').textContent='Edit Album';
  document.getElementById('modalBody').innerHTML=`
    <div class="field"><label>Album Name (English) *</label><input type="text" id="m_name" value="${escAttr(c.name)}"></div>
    <div class="field"><label>Album Name (Amharic)</label><input type="text" id="m_name_am" class="amharic" value="${escAttr(c.name_am||'')}"></div>
    <div class="field"><label>Description</label><textarea id="m_description">${esc(c.description||'')}</textarea></div>
    <button class="btn btn-danger btn-sm" type="button" id="deleteCategory"><i class="fa-solid fa-trash"></i> Delete album</button>
  `;
  document.getElementById('deleteCategory').addEventListener('click',()=>deleteCat(c.id));
  document.getElementById('modalBg').classList.add('open');
}
async function deleteCat(id){
  if(!confirm('Delete this album? Photos inside become uncategorized.'))return;
  const fd=new FormData();fd.append('id',id);
  const d=await api('cat_delete',fd);
  if(d&&d.status==='success'){toast(d.message);closeModal();currentGalCat=0;loadGallery();}
  else if(d)toast(d.message,true);
}

/* ════════ TEACHERS ════════ */
let teachers=[];
async function loadTeachers(){
  const d=await apiGet('teacher_list');
  const grid=document.getElementById('teacherGrid');
  if(!d||d.status!=='success'){grid.innerHTML='';return;}
  teachers=Array.isArray(d.data)?d.data:[];
  if(!teachers.length){grid.innerHTML='<div class="empty" style="grid-column:1/-1"><i class="fa-solid fa-chalkboard-user"></i>No teachers added yet.</div>';return;}
  grid.innerHTML=teachers.map((t,index)=>`
    <div class="card">
      ${t.photo_path?`<img src="${escAttr(t.photo_path)}" style="width:100%;height:160px;object-fit:cover">`:`<div style="height:160px;background:var(--bg);display:flex;align-items:center;justify-content:center;color:var(--border);font-size:2.5rem"><i class="fa-solid fa-user"></i></div>`}
      <div class="card-body">
        <div class="card-title amharic">${esc(t.name_am||t.name)}</div>
        <div class="card-meta">${esc(t.role_title||'')}</div>
        ${t.is_active==0?'<div style="font-size:0.7rem;color:#b91c1c;margin-top:3px"><i class="fa-solid fa-eye-slash"></i> Hidden</div>':''}
        <div class="card-actions">
          <button class="btn btn-ghost btn-sm" type="button" data-content-action="teacher-edit" data-record-index="${index}"><i class="fa-solid fa-pen"></i> Edit</button>
          <button class="btn btn-danger btn-sm" type="button" data-content-action="teacher-delete" data-record-index="${index}"><i class="fa-solid fa-trash"></i></button>
        </div>
      </div>
    </div>`).join('');
}
function teacherForm(t){
  t=t||{};
  return `
    <div class="field"><label>Photo</label><input type="file" id="m_photo" accept="image/*">${t.photo_path?`<div class="hint">Current photo will be kept unless you choose a new one</div>`:''}</div>
    <div class="field-row">
      <div class="field"><label>Name (English) *</label><input type="text" id="m_name" value="${escAttr(t.name||'')}"></div>
      <div class="field"><label>Name (Amharic)</label><input type="text" id="m_name_am" class="amharic" value="${escAttr(t.name_am||'')}"></div>
    </div>
    <div class="field-row">
      <div class="field"><label>Role/Title (English)</label><input type="text" id="m_role_title" value="${escAttr(t.role_title||'')}" placeholder="e.g. Head Teacher"></div>
      <div class="field"><label>Role/Title (Amharic)</label><input type="text" id="m_role_title_am" class="amharic" value="${escAttr(t.role_title_am||'')}"></div>
    </div>
    <div class="field"><label>Short Bio</label><textarea id="m_bio">${esc(t.bio||'')}</textarea></div>
    <div class="field-row">
      <div class="field"><label>Display Order</label><input type="number" id="m_sort_order" value="${escAttr(t.sort_order||0)}"></div>
      <div class="field"><label>&nbsp;</label><div class="check-row"><input type="checkbox" id="m_active"${(t.is_active==1||t.id===undefined)?' checked':''}><label style="margin:0">Show on website</label></div></div>
    </div>`;
}
function openTeacherModal(){modalMode={type:'teacher_new'};document.getElementById('modalTitle').textContent='Add Teacher';document.getElementById('modalBody').innerHTML=teacherForm();document.getElementById('modalBg').classList.add('open');}
function editTeacher(t){modalMode={type:'teacher_edit',id:t.id};document.getElementById('modalTitle').textContent='Edit Teacher';document.getElementById('modalBody').innerHTML=teacherForm(t);document.getElementById('modalBg').classList.add('open');}
async function deleteTeacher(id){if(!confirm('Delete this teacher?'))return;const fd=new FormData();fd.append('id',id);const d=await api('teacher_delete',fd);if(d&&d.status==='success'){toast(d.message);loadTeachers();}else if(d)toast(d.message,true);}

/* ════════ SCHEDULE ════════ */
let schedules=[];
async function loadSchedule(){
  const d=await apiGet('schedule_list');
  const list=document.getElementById('scheduleList');
  if(!d||d.status!=='success'){list.innerHTML='';return;}
  schedules=Array.isArray(d.data)?d.data:[];
  if(!schedules.length){list.innerHTML='<div class="empty"><i class="fa-solid fa-calendar-week"></i>No schedule entries yet.</div>';return;}
  let html='<table class="tbl"><thead><tr><th>Day</th><th>Time</th><th>Activity</th><th>Location</th><th>Visible</th><th></th></tr></thead><tbody>';
  schedules.forEach((s,index)=>{
    html+=`<tr>
      <td><strong>${esc(s.day_of_week)}</strong>${s.day_of_week_am?'<br><span class="amharic" style="font-size:0.72rem;color:var(--text-dim)">'+esc(s.day_of_week_am)+'</span>':''}</td>
      <td>${esc(s.time_label||'—')}</td>
      <td>${esc(s.activity)}${s.activity_am?'<br><span class="amharic" style="font-size:0.72rem;color:var(--text-dim)">'+esc(s.activity_am)+'</span>':''}</td>
      <td>${esc(s.location||'—')}</td>
      <td>${s.is_active==1?'<i class="fa-solid fa-eye" style="color:#166534"></i>':'<i class="fa-solid fa-eye-slash" style="color:#b91c1c"></i>'}</td>
      <td><button class="btn btn-ghost btn-sm" type="button" data-content-action="schedule-edit" data-record-index="${index}"><i class="fa-solid fa-pen"></i></button> <button class="btn btn-danger btn-sm" type="button" data-content-action="schedule-delete" data-record-index="${index}"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  });
  html+='</tbody></table>';
  list.innerHTML=html;
}
function scheduleForm(s){
  s=s||{};
  return `
    <div class="field-row">
      <div class="field"><label>Day (English) *</label><input type="text" id="m_day_of_week" value="${escAttr(s.day_of_week||'')}" placeholder="Sunday"></div>
      <div class="field"><label>Day (Amharic)</label><input type="text" id="m_day_of_week_am" class="amharic" value="${escAttr(s.day_of_week_am||'')}" placeholder="እሁድ"></div>
    </div>
    <div class="field"><label>Time</label><input type="text" id="m_time_label" value="${escAttr(s.time_label||'')}" placeholder="e.g. 8:00 AM - 10:00 AM"></div>
    <div class="field-row">
      <div class="field"><label>Activity (English) *</label><input type="text" id="m_activity" value="${escAttr(s.activity||'')}"></div>
      <div class="field"><label>Activity (Amharic)</label><input type="text" id="m_activity_am" class="amharic" value="${escAttr(s.activity_am||'')}"></div>
    </div>
    <div class="field"><label>Location</label><input type="text" id="m_location" value="${escAttr(s.location||'')}"></div>
    <div class="field-row">
      <div class="field"><label>Display Order</label><input type="number" id="m_sort_order" value="${escAttr(s.sort_order||0)}"></div>
      <div class="field"><label>&nbsp;</label><div class="check-row"><input type="checkbox" id="m_active"${(s.is_active==1||s.id===undefined)?' checked':''}><label style="margin:0">Show on website</label></div></div>
    </div>`;
}
function openScheduleModal(){modalMode={type:'schedule_new'};document.getElementById('modalTitle').textContent='Add Schedule Entry';document.getElementById('modalBody').innerHTML=scheduleForm();document.getElementById('modalBg').classList.add('open');}
function editSchedule(s){modalMode={type:'schedule_edit',id:s.id};document.getElementById('modalTitle').textContent='Edit Schedule Entry';document.getElementById('modalBody').innerHTML=scheduleForm(s);document.getElementById('modalBg').classList.add('open');}
async function deleteSchedule(id){if(!confirm('Delete this schedule entry?'))return;const fd=new FormData();fd.append('id',id);const d=await api('schedule_delete',fd);if(d&&d.status==='success'){toast(d.message);loadSchedule();}else if(d)toast(d.message,true);}

/* ════════ PROGRAMS ════════ */
let programs=[];
async function loadPrograms(){
  const d=await apiGet('program_list');
  const grid=document.getElementById('programGrid');
  if(!d||d.status!=='success'){grid.innerHTML='';return;}
  programs=Array.isArray(d.data)?d.data:[];
  if(!programs.length){grid.innerHTML='<div class="empty" style="grid-column:1/-1"><i class="fa-solid fa-graduation-cap"></i>No programs yet.</div>';return;}
  grid.innerHTML=programs.map((p,index)=>`
    <div class="card"><div class="card-body">
      <div style="font-size:1.5rem;color:var(--gold-dark);margin-bottom:0.5rem"><i class="${escAttr(p.icon_class||'fa-solid fa-book')}"></i></div>
      <div class="card-title">${esc(p.title)}</div>
      ${p.title_am?'<div class="card-meta amharic">'+esc(p.title_am)+'</div>':''}
      <div class="card-meta" style="margin-top:0.4rem;line-height:1.4">${esc((p.description||'').substring(0,80))}${(p.description||'').length>80?'…':''}</div>
      ${p.is_active==0?'<div style="font-size:0.7rem;color:#b91c1c;margin-top:5px"><i class="fa-solid fa-eye-slash"></i> Hidden</div>':''}
      <div class="card-actions">
        <button class="btn btn-ghost btn-sm" type="button" data-content-action="program-edit" data-record-index="${index}"><i class="fa-solid fa-pen"></i> Edit</button>
        <button class="btn btn-danger btn-sm" type="button" data-content-action="program-delete" data-record-index="${index}"><i class="fa-solid fa-trash"></i></button>
      </div>
    </div></div>`).join('');
}
function programForm(p){
  p=p||{};
  return `
    <div class="field"><label>Icon (Font Awesome class)</label><input type="text" id="m_icon_class" value="${escAttr(p.icon_class||'fa-solid fa-book')}" placeholder="fa-solid fa-book"><div class="hint">Find icons at fontawesome.com/icons — e.g. fa-solid fa-cross, fa-solid fa-music</div></div>
    <div class="field-row">
      <div class="field"><label>Title (English) *</label><input type="text" id="m_title" value="${escAttr(p.title||'')}"></div>
      <div class="field"><label>Title (Amharic)</label><input type="text" id="m_title_am" class="amharic" value="${escAttr(p.title_am||'')}"></div>
    </div>
    <div class="field"><label>Description (English)</label><textarea id="m_description">${esc(p.description||'')}</textarea></div>
    <div class="field"><label>Description (Amharic)</label><textarea id="m_description_am" class="amharic">${esc(p.description_am||'')}</textarea></div>
    <div class="field"><label>Key Features (one per line)</label><textarea id="m_features" placeholder="Bible study&#10;Prayer&#10;Hymns">${esc(p.features||'')}</textarea><div class="hint">Each line becomes a bullet point with a checkmark</div></div>
    <div class="field-row">
      <div class="field"><label>Display Order</label><input type="number" id="m_sort_order" value="${escAttr(p.sort_order||0)}"></div>
      <div class="field"><label>&nbsp;</label><div class="check-row"><input type="checkbox" id="m_active"${(p.is_active==1||p.id===undefined)?' checked':''}><label style="margin:0">Show on website</label></div></div>
    </div>`;
}
function openProgramModal(){modalMode={type:'program_new'};document.getElementById('modalTitle').textContent='Add Program';document.getElementById('modalBody').innerHTML=programForm();document.getElementById('modalBg').classList.add('open');}
function editProgram(p){modalMode={type:'program_edit',id:p.id};document.getElementById('modalTitle').textContent='Edit Program';document.getElementById('modalBody').innerHTML=programForm(p);document.getElementById('modalBg').classList.add('open');}
async function deleteProgram(id){if(!confirm('Delete this program?'))return;const fd=new FormData();fd.append('id',id);const d=await api('program_delete',fd);if(d&&d.status==='success'){toast(d.message);loadPrograms();}else if(d)toast(d.message,true);}

/* ════════ SOCIAL ════════ */
let socialLinks=[];
async function loadSocial(){
  const d=await apiGet('social_list');
  const list=document.getElementById('socialList');
  if(!d||d.status!=='success'){list.innerHTML='';return;}
  socialLinks=Array.isArray(d.data)?d.data:[];
  if(!socialLinks.length){list.innerHTML='<div class="empty"><i class="fa-solid fa-share-nodes"></i>No social links yet.</div>';return;}
  let html='<table class="tbl"><thead><tr><th>Icon</th><th>Platform</th><th>URL</th><th>Visible</th><th></th></tr></thead><tbody>';
  socialLinks.forEach((s,index)=>{
    html+=`<tr>
      <td style="font-size:1.2rem;color:var(--maroon)"><i class="${escAttr(s.icon_class)}"></i></td>
      <td><strong>${esc(s.platform)}</strong></td>
      <td style="font-size:0.78rem;color:var(--text-dim);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.url)}</td>
      <td>${s.is_active==1?'<i class="fa-solid fa-eye" style="color:#166534"></i>':'<i class="fa-solid fa-eye-slash" style="color:#b91c1c"></i>'}</td>
      <td><button class="btn btn-ghost btn-sm" type="button" data-content-action="social-edit" data-record-index="${index}"><i class="fa-solid fa-pen"></i></button> <button class="btn btn-danger btn-sm" type="button" data-content-action="social-delete" data-record-index="${index}"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  });
  html+='</tbody></table>';
  list.innerHTML=html;
}
const ICONS={Facebook:'fa-brands fa-facebook',Telegram:'fa-brands fa-telegram',YouTube:'fa-brands fa-youtube',TikTok:'fa-brands fa-tiktok',Instagram:'fa-brands fa-instagram',Twitter:'fa-brands fa-x-twitter',WhatsApp:'fa-brands fa-whatsapp',LinkedIn:'fa-brands fa-linkedin'};
function socialForm(s){
  s=s||{};
  const opts=Object.keys(ICONS).map(p=>`<option value="${p}"${s.platform===p?' selected':''}>${p}</option>`).join('');
  return `
    <div class="field"><label>Platform *</label><select id="m_platform">${opts}<option value="Other"${s.platform&&!ICONS[s.platform]?' selected':''}>Other</option></select></div>
    <div class="field"><label>Icon class</label><input type="text" id="m_icon_class" value="${escAttr(s.icon_class||'fa-brands fa-facebook')}"></div>
    <div class="field"><label>URL *</label><input type="text" id="m_url" value="${escAttr(s.url||'')}" placeholder="https://..."></div>
    <div class="field"><label>Label (optional)</label><input type="text" id="m_label" value="${escAttr(s.label||'')}"></div>
    <div class="field-row">
      <div class="field"><label>Display Order</label><input type="number" id="m_sort_order" value="${escAttr(s.sort_order||0)}"></div>
      <div class="field"><label>&nbsp;</label><div class="check-row"><input type="checkbox" id="m_active"${(s.is_active==1||s.id===undefined)?' checked':''}><label style="margin:0">Show on website</label></div></div>
    </div>`;
}
function bindSocialPlatform(){
  const platform=document.getElementById('m_platform');
  platform?.addEventListener('change',()=>{
    const icon=document.getElementById('m_icon_class');
    if(icon)icon.value=ICONS[platform.value]||'fa-solid fa-link';
  });
}
function openSocialModal(){modalMode={type:'social_new'};document.getElementById('modalTitle').textContent='Add Social Link';document.getElementById('modalBody').innerHTML=socialForm();bindSocialPlatform();document.getElementById('modalBg').classList.add('open');}
function editSocial(s){modalMode={type:'social_edit',id:s.id};document.getElementById('modalTitle').textContent='Edit Social Link';document.getElementById('modalBody').innerHTML=socialForm(s);bindSocialPlatform();document.getElementById('modalBg').classList.add('open');}
async function deleteSocial(id){if(!confirm('Delete this social link?'))return;const fd=new FormData();fd.append('id',id);const d=await api('social_delete',fd);if(d&&d.status==='success'){toast(d.message);loadSocial();}else if(d)toast(d.message,true);}

/* Generated CMS records use array indexes instead of serializing database objects into
 * executable HTML attributes. This delegated handler keeps rendering declarative and
 * ensures untrusted record values never become JavaScript source. */
document.addEventListener('click',event=>{
  const control=event.target.closest('[data-content-action]');
  if(!control)return;
  const action=control.dataset.contentAction;
  if(action==='category-all'){
    loadPhotos(0);
    return;
  }
  const index=Number.parseInt(control.dataset.recordIndex||'',10);
  if(!Number.isInteger(index)||index<0)return;
  if(action==='registration-view')viewSub(index);
  else if(action==='registration-delete'&&registrations[index])deleteSub(registrations[index].id);
  else if(action==='category-select'&&galCategories[index])loadPhotos(galCategories[index].id);
  else if(action==='category-edit'&&galCategories[index])editCat(galCategories[index]);
  else if(action==='photo-edit'&&galleryPhotos[index])editPhoto(galleryPhotos[index]);
  else if(action==='photo-delete'&&galleryPhotos[index])deletePhoto(galleryPhotos[index].id);
  else if(action==='teacher-edit'&&teachers[index])editTeacher(teachers[index]);
  else if(action==='teacher-delete'&&teachers[index])deleteTeacher(teachers[index].id);
  else if(action==='schedule-edit'&&schedules[index])editSchedule(schedules[index]);
  else if(action==='schedule-delete'&&schedules[index])deleteSchedule(schedules[index].id);
  else if(action==='program-edit'&&programs[index])editProgram(programs[index]);
  else if(action==='program-delete'&&programs[index])deleteProgram(programs[index].id);
  else if(action==='social-edit'&&socialLinks[index])editSocial(socialLinks[index]);
  else if(action==='social-delete'&&socialLinks[index])deleteSocial(socialLinks[index].id);
});

/* ════════ MODAL SAVE ROUTER ════════ */
let modalMode={};
function closeModal(){document.getElementById('modalBg').classList.remove('open');modalMode={};}
function val(id){const el=document.getElementById(id);return el?el.value:'';}
function checked(id){const el=document.getElementById(id);return el&&el.checked;}
function fileOf(id){const el=document.getElementById(id);return el&&el.files&&el.files[0]?el.files[0]:null;}

async function saveModal(){
  const btn=document.getElementById('modalSave');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
  const fd=new FormData();
  let action='';
  const m=modalMode;

  if(m.type==='cat_new'||m.type==='cat_edit'){
    action='cat_save';if(m.id)fd.append('id',m.id);
    fd.append('name',val('m_name'));fd.append('name_am',val('m_name_am'));fd.append('description',val('m_description'));
  }
  else if(m.type==='photo_new'){
    action='photo_upload';
    const picker=document.getElementById('m_image');
    if(!picker||!picker.files||!picker.files.length){toast('Please choose an image',true);resetBtn();return;}
    if(picker.files.length===1){fd.append('image',picker.files[0]);}
    else{for(let i=0;i<picker.files.length;i++) fd.append('images[]',picker.files[i]);}
    fd.append('category_id',val('m_category'));fd.append('caption',val('m_caption'));fd.append('caption_am',val('m_caption_am'));if(checked('m_featured'))fd.append('is_featured','1');
  }
  else if(m.type==='photo_edit'){
    action='photo_update';fd.append('id',m.id);
    const repl=fileOf('m_image');if(repl)fd.append('image',repl);
    fd.append('category_id',val('m_category'));fd.append('caption',val('m_caption'));fd.append('caption_am',val('m_caption_am'));if(checked('m_featured'))fd.append('is_featured','1');
  }
  else if(m.type==='teacher_new'||m.type==='teacher_edit'){
    action='teacher_save';if(m.id)fd.append('id',m.id);
    const ph=fileOf('m_photo');if(ph)fd.append('photo',ph);
    fd.append('name',val('m_name'));fd.append('name_am',val('m_name_am'));fd.append('role_title',val('m_role_title'));fd.append('role_title_am',val('m_role_title_am'));fd.append('bio',val('m_bio'));fd.append('sort_order',val('m_sort_order'));if(checked('m_active'))fd.append('is_active','1');
  }
  else if(m.type==='schedule_new'||m.type==='schedule_edit'){
    action='schedule_save';if(m.id)fd.append('id',m.id);
    fd.append('day_of_week',val('m_day_of_week'));fd.append('day_of_week_am',val('m_day_of_week_am'));fd.append('time_label',val('m_time_label'));fd.append('activity',val('m_activity'));fd.append('activity_am',val('m_activity_am'));fd.append('location',val('m_location'));fd.append('sort_order',val('m_sort_order'));if(checked('m_active'))fd.append('is_active','1');
  }
  else if(m.type==='program_new'||m.type==='program_edit'){
    action='program_save';if(m.id)fd.append('id',m.id);
    fd.append('title',val('m_title'));fd.append('title_am',val('m_title_am'));fd.append('description',val('m_description'));fd.append('description_am',val('m_description_am'));fd.append('icon_class',val('m_icon_class'));fd.append('features',val('m_features'));fd.append('sort_order',val('m_sort_order'));if(checked('m_active'))fd.append('is_active','1');
  }
  else if(m.type==='social_new'||m.type==='social_edit'){
    action='social_save';if(m.id)fd.append('id',m.id);
    fd.append('platform',val('m_platform'));fd.append('url',val('m_url'));fd.append('icon_class',val('m_icon_class'));fd.append('label',val('m_label'));fd.append('sort_order',val('m_sort_order'));if(checked('m_active'))fd.append('is_active','1');
  }
  else if(m.type==='sub'){
    action='sub_update_status';fd.append('id',m.id);fd.append('status',val('m_status'));fd.append('admin_notes',val('m_notes'));
  }

  const d=await api(action,fd);
  resetBtn();
  if(d&&d.status==='success'){
    toast(d.message);closeModal();
    // reload the relevant panel
    if(action.startsWith('cat')||action.startsWith('photo')){currentGalCat=currentGalCat;loadGallery();}
    else if(action.startsWith('teacher'))loadTeachers();
    else if(action.startsWith('schedule'))loadSchedule();
    else if(action.startsWith('program'))loadPrograms();
    else if(action.startsWith('social'))loadSocial();
    else if(action.startsWith('sub'))loadRegistrations(currentRegFilter);
  }else if(d){toast(d.message,true);}
  function resetBtn(){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Save';}
}
function resetBtn(){const btn=document.getElementById('modalSave');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Save';}

// Close modal on background click
document.getElementById('modalBg').addEventListener('click',e=>{if(e.target.id==='modalBg')closeModal();});

// Initial load
loadRegistrations('all');
