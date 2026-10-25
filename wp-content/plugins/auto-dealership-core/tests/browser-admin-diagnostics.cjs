'use strict';

// Read-only acceptance for AD-046. It verifies the health page contract without
// enabling diagnostics or changing any source-site option.
const fs = require('node:fs');
const path = require('node:path');
const { root, output, launch, mode } = require('./accessibility-browser.cjs');

const origin = (process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress').replace(/\/$/, '');
const checks = [];
const check = (pass, label, details = null) => {
    const item = { pass: !!pass, label, details };
    checks.push(item);
    console.log((item.pass ? 'PASS ' : 'FAIL ') + label + (details ? ' ' + JSON.stringify(details) : ''));
};

(async () => {
    const { browser, page } = await launch(true);
    try {
        for (const language of ['default', 'lang-param']) {
            for (const appearance of ['light', 'dark']) {
                for (const width of [390, 1440]) {
                    await page.setViewportSize({ width, height: 1000 });
                    const url = new URL(origin + '/wp-admin/admin.php?page=adc-diagnostics');
                    if (language === 'lang-param') url.searchParams.set('lang', 'en');
                    const response = await page.goto(url.href, { waitUntil: 'networkidle' });
                    await mode(page, appearance);
                    const state = await page.evaluate(() => {
                        const text = document.body.innerText;
                        const ids = [...document.querySelectorAll('code')].map(node => node.textContent.trim()).filter(value => /^[0-9a-f]{8}-[0-9a-f-]{27,28}$/i.test(value));
                        const cells = [...document.querySelectorAll('table tbody tr td')].map(cell => cell.textContent.trim());
                        return {
                            heading: document.querySelector('.wrap > h1')?.textContent.trim() || '',
                            htmlLang: document.documentElement.lang || '',
                            direction: getComputedStyle(document.documentElement).direction,
                            overflow: document.documentElement.scrollWidth > innerWidth + 2,
                            diagnosticsForm: !!document.querySelector('form[action*="admin-post.php"] input[name="action"][value="adc_save_diagnostics"]'),
                            nonce: !!document.querySelector('form[action*="admin-post.php"] input[name="_wpnonce"]'),
                            requestIds: ids,
                            cells,
                            rawLeak: /\b(?:SELECT|INSERT|UPDATE|DELETE)\s+(?:FROM|INTO|UPDATE|SET|WHERE|TABLE)\b|last_error|password_hash|wp-content\/uploads/i.test(text),
                        };
                    });
                    check(response?.status() === 200, language + '/' + appearance + '/' + width + ' diagnostics page responds successfully');
                    check(state.heading.length > 0, language + '/' + appearance + '/' + width + ' diagnostics page has a heading');
                    check(state.direction === (state.htmlLang.startsWith('en') ? 'ltr' : 'rtl'), language + '/' + appearance + '/' + width + ' direction matches the active admin locale', { lang: state.htmlLang, direction: state.direction });
                    check(!state.overflow, language + '/' + appearance + '/' + width + ' page has no horizontal overflow');
                    check(state.diagnosticsForm && state.nonce, language + '/' + appearance + '/' + width + ' settings form is nonce protected');
                    check(state.requestIds.every(value => /^[0-9a-f]{8}-[0-9a-f-]{27,28}$/i.test(value)), language + '/' + appearance + '/' + width + ' request IDs are UUID-shaped', state.requestIds);
                    check(!state.rawLeak, language + '/' + appearance + '/' + width + ' diagnostics view exposes no raw SQL or secrets');
                }
            }
        }
        fs.writeFileSync(path.join(output, 'admin-diagnostics.json'), JSON.stringify({ checks }, null, 2));
        if (checks.some(item => !item.pass)) process.exitCode = 1;
    } finally {
        fs.writeFileSync(path.join(output, 'admin-diagnostics.json'), JSON.stringify({ checks }, null, 2));
        await browser.close();
    }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
