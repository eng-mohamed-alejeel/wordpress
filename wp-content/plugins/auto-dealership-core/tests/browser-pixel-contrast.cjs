'use strict';
// Measure actual rendered backgrounds, including images, gradients and pseudo-elements.
const fs = require('node:fs');
const path = require('node:path');
const { output, dependency, launch, mode } = require('./accessibility-browser.cjs');
const { PNG } = dependency('pngjs');
const luminance = rgb => rgb.map(x => { x /= 255; return x <= .04045 ? x / 12.92 : ((x + .055) / 1.055) ** 2.4; }).reduce((a, v, i) => a + v * [.2126, .7152, .0722][i], 0);
const contrast = (a, b) => { const x = luminance(a), y = luminance(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
async function pixelAudit(page) {
    await page.evaluate(async () => { await document.fonts.ready; window.scrollTo(0, 0); });
    const samples = await page.evaluate(() => {
        const result = [], walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            const element = node.parentElement;
            if (!node.textContent.trim() || !element || element.closest('script,style,noscript,svg,[aria-hidden="true"],[inert],.screen-reader-text,.skip-link,.dashicons,[disabled]')) continue;
            const style = getComputedStyle(element);
            if (style.visibility !== 'visible' || /dashicons/i.test(style.fontFamily) || !element.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true, contentVisibilityAuto: true })) continue;
            let opacity = 1, complex = false, hidden = false;
            for (let p = element; p; p = p.parentElement) {
                const s = getComputedStyle(p);
                opacity *= Number(s.opacity);
                if (s.display === 'none' || s.clipPath === 'inset(50%)') hidden = true;
                if (s.backgroundImage !== 'none' || Number(s.opacity) < 1) complex = true;
                for (const pseudo of ['::before', '::after']) {
                    const ps = getComputedStyle(p, pseudo);
                    if (ps.content !== 'none' && (ps.backgroundImage !== 'none' || ps.backgroundColor !== 'rgba(0, 0, 0, 0)')) complex = true;
                }
            }
            if (hidden || opacity === 0) continue;
            const range = document.createRange(); range.selectNodeContents(node);
            const rects = [...range.getClientRects()].map(rect => {
                let left=rect.left, right=rect.right, top=rect.top, bottom=rect.bottom;
                for(let p=element;p;p=p.parentElement){
                    const s=getComputedStyle(p), r=p.getBoundingClientRect();
                    if(/hidden|clip|scroll|auto/.test(s.overflowX)){left=Math.max(left,r.left);right=Math.min(right,r.right);}
                    if(/hidden|clip|scroll|auto/.test(s.overflowY)){top=Math.max(top,r.top);bottom=Math.min(bottom,r.bottom);}
                }
                return {x:left+scrollX,y:top+scrollY,width:right-left,height:bottom-top};
            }).filter(r=>r.width>2&&r.height>2&&r.x+r.width>0&&r.x<innerWidth);
            if (!rects.length) continue;
            const rgb = style.color.match(/[\d.]+/g).map(Number);
            const size = parseFloat(style.fontSize), weight = parseFloat(style.fontWeight) || 400;
            result.push({ text: node.textContent.trim().slice(0, 100), selector: element.tagName.toLowerCase() + (element.id ? '#' + element.id : '.' + [...element.classList].join('.')), color: rgb.slice(0, 3), alpha: (rgb[3] ?? 1) * opacity, required: size >= 24 || (size >= 18.6667 && weight >= 700) ? 3 : 4.5, complex, rects });
        }
        return result;
    });
    // Floating controls are audited separately; they are not the underlying text background.
    const hide = await page.addStyleTag({ content: '* { -webkit-text-fill-color: transparent !important; text-shadow: none !important; caret-color: transparent !important; } .ab-float-wa, .ab-back-top { visibility: hidden !important; }' });
    let screenshot;
    try { screenshot = PNG.sync.read(await page.screenshot({ fullPage: true, animations: 'disabled' })); }
    finally { await hide.evaluate(el => el.remove()); }
    let pixels = 0;
    const measurements = samples.map(sample => {
        let minimum = Infinity, worst;
        for (const rect of sample.rects) {
            // Every pixel inside the text's layout rectangle, excluding the one-pixel edge.
            for (let y = Math.max(0, Math.ceil(rect.y) + 1); y < Math.min(screenshot.height, Math.floor(rect.y + rect.height) - 1); y++) {
                for (let x = Math.max(0, Math.ceil(rect.x) + 1); x < Math.min(screenshot.width, Math.floor(rect.x + rect.width) - 1); x++) {
                    const i = (y * screenshot.width + x) * 4;
                    const bg = Array.from(screenshot.data.subarray(i, i + 3));
                    const fg = sample.color.map((c, index) => c * sample.alpha + bg[index] * (1 - sample.alpha));
                    const ratio = contrast(fg, bg); pixels++;
                    if (ratio < minimum) { minimum = ratio; worst = { x, y, background: bg }; }
                }
            }
        }
        return { text: sample.text, selector: sample.selector, required: sample.required, complex: sample.complex, color: sample.color, alpha: sample.alpha, minimum, worst };
    }).filter(s => s.minimum !== Infinity);
    return { tested: measurements.length, complex: measurements.filter(s => s.complex).length, pixels, failures: measurements.filter(s => s.minimum < s.required), measurements };
}
module.exports = pixelAudit;
if (require.main === module) (async () => {
    const { browser, page } = await launch();
    let report = [];
    try {
        let routes = ['/', '/about/', '/contact/', '/finance/', '/cars/', '/offers/', '/faq/', '/buying-guide/'];
        if (process.env.ADC_PIXEL_ROUTES) routes = JSON.parse(process.env.ADC_PIXEL_ROUTES);
        if (process.env.ADC_PIXEL_MERGE === '1') report = JSON.parse(fs.readFileSync(path.join(output, 'pixels.json'))).filter(r => !routes.includes(r.route) && r.route !== 'home/white-image-stress-fixture');
        for (const route of routes) for (const language of ['ar', 'en']) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: 1000 });
                const url = new URL('http://localhost/wordpress' + route);
                if (language === 'en') url.searchParams.set('lang', 'en');
                await page.goto(url.href, { waitUntil: 'networkidle' });
                await page.waitForTimeout(1800);
                const consent = page.locator('#cd-cookie-accept-essential');
                if (await consent.isVisible()) { await consent.click(); await page.waitForTimeout(600); }
                for (const appearance of ['light', 'dark']) {
                    await mode(page, appearance);
                    const result = await pixelAudit(page);
                    report.push({ route, language, width, mode: appearance, ...result });
                    fs.writeFileSync(path.join(output, 'pixels.json'), JSON.stringify(report, null, 2));
                    console.log(`${route} ${language} ${width} ${appearance}: ${result.tested} text areas, ${result.complex} complex, ${result.failures.length} failures`);
                }
            }
        }
        // A white image is the worst luminance case beneath the navy overlay.
        // Check it explicitly, even when the current page uses its gradient fallback.
        const white = new PNG({ width: 2, height: 2 }); white.data.fill(255);
        const image = 'data:image/png;base64,' + PNG.sync.write(white).toString('base64');
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto('http://localhost/wordpress/', { waitUntil: 'networkidle' });
            await page.waitForTimeout(1800);
            const consent = page.locator('#cd-cookie-accept-essential');
            if (await consent.isVisible()) { await consent.click(); await page.waitForTimeout(600); }
            await page.evaluate(image => document.querySelector('.ab-hero').style.setProperty('--hero-image', `url("${image}")`), image);
            for (const appearance of ['light', 'dark']) {
                await mode(page, appearance);
                const result = await pixelAudit(page);
                report.push({ route: 'home/white-image-stress-fixture', language: 'ar', width, mode: appearance, ...result });
            }
        }
        fs.writeFileSync(path.join(output, 'pixels.json'), JSON.stringify(report, null, 2));
        console.log(`PIXEL CONTRAST: ${report.length} states; ${report.reduce((sum, item) => sum + item.tested, 0)} text areas; ${report.reduce((sum, item) => sum + item.failures.length, 0)} failures`);
        if (report.some(r => r.failures.length)) process.exitCode = 1;
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
