'use strict';
// Test-only dependencies: see docs/auto-brands-accessibility-audit.md.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../../../..');
const dependency = name => require(path.join(root, '.tmp/accessibility-tools/node_modules', name));
const { chromium } = dependency('playwright-core');
const output = path.join(root, '.tmp/accessibility-acceptance');
fs.mkdirSync(output, { recursive: true });
async function launch(auth = false) {
    const browser = await chromium.launch({ executablePath: process.env.ADC_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    await context.route('**/*', route => {
        let url;
        try { url = new URL(route.request().url()); } catch { return route.abort(); }
        return ['localhost', '127.0.0.1'].includes(url.hostname) || ['data:', 'blob:'].includes(url.protocol) ? route.continue() : route.abort();
    });
    if (auth) {
        const session = JSON.parse(fs.readFileSync(path.join(root, '.tmp/dashboard-browser-session.json')));
        await context.addCookies(session.cookies.map(c => ({ ...c, domain: 'localhost', path: '/wordpress/', httpOnly: true, sameSite: 'Lax' })));
    }
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    return { browser, context, page };
}
async function mode(page, value) {
    await page.evaluate(value => window.autoBrandsAppearance.select(value), value);
    await page.waitForTimeout(800);
}
async function axe(page) {
    await page.addScriptTag({ path: path.join(root, '.tmp/accessibility-tools/node_modules/axe-core/axe.min.js') });
    return page.evaluate(async () => {
        const result = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] } });
        const brief = items => items.map(r => ({ id: r.id, impact: r.impact, help: r.help, nodes: r.nodes.map(n => ({ target: n.target, html: n.html, summary: n.failureSummary })) }));
        return { violations: brief(result.violations), incomplete: brief(result.incomplete), passes: result.passes.length };
    });
}
module.exports = { root, output, dependency, launch, mode, axe };
