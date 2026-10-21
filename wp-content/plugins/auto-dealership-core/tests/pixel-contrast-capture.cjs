'use strict';
// Browser-only regression: no site, database, credentials or external requests.
const assert=require('node:assert/strict');
const {launch}=require('./accessibility-browser.cjs');
const audit=require('./browser-pixel-contrast.cjs');
(async()=>{
 const {browser,page}=await launch();
 try {
  await page.setViewportSize({width:390,height:1000});
  await page.setContent('<style>body{margin:0;font:20px Arial}main{height:17000px;background:#08264d;color:color(srgb 1 1 1)}footer{height:100px;background:#fff;color:#000}.adc-chat{position:fixed;bottom:10px;right:10px;padding:10px;background:#08264d;color:#fff}</style><main>Top dark surface</main><footer id="end">Bottom light surface</footer><aside class="adc-chat">Floating control</aside>');
  const valid=await audit(page);
  assert.equal(valid.tested,3);assert.equal(valid.failures.length,0,JSON.stringify(valid.failures));
  assert.ok(valid.measurements.find(item=>item.text==='Floating control').floating);
  await page.locator('#end').evaluate(el=>el.style.color='#fff');
  const invalid=await audit(page);
  assert.equal(invalid.failures.length,1);assert.equal(invalid.failures[0].text,'Bottom light surface');
  await page.locator('.adc-chat').evaluate(el=>el.style.color='#08264d');
  const invalidFloating=await audit(page);assert.equal(invalidFloating.failures.length,2);
  assert.ok(invalidFloating.failures.some(item=>item.floating&&item.text==='Floating control'));
  console.log('PASS Tall-page capture preserves both surfaces beyond 16,384px and detects a real contrast failure.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
