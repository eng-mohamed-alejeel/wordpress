'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { output, launch, mode } = require('./accessibility-browser.cjs');
const pixels = require('./browser-pixel-contrast.cjs');
(async () => {
    const { browser, page } = await launch(true);
    const report = [];
    try {
        for (const route of ['/wp-admin/', '/wp-admin/admin.php?page=adc-workspace', '/wp-admin/admin.php?page=adc-area-inventory', '/wp-admin/admin.php?page=adc-catalog-cutover']) {
            await page.goto('http://localhost/wordpress' + route, { waitUntil: 'networkidle' });
            for (const appearance of ['light', 'dark']) {
                await mode(page, appearance);
                const result = await pixels(page);
                report.push({ route, mode: appearance, ...result });
                fs.writeFileSync(path.join(output, 'pixels-admin.json'), JSON.stringify(report, null, 2));
                console.log(`${route} ${appearance}: ${result.tested} areas; ${result.failures.length} failures`);
            }
        }
        if (report.some(r => r.failures.length)) process.exitCode = 1;
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
