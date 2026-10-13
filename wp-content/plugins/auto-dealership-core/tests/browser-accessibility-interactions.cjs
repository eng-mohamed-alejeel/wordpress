'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { output, launch, mode, axe } = require('./accessibility-browser.cjs');
(async () => {
    const { browser, page } = await launch();
    const checks = [], scans = [];
    const check = (value, label) => { checks.push({ label, pass: !!value }); console.log((value ? 'PASS ' : 'FAIL ') + label); };
    try {
        for (const lang of ['ar', 'en']) for (const appearance of ['light', 'dark']) {
            const suffix = lang === 'en' ? '?lang=en' : '';
            await page.setViewportSize({ width: 390, height: 844 });
            await page.goto('http://localhost/wordpress/contact/' + suffix, { waitUntil: 'networkidle' });
            await mode(page, appearance);
            await page.keyboard.press('Tab');
            check(await page.locator('.skip-link').evaluate(e => e === document.activeElement && e.getBoundingClientRect().top >= 0), `${lang}/${appearance} visible skip link is first keyboard stop`);
            await page.keyboard.press('Enter');
            check(await page.evaluate(() => location.hash === '#primary' && (document.activeElement.id === 'primary' || document.querySelector('main').contains(document.activeElement))), `${lang}/${appearance} skip link moves focus to main`);
            const toggle = page.locator('.menu-toggle');
            await toggle.focus(); await page.keyboard.press('Enter');
            check(await toggle.getAttribute('aria-expanded') === 'true', `${lang}/${appearance} Enter opens mobile menu`);
            await page.keyboard.press('Escape');
            check(await toggle.getAttribute('aria-expanded') === 'false' && await toggle.evaluate(e => e === document.activeElement), `${lang}/${appearance} Escape closes menu and restores focus`);
            await page.locator('.language-dropdown summary').focus(); await page.keyboard.press('Enter');
            check(await page.locator('.language-dropdown').evaluate(e => e.open), `${lang}/${appearance} keyboard opens language selector`);
            await page.keyboard.press('Escape');
            check(!await page.locator('.language-dropdown').evaluate(e => e.open), `${lang}/${appearance} Escape closes language selector`);
            await page.waitForSelector('#cd-cookie-settings-btn');
            await page.locator('#cd-cookie-settings-btn').focus(); await page.keyboard.press('Enter');
            await page.waitForTimeout(500);
            check(await page.evaluate(() => document.querySelector('#cd-cookie-settings').contains(document.activeElement) && document.querySelector('#page').inert), `${lang}/${appearance} cookie dialog owns focus and background is inert`);
            scans.push({ state: `${lang}/${appearance}/cookies`, ...await axe(page) });
            await page.locator('.cd-cookie-save-btn').focus(); await page.keyboard.press('Tab');
            check(await page.locator('.cd-cookie-settings-close').evaluate(e => e === document.activeElement), `${lang}/${appearance} dialog traps forward Tab`);
            await page.keyboard.press('Shift+Tab');
            check(await page.locator('.cd-cookie-save-btn').evaluate(e => e === document.activeElement), `${lang}/${appearance} dialog traps reverse Tab`);
            await page.keyboard.press('Escape');
            check(await page.locator('#cd-cookie-settings-btn').evaluate(e => e === document.activeElement) && !await page.locator('#page').evaluate(e => e.inert), `${lang}/${appearance} dialog Escape restores focus and background`);
            await page.waitForTimeout(400);
            await page.locator('#cd-cookie-settings-btn').click();
            await page.locator('#cd-cookie-settings-cancel').click();
            await page.waitForTimeout(400);
            check(await page.locator('#cd-cookie-settings').count() === 0, `${lang}/${appearance} cancel button closes dialog`);
            await page.locator('#cd-cookie-accept-essential').click();
            await page.waitForTimeout(600);
            const invalid = await page.locator('.cd-contact-form').evaluate(form => {
                const email = form.querySelector('input[type=email]');
                email.value = 'invalid';
                const valid = email.checkValidity();
                return !valid && email.labels.length > 0;
            });
            check(invalid, `${lang}/${appearance} invalid email is rejected by a named native control without submission`);
            check(await page.locator('.cd-contact-form').evaluate(form => !!form.querySelector('[role=status], [aria-live]')), `${lang}/${appearance} contact feedback has a live region`);
            for (const width of [320, 640, 1280]) {
                await page.setViewportSize({ width, height: 900 });
                const style = await page.addStyleTag({ content: '.site * { line-height: 1.5 !important; letter-spacing: .12em !important; word-spacing: .16em !important; } .site p { margin-bottom: 2em !important; }' });
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `${lang}/${appearance} text spacing and reflow fit ${width}px`);
                await style.evaluate(e => e.remove());
            }
            await page.setViewportSize({ width: 1280, height: 900 });
            const zoom = await page.addStyleTag({ content: '.site { zoom: 2; }' });
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `${lang}/${appearance} 200% zoom preserves reflow`);
            await zoom.evaluate(e => e.remove());
            await page.evaluate(() => localStorage.removeItem('cd_cookie_consent'));
        }
        check(scans.every(s => !s.violations.length), 'Open cookie dialogs pass axe checks in both languages and modes');
        fs.writeFileSync(path.join(output, 'interactions.json'), JSON.stringify({ checks, scans }, null, 2));
        if (checks.some(c => !c.pass)) process.exitCode = 1;
    } finally { fs.writeFileSync(path.join(output, 'interactions.json'), JSON.stringify({ checks, scans }, null, 2)); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
