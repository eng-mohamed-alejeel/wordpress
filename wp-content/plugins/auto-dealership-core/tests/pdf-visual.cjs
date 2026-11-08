'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(path.resolve(__dirname, '../../../../.tmp/accessibility-tools/node_modules/playwright-core'));

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.ADC_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
        const results = {};
        for (const item of [
            ['ar', 'adc-pdf-margin-check-'],
            ['en', 'adc-pdf-margin-check-'],
            ['report-ar', 'adc-pdf-report-check-'],
            ['report-en', 'adc-pdf-report-check-'],
        ]) {
            const language = item[0];
            const pdf = path.resolve(__dirname, '../../../../.tmp/' + item[1] + language.replace('report-', '') + '.pdf');
            const screenshot = path.resolve(__dirname, '../../../../.tmp/' + item[1] + language.replace('report-', '') + '.png');
            await page.goto('file:///' + pdf.replaceAll('\\', '/'), { waitUntil: 'load' });
            await page.waitForTimeout(1500);
            await page.screenshot({ path: screenshot, fullPage: true });
            results[language] = { pdf_exists: fs.existsSync(pdf), screenshot_exists: fs.existsSync(screenshot), url: page.url() };
        }
        console.log(JSON.stringify(results));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
