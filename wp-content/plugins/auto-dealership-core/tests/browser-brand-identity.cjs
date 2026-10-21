'use strict';
// Read-only public-site acceptance against the local development origin.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const auditTextContrast = require('./browser-color-audit.cjs');

(async () => {
    const origin = process.env.ADC_SITE_ORIGIN || 'http://localhost/wordpress';
    assert.match(origin, /^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?\/wordpress\/?$/);
    const root = origin.replace(/\/$/, '');
    const profileRoot = path.resolve(__dirname, '../../../../.tmp/browser-profiles');
    fs.mkdirSync(profileRoot, { recursive: true });
    const profile = fs.mkdtempSync(path.join(profileRoot, 'adc-brand-'));
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
    const captures = path.resolve(__dirname, '../../../../.tmp/brand-acceptance');
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
            const navigationToken = String(Date.now()) + Math.random();
            await evaluate('window.__brandNavigationToken=' + JSON.stringify(navigationToken));
            await call('Page.navigate', { url: root + route });
            await wait("window.__brandNavigationToken !== " + JSON.stringify(navigationToken) + " && document.readyState === 'complete' && !!document.querySelector('#autobrands-appearance')", route);
        };

        await call('Network.setCacheDisabled', {cacheDisabled:true});
        await call('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await visit('/');
        check(await evaluate("document.querySelectorAll('.site-header [data-ab-toggle]').length===1"), 'Header exposes one compact appearance toggle');
        check(await evaluate("!!document.querySelector('.site-header [data-ab-toggle]').getAttribute('aria-label')"), 'Appearance toggle has an accessible name');
        await evaluate("window.autoBrandsAppearance.select('light');document.querySelector('.site-header [data-ab-toggle]').click()");
        check(await evaluate("document.documentElement.dataset.abPreference==='dark'"), 'Pointer selection updates the active appearance');
        await evaluate("const option=document.querySelector('.site-header [data-ab-toggle]');option.focus();option.click()");
        check(await evaluate("document.documentElement.dataset.abPreference==='light'&&document.activeElement.matches('[data-ab-toggle]')"), 'Toggling preserves keyboard focus');
        await evaluate("window.autoBrandsAppearance.select('system')");
        const routes = (await evaluate("[...new Set(['/', '/contact/', '/about/', '/finance/', '/?cd_account=login', '/?cd_account=register', '/?post_type=car', '/?post_type=car_offer', ...[...document.querySelectorAll('#primary-menu a[href], .footer-legal-links a[href]')].map(a=>a.href).filter(h=>h.startsWith(location.origin+'/wordpress/')&&!h.includes('logout'))])]")).map(route => route.startsWith(root) ? route.slice(root.length) : route).filter((route,index,all)=>all.indexOf(route)===index);
        await visit('/?post_type=car');
        const detail = await evaluate("document.querySelector('.car-card h3 a, .car-card a[href]')?.href");
        if(detail) routes.push(detail);
        const report = [];
        const audit = async () => { const result=await evaluate('('+auditTextContrast.toString()+')()'); return {url:await evaluate('location.href'),mode:await evaluate('document.documentElement.dataset.abTheme'),...result}; };
        const contrasts=[];
        const state = () => evaluate('(' + (() => {
            const root = document.documentElement;
            const css = getComputedStyle(document.body);
            const sample = selector => { const e=document.querySelector(selector); if(!e)return null;const s=getComputedStyle(e);return {color:s.color,bg:s.backgroundColor,border:s.borderColor}; };
            return {url:location.href,mode:root.dataset.abTheme,overflow:root.scrollWidth-root.clientWidth,bg:css.backgroundColor,text:css.color,heading:sample('main h2'),button:sample('.btn:not(.btn-outline):not(.btn-chrome):not(.btn-ghost)'),header:sample('.site-header'),field:sample('main input:not([type=hidden]):not([type=checkbox])'),errors:[...document.querySelectorAll('.wp-die-message')].map(e=>e.textContent)};
        }).toString() + ')()');
        for (const mode of ['light','dark']) {
            await evaluate('window.autoBrandsAppearance.select(' + JSON.stringify(mode) + ')');
            for (const route of routes) {
                await visit(route.startsWith(root) ? route.slice(root.length) : route);
                const current = await state(); report.push(current);
                contrasts.push(await audit());
                check(current.mode === mode, mode + ' survives navigation: ' + route);
                check(current.overflow <= 2, mode + ' desktop has no horizontal overflow: ' + route);
            }
            await visit('/');
            const shot = await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            fs.writeFileSync(path.join(captures,'home-'+mode+'.png'),Buffer.from(shot.data,'base64'));
            await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
            await visit('/?lang=en');
            check((await state()).overflow <= 2, mode + ' mobile English has no horizontal overflow');
            check(await evaluate("document.documentElement.dir==='ltr' && document.querySelector('.site-header .language-options [aria-current=page]')?.lang==='en'"), 'English language and active switch agree');
            check(await evaluate("!!document.querySelector('.site-header [data-ab-toggle]').getAttribute('aria-label')"), 'Appearance control names the next action');
            const mobile = await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            fs.writeFileSync(path.join(captures,'mobile-'+mode+'.png'),Buffer.from(mobile.data,'base64'));
            await evaluate("document.querySelector('.menu-toggle').click()");
            await pause(350);
            check(await evaluate("document.querySelector('.menu-toggle').getAttribute('aria-expanded')==='true'"), mode+' mobile navigation opens');
            contrasts.push(await audit());
            await visit('/');
            check(await evaluate("document.documentElement.dir==='rtl' && document.querySelector('.site-header .language-options [aria-current=page]')?.lang==='ar'"), 'Arabic language and active switch agree');
            await evaluate("localStorage.removeItem('cd_cookie_consent')");
            await visit('/contact/');
            await wait("!!document.querySelector('.cd-cookie-banner.cd-cookie-visible')", 'Cookie banner ready');
            await pause(500);
            contrasts.push(await audit());
            await evaluate("document.querySelector('#cd-cookie-settings-btn').click()");
            await pause(350);
            contrasts.push(await audit());
            check(await evaluate("getComputedStyle(document.querySelector('.cd-cookie-settings')).backgroundColor===getComputedStyle(document.querySelector('.cd-cookie-banner')).backgroundColor"), mode+' cookie dialog shares surface color');
            await evaluate("document.querySelector('#cd-cookie-settings-cancel').click();document.querySelector('#cd-cookie-accept-essential').click()");
            await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        }
        await evaluate("window.autoBrandsAppearance.select('system')");
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'dark'}]});
        await wait("document.documentElement.dataset.abTheme==='dark'", 'System dark preference applies');
        check(true, 'System dark preference applies');
        await evaluate("window.autoBrandsAppearance.select('light')");
        await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'light'}]});
        check(await evaluate("document.documentElement.dataset.abTheme==='light'"), 'Explicit preference overrides device');
        await evaluate("window.autoBrandsAppearance.select('dark')");
        await call('Emulation.setEmulatedMedia',{media:'print'});
        await wait("getComputedStyle(document.documentElement).getPropertyValue('--ab-text').trim().toUpperCase()==='#1A1D20'", 'Print palette applies');
        check(true, 'Printing uses dark ink even when the screen is dark');
        await call('Emulation.setEmulatedMedia',{media:''});
        await visit('/wp-login.php');
        for(const mode of ['light','dark']) {
            await evaluate('window.autoBrandsAppearance.select('+JSON.stringify(mode)+')');
            await pause(350); // Native login links animate color when switching themes.
            report.push(await state());
            contrasts.push(await audit());
            check(await evaluate("document.querySelectorAll('.site-header [data-ab-toggle]').length===1 && document.querySelectorAll('.site-footer [data-ab-toggle]').length===1"), mode + ' login has appearance control');
        }
        const authFile=path.resolve(__dirname,'../../../../.tmp/dashboard-browser-session.json');
        assert.ok(fs.existsSync(authFile), 'Prepare the temporary local dashboard session before running this acceptance suite.');
        if(fs.existsSync(authFile)) {
            const session=JSON.parse(fs.readFileSync(authFile,'utf8'));
            for(const cookie of session.cookies) await call('Network.setCookie',{name:cookie.name,value:cookie.value,url:root+'/',path:'/wordpress/'});
            await visit('/wp-admin/');
            const adminRoutes = await evaluate("[...new Set(['/wp-admin/','/wp-admin/edit.php?post_type=car',...[...document.querySelectorAll('#adminmenu a[href]')].map(a=>new URL(a.href)).filter(u=>u.pathname.endsWith('/admin.php')&&u.searchParams.size===1&&u.searchParams.get('page')?.startsWith('adc-')).map(u=>'/wp-admin/admin.php'+u.search)])]");
            for(const mode of ['light','dark']) {
                await evaluate('window.autoBrandsAppearance.select('+JSON.stringify(mode)+')');
                for(const route of adminRoutes) {
                    await visit(route); report.push(await state()); contrasts.push(await audit());
                    check(await evaluate("!!document.querySelector('#wpadminbar')"), mode+' authenticated administration: '+route);
                    check((await state()).overflow <= 2, mode+' administration fits viewport: '+route);
                }
                const admin = await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
                fs.writeFileSync(path.join(captures,'admin-'+mode+'.png'),Buffer.from(admin.data,'base64'));
            }
        }
        fs.writeFileSync(path.join(captures,'report.json'),JSON.stringify(report,null,2));
        fs.writeFileSync(path.join(captures,'contrast.json'),JSON.stringify(contrasts,null,2));
        const contrastFailures = contrasts.reduce((total,entry)=>total+entry.failures.length,0);
        check(contrastFailures===0, 'Text contrast on solid backgrounds; '+contrastFailures+' failures (see contrast.json)');
        check(errors.length===0,'No browser runtime exceptions: '+errors.join('; '));
        check(badAssets.length===0,'All scripts and styles load: '+badAssets.join('; '));
        process.stdout.write('BRAND PASS: '+passed+' checks; '+captures+'\n');
    } finally {
        if (socket) socket.close();
        browser.kill();
        await pause(500);
        if (path.dirname(path.resolve(profile)) === profileRoot && path.basename(profile).startsWith('adc-brand-')) {
            try { fs.rmSync(profile, { recursive: true, force: true }); } catch {}
        }
    }
})().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
