'use strict';
// Read-only local browser acceptance: search requests use GET, no submissions to business forms.
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=path.resolve(__dirname,'../../../..');
const {chromium}=require(path.join(root,'.tmp/accessibility-tools/node_modules/playwright-core'));
const base='http://localhost/wordpress';let checks=0;const check=(v,m)=>{assert.ok(v,m);checks++;console.log('PASS '+m);};
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});try{
for(const language of ['ar','en'])for(const width of [390,1440])for(const mode of ['light','dark']){
 const c=await browser.newContext({viewport:{width,height:900},colorScheme:mode,reducedMotion:'reduce'});await c.route('**/*',r=>['localhost','127.0.0.1'].includes(new URL(r.request().url()).hostname)?r.continue():r.abort());
 const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/?lang='+language,{waitUntil:'networkidle'});
 const panel=p.locator('#header-search-panel'),box=p.locator('[data-header-search]'),trigger=p.locator('#header-search-trigger'),input=p.locator('#site-search');
 check(!await panel.isVisible(),'search initially collapsed '+language+'/'+width+'/'+mode);const before=await p.locator('#primary').boundingBox();
 await trigger.click();await input.waitFor({state:'visible'});await p.waitForFunction(()=>document.activeElement.id==='site-search');
 check(await input.evaluate(el=>el===document.activeElement),'opening focuses field');check((await trigger.boundingBox()).width>=44,'search trigger meets touch target');
 const after=await p.locator('#primary').boundingBox();check(Math.abs(before.y-after.y)<1,'opening does not shift content');
 check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'panel fits viewport');
 await input.fill('  ');await input.press('Enter');await p.waitForFunction(()=>document.querySelector('#site-search').getAttribute('aria-invalid')==='true'&&document.querySelector('#header-search-status').textContent);check(true,'blank search gives localized guidance');
 await input.fill('sample');await p.locator('[data-search-clear]').click();check(await input.inputValue()===''&&await input.evaluate(el=>el===document.activeElement),'clear keeps field focused');
 await input.fill('retained');await input.press('Escape');check(!await panel.isVisible()&&await trigger.evaluate(el=>el===document.activeElement),'Escape closes and restores trigger focus');
 await trigger.click();check(await input.inputValue()==='retained','reopening retains draft in page');
 check(await p.locator('[data-search-close],#header-search-heading,#header-search-help').count()===0,'compact panel omits heading, help copy and close button');
 await trigger.click();check(!await panel.isVisible(),'search trigger also closes panel');
 await p.keyboard.press('Control+k');await p.waitForFunction(()=>document.querySelector('[data-header-search]').open);check(await input.evaluate(el=>el===document.activeElement),'keyboard shortcut opens search');
 await input.press('Control+k');check(await box.evaluate(el=>el.open),'shortcut does not intercept input editing');
 await p.mouse.click(5,500);check(!await panel.isVisible(),'click outside closes without navigation');
 if(width===390){await p.locator('.menu-toggle').click();await trigger.click();await p.waitForFunction(()=>document.querySelector('.menu-toggle').getAttribute('aria-expanded')==='false');check(!await p.locator('.main-navigation').evaluate(el=>el.classList.contains('is-open')),'search closes mobile navigation');}
 if(!await panel.isVisible()) await trigger.click();
 await input.fill('search-no-match-927541');await Promise.all([p.waitForURL(u=>u.pathname.includes('/cars/')&&u.searchParams.get('search')==='search-no-match-927541'),input.press('Enter')]);
 check(language!=='en'||new URL(p.url()).searchParams.get('lang')==='en','submission preserves language');
 check(await p.locator('.catalog-empty').count()===1&&(await p.locator('.catalog-empty').innerText()).includes('search-no-match-927541'),'empty results explain query');
 await p.locator('[data-open-vehicle-search]').click();await p.waitForFunction(()=>document.activeElement.id==='site-search');check(await input.inputValue()==='search-no-match-927541','edit search opens and preserves submitted query');
 await p.addScriptTag({path:path.join(root,'.tmp/accessibility-tools/node_modules/axe-core/axe.min.js')});const violations=await p.evaluate(async()=>{const r=await axe.run('#header-search-panel',{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','best-practice']}});return r.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)}));});check(!violations.length,'panel accessibility '+JSON.stringify(violations));
 check(!errors.length,'no browser exceptions');await c.close();
}
const c=await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:900}});const p=await c.newPage();await p.goto(base+'/?lang=en');await p.locator('#header-search-trigger').click();check(await p.locator('#site-search').isVisible(),'native disclosure works without JavaScript');await p.locator('#site-search').fill('no-match-native-927541');await Promise.all([p.waitForURL(u=>u.searchParams.get('search')==='no-match-native-927541'),p.locator('.header-search button[type=submit]').click()]);check(await p.locator('.catalog-empty').count()===1,'search works without JavaScript');await c.close();
const extra=await browser.newContext();const q=await extra.newPage();await q.goto(base+'/cars/?brand=test-brand&max_price=50000&sort=price_asc&lang=en',{waitUntil:'networkidle'});await q.locator('#header-search-trigger').click();await q.locator('#site-search').fill('test-query');await Promise.all([q.waitForURL(u=>u.searchParams.get('search')==='test-query'),q.locator('.header-search button[type=submit]').click()]);const u=new URL(q.url());check(u.searchParams.get('brand')==='test-brand'&&u.searchParams.get('max_price')==='50000'&&u.searchParams.get('sort')==='price_asc','catalog filters retained when searching');await extra.close();
fs.mkdirSync(path.join(root,'.tmp/header-search-acceptance'),{recursive:true});fs.writeFileSync(path.join(root,'.tmp/header-search-acceptance/results.json'),JSON.stringify({checks,passed:true},null,2));console.log('HEADER SEARCH PASS: '+checks+' checks');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
