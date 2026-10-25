'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { root, output, launch, mode } = require('./accessibility-browser.cjs');

const routes = [
    '/wp-admin/admin.php?page=adc-workspace',
    '/wp-admin/admin.php?page=adc-area-inventory',
    '/wp-admin/admin.php?page=adc-area-reports',
    '/wp-admin/admin.php?page=adc-diagnostics',
    '/wp-admin/admin.php?page=adc-settings',
    '/wp-admin/admin.php?page=adc-onyx',
];
const checks = [];
const scans = [];
const check = (pass, label, details = null) => {
    const item = { pass: !!pass, label, details };
    checks.push(item);
    console.log(`${item.pass ? 'PASS' : 'FAIL'} ${label}${details ? ` ${JSON.stringify(details)}` : ''}`);
};

const axeContent = async page => {
    await page.addScriptTag({ path: path.join(root, '.tmp/accessibility-tools/node_modules/axe-core/axe.min.js') });
    return page.evaluate(async () => {
        const result = await window.axe.run(document.querySelector('.adc-admin .wrap'), { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] } });
        return { violations: result.violations.map(item => ({ id: item.id, impact: item.impact, nodes: item.nodes.map(node => ({ target: node.target, html: node.html, summary: node.failureSummary })) })) };
    });
};

const audit = page => page.evaluate(() => {
    const visible = element => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0 && !element.closest('[hidden], [aria-hidden="true"]');
    };
    const name = element => {
        const labelledBy = element.getAttribute('aria-labelledby');
        const labelled = labelledBy ? labelledBy.split(/\s+/).map(id => document.getElementById(id)?.textContent || '').join(' ') : '';
        return (element.getAttribute('aria-label') || labelled || element.labels?.[0]?.textContent || element.textContent || element.title || '').replace(/\s+/g, ' ').trim();
    };
    const initialOverflow = document.documentElement.scrollWidth > innerWidth + 2;
    const controls = [...document.querySelectorAll('.adc-admin .wrap a[href], .adc-admin .wrap button, .adc-admin .wrap input:not([type="hidden"]), .adc-admin .wrap select, .adc-admin .wrap textarea, .adc-admin .wrap summary, .adc-admin .wrap [role="button"]')].filter(control => visible(control) && !control.disabled);
    const results = controls.slice(0, 100).map(control => {
        control.focus();
        const style = getComputedStyle(control);
        return {
            tag: control.tagName.toLowerCase(),
            name: name(control) || (control instanceof HTMLInputElement ? control.value : ''),
            focus: parseFloat(style.outlineWidth) >= 2 && style.outlineStyle !== 'none',
        };
    });
    return {
        controls: results,
        overflow: initialOverflow,
        transitions: [...document.querySelectorAll('.adc-admin-shell *')].map(element => {
            const style = getComputedStyle(element);
            return { transition: style.transitionDuration, animation: style.animationDuration };
        }).filter(item => item.transition !== '0s' || item.animation !== '0s'),
    };
});

(async () => {
    const { browser, page } = await launch(true);
    try {
        for (const language of ['ar', 'en']) {
            for (const route of routes) {
                const url = new URL(`http://localhost/wordpress${route}`);
                if (language === 'en') url.searchParams.set('lang', 'en');
                await page.goto(url.href, { waitUntil: 'networkidle' });
                for (const appearance of ['light', 'dark']) {
                    await mode(page, appearance);
                    for (const width of [320, 390, 1440]) {
                        await page.setViewportSize({ width, height: 1000 });
                        await page.waitForTimeout(150);
                        const result = await audit(page);
                        check(!result.overflow, `${language}/${appearance} ${route} fits at ${width}px`);
                        check(result.controls.every(control => control.name), `${language}/${appearance} ${route} interactive controls have names`, result.controls.filter(control => !control.name));
                        check(result.controls.every(control => control.focus), `${language}/${appearance} ${route} sampled controls expose visible focus`, result.controls.filter(control => !control.focus));
                        check(result.transitions.every(item => parseFloat(item.transition) <= 0.02 && parseFloat(item.animation) <= 0.02), `${language}/${appearance} ${route} honors reduced motion`, result.transitions.filter(item => parseFloat(item.transition) > 0.02 || parseFloat(item.animation) > 0.02).slice(0, 5));
                    }
                    scans.push({ route, language, mode: appearance, ...(await axeContent(page)) });
                }
            }
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('http://localhost/wordpress/wp-admin/admin.php?page=adc-workspace', { waitUntil: 'networkidle' });
        const fixture = await page.locator('.adc-admin .wrap').evaluate(wrap => {
            const form = document.createElement('form');
            form.style.cssText = 'position:fixed;inset-inline-start:-10px;top:0;width:1px;height:1px;opacity:0;pointer-events:none';
            form.innerHTML = '<label>Accessibility fixture<input required name="adc_a11y_fixture_email" type="email"></label>';
            wrap.append(form);
            return form.querySelector('input').name;
        });
        const invalid = page.locator(`[name="${fixture}"]`);
        await invalid.evaluate(control => control.reportValidity());
        check(await invalid.getAttribute('aria-invalid') === 'true', 'admin invalid control exposes aria-invalid');
        const describedBy = await invalid.getAttribute('aria-describedby');
        check(!!describedBy && await page.locator(`#${describedBy.split(/\s+/).at(-1)}`).count() === 1, 'admin invalid control has an associated message');
        await invalid.fill('valid@example.test');
        check(await invalid.getAttribute('aria-invalid') === null && await invalid.getAttribute('aria-describedby') === null, 'admin invalid state clears after correction');

        await page.goto('http://localhost/wordpress/wp-admin/admin.php?page=adc-settings&updated=1', { waitUntil: 'networkidle' });
        const notice = page.locator('.adc-admin .notice-success').first();
        check(await notice.count() === 1, 'admin save result renders a success notice');
        check(await notice.getAttribute('role') === 'status' && await notice.getAttribute('aria-live') === 'polite', 'admin save result is announced as a polite status');
        await page.waitForTimeout(250);
        check(await notice.evaluate(element => element === document.activeElement), 'admin save result receives focus after redirect');

        check(scans.every(scan => !scan.violations.length), 'admin accessibility contract routes pass axe', scans.flatMap(scan => scan.violations));
        fs.writeFileSync(path.join(output, 'admin-accessibility-contract.json'), JSON.stringify({ checks, scans }, null, 2));
        if (checks.some(item => !item.pass)) process.exitCode = 1;
    } finally {
        fs.writeFileSync(path.join(output, 'admin-accessibility-contract.json'), JSON.stringify({ checks, scans }, null, 2));
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
