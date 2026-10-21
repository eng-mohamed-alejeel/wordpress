'use strict';
// Receives only disposable ZIP-installation credentials from the guarded runner.
module.exports=async({browser,config,check,errors})=>{
 const path=require('node:path'),fs=require('node:fs'),out=path.join(config.root,'.tmp/account-work-acceptance');fs.mkdirSync(out,{recursive:true});
 for(const role of ['customer','staff','admin','inventory','finance','purchasing','delivery','marketing','auditor','manager','multi','module','unassigned']){
  const ctx=await browser.newContext({reducedMotion:'reduce'}),page=await ctx.newPage();page.on('pageerror',error=>errors.push(error.message));
  await ctx.request.get(config.base+'/adc-product-personal-fixture?key='+config.key+'&as='+role);
  const response=await page.goto(config.base+'/?cd_account=dashboard&lang=en');
  check(response.status()===200,role+': dashboard entry renders');
  if(role==='customer'){
   check(await page.locator('.adc-work-dashboard').count()===0&&await page.locator('.adc-p-nav').count()===1,'customer: default remains personal');
   for(const suffix of ['account_context=work','account_context=unknown','account_context[]=work']){const denied=await ctx.request.get(config.base+'/?cd_account=dashboard&'+suffix);check(denied.status()===403,'customer: unauthorized/malformed work context rejected '+suffix);}
   await ctx.close();continue;
  }
  check(await page.locator('.adc-work-dashboard').count()===1,role+': default uses authorized work view');
  check(/no-cache|no-store/.test(response.headers()['cache-control']||''),role+': account HTML is not publicly cached');
  check(await page.locator('.adc-p-nav').count()===0,role+': customer sections are not mislabeled as work');
  const links=await page.locator('.adc-work-modules a').evaluateAll(nodes=>nodes.map(n=>n.href));check(new Set(links).size===links.length,role+': module links are not duplicated');
  if(role==='staff')check(!links.some(url=>/adc-profit-margin|adc-payments|page=adc-settings/.test(url)),'sales: unrelated finance and settings modules omitted');
  if(role==='module')check(links.length===1&&links[0].includes('adc-conversations')&&await page.locator('#adc-work-overview').count()===0,'module-only: no global workspace permission is inferred');
  if(role==='unassigned')check((await page.locator('.adc-work-dashboard').innerText()).includes('No active branch'),'unassigned: clear branch restriction');
  await page.locator('.adc-work-context a').last().click();await page.locator('.adc-p-nav').waitFor({state:'visible'});check(await page.locator('.adc-p-nav').count()===1&&await page.locator('.adc-work-dashboard').count()===0,role+': explicit personal switch remains available');
  check(await page.locator('.adc-p-nav a').count()===2&&await page.locator('input[name="display_name"]').count()===1,role+': settings contains profile and appearance only');
  for(const section of ['requests','bookings','favorites','purchases','documents','messages','reviews','overview','preferences']){
   await page.goto(config.base+'/?cd_account=dashboard&portal='+section+'&lang=en');
   check(await page.locator('.adc-p-nav a').count()===2&&await page.locator('.adc-p-nav a[href*="portal=requests"],input[name="consent_marketing"],.adc-p-thread-card').count()===0,role+': customer section suppressed '+section);
   if(section==='preferences')check((await page.locator('.adc-p-main').innerText()).includes('Appearance preferences'),role+': appearance remains available');
  }
  await page.locator('.adc-p-account-context a').click();await page.locator('.adc-work-dashboard').waitFor({state:'visible'});check(await page.locator('.adc-work-dashboard').count()===1,role+': work switch returns to work');
  if(['staff','admin','inventory','module'].includes(role))for(const lang of ['ar','en']){
   await page.goto(config.base+'/?cd_account=dashboard&lang='+lang);
   for(const width of [320,390,768,1440]){
    await page.setViewportSize({width,height:1000});
    for(const mode of ['light','dark']){
     await page.evaluate(value=>window.autoBrandsAppearance.select(value),mode);
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${role} ${lang} ${width} ${mode}: no horizontal page overflow`);
     const activeContrast=await page.locator('.adc-work-context [aria-current]').evaluate(el=>{const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d');const rgb=color=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=color;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].slice(0,3);};const lum=values=>values.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((n,v,i)=>n+v*[.2126,.7152,.0722][i],0);const style=getComputedStyle(el),a=lum(rgb(style.color)),b=lum(rgb(style.backgroundColor));return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);});
     check(activeContrast>=4.5,`${role} ${lang} ${width} ${mode}: active work tab text remains readable (${activeContrast.toFixed(2)})`);
    }
   }
   await page.addScriptTag({path:config.root+'/.tmp/accessibility-tools/node_modules/axe-core/axe.min.js'});
   const violations=await page.evaluate(async()=>{const result=await axe.run(document.querySelector('.adc-work-dashboard'),{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}});return result.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)}));});
   check(violations.length===0,role+' '+lang+': work dashboard accessibility '+JSON.stringify(violations));
   if(role==='staff'||role==='admin')await page.screenshot({path:path.join(out,role+'-'+lang+'.png'),fullPage:true});
   if(role==='staff'||role==='admin'){
    await page.goto(config.base+'/?cd_account=dashboard&portal=profile&lang='+lang);
    for(const width of [390,1440]){
     await page.setViewportSize({width,height:1000});
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),role+' '+lang+' '+width+': settings fit the viewport');
    }
    check(await page.locator('.adc-portal').getAttribute('dir')===(lang==='ar'?'rtl':'ltr'),role+' '+lang+': settings direction matches language');
    await page.goto(config.base+'/?cd_account=dashboard&portal=preferences&lang='+lang);
    check(await page.locator('input[name="consent_marketing"],select[name="newsletter_language"]').count()===0,role+' '+lang+': no customer marketing controls');
   }
  }
  await ctx.close();
 }
 const plain=await browser.newContext({javaScriptEnabled:false}),page=await plain.newPage();await plain.request.get(config.base+'/adc-product-personal-fixture?key='+config.key+'&as=staff');await page.goto(config.base+'/?cd_account=dashboard&lang=en');check(await page.locator('.adc-work-dashboard').count()===1&&await page.locator('.adc-work-modules a').count()>0,'Work view and navigation remain available without JavaScript');
 await page.goto(config.base+'/?cd_account=dashboard&portal=requests&lang=en');check(await page.locator('input[name="display_name"]').count()===1&&await page.locator('.adc-p-nav a').count()===2,'Staff settings and legacy section handling remain available without JavaScript');await plain.close();
 check(errors.length===0,'Work dashboard has no uncaught runtime errors: '+errors.join(' | '));
};
