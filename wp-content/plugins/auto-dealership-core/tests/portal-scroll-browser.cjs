/* Layout-only fixtures: no messages or customer data are written. */
module.exports=async({page,check})=>{
 const viewport=page.viewportSize(),history=page.locator('.adc-p-messages');
 await page.evaluate(()=>{const history=document.querySelector('.adc-p-messages');for(let i=0;i<50;i++){const row=document.createElement('article');row.dataset.scrollFixture='1';row.className='adc-p-message';row.style.minHeight='70px';row.textContent='Scroll acceptance '+i;history.append(row);}history.scrollTop=0;});
 const response=page.waitForResponse(r=>r.url().includes('action=adc_portal_poll'));
 await page.locator('[data-refresh-thread]').click();await response;await page.waitForTimeout(200);
 check(await history.evaluate(el=>el.scrollTop<2),'refresh preserves the position while reading earlier messages');
 const stable=await page.evaluate(()=>{const node=document.querySelector('.adc-chat-peer'),before=node.getBoundingClientRect().height,y=scrollY;node.classList.add('is-typing');const after=node.getBoundingClientRect().height;node.classList.remove('is-typing');return Math.abs(before-after)<1&&Math.abs(scrollY-y)<1;});
 check(stable,'typing indicator reserves the same height without shifting the page');
 await page.setViewportSize({width:1100,height:600});await history.evaluate(el=>{window.scrollTo(0,Math.max(0,el.getBoundingClientRect().top+scrollY-100));el.scrollTop=el.scrollHeight;});await page.waitForTimeout(150);
 const box=await history.boundingBox(),before=await page.evaluate(()=>scrollY);await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await page.mouse.wheel(0,400);await page.waitForTimeout(500);if(!await page.evaluate(y=>scrollY>y,before)){await page.mouse.wheel(0,400);await page.waitForTimeout(500);}
 const moved=await page.evaluate(y=>scrollY>y,before);if(!moved)console.log('Scroll diagnostic',await history.evaluate((el,y)=>({before:y,after:scrollY,max:document.documentElement.scrollHeight-innerHeight,overscroll:getComputedStyle(el).overscrollBehaviorY,top:el.getBoundingClientRect().top,height:el.clientHeight,scroll:el.scrollTop,end:el.scrollHeight-el.clientHeight}),before));
 check(moved,'wheel scrolling continues into the page at the end of message history');
 await page.evaluate(()=>document.querySelectorAll('[data-scroll-fixture]').forEach(el=>el.remove()));if(viewport)await page.setViewportSize(viewport);await history.evaluate(el=>{el.scrollTop=el.scrollHeight;});
};
