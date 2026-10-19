'use strict';
// Synthetic session HTTP server only; never uses a source-site login or fixture.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const config = JSON.parse(fs.readFileSync(0, 'utf8'));
  assert.match(config.base, /^http:\/\/127\.0\.0\.1:\d+$/);
  const { chromium } = require(process.env.ADC_PLAYWRIGHT_MODULE || path.join(config.root, '.tmp/accessibility-tools/node_modules/playwright-core'));
  const browser = await chromium.launch({ executablePath: process.env.ADC_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const output = path.join(config.root, '.tmp/workspace-overview-acceptance');
  fs.mkdirSync(output, { recursive: true });
  let checks = 0;
  const check = (condition, message) => { assert.ok(condition, message); checks++; };
  const asset = name => path.join(config.root, 'wp-content/plugins/auto-dealership-core/assets/css', name);
  const style = async page => {
    await page.evaluate(() => document.body.classList.add('adc-admin', 'adc-admin-shell'));
    await page.addStyleTag({content: ':root{--ab-text:#172b41;--ab-heading:#172b41;--ab-muted:#52657a;--ab-border:#dce3eb;--ab-surface:#fff;--ab-bg:#f3f6fa;--ab-soft:#eef4fc;--ab-primary:#16324f;--ab-action:#245da4;--ab-inverse:#fff;--ab-link:#245da4;--ab-action-hover:#174478;--ab-on-dark-muted:#e3eaf3}body{margin:24px;font-family:Arial,sans-serif;background:var(--ab-bg)}a{color:#245da4}'});
    for (const name of ['admin-tokens.css', 'admin.css', 'admin-workspace.css', 'admin-structure.css']) await page.addStyleTag({path:asset(name)});
  };
  try {
    for (const [actor, user, direction, heading] of [['admin',config.admin,'rtl','ملخص العمل'],['sales',config.sales,'ltr','Operational overview']]) {
      const context = await browser.newContext();
      await context.request.get(`${config.base}/adc-test-session?key=${config.key}&user_id=${user}`);
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      for (const width of [1440,390]) {
        await page.setViewportSize({width,height:1000});
        const response = await page.goto(`${config.base}/wp-admin/admin.php?page=adc-workspace&workspace_task=overdue`);
        check(response.status() === 200, `${actor} workspace status`);
        await style(page);
        check(await page.locator('#adc-overview-heading').innerText() === heading, `${actor} localized heading`);
        check(await page.locator('.adc-workspace').getAttribute('dir') === direction, `${actor} direction`);
        check(await page.locator('.adc-work-list tbody tr').count() === 20, `${actor} bounded initial page`);
        check(!(await page.locator('body').innerText()).includes('FOREIGN_CUSTOMER_SECRET'), `${actor} foreign identity absent`);
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `${actor} ${width}px no page overflow`);
        check(await page.locator('.adc-overview-metric[aria-current="true"]').count() === 1, 'Selected indicator is announced');
		await page.locator('.adc-work-table').focus();
		check(await page.locator('.adc-work-table').evaluate(node => document.activeElement === node), 'Scrollable table is keyboard accessible');
        await page.screenshot({path:path.join(output, `${actor}-${width}.png`),fullPage:true});
      }
      check(await page.locator('.adc-workspace-card').count() === 0, 'Home does not repeat module cards');
      check(await page.locator('.adc-area-card').count() > 0, 'Home provides compact area navigation');
      await page.locator('.adc-overview-pagination a').filter({hasText:'2'}).first().click();
      check(await page.locator('.adc-work-list tbody tr').count() === (actor === 'admin' ? 13 : 5), 'Actual page-two link preserves scoped queue');
      await page.locator('[name="workspace_search"]').fill('FOREIGN_CUSTOMER_SECRET');
      await Promise.all([page.waitForNavigation(),page.locator('.adc-overview-filters button').click()]);
      check(await page.locator('.adc-work-list tbody tr').count()===(actor==='admin'?3:0),'Customer search respects administrator-global and sales-branch access');
      await page.locator('[name="workspace_search"]').fill('Overview');
      await Promise.all([page.waitForNavigation(),page.locator('.adc-overview-filters button').click()]);
      check(await page.locator('.adc-work-list tbody tr').count()===20,'Customer search remains available in the moved follow-up list');
      check(await page.locator('.adc-work-list tbody tr').first().locator('td').count()===5&&await page.locator('.adc-work-list a[href*="adc-customer-workspace"]').count()===20,'Owner column and customer links survive consolidation');
      await page.locator('.adc-overview-pagination a').filter({hasText:'2'}).first().click();
      check(await page.locator('[name="workspace_search"]').inputValue()==='Overview'&&await page.locator('.adc-work-list tbody tr').count()===(actor==='admin'?10:5),'Paging retains customer search and exact result count');
      if (actor === 'admin') {
        for (const [slug, expected, absent] of [
          ['adc-contact','input[name="settings[phone]"]','input[name="settings[facebook]"]'],
          ['adc-social-channels','input[name="social_urls[facebook]"]','input[name="settings[phone]"]'],
          ['adc-editorial-setup','.adc-setup-tools','input[name="settings[vat_percent]"]'],
          ['adc-transfers','a[href*="section=new"]','a[href*="adc-transfer-queue"]'],
        ]) {
          await page.goto(`${config.base}/wp-admin/admin.php?page=${slug}`);
          await style(page);
          check(await page.locator(expected).count() === 1, `${slug} canonical operation exists`);
          check(await page.locator(absent).count() === 0, `${slug} duplicate editor absent`);
          check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `${slug} mobile page does not overflow`);
          await page.screenshot({path:path.join(output, `${slug}-390.png`), fullPage:true});
        }
      }
      check(errors.length === 0, 'No browser script errors');
      await context.close();
    }
  } finally { await browser.close(); }
  console.log(`PASS: ${checks} real browser workspace checks (Arabic/English, 1440/390px).`);
})().catch(error => { console.error(error.message); process.exitCode = 1; });
