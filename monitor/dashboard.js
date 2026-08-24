'use strict';

const csrfToken=document.querySelector('meta[name="csrf-token"]')?.content||'';
let currentErrorId=null;

function validErrorId(value){
  const id=Number.parseInt(String(value),10);
  return Number.isSafeInteger(id)&&id>0?id:null;
}

async function requestJson(url,options={}){
  const {headers={},...requestOptions}=options;
  const response=await fetch(url,{...requestOptions,headers:{Accept:'application/json',...headers}});
  if(response.status===401){
    location.assign('/monitor/');
    throw new Error('Monitor authorization expired');
  }
  if(!response.ok)throw new Error(`Monitor request failed (${response.status})`);
  return response.json();
}

function appendDetail(container,label,value,kind='text'){
  const labelElement=document.createElement('div');
  labelElement.className='dl';
  labelElement.textContent=label;

  const valueElement=document.createElement('div');
  if(kind==='severity'){
    const severity=['critical','error','warning','info'].includes(value)?value:'info';
    const badge=document.createElement('span');
    badge.className=`sb sb-${severity}`;
    badge.textContent=severity;
    valueElement.appendChild(badge);
  }else if(kind==='code'){
    const code=document.createElement('code');
    code.textContent=String(value??'');
    valueElement.appendChild(code);
  }else if(kind==='pre'){
    const pre=document.createElement('pre');
    pre.className='detail-json';
    pre.textContent=String(value??'');
    valueElement.appendChild(pre);
  }else{
    valueElement.textContent=String(value??'');
  }

  container.append(labelElement,valueElement);
}

async function showDetail(id){
  const safeId=validErrorId(id);
  if(!safeId)return;
  try{
    const detail=await requestJson(`?action=get_detail&id=${encodeURIComponent(safeId)}`);
    if(!detail)return;
    currentErrorId=safeId;

    document.getElementById('di').textContent=`#${safeId}`;
    const container=document.getElementById('dc');
    container.replaceChildren();
    appendDetail(container,'Project',detail.project_name||'');
    appendDetail(container,'Type',detail.error_type||'');
    appendDetail(container,'Severity',detail.severity||'info','severity');
    appendDetail(container,'Message',detail.message||'');
    appendDetail(container,'File',`${detail.file_path||''}:${detail.line_number||0}`,'code');
    appendDetail(container,'URL',detail.url||'N/A');
    appendDetail(container,'Method',detail.http_method||'N/A');
    appendDetail(container,'IP',detail.ip_address||'N/A');
    appendDetail(container,'User Agent',detail.user_agent||'N/A');
    appendDetail(container,'Memory',formatBytes(detail.memory_usage));
    appendDetail(container,'Peak',formatBytes(detail.peak_memory));
    appendDetail(container,'Time',`${detail.execution_time||0}s`);
    appendDetail(container,'PHP',detail.php_version||'N/A');
    appendDetail(container,'Auto-Fix',detail.auto_fix_applied||'None');
    appendDetail(container,'Status',detail.is_resolved==1?'✅ Resolved':'❌ Open');
    appendDetail(container,'When',detail.created_at||'N/A');
    if(hasJsonDetail(detail.request_data))appendDetail(container,'Request',formatJson(detail.request_data),'pre');
    if(hasJsonDetail(detail.extra_data))appendDetail(container,'Extra',formatJson(detail.extra_data),'pre');
    if(detail.resolved_note)appendDetail(container,'Note',detail.resolved_note);

    document.getElementById('ds').textContent=detail.stack_trace||'No stack trace';
    document.getElementById('dm').classList.add('active');
  }catch(error){
    console.error(error);
    alert('Could not load error details. Please refresh and try again.');
  }
}

function closeModal(){
  document.getElementById('dm').classList.remove('active');
  currentErrorId=null;
}

async function postAction(action,data={}){
  const body=new URLSearchParams({...data,csrf_token:csrfToken});
  return requestJson(`?action=${encodeURIComponent(action)}`,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
    body:body.toString()
  });
}

async function resolveError(id){
  const safeId=validErrorId(id);
  if(!safeId)return;
  const note=prompt('Note (optional):')||'';
  try{
    const result=await postAction('resolve',{id:String(safeId),note});
    if(result.success)location.reload();
  }catch(error){
    console.error(error);
    alert('Could not resolve this error. Please try again.');
  }
}

async function deleteError(id){
  const safeId=validErrorId(id);
  if(!safeId||!confirm('Delete?'))return;
  try{
    const result=await postAction('delete',{id:String(safeId)});
    if(result.success)location.reload();
  }catch(error){
    console.error(error);
    alert('Could not delete this error. Please try again.');
  }
}

async function clearResolved(){
  if(!confirm('Delete ALL resolved?'))return;
  try{
    const result=await postAction('clear_resolved');
    if(result.success)location.reload();
  }catch(error){
    console.error(error);
    alert('Could not clear resolved errors. Please try again.');
  }
}

async function testError(){
  try{
    const result=await postAction('test_error');
    alert(result.message||'Done!');
    setTimeout(()=>location.reload(),500);
  }catch(error){
    console.error(error);
    alert('Could not run the monitor test. Please try again.');
  }
}

function formatBytes(value){
  const bytes=Number(value);
  if(!Number.isFinite(bytes)||bytes<=0)return'0 B';
  const units=['B','KB','MB','GB','TB'];
  const index=Math.min(Math.floor(Math.log(bytes)/Math.log(1024)),units.length-1);
  return`${Number((bytes/Math.pow(1024,index)).toFixed(1))} ${units[index]}`;
}

function hasJsonDetail(value){
  return value&&value!=='[]'&&value!=='{}'&&value!=='null';
}

function formatJson(value){
  try{return JSON.stringify(JSON.parse(value),null,2);}catch(error){return String(value??'');}
}

document.addEventListener('click',event=>{
  const actionControl=event.target.closest('[data-monitor-action]');
  if(actionControl){
    event.preventDefault();
    event.stopPropagation();
    const action=actionControl.dataset.monitorAction;
    const id=actionControl.dataset.errorId;
    if(action==='refresh')location.reload();
    else if(action==='test')testError();
    else if(action==='clear-resolved')clearResolved();
    else if(action==='close-detail')closeModal();
    else if(action==='resolve')resolveError(id||currentErrorId);
    else if(action==='delete')deleteError(id||currentErrorId);
    return;
  }

  const row=event.target.closest('[data-error-row]');
  if(row)showDetail(row.dataset.errorId);
});

document.addEventListener('keydown',event=>{if(event.key==='Escape')closeModal();});
document.getElementById('dm').addEventListener('click',event=>{if(event.target===event.currentTarget)closeModal();});
