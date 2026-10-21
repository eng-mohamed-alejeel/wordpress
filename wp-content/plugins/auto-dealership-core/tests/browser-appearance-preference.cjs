'use strict';
// Read-only browser acceptance. No accounts, forms or site options are modified.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../../../..');
const {chromium}=require(path.join(root,'.tmp/accessibility-tools/node_modules/playwright-core'));
const base=process.env.ADC_SITE_ORIGIN||'http://localhost/wordpress';
if(!/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?(?:\/wordpress)?$/.test(base))throw new Error('Loopback only');
const key='autobrands.appearance';let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.ADC_BROWSER||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 const report=[];
 try{
  for(const lang of ['ar','en'])for(const device of ['light','dark']){
   const context=await browser.newContext({colorScheme:device,reducedMotion:'reduce',viewport:{width:390,height:844}});
   await context.route('**/*',route=>{const u=new URL(route.request().url());return ['localhost','127.0.0.1'].includes(u.hostname)?route.continue():route.abort();});
   const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/?lang='+lang,{waitUntil:'networkidle'});
   const state=()=>page.evaluate(()=>({mode:document.documentElement.dataset.abTheme,preference:document.documentElement.dataset.abPreference,scheme:document.documentElement.style.colorScheme,saved:localStorage.getItem('autobrands.appearance')}));
   let s=await state();check(s.mode===device&&s.scheme===device&&s.preference==='system'&&s.saved===null,lang+'/'+device+' first visit follows device without storing a manual choice');
   check(await page.locator('.site-header [data-ab-toggle]').count()===1&&await page.locator('[data-ab-appearance-option=system]').count()===0,'one header toggle; no device icon');
   await page.emulateMedia({colorScheme:device==='dark'?'light':'dark'});
   await page.waitForFunction(mode=>document.documentElement.dataset.abTheme===mode,device==='dark'?'light':'dark');
   check((await state()).preference==='system','device changes remain automatic before manual choice');
   const before=(await state()).mode,next=before==='light'?'dark':'light',toggle=page.locator('.site-header [data-ab-toggle]');
   await toggle.focus();await page.keyboard.press('Enter');
   s=await state();check(s.mode===next&&s.preference===next&&s.saved===next,'keyboard toggle selects and saves the opposite effective mode');
   check((await toggle.getAttribute('aria-label')).includes(lang==='ar'?'تفعيل':'Switch to'),'localized action label');
   const box=await toggle.boundingBox();check(box.width>=44&&box.height>=44,'44px pointer target');
   check(await toggle.locator('svg:visible').count()===1,'only one fixed-size icon is visible');
   await page.emulateMedia({colorScheme:next==='dark'?'light':'dark'});check((await state()).mode===next,'manual choice takes precedence over device');
   await page.reload({waitUntil:'networkidle'});check((await state()).mode===next,'manual choice persists after reload');
   const second=await context.newPage();await second.goto(base+'/cars/?lang='+lang,{waitUntil:'networkidle'});
   const other=next==='dark'?'light':'dark';await second.evaluate(value=>window.autoBrandsAppearance.select(value),other);
   await page.waitForFunction(mode=>document.documentElement.dataset.abTheme===mode,other);
   check((await state()).mode===other,'open tabs synchronize changes');
   await second.evaluate(()=>window.autoBrandsAppearance.select('system'));
   await page.waitForFunction(()=>document.documentElement.dataset.abPreference==='system');
   check((await state()).saved===null,'returning to device settings removes manual choice across tabs');
   for(const width of [390,1440]){await page.setViewportSize({width,height:900});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'header reflow '+lang+'/'+width);}
   await page.evaluate(()=>localStorage.setItem('autobrands.appearance','system'));await page.reload({waitUntil:'networkidle'});check((await state()).preference==='system','legacy system preference remains automatic');
   await page.locator('#cd-cookie-accept-essential').waitFor({state:'visible',timeout:5000}).catch(()=>{});
   if(await page.locator('#cd-cookie-accept-essential').isVisible()) await page.locator('#cd-cookie-accept-essential').click();
   await toggle.click();await page.locator('.ab-footer-appearance summary').click();
   await page.locator('.ab-footer-appearance [data-ab-reset]').click();
   check((await state()).preference==='system'&&(await state()).saved===null,'guest can restore device setting from footer preferences');
   check(await page.locator('.ab-footer-appearance [data-ab-reset]').isDisabled(),'reset is disabled when already automatic');
   check(errors.length===0,'no browser exceptions');
   report.push({lang,device,passed:true});await context.close();
  }
  const context=await browser.newContext({colorScheme:'dark'});
  await context.addInitScript(()=>{Object.defineProperty(Storage.prototype,'getItem',{value(){throw new Error('blocked');}});Object.defineProperty(Storage.prototype,'setItem',{value(){throw new Error('blocked');}});Object.defineProperty(Storage.prototype,'removeItem',{value(){throw new Error('blocked');}});});
  const page=await context.newPage();await page.goto(base+'/?lang=en',{waitUntil:'networkidle'});
  check(await page.evaluate(()=>document.documentElement.dataset.abTheme==='dark'),'blocked storage still follows device');
  await page.locator('.site-header [data-ab-toggle]').click();check(await page.evaluate(()=>document.documentElement.dataset.abTheme==='light'),'blocked storage still allows switching');await context.close();
  const early=await browser.newContext({colorScheme:'light'});await early.addInitScript(()=>localStorage.setItem('autobrands.appearance','dark'));
  let unblock,requested;const pending=new Promise(r=>{requested=r;});const gate=new Promise(r=>{unblock=r;});
  await early.route('**/brand-appearance.js*',async route=>{requested();await gate;await route.continue();});
  const first=await early.newPage();const navigation=first.goto(base+'/?lang=en',{waitUntil:'networkidle'});
  try{await pending;check(await first.evaluate(()=>document.documentElement.dataset.abTheme==='dark'&&document.documentElement.style.colorScheme==='dark'),'saved appearance applied before delayed main script or page content');}finally{unblock();await navigation;}
  await first.goto(base+'/wp-login.php',{waitUntil:'networkidle'});check(await first.locator('.site-header [data-ab-toggle]').count()===1 && await first.locator('.site-footer [data-ab-toggle]').count()===1,'WordPress sign-in uses the same compact toggle');await early.close();
  fs.mkdirSync(path.join(root,'.tmp/appearance-acceptance'),{recursive:true});fs.writeFileSync(path.join(root,'.tmp/appearance-acceptance/results.json'),JSON.stringify({checks,report},null,2));
  console.log('APPEARANCE PASS: '+checks+' checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
