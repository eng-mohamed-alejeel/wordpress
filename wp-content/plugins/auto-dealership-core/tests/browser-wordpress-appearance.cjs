'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { output, launch, mode, axe } = require('./accessibility-browser.cjs');
(async () => {
    const { browser, page } = await launch(true);
    const report = [];
    try {
        for (const route of ['/wp-admin/customize.php', '/wp-admin/upload.php?mode=grid']) {
            await page.goto('http://localhost/wordpress' + route, { waitUntil: 'networkidle', timeout: 60000 });
            const customizer = route.includes('customize');
            await page.waitForSelector(customizer ? '#ab-customizer-appearance select' : '.attachments-browser');
            for (const appearance of ['light', 'dark']) {
                await mode(page, appearance);
                if (customizer) {
                    const preview = page.frames().find(f => f.url().includes('customize_changeset_uuid'));
                    if (preview) {
                        await preview.waitForFunction(value => document.documentElement.dataset.abTheme === value, appearance);
                        report.push({ route: 'customizer/preview-frame', mode: appearance, ...await axe(preview) });
                    }
                }
                report.push({ route, mode: appearance, ...await axe(page) });
                await page.screenshot({ path: path.join(output, (customizer ? 'customizer-' : 'media-library-') + appearance + '.png') });
                console.log(`${route} ${appearance}: ${report.at(-1).violations.map(v => v.id + '(' + v.nodes.length + ')').join(', ') || 'PASS'}`);
            }
            if (customizer) {
                for (const section of ['title_tagline', 'car_dealer_appearance', 'car_dealer_contact']) {
                    await page.evaluate(id => wp.customize.section(id).expand(), section);
                    await page.waitForTimeout(500);
                    for (const appearance of ['light', 'dark']) {
                        await page.locator('#ab-customizer-appearance select').selectOption(appearance);
                        await page.waitForTimeout(800);
                        report.push({ route: 'customizer/' + section, mode: appearance, ...await axe(page) });
                        await page.screenshot({ path: path.join(output, section + '-' + appearance + '.png') });
                    }
                    if (section === 'car_dealer_appearance') {
                        await page.locator('#customize-control-car_dealer_theme_settings-primary_color .wp-color-result').click();
                        for (const appearance of ['light', 'dark']) {
                            await mode(page, appearance);
                            report.push({ route: 'color-picker', mode: appearance, ...await axe(page) });
                            await page.screenshot({ path: path.join(output, 'color-picker-' + appearance + '.png') });
                        }
                    }
                    if (section === 'title_tagline') {
                        await page.locator('#customize-control-custom_logo .upload-button').click();
                        await page.waitForSelector('.media-modal');
                        for (const appearance of ['light', 'dark']) {
                            await mode(page, appearance);
                            report.push({ route: 'customizer/media-picker', mode: appearance, ...await axe(page) });
                            await page.screenshot({ path: path.join(output, 'media-picker-' + appearance + '.png') });
                        }
                        const uploadTab = page.locator('.media-router [role="tab"]').first();
                        await uploadTab.click();
                        for (const appearance of ['light', 'dark']) {
                            await mode(page, appearance);
                            report.push({ route: 'customizer/upload-tab', mode: appearance, ...await axe(page) });
                        }
                        await page.keyboard.press('Escape');
                        assert.equal(await page.locator('.media-modal:visible').count(), 0, 'Escape closes media picker');
                        await page.waitForFunction(() => document.activeElement === document.querySelector('#customize-control-custom_logo .upload-button'));
                    }
                }
            }
            if (!customizer) {
                const attachment = page.locator('.attachments .attachment').first();
                if (await attachment.count()) {
                    await attachment.dblclick();
                    await page.waitForSelector('.media-modal');
                    for (const appearance of ['light', 'dark']) {
                        await mode(page, appearance);
                        report.push({ route: 'attachment-details', mode: appearance, ...await axe(page) });
                        await page.screenshot({ path: path.join(output, 'media-details-' + appearance + '.png') });
                    }
                    await page.keyboard.press('Escape');
                    assert.equal(await page.locator('.media-modal:visible').count(), 0, 'Escape closes the media modal');
                }
            }
            fs.writeFileSync(path.join(output, 'wordpress.json'), JSON.stringify(report, null, 2));
            await page.setViewportSize({ width: 390, height: 844 });
            for (const appearance of ['light', 'dark']) {
                await mode(page, appearance);
                report.push({ route: route + '/mobile', mode: appearance, ...await axe(page) });
                if (customizer) {
                    assert.ok(await page.locator('#ab-customizer-appearance select').isVisible(), 'Mobile Customizer has a visible appearance selector');
                    await page.locator('#ab-customizer-appearance select').selectOption(appearance);
                }
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Native screen reflows on mobile');
                await page.screenshot({ path: path.join(output, (customizer ? 'customizer-mobile-' : 'media-mobile-') + appearance + '.png') });
            }
            await page.setViewportSize({ width: 1440, height: 1000 });
        }
        assert.ok(report.every(r => !r.violations.length), 'Native surfaces pass accessibility rules');
        console.log(`WORDPRESS APPEARANCE PASS: ${report.length} states`);
    } finally { fs.writeFileSync(path.join(output, 'wordpress.json'), JSON.stringify(report, null, 2)); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
