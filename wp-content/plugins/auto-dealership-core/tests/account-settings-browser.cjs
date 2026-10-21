'use strict';
// Focused coverage for the account settings fallback when the customer portal is off.
module.exports=async({browser,config,check})=>{
 const errors=[];
 for(const role of ['staff','admin','module','multi']){
  const ctx=await browser.newContext({javaScriptEnabled:false}),page=await ctx.newPage();
  page.on('pageerror',e=>errors.push(e.message));
  const fixture=config.base+'/adc-product-personal-fixture?key='+config.key+'&as='+role;
  try{
   await ctx.request.get(fixture+'&portal_enabled=0');
   const work=await page.goto(config.base+'/?cd_account=dashboard&lang=en');
   check(work.status()===200&&await page.locator('.adc-work-dashboard').count()===1,role+': work remains available with customer portal disabled');
   for(const section of ['requests','preferences']){
    const response=await page.goto(config.base+'/?cd_account=dashboard&portal='+section+'&lang=en');
    check(response.status()===200&&await page.locator('.adc-p-nav a').count()===2,role+': settings survive disabled customer portal '+section);
    check(await page.locator('.adc-p-stat,input[name="consent_marketing"],.adc-p-next').count()===0,role+': no fallback to customer content '+section);
    if(section==='requests')check(await page.locator('input[name="display_name"]').count()===1,role+': old customer link opens account details');
   }
  }finally{await ctx.request.get(fixture+'&portal_enabled=1');await ctx.close();}
 }
 check(errors.length===0,'Account settings fallback has no runtime errors: '+errors.join(' | '));
};
