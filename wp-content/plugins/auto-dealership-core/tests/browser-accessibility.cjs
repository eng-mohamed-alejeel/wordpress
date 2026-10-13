'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { output, launch, mode, axe } = require('./accessibility-browser.cjs');
(async () => {
    const { browser, page } = await launch(process.env.ADC_A11Y_ADMIN === '1');
    let report = [];
    try {
        let routes = ['/', '/about/', '/contact/', '/finance/', '/cars/', '/offers/', '/faq/', '/buying-guide/', '/?cd_account=login', '/?cd_account=register', '/wp-login.php', '/missing-a11y-page/'];
        if (!process.env.ADC_A11Y_ROUTES) {
            await page.goto('http://localhost/wordpress/', { waitUntil: 'networkidle' });
            const discovered = await page.locator('.footer-legal-links a, .car-card a, .offer-card a').evaluateAll(links => links.map(a => a.href).filter(h => h.startsWith(location.origin + '/wordpress/')));
            const legal = discovered.filter(h => /policy|terms|privacy/.test(h));
            const car = discovered.find(h => h.includes('/cars/'));
            const offer = discovered.find(h => h.includes('/offers/'));
            for (const href of [...legal, car, offer].filter(Boolean)) routes.push(href.replace('http://localhost/wordpress', '').split('#')[0]);
            routes.push('/comparison/', '/cars/?search=missing-a11y-vehicle');
            routes = [...new Set(routes)];
        }
        if (process.env.ADC_A11Y_ROUTES) routes = JSON.parse(process.env.ADC_A11Y_ROUTES);
        if (process.env.ADC_A11Y_MERGE === '1') report = JSON.parse(fs.readFileSync(path.join(output, 'axe-public.json'))).filter(r => !routes.includes(r.route) && r.route !== '/?cd_compare=1');
        for (const route of routes) {
            for (const language of (route.includes('wp-admin') ? ['ar'] : ['ar', 'en'])) {
                const url = new URL('http://localhost/wordpress' + route);
                if (language === 'en') url.searchParams.set('lang', 'en');
                await page.goto(url.href, { waitUntil: 'networkidle' });
                for (const width of (route.includes('wp-admin') ? [1440] : [1440, 390])) {
                  await page.setViewportSize({ width, height: 1000 });
                  for (const appearance of ['light', 'dark']) {
                    await mode(page, appearance);
                    const result = await axe(page);
                    report.push({ route, language, width, mode: appearance, ...result });
                    fs.writeFileSync(path.join(output, process.env.ADC_A11Y_ADMIN === '1' ? 'axe-admin.json' : 'axe-public.json'), JSON.stringify(report, null, 2));
                    console.log(`${route} ${language} ${width} ${appearance}: ${result.violations.map(v => v.id + '(' + v.nodes.length + ')').join(', ') || 'PASS'}`);
                  }
                }
            }
        }
        if (report.some(r => r.violations.length)) process.exitCode = 1;
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
