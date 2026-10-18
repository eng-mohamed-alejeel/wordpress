/* Two real authenticated sessions, backed by the extracted PHP package. */
module.exports=async({page,staff,staffCtx,reply,check})=>{
 if(await page.locator('#cd-cookie-accept-essential').isVisible())await page.locator('#cd-cookie-accept-essential').click();
 const input=reply.locator('[name=body]'),peer=page.locator('.adc-chat-peer'),staffPeer=staff.locator('.adc-chat-peer');
 const hasTyping=async locator=>(await locator.textContent()).includes('Typing');
 const waitTyping=(p,value)=>p.waitForFunction(expected=>{const text=document.querySelector('.adc-chat-peer')?.textContent||'';return text.includes('Typing')===expected;},value,{timeout:4000});
 const packets=[],reads=[];
 const monitor=request=>{if(!request.url().includes('admin-ajax.php'))return;if(request.method()==='POST'&&(request.postData()||'').includes('adc_live_status'))packets.push(request.postData());if(request.method()==='GET'&&new URL(request.url()).searchParams.get('action')==='adc_live_status')reads.push(Date.now());};
 staff.on('request',monitor);
 await input.focus();await staff.waitForTimeout(1200);check(!await hasTyping(peer),'focusing the composer alone never announces typing');
 const before=reads.length;await staff.waitForTimeout(3500);check(reads.length-before>=2&&reads.length-before<=5,'peer-state checks run approximately once a second in an open conversation');
 await input.fill('Typing preview that must stay private');await waitTyping(page,true);check(true,'the first actual text input announces typing');
 await reply.locator('button').focus();await waitTyping(page,false);check(true,'leaving the textarea clears peer typing without sending a message');
 await input.fill('Resumed typing preview');await waitTyping(page,true);const idleStart=Date.now();await waitTyping(page,false);check(Date.now()-idleStart<3500,'typing expires after about two seconds of keyboard inactivity');
 await input.fill('Before switching to internal notes');await waitTyping(page,true);await reply.locator('[name=internal]').check();await input.fill('INTERNAL_DRAFT_NEVER_SENT');await waitTyping(page,false);await staff.waitForTimeout(1300);check(!await hasTyping(peer),'internal-note input never announces customer-visible typing');await reply.locator('[name=internal]').uncheck();
 await input.fill('Network interruption preview');await waitTyping(page,true);await staffCtx.setOffline(true);await waitTyping(page,false);check(true,'lost network cannot leave the other session stuck typing');await staffCtx.setOffline(false);await staff.waitForTimeout(600);
 await input.fill('Before hiding the page');await waitTyping(page,true);await staff.evaluate(()=>{Object.defineProperty(document,'hidden',{get:()=>true,configurable:true});document.dispatchEvent(new Event('visibilitychange'));});await waitTyping(page,false);const hiddenReads=reads.length;await staff.waitForTimeout(1400);check(reads.length===hiddenReads,'typing status polling stops while the page is hidden');await staff.evaluate(()=>{delete document.hidden;document.dispatchEvent(new Event('visibilitychange'));});
 const widgetInput=page.locator('.adc-chat-panel textarea');await widgetInput.fill('Customer-side typing preview');await waitTyping(staff,true);await page.locator('[data-chat-close]').click();await waitTyping(staff,false);check(true,'closing the floating conversation clears customer typing');await page.locator('.adc-chat-launch').click();await widgetInput.fill('');
 check(packets.length>0&&packets.every(body=>!body.includes('name="body"')&&!body.includes('Typing preview')&&!body.includes('INTERNAL_DRAFT_NEVER_SENT')),'typing traffic contains state metadata only, without draft or internal-note text');
 await input.fill('');staff.off('request',monitor);
};
