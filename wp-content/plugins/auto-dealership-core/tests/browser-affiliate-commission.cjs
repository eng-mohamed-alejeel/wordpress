'use strict';

// Authenticated browser contract for the affiliate report, server PDF response,
// reconciliation view and independent administrative-alert history screen.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { launch, output } = require('./accessibility-browser.cjs');

const origin = (process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
const checks = [];
const check = (value, label, details = null) => { checks.push({ pass: !!value, label, details }); console.log((value ? 'PASS ' : 'FAIL ') + label + (details ? ' ' + JSON.stringify(details) : '')); assert.ok(value, label); };

(async () => {
    const { browser, page } = await launch(true);
    try {
        await page.goto(origin + '/wp-admin/admin.php?page=adc-affiliate-reports', { waitUntil: 'networkidle' });
        check(page.url().includes('adc-affiliate-reports'), 'Affiliate report page is accessible to the authenticated finance user.');
        check(await page.locator('form input[value="adc_affiliate_commission_report_excel"]').count() === 1, 'Affiliate report exposes the Excel export action.');
        check(await page.locator('form input[value="adc_affiliate_commission_report_pdf"]').count() === 1, 'Affiliate report exposes the server PDF export action.');
        check((await page.locator('body').innerText()).includes('التسوية') || (await page.locator('body').innerText()).toLowerCase().includes('reconciliation'), 'Affiliate report exposes the reconciliation section.');

        const pdfForm = page.locator('form').filter({ has: page.locator('input[value="adc_affiliate_commission_report_pdf"]') });
        const downloadPromise = page.waitForEvent('download');
        await pdfForm.locator('button').click();
        const download = await downloadPromise;
        const pdfPath = await download.path();
        const pdfBytes = pdfPath ? fs.readFileSync(pdfPath) : Buffer.alloc(0);
        check(pdfBytes.subarray(0, 5).toString() === '%PDF-', 'Affiliate report download is a real PDF binary.');
        check(download.suggestedFilename().endsWith('.pdf'), 'Affiliate report PDF has a safe PDF filename.');

        await page.goto(origin + '/wp-admin/admin.php?page=adc-administrative-alerts', { waitUntil: 'networkidle' });
        check(page.url().includes('adc-administrative-alerts'), 'Administrative alert history page is accessible.');
        check(await page.locator('table.widefat').count() === 1, 'Administrative alert history renders a paginated table.');
        check(await page.locator('select[name="status"]').count() === 1 && await page.locator('input[name="s"]').count() === 1, 'Administrative alert history exposes status and search filters.');

        fs.writeFileSync(path.join(output, 'affiliate-commission-browser.json'), JSON.stringify({ checks }, null, 2));
        console.log('Completed ' + checks.length + ' affiliate commission browser checks.');
    } finally {
        fs.writeFileSync(path.join(output, 'affiliate-commission-browser.json'), JSON.stringify({ checks }, null, 2));
        await browser.close();
    }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
