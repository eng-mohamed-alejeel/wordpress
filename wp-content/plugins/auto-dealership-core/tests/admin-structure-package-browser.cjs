'use strict';
const fs = require('node:fs');
const path = require('node:path');

// Extracted ZIPs, actual WP menus/header/footer and real plugin styles; synthetic database only.
module.exports = async ({browser, config, check}) => {
  const output = path.join(config.root, '.tmp/admin-structure-acceptance');
  fs.mkdirSync(output, {recursive:true});
  const context = await browser.newContext();
  await context.route(/^https?:\/\//, route => new URL(route.request().url()).origin === config.base ? route.continue() : route.abort());
  await context.request.get(`${config.base}/adc-product-admin-fixture?key=${config.key}`);
  await context.request.get(`${config.base}/adc-product-session?key=${config.key}`);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  const visit = async (slug, extra='') => {
    const response = await page.goto(`${config.base}/wp-admin/admin.php?page=${slug}${extra}`);
    check(response.status() === 200, `Actual WP page: ${slug}${extra}`);
    check(!(await page.locator('body').innerText()).includes('Fatal error'), `No fatal output: ${slug}`);
  };
  await visit('adc-workspace');
  fs.writeFileSync(path.join(output,'menu-admin.json'), JSON.stringify(await page.locator('#adminmenu a[href]').evaluateAll(nodes => nodes.map(node => ({title:node.textContent.trim(),href:node.getAttribute('href')}))),null,2));
  for (const slug of ['adc-contact','adc-social-channels','adc-platform-setup','adc-transfers']) {
    check(await page.locator(`#adminmenu a[href$="page=${slug}"]`).count() === 1, `Exactly one sidebar entry: ${slug}`);
  }
  check(await page.locator('#adminmenu a[href*="adc-transfer-queue"],#adminmenu a[href*="options-general.php?page=adc-platform-setup"]').count() === 0, 'Retired sidebar links removed');
  check(await page.locator('#adminmenu a[href="index.php"]').count() === 0, 'There is a single workspace home, without the old WordPress dashboard shortcut');
  await page.goto(`${config.base}/wp-admin/index.php`);
  check(new URL(page.url()).searchParams.get('page') === 'adc-workspace', 'Core dashboard bookmark redirects to the workspace before menu access validation');
  check(await page.locator('.adc-workspace .adc-workspace-card').count() === 0 && await page.locator('.adc-area-card').count() > 0, 'Actual home contains area cards without repeated module links');
  check(await page.locator('.adc-area-card > span:last-child').first().innerText() === '›', 'Area navigation uses its intended directional glyph');
  for (const language of ['ar','en']) {
    await context.request.get(`${config.base}/adc-product-session?key=${config.key}&locale=${language === 'ar' ? 'ar' : 'en_US'}`);
    for (const width of [1440,390]) {
      await page.setViewportSize({width,height:950});
      for (const slug of ['adc-workspace','adc-area-settings','adc-contact','adc-social-channels','adc-editorial-setup','adc-transfers','adc-brand-colors']) {
        await visit(slug, `&lang=${language}`);
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `${slug}: ${language}/${width} has no page overflow`);
        check(await page.locator('html').evaluate(node => getComputedStyle(node).direction) === (language === 'ar' ? 'rtl' : 'ltr'), `${slug}: actual core direction is ${language}`);
        check(await page.locator('#adminmenu').evaluate(node => getComputedStyle(node).listStyleType === 'none'), `${slug}: actual core menu CSS loaded`);
        check(await page.locator('link[href*="admin-structure.css"]').count() === 1, `${slug}: canonical stylesheet loaded`);
        await page.screenshot({path:path.join(output,`${slug}-${language}-${width}.png`),fullPage:true});
      }
    }
  }
  for (const [url, owner] of [
    ['options-general.php?page=adc-platform-setup&section=appearance','adc-contact'],
    ['admin.php?page=adc-platform-setup&section=policy','adc-settings'],
    ['admin.php?page=adc-platform-setup&section=pages','adc-editorial-setup'],
    ['admin.php?page=adc-transfer-queue','adc-transfers'],
  ]) {
    await page.goto(`${config.base}/wp-admin/${url}`);
    check(new URL(page.url()).searchParams.get('page') === owner, `Core menu bootstrap redirects retired URL to ${owner}`);
  }
  await visit('adc-workspace');
  await page.locator('#wp-admin-bar-ab-appearance > a').click();
  check(await page.locator('html').getAttribute('data-ab-theme') === 'dark', 'Actual dashboard appearance switches to dark');
  check(await page.locator('.adc-area-card').first().evaluate(node => {
    const style = getComputedStyle(node);
    const luminance = value => value.match(/[\d.]+/g).slice(0,3).map(Number).map(v => {v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
    const a=luminance(style.color),b=luminance(style.backgroundColor);
    return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5;
  }), 'Dark area navigation has readable text contrast');
  await page.screenshot({path:path.join(output,'adc-workspace-dark-390.png'),fullPage:true});
  await page.locator('#wp-admin-bar-ab-appearance > a').click();
  await visit('adc-contact');
  await page.locator('#setup-phone').fill('+966500000003');
  await page.locator('#setup-email').fill('package-contact@example.invalid');
  await Promise.all([page.waitForNavigation(), page.locator('form input[type=submit]').click()]);
  check(new URL(page.url()).searchParams.get('page') === 'adc-contact' && await page.locator('#setup-phone').inputValue() === '+966500000003', 'Packaged contact form saves and returns to canonical owner');
  await context.request.get(`${config.base}/adc-product-session?key=${config.key}&actor=staff`);
  await visit('adc-workspace');
  check(new URL(page.url()).searchParams.get('page') === 'adc-workspace' && await page.locator('#adminmenu').count() === 1 && await page.locator('#adc-overview-heading').count() === 1, 'Actual dealership sales role reaches its operational workspace');
  check(await page.locator('#adminmenu a[href*="page=adc-contact"],#adminmenu a[href*="page=adc-social-channels"]').count() === 0, 'Actual sales sidebar omits settings owners');
  fs.writeFileSync(path.join(output,'menu-sales.json'), JSON.stringify(await page.locator('#adminmenu a[href]').evaluateAll(nodes => nodes.map(node => ({title:node.textContent.trim(),href:node.getAttribute('href')}))),null,2));
  await page.goto(`${config.base}/wp-admin/index.php`);
  check(new URL(page.url()).searchParams.get('page') === 'adc-workspace', 'Sales WordPress dashboard bookmark also uses the canonical workspace');
  for (const slug of ['adc-contact','adc-social-channels','adc-platform-setup','adc-transfers']) {
    const result = await page.goto(`${config.base}/wp-admin/admin.php?page=${slug}`);
    check(result.status() === 403, `Core menu access denies sales: ${slug}`);
  }
  for (const [role, routes] of [
    ['dealership_finance', [['adc-onyx-documents',200],['adc-payments',200],['adc-contact',403]]],
    ['dealership_inventory', [['adc-transfers',200],['adc-inventory-identity',200],['adc-vehicle-vin',403]]],
    ['dealership_general_manager', [['adc-vehicle-vin',200],['adc-inventory-identity',200],['adc-contact',403]]],
    ['dealership_delivery', [['adc-delivery',200],['adc-vehicle-vin',403],['adc-contact',403]]],
  ]) {
    await context.request.get(`${config.base}/adc-product-session?key=${config.key}&actor=${role}`);
    for (const [slug,status] of routes) {
      const response = await context.request.get(`${config.base}/wp-admin/admin.php?page=${slug}`);
      const html = await response.text();
      check(response.status()===status && !html.includes('Fatal error') && !html.includes('critical error on this website'), `Actual ${role} access to ${slug} is ${status}`);
      if (role==='dealership_general_manager'&&slug==='adc-inventory-identity') check(html.includes('VIN')&&!html.includes('value="adc_record_vehicle_receipt"'),'Old intake bookmark preserves VIN-only manager access without intake permission');
    }
  }
  check(errors.length === 0, `No browser script errors: ${errors.join('; ')}`);
  await context.close();
};
