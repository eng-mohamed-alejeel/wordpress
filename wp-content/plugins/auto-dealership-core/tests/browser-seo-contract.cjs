'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');

const origin = process.env.ADC_TEST_ORIGIN || 'http://localhost/wordpress';
assert.match(origin, /^https?:\/\/[^/]+\/wordpress$/);

(async () => {
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'adc-seo-browser-'));
    const browser = spawn(process.env.ADC_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
        '--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0',
        '--no-first-run', '--disable-background-networking', '--disable-sync',
        '--user-data-dir=' + profile, 'about:blank'
    ], { windowsHide: true, stdio: 'ignore' });
    let socket;
    let checks = 0;
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    const check = (condition, message) => {
        assert.ok(condition, message);
        checks++;
        console.log('SEO BROWSER PASS: ' + message);
    };

    try {
        const active = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !fs.existsSync(active); i++) await pause(100);
        assert.ok(fs.existsSync(active), 'Browser must start.');
        const port = Number(fs.readFileSync(active, 'utf8').split('\n')[0]);
        const targets = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
        const pageTarget = targets.find(target => target.type === 'page');
        assert.ok(pageTarget, 'Browser page target must be available.');
        socket = new WebSocket(pageTarget.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });

        let sequence = 0;
        const pending = new Map();
        const errors = [];
        const call = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
            pending.set(id, { resolve, reject, timer });
            socket.send(JSON.stringify({ id, method, params }));
        });
        socket.onmessage = event => {
            const data = JSON.parse(event.data);
            if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);
            const request = pending.get(data.id);
            if (!request) return;
            clearTimeout(request.timer);
            pending.delete(data.id);
            data.error ? request.reject(new Error(data.error.message)) : request.resolve(data.result);
        };
        await call('Page.enable');
        await call('Runtime.enable');
        const evaluate = async expression => {
            const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };
        const wait = async () => {
            for (let i = 0; i < 120; i++) {
                if (await evaluate('document.readyState === "complete" && !!document.body')) return;
                await pause(100);
            }
            throw new Error('Timed out waiting for document.');
        };
        const navigate = async route => {
            const separator = route.includes('?') ? '&' : '?';
            await call('Page.navigate', { url: origin + route + separator + 'adc_browser_seo=' + Date.now() });
            await wait();
        };
        const inspectExpression = '(function(){' +
            'var metas=function(s){return Array.from(document.querySelectorAll(s)).map(function(n){return n.content||"";});};' +
            'var schemas=Array.from(document.querySelectorAll("script[type=\\"application/ld+json\\"]")).map(function(n){try{return JSON.parse(n.textContent);}catch(e){return null;}}).filter(Boolean);' +
            'return {description:metas("meta[name=\\"description\\"]"),og:metas("meta[property^=\\"og:\\"]"),canonical:document.querySelector("link[rel=\\"canonical\\"]")?.href||"",alternates:Array.from(document.querySelectorAll("link[rel=\\"alternate\\"][hreflang]")).map(function(n){return n.hreflang;}),robots:document.querySelector("meta[name=\\"robots\\"]")?.content||"",schemas:schemas,contextLinks:Array.from(document.querySelectorAll(".cd-context-navigation a[href], .offer-vehicle-link a[href], .ab-offer-vehicle-link a[href]")).map(function(n){return n.href;}),breadcrumbVisible:Array.from(document.querySelectorAll(".cd-context-navigation li:not(.cd-context-navigation-separator)")).map(function(n){return n.textContent.trim();}),breadcrumbSchema:(schemas.find(function(n){return n["@type"] === "BreadcrumbList";})||{}).itemListElement||[],relatedOfferLinks:Array.from(document.querySelectorAll(".cd-related-content a[href]")).map(function(n){return n.href;}),overflow:document.documentElement.scrollWidth>window.innerWidth+1};' +
        '})()';
        const inspect = () => evaluate(inspectExpression);

        await navigate('/?seo_probe=20261029');
        let data = await inspect();
        check(data.description.length === 1 && data.og.length >= 4, 'Homepage has generated description and Open Graph metadata.');
        check(data.canonical === origin + '/', 'Homepage canonical is present and points to the public origin.');
        check(data.alternates.length === 3 && data.alternates.includes('ar') && data.alternates.includes('en') && data.alternates.includes('x-default'), 'Homepage publishes Arabic, English and x-default alternates.');

        await navigate('/cars/?seo_probe=20261029');
        data = await inspect();
        check(data.description.length === 1 && data.canonical === origin + '/cars/' && !data.robots.includes('noindex'), 'Vehicle archive is indexable with a clean canonical URL.');
        check(!data.contextLinks.includes(origin + '/finance/') && !data.contextLinks.includes(origin + '/contact/') && data.breadcrumbVisible.length === 2 && data.breadcrumbSchema.length === 2, 'Vehicle archive uses a compact two-level breadcrumb.');

        await navigate('/offers/?seo_probe=20261029');
        data = await inspect();
        check(data.description.length === 1 && data.canonical === origin + '/offers/' && !data.robots.includes('noindex'), 'Offer archive is indexable with a clean canonical URL.');
        check(!data.contextLinks.includes(origin + '/finance/') && !data.contextLinks.includes(origin + '/contact/') && data.breadcrumbVisible.length === 2 && data.breadcrumbSchema.length === 2, 'Offer archive uses a compact two-level breadcrumb.');

        await navigate('/cars/?search=seo-no-match&seo_probe=20261029');
        data = await inspect();
        check(data.robots.includes('noindex') && data.robots.includes('follow') && data.canonical === origin + '/cars/', 'Filtered vehicle archive is noindex/follow and canonicalizes to the archive.');

        await navigate('/cars/car-89/?seo_probe=20261029');
        data = await inspect();
        const vehicle = data.schemas.find(node => node['@type'] === 'Vehicle');
        check(data.description.length === 1 && data.canonical === origin + '/cars/car-89/' && vehicle && vehicle.url === origin + '/cars/car-89/', 'Vehicle page metadata and Vehicle JSON-LD use the clean route.');
        check(data.schemas.some(node => node['@type'] === 'BreadcrumbList'), 'Vehicle page includes breadcrumb structured data.');
        check(data.contextLinks.includes(origin + '/cars/') && !data.contextLinks.includes(origin + '/finance/') && !data.contextLinks.includes(origin + '/contact/') && data.breadcrumbVisible.length === 3 && data.breadcrumbSchema.length === 3, 'Vehicle breadcrumb stays focused on the vehicle hierarchy.');
        check(data.contextLinks.every(url => !/%(?:d8|d9)/i.test(url)), 'Vehicle contextual links do not contain encoded Arabic slugs.');

        await navigate('/cars/car-43/?seo_probe=20261029');
        data = await inspect();
        check(data.relatedOfferLinks.includes(origin + '/offers/offer-111/'), 'Vehicle page exposes its active related offer with a clean URL.');

        await navigate('/offers/offer-111/?seo_probe=20261029');
        data = await inspect();
        const offer = data.schemas.find(node => node['@type'] === 'Vehicle');
        check(data.description.length === 1 && data.canonical === origin + '/offers/offer-111/' && offer && offer.url === origin + '/offers/offer-111/', 'Offer page metadata and structured data use the clean route.');
        check(data.contextLinks.includes(origin + '/offers/') && data.contextLinks.some(url => url.indexOf('/cars/car-') !== -1 && url.endsWith('/')) && data.breadcrumbVisible.length === 3 && data.breadcrumbSchema.length === 3, 'Offer page uses a focused breadcrumb and links back to its related vehicle.');
        check(data.contextLinks.every(url => !/%(?:d8|d9)/i.test(url)), 'Offer contextual links do not contain encoded Arabic slugs.');

        await navigate('/?cd_account=login&seo_probe=20261029');
        data = await inspect();
        check(data.robots.includes('noindex') && data.description.length === 0 && data.og.length === 0, 'Account views are excluded from index metadata and social previews.');

        for (const width of [1440, 390]) {
            await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width <= 390 });
            await pause(150);
            check(!(await inspect()).overflow, 'SEO-enabled public page fits viewport ' + width + 'px.');
        }
        check(errors.length === 0, 'No uncaught browser errors during SEO checks: ' + errors.join(';'));
        console.log('Completed ' + checks + ' SEO browser checks.');
        await call('Browser.close').catch(() => {});
    } finally {
        if (socket) socket.close();
        if (browser.exitCode === null) browser.kill();
        await pause(500);
        const resolved = path.resolve(profile);
        if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('adc-seo-browser-')) {
            try { fs.rmSync(resolved, { recursive: true, force: true }); } catch {}
        }
    }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
