'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { output, launch, axe } = require('./accessibility-browser.cjs');
const auditTextContrast = require('./browser-color-audit.cjs');

(async () => {
    const { browser, page } = await launch();
    const report = [];
    try {
        for (const language of ['ar', 'en']) {
            const suffix = language === 'en' ? '?lang=en' : '';
            await page.goto('http://localhost/wordpress/' + suffix, { waitUntil: 'networkidle' });
            const choices = page.locator('.site-header [data-ab-appearance-option]');
            assert.equal(await choices.count(), 3, `${language}: three appearance choices are visible`);
            assert.deepEqual(await choices.evaluateAll(buttons => buttons.map(button => button.dataset.abAppearanceOption)), ['light', 'system', 'dark']);

            const system = choices.filter({ has: page.locator('svg') }).nth(1);
            await system.focus();
            await page.keyboard.press(language === 'ar' ? 'ArrowLeft' : 'ArrowRight');
            assert.equal(await page.evaluate(() => document.documentElement.dataset.abPreference), 'dark', `${language}: arrow keys follow visual direction`);

            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
                for (const appearance of ['light', 'dark']) {
                    const option = page.locator(`.site-header [data-ab-appearance-option="${appearance}"]`);
                    await option.click();
                    await page.waitForFunction(value => document.documentElement.dataset.abPreference === value, appearance);
                    await page.waitForTimeout(500);
                    assert.equal(await option.getAttribute('aria-checked'), 'true', `${language}/${width}/${appearance}: selected state is announced`);
                    assert.equal(await page.locator('.site-header [data-ab-appearance-option][aria-checked="true"]').count(), 1, `${language}/${width}/${appearance}: only one choice is selected`);
                    const metrics = await page.evaluate(() => {
                        const button = document.querySelector('.site-header [data-ab-appearance-option][aria-checked="true"]');
                        const box = button.getBoundingClientRect();
                        button.focus();
                        const style = getComputedStyle(button);
                        return { width: box.width, height: box.height, overflow: document.documentElement.scrollWidth - innerWidth, outline: parseFloat(style.outlineWidth) };
                    });
                    assert.ok(metrics.width >= 38 && metrics.height >= 38, `${language}/${width}/${appearance}: appearance choice has a practical pointer target`);
                    assert.ok(metrics.overflow <= 2, `${language}/${width}/${appearance}: header fits the viewport`);
                    assert.ok(metrics.outline >= 3, `${language}/${width}/${appearance}: keyboard focus is visible`);
                    const contrast = await page.evaluate(`(${auditTextContrast.toString()})()`);
                    assert.equal(contrast.failures.length, 0, `${language}/${width}/${appearance}: solid-background contrast passes: ${JSON.stringify(contrast.failures)}`);
                    const accessibility = await axe(page);
                    assert.equal(accessibility.violations.length, 0, `${language}/${width}/${appearance}: axe passes: ${JSON.stringify(accessibility.violations)}`);
                    report.push({ language, width, appearance, metrics, contrast, accessibility });
                    await page.screenshot({ path: path.join(output, `appearance-${language}-${width}-${appearance}.png`) });
                }
            }
        }
        fs.writeFileSync(path.join(output, 'appearance-control.json'), JSON.stringify(report, null, 2));
        console.log(`APPEARANCE CONTROL PASS: ${report.length} visual and accessibility states`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
