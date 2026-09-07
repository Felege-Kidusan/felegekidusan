/* Focused browser regressions for shared calendar, escaping, finance saving
 * and impersonation. Run only with the synthetic local database. */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const base=process.env.SSMS_AUDIT_BASE||'http://127.0.0.1:8081';
if(process.env.SSMS_AUDIT_TESTING!=='1'||!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw new Error('Local opt-in only');
const fixture=x=>JSON.parse(execFileSync('php',[path.join(__dirname,'db_fixture.php'),JSON.stringify(x)],{cwd:root,encoding:'utf8',env:process.env}));
const results=[];
async function check(name,fn){await fn();results.push({name,passed:true});console.log('PASS',name);}
const calendar=fs.readFileSync(path.join(root,'admin/js/wbws-calendar.js'),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true});
 await check('Calendar boots from head, is safe to include twice, and tracks property-set values',async()=>{
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<html><head><script>window.WBWS_CALENDAR_MODE="ethiopian";</script><script>'+calendar+'</script><script>'+calendar+'</script></head><body><form><input id="day" type="date" value="2026-08-22"></form></body></html>');
  await page.waitForSelector('.ec-wrap');
  assert.deepEqual(errors,[]);assert.equal(await page.locator('.ec-wrap').count(),1);
  await page.evaluate(()=>document.getElementById('day').value='2026-09-11');
  assert.equal(await page.locator('.ec-wrap input[type=text]').inputValue(),'መስከረም 1, 2019');
  await page.evaluate(()=>document.getElementById('day').value='');
  assert.equal(await page.locator('.ec-wrap input[type=text]').inputValue(),'');
  await page.evaluate(()=>document.getElementById('day').disabled=true);
  await page.waitForFunction(()=>document.querySelector('.ec-wrap input[type=text]').disabled);
  await page.close();
 });
 await check('Gregorian mode never converts dynamically inserted native date inputs',async()=>{
  const page=await browser.newPage();
  await page.setContent('<html><head><script>window.WBWS_CALENDAR_MODE="gregorian";</script><script>'+calendar+'</script></head><body><input type="date" id="native"></body></html>');
  await page.evaluate(()=>{const i=document.createElement('input');i.type='date';i.id='later';document.body.appendChild(i);WBWSCalendar.refreshPickers();});
  await page.waitForTimeout(180);
  assert.equal(await page.locator('.ec-wrap').count(),0);
  assert.equal(await page.locator('#later').getAttribute('type'),'date');await page.close();
 });
 fixture({op:'seed_roles'});fixture({op:'sql',sql:'DELETE FROM security_rate_limits'});
 async function login(role){
  const context=await browser.newContext({viewport:{width:1365,height:900},ignoreHTTPSErrors:true});
  const allowed=new Set(['cdnjs.cloudflare.com','cdn.tailwindcss.com','cdn.jsdelivr.net','fonts.googleapis.com','fonts.gstatic.com']);
  await context.route('**/*',route=>new URL(route.request().url()).origin===new URL(base).origin||allowed.has(new URL(route.request().url()).hostname)?route.continue():route.abort());
  const page=await context.newPage();
  const html=await(await context.request.get(base+'/admin/index.php')).text();
  const csrf=html.match(/name="csrf_token"\s+value="([a-f0-9]+)"/)[1];
  const r=await context.request.post(base+'/admin/backend/login.php',{form:{username:'audit_'+role,password:'AuditTest#2026',csrf_token:csrf},headers:{Accept:'application/json'},maxRedirects:0});
  const d=await r.json();assert.equal(d.status,'success');
  await page.goto(base+d.redirect,{waitUntil:'domcontentloaded'});await page.waitForLoadState('networkidle',{timeout:5000}).catch(()=>{});
  return {page,context};
 }
 await check('Finance double-click sends one write; Gregorian date remains exact',async()=>{
  const {page,context}=await login('finance_dept');
  const marker='Audit browser single-flight '+Date.now();let writes=0;
  await page.route('**/backend/api/finance.php',async route=>{
   if(route.request().method()==='POST'&&(route.request().postData()||'').includes('add_transaction')){writes++;await new Promise(r=>setTimeout(r,150));}
   await route.continue();
  });
  await page.evaluate(marker=>{
   Finance.openAddTxn('income');
   document.getElementById('txnAmt').value='12.34';document.getElementById('txnDesc').value=marker;
   document.getElementById('txnDate').value='2026-08-23';
  },marker);
  const response=page.waitForResponse(r=>r.url().endsWith('/backend/api/finance.php')&&r.request().method()==='POST');
  await page.evaluate(()=>{Finance.saveTxn();Finance.saveTxn();});
  const d=await(await response).json();assert.equal(d.status,'success');assert.equal(writes,1);
  const rows=fixture({op:'sql',sql:'SELECT id,transaction_date,amount FROM finance_transactions WHERE description=?',params:[marker]}).rows;
  assert.equal(rows.length,1);assert.equal(rows[0].transaction_date,'2026-08-23');assert.equal(rows[0].amount,'12.34');
  fixture({op:'sql',sql:'DELETE FROM finance_transactions WHERE id=?',params:[rows[0].id]});
  await context.close();
 });
 await check('Shared esc() is safe for quoted HTML attributes as well as text',async()=>{
  const {page,context}=await login('finance_dept');
  const result=await page.evaluate(()=>{
   const p='" autofocus onfocus="window.__auditXss=1';const host=document.createElement('div');
   host.innerHTML='<input value="'+esc(p)+'">';document.body.appendChild(host);
   return {value:host.firstElementChild.value,handler:host.firstElementChild.getAttribute('onfocus'),expected:p};
  });
  assert.equal(result.value,result.expected);assert.equal(result.handler,null);await context.close();
 });
 await check('Admin restore buttons work on legacy and migrated department pages',async()=>{
  const {page,context}=await login('super_admin');
  for(const role of ['hr_dept','mezmur_dept']){
   const html=await page.content();const csrf=html.match(/(?:csrf|csrf_token|CSRF_TOKEN)["']?\s*[:=]\s*["']([a-f0-9]{64})/)[1];
   const result=await(await context.request.post(base+'/admin/api_impersonate.php',{form:{action:'switch',role,csrf_token:csrf},headers:{Accept:'application/json'}})).json();
   assert.equal(result.status,'success');await page.goto(base+'/admin/dashboard.php',{waitUntil:'domcontentloaded'});
   await page.locator('#impersonateBar').click();await page.waitForURL('**/admin/dashboard.php',{timeout:10000});
   await page.waitForFunction(()=>!document.getElementById('impersonateBar'));
   assert.match(await page.title(),/Super Admin/);
  }
  await context.close();
 });
 await browser.close();
 fs.writeFileSync(path.join(root,'docs/audits/production-2026-09-07/browser-contracts.json'),JSON.stringify(results,null,2)+'\n');
})().catch(error=>{console.error(error);process.exit(1);});
