'use strict';
// Read-only public-site acceptance against the local development origin.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

(async () => {
    const origin = process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress';
    assert.match(origin, /^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?\/wordpress\/?$/);
    const root = origin.replace(/\/$/, '');
    const profileRoot = path.resolve(__dirname, '../../../../.tmp/browser-profiles');
    fs.mkdirSync(profileRoot, { recursive: true });
    const profile = fs.mkdtempSync(path.join(profileRoot, 'adc-bilingual-'));
    const browser = spawn(process.env.ADC_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
        '--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0',
        '--no-first-run', '--disable-background-networking', '--disable-sync',
        `--user-data-dir=${profile}`, 'about:blank'
    ], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let browserLog = '';
    browser.stderr.on('data', chunk => { browserLog = (browserLog + chunk.toString()).slice(-2000); });
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    let socket;
    let passed = 0;
    const errors = [];
    const badAssets = [];
    const captures = path.resolve(__dirname, '../../../../.tmp/bilingual-acceptance');
    fs.mkdirSync(captures, { recursive: true });
    const check = (value, description) => {
        assert.ok(value, description);
        passed++;
        process.stdout.write(`PASS ${description}\n`);
    };
    try {
        const activePort = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !fs.existsSync(activePort); i++) await pause(100);
        assert.ok(fs.existsSync(activePort), `Browser must start: ${browserLog}`);
        const port = Number(fs.readFileSync(activePort, 'utf8').split('\n')[0]);
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        const target = targets.find(item => item.type === 'page');
        assert.ok(target, 'Browser page target must exist');
        socket = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
        let sequence = 0;
        const pending = new Map();
        const call = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
            pending.set(id, { resolve, reject, timer });
            socket.send(JSON.stringify({ id, method, params }));
        });
        socket.onmessage = event => {
            const data = JSON.parse(event.data);
            if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);
            if (data.method === 'Network.responseReceived' && data.params.response.status >= 400 && ['Stylesheet', 'Script'].includes(data.params.type)) badAssets.push(data.params.response.url);
            if (data.method === 'Fetch.requestPaused') {
                const request = data.params;
                const local = request.request.url.startsWith(root + '/') || request.request.url.startsWith('data:');
                call(local ? 'Fetch.continueRequest' : 'Fetch.failRequest', local ? { requestId: request.requestId } : { requestId: request.requestId, errorReason: 'BlockedByClient' }).catch(() => {});
            }
            const waiting = pending.get(data.id);
            if (!waiting) return;
            clearTimeout(waiting.timer);
            pending.delete(data.id);
            data.error ? waiting.reject(new Error(data.error.message)) : waiting.resolve(data.result);
        };
        await call('Page.enable');
        await call('Runtime.enable');
        await call('Network.enable');
        await call('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
        const evaluate = async expression => {
            const response = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (response.exceptionDetails) throw new Error(JSON.stringify(response.exceptionDetails));
            return response.result.value;
        };
        const wait = async (expression, description) => {
            for (let i = 0; i < 120; i++) {
                try { if (await evaluate(expression)) return; } catch {}
                await pause(100);
            }
            throw new Error(`Timed out: ${description}; URL: ${await evaluate('location.href')}`);
        };
        const visit = async route => {
            await call('Page.navigate', { url: root + route });
            await wait("document.readyState === 'complete' && !!document.querySelector('#colophon')", route);
        };
        const state = () => evaluate(`(() => {
            const switches = [...document.querySelectorAll('.site-language-switch')];
            const wa = document.querySelector('.ab-float-wa');
            return {
                language: document.documentElement.lang, direction: document.documentElement.dir,
                switches: switches.length,
                active: switches.map(x => x.querySelector('[aria-current="page"]')?.getAttribute('lang')),
                links: switches[0] ? [...switches[0].querySelectorAll('a')].map(x => ({lang:x.lang,url:x.href})) : [],
                whatsapp: wa?.href || '', waPosition: wa ? getComputedStyle(wa).position : '',
                footer: !!document.querySelector('#colophon .footer-grid'),
                legalLinks: [...document.querySelectorAll('#colophon .footer-legal-links a')].map(x => x.href),
                overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                body: document.body.innerText
            };
        })()`);
        await visit('/');
        let page = await state();
        check(page.language === 'ar' && page.direction === 'rtl', 'Arabic home declares RTL');
        check(page.switches === 2 && page.active.every(x => x === 'ar'), 'Header and footer show Arabic as active');
        check(page.whatsapp.includes('wa.me/966550928190') && page.waPosition === 'fixed', 'Configured WhatsApp button is fixed and visible');
        check(page.footer && page.legalLinks.length === 0, 'Footer renders and omits unpublished legal drafts');
        check(await evaluate("!document.querySelector('#colophon .footer-grid .footer-legal-links')"), 'Footer omits the empty legal column');
        check(await evaluate("document.querySelectorAll('.car-image-placeholder svg').length > 0 && !document.querySelector('.car-image-placeholder')?.textContent.includes('🚘')"), 'Vehicles without photos use a vector placeholder');
        await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        await visit('/?lang=en');
        page = await state();
        check(page.language === 'en' && page.direction === 'ltr', 'English home declares LTR');
        check(page.active.every(x => x === 'en') && page.body.includes('Your new vehicle starts with Auto Brands'), 'English switch and home copy render');
        check(await evaluate("(() => {const f=document.querySelector('#colophon .cd-newsletter-form');return !!f && getComputedStyle(f).backgroundColor === 'rgba(0, 0, 0, 0)' && getComputedStyle(f.querySelector('label')).color === 'rgb(255, 255, 255)' && getComputedStyle(f.querySelector('.cd-newsletter-consent')).flexDirection === 'row';})()"), 'Footer newsletter has readable text and aligned consent');
        check(page.links.find(x => x.lang === 'ar')?.url.endsWith('/wordpress/'), 'Arabic switch returns to the home view');
        fs.writeFileSync(path.join(captures, 'home-en-desktop.png'), Buffer.from((await call('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
        await evaluate("document.documentElement.style.scrollBehavior='auto';window.scrollTo(0,document.documentElement.scrollHeight)");
        await pause(100);
        fs.writeFileSync(path.join(captures, 'footer-en-desktop.png'), Buffer.from((await call('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
        check(await evaluate("[...document.querySelectorAll('#site-navigation a')].filter(a => a.href.startsWith(location.origin + '/wordpress/')).every(a => new URL(a.href).searchParams.get('lang') === 'en')"), 'Primary internal navigation keeps English');
        check(await evaluate("document.querySelector('.header-search input[name=lang]')?.value === 'en' && new URL(document.querySelector('.header-search').action).pathname.endsWith('/cars/') && !!document.querySelector('.header-search input[name=search]')"), 'Vehicle search form targets the catalog and preserves English');
        await evaluate("(() => {const f=document.querySelector('.header-search');f.elements.search.value='Synthetic';f.requestSubmit();})()");
        await wait("document.readyState === 'complete' && location.pathname.endsWith('/cars/') && new URL(location.href).searchParams.get('search') === 'Synthetic' && new URL(location.href).searchParams.get('lang') === 'en'", 'English vehicle search');
        check(true, 'Submitting vehicle search keeps the query and English');
        for (const [route, expected] of [
            ['/about/?lang=en', 'About us'], ['/contact/?lang=en', 'Contact us'],
            ['/faq/?lang=en', 'Frequently asked questions'], ['/buying-guide/?lang=en', 'Your vehicle buying guide'],
            ['/finance/?lang=en', 'Finance Calculator'], ['/cars/?lang=en', 'Browse AUTO BRANDS Vehicles'],
            ['/offers/?lang=en', 'AUTO BRANDS Offers'], ['/?cd_account=login&lang=en', 'Welcome back']
        ]) {
            await visit(route);
            page = await state();
            check(page.language === 'en' && page.direction === 'ltr' && page.body.includes(expected), `${route} renders English`);
            const arabicLink = page.links.find(x => x.lang === 'ar')?.url;
            check(arabicLink && new URL(arabicLink).pathname === new URL(root + route).pathname && !arabicLink.includes('lang=en'), `${route} keeps its path in the Arabic switch`);
            check(!page.body.includes('Warning:') && !page.body.includes('Fatal error'), `${route} has no visible PHP warning`);
        }
        await visit('/?cd_account=register&lang=en');
        check((await state()).body.includes('Create account') && await evaluate("document.querySelector('.cd-auth-form form')?.action.includes('lang=en')"), 'English registration form preserves language');
        for (const [route, expected] of [['/about/', 'من نحن'], ['/contact/', 'تواصل معنا'], ['/finance/', 'حاسبة التمويل'], ['/faq/', 'الأسئلة الشائعة'], ['/buying-guide/', 'دليلك لاختيار السيارة']]) {
            await visit(route);
            page = await state();
            check(page.language === 'ar' && page.direction === 'rtl' && page.body.includes(expected), `${route} renders Arabic`);
        }
        await visit('/finance/');
        check(await evaluate("document.querySelector('.entry-content').innerText.includes('النتيجة إرشادية وغير ملزمة') && !document.querySelector('.entry-content').innerText.includes('Explore how the down payment')"), 'Finance page shows only Arabic editorial content');
        await visit('/contact/?lang=en');
        check(await evaluate("document.querySelector('.cd-contact-form [name=lang]')?.value === 'en' && document.querySelector('.cd-contact-form button')?.textContent.trim() === 'Send'"), 'English contact form preserves language without submission');
        await visit('/faq/?lang=en');
        check(await evaluate("document.querySelector('.entry-content').innerText.includes('SAR 20,000') && document.querySelector('.entry-content').innerText.includes('within three days of reservation cancellation') && document.querySelector('.entry-content').innerText.includes('same payment method')"), 'English FAQ presents the owner-confirmed deposit and refund policy');
        await visit('/about/');
        check(await evaluate("document.querySelector('.entry-content').innerText.includes('تحت الإنشاء') && document.querySelector('.entry-content').innerText.includes('20,000') && document.querySelector('.entry-content').innerText.includes('ثلاثة أيام من إلغاء الحجز')"), 'Arabic about copy includes the construction status and refund policy');
        await visit('/contact/?lang=en');
        check(await evaluate("document.querySelector('.entry-content').innerText.includes('autobrands2020@gmail.com') && document.querySelector('.entry-content').innerText.includes('+966 55 092 8190') && document.querySelector('.entry-content').innerText.includes('Jeddah')"), 'English contact displays the supplied official contact details');
        await visit('/cars/');
        check(await evaluate("[...document.querySelectorAll('.car-card')].some(x=>x.innerText.includes('مثال تجريبي') && /0\\s*كم/.test(x.innerText))"), 'New synthetic vehicle cards explicitly show zero mileage and demo labels');
        check(await evaluate("document.querySelector('.site-footer a[href*=\"/faq/\"]') && document.querySelector('.site-footer a[href*=\"/buying-guide/\"]')"), 'Footer exposes FAQ and buying-guide navigation');
        await visit('/finance/?lang=en');
        check(await evaluate("document.querySelector('.entry-content').innerText.includes('Explore how the down payment') && !document.querySelector('.entry-content').innerText.includes('النتيجة إرشادية وغير ملزمة')"), 'Finance page shows only English editorial content');
        for (const width of [1440, 768, 390, 320]) {
            await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width <= 390 });
            await pause(150);
            page = await state();
            check(page.overflow <= 2, `English layout has no page overflow at ${width}px`);
            check(await evaluate("(() => {const a=document.querySelector('.site-header .site-language-switch');const r=a.getBoundingClientRect();return r.width>0&&r.left>=0&&r.right<=innerWidth;})()"), `Language control remains in viewport at ${width}px`);
            if (width === 390) {
                await visit('/contact/?lang=en');
                fs.writeFileSync(path.join(captures, 'contact-en-mobile.png'), Buffer.from((await call('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
                await evaluate("document.documentElement.style.scrollBehavior='auto';window.scrollTo(0,document.documentElement.scrollHeight)");
                await pause(100);
                check(await evaluate("(() => {const a=document.querySelector('.ab-float-wa').getBoundingClientRect();const c=document.querySelector('.footer-copyright').getBoundingClientRect();return a.bottom<=innerHeight && (a.left>=c.right || a.top>=c.bottom || a.right<=c.left);})()"), 'Floating WhatsApp does not cover mobile footer copyright');
                fs.writeFileSync(path.join(captures, 'footer-en-mobile.png'), Buffer.from((await call('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
                await visit('/finance/?lang=en');
            }
        }
        await visit('/contact/');
        for (const width of [390, 320]) {
            await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: true });
            await pause(120);
            page = await state();
            check(page.language === 'ar' && page.direction === 'rtl' && page.overflow <= 2, `Arabic contact fits RTL viewport ${width}px`);
            check(await evaluate("(() => {const a=document.querySelector('.site-header .site-language-switch').getBoundingClientRect();return a.width>0&&a.left>=0&&a.right<=innerWidth;})()"), `Arabic language control remains visible at ${width}px`);
            if (width === 390) {
                await evaluate("document.documentElement.style.scrollBehavior='auto';window.scrollTo(0,document.documentElement.scrollHeight)");
                await pause(100);
                fs.writeFileSync(path.join(captures, 'footer-ar-mobile.png'), Buffer.from((await call('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
            }
        }
        check(errors.length === 0, `No browser runtime exceptions: ${errors.join('; ')}`);
        check(badAssets.length === 0, `No missing local scripts or styles: ${badAssets.join('; ')}`);
        process.stdout.write(`BILINGUAL SITE PASS: ${passed} checks; screenshots: ${captures}\n`);
    } finally {
        if (socket) socket.close();
        browser.kill();
        await pause(500);
        if (path.dirname(path.resolve(profile)) === profileRoot && path.basename(profile).startsWith('adc-bilingual-')) {
            try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
        }
    }
})().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
