'use strict';
// Receives synthetic credentials on stdin; connects only to the disposable loopback server.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');
(async () => {
    const input = JSON.parse(fs.readFileSync(0, 'utf8'));
    assert.match(input.origin, /^http:\/\/127\.0\.0\.1:[0-9]+$/);
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'adc-account-browser-'));
    const browser = spawn(process.env.ADC_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', ['--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=0', '--no-first-run', '--disable-background-networking', '--disable-sync', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
    let socket, checks = 0;
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    const check = (value, message) => { assert.ok(value, message); ++checks; console.log(`BROWSER PASS: ${message}`); };
    try {
        const active = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !fs.existsSync(active); i++) await pause(100);
        assert.ok(fs.existsSync(active), 'Browser must start.');
        const port = Number(fs.readFileSync(active, 'utf8').split('\n')[0]);
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
        let seq = 0;
        const pending = new Map(), errors = [], badAssets = [];
        const call = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++seq;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
            pending.set(id, { resolve, reject, timer }); socket.send(JSON.stringify({ id, method, params }));
        });
        socket.onmessage = event => {
            const data = JSON.parse(event.data);
            if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);
            if (data.method === 'Network.responseReceived' && data.params.response.status >= 400 && ['Stylesheet', 'Script'].includes(data.params.type)) badAssets.push(data.params.response.url);
            if (data.method === 'Fetch.requestPaused') {
                const request = data.params;
                const local = request.request.url.startsWith(input.origin + '/') || request.request.url.startsWith('data:');
                call(local ? 'Fetch.continueRequest' : 'Fetch.failRequest', local ? { requestId: request.requestId } : { requestId: request.requestId, errorReason: 'BlockedByClient' }).catch(() => {});
            }
            const item = pending.get(data.id); if (!item) return;
            clearTimeout(item.timer); pending.delete(data.id);
            data.error ? item.reject(new Error(data.error.message)) : item.resolve(data.result);
        };
        await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable');
        await call('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
        const evaluate = async expression => {
            const r = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
            return r.result.value;
        };
        const wait = async (expression, label) => {
            for (let i = 0; i < 120; i++) { try { if (await evaluate(expression)) return; } catch {} await pause(100); }
            throw new Error(`Timed out: ${label}; URL: ${await evaluate('location.href')}; page: ${(await evaluate('document.documentElement.outerHTML')).slice(0, 700)}`);
        };
        const navigate = async (url, selector) => { await call('Page.navigate', { url: input.origin + url }); await wait(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(selector)})`, selector); };
        const click = async selector => {
            await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center'})`);
            let point, previous;
            for (let i = 0; i < 40; i++) {
                point = await evaluate(`(() => { const b=document.querySelector(${JSON.stringify(selector)}); const r=b.getBoundingClientRect(); const x=r.x+r.width/2,y=r.y+r.height/2; return {x,y,ready:b.contains(document.elementFromPoint(x,y))}; })()`);
                if (point.ready && previous && Math.abs(point.x-previous.x)<1 && Math.abs(point.y-previous.y)<1) break;
                previous = point; await pause(100);
            }
            assert.ok(point.ready, `Button must be visible and unobscured: ${selector}`);
            delete point.ready;
            await call('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 });
            await call('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 });
        };
        const fill = async (selector, values) => {
            await wait("document.readyState === 'complete'", 'scripts loaded before form interaction');
            await evaluate(`(() => { const form=document.querySelector(${JSON.stringify(selector)}); const values=${JSON.stringify(values)}; for(const [key,value] of Object.entries(values)){ const f=form.elements.namedItem(key); if(f.type==='checkbox') f.checked=!!value; else f.value=value; } })()`);
            await click(selector + ' button');
        };
        const login = async (staff = false) => {
            await call('Network.clearBrowserCookies');
            await navigate('/?cd_account=login', '.cd-auth-form form');
            await fill('.cd-auth-form form', { login: staff ? input.staff : input.customer, password: staff ? input.staff_password : input.password });
            await wait("document.readyState === 'complete' && !!document.querySelector('.cd-account-welcome')", 'real account login');
        };
        await navigate('/?cd_account=dashboard', '.cd-auth-form form');
        check(await evaluate("location.search.includes('login')"), 'Anonymous account visit redirects to login.');
        await wait("!!document.querySelector('#cd-cookie-accept-essential')", 'cookie preference banner');
        await click('#cd-cookie-accept-essential');
        await wait("!document.querySelector('#cd-cookie-banner')", 'cookie preference saved');
        check(await evaluate("(() => {const p=JSON.parse(localStorage.getItem('cd_cookie_consent')).preferences;return p.essential && !p.marketing && !p.analytics;})()"), 'Customer can choose essential cookies before using account forms.');
        await login();
        check(await evaluate("document.body.innerText.includes('عميل الاختبار') && !!document.querySelector('#customer-messages')"), 'Customer logs in through the actual theme form and sees owned request panels.');
        check(await evaluate("document.querySelector('.cd-contact-form [name=email]').value === 'browser@example.invalid'"), 'Loaded theme script prefills the authenticated profile.');
        await fill('.cd-contact-form', { message: 'رسالة رحلة الحساب BROWSER-FIRST' });
        await wait("document.querySelector('.cd-contact-form .cd-form-status').textContent.includes('بنجاح')", 'contact AJAX success');
        await navigate('/?cd_account=dashboard', '#customer-messages');
        check(await evaluate("document.querySelector('#customer-messages').innerText.includes('BROWSER-FIRST')"), 'AJAX enquiry persists and appears in the customer account after reload.');
        await fill('.cd-account-profile', { display_name: 'عميل محدث', phone: '+966502222222' });
        await wait("location.search.includes('saved=1') && document.querySelector('.cd-account-welcome').innerText.includes('عميل محدث')", 'profile save');
        await fill('.cd-contact-form', { message: 'BROWSER-REFRESH' });
        await wait("document.querySelector('.cd-contact-form .cd-form-status').textContent.includes('بنجاح')", 'refreshed account enquiry');
        await navigate('/?post_type=car&p=' + input.car_id, 'form.cd-booking-form');
        await fill('#request-price .cd-lead-form', { message: 'BROWSER-PRICE' });
        await wait("document.querySelector('#request-price .cd-form-status').textContent.includes('بنجاح')", 'vehicle price enquiry');
        check(true, 'Actual vehicle detail page submits a price enquiry through the shared intake form.');
        const future = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
        await fill('.cd-booking-form', { date: future, time: '12:30' });
        await wait("document.querySelector('.cd-booking-form .cd-form-status a') !== null", 'booking account link');
        check(true, 'Real car booking form submits through AJAX and returns an account link.');
        await navigate('/?cd_account=dashboard', '#customer-bookings form');
        await fill('#customer-bookings form', {});
        await wait("location.search.includes('request_updated=1')", 'booking cancellation');
        check(await evaluate("document.querySelector('#customer-bookings').innerText.includes('ملغى') && !document.querySelector('#customer-bookings form')"), 'Account cancellation persists and removes the cancellation control.');
        await login(true);
        const lead = await evaluate(`(async () => { const nonce=await (await fetch('/adc-test-nonce?action=wp_rest')).text(); const rows=await (await fetch('/?rest_route=/auto-dealership/v1/leads&per_page=100',{headers:{'X-WP-Nonce':nonce}})).json(); return rows.find(r=>r.email==='browser@example.invalid'); })()`);
        check(lead && lead.full_name === 'عميل محدث' && lead.mobile === '+966502222222', 'Next authenticated enquiry refreshes canonical CRM name and mobile.');
        await navigate('/adc-test-review?lead_id=' + lead.id, 'form [name=revision]');
        await fill('form:has([name=revision])', { customer_reply: 'رد المعرض BROWSER-REPLY' });
        await pause(300);
        await login();
        check(await evaluate("document.body.innerText.includes('BROWSER-REPLY')"), 'Staff saves the actual protected request form and the customer sees the reply.');
        const artifacts = path.resolve(__dirname, '../../../../docs/artifacts/account-journey');
        fs.mkdirSync(artifacts, { recursive: true });
        for (const width of [1440, 768, 390, 320]) {
            await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width <= 390 });
            await pause(120);
            check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), `Account page fits viewport ${width}px without document overflow.`);
            check(await evaluate("getComputedStyle(document.querySelector('.cd-account')).direction === 'rtl'"), `Arabic account direction remains RTL at ${width}px.`);
            const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
            fs.writeFileSync(path.join(artifacts, `account-${width}.png`), Buffer.from(shot.data, 'base64'));
        }
        check(await evaluate("getComputedStyle(document.querySelector('.cd-account-table-hint')).display !== 'none' && Array.from(document.querySelectorAll('.cd-account-table')).every(e=>e.tabIndex===0 && e.getAttribute('aria-label') && getComputedStyle(e).overflowX==='auto' && e.scrollWidth>e.clientWidth)"), 'Narrow request tables have a visible scroll hint and labelled keyboard-focusable regions.');
        await evaluate("document.querySelector('.cd-account-table').focus()");
        await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'ArrowLeft', code: 'ArrowLeft', windowsVirtualKeyCode: 37 });
        await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'ArrowLeft', code: 'ArrowLeft', windowsVirtualKeyCode: 37 });
        await pause(200);
        check(await evaluate("document.querySelector('.cd-account-table').scrollLeft !== 0"), 'Arrow key scrolls the focused RTL request table on mobile.');
        check(await evaluate("getComputedStyle(document.querySelector('.cd-contact-form [name=email]')).direction==='ltr' && getComputedStyle(document.querySelector('.cd-account-profile [name=phone]')).direction==='ltr' && document.querySelector('.cd-account').lang==='ar'"), 'Arabic account content declares its language while email and phone remain LTR.');
        await evaluate("document.querySelector('.menu-toggle').click()");
        check(await evaluate("document.querySelector('.menu-toggle').getAttribute('aria-expanded')==='true' && document.querySelector('.main-navigation').classList.contains('is-open')"), 'Mobile navigation opens with synchronized accessible state.');
        await evaluate("document.querySelector('.menu-toggle').click()");
        check(await evaluate("Array.from(document.querySelectorAll('.cd-account input:not([type=hidden]),.cd-account textarea')).filter(e=>e.getClientRects().length).every(e=>e.labels?.length || e.getAttribute('aria-label'))"), 'Visible account form controls have accessible labels.');
        await call('Network.clearBrowserCookies');
        await navigate('/?cd_account=register', '.cd-auth-form form');
        check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'Registration form fits a 320px viewport.');
        await fill('.cd-auth-form form', { display_name: 'عميل مستقل', email: 'browser-other@example.invalid', phone: '+966503333333', password: input.password, password_confirm: input.password, privacy_consent: true });
        await wait("!!document.querySelector('.cd-account-welcome')", 'registration');
        check(await evaluate("!document.body.innerText.includes('BROWSER-FIRST') && !document.body.innerText.includes('BROWSER-REPLY')"), 'Newly registered account cannot see another customer’s messages or replies.');
        check(errors.length === 0, `No uncaught page JavaScript errors: ${errors.join(';')}`);
        check(badAssets.length === 0, `Local CSS/JS assets load successfully: ${badAssets.join(';')}`);
        console.log(`Completed ${checks} real-theme browser checks. Screenshots: ${artifacts}`);
        await call('Browser.close').catch(() => {});
    } finally {
        if (socket) socket.close();
        if (browser.exitCode === null) browser.kill();
        await pause(500);
        const resolved = path.resolve(profile);
        if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('adc-account-browser-')) { try { fs.rmSync(resolved, { recursive: true, force: true }); } catch {} }
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
