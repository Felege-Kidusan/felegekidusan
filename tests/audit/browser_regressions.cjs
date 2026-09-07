/* Local synthetic-data browser sweep. Never visits a production origin.
 * NODE_PATH=/path/to/node_modules SSMS_AUDIT_TESTING=1 node tests/audit/browser_regressions.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const base = process.env.SSMS_AUDIT_BASE || 'http://127.0.0.1:8081';
if (process.env.SSMS_AUDIT_TESTING !== '1' || !['127.0.0.1','localhost'].includes(new URL(base).hostname)) {
  throw new Error('Opt-in localhost testing only.');
}
const fixture = payload => JSON.parse(execFileSync('php', [path.join(__dirname, 'db_fixture.php'), JSON.stringify(payload)], {cwd:root, encoding:'utf8', env:process.env}));
const roles = fixture({op:'seed_roles'}).roles.filter(role => !process.env.SSMS_AUDIT_ROLES || process.env.SSMS_AUDIT_ROLES.split(',').includes(role));
fixture({op:'sql',sql:'DELETE FROM security_rate_limits'});
const cdns = new Set(['cdnjs.cloudflare.com','cdn.tailwindcss.com','cdn.jsdelivr.net','fonts.googleapis.com','fonts.gstatic.com']);
const evidence = {runtime:'Playwright Chromium; synthetic data; allowed static CDNs only', roles:[], loginTests:[]};

(async () => {
  const browser = await chromium.launch({headless:true});
  for (const role of roles) {
    const context = await browser.newContext({viewport:{width:1440,height:1000},ignoreHTTPSErrors:true});
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin === new URL(base).origin || cdns.has(url.hostname) || ['data:','blob:'].includes(url.protocol)) return route.continue();
      return route.abort('blockedbyclient');
    });
    const item = {role, pageErrors:[], httpErrors:[], nav:[], missingHandlers:[], duplicateIds:[], dialogs:[]};
    const page = await context.newPage();
    page.on('pageerror', err => item.pageErrors.push(err.stack || String(err)));
    page.on('response', response => {
      if (response.url().startsWith(base) && response.status() >= 400) item.httpErrors.push({url:response.url().slice(base.length),status:response.status()});
    });
    page.on('dialog', async dialog => {item.dialogs.push(dialog.message());await dialog.dismiss();});
    try {
      const login = await context.request.get(base + '/admin/index.php');
      const token = (await login.text()).match(/name="csrf_token"\s+value="([a-f0-9]+)"/)[1];
      const response = await context.request.post(base + '/admin/backend/login.php', {
        form:{username:'audit_'+role,password:'AuditTest#2026',csrf_token:token},headers:{Accept:'application/json'},maxRedirects:0
      });
      const result = await response.json();
      if (result.status !== 'success') throw new Error('Login rejected: ' + JSON.stringify(result));
      await page.goto(base + result.redirect, {waitUntil:'domcontentloaded',timeout:20000});
      await page.waitForLoadState('networkidle',{timeout:6000}).catch(()=>{});
      item.url=page.url().slice(base.length);
      item.title=await page.title();
      item.controlCount=await page.locator('button,a,input,select,textarea').count();
      item.duplicateIds=await page.evaluate(()=>{
        const seen={};for(const el of document.querySelectorAll('[id]')) seen[el.getAttribute('id')]=(seen[el.getAttribute('id')]||0)+1;
        return Object.entries(seen).filter(([id,n])=>n>1).map(([id,n])=>({id,n,markup:[...document.querySelectorAll('[id]')].filter(el=>el.getAttribute('id')===id).map(el=>el.outerHTML.slice(0,350))}));
      });
      item.missingHandlers=await page.evaluate(()=>{
        const missing=[];
        for(const el of document.querySelectorAll('[onclick],[onsubmit],[onchange]')) {
          for(const attr of ['onclick','onsubmit','onchange']) {
            const code=el.getAttribute(attr)||'';
            const match=code.match(/^\s*(?:return\s+)?([A-Za-z_$][\w$.]*)\s*\(/);
            if(!match || ['if','this','event.preventDefault'].includes(match[1])) continue;
            try {if(eval('typeof '+match[1])!=='function') missing.push({id:el.id,tag:el.tagName,handler:code.slice(0,160)});}
            catch(e){missing.push({id:el.id,tag:el.tagName,handler:code.slice(0,160)});}
          }
        }
        return missing;
      });
      const selectors = 'aside [data-section],.sidebar [data-section],aside [data-sec],.tabs [data-panel],aside [onclick],.sidebar [onclick]';
      const nav = await page.locator(selectors).evaluateAll(els=>els.map((el,i)=>({
        i,section:el.getAttribute('data-section')||el.getAttribute('data-sec')||el.getAttribute('data-panel')||'',text:(el.innerText||'').trim().slice(0,60),onclick:el.getAttribute('onclick')||''
      })).filter(el=>el.section||/^(?:nav|showSection|switchSection)\(/.test(el.onclick)));
      const seen = new Set();
      for (const tab of nav) {
        const key=tab.section||tab.onclick;
        if (seen.has(key)) continue; seen.add(key);
        const node=page.locator(selectors).nth(tab.i);
        if(!await node.isVisible()) continue;
        const probe={section:key,label:tab.text};
        try {
          await node.click({timeout:3000});
          await page.waitForTimeout(120);
          await page.waitForLoadState('networkidle',{timeout:2500}).catch(()=>{});
          probe.ok=true;
          if(tab.section) {
            for (const prefix of ['section-','sec-','panel-','s-']) {
              const target=page.locator('#'+prefix+tab.section);
              if(await target.count()) {probe.visible=await target.first().isVisible();break;}
            }
          }
        } catch(error) {probe.ok=false;probe.error=String(error).slice(0,180);}
        item.nav.push(probe);
      }
      await page.setViewportSize({width:390,height:844});
      await page.waitForTimeout(120);
      item.mobile=await page.evaluate(()=>({width:window.innerWidth,scrollWidth:document.documentElement.scrollWidth}));
      item.mobileNav=[];
      const mobile=page.locator('.wbws-bnav [data-section],.wbws-bnav [data-sec],.school-bottom-nav [data-section],.tabs [data-panel]');
      const mobileCount=await mobile.count();
      for(let i=0;i<mobileCount;i++){
        const node=mobile.nth(i);
        if(!await node.isVisible())continue;
        const name=await node.getAttribute('data-section')||await node.getAttribute('data-sec')||await node.getAttribute('data-panel');
        try {await node.click({timeout:3000});await page.waitForTimeout(70);item.mobileNav.push({section:name,ok:true});}
        catch(error){item.mobileNav.push({section:name,ok:false,error:String(error).slice(0,140)});}
      }
      // Desktop logout links can be hidden on small screens; use the visible
      // mobile counterpart where available, or restore the desktop viewport.
      await page.setViewportSize({width:1440,height:1000});
      const logout=page.locator('a[href$="logout.php"]').first();
      if(await logout.count()) {
        await logout.click({timeout:5000});
        await page.waitForLoadState('domcontentloaded');
        item.logout={url:page.url().slice(base.length),ok:new URL(page.url()).pathname==='/admin/index.php'};
      }
    } catch(error) {item.error=String(error);}
    evidence.roles.push(item);
    console.log(role, JSON.stringify({pageErrors:item.pageErrors.length,httpErrors:item.httpErrors.length,nav:item.nav.length,missingHandlers:item.missingHandlers.length,duplicateIds:item.duplicateIds.length,error:item.error}));
    await context.close();
  }
  // The user's alternate login is tested by an actual form submission, not
  // merely by calling the JSON endpoint used above.
  fixture({op:'sql',sql:'DELETE FROM security_rate_limits'});
  const context=await browser.newContext({viewport:{width:1200,height:900}});
  await context.route('**/*', route => new URL(route.request().url()).origin===new URL(base).origin || cdns.has(new URL(route.request().url()).hostname) ? route.continue() : route.abort());
  const page=await context.newPage();
  await page.goto(base+'/frontend/pages/login.php',{waitUntil:'domcontentloaded'});
  await page.locator('#username').fill('audit_mezmur_dept');
  await page.locator('#password').fill('AuditTest#2026');
  await page.locator('#loginBtn').click();
  await page.waitForURL('**/frontend/pages/mezmur_dept.php',{timeout:10000});
  evidence.loginTests.push({name:'Alternate login button → Mezmur dashboard',ok:true,url:page.url().slice(base.length)});
  await page.locator('.school-logout-btn').click();
  await page.waitForURL('**/admin/index.php?success=*',{timeout:10000});
  evidence.loginTests.push({name:'Mezmur logout button → real login page',ok:true,url:page.url().slice(base.length)});
  await context.close();
  await browser.close();
  fs.writeFileSync(path.join(root,'docs/audits/production-2026-09-07/browser-results.json'),JSON.stringify(evidence,null,2)+'\n');
  const failures=evidence.roles.filter(role=>role.error||role.pageErrors.length||role.missingHandlers.length||role.nav.some(n=>!n.ok||n.visible===false)||role.logout?.ok===false);
  console.log('Browser roles with actionable errors:',failures.map(r=>r.role));
  process.exitCode=failures.length?1:0;
})().catch(error=>{console.error(error);fs.writeFileSync(path.join(root,'docs/audits/production-2026-09-07/browser-results.json'),JSON.stringify({...evidence,fatal:String(error)},null,2));process.exitCode=1;});
