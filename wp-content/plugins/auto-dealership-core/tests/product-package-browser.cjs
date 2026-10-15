const fs = require('fs');
const path = require('path');
const config = JSON.parse(fs.readFileSync(0, 'utf8'));
const { chromium } = require(path.join(config.root, '.tmp/accessibility-tools/node_modules/playwright-core'));
let browser;
let checks = 0;
const check = (ok, label) => { if (!ok) throw new Error(label); checks++; process.stdout.write(`PASS Package browser: ${label}\n`); };
(async () => {
  browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(config.base + '/?lang=en');
  if (!await page.locator('h1').count()) throw new Error('Homepage missing heading: ' + page.url() + ' ' + (await page.content()).slice(0, 2400));
  check((await page.locator('h1').first().innerText()).includes('Example Motors'), 'clean homepage uses tenant identity');
  check(!(await page.locator('body').innerText()).includes('Auto Brands'), 'homepage has no old business identity');
  check(await page.locator('a[href*="wa.me"]').count() === 0, 'unset contact channels do not create fake WhatsApp links');
  const defaultMenu = await page.locator('#primary-menu > li:not(.menu-item-home):not(.cd-account-link) > a').allTextContents();
  check(JSON.stringify(defaultMenu.map(x => x.trim())) === JSON.stringify(['All vehicles','About us','Finance Calculator','FAQ','Your vehicle buying guide','Contact us']), 'upper menu contains only six requested pages in order with English labels');
  for (const lang of ['ar','en']) {
    await page.goto(config.base + '/?pagename=about&lang=' + lang);
    check(await page.locator('main h1').count() === 1 && await page.locator('.adc-about-value').count() === 3 && await page.locator('.adc-about-step').count() === 3, `About layout has a single heading and complete sections: ${lang}`);
    const aboutText = await page.locator('.adc-about-page').innerText();
    check(!aboutText.includes('[adc_') && (lang !== 'en' || !/\p{Script=Arabic}/u.test(aboutText)), `About text and actions follow the selected language: ${lang}`);
  }
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'About page fits mobile viewport');
  await page.setViewportSize({width:1440,height:950});
  await page.goto(config.base + '/?pagename=contact&lang=en');
  check(await page.locator('form[data-action="car_dealer_contact"]').count() > 0, 'provisioned contact page renders its form');
  for (const slug of ['privacy-policy', 'terms', 'cookie-policy', 'reservation-policy']) {
    for (const lang of ['ar', 'en']) {
      await page.goto(config.base + '/?pagename=' + slug + '&lang=' + lang);
      const legal = page.locator('article.legal-page');
      const text = await legal.innerText();
      check(await legal.locator('h2').count() >= 6 && text.length > 800 && !text.includes('Complete, review') && !text.includes('Suggested text:') && !text.includes('awaiting review'), `full legal template and content: ${slug} ${lang}`);
      check(!text.includes('[adc_') && !text.includes('autobrands2020') && (lang !== 'en' || !/\p{Script=Arabic}/u.test(text)), `legal shortcodes and tenant translation: ${slug} ${lang}`);
    }
  }
  const denied = await context.request.post(config.base + '/wp-admin/admin-post.php', { form: { action: 'adc_platform_setup', operation: 'save', section: 'identity', 'settings[brand_ar]': 'Forbidden' } });
  check(denied.status() === 403, 'anonymous settings mutation is forbidden');
  await context.request.get(config.base + '/adc-product-session?key=' + config.key);
  await context.route('https://www.google.com/maps/**', route => route.abort());
  const branches = config.base + '/wp-admin/options-general.php?page=adc-branch-locations';
  await page.goto(branches);
  for (let i = 0; i < 3; i++) {
    await page.locator('#adc-add-branch').click();
    const row = page.locator('.adc-branch-editor').last();
    await row.locator('input[name$="[name_ar]"]').fill('\u0641\u0631\u0639 ' + i);
    await row.locator('input[name$="[name_en]"]').fill('Branch ' + i);
    await row.locator('input[name$="[address_ar]"]').fill('\u062c\u062f\u0629');
    await row.locator('input[name$="[address_en]"]').fill('Jeddah');
    await row.locator('input[name$="[embed_url]"]').fill('https://www.google.com/maps/embed?pb=test' + i);
  }
  await Promise.all([page.waitForNavigation(), page.locator('#adc-branches-form input[type=submit]').click()]);
  check(await page.locator('.adc-branch-editor').count() === 3 && await page.locator('.notice-success').count() === 1, 'branch dashboard adds and saves future branches');
  await page.locator('.adc-branch-editor').last().locator('[data-branch-up]').click();
  await page.locator('.adc-branch-editor').first().locator('input[type=checkbox]').uncheck();
  await Promise.all([page.waitForNavigation(), page.locator('#adc-branches-form input[type=submit]').click()]);
  await page.goto(config.base + '/?pagename=contact&lang=en');
  check(await page.locator('.adc-branch-card').count() === 2 && (await page.locator('.adc-branch-card h3').allTextContents()).join(',') === 'Branch 2,Branch 1', 'contact page respects branch visibility and order');
  check(await page.locator('.adc-branch-map iframe[loading=lazy]').count() === 2 && !/\p{Script=Arabic}/u.test(await page.locator('.adc-branch-directory').innerText()), 'branch maps render lazily with English interface and names');
  check(await page.evaluate(() => !!(document.querySelector('.adc-branch-directory').compareDocumentPosition(document.querySelector('form[data-action="car_dealer_contact"]')) & Node.DOCUMENT_POSITION_FOLLOWING)), 'branch maps precede the contact form');
  check(await page.locator('.adc-contact-branch-card').count() === 2 && (await page.locator('.adc-contact-branch-card h3').allTextContents()).join(',') === 'Branch 2,Branch 1', 'top contact cards include every visible branch in matching order');
  check(await page.evaluate(() => {
    const maps = document.querySelector('.adc-branch-directory'), intro = document.querySelector('.adc-contact-intro'), form = document.querySelector('form[data-action="car_dealer_contact"]');
    return document.querySelectorAll('.adc-contact-intro').length === 1 && !!(maps.compareDocumentPosition(intro) & Node.DOCUMENT_POSITION_FOLLOWING) && !!(intro.compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING);
  }), 'enquiry guidance appears once between maps and the form');
  check(await page.locator('.adc-contact-guidance li').count() === 3 && !/\p{Script=Arabic}/u.test(await page.locator('.adc-contact-intro').innerText()), 'enquiry guidance contains three complete English notices');
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'branch map cards fit mobile viewport');
  await page.setViewportSize({width:1440,height:950});
  await page.goto(config.base + '/?pagename=about&lang=en');
  check((await page.locator('.adc-about-location h3').allTextContents()).join(',') === 'Branch 2,Branch 1', 'About branch list stays synchronized with dashboard visibility and order');
  await page.goto(branches);
  while (await page.locator('[data-branch-remove]').count()) await page.locator('[data-branch-remove]').first().click();
  await Promise.all([page.waitForNavigation(), page.locator('#adc-branches-form input[type=submit]').click()]);
  check(await page.locator('.adc-branch-editor').count() === 0, 'administrator can remove branches without leaving stale cards');
  const setup = config.base + '/wp-admin/options-general.php?page=adc-platform-setup';
  for (const section of ['identity', 'appearance', 'policy', 'mail', 'pages', 'readiness']) {
    await page.goto(setup + '&section=' + section);
    if (await page.locator('.nav-tab').count() !== 6) throw new Error('Setup response: ' + page.url() + ' ' + (await page.content()).slice(0, 3000));
    check(await page.locator('h1').count() === 1 && await page.locator('.nav-tab').count() === 6, `${section} setup renders without fatal errors`);
  }
  await page.goto(setup + '&section=identity');
  await page.locator('#setup-brand_en').fill('Updated Motors');
  await Promise.all([page.waitForNavigation(), page.locator('input[type=submit]').click()]);
  check(await page.locator('#setup-brand_en').inputValue() === 'Updated Motors', 'identity form saves through nonce-protected POST');
  check(await page.locator('.notice-success').count() === 1, 'save displays a single completion notice');
  await page.reload();
  check(await page.locator('.notice-success').count() === 0, 'completion notice does not persist after reload');
  await page.goto(setup + '&section=appearance');
  await page.locator('#setup-address').fill('\u0627\u0644\u0641\u0631\u0639 \u0627\u0644\u0631\u0626\u064a\u0633\u064a\u060c \u062c\u062f\u0629');
  await page.locator('#setup-address_en').fill('Main Branch, Jeddah');
  await Promise.all([page.waitForNavigation(), page.locator('form input[type=submit]').click()]);
  check(await page.locator('#setup-address_en').inputValue() === 'Main Branch, Jeddah', 'English address survives active theme settings sanitization');
  await page.goto(config.base + '/?pagename=reservation-policy&lang=en');
  check((await page.locator('article.legal-page').innerText()).includes('Main Branch, Jeddah'), 'Saved English address appears on public English legal pages');
  await page.goto(config.base + '/?pagename=contact&lang=en');
  check((await page.locator('.ab-contact-grid').innerText()).includes('Main Branch, Jeddah') && !/\p{Script=Arabic}/u.test(await page.locator('.ab-contact-grid').innerText()), 'Contact cards use English address and translated labels');
  await page.goto(config.base + '/?pagename=contact&lang=ar');
  check((await page.locator('.ab-contact-grid').innerText()).includes('\u0627\u0644\u0641\u0631\u0639'), 'Arabic contact cards retain the Arabic address');
  const badNonce = await context.request.post(config.base + '/wp-admin/admin-post.php', { form: { action: 'adc_platform_setup', operation: 'save', section: 'identity', 'settings[brand_ar]': 'Forbidden', _wpnonce: 'invalid' } });
  check(badNonce.status() === 403, 'authenticated mutation with invalid nonce is rejected');
  await page.goto(setup + '&section=readiness');
  const exportForm = page.locator('form').filter({ has: page.locator('input[name=operation][value=export]') });
  const nonce = await exportForm.locator('input[name=_wpnonce]').inputValue();
  const exported = await context.request.post(config.base + '/wp-admin/admin-post.php', { form: { action: 'adc_platform_setup', operation: 'export', section: 'readiness', _wpnonce: nonce } });
  const data = await exported.json();
  check(data.product === 'auto-dealership' && data.settings.profile.brand_en === 'Updated Motors' && !JSON.stringify(data).includes('ADC_SMTP_PASSWORD'), 'export includes selected settings and excludes SMTP credentials');
  data.settings.profile.brand_en = 'Imported Motors';
  await page.locator('input[type=file]').setInputFiles({ name: 'settings.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(data)) });
  const upload = page.locator('form').filter({ has: page.locator('input[name=operation][value=preview]') });
  await Promise.all([page.waitForNavigation(), upload.locator('input[type=submit]').click()]);
  check((await page.locator('pre').innerText()).includes('Imported Motors'), 'uploaded settings require a visible preview');
  const apply = page.locator('form').filter({ has: page.locator('input[name=operation][value=import]') });
  await Promise.all([page.waitForNavigation(), apply.locator('input[type=submit]').click()]);
  await page.goto(config.base + '/?lang=en');
  check((await page.locator('h1').first().innerText()).includes('Imported Motors'), 'approved import updates frontend identity');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(setup + '&section=identity');
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'setup fits mobile viewport');
  check(errors.length === 0, 'packaged frontend and setup have no JavaScript errors: ' + errors.join(' | '));
  process.stdout.write(`Product package browser passed (${checks} checks). No real email sent.\n`);
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; }).finally(async () => { if (browser) await browser.close(); });
