'use strict';
module.exports=async({browser,config,check,errors})=>{
  const base=config.base,account=(section='overview',args='')=>base+'/?cd_account=dashboard&lang=en&portal='+section+args;
  for(const role of ['customer','staff','admin','empty']){
    const ctx=await browser.newContext(),page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
    const fixture=await(await ctx.request.get(base+'/adc-product-personal-fixture?key='+config.key+'&as='+role)).json(),own=fixture[role],other=fixture[role==='customer'?'staff':'customer'];
    await page.goto(account());
    if(role!=='customer'){
      check(await page.locator('.adc-p-stat,.adc-p-next,.adc-p-thread-card').count()===0&&await page.locator('.adc-p-nav a').count()===2,role+': work accounts have settings without customer summaries');
      await page.goto(account('profile'));const profile=page.locator('form').filter({has:page.locator('[name=display_name]')});await profile.locator('[name=display_name]').fill('Updated personal '+role);await Promise.all([page.waitForNavigation(),profile.locator('button[type=submit]').click()]);await page.reload();check((await page.locator('h1').innerText()).includes('Updated personal '+role),role+': account settings save persists');
      if(role!=='empty'){
        const cfg=await page.evaluate(()=>window.adcPortal);
        const send=await ctx.request.post(base+'/wp-admin/admin-post.php',{headers:{Accept:'application/json'},form:{action:'adc_portal',operation:'send',section:'messages',portal_scope:'personal',admin:role==='admin'?'1':'0',id:String(other.thread),body:'forged cross-account reply',request_key:crypto.randomUUID(),_wpnonce:cfg.nonce}});
        check(send.status()===403,role+': hidden personal UI does not weaken message ownership');
        const poll=await ctx.request.get(base+'/wp-admin/admin-ajax.php?action=adc_portal_poll&nonce='+cfg.pollNonce+'&id='+other.thread+'&portal_scope=personal');check(poll.status()===404,role+': personal polling retains ownership checks');
        if(role==='admin'){const work=await ctx.request.get(base+'/wp-admin/admin-ajax.php?action=adc_portal_poll&nonce='+cfg.pollNonce+'&id='+other.thread+'&portal_scope=staff');check(work.status()===200,'Authorized work polling remains available');}
      }
      await ctx.close();continue;
    }
    check(await page.locator('.adc-p-stat').count()===6,role+': personal summary has six scoped counters');
    check(await page.locator('.adc-chat-launch').count()===0,role+': floating chat does not cover personal content');
    check(await page.locator('.adc-p-footer-links').count()===1&&await page.locator('.footer-newsletter').count()===0,role+': compact account footer keeps legal links without duplicate subscription');
    const stats=await page.locator('.adc-p-stat strong').allTextContents();check(stats.join(',')==='1,1,1,0,1,1',role+': summary reflects only signed-in account');
    check(!(await page.locator('.adc-p-main').innerText()).includes(other.marker),role+': overview contains no other-account records');
    if(role==='customer'){await page.screenshot({path:config.root+'/.tmp/personal-account-en-desktop.png',fullPage:true});}
    await page.setViewportSize({width:390,height:844});
    await page.waitForFunction(()=>document.querySelector('.adc-p-navigation')?.open===false);
    check(await page.locator('.adc-p-navigation').getAttribute('open')===null,role+': mobile navigation starts collapsed');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),role+': mobile viewport fits');
    if(role==='customer'){await page.screenshot({path:config.root+'/.tmp/personal-account-en-mobile.png',fullPage:true});}
    await page.locator('.adc-p-navigation summary').click();check(await page.locator('.adc-p-nav a').first().isVisible(),role+': keyboard/native menu can expose all account sections');
    await page.setViewportSize({width:1440,height:1000});
    await page.locator('.adc-p-nav a').last().waitFor({state:'visible'});
    check(await page.locator('.adc-p-nav a').last().isVisible(),role+': desktop navigation remains visible');
    for(const section of ['requests','bookings','purchases','documents','messages']){
      await page.goto(account(section));const text=await page.locator('.adc-p-main').innerText();check(!text.includes(other.marker)&&!text.includes('_internal'),role+': '+section+' stays personal');
      check(await page.locator('.adc-p-account-filters').count()===1,role+': '+section+' has real filters');
    }
    await page.goto(account('documents'));const download=page.locator('form').filter({has:page.locator('[name=operation][value=download]')});const data=await download.evaluate(f=>Object.fromEntries(new FormData(f)));
    const forbidden=await ctx.request.post(base+'/wp-admin/admin-post.php',{form:{...data,id:String(other.doc)}});check(forbidden.status()===404,role+': personal PDF action rejects another account even for admin');
    const valid=await ctx.request.post(base+'/wp-admin/admin-post.php',{form:data});check(valid.status()===200&&valid.headers()['content-type'].includes('pdf'),role+': own protected PDF still downloads');
    await page.goto(account('messages','&thread='+other.thread));check(await page.locator('.adc-p-message').count()===0&&await page.locator('.adc-p-notice.is-error').count()===1,role+': guessed conversation URL is denied');
    await page.goto(account('messages','&thread='+own.thread));check((await page.locator('.adc-p-messages').innerText()).includes(own.marker+'_public')&&!(await page.locator('.adc-p-messages').innerText()).includes('_internal'),role+': own conversation shows public messages only');
    const nonce=await page.locator('[name=_wpnonce]').first().inputValue();
    const send=await ctx.request.post(base+'/wp-admin/admin-post.php',{headers:{Accept:'application/json'},form:{action:'adc_portal',operation:'send',section:'messages',portal_scope:'personal',admin:role==='admin'?'1':'0',id:String(other.thread),body:'forged cross-account reply',request_key:crypto.randomUUID(),_wpnonce:nonce}});
    check(send.status()===403,role+': forged personal reply cannot inherit staff/admin authority');
    const cfg=await page.evaluate(()=>window.adcPortal);const poll=await ctx.request.get(base+'/wp-admin/admin-ajax.php?action=adc_portal_poll&nonce='+cfg.pollNonce+'&id='+other.thread+'&portal_scope=personal');check(poll.status()===404,role+': personal polling enforces ownership');
    await page.goto(account('requests'));await page.locator('.adc-p-account-filters [name=search]').fill('NO_MATCH_%_');await Promise.all([page.waitForNavigation(),page.locator('.adc-p-account-filters button').click()]);check(await page.locator('.adc-p-main article').count()===0,role+': wildcard search cannot widen results');
    await page.goto(account('profile'));const profile=page.locator('form').filter({has:page.locator('[name=display_name]')});await profile.locator('[name=display_name]').fill('Updated personal '+role);await Promise.all([page.waitForNavigation(),profile.locator('button[type=submit]').click()]);await page.reload();check((await page.locator('h1').innerText()).includes('Updated personal '+role)&&await page.locator('[name=display_name]').count()===1&&new URL(page.url()).searchParams.get('portal')==='profile',role+': native profile save and reload retain the correct account section');
    await page.goto(account('preferences'));const preferences=page.locator('form').filter({has:page.locator('[name=account_action][value=save_preferences]')});await preferences.locator('[name=newsletter_language]').selectOption('en');await preferences.locator('[name=consent_marketing][value="0"]').check();await Promise.all([page.waitForNavigation(),preferences.locator('button[type=submit]').click()]);await page.reload();check(new URL(page.url()).searchParams.get('portal')==='preferences'&&await page.locator('[name=newsletter_language]').inputValue()==='en'&&await page.locator('[name=consent_marketing][value="0"]').isChecked(),role+': account communication preferences persist through their protected native form');
    if(role==='customer'){
      await page.goto(base+'/?cd_account=dashboard&lang=ar');check(await page.locator('.adc-portal').getAttribute('dir')==='rtl','Personal dashboard supports real Arabic direction');
      await page.screenshot({path:config.root+'/.tmp/personal-account-ar-desktop.png',fullPage:true});await page.setViewportSize({width:390,height:844});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Arabic personal dashboard fits mobile');await page.screenshot({path:config.root+'/.tmp/personal-account-ar-mobile.png',fullPage:true});
      await page.evaluate(()=>document.documentElement.setAttribute('data-ab-theme','dark'));await page.waitForTimeout(500);await page.screenshot({path:config.root+'/.tmp/personal-account-dark.png',fullPage:true});
      const contrast=await page.locator('.adc-p-stat').first().evaluate(el=>{const rgb=s=>s.match(/[\d.]+/g).slice(0,3).map(Number),lum=c=>c.map(v=>{v/=255;return v<=.04045?v/12.92:Math.pow((v+.055)/1.055,2.4);}).reduce((n,v,i)=>n+v*[.2126,.7152,.0722][i],0),style=getComputedStyle(el),a=lum(rgb(style.color)),b=lum(rgb(style.backgroundColor));return {ratio:(Math.max(a,b)+.05)/(Math.min(a,b)+.05),color:style.color,background:style.backgroundColor};});check(contrast.ratio>=4.5,'Dark personal counter text contrast: '+JSON.stringify(contrast));
      await page.addScriptTag({path:config.root+'/.tmp/accessibility-tools/node_modules/axe-core/axe.min.js'});const violations=await page.evaluate(async()=>{const r=await axe.run(document.querySelector('.adc-portal'),{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}});return r.violations.filter(v=>['serious','critical'].includes(v.impact)).map(v=>v.id);});check(violations.length===0,'Personal overview WCAG serious/critical checks: '+violations.join(','));
    }
    await ctx.close();
  }
  const plain=await browser.newContext({javaScriptEnabled:false}),page=await plain.newPage();await plain.request.get(base+'/adc-product-personal-fixture?key='+config.key+'&as=customer');await page.setViewportSize({width:390,height:844});await page.goto(account());check(await page.locator('.adc-p-nav a').last().isVisible(),'personal navigation remains available without JavaScript');await plain.close();
  await require('./account-work-browser.cjs')({browser,config,check,errors});
};
