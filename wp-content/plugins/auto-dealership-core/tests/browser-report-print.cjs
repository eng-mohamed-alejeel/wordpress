'use strict';

const assert = require('node:assert/strict');
const { launch } = require('./accessibility-browser.cjs');

const origin = (process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
const pages = [
    'adc-area-reports',
    'adc-operational-reports',
    'adc-financial-export',
    'adc-affiliate-reports',
    'adc-crm-reports',
    'adc-lead-performance',
    'adc-marketing-reports',
];

(async () => {
    const { browser, page } = await launch(true);
    try {
        for (const slug of pages) {
            await page.goto(origin + '/wp-admin/admin.php?page=' + slug, { waitUntil: 'networkidle' });
            assert.equal(page.url().includes('page=' + slug), true, slug + ' report page is accessible');
            const printButton = page.locator('[data-adc-report-print]');
            assert.equal(await printButton.count(), 1, slug + ' exposes one print button');

            const popupPromise = page.waitForEvent('popup');
            await printButton.click();
            const popup = await popupPromise;
            await popup.waitForLoadState('domcontentloaded');
            await popup.waitForTimeout(350);

            const state = await popup.evaluate(() => {
                const report = document.querySelector('.report-sheet');
                return {
                    reportSheets: document.querySelectorAll('.report-sheet').length,
                    hasReport: !!report && report.innerText.trim().length > 0,
                    hasAdminChrome: !!document.querySelector('#adminmenumain, #wpadminbar'),
                    hasControls: !!document.querySelector('form,button,input,select,textarea,[data-adc-report-print]'),
                    tableCount: document.querySelectorAll('table').length,
                };
            });

            assert.equal(state.reportSheets, 1, slug + ' report tab contains one report sheet');
            assert.equal(state.hasReport, true, slug + ' print window contains report content');
            assert.equal(state.hasAdminChrome, false, slug + ' print window excludes WordPress chrome');
            assert.equal(state.hasControls, false, slug + ' report tab excludes admin controls');
            assert.ok(state.tableCount > 0, slug + ' print window contains report tables');
            assert.equal(page.url().includes('page=' + slug), true, slug + ' original report tab remains unchanged');
            console.log('PASS ' + slug + ' dedicated report tab');
            await popup.close();
        }
        console.log('Completed ' + pages.length + ' report print browser checks.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
